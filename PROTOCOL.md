# Benshi BLE protocol reference

Applies to the whole family — **VGC VR-N76, VR-N7500, VR-N7600, BTech UV-Pro,
RadioOddity GA-5WB**. Reverse-engineered by Kyle Husmann in
[benlink](https://github.com/khusmann/benlink) (Apache-2.0); this file is the subset
needed to reimplement it, extracted so a future session need not re-read the
Python.

Everything below is confirmed against benlink's source. Anything marked
**GUESS** is not.

## Transport

Custom GATT service:

| Role | UUID |
|---|---|
| Service | `00001100-d102-11e1-9b23-00025b00a5a5` |
| Write | `00001101-d102-11e1-9b23-00025b00a5a5` |
| Indicate | `00001102-d102-11e1-9b23-00025b00a5a5` |

**Over BLE messages are sent raw.** The GAIA frame wrapper
(`ff 01 <flags> <len> …`) applies only to the RFCOMM/Bluetooth-Classic path.

The radio **requires a bonded (encrypted) link**. An unbonded central is
accepted and then dropped within a second, which looks exactly like a failed
connection. On macOS the browser delegates bonding to the OS, so the system
pairing prompt *is* the browser pairing — accept it.

## Message framing

32-bit header, MSB-first, **not byte-aligned**:

```
command_group : u16    BASIC = 2, EXTENDED = 10
is_reply      : 1
command       : u15
body          : remainder
```

`READ_RF_CH` for channel 0 is exactly `00 02 00 0d 00`.

## Commands (group BASIC = 2)

Decoded bodies are marked ✓ — those can be implemented directly. Most of this
table is benlink; a handful of rows (marked accordingly) come from
[Ylianst/HTCommander](https://github.com/Ylianst/HTCommander)
(`docs/blogs/radio-command-protocol.md`, Apache-2.0), a separate,
hardware-confirmed reverse-engineering effort that has gone further than
benlink on the region/group commands.

| ID | Command | Body decoded |
|---|---|---|
| 1 | `GET_DEV_ID` | |
| 4 | `GET_DEV_INFO` | ✓ |
| 5 | `READ_STATUS` (battery/power) | ✓ |
| 6 | `REGISTER_NOTIFICATION` | ✓ |
| 9 | `EVENT_NOTIFICATION` (push) | ✓ |
| 10 / 11 | `READ_SETTINGS` / `WRITE_SETTINGS` | ✓ |
| 13 / 14 | `READ_RF_CH` / `WRITE_RF_CH` | ✓ |
| 20 | `GET_HT_STATUS` | ✓ |
| 21 | `SET_HT_ON_OFF` | |
| 22 / 23 | `GET_VOLUME` / `SET_VOLUME` | |
| 24–28 | `RADIO_*` (FM broadcast receiver) | |
| 29 / 30 | `READ`/`WRITE_ADVANCED_SETTINGS` | |
| 31 | `HT_SEND_DATA` (TNC transmit) | ✓ |
| 32 | `SET_POSITION` | |
| 33 / 34 | `READ`/`WRITE_BSS_SETTINGS` (APRS) | ✓ |
| 39 | `READ_FREQ_RANGE` | |
| 41 | `STOP_RINGING` | |
| 42 | `SET_TX_TIME_LIMIT` | |
| 55 / 56 | `GET_PF` / `SET_PF` | ✓ |
| 57 | `RX_DATA` | |
| 58 | `WRITE_REGION_CH` | |
| 59 | `WRITE_REGION_NAME` | ✓ (HTCommander) — see below |
| 60 | `SET_REGION` | index still **GUESS: u8**; **confirmed no reply at all** (HTCommander) |
| 67 / 68 | `SET_MSG` / `GET_MSG` | |
| 70 | `SET_TIME` | |
| 71 / 72 | `SET`/`GET_APRS_PATH` | ✓ (HTCommander) — not yet implemented here, see PLAN.md |
| 73 | `READ_REGION_NAME` | ✓ (HTCommander) — see below |

`ReplyStatus` (u8): `0 SUCCESS · 1 NOT_SUPPORTED · 2 NOT_AUTHENTICATED ·
3 INSUFFICIENT_RESOURCES · 4 AUTHENTICATING · 5 INVALID_PARAMETER ·
6 INCORRECT_STATE · 7 IN_PROGRESS`

Writing to a slot beyond the radio's `channel_count` returns
`INVALID_PARAMETER` per channel — check capacity first.

## `DevInfo` — reply to GET_DEV_INFO (80 bits)

Request body is a literal `3` (u8). Reply is `reply_status:u8` then:

| Bits | Field |
|---|---|
| 8 | `vendor_id` |
| 16 | `product_id` |
| 8 | `hw_ver` |
| 16 | `soft_ver` |
| 1 ×6 | `support_radio`, `support_medium_power`, `fixed_loc_speaker_vol`, `not_support_soft_power_ctrl`, `have_no_speaker`, `have_hm_speaker` |
| 6 | `region_count` ← **number of channel groups** |
| 1 ×4 | `support_noaa`, `gmrs`, `support_vfo`, `support_dmr` |
| 8 | `channel_count` ← **slots per group** |
| 4 | `freq_range_count` |
| 4 | pad |

Total capacity is `region_count × channel_count`. A VR-N76 is specced at
6 × 32 = 192; the hardware here reports `channel_count: 32` correctly but an
unreliable `region_count`.

## `RfCh` — a channel, 200 bits / 25 bytes

| Bits | Field | Notes |
|---|---|---|
| 8 | `channel_id` | slot number |
| 2 | `tx_mod` | 0 FM · 1 AM · 2 DMR |
| 30 | `tx_freq` | **Hz** — 146.820 MHz = 146820000 |
| 2 | `rx_mod` | |
| 30 | `rx_freq` | Hz |
| 16 | `tx_sub_audio` | see below |
| 16 | `rx_sub_audio` | |
| 1 | `scan` | |
| 1 | `tx_at_max_power` | |
| 1 | `talk_around` | |
| 1 | `bandwidth` | 0 narrow · 1 wide |
| 1 | `pre_de_emph_bypass` | |
| 1 | `sign` | |
| 1 | `tx_at_med_power` | |
| 1 | `tx_disable` | |
| 1 | `fixed_freq` | |
| 1 | `fixed_bandwidth` | |
| 1 | `fixed_tx_power` | |
| 1 | `mute` | |
| 4 | pad | |
| 80 | `name` | 10 bytes ASCII |

**Sub-audio** is one 16-bit field encoding three states:

- `0` → no tone
- `< 6700` → DCS code (`23` = DCS 023)
- `>= 6700` → CTCSS Hz × 100 (`8850` = 88.5 Hz)

Power is two booleans: max → High, med → Medium, neither → Low.

TX and RX frequencies are independent — there is no duplex/offset concept on
the wire, so odd splits are free.

**DMR variant:** `RfChDMR` extends `RfCh` with `tx_color:4`, `rx_color:4`,
`slot:1`, `pad:7`. benlink picks the variant by body length. A decoder that
assumes plain `RfCh` will misread a DMR-capable radio.

## `Status` — reply to GET_HT_STATUS

Base is 16 bits; `StatusExt` adds 16 more. Switch on body length.

| Bits | Field |
|---|---|
| 1 | `is_power_on` |
| 1 | `is_in_tx` |
| 1 | `is_sq` |
| 1 | `is_in_rx` |
| 2 | `double_channel` (0 off, 1 A, 2 B) |
| 1 | `is_scan` |
| 1 | `is_radio` |
| 4 | `curr_ch_id_lower` |
| 1 | `is_gps_locked` |
| 1 | `is_hfp_connected` |
| 1 | `is_aoc_connected` |
| 1 | unknown |

`StatusExt` adds:

| Bits | Field |
|---|---|
| 4 | `rssi` (× 100/15 for percent) |
| 6 | `curr_region` ← **current group; use this to verify group switching** |
| 4 | `curr_channel_id_upper` |
| 2 | pad |

## Battery — READ_STATUS (5)

Request body is `PowerStatusType` as **u16**. Reply is `reply_status:u8`,
then the type echoed as u16, then the value.

| Type | Value |
|---|---|
| 1 `BATTERY_LEVEL` | u8 |
| 2 `BATTERY_VOLTAGE` | u16 ÷ 1000 → volts |
| 3 `RC_BATTERY_LEVEL` | u8 |
| 4 `BATTERY_LEVEL_AS_PERCENTAGE` | u8 |

## Events — REGISTER_NOTIFICATION (6) / EVENT_NOTIFICATION (9)

Register with a one-way `REGISTER_NOTIFICATION` carrying `event_type:u8`.
The radio then pushes `EVENT_NOTIFICATION` messages, which are **not replies**
and must be dispatched before any pending-request lookup.

| ID | Event | Payload |
|---|---|---|
| 1 | `HT_STATUS_CHANGED` | `Status` / `StatusExt` |
| 2 | `DATA_RXD` | `TncDataFragment` — incoming packet |
| 3 | `NEW_INQUIRY_DATA` | |
| 4 | `RESTORE_FACTORY_SETTINGS` | |
| 5 | `HT_CH_CHANGED` | `RfCh` |
| 6 | `HT_SETTINGS_CHANGED` | `Settings` |
| 7 | `RINGING_STOPPED` | |
| 8 | `RADIO_STATUS_CHANGED` | |
| 9 | `USER_ACTION` | |
| 10 | `SYSTEM_EVENT` | |
| 11 | `BSS_SETTINGS_CHANGED` | |

## `TncDataFragment` — packet data both directions

```
is_final_fragment : 1
with_channel_id   : 1
fragment_id       : 6
data              : remainder (minus 8 bits if with_channel_id)
channel_id        : u8, only if with_channel_id
```

Transmit with `HT_SEND_DATA` (31); receive via the `DATA_RXD` event.

## `BSSSettings` — APRS config

See `PLAN.md` item 1 for the full field table.

## Region (group) names and `SET_REGION`

Not from benlink — decoded by
[Ylianst/HTCommander](https://github.com/Ylianst/HTCommander/blob/main/docs/blogs/radio-command-protocol.md)
against real hardware (Apache-2.0). Region index is 0-based on the wire in
all three commands, same as everywhere else in this protocol; this app's UI
displays it as `index + 1` to match the radio's own screen (see `PLAN.md`).

- **`SET_REGION` (60)** — request body `[region:u8]`. **Gets no reply at
  all** — not even a status byte. Send it and move on; don't wait on a
  reply or you'll eat the full request timeout every time. (This app did,
  until 2026-08-24 — see `CHANGELOG.md`.) The index itself is still an
  unconfirmed guess; `SET_REGION` not actually switching groups and
  `SET_REGION` timing out because it was never going to reply are two
  different failure modes that look identical from the log, which is worth
  remembering if group switching still looks broken after the no-reply fix.
- **`READ_REGION_NAME` (73)** — request body `[region:u8]`. Reply:
  `reply_status:u8, region:u8, name` — name is UTF-8, null-padded to 10
  bytes on the radio (same width as `RfCh.name`), consume whatever remains
  of the frame rather than assuming exactly 10.
- **`WRITE_REGION_NAME` (59)** — request body `[region:u8] + name padded to
  10 bytes`. Unlike `SET_REGION`, this one replies normally with a
  `reply_status:u8`.

## Scanning — no start/stop command is known

Nothing in benlink or HTCommander starts or stops a scan. What exists is:

- `RfCh.scan` (1 bit per channel) — whether a channel is *included* in a sweep.
  Writable, and this app writes it.
- `Status.is_scan` — read-only telemetry saying a sweep is running.
- `EVENT.HT_CH_CHANGED` — pushes the new `RfCh` each time the radio lands on a
  different channel, which is how you follow a scan in progress.

So a controller can observe a scan completely and start one not at all. The
scan button is on the radio. An app-driven sweep would need a way to set the
*current* channel; the untested candidates are `WRITE_REGION_CH` (58, undecoded)
and `channel_a`/`channel_b` in `Settings` (10/11, decoded in benlink, not
implemented here).

## Audio — not reachable from a browser

benlink has an audio link, but it runs over **RFCOMM (Bluetooth Classic)**.
Web Bluetooth is BLE-only, so browser-based audio and Bluetooth PTT are
impossible. This is a platform limit, not an implementation gap.
