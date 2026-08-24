# Changelog

## 2026-08-24 — reorganised as 07-VGC, plan written

- Renamed `07-VRN76-Control/` to **`07-VGC/`** and `vrn76.html` to
  `vgc-programmer.html`. The protocol is shared across Benshi radios and the
  app reads capacity from the device, so one app serves the VR-N76, VR-N7600,
  UV-Pro and GA-5WB. Toolchain repointed and re-verified after the move.
- Added **`PLAN.md`** — current state, the open `SET_REGION` question, and a
  prioritised roadmap (APRS config → packet terminal → radio settings).
- Added **`PROTOCOL.md`** — the full wire protocol extracted from benlink so
  future work needs no Python: framing, command table with which bodies are
  decoded, `DevInfo`, `RfCh`, `Status`/`StatusExt`, battery, events,
  `TncDataFragment`.
- Added **`radios/VR-N76.md`** (measured capacity, bonding requirement, what is
  verified vs untested) and **`radios/VR-N7600.md`** (first-connect checklist;
  flags that a DMR-capable radio needs the longer `RfChDMR` layout, which the
  current decoder does not handle).
- Removed the 54 MB venv — its shebangs broke on the move and it is
  reproducible from the new `requirements.txt`. Folder went 54 MB → 256 KB.

### Live status and events

- Status strip: TX / RX / squelch / scan / GPS, RSSI bar, current channel,
  **current group**, battery percent and voltage. Polls every 4 s.
- Subscribed to the radio's event pushes, so the page reacts when the knob is
  turned: status changes, channel changes, and **incoming TNC packets** now
  print to the log. Unsolicited events are dispatched before the pending-reply
  lookup, since they are not replies to anything.
- `curr_region` in the status strip is the cheapest way to settle whether
  group switching works — change groups on the radio and watch the number.

## 2026-08-23 (evening) — hardware session

- **First successful writes to a real radio**: 31 channels.
- Found the radio holds **32 slots per group**; writing beyond that returns
  `INVALID_PARAMETER` per channel. Writes are now capacity-aware and refuse
  up front instead of firing dozens of doomed commands.
- **Separated library categories from radio groups/slots.** Added a layout
  editor: click a slot to target it, `+` on a channel to place it, and the same
  channel may appear in any number of groups and slots. Slot index becomes the
  channel number sent to the radio.
- **Rewrote the diff to compare content, not position.** Comparing library[n]
  against radio slot n stopped meaning anything once any channel could go in
  any slot. The table now shows **Planned** (where you put it) beside
  **On radio** (where it actually is); layout slots show their own status.
- Removed the redundant group dropdown and the old flat write path — two
  competing mechanisms that disagreed.
- Diagnosed the connection failures: the radio **requires a bonded link** and
  silently drops unbonded centrals. Earlier guidance to forget the radio in
  macOS Bluetooth was wrong and is corrected in the docs.

## 2026-08-23 (later)

### Repeater map

- Added an inline-SVG map of transmitter sites, centred on the QTH, with
  distance rings and dots sized by channel count and coloured by distance
  band. No tile server, so it still works offline in the field.
- **80/80 repeaters geolocated** from the CSV comment text via a site table
  (named peaks, landmarks, city centroids). Computed distances independently
  reproduce the reachability table in `../00-README.md` to within ~1-2 mi
  (South Mountain 12.3 vs 14, Usery 22.4 vs 22, Thompson Peak 28.2 vs 28,
  Overgaard 110.7 vs 109) — two unrelated methods agreeing.
- Click a site to filter the channel list to it; **Metro (<=40 mi)** and
  **All sites incl. rim country** views. Added Site and Dist columns.
- Greedy label placement with collision avoidance against both other labels
  and every site dot; verified 0 overlaps in both views via getBBox.
- Site coordinates are approximate and labelled as such in the UI.

### Connection diagnostics

- **Removed the pre-emptive Brave banner.** Brave with the flag already
  enabled works fine, so detecting Brave was not by itself a reason to warn.
  The banner now appears only on a real failure.
- Mapped every Web Bluetooth failure mode to a specific cause and fix,
  including `Web Bluetooth API globally disabled` (Brave's default, or a
  Chrome flag/policy) which was the actual blocker hit in testing.
- Automatic GATT connect retries (4 attempts, backing off) — macOS commonly
  fails the first connect to a previously-seen device and succeeds on retry.
- **Show all Bluetooth devices** button, bypassing the name/service filter,
  since BLE advertising names often differ from the Classic name macOS shows.
- On service-lookup failure the page now enumerates whatever GATT services
  the device does expose, which is the key fact for diagnosing a wrong or
  non-Benshi device.
- Corrected the NetworkError guidance: the macOS pairing is *not* required
  and holding the radio as a Classic "Headset" is a likely cause, so the
  advice is now to forget it in macOS rather than to pair it.

## 2026-08-23

### Published to https://n7wgp.com

- Deployed to the Hostinger addon domain for the callsign; Cloudflare DNS in
  front, HTTP→HTTPS redirect, `www` working. `deploy-n7wgp.sh` builds
  `public/index.html` from `vgc-programmer.html` so the published copy cannot drift.
- Removed the Hostinger `default.php` placeholder from the domain root.
- Removed the earlier `jasonhuber.com/vrn76/` copy (published ~40 min prior)
  and its `site/vrn76/` staging directory, so n7wgp.com is the single home.
  All other jasonhuber.com surfaces (`/`, `/llm`, `/track`, `/travels`,
  `/seatosky`) verified intact before and after.

### Built-in channel library, groups, and radio diffing

- Embedded all 125 channels from `MASTER-Channel-List-CORRECTED.csv` directly
  in the page — the field workflow no longer needs a CSV file at hand.
- Grouped into Simplex (22), Phoenix Metro (75), Rim Country/travel (5),
  GMRS/FRS (22), Satellite (1), with filter chips.
- Added **diff against the radio**: reads every slot and reports per-field
  drift (`tx tone`, `power`, `rx`, `name`, `not on radio`, `radio-only`) with
  live counts. Verified against a simulated radio covering each category —
  including the CTCSS-instead-of-DCS failure, which reports as `tx tone, rx tone`.
- Added **write shown** and **write only differing**, both confirmed and
  followed by an automatic re-read to verify.
- Library edits persist in `localStorage`; **Reset to built-in** restores.
- `build-library.mjs` regenerates the embedded library from the CSV and reports
  duplicate IDs and repeaters missing a tone.

### Fixed

- **CHIRP `Power` column parsed as Low for the entire master list.** The list
  carries wattage strings (`8.0W`), not CHIRP's words (`High`/`Mid`/`Low`), and
  the importer only recognised the words — so every channel would have been
  programmed at low power. Now handles both forms; covered by tests.
- Floating-point artifacts from `rx - offset` (e.g. `144.51000000000002`) now
  rounded on import. Harmless at the wire, ugly in the editor.

### Initial build

- Reverse-engineered the Benshi BLE protocol from [benlink](https://github.com/khusmann/benlink)
  (MIT) and reimplemented the channel-programming subset in plain JavaScript.
  Key finding: over BLE the radio takes **raw messages with no GAIA framing** —
  that wrapper is RFCOMM-only.
- Chose a browser app over Python after Homebrew's `python3` proved unable to
  touch CoreBluetooth on this Mac (`SIGABRT`, no Bluetooth TCC grant).
- 57 offline assertions covering framing, sub-audio, the 25-byte `RfCh`
  round-trip, frequency precision, and CSV row math.
- Page is fully self-contained: zero external resource requests, works offline.
