/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Page : Modules complémentaires (administration)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * L'écran le plus sensible de l'application après la configuration de la base :
 * installer une extension, c'est exécuter du code tiers sur le serveur.
 *
 * PARTI PRIS D'INTERFACE
 * Tout est dessiné pour empêcher l'installation machinale :
 *   - aucune coche pré-remplie : l'administrateur coche chaque permission ;
 *   - les permissions sont triées du plus dangereux au plus anodin, en français,
 *     avec la phrase qui explique la conséquence concrète ;
 *   - le bouton reste désactivé tant que tout n'est pas coché ;
 *   - l'écran ne dit JAMAIS « analyse réussie ». Il dit ce que l'analyse a vu,
 *     et il dit qu'elle ne certifie rien. Un badge vert produirait exactement
 *     la fausse confiance qu'on cherche à éviter.
 *
 * POINT D'ENTRÉE : renderExtensions()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const ExtState = {
  inventaire: [],
  actif: false,
  isolationDispo: false,
  examen: null,
  acceptees: new Set(),
  paquets: [],
  jeton: null,
};

const EXT_RISQUE = {
  faible:   { couleur: '#16a34a', fond: '#f0fdf4', libelle: 'Faible' },
  moyen:    { couleur: '#ca8a04', fond: '#fefce8', libelle: 'Moyen' },
  eleve:    { couleur: '#ea580c', fond: '#fff7ed', libelle: 'Élevé' },
  critique: { couleur: '#b91c1c', fond: '#fef2f2', libelle: 'Critique' },
};

function _extEsc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── Rendu principal ──────────────────────────────────────────────────────────

async function renderExtensions() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const rep = await apiRequest('ext_inventaire', 'GET');
    const d = rep?.data ?? rep;
    ExtState.inventaire = d.extensions || [];
    ExtState.actif = !!d.actif;
    ExtState.isolationDispo = !!d.isolation_disponible;
    ExtState.paquets = d.paquets_disponibles || [];
    ExtState.zipDispo = d.zip_disponible !== false;
    c.innerHTML = _extPageHtml();
  } catch (e) {
    c.innerHTML = errorHtml(e.message);
  }
}

function _extPageHtml() {
  if (!ExtState.actif) {
    return `<div class="card"><div class="card-body">
      <h3 style="margin-top:0">Modules complémentaires — désactivés</h3>
      <p>La couche est inerte sur cette installation : aucun dossier n'est ouvert,
      aucun code tiers n'est découvert ni chargé.</p>

      <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;
        padding:12px 14px;margin:14px 0;font-size:13px;line-height:1.6">
        L'activer ne fait qu'<strong>ouvrir la possibilité</strong> d'installer des modules :
        rien ne s'exécutera tant que vous n'aurez pas importé un paquet, accepté ses
        permissions une par une, puis affecté le module à un client.
      </div>

      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="extActiverCouche(true)">
          Activer les modules complémentaires</button>
        <span style="font-size:12px;color:var(--gray-text)">
          réversible à tout moment</span>
      </div>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;font-size:13px;color:var(--gray-text)">
          Activer à la main dans <code>config.json</code></summary>
        <pre style="background:var(--gray-bg);padding:12px;border-radius:6px;
          font-size:12px;margin-top:8px">"extensions": {
  "actif": true
}</pre>
        <p style="font-size:12px;color:var(--gray-text)">
          À placer <strong>avant l'accolade fermante</strong> du fichier, avec une virgule
          après la section précédente. Depuis cette version, <code>start.sh</code> et
          <code>deploy/install.sh</code> écrivent la section d'office (inactive) : les
          installations neuves n'ont plus rien à coller.</p>
      </details>

      <p style="font-size:12px;color:var(--gray-text);margin-top:14px">
        Chaque module annonce, avant installation, les tables qu'il créera et les
        écrans qu'il ajoutera.</p>
    </div></div>`;
  }

  return `
    ${_extBandeauZip()}
    ${_extZoneDepot()}
    <div class="card"><div class="card-body">
      <h3 style="margin-top:0">Modules installés</h3>
      ${ExtState.inventaire.length ? _extTableau() :
        `<p style="color:var(--gray-text)">Aucun module installé pour l'instant.</p>`}
    </div></div>
    <div id="extZoneExamen"></div>`;
}

/**
 * Bandeau d'alerte quand l'extension PHP « zip » manque.
 *
 * Affiché en tête, avec la commande exacte : sans elle, aucun paquet ne peut
 * être ouvert, et l'erreur ne se découvrait qu'après avoir glissé un fichier —
 * moment où l'on soupçonne le fichier plutôt que le serveur.
 */
function _extBandeauZip() {
  if (ExtState.zipDispo !== false) return '';
  return `<div style="background:#fef2f2;border:1px solid #b91c1c;border-radius:6px;
    padding:14px 16px;margin-bottom:16px">
    <strong style="color:#b91c1c">Extension PHP « zip » absente</strong>
    <p style="margin:6px 0;font-size:13px;line-height:1.6">
      Les paquets <code>.larka</code> sont des archives : sans cette extension, aucun module
      ne peut être ouvert ni installé. Les modules déjà installés continuent de fonctionner.</p>
    <pre style="background:var(--gray-bg);padding:10px;border-radius:6px;font-size:12px;
      margin:8px 0 4px">sudo apt install php8.3-zip     # adaptez la version : php -v
sudo systemctl restart php8.3-fpm
# serveur de développement : il suffit de relancer ./start.sh</pre>
    <div style="font-size:12px;color:var(--gray-text)">
      Vérification : <code>php -m | grep zip</code></div>
  </div>`;
}

/**
 * Zone de dépôt : glisser un .larka dessus, ou cliquer pour parcourir.
 *
 * Elle liste aussi les paquets DÉJÀ posés sur le disque et non installés —
 * dont l'exemple livré avec Larka. Sans cette liste, un fichier déposé à la
 * main dans extensions/ reste invisible depuis l'interface, puisque le code
 * installé vit désormais sous data/extensions/code/.
 */
function _extZoneDepot() {
  const dispo = (ExtState.paquets || []).filter(p => !p.deja_installe);

  const liste = dispo.length ? `
    <div style="margin-top:14px">
      <div style="font-size:13px;font-weight:600;margin-bottom:6px">
        Paquets présents sur le serveur</div>
      ${dispo.map(p => p.valide ? `
        <div style="display:flex;align-items:center;gap:12px;padding:9px 12px;
          border:1px solid var(--gray-border);border-radius:6px;margin-bottom:6px">
          <span style="flex:1">
            <strong>${_extEsc(p.nom)}</strong>
            <span style="color:var(--gray-text);font-size:12px">v${_extEsc(p.version)} ·
              ${_extEsc(p.auteur)}</span>
            <div style="font-size:11px;color:var(--gray-text)">
              <code>${_extEsc(p.fichier)}</code></div>
          </span>
          <button class="btn btn-sm btn-primary"
            onclick="extImporterLocal('${_extEsc(p.fichier)}')">Examiner</button>
        </div>` : `
        <div style="padding:9px 12px;border:1px solid #b91c1c;border-radius:6px;
          margin-bottom:6px;font-size:12px">
          <code>${_extEsc(p.fichier)}</code> — refusé : ${_extEsc(p.erreur)}
        </div>`).join('')}
    </div>` : '';

  if (ExtState.zipDispo === false) return '';

  return `<div class="card" style="margin-bottom:16px"><div class="card-body">
    <div id="extDropZone"
      ondragover="extDragSurvol(event, true)"
      ondragleave="extDragSurvol(event, false)"
      ondrop="extDrop(event)"
      onclick="document.getElementById('extFichier').click()"
      style="border:2px dashed var(--gray-border);border-radius:10px;padding:26px;
        text-align:center;cursor:pointer;transition:all .15s;background:var(--gray-bg)">
      <div style="font-size:30px;line-height:1">🧩</div>
      <div style="font-weight:600;margin-top:6px">
        Glissez un paquet <code>.larka</code> ici</div>
      <div style="font-size:12px;color:var(--gray-text);margin-top:3px">
        ou cliquez pour parcourir · rien ne s'installe avant votre accord</div>
    </div>
    <input type="file" id="extFichier" accept=".larka" style="display:none"
      onchange="extFichierChoisi(this)">
    ${liste}
  </div></div>`;
}

function extDragSurvol(ev, dedans) {
  ev.preventDefault();
  const z = document.getElementById('extDropZone');
  if (!z) return;
  z.style.borderColor = dedans ? 'var(--navy)' : 'var(--gray-border)';
  z.style.background  = dedans ? '#eef2ff' : 'var(--gray-bg)';
}

function extDrop(ev) {
  ev.preventDefault();
  extDragSurvol(ev, false);
  const f = ev.dataTransfer?.files?.[0];
  if (f) extTeleverserPaquet(f);
}

function extFichierChoisi(input) {
  const f = input.files?.[0];
  input.value = '';
  if (f) extTeleverserPaquet(f);
}

/** Téléverse un paquet et affiche son rapport d'examen. Rien n'est installé. */
async function extTeleverserPaquet(fichier) {
  if (!fichier.name.toLowerCase().endsWith('.larka')) {
    toast('Seuls les paquets .larka sont acceptés.', 'error');
    return;
  }
  const zone = document.getElementById('extZoneExamen');
  zone.innerHTML = '<div class="card"><div class="card-body">Vérification du paquet…</div></div>';
  try {
    const fd = new FormData();
    fd.append('paquet', fichier);
    const res = await fetch('api/index.php?action=ext_televerser', {
      method: 'POST', credentials: 'include', body: fd });
    const j = await res.json();
    if (!j.success) throw new Error(j.error || 'Paquet refusé.');
    _extAfficherExamen(j.data);
  } catch (e) {
    zone.innerHTML = `<div class="card"><div class="card-body"
      style="border-left:4px solid #b91c1c">
      <h3 style="margin-top:0;color:#b91c1c">Paquet refusé</h3>
      <p style="font-size:13px">${_extEsc(e.message)}</p></div></div>`;
  }
}

/** Examine un paquet déjà présent sur le serveur (exemple fourni, réinstall). */
async function extImporterLocal(fichier) {
  const zone = document.getElementById('extZoneExamen');
  zone.innerHTML = '<div class="card"><div class="card-body">Vérification du paquet…</div></div>';
  try {
    const rep = await apiRequest('ext_importer_local', 'POST', { fichier });
    _extAfficherExamen(rep?.data ?? rep);
  } catch (e) {
    toast(e.message, 'error');
    zone.innerHTML = '';
  }
}

function _extAfficherExamen(data) {
  ExtState.jeton = data.jeton;
  ExtState.examen = data.rapport;
  ExtState.acceptees = new Set();
  const zone = document.getElementById('extZoneExamen');
  zone.innerHTML = _extExamenHtml(data.rapport);

  // Le bouton est rendu « disabled » : il faut évaluer l'état MAINTENANT.
  // Un module déjà installé rouvre avec ses permissions cochées, et un module
  // qui n'en demande aucune doit être installable sans qu'on ait rien à
  // cliquer — dans les deux cas, aucun clic ne viendra déclencher la mise à
  // jour.
  extMajInstallation();

  zone.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * Bascule extensions.actif dans config.json.
 *
 * Passe par la route config_serveur, qui écrit la section même si elle est
 * absente du fichier — les installations antérieures à cette version n'ont donc
 * rien à coller à la main, ce qui évitait surtout de casser le JSON.
 *
 * La confirmation n'est pas décorative : c'est le seul moment où l'on décide
 * d'ouvrir la porte à du code tiers. La refermer, en revanche, est immédiat et
 * sans conséquence — les modules installés restent sur le disque, simplement
 * plus rien ne les charge.
 */
async function extActiverCouche(actif) {
  const suite = async () => {
    try {
      const res = await fetch('api/index.php?action=config_serveur', {
        method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify([
          { section: 'extensions', cle: 'actif', valeur: actif },
        ]),
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.error || 'Échec');
      (j.data?.warnings || []).forEach(w => toast(w, 'warning'));
      toast(actif ? 'Modules complémentaires activés.'
                  : 'Modules communautaires désactivés.', 'success');
      await renderExtensions();
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  if (!actif) return suite();

  // showConfirm(message, callback, libelleBouton) — DEUX arguments avant le
  // libellé, pas un titre puis un message. Passer trois arguments met la chaîne
  // de message à la place du callback : cb() lève « is not a function » et
  // l'action ne part jamais.
  showConfirm(
    'Activer les modules complémentaires ? Un module est un fichier JSON qui décrit des '
    + 'données et des écrans : Larka les affiche, il n\'exécute aucun code. Rien ne '
    + 'changera tant que vous n\'aurez pas installé un module — et chacun vous dira, '
    + 'avant installation, ce qu\'il ajoute.',
    suite,
    'Activer');
}


function _extTableau() {
  const lignes = ExtState.inventaire.map(e => {
    if (!e.valide) {
      // Une extension trop récente n'est pas une extension cassée : le message
      // doit désigner Larka, pas le paquet, sinon on cherche du côté de
      // l'auteur alors qu'il n'y a rien à corriger chez lui.
      const couleur = e.incompatible ? '#ea580c' : '#b91c1c';
      const titre = e.incompatible ? 'Incompatible avec cette version de Larka'
                                   : 'Manifeste invalide';
      return `<tr>
        <td colspan="4"><strong>${_extEsc(e.identifiant)}</strong>
          <div style="color:${couleur};font-size:12px">
            <strong>${titre}</strong> — ${_extEsc(e.erreur)}</div>
        </td>
        <td>—</td></tr>`;
    }
    const r = EXT_RISQUE[e.risque] || EXT_RISQUE.critique;
    let etat, couleurEtat;
    if (e.suspendue)          { etat = 'Suspendue'; couleurEtat = '#b91c1c'; }
    else if (e.code_modifie)  { etat = 'Code modifié'; couleurEtat = '#b91c1c'; }
    else if (e.capacites_nouvelles?.length) { etat = 'Nouvelles permissions'; couleurEtat = '#ea580c'; }
    else if (e.active)        { etat = 'Active'; couleurEtat = '#16a34a'; }
    else if (e.installee)     { etat = 'Installée, inactive'; couleurEtat = 'var(--gray-text)'; }
    else                      { etat = 'Non installée'; couleurEtat = 'var(--gray-text)'; }

    return `<tr>
      <td>
        <strong>${_extEsc(e.nom)}</strong> <span style="color:var(--gray-text)">v${_extEsc(e.version)}</span>
        <div style="font-size:12px;color:var(--gray-text)">${_extEsc(e.identifiant)}</div>
        <div style="font-size:12px;margin-top:2px">${_extEsc(e.description)}</div>
      </td>
      <td style="font-size:12px">${_extEsc(e.auteur)}</td>
      <td style="color:${couleurEtat};font-size:13px">
        ${etat}
        ${e.suspendue ? `<div style="font-size:11px">${_extEsc(e.suspendue)}</div>
          <button class="btn btn-sm btn-secondary" style="margin-top:4px"
            onclick="extRelancer('${_extEsc(e.identifiant)}')">Relancer</button>` : ''}
        ${e.echecs ? `<div style="font-size:11px">${e.echecs} échec(s)</div>` : ''}
        ${e.installee && e.roles_effectifs ? `
          <div style="font-size:11px;color:var(--gray-text);margin-top:3px">
            👥 ${_extEsc(e.roles_effectifs.join(', '))}</div>` : ''}
      </td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm btn-secondary"
          onclick="extExaminer('${_extEsc(e.identifiant)}')">Examiner</button>
        ${e.installee && (e.roles_optionnels || []).length ? `
          <button class="btn btn-sm btn-secondary"
            onclick="extRoles('${_extEsc(e.identifiant)}')"
            title="Qui voit ce module">👥 Accès</button>` : ''}
        ${e.installee && !e.code_modifie && !e.capacites_nouvelles?.length ? `
          <button class="btn btn-sm ${e.active ? 'btn-secondary' : 'btn-primary'}"
            onclick="extBasculer('${_extEsc(e.identifiant)}', ${!e.active})">
            ${e.active ? 'Désactiver' : 'Activer'}</button>` : ''}
      </td>
    </tr>`;
  }).join('');

  return `<table class="table">
    <thead><tr><th>Module</th><th>Auteur</th><th>État</th><th></th></tr></thead>
    <tbody>${lignes}</tbody></table>`;
}

// ── Écran d'examen ───────────────────────────────────────────────────────────

/**
 * Lève une suspension. Utile quand la panne venait du cœur et non du module :
 * sans cela, il fallait désinstaller puis réinstaller, en perdant les
 * permissions accordées.
 */
async function extRelancer(id) {
  try {
    await apiRequest('ext_relancer', 'POST', { extension: id });
    toast('Module relancé.', 'success');
    renderExtensions();
  } catch (e) { toast(e.message, 'error'); }
}

async function extExaminer(id) {
  const zone = document.getElementById('extZoneExamen');
  zone.innerHTML = '<div class="card"><div class="card-body">Analyse en cours…</div></div>';
  try {
    // Convention du projet : les paramètres GET additionnels sont concaténés à
    // l'action (cf. ListesApi, InterventionsApi dans js/api.js).
    const rep = await apiRequest(`ext_examiner&extension=${encodeURIComponent(id)}`, 'GET');
    const d = rep?.data ?? rep;
    ExtState.examen = d;
    ExtState.acceptees = new Set();          // rien n'est coché d'avance
    zone.innerHTML = _extExamenHtml(d);
    zone.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (e) {
    zone.innerHTML = errorHtml(e.message);
  }
}

function _extExamenHtml(d) {
  const r = EXT_RISQUE[d.risque] || EXT_RISQUE.critique;

  // Les capacités déjà accordées repartent cochées, et sont comptées comme
  // acceptées : rouvrir un module installé ne doit pas donner l'impression que
  // rien n'avait été enregistré.
  const deja = new Set(d.deja_accordees || []);
  deja.forEach((c) => ExtState.acceptees.add(c));

  const capacites = d.capacites.map((c, i) => {
    const rc = EXT_RISQUE[c.risque] || EXT_RISQUE.critique;
    return `<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;
      border:1px solid var(--gray-border);border-left:4px solid ${rc.couleur};
      border-radius:6px;margin-bottom:8px;cursor:pointer;background:${rc.fond}">
      <input type="checkbox" data-cap="${_extEsc(c.capacite)}" style="margin-top:3px"
        ${deja.has(c.capacite) ? 'checked' : ''} onchange="extCocher(this)">
      <span>
        <strong>${_extEsc(c.libelle)}</strong>
        <span style="color:${rc.couleur};font-size:11px;font-weight:700;margin-left:6px">
          ${rc.libelle.toUpperCase()}</span>
        <div style="font-size:12px;line-height:1.5;margin-top:3px">${_extEsc(c.detail)}</div>
        ${!c.connue ? `<div style="color:#b91c1c;font-size:12px;font-weight:600">
          Capacité inconnue de cette version de Larka.</div>` : ''}
      </span>
    </label>`;
  }).join('');

  const reseau = d.domaines_reseau?.length ? `
    <div style="margin:12px 0">
      <strong>Serveurs contactés</strong>
      <div style="font-size:13px;color:var(--gray-text)">Toute autre destination sera refusée.</div>
      <ul style="margin:6px 0">${d.domaines_reseau.map(x =>
        `<li><code>${_extEsc(x)}</code></li>`).join('')}</ul>
    </div>` : '';

  const hooks = d.hooks?.length ? `
    <div style="margin:12px 0">
      <strong>Événements écoutés</strong>
      <ul style="margin:6px 0;font-size:13px">${d.hooks.map(h =>
        `<li>${_extEsc(h.libelle)}</li>`).join('')}</ul>
      <div style="font-size:12px;color:var(--gray-text)">Une extension est notifiée après coup :
        elle ne peut ni annuler ni modifier l'action.</div>
    </div>` : '';

  return `<div class="card" style="margin-top:16px"><div class="card-body">
    <h3 style="margin-top:0">${_extEsc(d.nom)}
      <span style="color:var(--gray-text);font-weight:400">v${_extEsc(d.version)}</span></h3>
    <div style="font-size:13px;color:var(--gray-text)">
      ${_extEsc(d.auteur)} · <code>${_extEsc(d.identifiant)}</code></div>
    <p>${_extEsc(d.description)}</p>

    ${d.declaratif ? _extBandeauDeclaratif() : `
      <div style="background:${r.fond};border:1px solid ${r.couleur};border-radius:6px;
        padding:14px;margin:16px 0;white-space:pre-line;font-size:13px;line-height:1.6">
        ${_extEsc(d.avertissement)}</div>`}

    ${d.declaratif ? _extResumeDeclaration(d.resume_declaration)
                   : _extAnalyseHtml(d.analyse)}
    ${reseau}
    ${hooks}

    ${d.deja_installee ? `
      <div style="background:#eff6ff;border-left:3px solid #2563eb;border-radius:4px;
        padding:10px 14px;margin:14px 0;font-size:13px">
        <strong style="color:#2563eb">Module déjà installé</strong> — les accès que vous
        aviez accordés sont cochés ci-dessous. Réenregistrer ne remet rien à zéro ;
        seules les cases décochées seront retirées.
      </div>` : ''}

    ${(d.roles_optionnels || []).length ? `
      <h4>Qui verra ce module</h4>
      <p style="font-size:13px;color:var(--gray-text)">
        Les gestionnaires et administrateurs y ont accès dans tous les cas.
        Décochez ce qui ne convient pas : un habillage peut s'ouvrir à tous,
        un registre nominatif rarement.</p>
      <div style="margin-bottom:16px">
        ${d.roles_optionnels.map(r => `
          <label style="display:flex;gap:10px;align-items:center;padding:8px 12px;
            border:1px solid var(--gray-border);border-radius:6px;margin-bottom:6px;
            cursor:pointer;font-size:13px">
            <input type="checkbox" class="ext-role-cb" data-role="${_extEsc(r)}" checked>
            <span><strong>${_extEsc(r)}</strong>
              <span style="color:var(--gray-text)">— ${
                r === 'Demandeur' ? 'les personnes qui déposent des demandes'
                                  : 'les comptes en consultation seule'}</span></span>
          </label>`).join('')}
      </div>` : ''}

    <h4>Ce à quoi le module aura accès</h4>
    <p style="font-size:13px;color:var(--gray-text)">
      Cochez chaque ligne pour confirmer votre accord. Le module a besoin de toutes
      pour fonctionner.</p>

    ${d.capacites.length > 1 ? `
      <label style="display:flex;gap:10px;align-items:center;padding:8px 12px;
        border:1px dashed var(--gray-border);border-radius:6px;margin-bottom:10px;
        cursor:pointer;font-size:13px">
        <input type="checkbox" id="extChkTout" onchange="extToutCocher(this)"
          ${d.capacites.every(c => deja.has(c.capacite)) ? 'checked' : ''}>
        <span><strong>Tout accepter</strong>
          <span style="color:var(--gray-text)">— ${d.capacites.length} permissions.
          Les lignes restent affichées : la case coche, elle ne masque rien.</span></span>
      </label>` : ''}

    ${capacites}

    <div style="display:flex;gap:10px;align-items:center;margin-top:18px">
      <button id="extBtnInstaller" class="btn btn-primary" disabled
        onclick="extInstaller()">Installer et activer</button>
      <button class="btn btn-secondary"
        onclick="document.getElementById('extZoneExamen').innerHTML=''">Annuler</button>
      <span id="extCompteur" style="font-size:13px;color:var(--gray-text)"></span>
    </div>
  </div></div>`;
}

/**
 * Le compte rendu d'analyse. Volontairement dépourvu de tout badge « conforme »
 * ou « sûr » : l'analyse statique se contourne, et l'écran doit le dire au lieu
 * de laisser croire qu'elle valide quoi que ce soit.
 */
/**
 * Bandeau d'un pack de données.
 *
 * Les avertissements sur le code n'ont aucun sens ici : il n'y en a pas. Les
 * afficher quand même — « le code s'exécutera dans un processus séparé » sur un
 * module sans code — apprend à l'administrateur à ne plus lire cet écran, et
 * c'est exactement l'écran qu'il doit lire.
 */
function _extBandeauDeclaratif() {
  // Le bandeau disait en trois phrases ce que « Ce qu'il ne fera pas » répète
  // juste en dessous. Une seule ligne suffit à poser le cadre ; les garanties
  // détaillées viennent après, une fois qu'on sait ce que le module ajoute.
  return `<div style="background:#f0fdf4;border-left:3px solid #16a34a;border-radius:4px;
    padding:10px 14px;margin:14px 0;font-size:13px">
    <strong style="color:#16a34a">Pack de données</strong> — ni PHP ni JavaScript.
    Sa déclaration tient en un fichier de quelques Ko, lisible avant d'installer.
  </div>`;
}

/** Ce que la déclaration va réellement produire. */
function _extResumeDeclaration(r) {
  if (!r) return '';

  // Ce que le module VA FAIRE, en phrases. Une liste de tables et de champs
  // n'aide pas à décider : l'administrateur veut savoir ce qui va changer dans
  // son application, pas combien de colonnes seront créées.
  const actions = (r.actions || []).map(a =>
    `<li style="margin:3px 0">${_extEsc(a)}</li>`).join('');
  const garanties = (r.garanties || []).map(g =>
    `<li style="margin:3px 0">${_extEsc(g)}</li>`).join('');

  const enTete = `
    <div style="margin:12px 0">
      <strong>Ce que ce module va faire</strong>
      <ul style="margin:6px 0 0;padding-left:20px;font-size:13px;line-height:1.6">
        ${actions}</ul>
    </div>
    <div style="background:#f0fdf4;border-left:3px solid #16a34a;border-radius:4px;
      padding:10px 14px;margin:12px 0">
      <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.6">
        ${garanties}</ul>
    </div>`;
  const bloc = (titre, contenu) => contenu
    ? `<div style="margin:12px 0"><strong>${titre}</strong>${contenu}</div>` : '';

  // « Détail technique » répétait en jargon ce que la liste ci-dessus dit déjà
  // en français : mêmes tables, mêmes écrans, mêmes ancrages. Deux fois la même
  // information, c'est une fois de trop — l'administrateur cesse de lire.
  //
  // Ne subsiste que ce que la prose ne dit PAS : le nom exact des tables (utile
  // pour une sauvegarde) et les échanges entre modules.
  const technique = [
    r.jeux.map(j => `<code style="font-size:11px">${_extEsc(j.table)}</code>`).join(' '),
    r.partage.length ? '<div style="margin-top:6px">Publie <code>'
      + r.partage.map(p => _extEsc(p.nom)).join('</code>, <code>')
      + `</code> aux autres modules</div>` : '',
    r.calculs.length ? '<div style="margin-top:6px">Lit une valeur agrégée chez <code>'
      + r.calculs.map(c => _extEsc(String(c.de).split('/')[0])).join('</code>, <code>')
      + '</code></div>' : '',
  ].filter(Boolean).join('');

  return enTete + (technique ? `<div style="background:var(--gray-bg);border-radius:6px;
    padding:10px 14px;margin:12px 0;font-size:12px;color:var(--gray-text)">
    ${technique}</div>` : '');
}

function _extAnalyseHtml(a) {
  if (!a) return '';
  const bloc = (titre, liste, couleur) => liste.length ? `
    <div style="margin:10px 0">
      <strong style="color:${couleur}">${titre} (${liste.length})</strong>
      <ul style="margin:6px 0;font-size:13px;line-height:1.6">
        ${liste.map(p => `<li><code>${_extEsc(p.fichier)}</code>${p.ligne ? ':' + p.ligne : ''}
          — ${_extEsc(p.message)}</li>`).join('')}
      </ul>
    </div>` : '';

  return `<div style="border:1px solid var(--gray-border);border-radius:6px;padding:14px;margin:16px 0">
    <strong>Lecture automatique du code</strong>
    <div style="font-size:12px;color:var(--gray-text);margin:4px 0 8px">
      ${a.fichiers} fichier(s) PHP, ${a.lignes} lignes. Cette lecture repère les constructions
      dangereuses courantes. Elle ne certifie rien : un auteur décidé la contourne sans peine.
      Elle vous donne une idée de ce que fait le code — elle ne remplace pas de le lire, ni de
      connaître celui qui l'a écrit.</div>
    ${bloc('Refusé', a.bloquants, '#b91c1c')}
    ${bloc('À vérifier', a.avertissements, '#ea580c')}
    ${bloc('Remarques', a.notes, 'var(--gray-text)')}
    ${!a.bloquants.length && !a.avertissements.length && !a.notes.length
      ? '<div style="font-size:13px">Rien de particulier relevé.</div>' : ''}
  </div>`;
}

function extCocher(input) {
  const cap = input.dataset.cap;
  if (input.checked) ExtState.acceptees.add(cap); else ExtState.acceptees.delete(cap);
  extSyncToutCocher();
  extMajInstallation();
}

/**
 * « Tout accepter » — coche ou décoche les permissions d'un seul geste.
 *
 * Une par une, c'est une friction VOULUE : elle oblige à passer devant chaque
 * ligne. Mais sur un module qui en demande huit, la friction cesse d'être une
 * lecture et devient un tapotement — on clique huit fois sans lire, ce qui est
 * exactement ce que la friction cherchait à éviter.
 *
 * Le compromis retenu : la case groupe le GESTE, jamais l'information. Les
 * lignes restent affichées, avec leur couleur de risque et leur détail ; rien
 * n'est replié. Et elle n'apparaît qu'à partir de deux permissions.
 */
function extToutCocher(input) {
  const cases = document.querySelectorAll('#extZoneExamen [data-cap]');
  cases.forEach((c) => {
    c.checked = input.checked;
    if (input.checked) ExtState.acceptees.add(c.dataset.cap);
    else ExtState.acceptees.delete(c.dataset.cap);
  });
  extMajInstallation();
}

/** Remet « Tout accepter » d'accord avec les cases individuelles. */
function extSyncToutCocher() {
  const tout = document.getElementById('extChkTout');
  if (!tout) return;
  const cases = Array.from(document.querySelectorAll('#extZoneExamen [data-cap]'));
  tout.checked = cases.length > 0 && cases.every((c) => c.checked);
  // Ni tout coché ni rien coché : l'état intermédiaire se voit, plutôt que de
  // laisser la case afficher « décoché » alors que six lignes sur huit le sont.
  tout.indeterminate = !tout.checked && cases.some((c) => c.checked);
}

/**
 * Met à jour le bouton d'installation et son compteur.
 *
 * Elle vivait DANS extCocher(), donc ne s'exécutait qu'au clic d'une case. Un
 * module sans permission n'en affiche aucune : rien ne réactivait jamais le
 * bouton, rendu « disabled ». Le module le plus inoffensif était le seul
 * impossible à installer.
 *
 * Elle est maintenant appelée aussi à l'affichage de l'examen.
 */
function extMajInstallation() {
  if (!ExtState.examen) return;
  extSyncToutCocher();

  const total = ExtState.examen?.capacites?.length || 0;
  const n = ExtState.acceptees.size;
  // « total > 0 » interdisait d'installer un module qui ne demande AUCUNE
  // permission — un habillage ou une traduction, qui ne lit rien et n'écrit
  // rien. Le bouton restait grisé sans explication : le module le plus
  // inoffensif était le seul impossible à installer.
  const pret = n === total && ExtState.examen.installable;

  const btn = document.getElementById('extBtnInstaller');
  if (btn) btn.disabled = !pret;
  const cpt = document.getElementById('extCompteur');
  if (cpt) {
    cpt.textContent = ExtState.examen.installable
      ? (total === 0
          ? 'Ce module ne demande aucun accès.'
          : `${n} / ${total} permission(s) acceptée(s)`)
      : 'Installation impossible : voir les points refusés ci-dessus.';
  }
}

async function extInstaller() {
  const d = ExtState.examen;
  if (!d) return;

  if (!ExtState.jeton) {
    toast('Aucun paquet en attente : réexaminez le module avant d\'installer.', 'error');
    return;
  }

  showConfirm(
    (ExtState.acceptees.size === 0
      ? `Installer « ${d.nom} » ? Ce module ne demande aucun accès à vos données. `
        + `Chaque utilisateur pourra ensuite l'activer pour lui-même depuis `
        + `Préférences interface.`
      : `Installer « ${d.nom} » ? Il disposera des ${ExtState.acceptees.size} `
        + `permission(s) que vous venez d'accorder. Vous pourrez le désactiver à `
        + `tout moment, mais ce qu'il aura fait entre-temps ne sera pas annulé.`),
    async () => {
      try {
        // Toute installation passe désormais par un paquet mis en attente :
        // téléversé, ou importé depuis le disque. Le jeton désigne ce paquet.
        // Rôles retenus. On envoie TOUJOURS le tableau, même vide : côté
        // serveur, l'absence du champ signifie « je ne me prononce pas » et fait
        // reprendre la déclaration du module. Un tableau vide, lui, veut dire
        // « personne d'autre que les gestionnaires » — deux choses différentes.
        const roles = [];
        document.querySelectorAll('.ext-role-cb').forEach(cb => {
          if (cb.checked) roles.push(cb.dataset.role);
        });
        await apiRequest('ext_installer_paquet', 'POST', {
          jeton: ExtState.jeton,
          capacites: Array.from(ExtState.acceptees),
          roles,
          confirmation: true,
        });
        // Le module est utilisable immédiatement. Il l'était déjà avant, en
        // réalité — mais un message renvoyait vers une affectation à faire
        // ailleurs, qui n'existe plus : une extension s'installe pour le client
        // chez qui on est connecté, et pour lui seul.
        toast(`Module « ${d.nom} » installé et actif.`, 'success');
        // Le jeton est consommé côté serveur : le garder inviterait à un
        // second clic qui échouerait avec « paquet introuvable ».
        ExtState.jeton = null;
        ExtState.examen = null;
        document.getElementById('extZoneExamen').innerHTML = '';
        await renderExtensions();
      } catch (e) {
        toast(e.message, 'error');
      }
    },
    'Installer');
}

/**
 * Qui voit ce module — modifiable après l'installation.
 *
 * La sensibilité d'un module ne dépend pas de son code mais de ce qu'on y met,
 * et cela se découvre à l'usage : un registre ouvert aux demandeurs au départ
 * peut se remplir de données nominatives six mois plus tard. Figer le choix à
 * l'installation obligerait à désinstaller pour le reprendre.
 */
async function extRoles(id) {
  const e = (ExtState.inventaire || []).find(x => x.identifiant === id);
  if (!e) { toast('Module introuvable.', 'error'); return; }

  const possibles = e.roles_optionnels || [];
  const actifs = new Set(e.roles_effectifs || []);

  openModal(`👥 Accès à « ${_extEsc(e.nom || id)} »`, `
    <div class="form-grid cols-1">
      <p style="font-size:13px;color:var(--gray-text);margin:0">
        Les gestionnaires et administrateurs conservent l'accès dans tous les
        cas — sans quoi plus personne ne pourrait ouvrir le module, pas même
        pour le désinstaller.</p>
      <div style="display:flex;flex-direction:column;gap:8px">
        ${possibles.map(r => `
          <label style="display:flex;gap:10px;align-items:center;padding:10px 12px;
            border:1px solid var(--gray-border);border-radius:8px;cursor:pointer;font-size:13px">
            <input type="checkbox" class="ext-role-edit" data-role="${_extEsc(r)}"
              ${actifs.has(r) ? 'checked' : ''}>
            <span><strong>${_extEsc(r)}</strong>
              <span style="color:var(--gray-text)">— ${
                r === 'Demandeur' ? 'les personnes qui déposent des demandes'
                                  : 'les comptes en consultation seule'}</span></span>
          </label>`).join('')}
      </div>
      <div style="font-size:12px;color:var(--gray-text)">
        Le refus s'applique au serveur, pas seulement au menu : un rôle retiré
        ne peut plus appeler le module, même directement.</div>
    </div>`, async () => {
    const roles = [];
    document.querySelectorAll('.ext-role-edit').forEach(cb => {
      if (cb.checked) roles.push(cb.dataset.role);
    });
    try {
      await apiRequest('ext_roles', 'POST', { extension: id, roles });
      toast('Accès mis à jour.', 'success');
      closeModal();
      renderExtensions();
    } catch (err) { toast(err.message, 'error'); }
  }, 'Enregistrer');
}

async function extBasculer(id, actif) {
  try {
    await apiRequest('ext_activer', 'POST', { extension: id, actif });
    toast(actif ? 'Extension activée.' : 'Extension désactivée.', 'success');
    await renderExtensions();
  } catch (e) {
    toast(e.message, 'error');
  }
}
