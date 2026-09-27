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
# ==============================================================================
# CONFIRMED HARDWARE / FIRMWARE STATE
# ==============================================================================
#   - Modem:        Telit LN940A9 / Telit LN940 Mobile Broadband
#                   (Foxconn T77W676 / HP lt4220)
#   - Baseband:     Qualcomm Snapdragon X12 LTE-A (MDM9640)
#   - Firmware:     T77W676.F0.0.0.4.7.DF.026 041
#   - IMPORTANT:    No firmware was flashed during the current mode conversion.
#                   Firmware remained DF.026 041 before and after the USB composition
#                   change. GC firmware is NOT required for the current RouterOS setup!
#
# ==============================================================================
# IMPORTANT TECHNICAL DISTINCTIONS
# ==============================================================================
#   QCMB_SDK_Tool            != Firmware flashing
#   USB composition change   != Firmware update
#
# ==============================================================================
# SUGGESTED COMPOSITION SUMMARY (Experimentally Observed Device Behavior)
# ==============================================================================
#   1BC7:1900 -> QMI / legacy composition
#   1BC7:1901 -> MBIM_EXT composition (required for RouterOS LTE + SMS + AT + USSD)
#   03F0:0857 -> HP QMI composition
#   03F0:0A57 -> HP MBIM composition
#
#   (Note: Phrased here as observed device behavior, not as an exhaustive
#    official Telit composition mapping unless backed by vendor documentation).
#
# ==============================================================================
# CUSTOMER CONFIGURATION (Observed Behavior)
# ==============================================================================
#   Command successfully executed:
#     AT^CUSTOMER=2
#
#   After this configuration, the modem was observed enumerating as:
#     1BC7:1900
#
#   (Note: What is confirmed experimentally is that this command was followed by
#    the 1BC7:1900 composition; do not overstate the mapping unless supported
#    by vendor documentation).
#
# ==============================================================================
# QCMB_SDK_TOOL (USB Composition Switcher)
# ==============================================================================
#   Windows tool:
#     QCMB_SDK_Tool.exe Win8_Mode_MBIM_EXT
#
#   Tool location:
#     D:\LN940A9\Firmware_Tool\Utilities\Firmware Selector Tool\QCMB_SDK_Tool.exe
#
#   Successful output included:
#     Command : GobiConnectA EXT_QMUX:{GUID}
#               Return Code : 0 : Success.
#     Command : ChangeDeviceDownLoadMode(4) is success.
#               Return Code : 0.
#     ChangedownloadMode : (4)
#
#   Conclusion:
#     1BC7:1900  -->  1BC7:1901
#
#   Key Facts:
#     - QCMB_SDK_Tool.exe Win8_Mode_MBIM_EXT does NOT flash firmware.
#     - "ChangeDeviceDownLoadMode(4)" is an SDK/API operation for changing the
#       modem mode/composition; the word "DownLoadMode" must NOT be interpreted
#       as "firmware download".
#     - The firmware image itself was not changed.
#     - The modem remained on T77W676.F0.0.0.4.7.DF.026 041.
#
# ==============================================================================
# MBIM_EXT COMPOSITION (1BC7:1901)
# ==============================================================================
#   After running the QCMB tool, Windows enumerated the modem as VID:PID 1BC7:1901
#   with the following composite interfaces (all confirmed as Status: OK):
#     MI_00  Diagnostic
#     MI_01  Modem
#     MI_02  Application Interface
#     MI_03  NMEA
#     MI_04  Mobile Broadband / MBIM
#
#   This confirms that 1BC7:1901 provides the MBIM_EXT-style composition required
#   for MBIM data, modem interface, application/AT interface, NMEA, and diagnostics.
#
# ==============================================================================
# DEBIAN TESTING
# ==============================================================================
#   The MBIM_EXT composition was also tested on Debian Linux.
#   Serial interfaces could be exposed with:
#     modprobe option
#     echo "03f0 0a57" > /sys/bus/usb-serial/drivers/option1/new_id
#     (or dynamically registering 1bc7 1901)
#   This produced /dev/ttyUSB* interfaces together with /dev/cdc-wdm0.
#   AT commands worked on the appropriate serial interface.
#
#   USSD was confirmed with:
#     AT+CUSD=1,"*101#",15
#   and the modem returned the actual VinaPhone balance/status response through +CUSD.
#
# ==============================================================================
# MIKROTIK ROUTEROS hEX S RESULT
# ==============================================================================
#   RouterOS version: 7.24.4
#   LTE interface:    lte1
#
#   With the modem in 1BC7:1901 / MBIM_EXT composition, RouterOS successfully provides:
#     - LTE Internet (Data)  [OK]
#     - SMS                  [OK]
#     - AT commands          [OK]
#     - USSD                 [OK]
#
#   AT-over-MBIM works through:
#     /interface/lte/at-chat lte1 input="AT"
#     Output: output: OK
#
#   USSD works through:
#     /interface/lte/at-chat lte1 input="AT+CUSD=1,\"*101#\",15"
#     Immediate result:
#       output: OK
#     Actual asynchronous USSD result in RouterOS log:
#       gsm,info USSD: So TB 0825657578 (VINA690). TK chinh=19900 VND, HSD 16/09/2027.
#       Ngay KH: 10/08/2022. Khoa1C: 16/09/2027. Khoa2C: 26/09/2027. CSKH 18001091 (0d)
#
#   SMS was also confirmed through:
#     /tool/sms/inbox/print
#
# ==============================================================================
# IMPORTANT USAGE NOTES
# ==============================================================================
#   - Do NOT change bConfigurationValue manually.
#   - SETMODE changes the USB composition and the modem re-enumerates.
#   - ttyUSB1 is normally the AT command port on this LN940 firmware.
# ==============================================================================

set -u

VID="1bc7"
PID_MBIM="1901"
PID_QMI="1900"

ALT_VID="03f0"
ALT_PID_MBIM="0a57"
ALT_PID_QMI="0857"

MODE=""

# ------------------------------------------------------------
# Ensure serial drivers are loaded and bound
# ------------------------------------------------------------
load_serial_drivers() {
    modprobe option 2>/dev/null || true
    modprobe qcserial 2>/dev/null || true

    # If LN940 is plugged in but no ttyUSB was registered yet, dynamically register IDs
    if [ ! -e /dev/ttyUSB0 ] && [ -d /sys/bus/usb-serial/drivers/option1 ]; then
        echo "${VID} ${PID_MBIM}" > /sys/bus/usb-serial/drivers/option1/new_id 2>/dev/null || true
        echo "${VID} ${PID_QMI}" > /sys/bus/usb-serial/drivers/option1/new_id 2>/dev/null || true
        echo "${ALT_VID} ${ALT_PID_MBIM}" > /sys/bus/usb-serial/drivers/option1/new_id 2>/dev/null || true
        echo "${ALT_VID} ${ALT_PID_QMI}" > /sys/bus/usb-serial/drivers/option1/new_id 2>/dev/null || true
    fi
}

# ------------------------------------------------------------
# Find LN940 USB device
# ------------------------------------------------------------
find_device() {
    if lsusb | grep -qiE "(${VID}:${PID_MBIM}|${ALT_VID}:${ALT_PID_MBIM})"; then
        MODE="MBIM + Serial"
        return 0
    fi

    if lsusb | grep -qiE "(${VID}:${PID_QMI}|${ALT_VID}:${ALT_PID_QMI})"; then
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
