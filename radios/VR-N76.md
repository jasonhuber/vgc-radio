# VGC VR-N76

The radio this tooling was built against. Station: **N7WGP**, DM43bg.

## Capacity

| Source | Groups | Slots/group | Total |
|---|---|---|---|
| Vendor + dealer specs | 6 | 32 | 192 |
| Reported by this radio over BLE | **0 or 1 (suspect)** | **32 (correct)** | — |

`channel_count` comes back as 32 and matches the specs. `region_count` comes
back too low, which is why the app lets you override the group count manually.
Set **groups = 6, slots = 32**.

Some dealer pages also claim "12 channel banks" or "16 × 12". All variants
total 192, so the sources agree on capacity and disagree on arrangement.
32 slots per group is the figure measured over the wire, so trust that one.

## Bluetooth

- Address as a Classic device: `38:D2:00:00:F8:D9`, presents as **Headset**.
  That is the audio profile and is irrelevant to control.
- Control runs over **BLE GATT** and is reachable from a browser.
- **Requires a bonded link.** Put the radio into pairing mode from its own
  menu, then accept the OS pairing prompt. Without a bond it accepts the
  connection and drops it within a second, which looks like a connection
  failure rather than a pairing problem.
- Serves **one controller at a time.** Turn Bluetooth off on the phone —
  quitting the VGC app is not enough, phones keep the bond and reconnect.

## Verified working

- Reading channels back off the radio.
- Writing channels — 31 written successfully in one pass, 2026-08-23.
- Device info, including the capacity fields.

## Verified failing

- Writing to any slot ≥ `channel_count` returns `INVALID_PARAMETER` per
  channel. The app now checks capacity first.

## Untested

- `SET_REGION` group switching (see `../PLAN.md`).
- Live status strip, battery, event push — implemented, never seen real data.
- DMR: this radio reports `support_dmr` false, so the plain 25-byte `RfCh`
  layout applies.

## Other capabilities not yet exploited

KISS TNC, APRS beaconing, satellite tracking with Doppler correction (built
into the radio firmware, not controllable over this protocol). The satellite
feature is the reason this radio is the right one for the campground work in
`../00-README.md` — it just needs a directional antenna.
