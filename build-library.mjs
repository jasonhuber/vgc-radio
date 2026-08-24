/* Turns the CHIRP master channel list into the channel library embedded in
   vgc-programmer.html, assigning each channel to a group. Regenerate after editing
   the CSV:

     node build-library.mjs            # summary only
     node build-library.mjs --write    # rewrite the LIBRARY block in vgc-programmer.html

   Groups come from the structure documented in ../00-README.md plus the
   [TRAVEL] tags already in the CSV comments.                              */
import { readFileSync, writeFileSync } from "node:fs";

const CSV = new URL("../03-Codeplugs/Current/MASTER-Channel-List-CORRECTED.csv", import.meta.url);
const HTML = new URL("./vgc-programmer.html", import.meta.url);

function parseCsvLine(line) {
  const out = []; let cur = "", q = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (q) { if (ch === '"') { if (line[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += ch; }
    else if (ch === '"') q = true;
    else if (ch === ",") { out.push(cur); cur = ""; }
    else cur += ch;
  }
  out.push(cur); return out;
}

const lines = readFileSync(CSV, "utf8").split(/\r?\n/).filter(l => l.trim());
const hdr = parseCsvLine(lines[0]).map(h => h.trim());
const ix = {}; hdr.forEach((h, i) => ix[h] = i);

function powerFromCsv(s) {
  const t = (s || "").trim().toLowerCase();
  if (!t) return "high";
  if (t.startsWith("high")) return "high";
  if (t.startsWith("mid") || t.startsWith("med")) return "med";
  if (t.startsWith("low")) return "low";
  const w = parseFloat(t);
  if (!isNaN(w)) return w >= 4 ? "high" : (w >= 1 ? "med" : "low");
  return "high";
}

/* ---------------------------------------------------------------
   Transmitter site coordinates.

   APPROXIMATE. These come from public knowledge of well-known
   Phoenix-area radio sites and from city/landmark centroids -- they
   are NOT surveyed antenna locations, and the coordinators do not
   publish exact ones. Good enough to answer "roughly where is this
   and is it plausibly in range"; not good enough for path analysis.
   Refine from the AZ frequency coordinator listings if that changes.
   Key is the CSV Comment with [tags] stripped, lowercased.
   --------------------------------------------------------------- */
const SITES = {
  // named high sites -- the ones that actually matter for coverage
  "phoenix, south mountain":        [33.3387, -112.0662, "South Mountain"],
  "phoenix, shaw butte":            [33.5936, -112.0850, "Shaw Butte"],
  "phoenix, north mtn":             [33.5836, -112.0722, "North Mountain"],
  "phoenix, far north mountain":    [33.5900, -112.0700, "North Mountain"],
  "phoenix, brown mountain":        [33.3500, -112.1400, "Brown Mountain"],
  "phoenix, twin knolls":           [33.3400, -112.0300, "Twin Knolls"],
  "phoenix, papago park":           [33.4550, -111.9500, "Papago Park"],
  "phoenix, chase tower":           [33.4484, -112.0740, "Chase Tower"],
  "phoenix,  chase tower":          [33.4484, -112.0740, "Chase Tower"],
  "tempe, bell butte":              [33.4250, -111.9600, "Bell Butte"],
  "mesa, usery pass":               [33.4736, -111.6000, "Usery Pass"],
  "mesa, usery mountain":           [33.4700, -111.6100, "Usery Mountain"],
  "scottsdale, thompson peak":      [33.6553, -111.8267, "Thompson Peak"],
  "scottsdale, pinnacle peak":      [33.7200, -111.8600, "Pinnacle Peak"],
  "casa grande, sacaton peak":      [33.0847, -111.7386, "Sacaton Peak"],

  // buildings and street sites -- low, mostly local coverage
  "scottsdale, airport":            [33.6229, -111.9105, "Scottsdale Airport"],
  "scottsdale, hayden gd site":     [33.5000, -111.9070, "Hayden Rd"],
  "scottsdale, old town scottsdale":[33.4940, -111.9260, "Old Town Scottsdale"],
  "scottsdale, honorhealth scottsdale shea medical center":
                                    [33.5810, -111.9200, "HonorHealth Shea"],
  "phoenix, honor health - deer valley":
                                    [33.6800, -112.1100, "HonorHealth Deer Valley"],
  "phoenix, honor health hospital -dunlap & central":
                                    [33.5670, -112.0740, "HonorHealth Dunlap"],
  "phoenix, arizona national guard armory building":
                                    [33.4600, -112.0500, "NG Armory"],
  "phoenix, 24th st & thomas":      [33.4805, -112.0300, "24th St & Thomas"],
  "phoenix, 22nd st & camelback rd.":[33.5090, -112.0330, "22nd St & Camelback"],
  "mesa, dobson and university dr.":[33.4220, -111.8760, "Dobson & University"],
  "mesa, 1201 s. alma school rd":   [33.4030, -111.8570, "Alma School Rd"],
  "tempe, jgm water treatment plant":[33.4400, -111.9700, "JGM Water Plant"],
  "laveen, corona ranch and rodeo grounds":
                                    [33.3600, -112.1700, "Corona Ranch"],
  "peoria, sunrise mountain high school":
                                    [33.6700, -112.2400, "Sunrise Mtn HS"],
  "peoria, western phoenix":        [33.5800, -112.2370, "West Phoenix"],
  "youngtown, west valley ham shack":[33.5906, -112.3030, "Youngtown"],
  "maricopa, central az college - maricopa campus":
                                    [33.0560, -112.0400, "CAC Maricopa"],
  "san tan valley, broadcast tower":[33.1900, -111.5400, "San Tan Valley"],
  "queen creek, municipal services building":
                                    [33.2480, -111.6340, "Queen Creek"],
  "payson, forest lakes":           [34.4658, -110.8656, "Forest Lakes"],
  "young, moon drive":              [34.1050, -110.9370, "Young"],

  // bare city names
  "chandler":   [33.3062, -111.8413, "Chandler"],
  "mesa":       [33.4152, -111.8315, "Mesa"],
  "phoenix":    [33.4484, -112.0740, "Phoenix"],
  "scottsdale": [33.4942, -111.9261, "Scottsdale"],
  "tempe":      [33.4255, -111.9400, "Tempe"],
  "glendale":   [33.5387, -112.1860, "Glendale"],
  "maricopa":   [33.0581, -112.0476, "Maricopa"],
  "laveen":     [33.3630, -112.1690, "Laveen"],
  "payson":     [34.2310, -111.3470, "Payson"],
  "overgaard":  [34.4130, -110.5540, "Overgaard"]
};

const QTH = [33.250279, -111.882017];

function haversineMi(a, b) {
  const R = 3958.8, rad = d => d * Math.PI / 180;
  const dLat = rad(b[0] - a[0]), dLon = rad(b[1] - a[1]);
  const s = Math.sin(dLat / 2) ** 2 +
            Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(s));
}
function bearingDeg(a, b) {
  const rad = d => d * Math.PI / 180, deg = r => r * 180 / Math.PI;
  const dLon = rad(b[1] - a[1]);
  const y = Math.sin(dLon) * Math.cos(rad(b[0]));
  const x = Math.cos(rad(a[0])) * Math.sin(rad(b[0])) -
            Math.sin(rad(a[0])) * Math.cos(rad(b[0])) * Math.cos(dLon);
  return (deg(Math.atan2(y, x)) + 360) % 360;
}
const COMPASS = ["N","NNE","NE","ENE","E","ESE","SE","SSE","S","SSW","SW","WSW","W","WNW","NW","NNW"];
const compassOf = d => COMPASS[Math.round(d / 22.5) % 16];

function siteFor(comment) {
  const clean = comment.replace(/\[[^\]]*\]/g, "")
                       .replace(/&amp;/g, "&")
                       .replace(/\s+/g, " ").trim().replace(/[,\s]+$/, "").toLowerCase();
  if (!clean) return null;
  if (SITES[clean]) return SITES[clean];
  const city = clean.split(",")[0].trim();          // fall back to the city
  if (SITES[city]) return SITES[city];
  return null;
}

// Group assignment. Order matters -- first match wins.
function groupOf(id, rx, comment, name) {
  if (id >= 105 && id <= 126) return "gmrs";
  if (rx >= 430 && rx <= 440 && id >= 127) return "sat";
  if (/\[TRAVEL/i.test(comment)) return "travel";
  const simplex = !/[+-]/.test((ix.Duplex != null ? "" : ""));   // resolved by caller
  return null;   // caller fills simplex vs metro
}

const channels = [];
for (let i = 1; i < lines.length; i++) {
  const f = parseCsvLine(lines[i]);
  const get = k => (ix[k] != null ? (f[ix[k]] || "").trim() : "");
  const rx = parseFloat(get("Frequency"));
  const id = parseInt(get("Location"), 10);
  if (!rx || isNaN(id)) continue;

  const duplex = get("Duplex").toLowerCase();
  const off = parseFloat(get("Offset")) || 0;
  let tx = rx;
  if (duplex === "+") tx = rx + off;
  else if (duplex === "-") tx = rx - off;

  const toneMode = get("Tone").toUpperCase();
  let txt = null, rxt = null;
  if (toneMode === "TONE") txt = { ctcss: parseFloat(get("rToneFreq")) };
  else if (toneMode === "TSQL") { const t = parseFloat(get("cToneFreq")); txt = { ctcss: t }; rxt = { ctcss: t }; }
  else if (toneMode === "DTCS") { const d = parseInt(get("DtcsCode"), 10); txt = { dcs: d }; rxt = { dcs: d }; }

  const comment = get("Comment");
  const isSimplex = Math.abs(tx - rx) < 1e-6;

  let group = groupOf(id, rx, comment, get("Name"));
  if (!group) group = isSimplex ? "simplex" : "metro";

  const site = isSimplex ? null : siteFor(comment);
  channels.push({
    id,
    name: get("Name").slice(0, 10),
    rx: Math.round(rx * 1e6) / 1e6,
    tx: Math.round(tx * 1e6) / 1e6,
    txt, rxt,
    bw: get("Mode").toUpperCase().includes("N") ? 0 : 1,
    pwr: powerFromCsv(get("Power")),
    scan: get("Skip").toUpperCase() !== "S",
    note: comment.replace(/&amp;/g, "&").replace(/\s+/g, " ").trim(),
    site: site ? site[2] : null,
    lat:  site ? site[0] : null,
    lon:  site ? site[1] : null,
    mi:   site ? Math.round(haversineMi(QTH, [site[0], site[1]]) * 10) / 10 : null,
    brg:  site ? compassOf(bearingDeg(QTH, [site[0], site[1]])) : null
  });
  channels[channels.length - 1].group = group;
}

channels.sort((a, b) => a.id - b.id);

/* ---- summary ---- */
const GROUPS = {
  simplex: "Simplex",
  metro:   "Phoenix Metro",
  travel:  "Rim Country (travel)",
  gmrs:    "GMRS / FRS",
  sat:     "Satellite"
};
console.log(`parsed ${channels.length} channels from the master list\n`);
for (const [k, label] of Object.entries(GROUPS)) {
  const g = channels.filter(c => c.group === k);
  if (!g.length) { console.log(`  ${label.padEnd(22)} (none)`); continue; }
  const ids = g.map(c => c.id);
  console.log(`  ${label.padEnd(22)} ${String(g.length).padStart(3)} ch   ids ${Math.min(...ids)}-${Math.max(...ids)}`);
}
const dcs = channels.filter(c => c.txt?.dcs != null);
const noTone = channels.filter(c => Math.abs(c.tx - c.rx) > 1e-6 && !c.txt);
console.log(`\n  DCS channels          : ${dcs.map(c => `${c.id} ${c.name}`).join(", ") || "none"}`);
console.log(`  repeaters with NO tone: ${noTone.map(c => `${c.id} ${c.name}`).join(", ") || "none"}`);
const dupes = channels.map(c => c.id).filter((v, i, a) => a.indexOf(v) !== i);
console.log(`  duplicate ids         : ${dupes.join(", ") || "none"}`);

/* ---- site geocoding report ---- */
const repeaters = channels.filter(c => Math.abs(c.tx - c.rx) > 1e-6);
const located = repeaters.filter(c => c.site);
const unlocated = repeaters.filter(c => !c.site);
console.log(`\n  repeaters located     : ${located.length}/${repeaters.length}`);
if (unlocated.length)
  console.log(`  UNLOCATED             : ${unlocated.map(c => `${c.id} ${c.name} (${c.note.slice(0,30)})`).join("; ")}`);

const bySite = {};
located.forEach(c => (bySite[c.site] = bySite[c.site] || { n: 0, mi: c.mi, brg: c.brg }).n++);
console.log("\n  sites by distance from the QTH:");
Object.entries(bySite).sort((a, b) => a[1].mi - b[1].mi).forEach(([s, v]) =>
  console.log(`    ${String(v.mi).padStart(5)} mi ${v.brg.padEnd(3)}  ${String(v.n).padStart(2)} ch  ${s}`));

/* ---- emit ---- */
if (process.argv.includes("--write")) {
  const compact = c => {
    const t = x => x == null ? "0" : (x.dcs != null ? `{d:${x.dcs}}` : `{c:${x.ctcss}}`);
    const parts = [
      `i:${c.id}`, `n:${JSON.stringify(c.name)}`, `r:${c.rx}`,
      c.tx !== c.rx ? `t:${c.tx}` : null,
      c.txt ? `a:${t(c.txt)}` : null,
      c.rxt ? `b:${t(c.rxt)}` : null,
      c.bw !== 1 ? `w:${c.bw}` : null,
      c.pwr !== "high" ? `p:${JSON.stringify(c.pwr)}` : null,
      c.scan ? null : `s:0`,
      `g:${JSON.stringify(c.group)}`,
      c.site ? `k:${JSON.stringify(c.site)}` : null,
      c.lat != null ? `y:${c.lat}` : null,
      c.lon != null ? `x:${c.lon}` : null,
      c.note ? `m:${JSON.stringify(c.note)}` : null
    ].filter(Boolean);
    return "{" + parts.join(",") + "}";
  };
  const block =
    "/* ==LIBRARY-START== generated by build-library.mjs -- do not hand-edit */\n" +
    "const LIB_GROUPS = " + JSON.stringify(GROUPS) + ";\n" +
    "const LIBRARY = [\n" + channels.map(c => "  " + compact(c)).join(",\n") + "\n];\n" +
    "/* ==LIBRARY-END== */";

  let html = readFileSync(HTML, "utf8");
  const re = /\/\* ==LIBRARY-START==[\s\S]*?\/\* ==LIBRARY-END== \*\//;
  if (!re.test(html)) throw new Error("LIBRARY markers not found in vgc-programmer.html");
  html = html.replace(re, block);
  writeFileSync(HTML, html);
  console.log(`\nwrote LIBRARY block into vgc-programmer.html (${block.length} bytes)`);
}
