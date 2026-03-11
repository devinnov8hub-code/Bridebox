import RPi.GPIO as GPIO
import subprocess
import time
import os

# BUTTON PINS
BTN_POWER  = 17
BTN_START  = 27
BTN_STOP   = 22
BTN_UPDATE = 23
BTN_RESET  = 24

# LED PINS
LED_SERVER  = 5
LED_HOTSPOT = 6

# -----------------------------------------------------------------------
# CONFIGURATION
# Set this to the NetworkManager connection name for your internet WiFi
# (used only during the update action). Leave blank to skip WiFi toggle.
# Example: INTERNET_WIFI_CONNECTION = "HomeWiFi"
# -----------------------------------------------------------------------
INTERNET_WIFI_CONNECTION = ""

# Debounce time in seconds — prevents a single press triggering many times
DEBOUNCE_SECONDS = 0.5

GPIO.setmode(GPIO.BCM)

buttons = [BTN_POWER, BTN_START, BTN_STOP, BTN_UPDATE, BTN_RESET]
leds    = [LED_SERVER, LED_HOTSPOT]

for b in buttons:
    GPIO.setup(b, GPIO.IN, pull_up_down=GPIO.PUD_DOWN)

for led in leds:
    GPIO.setup(led, GPIO.OUT)
    GPIO.output(led, GPIO.LOW)


def run(cmd: list):
    """Run a command as a subprocess (avoids shell glob issues)."""
    try:
        subprocess.run(cmd, check=False, timeout=10)
    except Exception as e:
        print(f"Command failed {cmd}: {e}")


def find_php_fpm_service() -> str:
    """Return the installed php-fpm service name, e.g. php8.4-fpm."""
    candidates = [
        "php8.4-fpm",
        "php8.3-fpm",
        "php8.2-fpm",
        "php8.1-fpm",
        "php8.0-fpm",
        "php-fpm",
    ]
    for svc in candidates:
        result = subprocess.run(
            ["systemctl", "list-units", "--full", "--all", "-t", "service",
             "--no-pager", "--no-legend", f"{svc}.service"],
            capture_output=True, text=True, timeout=5
        )
        if result.returncode == 0 and result.stdout.strip():
            print(f"Detected php-fpm service: {svc}")
            return svc
    print("WARNING: Could not detect php-fpm service name; defaulting to 'php-fpm'")
    return "php-fpm"


# Resolve php-fpm service name once at startup
PHP_FPM_SERVICE = find_php_fpm_service()


def start_server():
    print("Starting server...")
    run(["systemctl", "start", "nginx"])
    run(["systemctl", "start", PHP_FPM_SERVICE])
    run(["systemctl", "start", "mariadb"])
    GPIO.output(LED_SERVER, GPIO.HIGH)
    print("Server started.")


def stop_server():
    print("Stopping server...")
    run(["systemctl", "stop", "nginx"])
    run(["systemctl", "stop", PHP_FPM_SERVICE])
    run(["systemctl", "stop", "mariadb"])
    GPIO.output(LED_SERVER, GPIO.LOW)
    print("Server stopped.")


def hotspot_on():
    print("Turning hotspot on...")
    run(["nmcli", "con", "up", "Hotspot"])
    GPIO.output(LED_HOTSPOT, GPIO.HIGH)
    print("Hotspot on.")


def hotspot_off():
    print("Turning hotspot off...")
    run(["nmcli", "con", "down", "Hotspot"])
    GPIO.output(LED_HOTSPOT, GPIO.LOW)
    print("Hotspot off.")


def update_system():
    print("Updating system...")
    GPIO.output(LED_SERVER, GPIO.LOW)

    if INTERNET_WIFI_CONNECTION:
        run(["nmcli", "con", "up", INTERNET_WIFI_CONNECTION])
        time.sleep(5)  # give NetworkManager time to connect

    result = subprocess.run(
        ["git", "-C", "/var/www/bridgebox", "pull"],
        capture_output=True, text=True, timeout=120
    )
    print(result.stdout)
    if result.returncode != 0:
        print(f"git pull failed: {result.stderr}")

    if INTERNET_WIFI_CONNECTION:
        run(["nmcli", "con", "down", INTERNET_WIFI_CONNECTION])

    result = subprocess.run(
        ["systemctl", "is-active", "nginx"],
        capture_output=True, text=True
    )
    if result.stdout.strip() == "active":
        GPIO.output(LED_SERVER, GPIO.HIGH)

    print("Update done.")


def factory_reset():
    print("FACTORY RESET — wiping app data and rebooting...")
    GPIO.output(LED_SERVER, GPIO.LOW)
    GPIO.output(LED_HOTSPOT, GPIO.LOW)
    run(["rm", "-rf", "/var/www/bridgebox/storage/app"])
    run(["rm", "-f", "/var/www/bridgebox/database/database.sqlite"])
    run(["reboot"])


# -----------------------------------------------------------------------
# Button state tracking for debounce
# -----------------------------------------------------------------------
last_trigger = {
    BTN_POWER:  0,
    BTN_START:  0,
    BTN_STOP:   0,
    BTN_UPDATE: 0,
    BTN_RESET:  0,
}

print("LMS Hardware Control Ready")
print(f"  php-fpm service : {PHP_FPM_SERVICE}")
print(f"  Internet WiFi   : {INTERNET_WIFI_CONNECTION or '(not set — WiFi toggle disabled)'}")

while True:
    now = time.time()

    for btn, action in [
        (BTN_POWER,  lambda: (hotspot_on(), start_server())),
        (BTN_START,  start_server),
        (BTN_STOP,   lambda: (stop_server(), hotspot_off())),
        (BTN_UPDATE, update_system),
        (BTN_RESET,  factory_reset),
    ]:
        if GPIO.input(btn) and (now - last_trigger[btn]) > DEBOUNCE_SECONDS:
            last_trigger[btn] = now
            action()

    time.sleep(0.05)
