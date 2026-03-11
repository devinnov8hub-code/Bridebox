<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AdminActionService
{
    // Ordered list of php-fpm service names to try (newest first).
    private const PHP_FPM_SERVICES = [
        'php8.4-fpm',
        'php8.3-fpm',
        'php8.2-fpm',
        'php8.1-fpm',
        'php8.0-fpm',
        'php-fpm',
    ];

    private const ACTIONS = [
        'start_server' => [
            'label' => 'Start Server',
            'requires_sudo' => true,
            'steps' => [
                ['systemctl', 'start', 'nginx'],
                // php-fpm service name is resolved dynamically in execute()
                ['systemctl', 'start', '__PHP_FPM__'],
            ],
        ],
        'stop_server' => [
            'label' => 'Stop Server',
            'requires_sudo' => true,
            'steps' => [
                ['systemctl', 'stop', 'nginx'],
                ['systemctl', 'stop', '__PHP_FPM__'],
            ],
        ],
        'hotspot_on' => [
            'label' => 'Hotspot On',
            'requires_sudo' => true,
            'steps' => [
                ['nmcli', 'con', 'up', 'Hotspot'],
            ],
        ],
        'hotspot_off' => [
            'label' => 'Hotspot Off',
            'requires_sudo' => true,
            'steps' => [
                ['nmcli', 'con', 'down', 'Hotspot'],
            ],
        ],
        'apply_hotspot_settings' => [
            'label' => 'Apply Hotspot Settings',
            'requires_sudo' => true,
            'steps' => [],
        ],
        'reboot' => [
            'label' => 'Reboot Device',
            'requires_sudo' => true,
            'steps' => [
                ['reboot'],
            ],
        ],
        'shutdown' => [
            'label' => 'Shutdown Device',
            'requires_sudo' => true,
            'steps' => [
                ['shutdown', '-h', 'now'],
            ],
        ],
        'clear_logs' => [
            'label' => 'Clear Logs',
            'requires_sudo' => false,
            'steps' => [],
            'success_message' => 'Logs cleared.',
        ],
    ];

    public function execute(string $action): array
    {
        if (!array_key_exists($action, self::ACTIONS)) {
            return $this->fail('Unknown admin action.');
        }

        if (!$this->actionsEnabled()) {
            return $this->fail(
                'Admin actions are disabled. Add ADMIN_ACTIONS_ENABLED=true to your .env file.'
            );
        }

        $definition = self::ACTIONS[$action];
        if (!empty($definition['requires_sudo']) && !$this->sudoEnabled()) {
            return $this->fail(
                'Sudo actions are disabled. Add ADMIN_ACTIONS_ALLOW_SUDO=true to your .env file, ' .
                'and ensure www-data has passwordless sudo for the required commands in /etc/sudoers.'
            );
        }

        if ($action === 'start_server') {
            $permissionCheck = $this->checkSqliteWritable();
            if ($permissionCheck !== null) {
                return $this->fail($permissionCheck);
            }
        }

        // Handle clear_logs specially — no shell steps needed.
        if ($action === 'clear_logs') {
            $logPath = storage_path('logs/laravel.log');
            if (is_file($logPath)) {
                file_put_contents($logPath, '');
            }
            return ['success' => true, 'message' => 'Logs cleared.'];
        }

        // Special handling for applying hotspot settings from storage
        if ($action === 'apply_hotspot_settings') {
            $path = storage_path('app/hotspot.json');
            if (!file_exists($path)) {
                return $this->fail('Hotspot settings not found. Save them first.');
            }
            $raw = file_get_contents($path);
            $data = json_decode($raw, true) ?: [];
            $ssid = trim($data['ssid'] ?? '');
            $password = $data['password'] ?? '';
            if ($ssid === '') {
                return $this->fail('SSID is empty in stored hotspot settings.');
            }

            $steps = [];
            if ($password !== null && $password !== '') {
                $steps[] = ['nmcli', 'dev', 'wifi', 'hotspot', 'ifname', 'wlan0', 'ssid', $ssid, 'password', $password];
            } else {
                $steps[] = ['nmcli', 'dev', 'wifi', 'hotspot', 'ifname', 'wlan0', 'ssid', $ssid];
            }
            $steps[] = ['nmcli', 'connection', 'modify', 'Hotspot', 'connection.autoconnect', 'yes'];
            $steps[] = ['nmcli', 'connection', 'modify', 'Hotspot', 'connection.autoconnect-priority', '100'];
            $steps[] = ['nmcli', 'connection', 'modify', 'Hotspot', 'ipv4.method', 'shared'];
            $steps[] = ['nmcli', 'connection', 'modify', 'Hotspot', 'ipv4.addresses', '192.168.4.1/24'];

            foreach ($steps as $step) {
                $result = $this->runStep($step, true);
                if (!$result['success']) {
                    return $this->fail($result['message']);
                }
            }

            return ['success' => true, 'message' => 'Hotspot settings applied.'];
        }

        // Resolve __PHP_FPM__ placeholder to the actual installed service name.
        $phpFpmService = $this->resolvePhpFpmService();
        $steps = array_map(function (array $step) use ($phpFpmService): array {
            return array_map(
                fn($part) => $part === '__PHP_FPM__' ? $phpFpmService : $part,
                $step
            );
        }, $definition['steps']);

        foreach ($steps as $step) {
            $result = $this->runStep($step, !empty($definition['requires_sudo']));
            if (!$result['success']) {
                return $this->fail($result['message']);
            }
        }

        $message = $definition['success_message'] ?? ($definition['label'] . ' completed.');

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    public function labels(): array
    {
        return collect(self::ACTIONS)
            ->map(fn ($action) => $action['label'])
            ->all();
    }

    public function isEnabled(): bool
    {
        return $this->actionsEnabled();
    }

    public function isSudoAllowed(): bool
    {
        return $this->sudoEnabled();
    }

    private function runStep(array $command, bool $useSudo): array
    {
        $commandLine = $useSudo ? array_merge(['sudo'], $command) : $command;

        try {
            $process = new Process($commandLine);
            $process->setTimeout(6);
            $process->run();

            if (!$process->isSuccessful()) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput());
                $error = $error !== '' ? $error : 'Command failed.';
                return $this->fail($this->formatCommandError($commandLine, $error));
            }

            return [
                'success' => true,
                'message' => 'ok',
            ];
        } catch (\Throwable $exception) {
            return $this->fail($this->formatCommandError($commandLine, $exception->getMessage()));
        }
    }

    private function resolvePhpFpmService(): string
    {
        foreach (self::PHP_FPM_SERVICES as $service) {
            $process = new Process(['systemctl', 'list-units', '--full', '--all', '-t', 'service', '--no-pager', '--no-legend', $service . '.service']);
            $process->setTimeout(3);
            $process->run();
            if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                return $service;
            }
        }
        // Fall back to the generic name and let systemctl surface the error.
        return 'php-fpm';
    }

    private function checkSqliteWritable(): ?string
    {
        $path = config('database.connections.sqlite.database');
        if (!$path) {
            return 'SQLite database path not configured.';
        }

        if (!Str::startsWith($path, ['/','\\'])) {
            $path = base_path($path);
        }

        if (!is_file($path)) {
            return 'SQLite database file not found.';
        }

        if (!is_writable($path)) {
            return 'SQLite database file is not writable.';
        }

        return null;
    }

    private function actionsEnabled(): bool
    {
        return filter_var(env('ADMIN_ACTIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function sudoEnabled(): bool
    {
        return filter_var(env('ADMIN_ACTIONS_ALLOW_SUDO', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    private function formatCommandError(array $command, string $error): string
    {
        $label = implode(' ', $command);
        return "{$label} failed: {$error}";
    }
}
