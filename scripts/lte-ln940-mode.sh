#!/bin/bash
# ==============================================================================
# Telit LN940 (LN940A9 / Foxconn T77W676) USB Mode Switcher
# https://github.com/hungngit2/home-network
# ==============================================================================
# Quickly run via curl:
#   curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/lte-ln940-mode.sh | sudo bash
# ==============================================================================
# SETMODE=1 : MBIM + Serial  (PID 1bc7:1901) -> MikroTik RouterOS
# SETMODE=2 : QMI  + Serial  (PID 1bc7:1900) -> Linux/QMI testing
#
# IMPORTANT:
#   - Do NOT change bConfigurationValue manually.
#   - SETMODE changes the USB composition and the modem re-enumerates.
#   - ttyUSB1 is normally the AT command port on this LN940 firmware.
# ==============================================================================

set -u

VID="1bc7"
PID_MBIM="1901"
PID_QMI="1900"

MODE=""

# ------------------------------------------------------------
# Ensure serial drivers are loaded and bound
# ------------------------------------------------------------
load_serial_drivers() {
    modprobe option 2>/dev/null || true
    modprobe qcserial 2>/dev/null || true

    # If Telit LN940 is plugged in but no ttyUSB was registered yet, dynamically register IDs
    if [ ! -e /dev/ttyUSB0 ] && [ -d /sys/bus/usb-serial/drivers/option1 ]; then
        echo "${VID} ${PID_MBIM}" > /sys/bus/usb-serial/drivers/option1/new_id 2>/dev/null || true
        echo "${VID} ${PID_QMI}" > /sys/bus/usb-serial/drivers/option1/new_id 2>/dev/null || true
    fi
}

# ------------------------------------------------------------
# Find LN940 USB device
# ------------------------------------------------------------
find_device() {
    if lsusb | grep -qi "${VID}:${PID_MBIM}"; then
        MODE="MBIM + Serial"
        return 0
    fi

    if lsusb | grep -qi "${VID}:${PID_QMI}"; then
        MODE="QMI + Serial"
        return 0
    fi

    MODE="Not detected"
    return 1
}

# ------------------------------------------------------------
# Find AT serial port
# ------------------------------------------------------------
find_at_port() {
    local port

    load_serial_drivers

    # The LN940 normally exposes ttyUSB1 as AT port.
    for port in /dev/ttyUSB*; do
        [ -e "$port" ] || continue
        if [ "$(basename "$port")" = "ttyUSB1" ]; then
            echo "$port"
            return 0
        fi
    done

    # Fallback: check other available ttyUSB ports
    for port in /dev/ttyUSB*; do
        [ -e "$port" ] || continue
        echo "$port"
        return 0
    done

    return 1
}

# ------------------------------------------------------------
# Send AT command
# ------------------------------------------------------------
send_at() {
    local port="$1"
    local command="$2"

    stty -F "$port" 115200 cs8 -cstopb -parenb -ixon -ixoff -crtscts raw -echo 2>/dev/null || true

    # Flush any stale buffer
    timeout 0.2 cat "$port" >/dev/null 2>&1 || true

    printf '%s\r' "$command" > "$port"

    timeout 3 cat "$port" 2>/dev/null | tr -d '\r' || true
}

# ------------------------------------------------------------
# Show current status
# ------------------------------------------------------------
show_status() {
    echo
    echo "=== LN940A9 status ==="

    if ! find_device; then
        echo "Device : NOT DETECTED"
        echo
        return
    fi

    echo "Device : Telit LN940A9"
    echo "USB    : $MODE"

    local port
    port="$(find_at_port 2>/dev/null || true)"

    if [ -n "$port" ]; then
        echo "AT port: $port"
        echo
        echo "--- AT^SETMODE? ---"
        send_at "$port" "AT^SETMODE?"
    else
        echo "AT port: NOT FOUND"
    fi

    echo
}

# ------------------------------------------------------------
# Change mode
# ------------------------------------------------------------
change_mode() {
    local target="$1"
    local port

    port="$(find_at_port 2>/dev/null || true)"

    if [ -z "$port" ]; then
        echo "ERROR: AT port not found."
        return 1
    fi

    echo
    echo "Current USB mode:"
    find_device >/dev/null 2>&1 || true
    echo "  $MODE"
    echo "AT port: $port"
    echo

    if [ "$target" = "1" ]; then
        echo "Switching LN940 to MBIM + Serial..."
        send_at "$port" "AT^SETMODE=1"
    elif [ "$target" = "2" ]; then
        echo "Switching LN940 to QMI + Serial..."
        send_at "$port" "AT^SETMODE=2"
    else
        echo "Invalid mode."
        return 1
    fi

    echo
    echo "The modem should now re-enumerate."
    echo "Wait a few seconds..."

    sleep 5

    echo
    show_status
}

# Helper to read keyboard input safely under pipes (curl | bash)
read_input() {
    local prompt="$1"
    local var_name="$2"

    if [ -t 0 ]; then
        read -rp "$prompt" "$var_name"
    elif [ -r /dev/tty ]; then
        read -rp "$prompt" "$var_name" < /dev/tty
    else
        echo "$prompt"
        return 1
    fi
}

# ------------------------------------------------------------
# Main
# ------------------------------------------------------------
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root:"
    echo "  sudo $0"
    exit 1
fi

while true; do
    clear

    echo "======================================"
    echo "       Telit LN940A9 Mode Switcher"
    echo "======================================"
    echo

    if find_device; then
        echo "Detected : $MODE"
    else
        echo "Detected : NOT FOUND"
    fi

    echo
    echo "1) MBIM + Serial  (SETMODE=1)"
    echo "2) QMI  + Serial  (SETMODE=2)"
    echo "3) Show status"
    echo "0) Exit"
    echo

    read_input "Select: " choice || {
        echo "Non-interactive shell. Showing status and exiting:"
        show_status
        exit 0
    }

    case "$choice" in
        1)
            change_mode 1
            read_input "Press Enter to continue..." dummy || true
            ;;
        2)
            change_mode 2
            read_input "Press Enter to continue..." dummy || true
            ;;
        3)
            show_status
            read_input "Press Enter to continue..." dummy || true
            ;;
        0)
            exit 0
            ;;
        *)
            echo "Invalid choice."
            sleep 1
            ;;
    esac
done
