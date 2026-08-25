# Sources and evidence

Checked **2026-08-24**. Source claims in the research notes are intentionally
split between vendor statements, protocol implementations, and local evidence.

## Primary implementation and protocol sources

### CHIRP - current TD-H3 stock driver

- Repository: <https://github.com/kk7ds/chirp>
- Driver: <https://github.com/kk7ds/chirp/blob/master/chirp/drivers/tdh8.py>
- Commit inspected: `4093d4120428cc46c0cbfcc095b4c22429cf1fcb`
- What it establishes: stock H3 magic/identity flow, 8 KiB clone behavior,
  199-channel bounds, channel/settings structures, HAM/GMRS/unlocked identity
  variants, and current macOS-compatible CHIRP support.
- License: GPL; use as a behavioral reference. Do not paste driver code into the
  existing application without making an intentional licensing decision.

### Original TD-H3 engineering trace

- Repository: <https://github.com/nicsure/TD-H3-Engineering>
- Protocol trace: <https://github.com/nicsure/TD-H3-Engineering/blob/master/protocol.txt>
- Archived repository commit inspected:
  `37f22be3357878e290bc4e734784586e08988436`
- What it establishes: exact PVOJH stock programming handshake, 32-byte
  address-based read/write framing, additive checksum, acknowledgements, and
  captured `P31185` identity bytes.
- Caveat: archived and experimental; no repository license file was found.

### NicSure/nicFW V2 technical documentation

- Repository: <https://github.com/nicsure/nicfw2docs>
- Protocol: <https://github.com/nicsure/nicfw2docs/blob/main/programmer_radio_protocol.md>
- EEPROM map: <https://github.com/nicsure/nicfw2docs/blob/main/eeprom.md>
- Commit inspected: `f824f36092af099cc4ad9d8dc0d684dc9b3928f2`
- What it establishes: 8 KiB/256-block NicSure memory, `0x30`/`0x31` block
  protocol, BLE as a supported serial transport, group/channel/settings
  regions, and V2.0X versus V2.5X endianness distinction.
- Caveat: no repository license file was found.

### Current NicSure interoperability notes

- Repository: <https://github.com/RCGV1/nicsure-tdh3-firmware-notes>
- BLE transport: <https://github.com/RCGV1/nicsure-tdh3-firmware-notes/blob/main/docs/ble-transport.md>
- Compatibility: <https://github.com/RCGV1/nicsure-tdh3-firmware-notes/blob/main/docs/compatibility.md>
- App builder guide: <https://github.com/RCGV1/nicsure-tdh3-firmware-notes/blob/main/docs/app-builder-guide.md>
- Commit inspected: `d80b898e8d975f8855e85ae038d4ce91ac3bcbec`
- Evidence baseline named by the notes: NicTUI
  `7ec01e961a92310d427bbe7b7883e78a4dfe0b40`.
- What it establishes: FF00/FF01/FF02 UUIDs, GATT chunking, tested native BLE,
  firmware detection, record maps, safe backup/write/readback flow, and the
  limited state of remote telemetry/control.
- License: documentation is MIT.

### NicTUI

- Repository: <https://github.com/RCGV1/NicTUI>
- Commit inspected: `d81b5bd85950bae6b3de8f10d3c88a91f6a14043`
- What it establishes: a current working TD-H3/NicSure programmer using native
  BLE and USB serial on macOS/Linux/Windows.
- License: GPL-3.0.

### TD-H3 Plus Web CPS

- App: <https://jamarju.github.io/tid-h3-plus-cps/>
- Repository: <https://github.com/jamarju/tid-h3-plus-cps>
- Protocol: <https://github.com/jamarju/tid-h3-plus-cps/blob/main/info/ble-protocol.md>
- Memory map: <https://github.com/jamarju/tid-h3-plus-cps/blob/main/info/memory-map.md>
- Commit inspected: `0682568b728bf5d4053a3cf06d07dc0025e2ba65`
- What it establishes: working pure-browser Web Bluetooth CPS, FF00/FF01/FF02,
  `AT+BAUD?` plus PVOJH session setup, checksum/ACK behavior, 16 KiB memory, and
  a safe write-range allow-list for the Plus.
- License note: its README says MIT, but no `LICENSE` file was present at the
  inspected commit. Treat it as a reference unless that is clarified; a clean
  implementation from documented wire facts avoids ambiguity.

## Vendor sources

- Original TD-H3 product page:
  <https://tidradio.com/products/h3-ham-radio>
- TD-H3 Plus product page:
  <https://tidradio.com/products/h3-plus-5w-ham-gmrs-bluetooth-radio>
- Official ODMaster Web programming instructions:
  <https://tidradio.com/blogs/news/how-to-program-td-h3-plus-with-cps-or-odmaster>
- Vendor firmware/download landing page:
  <https://tidradio.com/pages/tidradio-firmware>
- ODMaster Web: <https://web.odmaster.net/login?lang=en_US>

Vendor pages establish product positioning, Bluetooth/Web programming support,
and the current model family. They do not by themselves establish compatible
memory layouts.

## Local evidence already on file

- `../../02-Reference/TIDRADIO-TD-H3-Manual.pdf` - archived original-H3 manual;
  identifies primary transmit ranges as 136-174 and 400-520 MHz.
- `../../02-Reference/TIDRADIO-HT-App-Operating-Instructions.pdf` - archived
  ODMaster/TIDRADIO app instructions.
- `../../04-Radio-Software/TD-H3/` - archived 2024 stock firmware and Windows
  tools. These are fallback material, not the current download source.
- `../../03-Codeplugs/Current/TD-H3_2025-01-24.img` - known-working earlier
  radio codeplug. Its leading identity is `P31183 FF FF` (unlocked/normal).
- `../../03-Codeplugs/Current/MASTER-Channel-List-CORRECTED.csv` - current source
  channel list for the incoming radios.

The real codeplug remains outside this research folder. Do not copy or commit a
new radio dump here; it may contain local frequencies, DTMF data, device
identifiers, or other station-specific information.

