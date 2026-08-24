# VGC / Benshi radio control — plan

Written 2026-08-24. Pick this up cold from here.

Live at **<https://n7wgp.com>**. Source of truth is `vgc-programmer.html`
(one self-contained file). Deploy with `./deploy-n7wgp.sh --apply`.

---

## Where things stand

| Area | State |
|---|---|
| BLE transport, bonding, retries | **working on real hardware** |
| Channel read | **working** — reads slots back off the radio |
| Channel write | **working** — 31 channels written to a real VR-N76 |
| Channel codec (25-byte `RfCh`) | verified: 57 offline assertions + live round-trip |
| Group/slot layout editor | built, exercised in-browser, **not yet used against hardware end-to-end** |
| Repeater map, 80 sites geocoded | working |
| Live status strip (RSSI, TX/RX, battery, current group) | **built, never seen real data** |
| Event push (status/channel/packet-in) | **built, never seen real data** |
| `SET_REGION` group switching | index still unverified, but a real bug that broke every attempt is now fixed — see below |
| APRS/BSS settings page (`BssSettings`, 46/50-byte codec) | built, 4 offline round-trip assertions, **not yet run against real hardware** |
| Group (region) rename (`READ`/`WRITE_REGION_NAME`) | **built 2026-08-24**, sourced from HTCommander's hardware-confirmed decode, **not yet round-tripped by this app** |
| Coverage log (sessions, GPS track, bubble map) | **built 2026-08-24**, **never run against a radio** — see below |
| Accounts (invite only), coverage sync, AI list review | **built and live 2026-08-24** — `radio-api/`, verified end to end |
| UI split into pages, on-page instructions | **built 2026-08-24** |

### The coverage log, and what it is really testing

Built 2026-08-24. It is **passive**: it commands the radio not at all. It
listens to `HT_STATUS_CHANGED` pushes plus the status poll (1.2s while
logging, 4s otherwise), opens a row when `is_sq || is_in_rx` goes true, closes
it when squelch shuts or the channel changes underneath it, and tags the row
with `watchPosition()`'s latest fix. `DATA_RXD` packets get their own rows.
Export is CSV and GeoJSON; points overlay the repeater map.

**Every field it records comes from `StatusExt` — which this project has never
seen arrive.** RSSI, `curr_region` and the upper channel bits are all in the
extended half. So the first real-hardware run of the coverage log is
simultaneously:

1. the hardware test the live status strip has been waiting for,
2. evidence about whether `curr_region` is real (see the `SET_REGION` question
   below — watching this number while changing groups on the radio's own keypad
   is listed there as the cheapest way to settle it), and
3. the coverage log's own shakedown.

Run it before assuming any of it works. If the group column reads 1 everywhere
and RSSI is blank, the radio is sending the short `Status` and only the
timestamp/channel/position half of each row is trustworthy.

**Why it cannot start the scan.** No scan start/stop command has been decoded
for this protocol — not in benlink, not in HTCommander. `RfCh.scan` (which this
app already writes) only decides whether a channel is *included* in a sweep the
user starts from the keypad; `Status.is_scan` is read-only telemetry. An
app-driven sweep — step channel, dwell, sample RSSI, repeat — needs a way to
set the *current* channel, and there are two candidates, neither implemented:

- `WRITE_REGION_CH` (58), undecoded; the name is suggestive and nothing more.
- `channel_a` / `channel_b` in `Settings` (roadmap item 3, decoded in benlink).

Before building that, consider that a sweep hammers the radio's settings store
hundreds of times an hour. Worth understanding the wear implications, and worth
gating behind an explicit opt-in.

### The backend turn — decided 2026-08-24, not yet built

Direction from the owner, overriding the earlier "no login" recommendation:
**login is required to use any feature**, the coverage log is stored
server-side per account, and the AI work is a **checking** pass over a list the
user imports rather than generation from scratch ("most people will want to
pull in their own list and then check it"). That reframing is a better product
than the original generate-a-list idea — it puts a human's own data in front
and uses the model where it is actually reliable.

**The AI proxy convention is already established** in the Sustav iOS apps
(`Briga`, `Izraz`, `Pokret`, `Govoriti`, `Pogodi` — see each app's
`Services/AIService.swift`):

```
POST https://sustav.dev/v1/prompt
{ "systemPrompt": "...", "userPrompt": "...", "preferredProvider": "automatic" }
-> { "text": "..." }
```

The Swift `LLMProvider` enum is `proxy | openai | anthropic`, and Izraz sends
the string `"automatic"`. **No Chinese-model identifier appears anywhere in the
client code** — provider routing lives on the server, and the sustav.dev copy
in this workspace is only the static marketing site, so the server source is
not here. The one thing still needed before this can be written is the exact
`preferredProvider` string the proxy expects for the intended model.

**Built and deployed 2026-08-24.** `radio-api/` — PHP + SQLite, invite only,
live at `https://n7wgp.com/api`. See `CHANGELOG.md` for the security
properties and what was verified against the live host.

**The DeepSeek routing is not real yet, and the UI says so.** Two corrections
came out of testing:

- The proxy is **`https://api.sustav.dev/v1/prompt`** (Cloudflare Worker). The
  bare `sustav.dev/v1/prompt` in some Swift comments is the static marketing
  site and 404s.
- The worker's `/health` reports `configuredProviders: ["openai","anthropic"]`
  and does **not** reject an unknown provider — it silently falls back and
  names what actually answered in a `source` field. Asking for `deepseek`
  today returns OpenAI. The API forwards `source` and the review panel prints
  "answered by openai, not deepseek" rather than pretending.
  **Next step: add DeepSeek to the worker's configured providers.** No change
  is needed here when that happens — `RADIO_PROXY_PROVIDER` is already
  `deepseek`.

**The one remaining step is a secret, and it is not this project's to set.**
The worker behind `api.sustav.dev` is
`Sustav Dev/Pogodi/ios/cloudflare-worker`, and DeepSeek is already implemented
in it. `availableProviders()` lists a provider only when its API key exists, so:

```bash
cd "~/Library/CloudStorage/Dropbox/Personal/Sustav Dev/Pogodi/ios/cloudflare-worker"
npx wrangler secret put DEEPSEEK_API_KEY
```

After that `/health` should list `deepseek` first and the review panel will say
"answered by deepseek". No change is needed here.

Still open:

1. **Offline.** "Login for everything" and "works in the field with no signal"
   are in direct conflict. The fix is standard and must be built in from the
   start: authenticate once, cache the session token, and let every local
   feature keep working offline against it — never a login wall between a
   parked operator and their channel list.
4. **Coverage privacy.** A coverage log is a timestamped record of where
   someone drove. Storing it server-side makes the current footer claim
   ("nothing is uploaded, no data leaves this page") false, so that copy has to
   change honestly, and per-user isolation has to be real rather than assumed.

### The one thing blocking everything else

**Does `SET_REGION` (command 60) actually switch channel groups?** Still open
— but a real bug in how this app called it just got fixed, and it may have
been the whole reason this looked unverified.

**2026-08-24 finding:** `SET_REGION` gets **no reply at all** — confirmed by
[Ylianst/HTCommander](https://github.com/Ylianst/HTCommander)'s independent,
hardware-tested protocol notes (see `PROTOCOL.md`). This app's `setRegion()`
previously did `await request(...)` like every other command, which sits on
`radio.pending` waiting for a reply that was never coming — a silent 5-second
timeout on *every single call*, including the very first one inside "Probe
groups." That means every past test of group switching may have failed for a
reason that has nothing to do with whether the `u8` index guess is correct.
`setRegion()` is now fire-and-forget (`sendNoReply()`) with a 150ms settle
before the next command. **This needs a fresh hardware test** — the index
guess itself is still unconfirmed.

Three ways to settle it, cheapest first:

1. **Watch `curr_region` in the live status strip.** `StatusExt` reports the
   current group. Change groups using the radio's own menu and see whether the
   number tracks. That proves the field is real and gives you known-good values.
2. **Press "Probe groups."** It calls `setRegion(g)` then reads channel 0 of
   each group. Distinct contents per group ⇒ it works. Identical contents ⇒ the
   guess is wrong.
3. If wrong, capture what the VGC phone app actually sends. `SET_REGION` may
   take a 16-bit index, or group switching may run through `WRITE_REGION_CH`
   (58) instead.

Spec says the VR-N76 is **6 groups × 32 = 192 channels**. The radio reported
`channel_count: 32` over the wire (correct) but a `region_count` of 0 or 1
(suspect), which is why the group count is manually overridable in the UI.

**Display is 1-based, the wire is 0-based.** Confirmed against a real
VR-N76 2026-08-24: the radio's own screen calls the first group "1" and the
first channel in it "1", where the protocol's `SET_REGION`/`channel_id`
send `0`. Every group and slot/channel number the UI shows — group tabs,
the slot grid, the live status strip's `ch`/`group`, `Planned`/`On radio`
(`G1:1`), and the group-related log lines — is `wire value + 1` so it reads
the same as the radio's screen. `setRegion()`, `readChannel()`,
`writeChannel()`, and the `RfCh`/`BssSettings` codec are untouched and still
speak 0-based, exactly as the protocol requires — only display added the
offset. The one exception left alone on purpose: the **Channel library**
table's `#` column and the channel editor's **Channel #** field are the
library's own catalog IDs (from the master CSV), not a radio slot position,
so they were not renumbered.

---

## Roadmap

Ordered by value per unit of effort. Items 1–3 are fully decoded in benlink —
no reverse engineering needed, just implementation.

### 1. APRS / BSS settings page — **built 2026-08-24, needs a hardware pass**

`READ_BSS_SETTINGS` (33) / `WRITE_BSS_SETTINGS` (34). Fully decoded in
`benlink/protocol/command/bss_settings.py`. Sets the callsign and beacon once
from a keyboard instead of thumbing radio menus. In-app help panel explains
APRS/BSS/KISS/TNC in plain language, cross-referenced against the radio's own
menu tree (`Menu → General Settings → APRS Settings` / `Digital Mode`) per the
official VR-N76 manual. **Next**: read from a real radio, confirm the field
values match the phone app, then a write round-trip.

`BSSSettings` bitfield, in order:

| Bits | Field |
|---|---|
| 4 | `max_fwd_times` |
| 4 | `time_to_live` |
| 1 | `ptt_release_send_location` |
| 1 | `ptt_release_send_id_info` |
| 1 | `ptt_release_send_bss_user_id` |
| 1 | `should_share_location` |
| 1 | `send_pwr_voltage` |
| 1 | `packet_format` (0 = BSS, 1 = APRS) |
| 1 | `allow_position_check` |
| 1 | pad |
| 4 | `aprs_ssid` |
| 4 | pad |
| 8 | `location_share_interval` (×10 seconds) |
| 32 | `bss_user_id_lower` |
| 96 | `ptt_release_id_info` (12 chars) |
| 144 | `beacon_message` (18 chars) |
| 16 | `aprs_symbol` (2 chars) |
| 48 | `aprs_callsign` (6 chars) |

`BSSSettingsExt` appends `bss_user_id_upper` (32 bits) — switch on body length,
same trick as `Status` vs `StatusExt`.

Read body is a literal `2` (u8). UI: callsign **N7WGP**, SSID, symbol, beacon
text, share interval, and the PTT-release toggles.

### 2. Packet terminal (KISS TNC)

Receive already works — `DATA_RXD` events are decoded and printed to the log.
What is missing is transmit and proper framing.

- **Send:** `HT_SEND_DATA` (31). Body is `TncDataFragment`:
  `is_final_fragment:1 | with_channel_id:1 | fragment_id:6 | data | [channel_id:8]`.
  Fragment anything longer than one BLE write.
- **Receive:** already wired in `handleEvent()`, currently dumped as ASCII.
- **Then:** AX.25 frame decode → APRS parse (position, message, status) → show
  callsigns and positions. Position reports could drop straight onto the
  existing repeater map, which already has a working projection.

### 3. Radio settings page

`READ_SETTINGS` (10) / `WRITE_SETTINGS` (11), decoded in `settings.py`. ~60
fields: squelch, mic gain, TX time limit, TX hold, dual watch, power saving,
auto power off, screen timeout, PTT lock, NOAA channel, VFO frequencies and
power, time offset, imperial units.

Worth gating the dangerous ones behind a confirm — `ch_data_lock` and
`ptt_lock` can make the radio confusing until you find them again.

### 4. Check my imported list — the AI feature, as redefined 2026-08-24

**Superseded the "generate a list from a pin" idea.** The user imports their own
CHIRP CSV — which they already can — and the model *checks* it: flags tones
that look wrong for the band, offsets that do not match the standard plan,
duplicate or out-of-band frequencies, names that will truncate at 10
characters, and entries that look stale. Findings land as proposed edits in the
existing diff UI, never written to a radio unreviewed.

This is the better shape: it uses the model where it is reliable (spotting
inconsistency in data a human supplied) instead of where it is not (recalling
specific repeater frequencies from memory). It also needs no repeater database
and no licensing question.

The original idea, kept for reference: **drop a pin, get a list built for that
place** — repeaters in range, sorted by distance, with tones.

Open questions before this is a plan, not a wish:

- **Where does the data come from?** RepeaterBook has an API and clear terms;
  the current 80-site list was scraped from it in 2021 and is stale. Any
  answer has to respect the source's licence, and the page is currently
  dependency-free and makes zero network calls — a lookup breaks both
  properties, so it must be an explicit, optional, clearly-labelled action,
  never something the page does on load.
- **Where does the work happen?** A model call needs a backend and a key; the
  page has neither, by design. A precomputed regional bundle the page fetches
  on demand keeps the offline-first property and is much cheaper.
- **How is a bad suggestion caught?** A wrong tone or offset is a channel that
  silently doesn't work in the field. Anything generated must land in the
  library as *proposed*, diffable and editable, before it can be written to a
  radio.

The card for this is already on the Start page, marked "not built yet".

### 5. Smaller wins

- **GPS position** — `SET_POSITION` (32); controller exposes `position()`.
- **PF keys** — `GET_PF` (55) / `SET_PF` (56), decoded in `pf.py`.
- ~~**Group names**~~ — **built 2026-08-24.** `READ_REGION_NAME` (73) /
  `WRITE_REGION_NAME` (59), decoded by HTCommander (not benlink — see
  `PROTOCOL.md`). "Read group names from radio" / "Rename this group…" in
  the Radio layout panel. Independent of the `SET_REGION` question — both
  take the region index directly.
- **APRS path** — `SET_APRS_PATH` (71) / `GET_APRS_PATH` (72) are now
  decoded too (HTCommander): a plain variable-length UTF-8 string, reply
  `[status] + path`. Cheap to add — the BSS/APRS settings panel currently
  tells users to set this from the radio's own menu because it wasn't
  decoded when that panel was built; it now can be added to it directly.
- Import the AZ frequency coordinator's 70cm PDF to refresh tones; the
  RepeaterBook data behind the current list was last updated 2021.
- **Coverage log follow-ons**, once it has run on hardware: a "heard here /
  not heard here" view that pairs receptions against silence, an import so an
  older log can be reloaded after clearing site data, and IndexedDB instead of
  localStorage if 3000 rows turns out to be the binding limit.

### Not decoded — reverse engineering required

FM broadcast control (`RADIO_*`, 24–28), text messages (`GET_MSG`/`SET_MSG`,
67/68), advanced settings (29/30), `SET_VOLUME` (23), `SET_HT_ON_OFF` (21),
`READ_FREQ_RANGE` (39). `SET_REGION` (60) request/reply shape is now known
(see above) — what's still unconfirmed is only whether the `u8` index means
what this app assumes.

### Will never work in a browser

**Audio.** Bluetooth audio is a Classic profile (HFP/A2DP); Web Bluetooth is
BLE-only. No receive audio, no Bluetooth PTT. The HT app gets this because a
native app can open Classic profiles. Everything except audio is reachable.

---

## Adding the VR-N7600

The protocol is the same across Benshi radios, and the app already reads
capacity from the device rather than hard-coding it, so **it should mostly just
work**. Expected steps:

1. Connect and check the device-info line — `region_count × channel_count` is
   the whole capacity story.
2. Note anything odd in `radios/VR-N7600.md`.
3. The channel library is currently seeded from this station's CSV. If the
   N7600 wants a different list, regenerate with `build-library.mjs` against a
   different CSV, or import one in the browser.

The one thing to watch: `RfCh` has a DMR variant (`RfChDMR`, 25 bytes + colour
codes and slot). `decodeRfCh()` assumes the non-DMR 25-byte layout. If a radio
reports `support_dmr`, the record is longer and the decoder needs the variant —
benlink switches on body length in `channel_settings_disc()`.

---

## House rules for this project

- `vgc-programmer.html` is the only source file. `public/index.html` is
  generated by the deploy script; never edit it.
- `test-codec.mjs` extracts protocol code straight out of the HTML so it cannot
  drift. Run it before every deploy.
- Regenerate the built-in channel library with `node build-library.mjs --write`
  after editing the master CSV.
- Deploy is additive rsync, no `--delete`, dry run by default, and verifies
  jasonhuber.com's `/llm`, `/track` and `/travels` afterwards.
- Keep the page dependency-free and offline-capable — it is a field tool.
