# VGC / Benshi radio control

Browser-based programming and control for **VGC and other Benshi-protocol
radios** over Bluetooth. The front end is one self-contained HTML file plus an
installable offline shell; the PHP/SQLite API provides invite-only accounts,
private coverage sync and model-assisted list review.

Live at **<https://n7wgp.com>**. Built 2026-08-23 for N7WGP.

> **Picking this up in a new session? Read [`PLAN.md`](PLAN.md) first.** It has
> the current state, the open questions, and the prioritised roadmap.

## Files

| File | What it is |
|---|---|
| [`PLAN.md`](PLAN.md) | **Start here.** State, open questions, roadmap |
| [`PROTOCOL.md`](PROTOCOL.md) | Wire protocol reference — framing, all bitfields |
| [`vgc-programmer.html`](vgc-programmer.html) | **The entire front end.** Source of truth |
| [`radio-api/`](radio-api) | PHP + SQLite API: accounts, coverage sync, AI list review |
| [`build-library.mjs`](build-library.mjs) | Master CSV → embedded channel library + site geocoding |
| [`test-codec.mjs`](test-codec.mjs) | 79 offline protocol assertions |
| [`deploy-n7wgp.sh`](deploy-n7wgp.sh) | Publish to n7wgp.com |
| `manifest.webmanifest`, `sw.js`, `radio-icon.svg` | Installable/offline app shell |
| `site.htaccess` | Root security and cache headers, copied to `public/.htaccess` |
| `og.png` | Public social-preview card |
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
| Live status: TX/RX/SQ/scan/GPS, RSSI, battery, current group | working on owner hardware; exact results still need recording |
| **Coverage log** — sessions, antenna/radio tags, privacy zone, GPS track, CSV/GeoJSON and map | working; new metadata/privacy controls need a field run |
| On-page instructions, incl. a full APRS/BSS/TNC/KISS walkthrough | built |
| Event push — reacts when you turn the knob | working on owner hardware; exact results still need recording |
| APRS/BSS settings plus digipeater path | built; path write needs a live read/write/read check |
| Raw packet terminal (send/reassembly) | built, experimental, not yet transmitted from this version |
| General radio settings page | **not built** — see PLAN.md |
| Automatic pre-write group backup and restore | built; every group write requires typing its destination |
| Installable PWA and public read-only demo | built |
| Starting/stopping the radio's scan from the app | **not decoded** — no such command is known |
| Accounts (invite only), coverage stored per account, cross-device sync | built |
| "Check this list" — AI review of an imported channel list | built |
| Audio / Bluetooth PTT | **impossible in a browser** — BLE cannot do Classic audio |

## Accounts

The site is **invite only**. Mint codes with the admin token from
`radio-api/config.php`:

```bash
curl -s -X POST https://n7wgp.com/api/admin/invite \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' -d '{"count":3,"note":"who they are for"}'
```

Signing in once makes a device work **offline** from then on — only an explicit
401 from the server ends a session, so no signal never means no access.

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
node test-codec.mjs                 # 79 assertions, run before every deploy
node build-library.mjs              # summary + sanity checks, writes nothing
node build-library.mjs --write      # regenerate the embedded library from the CSV
./deploy-n7wgp.sh                   # dry run
./deploy-n7wgp.sh --apply           # publish the page
cd radio-api && ./deploy.sh         # dry run
cd radio-api && ./deploy.sh --apply # publish the API
```

The API deploy never syncs `radio-data/` or any `.db` — the database lives only
on the server. It refuses to run if `config.php` still holds a placeholder
secret, and after deploying it checks that `config.php` and the SQLite file
answer 403.

`vgc-programmer.html` is the only file to edit. `public/index.html` is built
from it at deploy time so the published copy cannot drift, and `test-codec.mjs`
extracts the protocol code straight out of the HTML for the same reason.

Deploy is additive rsync (no `--delete`), dry-run by default, and verifies the
radio site plus related services afterwards. Hostinger
credentials default to `../../jasonhuber.com/llm-api/.env` — n7wgp.com is an
addon domain on the same account, with Cloudflare DNS in front. If that shared
workspace moves, set `N7WGP_DEPLOY_ENV=/absolute/path/to/.env`.

## Credit

Protocol reverse-engineered by Kyle Husmann in
[benlink](https://github.com/khusmann/benlink) (Apache-2.0) and reimplemented here in
plain JavaScript. [`PROTOCOL.md`](PROTOCOL.md) captures what was needed, so the
Python is not required to work on this.
