# TD-H3 browser programmer research

Research date: **2026-08-24**  
Target hardware: the incoming **two original TIDRADIO TD-H3 5 W radios**, not
the TD-H3 Plus.

## Recommendation

**Yes - build the TD-H3 version.** The evidence is strong enough to justify a
small hardware-first prototype:

- The original TD-H3 programming protocol is documented and implemented in
  CHIRP.
- Stock firmware uses an 8 KiB codeplug, 32-byte block reads/writes, and a
  simple additive checksum.
- The documented TD-H3/NicSure Bluetooth link behaves as a byte stream, which
  maps cleanly onto Web Bluetooth; the incoming stock build needs one GATT
  capture to confirm it exposes the same path.
- The related TD-H3 Plus already has a working pure-HTML Web Bluetooth CPS,
  proving that this radio family and browser architecture are a good match.
- NicSure/nicFW has current protocol and memory-map documentation plus a tested
  native BLE programmer.

This should be a **separate TD-H3 app/adapter**, eventually published at
something like `n7wgp.com/h3`, rather than mixing TD-H3 packets into the
Benshi/VGC transport. The UI concepts and channel library can be shared later,
but the wire protocol, codeplug layout, safety rules, and live capabilities are
different.

## What should match the VGC tool

| Capability | TD-H3 outlook | Notes |
|---|---|---|
| Connect from Chrome/Edge/Brave | **Likely** | Must confirm the original stock radio's advertised service and characteristics on arrival. |
| Read all memories | **High confidence** | 199 user channels on stock firmware. |
| Edit and write memories | **High confidence after read-only probe** | Back up first, write changed 32-byte blocks only, then read back. |
| Import the master CHIRP CSV | **High confidence** | Existing corrected CSV is already the right source data. |
| Save/restore raw backups | **High confidence** | Preserve the exact 8 KiB radio image; CHIRP `.img` adds its own header/trailer metadata. |
| Diff plan versus radio | **High confidence** | Easier than the VGC group model because stock H3 has a flat 199-channel table. |
| General settings editor | **Feasible** | CHIRP maps the important stock settings. Start with read-only display. |
| Groups/banks | **Firmware-dependent** | Stock H3 has no CHIRP bank model. NicSure has 16 group labels and channel memberships. |
| Live RSSI/status | **Experimental on NicSure** | Telemetry is only partly decoded. Do not promise this on stock firmware. |
| Coverage logger | **Not an MVP feature** | The VGC logger depends on reliable live squelch/channel/RSSI events that are not yet established here. |
| APRS/TNC | **No** | The original TD-H3 is not the VGC's APRS/BSS/TNC radio. TD-H9 is the TIDRADIO model to investigate for GPS/APRS. |
| Firmware flashing | **Possible later, USB only** | Keep it separate from routine programming and require an explicit image/recovery workflow. |

## Why the original TD-H3 is the right first target

The two radios already ordered are the useful version for this project:

- The **original TD-H3** has the mature CHIRP driver and is the supported
  custom-firmware platform.
- The **TD-H3 Plus is not a drop-in protocol twin**. It has a separate 16 KiB
  memory map even though it uses the same general FF00/FF01/FF02 BLE-UART shape
  and PVOJH-style handshake.
- The existing archived codeplug identifies the earlier radio as unlocked
  `P31183`, which gives us a known-good comparison image.

The best use of the two-pack is:

1. Keep radio A on stock firmware as the reference and daily-use unit.
2. Keep radio B stock until the browser read/write path passes backup and
   readback tests.
3. Only then decide whether radio B should become the NicSure development unit.

That preserves a recovery/reference radio and lets us compare the same channel
plan across firmware families.

## Proposed MVP

The first useful release should do only this:

1. Connect over Web Bluetooth.
2. Identify the exact model/mode before enabling writes.
3. Read and download a raw 8 KiB backup.
4. Decode and display all 199 stock channels.
5. Import/export CHIRP CSV and compare with
   `../../03-Codeplugs/Current/MASTER-Channel-List-CORRECTED.csv`.
6. Show a field-level diff.
7. Write only changed aligned blocks after a typed destination confirmation.
8. Re-read each changed block and fail closed on any mismatch.

Do **not** put firmware flashing, remote PTT, live control, or NicSure editing in
the first write-capable release.

## Architecture recommendation

```text
shared channel library / CSV / diff UI
                 |
        TD-H3 application shell
                 |
      model + firmware classifier
          /                   \
stock H3 codeplug adapter   NicSure adapter (later)
          \                   /
          byte-stream transport
             /             \
      Web Bluetooth      Web Serial (later)
```

Keep the transport byte-oriented. BLE notifications can be accumulated into a
receive buffer, while writes should be split into 20-byte GATT payloads unless
the browser/radio negotiates something larger. The protocol layer should not
care whether the bytes came from BLE or USB serial.

## Important product-language caveat

"8-band" is a receive-coverage marketing description, not eight amateur
transmit bands. The archived H3 manual identifies the primary transmit ranges
as **136-174 MHz and 400-520 MHz**. Legal transmit limits still depend on the
radio mode, license, country, and service. The app must validate intended use
and must never present an unlocked codeplug as permission to transmit.

## Open questions that require the radios

- Does the incoming stock firmware advertise service `FF00` with notify/read
  `FF01` and write `FF02`, exactly like the documented NicSure and H3 Plus
  paths?
- Does stock firmware require `AT+BAUD?` before the PVOJH opener over BLE?
- Does it accept GATT writes with response, without response, or both?
- Is pairing/bonding required, or is a direct GATT connection enough?
- Does a read reply put its checksum before or after the 32-byte payload on
  this firmware? Existing sources disagree in prose, so the first capture must
  settle it from bytes.
- Does stock firmware expose any useful live status or only programming mode?
- Which exact firmware build and identity arrive in the new two-pack?

The safe arrival procedure is in [FIRST-RADIO-TEST.md](FIRST-RADIO-TEST.md).
Wire-level details are in [PROTOCOL-RESEARCH.md](PROTOCOL-RESEARCH.md), and all
sources are recorded in [SOURCES.md](SOURCES.md).
