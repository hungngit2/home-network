#!/bin/bash
# ==============================================================================
# Telit LN940 (LN940A9 / Foxconn T77W676) USB Mode Switcher
# https://github.com/hungngit2/home-network
# ==============================================================================
# Quickly run via curl:
#   curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/lte-ln940-mode.sh | sudo bash
# ==============================================================================

set -u

VENDOR="1bc7"
PID_MBIM="1901"
PID_QMI="1900"

find_device() {
    for d in /sys/bus/usb/devices/*; do
        [ -f "$d/idVendor" ] || continue

        if [ "$(cat "$d/idVendor" 2>/dev/null)" = "$VENDOR" ]; then
            case "$(cat "$d/idProduct" 2>/dev/null)" in
                "$PID_MBIM"|"$PID_QMI")
                    echo "$d"
                    return 0
                    ;;
            esac
        fi
    done

    return 1
}

find_at_port() {
    for p in /dev/ttyUSB*; do
        [ -e "$p" ] || continue

        # Try the port without changing its mode.
        exec 3<> "$p" 2>/dev/null || continue

        # Flush old data.
        timeout 0.2 cat <&3 >/dev/null 2>&1 || true

        printf "AT\r" >&3
        sleep 0.5

        response="$(timeout 1 cat <&3 2>/dev/null | tr -d '\r')"

        exec 3>&-

        if echo "$response" | grep -q "OK"; then
            echo "$p"
            return 0
        fi
    done

    return 1
}

show_status() {
    echo
    echo "=== LN940 USB status ==="

    if ! lsusb | grep -qi "1bc7:190"; then
        echo "LN940 not found."
        return 1
    fi

    lsusb | grep -i "1bc7:190"

    echo
    echo "=== USB tree ==="
    lsusb -t

    echo
    echo "=== Serial ports ==="
    ls -l /dev/ttyUSB* 2>/dev/null || echo "No ttyUSB ports."

    echo
    echo "=== AT mode ==="

    AT_PORT="$(find_at_port || true)"

    if [ -n "${AT_PORT:-}" ]; then
        echo "AT port: $AT_PORT"

        exec 3<> "$AT_PORT"

        printf "AT^SETMODE?\r" >&3
        sleep 0.5

        timeout 2 cat <&3 2>/dev/null | tr -d '\r' | head -20 || true

        exec 3>&-
    else
        echo "AT port not found."
    fi
}

change_mode() {
    local MODE="$1"

    case "$MODE" in
        1)
            TARGET="MBIM + Serial"
            ;;
        2)
            TARGET="QMI + Serial"
            ;;
        *)
            echo "Invalid mode."
            return 1
            ;;
    esac

    echo
    echo "=== LN940 mode switch ==="
    echo "Target mode: SETMODE=$MODE ($TARGET)"
    echo

    DEV="$(find_device || true)"

    if [ -z "$DEV" ]; then
        echo "[ERROR] LN940 not found."
        return 1
    fi

    echo "[OK] Device: $DEV"

    AT_PORT="$(find_at_port || true)"

    if [ -z "${AT_PORT:-}" ]; then
        echo "[ERROR] AT port not found."
        echo
        echo "Try reconnecting the LN940 USB device and run this script again."
        return 1
    fi

    echo "[OK] AT port: $AT_PORT"

    echo
    echo "--- Current mode ---"

    exec 3<> "$AT_PORT"

    printf "AT^SETMODE?\r" >&3
    sleep 0.5

    timeout 2 cat <&3 2>/dev/null | tr -d '\r' | head -20 || true

    echo
    echo "--- Switching to SETMODE=$MODE ---"

    printf "AT^SETMODE=$MODE\r" >&3

    # The modem may disappear immediately after accepting the command.
    sleep 2

    timeout 2 cat <&3 2>/dev/null | tr -d '\r' | head -20 || true

    exec 3>&-

    echo
    echo "[OK] Command sent."
    echo "[*] Waiting for modem to reboot and re-enumerate..."

    sleep 12

    echo
    echo "=== Result ==="

    if lsusb | grep -qi "1bc7:190"; then
        lsusb | grep -i "1bc7:190"
    else
        echo "[WARNING] LN940 has not appeared yet."
        echo "Wait a few more seconds and run this script again."
        return 1
    fi

    echo
    echo "=== USB tree ==="
    lsusb -t

    echo
    echo "[DONE]"
}

if [ "$EUID" -ne 0 ]; then
    echo "Please run this script with sudo:"
    echo "  sudo $0"
    exit 1
fi

while true; do
    echo
    echo "======================================"
    echo "       Telit LN940 Mode Switcher"
    echo "======================================"
    echo
    echo "  1) MBIM + Serial  (SETMODE=1)"
    echo "  2) QMI  + Serial  (SETMODE=2)"
    echo "  3) Show current status"
    echo "  0) Exit"
    if [ -t 0 ]; then
        read -rp "Select: " choice
    elif [ -r /dev/tty ]; then
        read -rp "Select: " choice < /dev/tty
    else
        echo "Non-interactive shell detected. Defaulting to show status."
        show_status
        exit 0
    fi

    case "$choice" in
        1)
            change_mode 1
            ;;
        2)
            change_mode 2
            ;;
        3)
            show_status
            ;;
        0)
            exit 0
            ;;
        *)
            echo "Invalid selection."
            ;;
    esac
done