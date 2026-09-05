# Changelog

## 2026-09-05 — the packet channel, and making it shut up

Found in the field at Rolfe C. Hoyer CG near Greer, debugging why `N7WGP-9`
had never once appeared on APRS over RF.

- **Root cause was `Digital Channel`, and it is not visible from this app.**
  The radio was set to channel 16 -- 147.500, a plain 2 m simplex slot -- so
  every beacon went out correctly formatted onto a dead frequency. The APRS
  page read perfectly the whole time. This is the second VR-N76 to ship this
  way; Kristin's was on 147.580 (see 2026-08-31). Documented in the guide as
  the first thing to check, with both observed wrong values named.
- **Wired up the per-channel mute bit.** `RfCh.mute` has been decoded and
  encoded since the protocol work but was hardcoded `false` in `fromLib()` and
  absent from the UI. It now comes from a **`[MUTE]` tag in the CSV comment**,
  following the existing `[SAT]`/`[TRAVEL]` convention, flows through
  `build-library.mjs` as a compact `u:1`, and is editable in the channel editor
  as **Speaker: Audible/Muted**. `144.390` ships muted.
- **Why that matters:** the Digital Channel is monitored in the background
  independently of A and B, and its audio goes to the speaker. Once it points
  at 144.390 the radio chatters constantly and retuning A and B does nothing.
  The guide now explains this, and says plainly not to fix it by turning off
  Digital Mode -- that is the switch that generates the beacon.
- Confirmed on air afterwards: beacons gated by two independent iGates
  (`BWMTN` and `N7GEE-10`) via the `GREENS` and `KE7JVX-3` digipeaters, at
  8,395 ft, path `WIDE1-1,WIDE2-1`. Also confirmed the leading unasterisked
  `GREENS` in the path is inserted by the digipeater, not the radio -- three
  unrelated stations show it, and neighbouring `EAGER`/`WHTMTN` do the same.
- Codec suite still 79 passed, 0 failed.

## 2026-09-03 (later) — surviving a dropped link

- **A mid-operation BLE drop no longer costs the rest of the pass.** Observed
  right after the GATT fix below: a full write pass followed immediately by a
  full read is ~45s of uninterrupted traffic, and the radio dropped the link
  during the last group. Web Bluetooth then failed all ten remaining slots
  instantly, so the real error (`GATT operation failed for unknown reason`)
  was buried under nine lines of `not connected`.
- `connect()` is split: device selection stays there, and everything after it
  — the connect/settle/discover retry, characteristics, event subscriptions —
  moves to `linkUp()`. `resumeLink()` runs that again after a drop. No user
  gesture is needed; permission for an already-chosen device survives.
- `readAll()` now stops on a drop, reconnects **once**, re-selects the group
  and retries the same slot. One attempt only — a radio that is genuinely gone
  would otherwise become an unbounded reconnect loop.
- `writeGroup()` returns instead of writing into a dead link, so a drop can no
  longer be counted as N real write failures.
- Both summaries say when a pass stopped early and how many groups were missed,
  rather than reporting the partial total as if it were the whole radio.

## 2026-09-03 — one GATT operation at a time

- **Fixed: a bulk write could be killed mid-pass by the status poll.** Web
  Bluetooth allows exactly one GATT operation in flight per device; a second
  `writeValue` issued while one is pending throws *GATT operation already in
  progress* rather than queueing. Nothing serialised them, so the 4s
  `startLive()` status poll (1.2s with coverage logging on) was free to fire
  in the middle of a group write. Observed 2026-09-03: group 4 wrote 31 of 31,
  then a poll tick collided with the group 5 backup read and the run stopped
  with 4 groups unwritten.
- Every path that touches `radio.chWrite` — `request()`, `sendNoReply()`,
  `registerEvent()` — now goes through a `gattOp()` queue, so operations
  serialise instead of colliding. The chain survives a failed operation.
- The poller also skips a tick while the queue is non-empty. The queue alone
  makes a collision harmless, but a bulk write is hundreds of operations deep
  and a status request joining that queue would answer long after it mattered.
- **The write-all summary no longer reports an aborted group as fine.** A group
  that failed its backup returned `{ok:0, bad:0}`, indistinguishable from an
  empty one, so the run above signed off as `117 written, 0 failed` with no
  mention of the four groups it never touched. `writeGroup` now returns an
  `aborted` reason and the summary names those groups.

## 2026-08-31 (night) — the log follows you

- **Docked protocol log.** A one-line strip pinned to the bottom of every page,
  showing the newest line coloured by kind; click it to expand a scrolling
  200-line view, click again to collapse. Open/closed is remembered.
- Why: the log lived only on the Protocol log page, and the page you are on
  while something is worth watching is never that one — writing groups, testing
  APRS receive, chasing a failed slot. You had to leave the thing you were doing
  to see whether it worked.
- Lines are mirrored, not moved: the Protocol log page is unchanged. The dock
  keeps the last 400 lines; `main` gets bottom padding so nothing is covered.

## 2026-08-31 (late) — replace whole group

- **A group write can now clear what it does not plan.** Until now `writeGroup`
  wrote only the filled slots, so anything an older codeplug left further down
  the group stayed on the radio; the slots showed as `radio: <name>` in the
  layout but nothing removed them. New **replace whole group** checkbox next to
  the write buttons, off by default.
- The radio has **no delete command** — `WRITE_RF_CH` is the only channel write
  — so a cleared slot is a record with `rx_freq` 0, which is exactly what
  `readAll` already treats as an empty slot. **This has never been verified on
  hardware**: if the firmware rejects it, the slots report `INVALID_PARAMETER`
  and keep their contents. Said plainly in the UI and the guide.
- When the group has been read, only slots the radio actually holds are blanked;
  when it has not, every planned-empty slot is, because there is no way to know.
  Verified in the browser against a simulated radio: plan in slots 0–2 with the
  radio holding 3, 4 and 7 writes three channels and three blanks in slot order,
  and leaves every other slot alone. With the box unticked, writes are unchanged.
- The wipe carries its own confirmation on top of the type-the-group-number
  gate, and the automatic pre-write backup still runs, so **Restore last backup**
  puts the cleared channels back.

## 2026-08-31 (evening) — the layout editor stopped being tedious

- **Auto-arrange all groups.** One press plans the entire radio. Three policies:
  by category (each library category gets its own group, a big one spilling
  across consecutive groups), nearest first, or by band. It previews the layout
  and asks before replacing the plan; the radio is never touched. On the current
  126-channel library the category policy produces Simplex 22 / Metro 32 / Metro
  32 / Metro+Travel 16 / GMRS+Sat 24 / empty.
- **Every group visible at once.** The layout section was one group at a time
  behind tabs, which is what made placing a single channel take so long. It now
  renders all groups as columns by default; the old single-group view is a
  checkbox.
- **Drag and drop.** Drag a slot onto any other slot, in the same group or
  across groups, and the two swap — so a move into a gap and a reorder are one
  operation and neither can lose a channel. Library rows are drag sources
  straight into a slot.
- **Close gaps / Sort by frequency** for the selected group.
- **Fixed: channel 0 was invisible to every slot count.** Slot ids are numbers
  and `0` is falsy, so `filter(Boolean)`/`some(Boolean)` on slot arrays dropped
  channel 0 — which is 2m National, 146.52. It undercounted every group holding
  it, and a group containing only it looked empty: the write button stayed
  disabled and "Write every group" skipped the group entirely. Emptiness is now
  `== null` everywhere via `slotsUsed()`/`anySlot()`. Pre-existing, not from
  this pass.

## 2026-08-31 (later) — the library had no APRS channel

- **Added 144.390 MHz to the channel library.** The instructions told you to
  point the radio's digital channel at 144.390 and the library never contained a
  channel at that frequency, so there was nothing to select. Found while setting
  up the second VR-N76: the radio was correctly configured for APRS and keyed up
  on 147.580 because that was what existed. Master CSV row 128, wide FM, no
  tone, `Skip=S` so the scanner doesn't stop on packet bursts.
- **`groupOf()` now honours an explicit `[SAT]` comment tag**, checked before the
  frequency rules, following the existing `[TRAVEL]` convention. The satellite
  group was matched on 430–440 MHz, which a 2 m APRS channel could never hit.
  Satellite is now ids 127–128.

## 2026-08-31 — full APRS explainer in the instructions

- **Rewrote the APRS section of the on-page guide** (`g-packet`) from a setup
  checklist into an explainer. New material: what APRS is actually for; the
  end-to-end path a beacon takes (radio TNC → 144.390 → digipeater → iGate →
  APRS-IS → aprs.fi) and which link usually fails; a can/can't table for this
  radio (no smart beaconing, no messaging from this page, no digipeating); the
  digipeater path explained with `WIDE1-1,WIDE2-1` as the default and why more
  hops is abuse; SSID conventions; beacon-interval guidance by activity; how to
  prove it worked on aprs.fi; and a plain statement of the licensing rules.
- **Added a privacy callout.** APRS is public and permanently archived by
  callsign — stated up front, with receive-only named as a normal way to run.
- **Added a "two radios in the family" subsection**, prompted by programming a
  second VR-N76: each licensed operator beacons under their own callsign (a
  position report is an identification), SSIDs separate one person's devices,
  and duplicate callsign+SSID is what makes a station teleport on the map. Also
  notes that the library and layout live in the browser, so the same plan writes
  to any number of radios, but callsign/SSID/symbol live on the radio — read
  from the radio after connecting a different one.
- **Documented the unverified fields honestly.** Max forward hops and time to
  live are described as BSS mesh forwarding, meaning taken from the reference
  implementation's field names and never confirmed on hardware — explicitly not
  the APRS path.
- Styling only otherwise: `.doc table` now has row-header rules so guide tables
  don't inherit the interactive channel-table look.

## 2026-08-25 — DeepSeek confirmed live, TD-H3 research

- **AI list checking now genuinely runs on DeepSeek.** The Sustav worker behind
  `api.sustav.dev` has DeepSeek deployed and keyed, so `RADIO_PROXY_PROVIDER =
  'deepseek'` no longer falls through to OpenAI. Verified against `/health`:
  `configuredProviders: ["deepseek","openai","anthropic"]`. Failover to OpenAI
  then Anthropic is unchanged, and the `source` field still reports who actually
  answered, so a fallback stays visible in the UI. The stale caveat in
  `radio-api/config.php` was replaced. See
  `NOTE-deepseek-proxy-2026-08-24.md` — no code in this repo needed to change.
- **Added TD-H3 protocol research** under `TD-H3/`. Conclusion: build a separate
  TD-H3 Web Bluetooth programmer rather than mixing TD-H3 packets into the
  Benshi/VGC transport. No implementation yet.

## 2026-08-24 (field-safety pass) — durable deletes, backups, antenna tags, privacy, demo and offline install

- **Coverage sync deletions are now real.** Session/all deletion queues a local
  tombstone, sends server deletes before the next pull, and survives being done
  offline. Deleted sessions can no longer return on the next sync. Session
  label/equipment changes carry a client timestamp so two devices resolve the
  same session deterministically instead of whichever device pushed last.
- **Coverage sessions record antenna and radio.** The antenna is chosen before
  starting and appears in session labels, stats, CSV and GeoJSON, enabling later
  setup comparisons.
- **Added a local-only privacy zone.** New receptions inside it retain time,
  channel and signal but omit coordinates; breadcrumbs inside it are skipped.
  Existing logs can be redacted, which queues a complete sanitized server
  replacement. The zone center itself is never uploaded.
- **Made region writes recoverable.** Writing a group requires typing its exact
  destination. Before the first channel is changed, every slot and the group
  name are read into a local backup; an incomplete backup cancels the write.
  The latest 12 can be downloaded, and the latest per-group backup can be
  restored after a second typed confirmation. Bulk writes name every target.
  The legacy one-channel editor now follows the same backup rule, identifies
  the destination as group:slot, and updates the grouped comparison cache.
- **Added APRS path controls and an experimental raw packet terminal.** Path
  writes are read back for verification. TNC data fragments at 40 data bytes,
  sends one at a time, waits for each acknowledgement, reassembles received
  fragments, refuses to start while TX/RX/squelch is active, and requires the
  operator to type `TRANSMIT`. AX.25/APRS composition remains a next step.
- **Added a public landing page and read-only demo** with realistic synthetic
  radio/coverage data. All radio, account, sync and transmit actions are
  disabled in demo mode, and demo edits are never persisted.
- **Added an installable offline shell:** manifest, service worker, app icon,
  security/cache headers and a branded social-preview card. The API is excluded
  from the service-worker cache.
- `Probe groups` now respects the manually overridden group count; using the
  radio's suspect reported count made the probe stop after group 1.
- Expanded the protocol suite from 76 to **79 assertions** for APRS-path framing
  and TNC-fragment round trips. Updated the handoff and radio notes with the
  owner's report that the broader live-radio workflow works, plus the exact
  confirmations still worth recording.

## 2026-08-24 (handoff) — privacy copy corrected, PLAN.md rewritten

- **Corrected four false privacy claims on the live page.** The footer, the
  Start page, the guide and the coverage note all still said some version of
  "nothing is uploaded, no data leaves this page". That stopped being true the
  moment coverage sync shipped — and what syncs is a timestamped record of
  where someone drove. All four now say plainly that channels, groups and the
  CSV stay in the browser while the **coverage log, positions included, is
  stored on the server against the account**, visible only to that account. The
  guide also says how to keep a log local (do not sync, do not end a session
  while online).
- **Rewrote `PLAN.md` as a cold-start handoff document.** It had accumulated
  contradictions from incremental edits — a section headed "not yet built"
  whose body said "built and deployed", a claim the worker source could not be
  found after it had been found, offline listed as open after it shipped, a
  broken list numbering, and stale figures. It is now structured for someone
  picking this up with no context: repo map, commands, state table with how
  each thing was verified, front-end and API architecture, the security and
  offline properties that must be preserved, the `SET_REGION` blocker, a
  prioritised roadmap led by a one-evening hardware pass, hard limits, a
  "things that have already bitten" list, and the honesty constraints the page
  makes to its users.

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
