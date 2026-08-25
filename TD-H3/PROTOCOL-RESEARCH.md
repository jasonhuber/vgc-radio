# TD-H3 protocol research

Research date: **2026-08-24**

This note separates three targets that share a product name but do **not** share
one safe codeplug implementation:

1. Original TD-H3 with stock firmware - first target.
2. Original TD-H3 with NicSure/nicFW - later adapter.
3. TD-H3 Plus - separate 16 KiB adapter, later.

## 1. Original TD-H3, stock firmware

### Confirmed protocol facts

The current CHIRP TD-H3 driver and the original engineering trace agree on the
stock programming session:

| Item | Value |
|---|---|
| Serial speed | 38,400 baud, 8N1 |
| Codeplug | 8 KiB (`0x0000` through `0x1FFF`) |
| Block size | 32 bytes |
| Session opener | `50 56 4F 4A 48 5C 14` (`PVOJH\x5c\x14`) |
| Open acknowledgement | `06` |
| Identity request | `02` |
| Identity acknowledgement | host `06`, then radio `06` |
| Read request | `52 addr_hi addr_lo 20` |
| Read reply | `57 addr_hi addr_lo 20` + 32 bytes + checksum |
| Write request | `57 addr_hi addr_lo 20` + 32 bytes + checksum |
| Write acknowledgement | `06` |
| End transaction | `45` (`E`) |
| Checksum | sum of the 32 data bytes modulo 256 |

The archived local CHIRP image begins with `P31183 FF FF`, identifying the
previous original TD-H3 as the normal/unlocked variant. CHIRP uses:

| Identity | Meaning in the CHIRP driver |
|---|---|
| `P31183 FF FF` | normal/unlocked |
| `P31184 FF FF` | GMRS |
| `P31185 FF FF` | amateur/HAM |

The browser must compare the returned identity against the selected adapter and
disable writes on an unknown identity.

### Stock channel layout

CHIRP exposes channels **1-199**. The radio image has a 200-entry array of
16-byte channel records at raw EEPROM offset `0x0000`; array entry zero is not a
user channel, so user channel 1 begins at raw offset `0x0010`. CHIRP's in-memory
image has an 8-byte identity prefix, so its structure definition starts at file
offset `0x0008` and indexes the array by the displayed channel number.

| Relative offset | Size | Meaning |
|---|---:|---|
| `0x00` | 4 | RX frequency, little-endian BCD |
| `0x04` | 4 | TX frequency, little-endian BCD |
| `0x08` | 2 | RX CTCSS/DCS |
| `0x0A` | 2 | TX CTCSS/DCS |
| `0x0C` | 1 | reserved/unknown |
| `0x0D` | 1 | PTT ID, hopping, busy-channel lock and unknown flags |
| `0x0E` | 1 | scramble, power, bandwidth, offset-direction flags |
| `0x0F` | 1 | reserved/firmware-dependent |

Stock channel names are eight bytes each in the name table. Important settings,
valid-channel bits, scan bits, VFOs, startup text, DTMF data, and microphone gain
are also mapped by CHIRP.

Implementation rule: read-modify-write the radio's original bytes. Do not
generate a whole image full of defaults because many reserved bytes are
firmware-dependent.

### BLE transport hypothesis

The original TD-H3 is officially programmable over Bluetooth through ODMaster,
but the exact GATT listing for the incoming stock build must be captured before
we call it confirmed. The strongest current hypothesis is:

| Purpose | UUID |
|---|---|
| Service | `0000ff00-0000-1000-8000-00805f9b34fb` |
| Notifications/read | `0000ff01-0000-1000-8000-00805f9b34fb` |
| Writes | `0000ff02-0000-1000-8000-00805f9b34fb` |

Those UUIDs are verified for TD-H3/NicSure and TD-H3 Plus. They are a common
BLE-UART layout, so the protocol should see a byte stream rather than BLE-sized
messages. Accumulate notification fragments and parse complete replies by their
expected length.

The H3 Plus browser implementation sends `AT+BAUD?\r\n` before the PVOJH opener.
Whether the original stock H3 needs that BLE-module setup command is a hardware
test, not an assumption.

## 2. Original TD-H3 with NicSure/nicFW

NicSure is a different firmware and codeplug schema. Current public
interoperability notes describe:

| Item | Value |
|---|---|
| EEPROM | 8 KiB, 256 blocks of 32 bytes |
| Normal ping | `01`, expect `01` echo |
| Read block | `30 block_number`; reply `30` + 32 bytes + checksum |
| Write block | `31 block_number` + 32 bytes + checksum; reply `31` |
| Settings magic | `0xD82F` after endianness detection |
| Channels | 198 records at `0x0040`, 32 bytes each |
| VFO memories | two 32-byte records at `0x0000` and `0x0020` |
| Settings | four blocks beginning at `0x1900` |
| Groups | 16 six-character labels plus four membership nibbles per channel |
| BLE | FF00 service, FF01 notify/read, FF02 write |

NicSure V2.0X and V2.5X use different endianness/layout rules. Detection must be
from magic and plausible values, never from the advertised radio name.

### What NicSure adds to the project

- Real groups/banks, closer to the VGC layout concept.
- Band-plan and scan-preset editing.
- DTMF presets and more settings.
- Partial live telemetry and experimental remote control.

### What it does not yet give us

- A proven stock-compatible codeplug.
- Reliable, decoded live squelch/channel/RSSI events comparable to Benshi.
- A coverage logger we can honestly label as working.

Remote telemetry must be treated as a lab feature. An echo, battery text, or
unknown packet is not proof that a control command worked.

## 3. TD-H3 Plus

The H3 Plus is useful evidence and a later target, but it is not the original
H3 adapter with a different label.

| Item | H3 Plus finding |
|---|---|
| BLE service | FF00 / FF01 / FF02 |
| Session setup | `AT+BAUD?`, PVOJH opener, `02`, `06` |
| Memory | 16 KiB (`0x0000` through `0x3FFF`) |
| Channels | 199 16-byte records plus separate names/bitmaps |
| Browser implementation | Working pure HTML/CSS/JS Web Bluetooth CPS |
| Safe write behavior | Writes only documented ranges; some unmapped ranges do not ACK |

The exact ODMaster-derived write ranges matter. A future Plus adapter should be
based on its own 16 KiB raw backup and range allow-list.

## Family compatibility plan

| Model/firmware | Initial status | Adapter strategy |
|---|---|---|
| Original TD-H3 stock | **Build first** | Stock 8 KiB/PVOJH adapter |
| Original TD-H3 NicSure | Build second if wanted | NicSure 8 KiB/block adapter selected by settings magic |
| TD-H3 Plus | Later | Separate 16 KiB adapter; never accept an original-H3 image |
| TD-H8 / TD-H8 Gen 3 | Probe later | CHIRP shows related PVOJH openers/layouts, but confirm BLE and identity on hardware |
| TD-H9 / H8 4th Gen | Defer | Treat as unknown until protocol and memory map are captured |

## Safety invariants for the implementation

1. Identify model, operating mode, firmware family, image size, and endianness
   before enabling writes.
2. Save a raw pre-write backup on every programming run.
3. Never convert an H3, H3 Plus, stock, or NicSure image implicitly.
4. Preserve every untouched byte.
5. Write only documented, aligned blocks.
6. Require a typed destination confirmation when two matching radios are near.
7. Re-read every changed block and compare bytes.
8. Abort on unknown identity, timeout, checksum failure, disconnect, or readback
   difference.
9. Keep firmware flashing in a separate screen/module with its own warnings and
   recovery instructions.
10. Keep calibration/power-table regions read-only unless a dedicated,
    hardware-verified tool is intentionally built.
