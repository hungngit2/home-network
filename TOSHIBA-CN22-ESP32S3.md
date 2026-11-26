# Toshiba CN22 HVAC Integration with ESP32-S3 Super Mini & ESPHome

This guide details how to connect an ESP32-S3 Super Mini to a Toshiba air conditioner via the CN22 indoor unit connector and integrate it into Home Assistant using ESPHome.

---

## ⚠️ Important Safety & Hardware Warnings
1. **Power Supply**: The Toshiba CN22 port provides **12V DC**. The ESP32-S3 Super Mini operates at **3.3V** and its 5V pin **cannot** accept 12V. Connecting 12V directly will instantly destroy the ESP32-S3. You **must** use a step-down buck converter (e.g., Mini-360 or MP1584) to convert 12V to 5V (or 3.3V).
2. **Logic Levels**: Toshiba CN22 serial communication uses **5V TTL logic**. ESP32-S3 GPIO pins are **3.3V and NOT 5V tolerant**. Use a bidirectional logic level converter or a simple resistor voltage divider on the RX pin of the ESP32 to prevent damage.

---

## 1. Pinout & Wiring

### CN22 Connector Pinout (Toshiba Indoor Unit)
| Pin | Function | Description |
|---|---|---|
| **1** | **+12V DC** | Power supply from AC unit (Connect to Buck Converter input) |
| **2** | **GND** | Ground (Common ground for power and serial) |
| **3** | **TX** | Transmit from AC unit |
| **4** | **RX** | Receive to AC unit |
| **5** | **Data / Mode** | (Optional / Varies by model - usually not needed for standard 4-wire CN22) |

### Wiring Diagram
```text
Toshiba CN22 Header       Buck Converter (12V -> 5V)       ESP32-S3 Super Mini
--------------------      --------------------------       -------------------
Pin 1 (12V) ------------> IN+
Pin 2 (GND) ------------> IN- & GND ---------------------> GND
                          OUT+ (5V) ---------------------> 5V (or VIN)
                          OUT- (GND)

Toshiba RX (Pin 4) -------> Level Shifter / Resistor -------> ESP32 RX (e.g., GPIO 6)
Toshiba TX (Pin 3) -------> Level Shifter / Resistor -------> ESP32 TX (e.g., GPIO 5)
```

---

## 2. ESPHome Configuration (`toshiba-cn22.yaml`)

Create your ESPHome configuration using the external component for Toshiba CN22 (e.g., `esphome-toshiba-cn22` or compatible climate component):

```yaml
esphome:
  name: toshiba-ac
  friendly_name: Toshiba AC

esp32:
  board: esp32-s3-devkitc-1
  variant: ESP32S3
  framework:
    type: esp-idf

wifi:
  ssid: "YOUR_WIFI_SSID"
  password: "YOUR_WIFI_PASSWORD"

  ap:
    ssid: "Toshiba-Ac Fallback Hotspot"
    password: "fallback_password"

captive_portal:

logger:
  baud_rate: 115200

api:
  encryption:
    key: "YOUR_API_ENCRYPTION_KEY"

ota:
  - platform: esphome
    password: "YOUR_OTA_PASSWORD"

uart:
  id: uart_bus
  tx_pin: GPIO5
  rx_pin: GPIO6
  baud_rate: 9600
  data_bits: 8
  parity: NONE
  stop_bits: 1

climate:
  - platform: toshiba_ac
    id: living_room_ac
    name: "Living Room AC"
    visual:
      min_temperature: 17 °C
      max_temperature: 30 °C
      temperature_step: 0.5 °C
```

*(Note: Depending on the specific third-party ESPHome component repository you use, such as `github: rcblackwell/esphome-toshiba-cn22` or `github:rieden/esphome-toshiba-ac`, add the `external_components` block at the top of your YAML).*

---

## 3. Home Assistant Integration

1. Once the ESP32-S3 running ESPHome connects to your network, Home Assistant will automatically discover the **Toshiba AC** device via the ESPHome integration.
2. Go to **Settings > Devices & Services > ESPHome** and click **Configure**.
3. Add the climate entity to your Lovelace dashboard for full control over temperature, fan speed, swing, and mode.
