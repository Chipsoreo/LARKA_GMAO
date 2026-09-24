/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Page « Journal » — consultation du journal applicatif
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Deux vues :
 *    • Synthèse : volumétrie par niveau, canaux les plus actifs, erreurs les
 *      plus fréquentes, routes les plus lentes, utilisateurs les plus actifs.
 *    • Détail   : liste filtrable, avec le diff avant/après des modifications
 *      et la possibilité de reconstituer une requête complète via son
 *      identifiant de corrélation.
 *
 *  Réservée aux Admin et Gestionnaires (le journal contient des IP, des
 *  identifiants de connexion et le détail des modifications).
 */

let _jrFiltres = { niveauMin: '', canal: '', login: '', q: '', depuis: '', jusqua: '', codeMin: '' };
let _jrOffset  = 0;
const _JR_PAR_PAGE = 100;

// Couleur associée à chaque niveau, pour repérer l'anormal d'un coup d'œil.
const _JR_COULEURS = {
  debug:    { fond: '#f1f5f9', texte: '#64748b', icone: '·'  },
  info:     { fond: '#eff6ff', texte: '#2563eb', icone: 'i'  },
  audit:    { fond: '#f0fdf4', texte: '#16a34a', icone: '✎'  },
  warning:  { fond: '#fffbeb', texte: '#d97706', icone: '!'  },
  error:    { fond: '#fef2f2', texte: '#dc2626', icone: '✕'  },
  critique: { fond: '#7f1d1d', texte: '#ffffff', icone: '‼'  },
};

function _jrBadge(niveau) {
  const c = _JR_COULEURS[niveau] || _JR_COULEURS.info;
  return `<span style="display:inline-flex;align-items:center;gap:4px;background:${c.fond};color:${c.texte};
    padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px">
    ${c.icone} ${escHtml(niveau || '')}</span>`;
}

async function renderJournal() {
  const c = document.getElementById('mainContent');
  document.getElementById('topbarActions').innerHTML = '';
  showLoading(c);

  // Module désactivé au niveau tenant par le SuperAdmin : masquer l'entrée de
  // menu ne suffit pas, l'URL reste atteignable directement.
  if ((App._disabledModules || []).includes('journal')) {
    c.innerHTML = `<div class="card" style="padding:24px;text-align:center;color:var(--gray-text)">
      Le journal d'activité est désactivé pour cette organisation.</div>`;
    return;
  }

  // ⚠️ CETTE GARDE NE CONNAISSAIT QUE LES RÔLES DE TENANT.
  // J'avais ouvert la route serveur au super administrateur en oubliant qu'un
  // second contrôle, côté client, refusait l'écran avant même de l'appeler.
  // Résultat : le message d'interdiction s'affichait à celui à qui l'on venait
  // de confier le journal. Deux gardes pour une même règle, et une seule
  // corrigée — c'est le risque quand la règle est écrite à deux endroits.
  // Le mode super administrateur est déclenché par « ?superadmin » dans l'URL
  // et n'expose aucun état global : c'est donc l'URL qui fait foi. Le serveur
  // revérifie de toute façon la session — ce test ne fait que choisir quoi
  // afficher, il n'accorde aucun droit.
  const estSuperAdmin = window.location.search.includes('superadmin');
  if (!estSuperAdmin && !['Admin', 'Gestionnaire'].includes(App.currentUser?.Role)) {
    c.innerHTML = `<div class="card" style="padding:24px;text-align:center;color:var(--gray-text)">
      Le journal est réservé aux administrateurs et gestionnaires.</div>`;
    return;
  }

  c.innerHTML = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div id="jrStats"></div>
      <div class="card" style="padding:14px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <label class="form-label" style="font-size:11px">Niveau minimum</label>
            <select class="form-control" id="jrNiveau" style="min-width:130px" onchange="_jrAppliquer()">
              <option value="">Tous</option>
              <option value="info">info et +</option>
              <option value="audit">audit et +</option>
              <option value="warning">warning et +</option>
              <option value="error">erreurs seules</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11px">Canal</label>
            <select class="form-control" id="jrCanal" style="min-width:120px" onchange="_jrAppliquer()">
              <option value="">Tous</option>
              <option value="api">api (requêtes)</option>
              <option value="navigation">navigation (pages)</option>
              <option value="interaction">interaction (clics, saisies)</option>
              <option value="donnees">donnees</option>
              <option value="audit">audit</option>
              <option value="php">php</option>
              <option value="client">client (JS)</option>
              <option value="securite">securite</option>
              <option value="systeme">systeme</option>
              <option value="journal">journal</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11px">Utilisateur</label>
            <input class="form-control" id="jrLogin" placeholder="login" style="width:130px" onkeydown="if(event.key==='Enter')_jrAppliquer()">
          </div>
          <div style="flex:1;min-width:180px">
            <label class="form-label" style="font-size:11px">Recherche</label>
            <input class="form-control" id="jrQ" placeholder="message, contexte, valeur modifiée…" onkeydown="if(event.key==='Enter')_jrAppliquer()">
          </div>
          <div>
            <label class="form-label" style="font-size:11px">Depuis</label>
            <input class="form-control" type="date" id="jrDepuis" style="width:150px" onchange="_jrAppliquer()">
          </div>
          <button class="btn btn-primary btn-sm" onclick="_jrAppliquer()">🔍 Filtrer</button>
          <button class="btn btn-ghost btn-sm" onclick="_jrReset()">↺</button>
          ${App.currentUser?.Role === 'Admin' ? `
            <a class="btn btn-ghost btn-sm" style="text-decoration:none"
               href="api/index.php?action=journal_export&depuis=${encodeURIComponent(new Date(Date.now()-7*864e5).toISOString().slice(0,10))}">⬇️ CSV</a>
            <button class="btn btn-ghost btn-sm" onclick="_jrPurger()">🗑️ Purger</button>` : ''}
        </div>
      </div>
      <div id="jrListe"></div>
    </div>`;

  await Promise.all([_jrChargerStats(), _jrCharger()]);
}

async function _jrChargerStats() {
  const el = document.getElementById('jrStats');
  if (!el) return;
  try {
    const s = await JournalApi.stats(7);
    const tuile = (titre, contenu) => `<div class="card" style="padding:12px;flex:1;min-width:190px">
      <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">${titre}</div>
      ${contenu || '<div style="font-size:12px;color:var(--gray-text)">—</div>'}</div>`;

    const ligne = (g, d) => `<div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:2px 0">
      <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${g}</span><strong>${d}</strong></div>`;

    el.innerHTML = `<div style="display:flex;gap:12px;flex-wrap:wrap">
      ${tuile('Sur 7 jours', (s.parNiveau || []).map(r => ligne(_jrBadge(r.Niveau), r.Total)).join(''))}
      ${tuile('Canaux', (s.parCanal || []).slice(0, 6).map(r => ligne(escHtml(r.Canal || '—'), r.Total)).join(''))}
      ${tuile('Erreurs fréquentes', (s.topErreurs || []).slice(0, 5).map(r => ligne(escHtml((r.Message || '').slice(0, 42)), r.Total)).join(''))}
      ${tuile('Routes lentes (>2s)', (s.lentes || []).slice(0, 5).map(r => ligne(escHtml(r.Route || '—'), r.PireMs + ' ms')).join(''))}
      ${tuile('Utilisateurs actifs', (s.actifs || []).slice(0, 5).map(r => ligne(escHtml(r.Login || '—'), r.Total)).join(''))}
    </div>`;
  } catch (e) {
    el.innerHTML = `<div class="card" style="padding:12px;font-size:12px;color:var(--gray-text)">Statistiques indisponibles.</div>`;
  }
}

async function _jrCharger() {
  const el = document.getElementById('jrListe');
  if (!el) return;
  el.innerHTML = `<div class="card" style="padding:20px;text-align:center;color:var(--gray-text)">⏳ Chargement…</div>`;

  let r;
  try {
    r = await JournalApi.liste({ ..._jrFiltres, limit: _JR_PAR_PAGE, offset: _jrOffset });
  } catch (e) {
    el.innerHTML = `<div class="card" style="padding:20px;color:var(--red)">Erreur : ${escHtml(e.message || '')}</div>`;
    return;
  }

  const items = r.items || [];
  if (!items.length) {
    el.innerHTML = `<div class="card" style="padding:24px;text-align:center;color:var(--gray-text)">
      Aucune entrée ne correspond à ces critères.</div>`;
    return;
  }

  el.innerHTML = `
    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:10px 14px;border-bottom:1px solid var(--gray-border);font-size:12px;color:var(--gray-text)">
        ${r.total} entrée(s) — affichage ${_jrOffset + 1} à ${Math.min(_jrOffset + items.length, r.total)}
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr style="background:var(--gray-bg);text-align:left">
            <th style="padding:7px 10px;white-space:nowrap">Date</th>
            <th style="padding:7px 10px">Niveau</th>
            <th style="padding:7px 10px">Canal</th>
            <th style="padding:7px 10px">Message</th>
            <th style="padding:7px 10px">Utilisateur</th>
            <th style="padding:7px 10px;text-align:right">Durée</th>
            <th style="padding:7px 10px"></th>
          </tr></thead>
          <tbody>
            ${items.map(e => `
              <tr style="border-top:1px solid var(--gray-border)">
                <td style="padding:6px 10px;white-space:nowrap;font-family:monospace;font-size:11px">${escHtml((e.DateHeure || '').slice(5, 19))}</td>
                <td style="padding:6px 10px">${_jrBadge(e.Niveau)}</td>
                <td style="padding:6px 10px;color:var(--gray-text)">${escHtml(e.Canal || '')}</td>
                <td style="padding:6px 10px">${escHtml(e.Message || '')}
                  ${e.CodeHttp ? `<span style="font-size:10px;color:${e.CodeHttp >= 400 ? 'var(--red)' : 'var(--gray-text)'};margin-left:6px">HTTP ${e.CodeHttp}</span>` : ''}</td>
                <td style="padding:6px 10px">${escHtml(e.Login || '—')}</td>
                <td style="padding:6px 10px;text-align:right;font-family:monospace;font-size:11px;color:${(e.DureeMs || 0) > 2000 ? 'var(--orange)' : 'var(--gray-text)'}">${e.DureeMs != null ? e.DureeMs + ' ms' : ''}</td>
                <td style="padding:6px 10px;text-align:right"><button class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:11px" onclick="_jrDetail(${e.Id})">Détail</button></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <div style="padding:10px 14px;display:flex;gap:8px;justify-content:center;border-top:1px solid var(--gray-border)">
        <button class="btn btn-ghost btn-sm" ${_jrOffset === 0 ? 'disabled' : ''} onclick="_jrPage(-1)">← Précédent</button>
        <button class="btn btn-ghost btn-sm" ${_jrOffset + items.length >= r.total ? 'disabled' : ''} onclick="_jrPage(1)">Suivant →</button>
      </div>
    </div>`;

  window._jrItems = items;
}

// Détail d'une entrée : contexte complet, diff avant/après, entrées corrélées.
async function _jrDetail(id) {
  const e = (window._jrItems || []).find(x => x.Id === id);
  if (!e) return;

  const bloc = (titre, contenu) => contenu
    ? `<div style="margin-top:12px">
         <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;margin-bottom:4px">${titre}</div>
         <pre style="background:var(--gray-bg);padding:10px;border-radius:6px;font-size:11px;overflow-x:auto;margin:0;white-space:pre-wrap;word-break:break-word">${escHtml(contenu)}</pre>
       </div>` : '';

  const joli = (s) => { try { return JSON.stringify(JSON.parse(s), null, 2); } catch (_) { return s; } };

  const champ = (l, v) => v ? `<div style="display:flex;gap:8px;padding:2px 0;font-size:12px">
    <span style="color:var(--gray-text);min-width:105px">${l}</span><span style="font-weight:500;word-break:break-all">${escHtml(String(v))}</span></div>` : '';

  let correles = '';
  if (e.RequestId) {
    try {
      const liste = await JournalApi.detail(e.RequestId);
      if (liste.length > 1) {
        correles = `<div style="margin-top:12px">
          <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;margin-bottom:4px">
            Même requête (${liste.length} entrées)</div>
          ${liste.map(x => `<div style="display:flex;gap:8px;font-size:11px;padding:3px 0;border-bottom:1px solid var(--gray-border)">
              ${_jrBadge(x.Niveau)}<span style="color:var(--gray-text)">${escHtml(x.Canal || '')}</span>
              <span style="flex:1">${escHtml((x.Message || '').slice(0, 70))}</span></div>`).join('')}
        </div>`;
      }
    } catch (_) { /* corrélation optionnelle */ }
  }

  openModal(`${_jrBadge(e.Niveau)} ${escHtml((e.Message || '').slice(0, 60))}`, `
    <div style="max-height:66vh;overflow-y:auto">
      ${champ('Date', e.DateHeure)}
      ${champ('Canal', e.Canal)}
      ${champ('Action', e.Action)}
      ${champ('Utilisateur', (e.Login || '—') + (e.Role ? ' (' + e.Role + ')' : ''))}
      ${champ('Adresse IP', e.Ip)}
      ${champ('Requête', (e.Methode || '') + ' ' + (e.Route || ''))}
      ${champ('Code HTTP', e.CodeHttp)}
      ${champ('Durée', e.DureeMs != null ? e.DureeMs + ' ms' : '')}
      ${champ('Entité', e.EntiteType ? e.EntiteType + (e.EntiteId ? ' #' + e.EntiteId : '') : '')}
      ${champ('Corrélation', e.RequestId)}
      ${champ('Navigateur', e.UserAgent)}
      ${bloc('Avant', e.Avant ? joli(e.Avant) : '')}
      ${bloc('Après', e.Apres ? joli(e.Apres) : '')}
      ${bloc('Contexte', e.Contexte ? joli(e.Contexte) : '')}
      ${correles}
    </div>`, null, null, 'Fermer');
}

function _jrAppliquer() {
  _jrFiltres = {
    niveauMin: document.getElementById('jrNiveau')?.value || '',
    canal:     document.getElementById('jrCanal')?.value  || '',
    login:     document.getElementById('jrLogin')?.value.trim() || '',
    q:         document.getElementById('jrQ')?.value.trim()     || '',
    depuis:    document.getElementById('jrDepuis')?.value ? document.getElementById('jrDepuis').value + ' 00:00:00' : '',
    jusqua:    '',
    codeMin:   '',
  };
  _jrOffset = 0;
  _jrCharger();
}

function _jrReset() {
  ['jrNiveau', 'jrCanal', 'jrLogin', 'jrQ', 'jrDepuis'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  _jrAppliquer();
}

function _jrPage(sens) {
  _jrOffset = Math.max(0, _jrOffset + sens * _JR_PAR_PAGE);
  _jrCharger();
}

async function _jrPurger() {
  const jours = prompt('Supprimer les entrées de plus de combien de jours ?\n(0 = tout purger)', '30');
  if (jours === null) return;
  const n = parseInt(jours);
  if (isNaN(n) || n < 0) { toast('Valeur invalide.', 'error'); return; }
  if (!confirm(n === 0
      ? 'Purger LA TOTALITÉ du journal ? Cette action est irréversible.'
      : `Supprimer les entrées de plus de ${n} jours ?`)) return;
  try {
    const r = await JournalApi.purge(n);
    toast(`${r.supprimees} entrée(s) supprimée(s).`, 'success');
    _jrOffset = 0;
    await Promise.all([_jrChargerStats(), _jrCharger()]);
  } catch (e) {
    toast(e.message, 'error');
  }
}
