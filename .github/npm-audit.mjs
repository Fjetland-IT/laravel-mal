// npm audit med navngitte unntak. Kjøres av deploy.yml etter «npm ci».
//
// Feiler (kode 1) på alle varsler med alvor high eller critical, unntatt dem som står i UNNTAK.
// Et unntak gjelder ett bestemt varsel (GHSA-id), ikke en pakke: nye varsler i samme pakke stopper
// bygget som før. Fjern linja når varselet er rettet og pakken oppgradert.
//
//   node .github/npm-audit.mjs                 kjører «npm audit --json»
//   node .github/npm-audit.mjs rapport.json    leser en lagret rapport (til testing)

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const UNNTAK = {
    // picomatch <= 4.0.7, ReDoS i extglob-mønstre. Ingen rettet versjon per 17. nov 2026.
    // Bare byggeavhengighet (Vite, tinyglobby, fdir), og mønstrene kommer fra egen konfigurasjon.
    'GHSA-fc5q-39xc-vjpf': 'picomatch ReDoS, ingen rettet versjon (lagt inn 2026-11-17)',
};

const ALVORLIG = new Set(['high', 'critical']);

function hentRapport() {
    if (process.argv[2]) return readFileSync(process.argv[2], 'utf8');
    try {
        return execFileSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    } catch (feil) {
        // npm audit avslutter med kode 1 når det finnes funn; rapporten står likevel på stdout.
        if (feil.stdout) return feil.stdout;
        throw feil;
    }
}

let rapport;
try {
    rapport = JSON.parse(hentRapport());
} catch (feil) {
    console.error(`npm-audit: fikk ikke lest rapporten fra npm audit: ${feil.message}`);
    process.exit(1);
}
if (rapport.error || typeof rapport.vulnerabilities !== 'object' || rapport.vulnerabilities === null) {
    console.error('npm-audit: npm audit ga ingen gyldig rapport:', JSON.stringify(rapport.error ?? rapport).slice(0, 500));
    process.exit(1);
}

// Hvert varsel står som et objekt i «via» hos pakken det gjelder; pakker som bare arver funnet har strenger.
const varsler = new Map();
for (const [pakke, funn] of Object.entries(rapport.vulnerabilities)) {
    for (const via of funn.via ?? []) {
        if (typeof via !== 'object' || via === null) continue;
        const id = String(via.url ?? '').split('/').pop() || `ukjent-${via.source ?? pakke}`;
        varsler.set(`${id} ${via.name ?? pakke}`, { id, pakke: via.name ?? pakke, alvor: via.severity, tittel: via.title, url: via.url });
    }
}

const stopper = [];
const unntatt = [];
for (const v of varsler.values()) {
    if (!ALVORLIG.has(v.alvor)) continue;
    (Object.hasOwn(UNNTAK, v.id) ? unntatt : stopper).push(v);
}

for (const v of unntatt) console.log(`npm-audit: UNNTATT ${v.id} (${v.pakke}, ${v.alvor}): ${UNNTAK[v.id]}`);
for (const v of stopper) console.error(`npm-audit: STOPPER ${v.id} (${v.pakke}, ${v.alvor}): ${v.tittel} ${v.url}`);

const brukt = new Set(unntatt.map((v) => v.id));
for (const id of Object.keys(UNNTAK)) {
    if (!brukt.has(id)) console.log(`npm-audit: unntaket ${id} treffer ikke lenger og kan fjernes`);
}

if (stopper.length > 0) {
    console.error(`npm-audit: ${stopper.length} alvorlige varsler uten unntak.`);
    process.exit(1);
}
console.log(`npm-audit: ingen alvorlige varsler utenom ${unntatt.length} unntatt.`);
