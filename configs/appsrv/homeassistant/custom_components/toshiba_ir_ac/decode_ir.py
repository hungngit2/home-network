"""Show what a learned hub IR code contains: python3 decode_ir.py <base64 code>

A Toshiba AC code prints messages starting F2 0D; anything else is another brand and
can still be sent as it is (see README.md).
"""

import base64
import sys

from encoder import decode_bytes


def main(code: str) -> None:
    raw = base64.b64decode(code + "=" * (-len(code) % 4))
    pulses = [int.from_bytes(raw[i : i + 2], "little") for i in range(0, len(raw) - 1, 2)]
    print(f"{len(pulses)} pulses, first pair {pulses[:2]}")
    if len(pulses) < 20 or not any(pulses):
        print("Empty or noise: the hub returned no real signal. Learn it again.")
        return
    for n, message in enumerate(decode_bytes(code)):
        print(f"message {n}: {' '.join(f'{b:02X}' for b in message)}")
    first = (decode_bytes(code) or [[]])[0]
    print("Toshiba AC message" if first[:2] == [0xF2, 0x0D] else "Not a Toshiba AC message")


if __name__ == "__main__":
    main(sys.argv[1])
