# First-radio test plan

Use this when the incoming two-pack arrives. The first session is deliberately
read-only. Do not flash firmware or write a codeplug until the evidence bundle
below is complete.

## Label the radios

- Radio A: **STOCK-REFERENCE**
- Radio B: **DEVELOPMENT**

Photograph the battery-bay labels and keep the serial numbers out of committed
public notes. Use A/B in logs and screenshots.

## 1. Record without connecting

For each radio, record locally:

| Field | Radio A | Radio B |
|---|---|---|
| Package/model wording | | |
| Hardware revision | | |
| Firmware version shown by radio | | |
| Operating mode (Normal/HAM/GMRS) | | |
| Bluetooth menu options | | |
| Advertised Bluetooth name | | |
| USB-C data port works? | | |

Do not publish serial numbers, BLE device IDs, or a private codeplug dump.

## 2. Make independent backups

1. Use current CHIRP over USB-C to download radio A.
2. Save the untouched result outside this repository using a date and A/B label.
3. Repeat for radio B.
4. Confirm each file can be reopened and shows sensible channel/settings data.
5. Hash both raw captures and record the hashes locally.

Do not upload the existing 2025 image yet. The purpose is to preserve the exact
factory state and learn whether the two new radios are byte-identical.

## 3. Read-only BLE discovery

With ODMaster fully closed and only one radio powered on:

1. Scan from Chromium using `acceptAllDevices` with FF00 as an optional service.
2. Record the advertised name and whether FF00 is advertised.
3. Connect and enumerate primary services.
4. If FF00 exists, enumerate its characteristics and properties.
5. Subscribe to FF01 if it supports notifications.
6. Do not send a write yet.

Expected but unconfirmed for stock firmware:

- service `0000ff00-0000-1000-8000-00805f9b34fb`
- notify/read `0000ff01-0000-1000-8000-00805f9b34fb`
- write `0000ff02-0000-1000-8000-00805f9b34fb`

Record whether an OS pairing/bonding prompt appears and whether the radio allows
two clients. Assume one client only until proven otherwise.

## 4. Protocol probe - still read-only

Try in this order, stopping after any unexplained response:

1. Subscribe to notifications.
2. Send `AT+BAUD?\r\n`; save the exact notification bytes and timing.
3. Send `50 56 4F 4A 48 5C 14`.
4. Require `06` before continuing.
5. Send `02` and record the exact identity reply.
6. Send `06`; require the final `06`.
7. Read block zero with `52 00 00 20`.
8. Validate header, address, length, and additive checksum.
9. Read the remaining 255 blocks only after block zero is unambiguous.
10. End with `45` and record the reply.

Compare the BLE dump byte-for-byte with the USB/CHIRP backup after stripping
CHIRP's identity prefix and metadata trailer. A match is the gate for moving to
write testing.

## 5. First write test

Perform this on **radio B only**:

1. Take a fresh pre-write backup.
2. Select one harmless, known channel name byte in a non-critical slot.
3. Show the exact old/new 32-byte block and its checksum.
4. Write one block.
5. Re-read it immediately and compare all 32 bytes.
6. End the programming session cleanly and power-cycle.
7. Confirm on the keypad that only the intended name changed.
8. Restore the original block and verify again.

Do not test by changing transmit limits, power calibration, mode, emergency,
public-safety, or weather frequencies.

## 6. MVP acceptance gates

The browser programmer may enable general writes only after all of these pass:

- Correct radio identity is displayed.
- Full BLE and USB dumps match.
- All read checksums validate.
- A one-block write is acknowledged.
- Immediate readback matches.
- The on-radio result matches.
- Restore succeeds and matches the original backup.
- Disconnect/reconnect during a deliberately interrupted **read** fails safely.
- The app never writes when the identity or image size is unknown.

## 7. Firmware decision

Keep radio A stock. After the stock browser MVP works, decide whether NicSure
features justify converting radio B. Before flashing:

- obtain the exact current original-H3 image from the firmware author/source;
- verify model/hardware compatibility;
- save both the stock codeplug and stock firmware recovery material;
- read the current NicSure manual and migration notes;
- treat its codeplug as a new format, not as an in-place stock image.

