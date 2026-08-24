# VGC / Benshi radio control — plan and handoff

Last rewritten **2026-08-24**. This file is written to be picked up **cold**:
read it top to bottom and you should be able to work without reading the code
first. `PROTOCOL.md` is the wire reference; `CHANGELOG.md` is the history.

---

## 1. What this is

A browser-based programmer and controller for **VGC VR-N76** and other
**Benshi-protocol** radios (VR-N7500, VR-N7600, BTech UV-Pro, RadioOddity
GA-5WB), plus a coverage-mapping tool and an AI channel-list reviewer.

Live at **<https://n7wgp.com>** — a Hostinger addon domain, Cloudflare DNS in
front. Built for the station **N7WGP** (Chandler, AZ, grid DM43bg).

Two deployable pieces:

| Piece | Source | Deployed to |
|---|---|---|
| The page | `vgc-programmer.html` — one self-contained file | `n7wgp.com/` |
| The API | `radio-api/` — PHP + SQLite | `n7wgp.com/api` |

The page talks to the radio over **Web Bluetooth** (BLE only — see §9) and to
the API for sign-in, coverage sync and list review. It is **invite only**: with
no session, the page shows nothing but a login card.

---

## 2. Repository map

| Path | What it is |
|---|---|
| `vgc-programmer.html` | **The entire front end.** Markup, CSS and JS in one file |
| `radio-api/index.php` | **The entire API.** Single-file router |
| `radio-api/config.php` | Secrets. **Gitignored.** Copy of `config.example.php` |
| `radio-api/radio-data/` | SQLite database. **Gitignored, server-side only** |
| `radio-api/deploy.sh` | Publishes the API |
| `deploy-n7wgp.sh` | Publishes the page |
| `build-library.mjs` | Master CSV → embedded channel library + site geocoding |
| `test-codec.mjs` | 76 offline protocol assertions, extracted from the HTML |
| `PROTOCOL.md` | Wire protocol: framing, every bitfield, what is guessed |
| `radios/` | Per-radio capacity, quirks, test status |
| `public/` | Deploy output. **Generated — never edit** |
| `scan.py` | BLE scan probe, kept as evidence; blocked by macOS TCC |

Master channel CSV lives **outside** this repo:
`~/Library/CloudStorage/Dropbox/Personal/HamRadio/03-Codeplugs/Current/MASTER-Channel-List-CORRECTED.csv`

---

## 3. How to work on it

```bash
node test-codec.mjs                 # 76 assertions — run before every deploy
node build-library.mjs              # summary + sanity checks, writes nothing
node build-library.mjs --write      # regenerate the embedded library from the CSV
./deploy-n7wgp.sh                   # dry run
./deploy-n7wgp.sh --apply           # publish the page
cd radio-api && ./deploy.sh         # dry run
cd radio-api && ./deploy.sh --apply # publish the API
php -l radio-api/index.php          # lint the API
```

Both deploys are additive rsync, no `--delete`, dry run by default. The API
deploy refuses to run with a placeholder secret in `config.php`, never syncs
`radio-data/` or any `.db`, and afterwards asserts that `config.php` and the
SQLite file both answer **403**.

### House rules

- `vgc-programmer.html` is the only front-end source file. `public/index.html`
  is generated at deploy time; never edit it.
- `test-codec.mjs` extracts the protocol code straight out of the HTML so it
  cannot drift. Run it before every deploy.
- Regenerate the library with `node build-library.mjs --write` after editing
  the master CSV — never hand-edit the `LIBRARY` block.
- Keep the page **dependency-free and offline-capable**. It is a field tool.
  The only permitted network calls are to its own `/api`.
- The page must stay usable with no signal once signed in. See §6.

---

## 4. State of everything

Read the third column carefully: several things are **built but have never
touched a radio**.

| Area | State | Verified how |
|---|---|---|
| BLE transport, bonding, retries | working | on real hardware |
| Channel read / write | working | 31 channels written to a real VR-N76 |
| `RfCh` codec (25 bytes) | verified | 76 offline assertions + live round-trip |
| Group/slot layout editor | built | in-browser only, **not hardware end-to-end** |
| Repeater map, 80 sites | working | in-browser |
| Live status strip (RSSI, TX/RX, battery, group) | built | **never seen real data** |
| Event push (status/channel/packet-in) | built | **never seen real data** |
| `SET_REGION` group switching | **unresolved** | see §7 — the blocker |
| APRS/BSS settings page | built | 4 offline assertions, **never run on hardware** |
| Group (region) rename | built | **never round-tripped on hardware** |
| Coverage log: sessions, GPS track, bubble map | built | in-browser with synthetic data, **never run against a radio** |
| Accounts, coverage sync, admin routes | **working** | end-to-end against the live host |
| AI list review (`/check-list`) | **working** | live; found a real library bug on first use |
| UI split into pages, on-page instructions | built | in-browser |

---

## 5. The front end

One file, ~4,000 lines, in this order: CSS → markup → JS. The JS sections are
commented as banners; search for them.

**Pages.** `VIEWS` maps a page name to an ordered list of panel ids, and
`showView(name, anchor)` toggles `.hidden` and sets CSS `order`. Any element
with `data-go="<page>"` navigates; add `data-anchor="<id>"` to scroll to a
heading. Pages: `start`, `radio`, `channels`, `map`, `coverage`, `packet`,
`guide`, `log`. Deep links work (`#coverage`).

**Key state, all in localStorage:**

| Key | Holds |
|---|---|
| `n7wgp.vrn76.library.v1` | the working channel library |
| `n7wgp.vrn76.layout.v1` | group/slot plan |
| `n7wgp.vrn76.coverage.v2` | `{sessions, rows, track, idleMin}` |
| `n7wgp.vrn76.auth.v1` | `{token, user}` |

`coverage.v1` (a flat row array) migrates into one session automatically on
first load. Do not remove that migration without a version bump.

**Numbering trap.** Display is **1-based**, the wire is **0-based**. Group
tabs, the slot grid, the status strip and every log line add 1 for display.
`setRegion()`, `readChannel()`, `writeChannel()` and the codecs are untouched
and speak 0-based. The one deliberate exception: the Channel library's `#`
column and the editor's **Channel #** are the library's own catalog IDs from
the master CSV, not radio slots, so they are not renumbered.

### The coverage log

**Passive. It never commands the radio.** It listens to `HT_STATUS_CHANGED`
pushes plus the status poll (1.2 s while logging, 4 s otherwise), opens a row
when `is_sq || is_in_rx` goes true, closes it when squelch shuts or the channel
changes underneath it, and tags it with `watchPosition()`'s latest fix.
`DATA_RXD` packets become their own rows. Squelch flutter under 0.4 s with no
RSSI sample is discarded.

- **Sessions.** One run each. Auto-end after `idleMin` (default 10) minutes
  with nothing heard, because people forget to press stop. A session that
  recorded nothing is discarded rather than cluttering the picker.
- **Track.** A GPS breadcrumb every ~25 m of movement or every 60 s standing
  still. This is what makes it a coverage map rather than a pile of hits — it
  shows where you heard *nothing*.
- **Bubble map.** Its own SVG (`#covmap`), fitted to the selected session, not
  to the QTH. Each reception is a bubble where it was heard, sized and coloured
  by signal. The repeater map (`#map`) is a separate thing on its own page and
  no longer carries coverage points.
- Caps: 5000 receptions, 8000 breadcrumbs.

**Everything it records comes from `StatusExt`, which this project has never
seen arrive.** RSSI, `curr_region` and the upper channel bits all live in the
extended half. So the first real-hardware run is simultaneously (a) the
hardware test the status strip has been waiting for, (b) evidence on whether
`curr_region` is real — which is the cheapest way to settle §7 — and (c) the
coverage log's own shakedown. **If the group column reads 1 everywhere and RSSI
is blank, the radio is sending short `Status`** and only the
timestamp/channel/position half of each row is trustworthy.

**Why it cannot start the scan.** No scan start/stop command has been decoded
for this protocol — not in benlink, not in HTCommander. `RfCh.scan` only marks
a channel as *included* in a sweep the operator starts from the keypad;
`Status.is_scan` is read-only telemetry. An app-driven sweep would need a way
to set the *current* channel; the two untested candidates are
`WRITE_REGION_CH` (58, undecoded) and `channel_a`/`channel_b` in `Settings`
(10/11, decoded in benlink, not implemented). Before building that: a sweep
writes to the radio's settings store hundreds of times an hour. Understand the
wear implications and gate it behind an explicit opt-in.

---

## 6. The API (`radio-api/`)

PHP 8 + SQLite, single-file router, modelled on `jasonhuber.com/track-api`.
Function prefix `r_`. Mounted at `/api`; `.htaccess` routes everything to
`index.php` and forces the `Authorization` header through (Hostinger strips it
otherwise — that is why the rewrite rule exists, do not delete it).

### Routes

| Route | Auth | Notes |
|---|---|---|
| `GET /api` | — | discovery |
| `GET /api/health` | — | liveness + user count |
| `POST /api/auth/redeem` | — | invite + email + password → token |
| `POST /api/auth/login` | — | email + password → token |
| `POST /api/auth/logout` | Bearer | revokes the calling token |
| `GET /api/auth/me` | Bearer | validates a cached token |
| `GET /api/coverage` | Bearer | pull this account's log |
| `POST /api/coverage` | Bearer | push sessions/rows/track |
| `DELETE /api/coverage` | Bearer | `?session=<id>` or `?all=1` |
| `POST /api/check-list` | Bearer | AI review of a channel list |
| `POST /api/admin/invite` | Admin | mint codes |
| `GET /api/admin/invite` | Admin | list codes and who used them |
| `GET /api/admin/users` | Admin | list accounts |
| `DELETE /api/admin/users` | Admin | `?email=…` — deletes the account and all its data, frees its invite |

### Security properties — preserve these

- **Invite only.** There is no open signup route at all. Every account can
  spend model tokens through the proxy; that is the reason.
- Passwords: `password_hash` / `password_verify`. Minimum 10 characters.
- Session tokens are 32 random bytes, stored **hashed with a pepper**
  (`RADIO_TOKEN_PEPPER`), so a database copy is not a set of live logins.
  Changing the pepper logs everyone out — the intended panic button.
- Login runs a `password_verify` even for an unknown email so timing does not
  reveal which addresses have accounts, and both failure paths return one
  identical message.
- Per-IP rate limits on login and redeem; per-user daily cap on `/check-list`.
- **Every coverage query is scoped by `user_id`.** There is no read of a
  coverage row without one. Verified: a second account sees nothing.
- CORS is an explicit origin allow-list. **Never widen it to `*`** — that would
  let any site on the internet spend a signed-in user's session.

### Offline is a first-class requirement

"Login for everything" and "works in a canyon with no signal" are in direct
conflict, and the resolution is load-bearing:

- The token and user are cached in localStorage.
- The app renders from cache **before** any network round trip.
- A failed `/auth/me` means **offline**, never signed out.
- **Only an explicit 401 clears a session.**
- Sessions last 180 days.

Do not "tidy" this into a blocking auth check on boot. There must never be a
login wall between a parked operator and their channel list.

### Sync

Push and pull **merge**, never replace, so two devices can both contribute.
Re-pushing is idempotent: rows key on `sid|t|g|ch|kind`, track on `sid|t`.
A finished session uploads itself. Verified by wiping the browser copy and
pulling it all back.

### Credentials

`radio-api/config.php` (gitignored, 403 on the server) holds
`RADIO_ADMIN_TOKEN` and `RADIO_TOKEN_PEPPER`. Read the admin token locally:

```bash
php -r 'require "radio-api/config.php"; echo RADIO_ADMIN_TOKEN, PHP_EOL;'
```

Mint invites:

```bash
curl -s -X POST https://n7wgp.com/api/admin/invite \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' -d '{"count":3,"note":"who for"}'
```

SSH credentials for both deploys come from
`../../jasonhuber.com/llm-api/.env` — n7wgp.com is an addon domain on that
same Hostinger account.

### The AI proxy

```
POST https://api.sustav.dev/v1/prompt
{ "systemPrompt": …, "userPrompt": …, "preferredProvider": "deepseek" }
-> { "text": …, "source": "openai" }
```

Two traps, both already hit:

1. The endpoint is **`api.sustav.dev`**. The bare `sustav.dev/v1/prompt` that
   appears in some Swift comments is the static marketing site and **404s**.
2. The worker **does not reject an unknown provider** — it silently falls back
   and names what actually answered in `source`. So a wrong provider string
   looks like success. The API forwards `source` and the review panel prints
   "answered by openai, not deepseek" rather than pretending.

The worker source is `Sustav Dev/Pogodi/ios/cloudflare-worker` (routed to
`api.sustav.dev`). **DeepSeek is already fully implemented there** —
`Provider` includes it, `DEEPSEEK_MODEL = "deepseek-v4-flash"`, routed to
DeepSeek's Anthropic-compatible surface. `availableProviders()` lists a provider
only when its API key exists, so the only missing piece is the secret:

```bash
cd "$HOME/Library/CloudStorage/Dropbox/Personal/Sustav Dev/Pogodi/ios/cloudflare-worker"
npx wrangler secret put DEEPSEEK_API_KEY
```

Nothing in this project changes when that lands — it already asks for
`deepseek`, and the worker orders providers cheapest-first. Note the worker's
`DEFAULT_PROVIDER` is portfolio-wide, so this affects every Sustav app that
does not pin a provider.

---

## 7. The blocker: does `SET_REGION` actually switch groups?

**Still unresolved.** This gates the whole multi-group story.

`SET_REGION` (60) gets **no reply at all** — confirmed by HTCommander's
hardware-tested notes. This app used to `await request(...)` like every other
command, burning a silent 5-second timeout on *every* call including the first
one inside "Probe groups". That is fixed (`sendNoReply()` plus a 150 ms
settle), which means **every previous test of group switching may have failed
for a reason unrelated to the index guess.** Needs a fresh hardware test.

Three ways to settle it, cheapest first:

1. **Watch `curr_region` in the status strip** while changing groups on the
   radio's own keypad. Proves the field is real and gives known-good values.
   Running the coverage log does this for free.
2. **Press "Probe groups."** It calls `setRegion(g)` then reads channel 0 of
   each. Distinct contents ⇒ it works. Identical ⇒ the guess is wrong.
3. If wrong, capture what the VGC phone app sends. `SET_REGION` may take a
   16-bit index, or switching may run through `WRITE_REGION_CH` (58).

The VR-N76 is specced at **6 groups × 32 = 192**. The radio reports
`channel_count: 32` correctly but a `region_count` of 0 or 1, which is why the
group count is manually overridable in the UI.

---

## 8. Roadmap

### 8.1 Hardware pass — do this first, it costs one evening

Nothing below matters as much as putting a radio in front of what is already
built. In one sitting you can settle §7, the status strip, event push, the
coverage log, the APRS panel and group rename. Order:

1. Connect. Watch the status strip — does RSSI/group appear (`StatusExt`)?
2. Change groups on the keypad. Does `curr_region` track?
3. Press "Probe groups."
4. Start a coverage session, drive around the block, check the bubble map.
5. "Read from radio" on the APRS panel; compare to the phone app; write back.
6. "Read group names"; rename one.

Record what you learn in `radios/VR-N76.md` and `CHANGELOG.md`.

### 8.2 Packet terminal (KISS TNC)

Receive already works (`DATA_RXD` → protocol log → coverage rows). Missing:

- **Send:** `HT_SEND_DATA` (31), body is `TncDataFragment`
  (`is_final:1 | with_channel_id:1 | fragment_id:6 | data | [channel_id:8]`).
  Fragment anything longer than one BLE write.
- **Then:** AX.25 decode → APRS parse → positions onto the map, which already
  has a working projection.

### 8.3 Radio settings page

`READ_SETTINGS` (10) / `WRITE_SETTINGS` (11), decoded in benlink's
`settings.py`. ~60 fields: squelch, mic gain, TX time limit, dual watch, power
saving, screen timeout, PTT lock, NOAA channel, VFO frequencies, time offset.
Gate `ch_data_lock` and `ptt_lock` behind a confirm — both can make the radio
confusing until you find them again. This also unlocks `channel_a`/`channel_b`,
which is the prerequisite for any app-driven scan (§5).

### 8.4 Smaller wins

- **APRS path** — `SET_APRS_PATH` (71) / `GET_APRS_PATH` (72), decoded by
  HTCommander: a plain UTF-8 string, reply `[status] + path`. Cheap. The APRS
  panel currently tells users to set it from the radio's menu.
- **GPS position** — `SET_POSITION` (32).
- **PF keys** — `GET_PF` (55) / `SET_PF` (56), decoded in `pf.py`.
- **Password reset.** There is none, and no email is sent from that host.
  Today recovery means deleting the account with the admin token and issuing a
  fresh invite. Worth building before handing codes to anyone else.
- **Owner-visible admin UI.** The API has no notion of an owner *account* —
  admin is a separate Bearer token used from curl. If the owner should see the
  user list in the browser, that is a small addition.
- **Coverage follow-ons:** a "heard here / not heard here" view pairing
  receptions against track silence; import so an old export can be reloaded;
  IndexedDB if 5000 rows becomes the binding limit.
- Refresh tones from the AZ frequency coordinator's 70cm PDF — the
  RepeaterBook data behind the current list was last updated **2021**.

### 8.5 Location-driven channel lists — deferred, not abandoned

The original idea was: drop a pin, get a list built for that place. It was
**superseded** by the list-review feature (§6), which is now live and needs no
repeater database and no licensing question. If the pin idea comes back, the
three questions that were never answered:

- **Where does the data come from?** RepeaterBook has an API and clear terms.
  Any answer must respect the source's licence.
- **Where does the work happen?** A precomputed regional bundle fetched on
  demand preserves the offline-first property; a live model call in the request
  path does not.
- **How is a bad suggestion caught?** A wrong tone is a channel that silently
  fails in the field. Anything generated must land as *proposed* and diffable.

The Start page still carries a card for this, marked "not built yet".

### 8.6 Not decoded — reverse engineering required

FM broadcast control (`RADIO_*`, 24–28), text messages (67/68), advanced
settings (29/30), `SET_VOLUME` (23), `SET_HT_ON_OFF` (21), `READ_FREQ_RANGE`
(39), `WRITE_REGION_CH` (58). For `SET_REGION` (60) the request/reply shape is
known; only the meaning of the index is unconfirmed.

---

## 9. Hard limits — do not try to design around these

- **Audio is impossible.** Bluetooth audio is a Classic profile (HFP/A2DP);
  Web Bluetooth is BLE-only. No receive audio, no Bluetooth PTT, ever. The
  phone app has it because a native app can open Classic profiles. Everything
  except audio is reachable.
- **iOS cannot run this.** No iOS browser implements Web Bluetooth — they are
  all Safari underneath. Firefox does not either, on any platform.
- **The radio requires a bonded link.** It accepts an unbonded central and
  drops it about a second later, which looks exactly like a failed connection.
  The OS pairing prompt *is* the browser's pairing prompt.
- **RSSI is 4 bits.** Sixteen uncalibrated steps. Relative, never dBm.
- **The tab must stay in the foreground while logging.** A backgrounded mobile
  browser suspends BLE notifications and throttles GPS.

---

## 10. Adding the VR-N7600

The protocol is shared and the app reads capacity from the device, so it should
mostly just work.

1. Connect, check the device-info line — `region_count × channel_count` is the
   whole capacity story.
2. Note anything odd in `radios/VR-N7600.md`.
3. Reseed the library from a different CSV if wanted, or import one in-browser.

**The one real trap:** `RfCh` has a DMR variant (`RfChDMR` — plain `RfCh` plus
`tx_color:4`, `rx_color:4`, `slot:1`, `pad:7`). `decodeRfCh()` assumes the
non-DMR 25-byte layout. If a radio reports `support_dmr` the record is longer
and the decoder needs the variant; benlink switches on body length in
`channel_settings_disc()`.

---

## 11. Things that have already bitten, so you do not repeat them

- **`SET_REGION` waits forever.** It never replies. Fixed, but the same trap
  exists for any other no-reply command.
- **`sustav.dev/v1/prompt` 404s.** It is `api.sustav.dev`.
- **The proxy falls back silently.** An unknown provider is not an error. Always
  surface `source`.
- **PHP's 30 s execution limit kills model calls** with a fatal, not an error
  the browser can act on. `/check-list` calls `set_time_limit(120)`. A real call
  takes 40–60 s.
- **`curl_close()` is deprecated in PHP 8.5** and its notice will land in the
  JSON body on a host with `display_errors` on. It is a no-op since 8.0; do not
  reintroduce it.
- **Greedy label placement must not reserve a label's own marker.** The first
  bubble-map attempt placed **zero** site labels because every candidate box
  collided with its own site's ring reservation.
- **localStorage throws on `data:` URLs**, which is how the preview pane renders
  local files. All storage access is wrapped in try/catch; keep it that way.
- **The GMRS interstitials were wrong in the shipped library** — all 14 were
  wide when they must be 12.5 kHz narrow, and ch 8–14 were at 8 W instead of
  0.5 W. Found by the list-review feature on its first real use, fixed in the
  master CSV. Note the model was only *partly* right: it also wanted low power
  on ch 1–7, where 5 W is permitted — it had applied the FRS limit. **Findings
  are advisory; check them.**

---

## 12. Honesty constraints

These are commitments the page makes to its users. Do not quietly break them.

- **Say what leaves the device.** The footer, the Start page and the guide now
  state plainly that channels and groups stay local while the **coverage log —
  including positions — is stored on the server** against the account. Earlier
  copy claimed "nothing is uploaded"; that became false the moment sync
  shipped, and was corrected. If you add another upload, update the copy in the
  same commit.
- **Never present a model's output as fact.** The review panel labels findings
  as suggestions, never writes to the library, and names the provider that
  actually answered.
- **Flag what is unverified.** Panels covering untested protocol work say so on
  the panel itself. Keep that habit — this is a tool that writes to hardware.
- **Transmit only where licensed** is not boilerplate. The GMRS and satellite
  entries have their own rules, and this is an amateur radio.
