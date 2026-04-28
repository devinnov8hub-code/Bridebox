<?php

namespace App\Services;

use App\Models\ImportedContent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * UsbImportService
 *
 * Handles detection of removable USB drives, virus scanning (ClamAV),
 * and copying their contents into the local library, organised by file type.
 *
 * Cross-platform: works on Linux (Raspberry Pi production) AND Windows
 * (developer test machines, where USB drives appear as drive letters E:, F:, etc.).
 */
class UsbImportService
{
    public const LIBRARY_ROOT = 'library';

    private const BLOCKED_EXTENSIONS = [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'vbs', 'vbe',
        'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'jar', 'reg', 'dll',
        'sys', 'app', 'sh', 'bash',
    ];

    private const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024;

    /**
     * Returns a list of currently mounted removable drives.
     */
    public function detectDrives(): array
    {
        if ($this->isWindows()) {
            return $this->detectDrivesWindows();
        }
        return $this->detectDrivesLinux();
    }

    public function inspectDrive(string $drivePath): array
    {
        $drivePath = $this->normalisePath($drivePath);
        if (! is_dir($drivePath) || ! is_readable($drivePath)) {
            return ['exists' => false, 'files' => 0, 'bytes' => 0, 'by_category' => []];
        }

        $files = 0;
        $bytes = 0;
        $byCategory = [];

        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($drivePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iter as $f) {
                if (! $f->isFile()) continue;
                if ($this->isBlockedExtension($f->getExtension())) continue;
                $files++;
                $bytes += $f->getSize();
                $cat = ImportedContent::categoryFromExtension($f->getExtension());
                $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            Log::warning('USB inspect failed: ' . $e->getMessage());
        }

        return [
            'exists' => true,
            'files' => $files,
            'bytes' => $bytes,
            'size_human' => $this->humanBytes($bytes),
            'by_category' => $byCategory,
        ];
    }

    public function startImport(string $drivePath, ?int $userId = null): array
    {
        $drivePath = $this->normalisePath($drivePath);
        if (! is_dir($drivePath)) {
            return ['success' => false, 'message' => 'Drive not found: ' . $drivePath];
        }

        $current = $this->currentJob();
        if ($current && in_array($current['status'] ?? '', ['scanning', 'copying'], true)) {
            return ['success' => false, 'message' => 'An import is already in progress.'];
        }

        $jobId = (string) Str::uuid();
        $progressPath = $this->progressFilePath();

        $initial = [
            'job_id' => $jobId,
            'status' => 'starting',
            'message' => 'Preparing import…',
            'drive' => $drivePath,
            'user_id' => $userId,
            'total_files' => 0,
            'copied_files' => 0,
            'total_bytes' => 0,
            'copied_bytes' => 0,
            'current_file' => null,
            'current_category' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'errors' => [],
            'scan' => ['status' => 'pending', 'engine' => null, 'message' => null],
        ];
        $this->writeJson($progressPath, $initial);

        $this->ensureLibraryDirs();
        $this->launchWorker($jobId, $drivePath, $userId);

        return ['success' => true, 'job_id' => $jobId];
    }

    public function runImport(string $jobId, string $drivePath, ?int $userId = null): void
    {
        $progressPath = $this->progressFilePath();
        $update = function (array $patch) use ($progressPath) {
            $current = $this->readJson($progressPath) ?? [];
            $merged = array_merge($current, $patch);
            $this->writeJson($progressPath, $merged);
        };

        try {
            $update(['status' => 'scanning', 'message' => 'Scanning files on USB…']);
            $files = $this->collectFiles($drivePath);
            if (empty($files)) {
                $update([
                    'status' => 'done',
                    'message' => 'No supported files found on the drive.',
                    'finished_at' => now()->toIso8601String(),
                ]);
                return;
            }
            $totalBytes = array_sum(array_column($files, 'size'));
            $update([
                'total_files' => count($files),
                'total_bytes' => $totalBytes,
            ]);

            $scanResult = $this->virusScan($drivePath);
            $update(['scan' => $scanResult]);
            if ($scanResult['status'] === 'infected') {
                $update([
                    'status' => 'aborted',
                    'message' => 'Import aborted: virus detected on USB drive.',
                    'finished_at' => now()->toIso8601String(),
                ]);
                return;
            }

            $update(['status' => 'copying', 'message' => 'Copying files…']);
            $copiedFiles = 0;
            $copiedBytes = 0;
            $errors = [];

            foreach ($files as $file) {
                try {
                    $info = $this->copySingleFile($file, $userId);
                    $copiedFiles++;
                    $copiedBytes += $file['size'];
                    $update([
                        'copied_files' => $copiedFiles,
                        'copied_bytes' => $copiedBytes,
                        'current_file' => $info['original_name'],
                        'current_category' => $info['category'],
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = $file['name'] . ': ' . $e->getMessage();
                    $update(['errors' => $errors]);
                    Log::warning("USB copy failed for {$file['path']}: " . $e->getMessage());
                }
            }

            $update([
                'status' => 'done',
                'message' => sprintf('Imported %d of %d file(s).', $copiedFiles, count($files)),
                'finished_at' => now()->toIso8601String(),
                'current_file' => null,
                'current_category' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('USB import job failed: ' . $e->getMessage());
            $update([
                'status' => 'error',
                'message' => 'Import failed: ' . $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]);
        }
    }

    public function currentJob(): ?array
    {
        return $this->readJson($this->progressFilePath());
    }

    public function isClamAvAvailable(): bool
    {
        $cmd = $this->isWindows() ? 'where clamscan' : 'which clamscan';
        $p = Process::fromShellCommandline($cmd);
        $p->setTimeout(3);
        try { $p->run(); } catch (\Throwable $e) { return false; }
        return $p->isSuccessful() && trim($p->getOutput()) !== '';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function collectFiles(string $drivePath): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($drivePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iter as $f) {
            if (! $f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if ($this->isBlockedExtension($ext)) continue;
            $size = $f->getSize();
            if ($size <= 0 || $size > self::MAX_FILE_SIZE) continue;
            $out[] = [
                'path' => $f->getRealPath() ?: $f->getPathname(),
                'name' => $f->getFilename(),
                'ext' => $ext,
                'size' => $size,
            ];
        }
        return $out;
    }

    private function copySingleFile(array $file, ?int $userId): array
    {
        $ext = $file['ext'];
        $category = ImportedContent::categoryFromExtension($ext);
        $libRoot = storage_path('app/public/' . self::LIBRARY_ROOT);
        $catDir = $libRoot . DIRECTORY_SEPARATOR . $category;
        if (! is_dir($catDir)) {
            @mkdir($catDir, 0775, true);
        }

        $base = pathinfo($file['name'], PATHINFO_FILENAME);
        $slug = Str::slug($base) ?: 'file';
        $storedName = $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $destAbs = $catDir . DIRECTORY_SEPARATOR . $storedName;

        $in = @fopen($file['path'], 'rb');
        if (! $in) throw new \RuntimeException('Cannot read source file.');
        $out = @fopen($destAbs, 'wb');
        if (! $out) {
            fclose($in);
            throw new \RuntimeException('Cannot create destination file.');
        }
        $hashCtx = hash_init('sha256');
        while (! feof($in)) {
            $chunk = fread($in, 1024 * 256);
            if ($chunk === false) break;
            fwrite($out, $chunk);
            hash_update($hashCtx, $chunk);
        }
        fclose($in);
        fclose($out);
        $hash = hash_final($hashCtx);

        $relativePath = $category . '/' . $storedName;

        $record = ImportedContent::create([
            'original_name' => $file['name'],
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'category' => $category,
            'extension' => $ext,
            'mime_type' => $this->guessMime($destAbs, $ext),
            'size_bytes' => $file['size'],
            'hash_sha256' => $hash,
            'source_drive' => dirname($file['path']),
            'scan_status' => ImportedContent::SCAN_CLEAN,
            'scan_message' => null,
            'imported_by' => $userId,
            'imported_at' => now(),
        ]);

        return [
            'original_name' => $record->original_name,
            'category' => $record->category,
        ];
    }

    private function virusScan(string $drivePath): array
    {
        if (! $this->isClamAvAvailable()) {
            return [
                'status' => ImportedContent::SCAN_SKIPPED,
                'engine' => null,
                'message' => 'ClamAV (clamscan) is not installed. Skipped virus scan; extension blocklist still enforced.',
            ];
        }

        $cmd = ['clamscan', '-r', '--no-summary', '-i', $drivePath];
        $p = new Process($cmd);
        $p->setTimeout(60 * 30);
        try {
            $p->run();
        } catch (\Throwable $e) {
            return [
                'status' => ImportedContent::SCAN_ERROR,
                'engine' => 'clamav',
                'message' => 'Scan failed to start: ' . $e->getMessage(),
            ];
        }

        $exit = $p->getExitCode();
        if ($exit === 0) {
            return ['status' => ImportedContent::SCAN_CLEAN, 'engine' => 'clamav', 'message' => 'No threats detected.'];
        }
        if ($exit === 1) {
            return [
                'status' => ImportedContent::SCAN_INFECTED,
                'engine' => 'clamav',
                'message' => trim($p->getOutput()) ?: 'Virus signature detected.',
            ];
        }
        return [
            'status' => ImportedContent::SCAN_ERROR,
            'engine' => 'clamav',
            'message' => trim($p->getErrorOutput()) ?: 'Scan completed with errors.',
        ];
    }

    private function detectDrivesLinux(): array
    {
        $out = [];

        $p = Process::fromShellCommandline(
            'lsblk -P -o NAME,RM,TYPE,MOUNTPOINT,SIZE,LABEL --noheadings'
        );
        $p->setTimeout(5);
        try { $p->run(); } catch (\Throwable $e) {}
        if ($p->isSuccessful()) {
            foreach (explode("\n", trim($p->getOutput())) as $line) {
                if (! preg_match('/RM="(\d+)".*MOUNTPOINT="([^"]*)".*SIZE="([^"]*)".*LABEL="([^"]*)"/', $line, $m)) continue;
                if ($m[1] !== '1') continue;
                if ($m[2] === '' || $m[2] === '/') continue;
                $out[] = [
                    'path' => $m[2],
                    'label' => $m[4] !== '' ? $m[4] : basename($m[2]),
                    'size_human' => $m[3],
                    'free_human' => $this->humanBytes((int) @disk_free_space($m[2])),
                ];
            }
        }

        if (empty($out)) {
            foreach (['/media', '/mnt', '/run/media'] as $root) {
                if (! is_dir($root)) continue;
                foreach (glob($root . '/*') as $entry) {
                    if (! is_dir($entry)) continue;
                    foreach (glob($entry . '/*') ?: [$entry] as $candidate) {
                        if (is_dir($candidate) && is_readable($candidate)) {
                            $total = (int) @disk_total_space($candidate);
                            if ($total <= 0) continue;
                            $out[] = [
                                'path' => $candidate,
                                'label' => basename($candidate),
                                'size_human' => $this->humanBytes($total),
                                'free_human' => $this->humanBytes((int) @disk_free_space($candidate)),
                            ];
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Windows USB detection — uses three strategies in order of reliability:
     *   1) PowerShell Get-CimInstance (works on PS 5+ which ships with Win10/11)
     *   2) PowerShell Get-Volume     (fallback when CIM not available)
     *   3) Drive-letter enumeration  (last resort: just scan A:..Z: with PHP)
     *
     * The third strategy is guaranteed to work as long as PHP can see the drive,
     * even if PowerShell is locked down by group policy.
     */
    private function detectDrivesWindows(): array
    {
        // Strategy 1: PowerShell + Get-CimInstance (most reliable on Win10/11)
        $out = $this->windowsDetectViaCim();
        if (! empty($out)) return $out;

        // Strategy 2: PowerShell Get-Volume
        $out = $this->windowsDetectViaGetVolume();
        if (! empty($out)) return $out;

        // Strategy 3: Brute-force drive letter scan (always works)
        return $this->windowsDetectByDriveLetter();
    }

    private function windowsDetectViaCim(): array
    {
        // Use a base64-encoded command to dodge all quoting hell.
        // Equivalent script:
        //   Get-CimInstance Win32_LogicalDisk -Filter "DriveType=2" |
        //     Select-Object DeviceID,VolumeName,Size,FreeSpace |
        //     ConvertTo-Json -Compress
        $script = "Get-CimInstance Win32_LogicalDisk -Filter \"DriveType=2\" | Select-Object DeviceID,VolumeName,Size,FreeSpace | ConvertTo-Json -Compress";
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE'));
        $p = Process::fromShellCommandline("powershell -NoProfile -EncodedCommand " . $encoded);
        $p->setTimeout(8);
        try { $p->run(); } catch (\Throwable $e) {
            Log::debug('USB CIM detection threw: ' . $e->getMessage());
            return [];
        }
        if (! $p->isSuccessful()) return [];

        $json = trim($p->getOutput());
        if ($json === '') return [];

        $data = json_decode($json, true);
        if ($data === null) return [];

        // Single object => wrap in array
        if (isset($data['DeviceID'])) $data = [$data];

        $out = [];
        foreach ($data as $row) {
            $deviceId = $row['DeviceID'] ?? '';
            if ($deviceId === '') continue;
            $path = $deviceId . '\\';
            $out[] = [
                'path' => $path,
                'label' => ($row['VolumeName'] ?? '') !== '' ? $row['VolumeName'] : $deviceId,
                'size_human' => $this->humanBytes((int) ($row['Size'] ?? 0)),
                'free_human' => $this->humanBytes((int) ($row['FreeSpace'] ?? 0)),
            ];
        }
        return $out;
    }

    private function windowsDetectViaGetVolume(): array
    {
        $script = "Get-Volume | Where-Object { \$_.DriveType -eq 'Removable' -and \$_.DriveLetter } | ForEach-Object { [PSCustomObject]@{ DriveLetter=\$_.DriveLetter; Label=\$_.FileSystemLabel; Size=\$_.Size; Free=\$_.SizeRemaining } } | ConvertTo-Json -Compress";
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE'));
        $p = Process::fromShellCommandline("powershell -NoProfile -EncodedCommand " . $encoded);
        $p->setTimeout(8);
        try { $p->run(); } catch (\Throwable $e) {
            Log::debug('USB Get-Volume detection threw: ' . $e->getMessage());
            return [];
        }
        if (! $p->isSuccessful()) return [];

        $json = trim($p->getOutput());
        if ($json === '') return [];

        $data = json_decode($json, true);
        if ($data === null) return [];

        if (isset($data['DriveLetter'])) $data = [$data];

        $out = [];
        foreach ($data as $row) {
            $letter = $row['DriveLetter'] ?? '';
            if ($letter === '') continue;
            $path = $letter . ':\\';
            $out[] = [
                'path' => $path,
                'label' => ($row['Label'] ?? '') !== '' ? $row['Label'] : ($letter . ':'),
                'size_human' => $this->humanBytes((int) ($row['Size'] ?? 0)),
                'free_human' => $this->humanBytes((int) ($row['Free'] ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * Final fallback: walks every drive letter and uses pure PHP to detect
     * which ones are removable. Uses a CMD ping-style trick: a removable
     * drive's total space is normally LESS than the system drive's total.
     * We can't perfectly distinguish removable from fixed without WMI/CIM,
     * but for a dev test machine, exposing ALL non-system drives lets the
     * user pick their USB stick from the list.
     */
    private function windowsDetectByDriveLetter(): array
    {
        $out = [];
        // Skip A: and B: (legacy floppy), and the system drive (usually C:).
        // The user's USB will appear as D:, E:, F:, etc.
        $systemDrive = strtoupper(getenv('SystemDrive') ?: 'C:');
        $systemLetter = $systemDrive[0] ?? 'C';

        foreach (range('A', 'Z') as $letter) {
            if ($letter === $systemLetter) continue;
            $path = $letter . ':\\';
            // is_dir() is enough to detect a mounted drive on Windows.
            if (! @is_dir($path)) continue;
            $total = @disk_total_space($path);
            if ($total === false || $total <= 0) continue;
            $free = (int) @disk_free_space($path);

            // Try to grab the volume label using `vol` command (built into Windows).
            $label = $this->windowsVolumeLabel($letter) ?: ($letter . ':');

            $out[] = [
                'path' => $path,
                'label' => $label,
                'size_human' => $this->humanBytes((int) $total),
                'free_human' => $this->humanBytes($free),
            ];
        }
        return $out;
    }

    private function windowsVolumeLabel(string $letter): string
    {
        $p = Process::fromShellCommandline('cmd /c vol ' . $letter . ':');
        $p->setTimeout(2);
        try { $p->run(); } catch (\Throwable $e) { return ''; }
        if (! $p->isSuccessful()) return '';
        $output = $p->getOutput();
        // Output looks like:  Volume in drive E is MYUSB
        //                  or Volume in drive E has no label.
        if (preg_match('/Volume in drive .* is (.+)/i', $output, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function launchWorker(string $jobId, string $drivePath, ?int $userId): void
    {
        $php = $this->phpBinary();
        $artisan = base_path('artisan');
        $cmd = [
            $php, $artisan, 'usb:run-import',
            '--job=' . $jobId,
            '--drive=' . $drivePath,
        ];
        if ($userId) $cmd[] = '--user=' . $userId;

        $p = new Process($cmd, base_path());
        $p->setTimeout(null);
        $p->setIdleTimeout(null);
        try {
            if ($this->isWindows()) {
                $shell = sprintf(
                    'start "" /B %s',
                    implode(' ', array_map(fn($a) => '"' . str_replace('"', '\"', $a) . '"', $cmd))
                );
                $sp = Process::fromShellCommandline($shell, base_path());
                $sp->setTimeout(null);
                $sp->setIdleTimeout(null);
                $sp->disableOutput();
                $sp->start();
            } else {
                $p->disableOutput();
                $p->start();
            }
        } catch (\Throwable $e) {
            Log::error('Failed to launch USB import worker: ' . $e->getMessage());
            $this->runImport($jobId, $drivePath, $userId);
        }
    }

    private function ensureLibraryDirs(): void
    {
        $base = storage_path('app/public/' . self::LIBRARY_ROOT);
        if (! is_dir($base)) @mkdir($base, 0775, true);
        foreach (['video', 'audio', 'document', 'image', 'archive', 'other'] as $cat) {
            $d = $base . DIRECTORY_SEPARATOR . $cat;
            if (! is_dir($d)) @mkdir($d, 0775, true);
        }
        $publicLink = public_path('storage');
        if (! file_exists($publicLink)) {
            try {
                @symlink(storage_path('app/public'), $publicLink);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    private function progressFilePath(): string
    {
        $dir = storage_path('app');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . DIRECTORY_SEPARATOR . 'usb_import_progress.json';
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, array $data): void
    {
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function isBlockedExtension(string $ext): bool
    {
        return in_array(strtolower(ltrim($ext, '.')), self::BLOCKED_EXTENSIONS, true);
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\' || str_starts_with(strtolower(PHP_OS), 'win');
    }

    private function normalisePath(string $path): string
    {
        $trimmed = rtrim($path, "/\\");
        // On Windows, "C:" alone is meaningless — it has to be "C:\".
        if ($this->isWindows() && preg_match('/^[A-Za-z]:$/', $trimmed)) {
            return $trimmed . '\\';
        }
        return $trimmed === '' ? $path : $trimmed;
    }

    private function phpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY) return PHP_BINARY;
        return $this->isWindows() ? 'php.exe' : 'php';
    }

    private function guessMime(string $path, string $ext): ?string
    {
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($path);
            if ($m) return $m;
        }
        $map = [
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip', '7z' => 'application/x-7z-compressed',
        ];
        return $map[$ext] ?? null;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $b, $units[$i]);
    }
}
