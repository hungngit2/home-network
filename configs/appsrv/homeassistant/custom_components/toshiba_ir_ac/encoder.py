"""Toshiba AC infrared frames in the Tuya hub format (base64 of little-endian 16-bit microsecond durations).

Timing and layout come from a code learned from the real remote; the Off frame is reproduced pulse for pulse.
Bytes are sent MSB first, each message twice, and the last byte is the XOR of the others.
"""

from __future__ import annotations

import base64

HEADER = (4378, 4378)
MARK = 540
ONE_SPACE = 1628
ZERO_SPACE = 540
FRAME_GAP = 6689
END_GAP = 30000

MODES = {"auto": 0, "cool": 1, "dry": 2, "heat": 3, "fan_only": 4, "off": 7}
FAN_SPEEDS = {"auto": 0, "1": 2, "2": 3, "3": 4, "4": 5, "5": 6}
MIN_TEMP = 17
MAX_TEMP = 30

SWING_FRAME = 0x04
FIX_FRAME = 0x00
FLAG_BOOST = 0x01
FLAG_ECO = 0x03


def _checksum(data: list[int]) -> int:
    value = 0
    for byte in data:
        value ^= byte
    return value


def state_bytes(temp: int, mode: str, fan: str, flags: int | None = None) -> list[int]:
    """9-byte state message, or 10 bytes with a flags byte for boost and eco."""
    if not MIN_TEMP <= temp <= MAX_TEMP:
        raise ValueError(f"temperature {temp} outside {MIN_TEMP}-{MAX_TEMP}")
    body = [0xF2, 0x0D, 0x03, 0xFC, 0x01, (temp - MIN_TEMP) << 4, MODES[mode] | (FAN_SPEEDS[fan] << 5), 0x00]
    if flags is not None:
        body = [0xF2, 0x0D, 0x04, 0xFB, 0x09, *body[5:8], flags]
    return [*body, _checksum(body)]


def short_bytes(code: int) -> list[int]:
    """7-byte message used by the swing and fix keys."""
    body = [0xF2, 0x0D, 0x01, 0xFE, 0x21, code]
    return [*body, _checksum(body)]


def pulses(message: list[int], repeats: int = 2) -> list[int]:
    out: list[int] = []
    for n in range(repeats):
        out.extend(HEADER)
        for byte in message:
            for i in range(7, -1, -1):
                out.extend((MARK, ONE_SPACE if (byte >> i) & 1 else ZERO_SPACE))
        out.extend((MARK, FRAME_GAP if n < repeats - 1 else END_GAP))
    return out


def encode(message: list[int], repeats: int = 2) -> str:
    return base64.b64encode(b"".join(p.to_bytes(2, "little") for p in pulses(message, repeats))).decode()


def decode_bytes(code: str) -> list[list[int]]:
    """Read the messages back out of a hub code; used by the self-check."""
    raw = base64.b64decode(code + "=" * (-len(code) % 4))
    p = [int.from_bytes(raw[i : i + 2], "little") for i in range(0, len(raw) - 1, 2)]
    messages: list[list[int]] = []
    bits: list[int] = []
    in_header = True
    for i in range(0, len(p) - 1, 2):
        space = p[i + 1]
        if in_header:
            in_header = False
        elif space > 5000:
            messages.append([sum(bits[j + k] << (7 - k) for k in range(8)) for j in range(0, len(bits) - 7, 8)])
            bits, in_header = [], True
        else:
            bits.append(1 if space > 1000 else 0)
    return messages
