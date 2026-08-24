# VGC / Benshi radio control

Browser-based programming and control for **VGC and other Benshi-protocol
radios** over Bluetooth. One self-contained HTML file, no backend, no install,
no network calls — it works offline in the field.

Live at **<https://n7wgp.com>**. Built 2026-08-23 for N7WGP.

> **Picking this up in a new session? Read [`PLAN.md`](PLAN.md) first.** It has
> the current state, the open questions, and the prioritised roadmap.

## Files

| File | What it is |
|---|---|
| [`PLAN.md`](PLAN.md) | **Start here.** State, open questions, roadmap |
| [`PROTOCOL.md`](PROTOCOL.md) | Wire protocol reference — framing, all bitfields |
| [`vgc-programmer.html`](vgc-programmer.html) | **The entire application.** Source of truth |
| [`build-library.mjs`](build-library.mjs) | Master CSV → embedded channel library + site geocoding |
| [`test-codec.mjs`](test-codec.mjs) | 57 offline protocol assertions |
| [`deploy-n7wgp.sh`](deploy-n7wgp.sh) | Publish to n7wgp.com |
| [`radios/`](radios) | Per-radio capacity, quirks, test status |
| `requirements.txt` | Recreates the benlink reference install (optional) |
| `scan.py` | BLE scan probe — blocked by macOS TCC, kept as evidence |
| `public/` | Deploy output. **Generated — never edit** |

## What it does

| Capability | State |
|---|---|
| Read every channel off the radio | working on hardware |
| Write channels | working on hardware |
| **Group/slot layout editor** — any channel, any group, any slot, overlaps allowed | built |
| Diff plan vs radio, per field | built |
| Repeater map, 80 sites geocoded, click to filter | built |
| Built-in 125-channel library, 5 categories | built |
| CHIRP CSV import/export | built |
| Live status: TX/RX/SQ/scan/GPS, RSSI, battery, current group | built, unverified |
| Event push — reacts when you turn the knob | built, unverified |
| APRS/BSS settings (callsign, beacon, share interval) | built, unverified on hardware |
| Packet terminal (send), radio settings page | **not built** — see PLAN.md |
| Audio / Bluetooth PTT | **impossible in a browser** — BLE cannot do Classic audio |

## Requirements

Web Bluetooth, which means **Chrome, Edge or Brave on desktop or Android**.
**iPhone and iPad cannot work** — iOS has no Web Bluetooth in any browser.
Firefox and Safari do not either. Everything except the radio link (library,
groups, map, CSV) works in any browser.

Brave ships Web Bluetooth **disabled**; enable
`brave://flags/#brave-web-bluetooth-api` and relaunch.

## Connecting

1. Quit the VGC phone app **and turn Bluetooth off on the phone.** The radio
   serves one controller at a time and phones silently reconnect.
2. **Put the radio into Bluetooth pairing mode** from its own menu.
3. Press **Connect radio** and pick the radio.
4. **Accept the system pairing dialog.**

Step 4 is not optional. The radio requires a bonded link and drops anything
unbonded, which presents as a connect-then-disconnect and looks like a
connection failure. The browser delegates bonding to the OS, so the OS prompt
*is* the browser pairing. Once only.

## Working on it

```bash
node test-codec.mjs                 # 57 assertions, run before every deploy
node build-library.mjs              # summary + sanity checks, writes nothing
node build-library.mjs --write      # regenerate the embedded library from the CSV
./deploy-n7wgp.sh                   # dry run
./deploy-n7wgp.sh --apply           # publish
```

`vgc-programmer.html` is the only file to edit. `public/index.html` is built
from it at deploy time so the published copy cannot drift, and `test-codec.mjs`
extracts the protocol code straight out of the HTML for the same reason.

Deploy is additive rsync (no `--delete`), dry-run by default, and verifies
jasonhuber.com's `/llm`, `/track` and `/travels` afterwards. Hostinger
credentials come from `../../jasonhuber.com/llm-api/.env` — n7wgp.com is an
addon domain on the same account, with Cloudflare DNS in front.

## Credit

Protocol reverse-engineered by Kyle Husmann in
[benlink](https://github.com/khusmann/benlink) (Apache-2.0) and reimplemented here in
plain JavaScript. [`PROTOCOL.md`](PROTOCOL.md) captures what was needed, so the
Python is not required to work on this.
