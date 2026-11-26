// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — épreuve de la LECTURE des classeurs et du DÉPLIAGE d'un planning.
 *
 * Deux défauts que seul un vrai classeur montre :
 *   - une date au format d'affichage « j-mmm » (21-janv) arrivait sans son
 *     année : la feuille était lue telle qu'affichée ;
 *   - un planning de contrôles (une ligne par matériel, deux colonnes par
 *     année, cellules fusionnées) n'était lisible ni « par ligne » ni « par
 *     colonne ».
 *
 * L'épreuve fabrique un classeur avec la bibliothèque livrée, le fait lire
 * par le VRAI lecteur (le worker), puis déplie le planning avec les VRAIES
 * fonctions de l'assistant, chargées sans navigateur.
 *
 *   node outils/epreuves/test-import-planning.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const racine = path.resolve(__dirname, '..', '..');
const XLSX = require(path.join(racine, 'js/vendor/xlsx/xlsx.full.min.js'));

let ok = 0, ko = 0;
function verifier(nom, cond, detail) {
  if (cond) { ok++; console.log('  ✅ ' + nom); } else { ko++; console.log('  ❌ ' + nom + (detail !== undefined ? ' — ' + JSON.stringify(detail) : '')); }
}

// ── Le classeur : deux sites, même forme ─────────────────────────────────
function feuille(site) {
  const d = (y, m, j) => ({ t: 'n', v: Math.round((Date.UTC(y, m - 1, j) - Date.UTC(1899, 11, 30)) / 86400000), z: 'd-mmm' });
  const aoa = [
    ['Planning des contrôles réglementaires de ' + site],
    [],
    ['Matériel concerné', 'périodicité', 2023, '', 2024, '', 2025, ''],
    ['', '', 'vérifié par', 'date', 'vérifié par', 'date', 'vérifié par', 'date'],
    ['Extincteurs', '1 fois/an', 'DESAUTEL', null, 'FIRE&CO', 'nov ', 'FIRE&CO', 'ok'],
    ['Baes', '1 fois/an', '', null, '', '16 et 17 janv', '', '13/04/2025 & 11/09/2025'],
    ['Ascenseur : - maintenance', '12 fois/an', 'ORONA', null, 'ORONA', 'pas fait', '', ''],
    ['', '', '', null, '', '', '', ''],
    ['      - Vérification générale périodique', '1 fois/an', 'BUREAU VERITAS', null, '', '', 'BUREAU VERITAS', ''],
  ];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  ws.D5 = d(2023, 1, 21);          // « 21-janv » à l'écran
  ws.D6 = d(2023, 2, 8);
  ws.D7 = d(2023, 3, 2);
  ws.D8 = d(2023, 4, 4);           // second passage de l'ascenseur (ligne de suite)
  ws.D9 = d(2023, 10, 30);
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 7 } },     // titre du document
    { s: { r: 4, c: 2 }, e: { r: 5, c: 2 } },     // un prestataire pour deux lignes
    { s: { r: 4, c: 4 }, e: { r: 5, c: 4 } },
    { s: { r: 6, c: 0 }, e: { r: 7, c: 0 } },     // le matériel sur ses deux lignes
  ];
  return ws;
}
const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, feuille('Bordeaux'), 'Bordeaux');
XLSX.utils.book_append_sheet(wb, feuille('Pau'), 'Pau');
const octets = XLSX.write(wb, { type: 'buffer', bookType: 'xlsx' });

// ── Lecture par le worker, tel quel ──────────────────────────────────────
console.log('\n─── lecture du classeur (worker) ───────────────────────────────');
let reponse = null;
{
  const self = { location: { search: '', href: 'http://larka.local/js/pages/biens-import.worker.js' }, postMessage: (m) => { reponse = m; } };
  const ctx = vm.createContext({ self, XLSX, TextDecoder, URL, Uint8Array, importScripts() {} });
  vm.runInContext(fs.readFileSync(path.join(racine, 'js/pages/biens-import.worker.js'), 'utf8'), ctx);
  self.onmessage({ data: { buf: octets, nom: 'planning.xlsx' } });
}
verifier('le worker répond', reponse && reponse.ok === true, reponse);
const lignes = reponse.feuilles[0][1];
verifier('« 21-janv » arrive avec son année (2023-01-21)', lignes[4][3] === '2023-01-21', lignes[4][3]);
verifier('les lignes gardent leur place (ligne 5 du fichier = indice 4)', lignes[4][0] === 'Extincteurs', lignes[4][0]);
verifier('la ligne vide du haut est conservée', Array.isArray(lignes[1]) && lignes[1].every((c) => c === ''), lignes[1]);
verifier('les zones fusionnées sont transmises', reponse.feuilles[0][2].length === 4, reponse.feuilles[0][2]);
verifier('l\'indentation des sous-lignes est conservée', /^\s{4,}-/.test(lignes[8][0]), lignes[8][0]);

// ── Dépliage avec les fonctions de l'assistant ───────────────────────────
console.log('\n─── dépliage du planning ───────────────────────────────────────');
const ctx = {
  console, setTimeout, localStorage: { getItem: () => null, setItem() {} },
  document: { getElementById: () => null, addEventListener() {}, querySelector: () => null, querySelectorAll: () => [] },
  lk: () => '', _esc: (s) => String(s), toast() {}, apiRequest() {},
};
ctx.window = ctx;
vm.createContext(ctx);
for (const f of ['js/pages/biens-import.js', 'js/pages/interventions-import.js']) {
  vm.runInContext(fs.readFileSync(path.join(racine, f), 'utf8'), ctx, { filename: f });
}
ctx.__lecture = reponse;
vm.runInContext('_II.workbook = _biValiderClasseur(__lecture); _II.sheetNames = _II.workbook.SheetNames;', ctx);

const plan = vm.runInContext('_iiPlanningDetecter(_II.workbook.feuilles.get("Bordeaux"))', ctx);
verifier('la feuille est reconnue comme un planning', !!plan);
verifier('3 années, 2 colonnes par année', plan && plan.groupes.length === 3 && plan.pas === 2, plan);
verifier('la colonne « date » est trouvée', plan && plan.offDate === 1, plan && plan.offDate);
verifier('un tableau ordinaire n\'est pas un planning',
  vm.runInContext('_iiPlanningDetecter([["N° OT","Description","Date"],["1","x","2024-01-01"]])', ctx) === null);

const d = JSON.parse(vm.runInContext('_II.planningToutes = true; JSON.stringify(_iiPlanningDeplier(_II.sheetNames))', ctx));
const titres = d.rows[0];
const col = (n) => titres.indexOf(n);
// « Matériel — Feuille » : la description, et la feuille (le site) dans la colonne Lieu.
const de = (desc) => { const [m, f] = desc.split(/ — (?=[^—]+$)/); return d.rows.slice(1).filter((r) => r[col('Description')] === m && r[col('Lieu')] === f); };
verifier('titres connus de l\'assistant', ['Description', 'vérifié par', 'Date prévue', 'Réalisé le', 'Type', 'Commentaire'].every((t) => titres.includes(t)), titres);
verifier('les deux feuilles sont dépliées', d.feuilles.join(',') === 'Bordeaux,Pau', d.feuilles);
verifier('le nom de la feuille va dans « Lieu », plus dans la description', col('Lieu') > 0
  && d.rows.slice(1).every((r) => ['Bordeaux', 'Pau'].includes(r[col('Lieu')]) && !/ — (Bordeaux|Pau)$/.test(r[col('Description')])), d.rows.slice(1, 4));

const ext = de('Extincteurs — Bordeaux');
verifier('Extincteurs : 2 passages datés (2023, nov 2024) ; « ok » n\'en est pas un', ext.length === 2, ext);
verifier('date Excel → AAAA-MM-JJ', ext[0] && ext[0][col('Date prévue')] === '2023-01-21', ext[0]);
verifier('« nov » + colonne 2024 → 1er novembre 2024, signalé', ext[1] && ext[1][col('Date prévue')] === '2024-11-01' && /jour non précisé/.test(ext[1][col('Commentaire')]), ext[1]);
verifier('la périodicité passe en commentaire', ext[0] && /Périodicité : 1 fois\/an/.test(ext[0][col('Commentaire')]), ext[0]);

const baes = de('Baes — Bordeaux');
verifier('prestataire d\'une cellule fusionnée recopié sur la ligne suivante', baes[0] && baes[0][col('vérifié par')] === 'DESAUTEL', baes[0]);
verifier('« 16 et 17 janv » → le 16', baes.some((r) => r[col('Date prévue')] === '2024-01-16'), baes);
verifier('« 13/04/2025 & 11/09/2025 » → deux passages', baes.filter((r) => r[col('Date prévue')].startsWith('2025-')).length === 2, baes);

const asc = de('Ascenseur : maintenance — Bordeaux');
verifier('ligne de suite (matériel fusionné) : second passage de l\'ascenseur', asc.length === 2, asc);
verifier('maintenance → Préventive', asc[0] && asc[0][col('Type')] === 'Préventive', asc[0]);
const vgp = de('Ascenseur — Vérification générale périodique — Bordeaux');
verifier('sous-ligne indentée rattachée à son matériel', vgp.length === 1 && vgp[0][col('Type')] === 'Contrôle réglementaire', d.rows.map((r) => r[0]));

verifier('cellules sans date listées, pas importées (ok, pas fait, prestataire seul)',
  d.ignorees.some((x) => x.valeur === 'ok') && d.ignorees.some((x) => x.valeur === 'pas fait') && d.ignorees.some((x) => /date vide/.test(x.valeur)), d.ignorees);
verifier('références d\'origine : feuille!cellule', d.refs[1] === 'Bordeaux!D5', d.refs.slice(0, 3));
verifier('passé → « Réalisé le » ; à venir → seulement « Date prévue »',
  ext[0][col('Réalisé le')] === '2023-01-21');

console.log('\n─── lecture des dates écrites à la main ────────────────────────');
const dates = (t, a) => JSON.parse(vm.runInContext('JSON.stringify(_iiPlanningDates(' + JSON.stringify(t) + ',' + a + '))', ctx)).dates;
verifier('« 27 aôut » (faute d\'accent)', dates('27 aôut', 2024)[0] === '2024-08-27');
verifier('« fiche frigo 5juin »', dates('fiche frigo 5juin', 2024)[0] === '2024-06-05');
verifier('« maintenance » n\'est pas « mai »', dates('maintenance', 2024).length === 0);
verifier('« reporté en 2025 » : aucune date', dates('reporté en 2025 avec nouveau titulaire', 2024).length === 0);
verifier('« contrat triennal à compter du 01/01/20 » : aucune date', dates('contrat triennal à compter du 01/01/20', 2020).length === 0);
verifier('« 12 fév 2022 en retard » : l\'année écrite l\'emporte', dates('12 fév 2022 en retard', 2021)[0] === '2022-02-12');

console.log('\n' + (ko ? '❌ ' + ko + ' échec(s), ' : '✅ ') + ok + ' vérification(s) réussie(s)');
process.exit(ko ? 1 : 0);
