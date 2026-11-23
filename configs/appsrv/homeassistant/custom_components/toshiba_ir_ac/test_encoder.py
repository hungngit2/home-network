"""Run with: python3 test_encoder.py (no Home Assistant needed)."""

from encoder import decode_bytes, encode, short_bytes, state_bytes

# Off, 27 degrees, learned from the real Floor 2 remote through the hub.
LEARNED_OFF = (
    "GhEaERwCXAYcAlwGHAJcBhwCXAYcAhwCHAIcAhwCXAYcAhwCHAIcAhwCHAIcAhwCHAIcAhwCXAYcAlwGHAIcAhwCXAYcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAlwGHAJcBhwCXAYcAlwGHAJcBhwCXAYcAlwGHAJcBhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCXAYcAlwGHAIcAhwCXAYcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAJcBhwCXAYcAlwGHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAlwGHAIcAhwCXAYcAhwCHAIcAhwCXAYcAlwGHAIcAhwCIRoaERoRHAJcBhwCXAYcAlwGHAJcBhwCHAIcAhwCHAJcBhwCHAIcAhwCHAIcAhwCHAIcAhwCHAJcBhwCXAYcAhwCHAJcBhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCXAYcAlwGHAJcBhwCXAYcAlwGHAJcBhwCXAYcAlwGHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAJcBhwCXAYcAhwCHAJcBhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAlwGHAJcBhwCXAYcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCHAIcAhwCXAYcAhwCHAJcBhwCHAIcAhwCHAJcBhwCXAYcAhwCHAIwdQ=="
)


def test_off_matches_the_learned_code_pulse_for_pulse() -> None:
    assert encode(state_bytes(27, "off", "auto")) == LEARNED_OFF
    assert decode_bytes(LEARNED_OFF) == [[0xF2, 0x0D, 0x03, 0xFC, 0x01, 0xA0, 0x07, 0x00, 0xA6]] * 2


def test_round_trip_and_checksum() -> None:
    for temp in (17, 24, 30):
        for mode in ("auto", "cool", "dry", "fan_only"):
            for fan in ("auto", "1", "3", "5"):
                message = state_bytes(temp, mode, fan)
                assert decode_bytes(encode(message)) == [message, message]
                checksum = 0
                for byte in message[:-1]:
                    checksum ^= byte
                assert message[-1] == checksum


def test_known_fields() -> None:
    assert state_bytes(24, "cool", "auto")[:7] == [0xF2, 0x0D, 0x03, 0xFC, 0x01, 0x70, 0x01]
    assert state_bytes(28, "cool", "5")[6] == 0x01 | (6 << 5)
    assert short_bytes(0x04) == [0xF2, 0x0D, 0x01, 0xFE, 0x21, 0x04, 0x25]
    assert short_bytes(0x00) == [0xF2, 0x0D, 0x01, 0xFE, 0x21, 0x00, 0x21]


if __name__ == "__main__":
    test_off_matches_the_learned_code_pulse_for_pulse()
    test_round_trip_and_checksum()
    test_known_fields()
    print("encoder checks passed")
