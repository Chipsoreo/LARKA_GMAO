#!/usr/bin/env node
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * « window.X » vers une liaison déclarée en const/let : toujours undefined.
 *
 * En JavaScript, « const App = {…} » au premier niveau d'un script classique
 * crée une liaison dans l'environnement lexical global — mais PAS une propriété
 * de window. Écrire « window.App » rend donc undefined, sans erreur : le test
 * échoue en silence, et la fonctionnalité disparaît sans que rien ne l'explique.
 *
 * C'est ainsi que l'écran Apparence a annoncé « couche extensions
 * indisponible » alors que la couche était chargée, et que le bouton de dépôt
 * n'apparaissait à personne.
 *
 * Le piège est documenté dans js/extensions.js depuis longtemps — et je l'ai
 * malgré tout reproduit deux fois. D'où ce contrôle : la vigilance ne suffit
 * pas, il faut une machine.
 *
 *   node outils/epreuves/verif-globales.js
 */
const fs = require('fs');
const path = require('path');

const racine = path.resolve(__dirname, '../..');
const dossiers = ['js', 'js/pages'];

const globales = new Set();
for (const f of fs.readdirSync(path.join(racine, 'js'))) {
  if (!f.endsWith('.js')) continue;
  const src = fs.readFileSync(path.join(racine, 'js', f), 'utf8');
  for (const m of src.matchAll(/^(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=/gm)) {
    globales.add(m[1]);
  }
}

const fautifs = [];
for (const d of dossiers) {
  const dir = path.join(racine, d);
  if (!fs.existsSync(dir)) continue;
  for (const f of fs.readdirSync(dir)) {
    if (!f.endsWith('.js')) continue;
    const lignes = fs.readFileSync(path.join(dir, f), 'utf8').split('\n');
    lignes.forEach((l, i) => {
      const t = l.trim();
      if (t.startsWith('//') || t.startsWith('*')) return;
      for (const m of l.matchAll(/window\.([A-Za-z_$][\w$]*)/g)) {
        // Une AFFECTATION est légitime : elle crée la propriété.
        if (new RegExp('window\\.' + m[1] + '\\s*=').test(l)) continue;
        if (globales.has(m[1])) fautifs.push(`${d}/${f}:${i + 1} — window.${m[1]}`);
      }
    });
  }
}

if (fautifs.length === 0) {
  console.log(`  ✅ aucun accès « window.X » vers une liaison const/let `
            + `(${globales.size} liaisons examinées)`);
  process.exit(0);
}
console.log('  ❌ accès toujours undefined :');
for (const x of fautifs) console.log('     ' + x);
console.log('     → utilisez « typeof X !== \'undefined\' && X »');
process.exit(1);
