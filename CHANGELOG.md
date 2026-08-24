# Changelog

## 2026-08-24 (cleanup) — GMRS defect fixed, admin user routes, DeepSeek traced

- **Fixed the GMRS bandwidth defect the review feature found**, in the master
  CSV, then regenerated the library. All 14 interstitial channels shipped as
  **wide**, and they must be 12.5 kHz narrow:
  - ch 1–7 (462.5625–462.7125) → narrow, 5 W
  - ch 8–14 (467.5625–467.7125) → narrow, **0.5 W** (these were also at 8 W)
  - ch 15–22 (462.5500–462.7250) were already right: wide and high power are
    both permitted on the main channels.
  Note the model was only **partly** right: it wanted low power on ch 1–7 too,
  but 5 W is permitted there — it had applied the FRS limit. Bandwidth was the
  real bug on all 14. A good argument for findings staying advisory.
- **Added `GET`/`DELETE /api/admin/users`** so an account and everything it
  owns can be removed. Deleting frees the invite code for reuse. Used it to
  remove the `deploy-check@n7wgp.test` verification account — the database now
  holds no users, and all four invite codes are unused.
- **Traced why DeepSeek is not answering.** The worker source is
  `Sustav Dev/Pogodi/ios/cloudflare-worker` (routed to `api.sustav.dev`).
  DeepSeek is **already fully implemented** there — `Provider` includes it,
  `DEEPSEEK_MODEL = "deepseek-v4-flash"`, and it routes to DeepSeek's
  Anthropic-compatible surface. `availableProviders()` includes a provider only
  when its API key is set, so the only thing missing is the secret:
  `npx wrangler secret put DEEPSEEK_API_KEY` in that worker directory. Nothing
  in this project changes when that lands — it already asks for `deepseek`, and
  the worker orders providers cheapest-first.

## 2026-08-24 (backend) — accounts, coverage sync, AI list review

- **Built `radio-api/`** — a single-file PHP router on SQLite, same shape as
  `jasonhuber.com/track-api`, deployed to `n7wgp.com/public_html/api`.
  Registration is **invite only**: there is no open signup route at all,
  because every account can spend model tokens through the prompt proxy.
  Codes are minted with an admin Bearer token from curl.
- **Auth.** `password_hash`/`password_verify`; session tokens are 32 random
  bytes, stored **hashed with a pepper** so a database copy is not a set of
  live logins. Login always runs a verify even for an unknown email so timing
  does not reveal which addresses have accounts, and both failure paths return
  the same message. Rate limited per IP. 180-day sessions, deliberately long.
- **Offline is a first-class case.** The token and user are cached; a failed
  `/auth/me` on boot means *offline*, never *signed out*; only an explicit 401
  clears the session. The app renders from cache before any network round trip,
  so there is never a login wall between a parked operator and their channel
  list.
- **Coverage sync.** Push/pull of sessions, rows and track, merged rather than
  replaced so two devices can both contribute. Re-pushing is idempotent — rows
  key on `sid|t|g|ch|kind`. A finished session uploads itself. Verified live by
  wiping the browser copy and pulling it all back.
- **"Check this list"** on the Channel library — sends the filtered list to the
  Sustav proxy and returns findings (bad offsets, tones on simplex, wide
  bandwidth where narrow is required, out-of-band frequencies, names that
  truncate at 10 characters, duplicates), rendered by severity. Findings are
  advisory and never touch the library.
- **Two corrections found by testing, both worth recording:**
  - The proxy is at **`https://api.sustav.dev/v1/prompt`** (a Cloudflare
    Worker). The bare `sustav.dev/v1/prompt` that appears in some Swift
    comments is the static marketing site and 404s.
  - The worker's `/health` reports `configuredProviders: ["openai","anthropic"]`.
    It does **not** reject an unknown provider — it silently falls back and
    reports what actually answered in a `source` field. So asking for DeepSeek
    today returns OpenAI. Rather than hide that, the API passes `source`
    through and the UI prints "answered by openai, not deepseek". **To make
    DeepSeek real, add it to the worker's configured providers.**
- Per-user isolation, 401s on every protected route, and the 403s on
  `config.php` and the SQLite file are all verified against the live host.

## 2026-08-24 (later still) — coverage sessions, a bubble map, map gets its own tab

- **The repeater map is now its own page.** It was tucked at the bottom of
  Channels; it is a site picker and deserved its own tab. Removed the coverage
  overlay from it — coverage has its own map now (below), which is what it
  should have had from the start.
- **Coverage runs are now sessions.** One drive, one hilltop, one evening.
  Pick an old session from the dropdown and the map, the totals and both
  tables all switch to that run. Sessions can be renamed and deleted
  individually.
  - **They end themselves**, because people forget — especially the person who
    parked and walked into a building. Nothing heard for N minutes (10 by
    default, editable next to the start button) closes the session. The status
    strip counts down to it. A session that recorded nothing is discarded
    rather than cluttering the picker.
  - Storage moved to `n7wgp.vrn76.coverage.v2` — `{sessions, rows, track}`.
    The old flat v1 log migrates into a single session on first load, so
    nothing already recorded is lost.
- **GPS breadcrumb track.** Every ~25 m of movement (or every minute standing
  still) a point is recorded, per session. This is the half that was missing:
  the track shows where you drove and heard **nothing**, which is what turns a
  pile of hits into an actual coverage map. Total miles travelled is now in the
  session stats.
- **Built the bubble map** — its own SVG, not the repeater map. It fits itself
  to the selected session rather than to the QTH, so a run four hours down the
  road frames correctly. Every reception is a bubble where it was heard from,
  **sized and coloured by signal strength**; the track draws underneath;
  repeater sites show as faint rings for context (toggleable) and never expand
  the bounds. Scale bar, legend, and hover detail on every bubble.
  - Site labels use greedy collision placement and reserve the bubbles too, so
    a label never lands on data. First attempt placed zero labels — every
    candidate box overlapped its own site's ring reservation. Offsets now clear
    the ring.
- GeoJSON export gained the track as a `LineString` per session alongside the
  reception points; CSV gained session id and label. Both export whatever the
  session dropdown is showing.

## 2026-08-24 (late night) — coverage log, pages instead of one long scroll

- **Built the coverage log** — a passive receive logger. It records a row every
  time the radio's squelch opens: group, channel, name and frequency, peak and
  average RSSI, duration, and the phone's GPS position at that moment. Summary
  by channel, full reception list, CSV and GeoJSON export, and the points plot
  onto the existing repeater map coloured by signal strength. Incoming packets
  (`DATA_RXD`) become rows too, with their text and position.
  - **It does not command the radio.** There is no decoded scan start/stop —
    the user presses scan on the radio and this listens. The only lever this
    app has over scanning is the per-channel `scan` flag in `RfCh`, which it
    already writes. An app-driven sweep needs a way to set the current channel
    (`WRITE_REGION_CH` (58), or `channel_a`/`channel_b` in `Settings`) and
    neither is implemented — see `PLAN.md`.
  - Sources both the pushed `HT_STATUS_CHANGED` events and the status poll,
    which now runs at 1.2s instead of 4s while logging. Squelch flutter under
    0.4s with no RSSI sample is discarded.
  - Asks for a screen wake lock; warns that a backgrounded mobile browser
    suspends BLE and throttles GPS. Capped at 3000 rows in localStorage.
  - **The whole feature rests on `StatusExt` actually arriving**, which had
    never been seen on real hardware — so running it is also the hardware test
    the live status strip has been waiting for.
- **Reorganised the UI into pages** (Start here · Radio link · Channels &
  groups · Coverage log · APRS & packet · Instructions · Protocol log). The
  landing page is now a set of cards describing what you can actually do,
  led by "load my area's repeaters onto the radio". The repeater map moved to
  the bottom of the Channels page — it was the loudest thing on screen and is
  a site picker, not the point. Connect/Disconnect moved into the header so it
  is reachable from every page. Deep links work (`#coverage`, `#guide`).
- **Wrote the on-page instructions** the UI kept deferring to: what you need,
  connecting, programming, groups vs slots, running the coverage log and every
  way it can mislead you, and a full plain-language APRS / BSS / TNC / KISS
  walkthrough — including that the TNC needs no separate connection, what
  receive already does, that transmit isn't built, and which two settings still
  have to be set from the radio's own menu.

## 2026-08-24 (night) — group rename, and SET_REGION probably never worked right

- **Added group (region) rename**, sourced from
  [Ylianst/HTCommander](https://github.com/Ylianst/HTCommander)'s independent,
  hardware-confirmed protocol decode (not benlink, which never got this far):
  `READ_REGION_NAME` (73) / `WRITE_REGION_NAME` (59). "Read group names from
  radio" and "Rename this group…" in the Radio layout panel. Not yet
  round-tripped by this app specifically — read before you trust a write.
- **Found and fixed a real bug in `setRegion()`**: the same source confirms
  `SET_REGION` (60) gets **no reply at all**. This app was awaiting one like
  every other command, meaning every group switch — including the very first
  step of "Probe groups" — silently burned the full 5-second request timeout
  and then errored. `setRegion()` is now fire-and-forget with a 150ms settle.
  This may be the actual reason `SET_REGION` looked broken; the underlying
  `u8` index guess is still unconfirmed and needs a fresh hardware test. See
  `PLAN.md`.
- Bonus find in the same source: `SET_APRS_PATH`/`GET_APRS_PATH` (71/72) are
  decoded too — a plain UTF-8 string, not yet wired into the APRS/BSS panel.
- Credited HTCommander (Apache-2.0) in the page footer alongside benlink.

## 2026-08-24 (evening) — group/channel numbers now match the radio's screen

- **Fixed a display off-by-one**, reported after comparing the app to a real
  VR-N76: the radio's own screen numbers groups and channels starting at 1,
  while the wire protocol (`SET_REGION`, `RfCh.channel_id`) is 0-based. The
  app was showing the raw wire value. Group tabs, the slot grid, the live
  status strip (`ch`/`group`, the thing `SET_REGION` verification depends
  on), `Planned`/`On radio` positions, and every group-related log line now
  add 1 for display only — `setRegion()`, `readChannel()`, `writeChannel()`,
  and the codec are untouched. The channel library's own `#` column (a
  catalog ID from the master CSV, not a radio slot) was deliberately left
  alone. See `PLAN.md`.

## 2026-08-24 (later) — git repo, APRS/BSS settings page

- Initialized git, pushed to **[github.com/jasonhuber/vgc-radio](https://github.com/jasonhuber/vgc-radio)** (public).
- Built the **APRS/BSS settings page** (roadmap item 1): reads and writes
  `BssSettings` (`READ_BSS_SETTINGS`/`WRITE_BSS_SETTINGS`, commands 33/34) —
  callsign, SSID, symbol, APRS-vs-BSS format, beacon message, location-share
  interval, PTT-release toggles, max-forward/TTL. 4 new offline round-trip
  assertions (46-byte base + 50-byte ext), 76/76 passing.
- Added an in-app glossary explaining **APRS, BSS, KISS and TNC** in plain
  language, plus a callout mapping the two fields this protocol *hasn't*
  decoded yet (digipeater `Path`, `Digital Channel`) to their real location in
  the radio's own menu — sourced from the official VR-N76 manual PDF
  (`Menu → General Settings → APRS Settings` / `Digital Mode`).

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
