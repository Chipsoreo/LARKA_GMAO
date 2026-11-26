/**
 * Larka — Appareils mobiles (app Larka hors ligne reliée à ce serveur).
 *
 * « Mon profil → Mes appareils mobiles » : chacun relie SES appareils. Un QR code (adresse du
 * serveur + code à usage unique, 15 min) ; l'appareil le scanne ; les deux écrans affichent le
 * même code de vérification ; l'appareil confirme, puis la personne accepte ici en redonnant son
 * mot de passe. L'appareil est toujours relié au compte connecté : impossible d'y mettre le compte
 * d'un autre.
 * « Configuration → Comptes → Appareils mobiles » (gestionnaires) : tous les appareils, pour délier.
 */

let _appMob = { invitation: null, minuterie: null, tous: false };

function _appMobOuvert() {
  return !!document.getElementById('appMob') && document.getElementById('modalOverlay')?.classList.contains('open');
}
function _appMobLocal() {
  const p = String((typeof App !== 'undefined' && App.currentUser && App.currentUser.Provider) || 'local').toLowerCase();
  return p === 'local' || p === '';
}

function ouvrirMesAppareils() {
  _appMob.tous = false;
  const u = (typeof App !== 'undefined' && App.currentUser) || {};
  openModal('Mes appareils mobiles', `<div id="appMob">
      <p style="margin-top:0">L'app mobile Larka fonctionne hors ligne. Reliée à ce serveur, elle se synchronise dès que le réseau le permet.
        Un appareil relié ici utilise <b>votre compte (${escHtml(u.Login || '')})</b> : il pourra modifier sur le serveur ce que vous avez le droit de modifier.</p>
      <button class="btn btn-primary" onclick="appMobInviter()">Relier un de mes appareils</button>
      <div id="appMobInvit" style="margin-top:12px"></div>
      <h4 style="margin:18px 0 6px">Mes appareils reliés</h4>
      <div id="appMobListe">Chargement…</div>
    </div>`, null, null, 'Fermer', '680px');
  appMobListe();
}

function ouvrirAppareilsEtablissement() {
  _appMob.tous = true;
  openModal('Appareils mobiles de l\'établissement', `<div id="appMob">
      <p style="margin-top:0">Appareils reliés à ce serveur, avec le compte qu'ils utilisent. Pour relier un appareil, chacun passe par
        <b>Mon profil → Mes appareils mobiles</b> : un appareil est toujours relié au compte de la personne connectée.</p>
      <div id="appMobListe">Chargement…</div>
    </div>`, null, null, 'Fermer', '760px');
  appMobListe();
}

async function appMobListe() {
  const z = document.getElementById('appMobListe');
  if (!z) return;
  try {
    const l = await apiRequest(_appMob.tous ? 'appareils&tous=1' : 'appareils');
    if (!l.length) { z.innerHTML = '<p style="color:var(--gray-text)">Aucun appareil relié.</p>'; return; }
    z.innerHTML = `<table class="table" style="width:100%;font-size:13px"><thead><tr><th>Appareil</th>${_appMob.tous ? '<th>Compte</th>' : ''}<th>Relié le</th><th>Dernière synchronisation</th><th></th></tr></thead><tbody>
      ${l.map(a => `<tr style="${a.actif ? '' : 'opacity:.5'}"><td>${escHtml(a.nom || '')}</td>${_appMob.tous ? `<td>${escHtml(a.login || '')}</td>` : ''}<td>${escHtml((a.relie_le || '').slice(0, 16))}</td>
        <td>${a.actif ? escHtml((a.vu_le || '').slice(0, 16)) : 'délié'}</td>
        <td>${a.actif ? `<button class="btn btn-ghost btn-sm" onclick="appMobRevoquer(${a.id})">Délier</button>` : ''}</td></tr>`).join('')}
      </tbody></table>`;
  } catch (e) { z.innerHTML = errorHtml(e.message); }
}

async function appMobRevoquer(id) {
  if (!confirm('Délier cet appareil ? Il ne pourra plus se synchroniser (ses données restent sur l\'appareil).')) return;
  try { await apiRequest('appareils_revoquer', 'POST', { id }); appMobListe(); } catch (e) { toast(e.message, 'error'); }
}

function _appMobZxing() {
  if (window.ZXingBrowser) return Promise.resolve();
  return new Promise((ok, ko) => { const sc = document.createElement('script'); sc.src = 'js/zxing-browser.min.js'; sc.onload = ok; sc.onerror = () => ko(new Error('Générateur de QR code indisponible.')); document.head.appendChild(sc); });
}

async function appMobInviter() {
  const z = document.getElementById('appMobInvit');
  if (!z) return;
  clearTimeout(_appMob.minuterie);
  z.innerHTML = 'Création du code…';
  try {
    const [inv, app] = await Promise.all([apiRequest('appareils_invitation', 'POST', {}), apiRequest('appareils_app').catch(() => null)]);
    _appMob.invitation = inv;
    _appMob.app = app && app.versionCode ? app : null;
    const gh = app && app.github;
    const ligneGithub = gh && gh.verifie ? `<p style="margin:4px 0 0;font-size:12px;color:var(--gray-text)">GitHub vérifié le ${escHtml(String(gh.verifie).slice(0, 16).replace('T', ' '))} :
        ${gh.erreur ? escHtml(gh.erreur) : gh.disponible ? `dernière app pour cette version : ${escHtml(gh.disponible.versionName)}${gh.telechargee ? ' (téléchargée ici)' : ''}` : 'aucune app publiée pour cette version'}.</p>` : '';
    const ici = location.origin + location.pathname.replace(/\/[^/]*$/, '');
    const local = /^(localhost|127\.|\[::1\])/.test(location.hostname);
    const url = local && inv.adresses && inv.adresses.length ? inv.adresses[0] : ici;
    z.innerHTML = `<h4 style="margin:4px 0 6px">1. Installer l'app (si ce n'est pas déjà fait)</h4>
      ${_appMob.app ? `<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px">
        <div id="appMobQrApp" style="background:#fff;padding:8px;border-radius:8px;width:176px;height:176px"></div>
        <div style="flex:1;min-width:220px;font-size:13px">
          <p style="margin:0 0 6px">Scannez avec l'appareil photo de la tablette : l'app Larka <b>${escHtml(app.versionName || app.version)}</b>
            (${(app.taille / 1048576).toFixed(0)} Mo) se télécharge depuis ce serveur.</p>
          <p style="margin:0;color:var(--gray-text);font-size:12px">Sert aussi à mettre l'app à jour (les données de la tablette sont gardées).
            Android demande d'autoriser l'installation depuis le navigateur, et Play Protect peut avertir
            (app hors Play Store) : « Plus de détails » → « Installer quand même ». Les mises à jour sont aussi proposées dans l'app.</p>
          ${ligneGithub}
        </div></div>`
      : `<p style="font-size:13px;color:var(--gray-text);margin:0 0 14px">Ce serveur ne propose pas encore l'app : construisez-la avec <code>npm run apk</code> dans le dossier de l'app mobile
          (elle est déposée dans <code>telechargements/</code>), ou publiez-la sur GitHub (le serveur la récupère).</p>${ligneGithub}`}
      <h4 style="margin:4px 0 6px">2. Relier l'appareil</h4>
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
        <div id="appMobQr" style="background:#fff;padding:8px;border-radius:8px;width:236px;height:236px"></div>
        <div style="flex:1;min-width:220px">
          <p style="margin:0 0 6px">Dans l'app : <b>Données et serveur central → Relier → Scanner le QR code</b>.</p>
          <p style="margin:0 0 4px">Ou saisissez le code :</p>
          <div id="appMobCode" style="font-size:26px;font-weight:700;letter-spacing:3px;font-family:monospace">${escHtml(inv.code)}</div>
          <label style="display:block;margin-top:8px;font-size:12px">Adresse du serveur vue par l'appareil
            <input id="appMobUrl" class="form-control" value="${escHtml(url)}" onchange="appMobQr()"></label>
          ${local ? `<p style="font-size:12px;color:var(--orange,#b45309);margin:6px 0 0">Ce navigateur utilise ${escHtml(location.host)} : l'appareil ne peut pas joindre cette adresse. Vérifiez l'adresse ci-dessus (réseau local) ; le serveur doit être démarré ouvert au réseau (option --host 0.0.0.0) et le pare-feu doit laisser passer le port.</p>` : ''}
          <p style="font-size:12px;color:var(--gray-text);margin:6px 0 0">Code valable 15 minutes, une seule fois, pour votre compte uniquement.</p>
        </div>
      </div>
      <div id="appMobEtat" style="margin-top:12px;padding:10px;border-radius:8px;background:var(--gray-light,#f1f5f9)">En attente de l'appareil…</div>`;
    await appMobQr();
    appMobSuivre();
  } catch (e) { z.innerHTML = errorHtml(e.message); }
}

async function appMobQr() {
  const inv = _appMob.invitation, zone = document.getElementById('appMobQr'), url = document.getElementById('appMobUrl')?.value.trim().replace(/\/+$/, '');
  if (!inv || !zone || !url) return;
  try {
    await _appMobZxing();
    const svg = new ZXingBrowser.BrowserQRCodeSvgWriter().write(`LARKA-SYNC|${url}|${inv.code}`, 220, 220);
    zone.innerHTML = ''; zone.appendChild(svg);
    const zApp = document.getElementById('appMobQrApp');
    if (zApp && _appMob.app && _appMob.app.chemin) { zApp.innerHTML = ''; zApp.appendChild(new ZXingBrowser.BrowserQRCodeSvgWriter().write(`${url}/${_appMob.app.chemin}`, 160, 160)); }
  } catch (e) { zone.textContent = e.message; }
}

async function appMobSuivre() {
  clearTimeout(_appMob.minuterie);
  const inv = _appMob.invitation, z = document.getElementById('appMobEtat');
  if (!inv || !z || !_appMobOuvert()) return;
  let e;
  try { e = await apiRequest('appareils_invitation_etat', 'GET', null, inv.id); }
  catch (err) { z.textContent = err.message; _appMob.minuterie = setTimeout(appMobSuivre, 4000); return; }
  const tel = e.telephone_confirme ? '✓ Code confirmé sur l\'appareil.' : 'Pas encore confirmé sur l\'appareil.';
  if (e.etat === 'ouverte') { z.textContent = 'En attente de l\'appareil…'; }
  else if (e.etat === 'demandee') {
    if (!document.getElementById('appMobDecision')) {
      const u = (typeof App !== 'undefined' && App.currentUser) || {};
      z.innerHTML = `<div id="appMobDecision">
          <p style="margin:0 0 6px">« <b>${escHtml(e.appareil || 'Appareil')}</b> » demande à se relier à <b>votre compte (${escHtml(u.Login || '')})</b>.</p>
          <p style="margin:0">Code de vérification : <span id="appMobVerif" style="font-size:24px;font-weight:700;letter-spacing:3px;font-family:monospace">${escHtml(String(e.verif).replace(/^(\d{3})(\d{3})$/, '$1 $2'))}</span></p>
          <p style="margin:2px 0 8px;font-size:12px">Vérifiez que l'appareil affiche <b>le même code</b>. <span id="appMobTel">${tel}</span></p>
          ${_appMobLocal() ? `<label style="font-size:12px;display:block;max-width:320px">Votre mot de passe, pour confirmer
            <input id="appMobMdp" type="password" class="form-control" autocomplete="current-password" onkeydown="if(event.key==='Enter') appMobDecider(true)"></label>` : ''}
          <div style="margin-top:8px"><button class="btn btn-primary" onclick="appMobDecider(true)">Accepter</button>
          <button class="btn btn-ghost" onclick="appMobDecider(false)">Refuser</button></div>
        </div>`;
    } else {
      const t = document.getElementById('appMobTel');
      if (t) t.textContent = tel;
    }
  }
  else if (e.etat === 'acceptee') { z.textContent = e.telephone_confirme ? 'Accepté : l\'appareil récupère son accès…' : 'Accepté. En attente de la confirmation du code sur l\'appareil…'; }
  else if (e.etat === 'livree') { z.innerHTML = '<b>✓ Appareil relié à votre compte.</b> Il compare maintenant ses données avec celles du serveur.'; _appMob.invitation = null; appMobListe(); return; }
  else {
    z.textContent = { refusee: 'Demande refusée.', retiree: 'Invitation retirée.', expiree: 'Le code a expiré : créez-en un nouveau.' }[e.etat] || e.etat;
    _appMob.invitation = null; return;
  }
  _appMob.minuterie = setTimeout(appMobSuivre, 2000);
}

async function appMobDecider(accepter) {
  const inv = _appMob.invitation;
  if (!inv) return;
  const mdp = document.getElementById('appMobMdp');
  if (accepter && mdp && !mdp.value) { toast('Saisissez votre mot de passe pour confirmer.', 'warning'); mdp.focus(); return; }
  try {
    await apiRequest('appareils_invitation_valider', 'POST', { id: inv.id, accepter, mot_de_passe: mdp ? mdp.value : '' });
    document.getElementById('appMobDecision')?.remove();
    appMobSuivre();
  } catch (e) { toast(e.message, 'error'); if (mdp) { mdp.value = ''; mdp.focus(); } }
}
