/* Verifies the bit-level codec in vgc-programmer.html against the layout in
   benlink's rf_ch.py / message.py. Pure logic -- no radio, no DOM.
   Run: node test-codec.mjs                                          */
import { readFileSync } from "node:fs";

// Pull the DOM-free protocol section straight out of the page so the
// test can never drift from the code that actually ships.
const html = readFileSync(new URL("./vgc-programmer.html", import.meta.url), "utf8");
const start = html.indexOf('const SVC   =');
const end   = html.indexOf('/* ============================================================\n   BLE transport');
if (start < 0 || end < 0) throw new Error("could not locate protocol section");
const src = html.slice(start, end);
const mod = await import("data:text/javascript," + encodeURIComponent(
  src + "\nexport { BitW, BitR, decodeRfCh, encodeRfCh, buildMessage, parseMessage," +
        " subAudioDecode, subAudioEncode, toneToText, toneFromText, powerFromCsv, CMD };"
));

let pass = 0, fail = 0;
const eq = (got, want, label) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) { pass++; console.log(`  ok   ${label}`); }
  else { fail++; console.log(`  FAIL ${label}\n         got  ${g}\n         want ${w}`); }
};
const hex = u8 => Array.from(u8).map(b => b.toString(16).padStart(2, "0")).join(" ");

console.log("\n— message framing —");
// group=2 (u16) | is_reply=0 (1b) | cmd=13 (15b) | channel_id=0 (u8)
const readCh0 = mod.buildMessage(mod.CMD.READ_RF_CH, w => w.int(0, 8));
eq(hex(readCh0), "00 02 00 0d 00", "READ_RF_CH ch0 encodes to 5 bytes");
const readCh7 = mod.buildMessage(mod.CMD.READ_RF_CH, w => w.int(7, 8));
eq(hex(readCh7), "00 02 00 0d 07", "READ_RF_CH ch7");
const info = mod.buildMessage(mod.CMD.GET_DEV_INFO, w => w.int(3, 8));
eq(hex(info), "00 02 00 04 03", "GET_DEV_INFO body literal 3");

// a synthetic reply: group=2, is_reply=1, cmd=13  -> byte2 bit7 set
const replyHdr = new Uint8Array([0x00, 0x02, 0x80, 0x0d, 0x00]);
const pm = mod.parseMessage(replyHdr);
eq([pm.group, pm.isReply, pm.command], [2, true, 13], "reply header parses is_reply=1");

console.log("\n— sub-audio (CTCSS / DCS) —");
eq(mod.subAudioEncode({ ctcss: 88.5 }), 8850, "CTCSS 88.5 -> 8850");
eq(mod.subAudioEncode({ ctcss: 100.0 }), 10000, "CTCSS 100.0 -> 10000");
eq(mod.subAudioEncode({ dcs: 23 }), 23, "DCS 023 -> 23");
eq(mod.subAudioEncode(null), 0, "none -> 0");
eq(mod.subAudioDecode(8850), { ctcss: 88.5 }, "8850 -> CTCSS 88.5");
eq(mod.subAudioDecode(23), { dcs: 23 }, "23 -> DCS 023");
eq(mod.subAudioDecode(0), null, "0 -> none");
eq(mod.toneToText({ dcs: 23 }), "D023", "DCS renders as D023");
eq(mod.toneToText({ ctcss: 162.2 }), "162.2", "CTCSS renders as 162.2");
eq(mod.toneFromText("D023"), { dcs: 23 }, "parse D023");
eq(mod.toneFromText("88.5"), { ctcss: 88.5 }, "parse 88.5");
eq(mod.toneFromText(""), null, "parse empty -> none");

console.log("\n— RfCh round-trip —");
// Real case from the station notes: K7AWW Young 145.11, DCS 023 (NOT CTCSS).
const ch = {
  channel_id: 3, tx_mod: 0, tx_freq: 144.510, rx_mod: 0, rx_freq: 145.110,
  tx_sub_audio: { dcs: 23 }, rx_sub_audio: { dcs: 23 },
  scan: true, tx_at_max_power: true, talk_around: false, bandwidth: 1,
  pre_de_emph_bypass: false, sign: false, tx_at_med_power: false,
  tx_disable: false, fixed_freq: false, fixed_bandwidth: false,
  fixed_tx_power: false, mute: false, name: "K7AWW Yng"
};
const w = new mod.BitW();
mod.encodeRfCh(ch, w);
const bytes = w.bytes();
eq(bytes.length, 25, "RfCh encodes to exactly 25 bytes (200 bits)");
console.log(`       raw: ${hex(bytes)}`);

const back = mod.decodeRfCh(new mod.BitR(bytes));
for (const k of Object.keys(ch)) {
  if (k === "name") { eq(back.name, ch.name, "field name"); continue; }
  eq(back[k], ch[k], `field ${k}`);
}

console.log("\n— 30-bit frequency precision —");
for (const f of [144.0, 146.82, 147.995, 440.0, 449.425, 446.600]) {
  const w2 = new mod.BitW();
  mod.encodeRfCh({ ...ch, rx_freq: f, tx_freq: f }, w2);
  const d = mod.decodeRfCh(new mod.BitR(w2.bytes()));
  eq(d.rx_freq, f, `${f} MHz survives 30-bit Hz round-trip`);
}

console.log("\n— name field —");
const w3 = new mod.BitW();
mod.encodeRfCh({ ...ch, name: "0123456789ABC" }, w3);   // over-long
eq(mod.decodeRfCh(new mod.BitR(w3.bytes())).name, "0123456789", "name truncates to 10 chars");
const w4 = new mod.BitW();
mod.encodeRfCh({ ...ch, name: "" }, w4);
eq(mod.decodeRfCh(new mod.BitR(w4.bytes())).name, "", "empty name round-trips");

console.log("\n— CHIRP Power column —");
// The master list uses wattage strings, not CHIRP's words. Both must work.
eq(mod.powerFromCsv("8.0W"), "high", '"8.0W" -> high (master list format)');
eq(mod.powerFromCsv("5W"), "high", '"5W" -> high');
eq(mod.powerFromCsv("2.0W"), "med", '"2.0W" -> med');
eq(mod.powerFromCsv("0.5W"), "low", '"0.5W" -> low (FRS cap)');
eq(mod.powerFromCsv("High"), "high", '"High" -> high');
eq(mod.powerFromCsv("Mid"), "med", '"Mid" -> med');
eq(mod.powerFromCsv("Low"), "low", '"Low" -> low');
eq(mod.powerFromCsv(""), "high", "empty -> high");

console.log("\n— CSV row math, against real master-list rows —");
// Mirrors the duplex/offset/tone conversion done by importCsv().
const conv = (freq, duplex, offset, tone, rTone, dtcs) => {
  const rx = parseFloat(freq), off = parseFloat(offset) || 0;
  let tx = rx;
  if (duplex === "+") tx = rx + off;
  else if (duplex === "-") tx = rx - off;
  let txt = null, rxt = null;
  const tm = (tone || "").toUpperCase();
  if (tm === "TONE") txt = { ctcss: parseFloat(rTone) };
  else if (tm === "DTCS") { const d = parseInt(dtcs, 10); txt = { dcs: d }; rxt = { dcs: d }; }
  return { tx: +tx.toFixed(6), txt, rxt };
};
// row 0: 2m Natl simplex
eq(conv("146.52", "", "0", "", "88.5", "023"),
   { tx: 146.52, txt: null, rxt: null }, "ch0 146.52 simplex, no tone");
// row 2: W7NAZ 447.475 -5 MHz, CTCSS 88.5 TX only
eq(conv("447.475", "-", "5", "Tone", "88.5", "023"),
   { tx: 442.475, txt: { ctcss: 88.5 }, rxt: null }, "ch2 W7NAZ -> TX 442.475, CTCSS 88.5");
// row 3: K7AWW 145.11 -0.6 MHz, DCS 023 -- the one that would never key as CTCSS
eq(conv("145.11", "-", "0.6", "DTCS", "88.5", "023"),
   { tx: 144.51, txt: { dcs: 23 }, rxt: { dcs: 23 } }, "ch3 K7AWW -> TX 144.51, DCS 023 (not CTCSS)");
// row 99: K0NL 449.425 -5 MHz, CTCSS 100.0
eq(conv("449.425", "-", "5", "Tone", "100.0", "023"),
   { tx: 444.425, txt: { ctcss: 100 }, rxt: null }, "ch99 K0NL -> TX 444.425, CTCSS 100.0");

console.log(`\n${pass} passed, ${fail} failed\n`);
process.exit(fail ? 1 : 0);
