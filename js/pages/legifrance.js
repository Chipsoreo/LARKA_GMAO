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
 * Larka — Page : Légifrance
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Recherche et consultation de textes juridiques via l'API Légifrance (PISTE).
 *
 * FONCTIONNALITÉS :
 *   - Recherche dans les codes, lois, décrets, jurisprudence
 *   - Consultation d'articles avec arborescence des sections
 *   - Affichage des modifications et versions d'articles
 *   - Filtrage par type de texte, date, pertinence
 *   - Lien direct vers le site Légifrance pour chaque résultat
 *
 * DÉPENDANCES :
 *   - api.js          → apiRequest() pour communiquer avec le backend
 *   - ui.js           → toast(), showLoading(), openModal()
 *   - Backend         → api/routes/france.php (proxy Légifrance via PISTE)
 *   - Configuration   → config.json > legifrance (clé API, environnement)
 *
 * POINT D'ENTRÉE :
 *   - renderLegifrance()  → appelé par nav.js quand on clique sur "Légifrance"
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ══════════════════════════════════════════════════════════════════════════════
//  LÉGIFRANCE
// ══════════════════════════════════════════════════════════════════════════════

function _escFr(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function _stripHtml(s){return String(s??'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();}
// Strip only <mark> tags but keep inner text — API sends highlighted matches as <mark>term</mark>
function _cleanMark(s){return String(s??'').replace(/<\/?mark[^>]*>/gi,'');}
function _fmtDate(d){
  if(!d)return'';
  var str=String(d);
  // Filter out placeholder dates like 2999-01-01 or 2222-01-01
  if(/^2[2-9]{3}/.test(str))return'';
  var ts=typeof d==='number'?d:(str.match(/^\d{10,}$/)?Number(str):Date.parse(str));
  if(isNaN(ts))return str;
  if(ts<9999999999)ts=ts*1000;
  // Also check the year of the resulting date
  var dt=new Date(ts);
  if(dt.getFullYear()>=2200)return'';
  return dt.toLocaleDateString('fr-FR',{day:'numeric',month:'long',year:'numeric'});
}
function _isPlaceholderDate(d){
  if(!d)return true;
  var str=String(d);
  if(/^2[2-9]{3}/.test(str))return true;
  var ts=typeof d==='number'?d:(str.match(/^\d{10,}$/)?Number(str):Date.parse(str));
  if(isNaN(ts))return true;
  if(ts<9999999999)ts=ts*1000;
  return new Date(ts).getFullYear()>=2200;
}
function _legiUrl(id){
  if(!id)return'';
  if(/^LEGIARTI/i.test(id))return'https://www.legifrance.gouv.fr/codes/article_lc/'+id;
  if(/^LEGITEXT/i.test(id))return'https://www.legifrance.gouv.fr/loda/id/'+id;
  if(/^JORFTEXT/i.test(id))return'https://www.legifrance.gouv.fr/jorf/id/'+id;
  if(/^JURITEXT/i.test(id))return'https://www.legifrance.gouv.fr/juri/id/'+id;
  if(/^KALI/i.test(id))return'https://www.legifrance.gouv.fr/conv_coll/id/'+id;
  if(/^CETATEXT/i.test(id))return'https://www.legifrance.gouv.fr/ceta/id/'+id;
  if(/^CNILTEXT/i.test(id))return'https://www.legifrance.gouv.fr/cnil/id/'+id;
  if(/^CONSTTEXT/i.test(id))return'https://www.legifrance.gouv.fr/cons/id/'+id;
  if(/^ACCOTEXT/i.test(id))return'https://www.legifrance.gouv.fr/acco/id/'+id;
  return'';
}

var FR_TYPES={
  texte:{titre:'Texte de loi / Décret / Arrêté',icon:'📜',cat:'Législation',
    desc:'Lois, décrets, arrêtés, ordonnances consolidés.',
    detail:'Textes législatifs et réglementaires : lois votées par le Parlement, décrets du Président ou Premier ministre, arrêtés ministériels ou préfectoraux, ordonnances. Recherche par numéro ou mots-clés.',
    fond:'LODA_ETAT',fondDate:'LODA_DATE',exemples:['décret ascenseur','arrêté amiante','loi 2019-290']},
  code:{titre:'Article de code',icon:'📖',cat:'Législation',
    desc:'Recherche dans les codes officiels (travail, civil, environnement…).',
    detail:'Les codes regroupent les textes par domaine. Recherche par numéro d\'article (L.4121-1, R4224-17) ou par thème. La table des matières est explorable.',
    fond:'CODE_ETAT',fondDate:'CODE_DATE',exemples:['R4224-17','eau','amiante'],hasCodeName:true},
  jorf:{titre:'Journal officiel (JORF)',icon:'📰',cat:'Législation',
    desc:'Textes publiés au Journal officiel de la République française.',
    detail:'Le JORF publie les lois, décrets, arrêtés et avis officiels. Recherche par NOR, numéro de texte ou mots-clés. Utile pour trouver la version initiale d\'un texte à sa date de publication.',
    fond:'JORF',exemples:['PRMD2117108D','décret 2021','amiante']},
  circulaire:{titre:'Circulaires',icon:'📋',cat:'Législation',
    desc:'Instructions et circulaires ministérielles.',
    detail:'Circulaires, instructions et notes de service adressées par les ministres aux services déconcentrés. Elles précisent l\'interprétation d\'un texte de loi.',
    fond:'CIRC',exemples:['amiante','sécurité incendie','accessibilité']},
  jurisprudence:{titre:'Jurisprudence judiciaire',icon:'⚖️',cat:'Jurisprudence',
    desc:'Cour de cassation, cours d\'appel, tribunaux judiciaires.',
    detail:'Arrêts et jugements des juridictions judiciaires (civiles, pénales, sociales). Recherche par mots-clés ou numéro d\'affaire (pourvoi).',
    fond:'JURI',exemples:['amiante responsabilité','trouble voisinage'],hasNumAffaire:true},
  cetat:{titre:'Jurisprudence administrative',icon:'🏛️',cat:'Jurisprudence',
    desc:'Conseil d\'État, cours administratives d\'appel, tribunaux administratifs.',
    detail:'Décisions de la justice administrative : recours pour excès de pouvoir, contentieux des marchés publics, urbanisme, responsabilité de l\'État, fonction publique, etc.',
    fond:'CETAT',exemples:['permis construire','marché public','responsabilité']},
  constit:{titre:'Conseil constitutionnel',icon:'🔖',cat:'Jurisprudence',
    desc:'Décisions du Conseil constitutionnel (QPC, DC, etc.).',
    detail:'Contrôle de constitutionnalité des lois (DC), questions prioritaires de constitutionnalité (QPC), contentieux électoral. Recherche par numéro de décision ou mots-clés.',
    fond:'CONSTIT',exemples:['QPC liberté','2018-717','environnement']},
  cnil:{titre:'CNIL',icon:'🔒',cat:'Autorités',
    desc:'Délibérations et décisions de la CNIL.',
    detail:'Commission nationale de l\'informatique et des libertés : délibérations, avis, autorisations, mises en demeure, sanctions. Données personnelles et RGPD.',
    fond:'CNIL',exemples:['vidéosurveillance','données personnelles','RGPD']},
  convention:{titre:'Convention collective',icon:'🤝',cat:'Social',
    desc:'Conventions collectives nationales par intitulé ou IDCC.',
    detail:'Accords entre organisations patronales et syndicats de salariés définissant les conditions de travail pour un secteur. Recherche par IDCC ou mots-clés.',
    fond:'KALI',exemples:['bâtiment','métallurgie','2098'],hasIdcc:true},
  acco:{titre:'Accords d\'entreprise',icon:'📝',cat:'Social',
    desc:'Accords collectifs d\'entreprise et de branche.',
    detail:'Accords négociés au niveau de l\'entreprise : temps de travail, rémunération, égalité professionnelle, télétravail, etc. Recherche par raison sociale ou thème.',
    fond:'ACCO',exemples:['télétravail','intéressement','égalité professionnelle']}
};

var FR_ETAT_MAP={
  'VIGUEUR':{bg:'rgba(26,179,148,.12)',bc:'rgba(26,179,148,.3)',c:'#1ab394',t:'En vigueur'},
  'EN_VIGUEUR':{bg:'rgba(26,179,148,.12)',bc:'rgba(26,179,148,.3)',c:'#1ab394',t:'En vigueur'},
  'VIGUEUR_ETEN':{bg:'rgba(26,179,148,.12)',bc:'rgba(26,179,148,.3)',c:'#1ab394',t:'En vigueur étendu'},
  'VIGUEUR_NON_ETEN':{bg:'rgba(26,179,148,.12)',bc:'rgba(26,179,148,.3)',c:'#1ab394',t:'En vigueur non étendu'},
  'VIGUEUR_DIFF':{bg:'rgba(245,166,35,.1)',bc:'rgba(245,166,35,.3)',c:'#f5a623',t:'En vigueur (différé)'},
  'ABROGE':{bg:'rgba(231,76,60,.1)',bc:'rgba(231,76,60,.25)',c:'#e74c3c',t:'Abrogé'},
  'ABROGE_DIFF':{bg:'rgba(231,76,60,.1)',bc:'rgba(231,76,60,.25)',c:'#e74c3c',t:'Abrogé (différé)'},
  'MODIFIE':{bg:'rgba(245,166,35,.1)',bc:'rgba(245,166,35,.3)',c:'#f5a623',t:'Modifié'},
  'PERIME':{bg:'rgba(122,138,158,.1)',bc:'rgba(122,138,158,.25)',c:'#7a8a9e',t:'Périmé'},
  'ANNULE':{bg:'rgba(231,76,60,.1)',bc:'rgba(231,76,60,.25)',c:'#e74c3c',t:'Annulé'},
  'SUBSTITUE':{bg:'rgba(122,138,158,.1)',bc:'rgba(122,138,158,.25)',c:'#7a8a9e',t:'Substitué'},
  'TRANSFERE':{bg:'rgba(122,138,158,.1)',bc:'rgba(122,138,158,.25)',c:'#7a8a9e',t:'Transféré'},
  'DENONCE':{bg:'rgba(231,76,60,.1)',bc:'rgba(231,76,60,.25)',c:'#e74c3c',t:'Dénoncé'},
};
function _frBadge(etat){
  if(!etat)return'';var k=String(etat).toUpperCase().replace(/\s+/g,'_'),s=FR_ETAT_MAP[k];
  if(s)return'<span class="lfr-badge" style="background:'+s.bg+';border-color:'+s.bc+';color:'+s.c+'">'+_escFr(s.t)+'</span>';
  return'<span class="lfr-badge">'+_escFr(etat)+'</span>';
}

/* ═══════════════════════════════════════════════════════ */
async function renderLegifrance(){
  var main=document.getElementById('mainContent');if(!main)return;
  main.innerHTML=_frCSS()+'\
<div class="lfr-hero"><div style="position:relative">\
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><span style="font-size:20px">🇫🇷</span><span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.7">Légifrance · PISTE</span></div>\
<div style="font-size:22px;font-weight:800;line-height:1.35">Recherche juridique</div>\
<div class="lfr-hero-sub">Textes de loi, codes, jurisprudence et conventions collectives.</div>\
</div></div>\
<div id="legifranceContent"><div class="lfr-section" style="text-align:center"><div class="lfr-skeleton" style="width:60%;margin:0 auto 10px"></div><div class="lfr-skeleton" style="width:40%;margin:0 auto"></div></div></div>';
  try{
    var status=await LegifranceApi.status();
    document.getElementById('legifranceContent').innerHTML=_renderLegifrancePage(status);
    _bindFrancePage(status);
  }catch(e){
    document.getElementById('legifranceContent').innerHTML='<div class="lfr-section"><div style="display:flex;align-items:flex-start;gap:14px"><span style="font-size:24px">⚠️</span><div><div style="font-size:15px;font-weight:700;color:var(--red);margin-bottom:6px">Connexion impossible</div><div style="font-size:13px;color:var(--gray-text)">'+_escFr(e.message)+'</div></div></div></div>';
  }
}

function _renderLegifrancePage(status){
  // Group types by category
  var cats={};
  Object.entries(FR_TYPES).forEach(function(e){
    var k=e[0],t=e[1],c=t.cat||'Autre';
    if(!cats[c])cats[c]=[];
    cats[c].push({key:k,type:t});
  });
  var cardsHtml='';
  Object.entries(cats).forEach(function(e){
    var catName=e[0],items=e[1];
    cardsHtml+='<div class="lfr-cat-label">'+_escFr(catName)+'</div><div class="lfr-types">';
    items.forEach(function(item){
      cardsHtml+='<div class="lfr-type-card" data-fr-type="'+item.key+'"><div class="lfr-type-icon">'+item.type.icon+'</div><div class="lfr-type-name">'+_escFr(item.type.titre)+'</div><div class="lfr-type-desc">'+_escFr(item.type.desc)+'</div><div class="lfr-type-examples">'+item.type.exemples.map(function(x){return'<span class="lfr-type-ex" data-fr-example="'+_escFr(x)+'">'+_escFr(x)+'</span>';}).join('')+'</div></div>';
    });
    cardsHtml+='</div>';
  });
  return'\
<div class="lfr-section">\
  <div class="lfr-section-title">Type de recherche</div>\
  <div class="lfr-section-sub">Sélectionne le type de document juridique recherché</div>\
  '+cardsHtml+'\
  <div id="fr-type-detail" class="lfr-type-detail-box"></div>\
  <div id="fr-code-picker" class="lfr-code-picker" style="display:none">\
    <div class="lfr-code-picker-label">📖 Périmètre de recherche <span style="font-weight:400;font-size:11px;color:var(--gray-text)">(optionnel — par défaut tous les codes)</span></div>\
    <div class="lfr-autocomplete-wrap">\
      <input id="fr-code-name" class="lfr-input lfr-code-input" placeholder="Cliquer ici pour choisir un code… ex: travail, civil, environnement…" autocomplete="off">\
      <div id="fr-code-suggestions" class="lfr-autocomplete-list"></div>\
    </div>\
    <div id="fr-code-selected" class="lfr-code-selected" style="display:none"></div>\
  </div>\
  <div id="fr-scope-indicator" style="display:none;margin-bottom:8px"><span class="lfr-badge lfr-badge-blue" style="font-size:12px;padding:5px 12px" id="fr-scope-badge">🔍 Recherche dans tous les codes</span></div>\
  <div class="lfr-search-bar"><span class="lfr-search-icon">🔎</span><input type="text" id="fr-q" placeholder="Rechercher un texte, article, décision…"><button class="lfr-search-submit" id="fr-search-btn">Rechercher</button></div>\
  <div class="lfr-search-options">\
    <div class="lfr-search-mode">\
      <label class="lfr-radio-label" title="Recherche l\'expression exacte telle que saisie"><input type="radio" name="fr-mode" value="EXACTE" id="fr-mode-exact"><span class="lfr-radio-pill">Expression exacte</span></label>\
      <label class="lfr-radio-label" title="Tous les mots doivent être présents (ignore les mots vides)"><input type="radio" name="fr-mode" value="TOUS_LES_MOTS_DANS_UN_CHAMP" id="fr-mode-all" checked><span class="lfr-radio-pill active">Tous les mots</span></label>\
      <label class="lfr-radio-label" title="Au moins un des mots suffit"><input type="radio" name="fr-mode" value="UN_DES_MOTS" id="fr-mode-any"><span class="lfr-radio-pill">Au moins un mot</span></label>\
    </div>\
    <div class="lfr-page-size-wrap">\
      <span style="font-size:11px;color:var(--gray-text)">Afficher</span>\
      <select id="fr-size" class="lfr-page-size-select"><option value="5">5</option><option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>\
      <span style="font-size:11px;color:var(--gray-text)">résultats</span>\
    </div>\
  </div>\
  <button class="lfr-advanced-toggle" id="fr-adv-toggle" style="margin-top:12px">▸ Options avancées</button>\
  <div class="lfr-advanced-panel" id="fr-adv-panel">\
    <div class="lfr-form-row">\
      <div class="lfr-field" id="fr-idcc-wrap" style="display:none"><label>IDCC</label><input id="fr-idcc" class="lfr-input" placeholder="Ex. 2098"></div>\
      <div class="lfr-field"><label>Date de version</label><input id="fr-date" type="date" class="lfr-input"></div>\
    </div>\
  </div>\
  <input type="hidden" id="fr-type" value="texte">\
  <div class="lfr-status-bar">\
    <span class="lfr-pill '+(status.service_active?'ok':'off')+'"><span class="dot"></span>'+(status.service_active?'Service actif':'Inactif')+'</span>\
    <span class="lfr-pill '+(status.client_configured?'ok':'warn')+'"><span class="dot"></span>'+(status.client_configured?'Configuré':'Non configuré')+'</span>\
    <span class="lfr-pill ok"><span class="dot"></span>'+_escFr(status.environment==='production'?'Production':'Sandbox')+'</span>\
  </div>\
</div>\
<div id="fr-results"></div>\
<div id="fr-detail"></div>';
}

function _bindFrancePage(status){
  var typeEl=$('fr-type'),qEl=$('fr-q'),dateEl=$('fr-date'),sizeEl=$('fr-size'),
      codePicker=$('fr-code-picker'),codeNameEl=$('fr-code-name'),codeSelectedEl=$('fr-code-selected'),
      idccWrap=$('fr-idcc-wrap'),idccEl=$('fr-idcc'),
      advToggle=$('fr-adv-toggle'),advPanel=$('fr-adv-panel'),detBox=$('fr-type-detail'),
      scopeIndicator=$('fr-scope-indicator'),scopeBadge=$('fr-scope-badge');
  function $(id){return document.getElementById(id);}

  // State for selected code
  var selectedCode={id:'',label:''};

  function updateScopeIndicator(){
    if(typeEl.value==='code'){
      scopeIndicator.style.display='';
      if(selectedCode.label){
        scopeBadge.innerHTML='🔍 Recherche dans <strong>'+_escFr(selectedCode.label)+'</strong>';
        scopeBadge.style.background='rgba(26,179,148,.1)';scopeBadge.style.borderColor='rgba(26,179,148,.3)';scopeBadge.style.color='#1ab394';
      }else{
        scopeBadge.innerHTML='🔍 Recherche dans <strong>tous les codes</strong>';
        scopeBadge.style.background='';scopeBadge.style.borderColor='';scopeBadge.style.color='';
      }
    }else{
      scopeIndicator.style.display='none';
    }
  };

  // Radio pill styling
  document.querySelectorAll('input[name="fr-mode"]').forEach(function(radio){
    radio.addEventListener('change',function(){
      document.querySelectorAll('.lfr-radio-pill').forEach(function(p){p.classList.remove('active');});
      if(radio.checked)radio.nextElementSibling.classList.add('active');
    });
  });
  function getSearchMode(){
    var checked=document.querySelector('input[name="fr-mode"]:checked');
    return checked?checked.value:'TOUS_LES_MOTS_DANS_UN_CHAMP';
  }

  var typeCards=document.querySelectorAll('.lfr-type-card');
  function selectType(key){
    typeCards.forEach(function(c){c.classList.remove('active');});
    var card=document.querySelector('.lfr-type-card[data-fr-type="'+key+'"]');
    if(card)card.classList.add('active');
    typeEl.value=key;
    var info=FR_TYPES[key];
    if(info&&detBox){
      detBox.innerHTML='<div style="display:flex;align-items:flex-start;gap:12px"><span style="font-size:20px">'+info.icon+'</span><div><div style="font-weight:700;color:var(--navy);margin-bottom:4px">'+_escFr(info.titre)+'</div><div style="font-size:12px;color:var(--text-mid);line-height:1.6">'+_escFr(info.detail)+'</div><div style="font-size:11px;color:var(--gray-text);margin-top:6px">Fonds API : <span style="font-family:\'DM Mono\',monospace">'+_escFr(info.fond+(info.fondDate?' / '+info.fondDate:''))+'</span></div></div></div>';
      detBox.style.display='';
    }
    // Show/hide code picker
    codePicker.style.display=(key==='code')?'':'none';
    idccWrap.style.display=(info&&info.hasIdcc)?'':'none';
    if(info&&info.hasIdcc){advPanel.classList.add('open');advToggle.textContent='▾ Options avancées';}
    // Update placeholder
    if(key==='code'){
      qEl.placeholder=selectedCode.label?'Rechercher dans '+selectedCode.label+'…':'Rechercher un article ou thème (tous les codes)…';
    }else{
      qEl.placeholder='Rechercher un texte, article, décision…';
    }
    updateScopeIndicator();
  }
  typeCards.forEach(function(c){c.addEventListener('click',function(){selectType(c.dataset.frType);});});
  selectType('texte');

  document.querySelectorAll('.lfr-type-ex').forEach(function(x){
    x.addEventListener('click',function(e){e.stopPropagation();qEl.value=x.dataset.frExample;qEl.focus();});
  });
  advToggle.addEventListener('click',function(){var o=advPanel.classList.toggle('open');advToggle.textContent=o?'▾ Options avancées':'▸ Options avancées';});

  // ── Code picker autocomplete ──
  var suggestBox=$('fr-code-suggestions');
  var suggestTimer=null;
  var allCodesCache=null; // preloaded list of all codes
  var allCodesLoading=false;

  // Extract codes array from various API response formats
  function _extractCodesArray(data){
    if(!data)return[];
    // Direct array response
    if(Array.isArray(data))return data;
    // {results: [...]}
    if(Array.isArray(data.results))return data.results;
    // {data: [...]} or {data: {results: [...]}}
    if(data.data){
      if(Array.isArray(data.data))return data.data;
      if(Array.isArray(data.data.results))return data.data.results;
    }
    // {list: [...]}
    if(Array.isArray(data.list))return data.list;
    // {codes: [...]}
    if(Array.isArray(data.codes))return data.codes;
    return[];
  }

  // Normalize a single code item from the API
  function _normalizeCodeItem(item){
    if(!item||typeof item!=='object')return null;
    var id=item.id||item.textId||item.cid||'';
    var label=item.titre||item.title||item.titreLong||item.label||item.nom||'';
    var etat=item.etat||item.legalStatus||item.state||'';
    if(!id||!label)return null;
    return{id:id,label:_cleanMark(label),etat:etat};
  }

  // Preload all codes once
  async function _preloadCodes(){
    if(allCodesCache)return allCodesCache;
    if(allCodesLoading)return null;
    allCodesLoading=true;
    try{
      var data=await LegifranceApi.consult('/list/code',{pageSize:200,pageNumber:1});
      var raw=_extractCodesArray(data);
      var codes=[];
      raw.forEach(function(item){
        var c=_normalizeCodeItem(item);
        if(c)codes.push(c);
      });
      // Sort alphabetically
      codes.sort(function(a,b){return a.label.localeCompare(b.label,'fr');});
      allCodesCache=codes;
      return codes;
    }catch(e){
      console.warn('Preload codes failed:',e.message);
      return null;
    }finally{
      allCodesLoading=false;
    }
  }

  function _selectCode(id,label){
    selectedCode={id:id,label:label};
    codeNameEl.value='';
    suggestBox.innerHTML='';suggestBox.style.display='none';
    codeSelectedEl.innerHTML='<div class="lfr-code-chip"><span class="lfr-code-chip-icon">📖</span><span class="lfr-code-chip-label">'+_escFr(label)+'</span><span class="lfr-code-chip-id">'+_escFr(id)+'</span><button class="lfr-code-chip-remove" title="Changer de code">✕</button></div>';
    codeSelectedEl.style.display='';
    codeNameEl.style.display='none';
    qEl.placeholder='Rechercher dans '+label+'…';
    qEl.focus();
    updateScopeIndicator();
    // Bind remove
    codeSelectedEl.querySelector('.lfr-code-chip-remove').addEventListener('click',function(){
      selectedCode={id:'',label:''};
      codeSelectedEl.innerHTML='';codeSelectedEl.style.display='none';
      codeNameEl.style.display='';codeNameEl.value='';
      qEl.placeholder='Rechercher un article ou thème (tous les codes)…';
      updateScopeIndicator();
      codeNameEl.focus();
    });
  }

  // On focus: preload codes and show all if empty input
  codeNameEl.addEventListener('focus',async function(){
    var codes=await _preloadCodes();
    if(codes&&!codeNameEl.value.trim()){
      _frShowCodeSuggestions(codes,'');
    }
  });

  codeNameEl.addEventListener('input',function(){
    var val=codeNameEl.value.trim();
    if(suggestTimer)clearTimeout(suggestTimer);
    // Filter locally from preloaded cache
    if(allCodesCache){
      if(val.length<1){_frShowCodeSuggestions(allCodesCache,'');return;}
      var vl=val.toLowerCase();
      var filtered=allCodesCache.filter(function(c){return c.label.toLowerCase().indexOf(vl)>=0;});
      _frShowCodeSuggestions(filtered,val);
      return;
    }
    // Fallback: load from API if cache not ready
    if(val.length<2){suggestBox.innerHTML='';suggestBox.style.display='none';return;}
    suggestTimer=setTimeout(async function(){
      var codes=await _preloadCodes();
      if(codes){
        var vl=val.toLowerCase();
        var filtered=codes.filter(function(c){return c.label.toLowerCase().indexOf(vl)>=0;});
        _frShowCodeSuggestions(filtered,val);
      }else{
        suggestBox.innerHTML='<div class="lfr-autocomplete-empty">Impossible de charger la liste des codes</div>';
        suggestBox.style.display='block';
      }
    },200);
  });

  function _frShowCodeSuggestions(results,query){
    if(!results.length){suggestBox.innerHTML='<div class="lfr-autocomplete-empty">Aucun code trouvé</div>';suggestBox.style.display='block';return;}
    var ql=(query||'').toLowerCase();
    suggestBox.innerHTML=results.slice(0,30).map(function(r){
      var label=_cleanMark(r.label);
      var display;
      if(ql){
        var idx=label.toLowerCase().indexOf(ql);
        display=idx>=0?_escFr(label.slice(0,idx))+'<strong>'+_escFr(label.slice(idx,idx+ql.length))+'</strong>'+_escFr(label.slice(idx+ql.length)):_escFr(label);
      }else{
        display=_escFr(label);
      }
      return'<div class="lfr-autocomplete-item" data-lfr-code-id="'+_escFr(r.id)+'" data-lfr-code-label="'+_escFr(label)+'">'+display+'</div>';
    }).join('');
    suggestBox.style.display='block';
    suggestBox.querySelectorAll('.lfr-autocomplete-item').forEach(function(item){
      item.addEventListener('click',function(){
        _selectCode(item.dataset.lfrCodeId,item.dataset.lfrCodeLabel);
      });
    });
  }

  document.addEventListener('click',function(e){
    if(!e.target.closest('.lfr-autocomplete-wrap')){suggestBox.innerHTML='';suggestBox.style.display='none';}
  });
  codeNameEl.addEventListener('keydown',function(e){
    if(e.key==='Escape'){suggestBox.innerHTML='';suggestBox.style.display='none';}
  });

  var doSearch=async function(){
    var q=qEl.value.trim();
    if(!q){if(typeof toast==='function')toast('Saisis une recherche.',false);qEl.focus();return;}
    var rEl=$('fr-results'),dEl=$('fr-detail');
    dEl.innerHTML='';
    rEl.innerHTML='<div class="lfr-section"><div style="display:grid;gap:10px"><div class="lfr-skeleton" style="height:52px"></div><div class="lfr-skeleton" style="height:52px"></div><div class="lfr-skeleton" style="height:52px"></div></div></div>';
    try{
      var payload=_frBuildPayload({kind:typeEl.value,q:q,date:dateEl.value,pageSize:Number(sizeEl.value||10),codeName:selectedCode.label,idcc:idccEl.value,searchMode:getSearchMode()});
      var data=await LegifranceApi.search(payload);
      var items=_frExtractResults(data);
      rEl.innerHTML=_frRenderResults(data,items);
      _frBindResults(items);
    }catch(e){
      rEl.innerHTML='<div class="lfr-section"><div style="display:flex;align-items:flex-start;gap:14px"><span style="font-size:22px">❌</span><div><div style="font-weight:700;margin-bottom:4px">Erreur de recherche</div><div style="font-size:13px;color:var(--gray-text)">'+_escFr(e.message)+'</div></div></div></div>';
    }
  };
  $('fr-search-btn').addEventListener('click',doSearch);
  qEl.addEventListener('keydown',function(e){if(e.key==='Enter')doSearch();});
  dateEl.value=new Date().toISOString().slice(0,10);
}

/* ── Extract article number from various formats ──────── */
function _frExtractArticleNum(q){
  // Strip leading "Article" or "Art." prefix (case-insensitive)
  var hadPrefix=/^(?:articles?\s|art\.?\s*)/i.test(q);
  var cleaned=q.replace(/^(?:articles?\s+|art\.?\s*)/i,'').trim();
  // Match patterns starting with L/R/D/A prefix (most reliable code article indicator)
  // e.g. L1224-1, R.4224-17, D312-1, A.1
  if(/^[LRDA]\.?\s*\d+[\w\-.]*$/i.test(cleaned)){
    return cleaned.replace(/\s+/g,'').replace(/^([LRDA])\.?/i,'$1');
  }
  // Purely numeric article numbers ONLY if explicitly prefixed with "Article" or "Art."
  // This avoids false positives on decree numbers like "2024-741"
  if(hadPrefix&&/^\d+[\w\-.]*$/.test(cleaned)){
    return cleaned.replace(/\s+/g,'');
  }
  return null;
}

/* ── Build payload ──────────────────────────────────────── */
function _frBuildPayload(o){
  var kind=o.kind,q=String(o.q||'').trim(),date=o.date,ps=o.pageSize,cn=o.codeName,idcc=o.idcc;
  var mode=o.searchMode||'TOUS_LES_MOTS_DANS_UN_CHAMP';
  var dTs=date?new Date(date+'T00:00:00').getTime():Date.now();

  // ── GLOBAL article number detection ──
  // If the query looks like a code article number (L1224-1, Article R4224-17, etc.)
  // we FORCE a code search regardless of the selected type
  var artNum=_frExtractArticleNum(q);
  if(artNum){
    let b={recherche:{champs:[],filtres:[],pageNumber:1,pageSize:ps||10,operateur:'ET',sort:'PERTINENCE',typePagination:'ARTICLE',fromAdvancedRecherche:false}};
    b.fond=date?'CODE_DATE':'CODE_ETAT';
    b.recherche.champs.push({typeChamp:'NUM_ARTICLE',operateur:'ET',criteres:[{valeur:artNum,typeRecherche:'EXACTE',operateur:'ET'}]});
    if(cn&&cn.trim()){
      b.recherche.filtres.push({facette:'NOM_CODE',valeurs:[cn.trim()]});
    }
    if(date)b.recherche.filtres.push({facette:'DATE_VERSION',singleDate:dTs});
    return b;
  }

  var isNumeric=/^\d{2,4}-\d+/.test(q);
  var isNumAffaire=/^\d/.test(q);
  var typeInfo=FR_TYPES[kind]||FR_TYPES.texte;
  let b={recherche:{champs:[],filtres:[],pageNumber:1,pageSize:ps||10,operateur:'ET',sort:'PERTINENCE',typePagination:'DEFAUT',fromAdvancedRecherche:false}};

  // Determine fond
  if(typeInfo.fondDate&&date) b.fond=typeInfo.fondDate;
  else b.fond=typeInfo.fond;

  // ── Code: text search within codes (non-article queries) ──
  if(kind==='code'){
    var codeMode=(mode==='TOUS_LES_MOTS_DANS_UN_CHAMP')?'UN_DES_MOTS':mode;
    b.recherche.champs.push({typeChamp:'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:codeMode,operateur:'ET'}]});
    if(cn&&cn.trim()){
      b.recherche.filtres.push({facette:'NOM_CODE',valeurs:[cn.trim()]});
    }
    if(date)b.recherche.filtres.push({facette:'DATE_VERSION',singleDate:dTs});
    return b;
  }

  // ── Jurisprudence judiciaire: NUM_AFFAIRE detection ──
  if(kind==='jurisprudence'){
    b.recherche.secondSort='DATE_DESC';b.recherche.sort=isNumAffaire?'DATE_DESC':'PERTINENCE';
    b.recherche.champs.push({typeChamp:isNumAffaire?'NUM_AFFAIRE':'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:isNumAffaire?'EXACTE':mode,operateur:'ET'}]});
    return b;
  }

  // ── Jurisprudence administrative (CETAT): NUM_DEC detection ──
  if(kind==='cetat'){
    b.recherche.secondSort='DATE_DESC';
    var isDec=/^\d/.test(q);
    b.recherche.champs.push({typeChamp:isDec?'NUM_DEC':'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:isDec?'EXACTE':mode,operateur:'ET'}]});
    return b;
  }

  // ── Conseil constitutionnel: NUM_DEC detection ──
  if(kind==='constit'){
    var isDecNum=/^\d/.test(q);
    b.recherche.champs.push({typeChamp:isDecNum?'NUM_DEC':'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:isDecNum?'EXACTE':mode,operateur:'ET'}]});
    return b;
  }

  // ── CNIL: NUM_DELIB detection ──
  if(kind==='cnil'){
    var isDelib=/^\d/.test(q);
    b.recherche.champs.push({typeChamp:isDelib?'NUM_DELIB':'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:isDelib?'EXACTE':mode,operateur:'ET'}]});
    return b;
  }

  // ── Convention collective (KALI): IDCC + TITLE ──
  if(kind==='convention'){
    b.recherche.secondSort='PERTINENCE';
    if(idcc&&idcc.trim())b.recherche.champs.push({typeChamp:'IDCC',operateur:'ET',criteres:[{valeur:idcc.trim(),typeRecherche:'TOUS_LES_MOTS_DANS_UN_CHAMP',operateur:'ET'}]});
    b.recherche.champs.push({typeChamp:'TITLE',operateur:'ET',criteres:[{valeur:q,typeRecherche:mode,operateur:'ET'}]});
    return b;
  }

  // ── Accords d'entreprise (ACCO) ──
  if(kind==='acco'){
    b.recherche.champs.push({typeChamp:'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:mode,operateur:'ET'}]});
    return b;
  }

  // ── Circulaires ──
  if(kind==='circulaire'){
    b.recherche.champs.push({typeChamp:'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:mode,operateur:'ET'}]});
    return b;
  }

  // ── JORF ──
  if(kind==='jorf'){
    var isNor=/^[A-Z]{4}\d{7}[A-Z]$/i.test(q.replace(/\s/g,''));
    b.recherche.champs.push({typeChamp:isNor?'NOR':(isNumeric?'NUM':'ALL'),operateur:'ET',criteres:[{valeur:q,typeRecherche:(isNor||isNumeric)?'EXACTE':mode,operateur:'ET'}]});
    return b;
  }

  // ── Default: texte (LODA) ──
  b.recherche.champs.push({typeChamp:isNumeric?'NUM':'ALL',operateur:'ET',criteres:[{valeur:q,typeRecherche:isNumeric?'EXACTE':mode,operateur:'ET'}]});
  if(date)b.recherche.filtres.push({facette:'DATE_VERSION',singleDate:dTs});
  b.recherche.filtres.push({facette:'TEXT_LEGAL_STATUS',valeur:'VIGUEUR'});
  return b;
}

/* ── Extract results ────────────────────────────────────── */
function _frExtractResults(data){
  var arr=Array.isArray(data&&data.results)?data.results:[];
  var out=[];
  arr.forEach(function(item,idx){
    var ft=(Array.isArray(item.titles)&&item.titles.length)?item.titles[0]:{};
    var titre=_cleanMark(ft.title||item.raisonSociale||item.jorfText||'');
    if(!titre)titre='Résultat '+(idx+1);
    var id=ft.id||'',cid=ft.cid||'',nature=ft.nature||item.nature||'',etat=ft.legalStatus||item.etat||'';
    var extracts=[];
    if(Array.isArray(item.sections)){item.sections.forEach(function(sec){
      if(Array.isArray(sec.extracts)){sec.extracts.forEach(function(ext){
        extracts.push({title:_cleanMark(ext.title||ext.num||sec.title||''),id:ext.id||sec.id||'',etat:ext.legalStatus||sec.legalStatus||'',values:Array.isArray(ext.values)?ext.values.map(_cleanMark):[],num:_cleanMark(ext.num||'')});
      });}
    });}
    var resume=_stripHtml((Array.isArray(item.resumePrincipal)?item.resumePrincipal.join(' '):'')|| (Array.isArray(item.autreResume)?item.autreResume.join(' '):'')||item.text||item.descriptionFusionHtml||'');

    // ── Flatten: if this is a CODE (LEGITEXT) result with LEGIARTI extracts,
    //    promote each article extract as a top-level result so it's directly clickable
    var hasArticleExtracts=(/^LEGITEXT/i.test(id))&&extracts.length&&extracts.some(function(e){return/^LEGIARTI/i.test(e.id);});
    if(hasArticleExtracts){
      extracts.forEach(function(ext){
        if(!ext.id)return;
        var artTitre=(ext.num?'Article '+ext.num:ext.title)||'Article';
        var artResume=ext.values.length?_stripHtml(ext.values.join(' ')).slice(0,500):'';
        var artUrl=_legiUrl(ext.id);
        out.push({
          raw:item,id:ext.id,cid:cid,
          titre:artTitre,
          nature:nature,etat:ext.etat||etat,
          num:ext.num||'',
          date:item.dateSignature||item.date||item.datePublication||'',
          resume:artResume,nor:'',extracts:[],
          parentCode:titre,parentCodeId:id,
          consult:/^LEGIARTI/i.test(ext.id)?{endpoint:'/consult/getArticle',payload:{id:ext.id}}:_frConsult(ext.id,'')
        });
      });
      return; // skip the parent code entry
    }

    out.push({raw:item,id:id,cid:cid,titre:titre,nature:nature,etat:etat,num:item.num||item.numParution||item.idcc||'',date:item.dateSignature||item.date||item.datePublication||'',resume:resume,nor:item.nor||'',extracts:extracts,consult:_frConsult(id,cid)});
  });
  return out;
}
function _frConsult(id,cid){
  if(/^LEGIARTI/i.test(id))return{endpoint:'/consult/getArticle',payload:{id:id}};
  if(/^LEGITEXT/i.test(id))return{endpoint:'/consult/legiPart',payload:{textId:id,date:Date.now()}};
  if(/^JORFTEXT/i.test(id))return{endpoint:'/consult/jorf',payload:{textCid:id}};
  if(/^JURITEXT/i.test(id))return{endpoint:'/consult/juri',payload:{textId:id}};
  if(/^CETATEXT/i.test(id))return{endpoint:'/consult/juri',payload:{textId:id}};
  if(/^CNILTEXT/i.test(id))return{endpoint:'/consult/cnil',payload:{textId:id}};
  if(/^CONSTTEXT/i.test(id))return{endpoint:'/consult/juri',payload:{textId:id}};
  if(/^KALITEXT/i.test(id))return{endpoint:'/consult/kaliCont',payload:{id:id}};
  if(/^KALICONT/i.test(id))return{endpoint:'/consult/kaliCont',payload:{id:id}};
  if(/^ACCOTEXT/i.test(id))return{endpoint:'/consult/acco',payload:{id:id}};
  if(/^LEGITEXT/i.test(cid))return{endpoint:'/consult/legiPart',payload:{textId:cid,date:Date.now()}};
  if(/^JORFTEXT/i.test(cid))return{endpoint:'/consult/jorf',payload:{textCid:cid}};
  return null;
}

/* ── Render results ─────────────────────────────────────── */
function _frRenderResults(data,items){
  var total=items.length||Number(data&&data.totalResultNumber||0);
  if(!items.length)return'<div class="lfr-section"><div class="lfr-empty"><div class="lfr-empty-icon">📭</div><div class="lfr-empty-title">Aucun résultat</div><div class="lfr-empty-desc">Essaye avec d\'autres mots-clés ou un type de recherche différent.</div></div></div>';
  var rows=items.map(function(item,idx){
    var ep='';
    if(item.extracts.length){
      var f3=item.extracts.slice(0,3);
      ep='<div class="lfr-result-extracts">'+f3.map(function(ext){
        var lbl=ext.num||ext.title||'';var val=ext.values.length?_stripHtml(ext.values[0]).slice(0,120):'';
        return'<div class="lfr-result-extract-item">'+(lbl?'<span class="lfr-extract-num">'+_escFr(lbl)+'</span>':'')+(val?'<span class="lfr-extract-text">'+_escFr(val)+(val.length>=120?'…':'')+'</span>':'')+'</div>';
      }).join('')+(item.extracts.length>3?'<div style="font-size:11px;color:var(--gray-text);margin-top:4px">+ '+(item.extracts.length-3)+' autre(s)…</div>':'')+'</div>';
    }
    var url=_legiUrl(item.id);
    return'<div class="lfr-result-item" data-fr-index="'+idx+'"><div style="min-width:0">\
<div class="lfr-result-title">'+_escFr(item.titre)+'</div>\
<div class="lfr-result-meta">'+_frBadge(item.etat)+(item.parentCode?'<span class="lfr-badge lfr-badge-blue lfr-badge-sm">📖 '+_escFr(item.parentCode)+'</span>':'')+(item.nature?'<span class="lfr-badge lfr-badge-sm">'+_escFr(item.nature)+'</span>':'')+(item.num?'<span class="lfr-badge lfr-badge-blue lfr-badge-sm">n° '+_escFr(item.num)+'</span>':'')+(item.nor?'<span class="lfr-badge lfr-badge-sm" style="font-family:\'DM Mono\',monospace">'+_escFr(item.nor)+'</span>':'')+(item.date?'<span style="font-size:11px;color:var(--gray-text)">'+_escFr(_fmtDate(item.date))+'</span>':'')+(url?'<a href="'+url+'" target="_blank" rel="noopener noreferrer" style="font-size:11px;color:var(--blue);text-decoration:none;margin-left:auto" onclick="event.stopPropagation()">Voir sur Légifrance ↗</a>':'')+'</div>\
'+(item.resume?'<div class="lfr-result-excerpt">'+_escFr(item.resume.slice(0,280))+'</div>':'')+ep+'</div>\
<div class="lfr-result-arrow">'+(item.consult?'→':'')+'</div></div>';
  }).join('');
  return'<div class="lfr-section"><div class="lfr-results-header"><div class="lfr-section-title">Résultats</div><span class="lfr-results-count">'+total+' résultat'+(total>1?'s':'')+'</span></div><div class="lfr-result-list">'+rows+'</div></div>';
}

/* ── Bind result clicks ─────────────────────────────────── */
function _frBindResults(items){
  document.querySelectorAll('.lfr-result-item').forEach(function(el){
    el.addEventListener('click',async function(e){
      // Ne pas intercepter les clics sur les liens externes
      if(e.target.closest('a'))return;
      var idx=Number(el.dataset.frIndex),item=items[idx];if(!item)return;
      document.querySelectorAll('.lfr-result-item').forEach(function(r){r.classList.remove('active');});
      el.classList.add('active');
      var dEl=document.getElementById('fr-detail');
      if(!item.consult){dEl.innerHTML=_frRenderSearchDetail(item);_frBindClose();dEl.scrollIntoView({behavior:'smooth',block:'start'});return;}
      dEl.innerHTML='<div class="lfr-section"><div style="display:grid;gap:10px"><div class="lfr-skeleton" style="height:28px;width:70%"></div><div class="lfr-skeleton" style="height:200px"></div></div></div>';
      dEl.scrollIntoView({behavior:'smooth',block:'start'});
      try{
        var p=Object.assign({},item.consult.payload);
        if(item.consult.endpoint==='/consult/legiPart'&&!p.date)p.date=Date.now();
        var data=await LegifranceApi.consult(item.consult.endpoint,p);
        dEl.innerHTML=_frRenderDetail(item,data);
        _frBindDetail(item,data);
      }catch(e){
        dEl.innerHTML='<div class="lfr-section"><div style="display:flex;gap:14px"><span style="font-size:22px">❌</span><div><div style="font-weight:700;margin-bottom:4px">Erreur</div><div style="font-size:13px;color:var(--gray-text)">'+_escFr(e.message)+'</div></div></div></div>';
      }
    });
  });
}

/* ── Search-only detail ─────────────────────────────────── */
function _frRenderSearchDetail(item){
  var h='<div class="lfr-detail" style="margin-top:20px">'+_frHead(item)+'<div class="lfr-detail-body">';
  if(item.resume)h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Résumé</div><div style="font-size:13px;line-height:1.65;color:var(--text)">'+_escFr(item.resume)+'</div></div>';
  if(item.extracts.length){
    h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Articles trouvés ('+item.extracts.length+')</div>';
    item.extracts.forEach(function(ext){
      var url=_legiUrl(ext.id);
      h+='<div class="lfr-detail-section-item"><div style="display:flex;align-items:center;gap:8px;margin-bottom:4px"><span style="font-weight:700;color:var(--navy)">'+_escFr(ext.num||ext.title||ext.id)+'</span>'+_frBadge(ext.etat)+(url?'<a href="'+url+'" target="_blank" rel="noopener noreferrer" class="lfr-open-link" onclick="event.stopPropagation()">Ouvrir ↗</a>':'')+'</div>';
      if(ext.values.length)h+='<div style="font-size:12px;color:var(--text-mid);line-height:1.6">'+_escFr(_stripHtml(ext.values.join(' ')).slice(0,500))+'</div>';
      h+='</div>';
    });
    h+='</div>';
  }
  h+='</div>'+_frFoot(item)+'</div>';
  return h;
}

/* ═══════════════════════════════════════════════════════ */
/*  FULL DETAIL — recursive sections, clickable articles   */
/* ═══════════════════════════════════════════════════════ */
function _frRenderDetail(item,data){
  var t=data||{};
  if(t.text&&(t.text.titre||t.text.titreLong))t=t.text;
  if(t.article&&t.article.id){var a=t.article;t={titre:a.fullSectionsTitre||('Article '+(a.num||a.id)),texteHtml:a.texteHtml||a.texte,etat:a.etat,num:a.num,id:a.id,nota:a.nota,notaHtml:a.notaHtml,dateDebut:a.dateDebut,dateFin:a.dateFin};}

  var titre=_cleanMark(t.title||t.titre||t.titreLong||item.titre||'Document');
  var visa=t.visa||t.visas||t.visasHtml||'';
  var notice=t.notice||t.noticeHtml||'';
  var body=t.texteHtml||t.content||t.texte||'';
  var nota=t.notaHtml||t.nota||'';
  var resume=t.resume||t.resumeHtml||'';
  var etat=t.etat||t.jurisState||item.etat||'';
  var nature=t.nature||item.nature||'';
  var nor=t.nor||item.nor||'';
  var dateT=t.dateTexte||t.dateDebut||item.date||'';
  var eli=t.eli||t.idEli||'';
  var solution=t.solution||'';var formation=t.formation||'';var juridiction=t.juridiction||t.juridictionJudiciaire||'';

  var sections=Array.isArray(t.sections)?t.sections:(Array.isArray(data&&data.sections)?data.sections:[]);
  var articles=Array.isArray(t.articles)?t.articles:(Array.isArray(data&&data.articles)?data.articles:[]);
  var liens=Array.isArray(t.liens)?t.liens:(Array.isArray(data&&data.liens)?data.liens:[]);

  var h='<div class="lfr-detail" style="margin-top:20px">';

  // Head
  h+='<div class="lfr-detail-head"><div class="lfr-detail-head-title">'+_escFr(titre)+'</div><div class="lfr-detail-head-meta">';
  if(etat)h+='<span class="lfr-detail-head-pill">'+_escFr(etat)+'</span>';
  if(item.parentCode)h+='<span class="lfr-detail-head-pill">📖 '+_escFr(item.parentCode)+'</span>';
  if(nature)h+='<span class="lfr-detail-head-pill">'+_escFr(nature)+'</span>';
  if(item.num)h+='<span class="lfr-detail-head-pill">n° '+_escFr(item.num)+'</span>';
  if(nor)h+='<span class="lfr-detail-head-pill" style="font-family:\'DM Mono\',monospace;font-size:10px">NOR '+_escFr(nor)+'</span>';
  if(dateT)h+='<span class="lfr-detail-head-pill">'+_escFr(_fmtDate(dateT))+'</span>';
  var mainUrl=_legiUrl(item.id);
  if(mainUrl)h+='<a href="'+mainUrl+'" target="_blank" rel="noopener noreferrer" class="lfr-detail-head-pill" style="text-decoration:none;cursor:pointer">Voir sur Légifrance ↗</a>';
  h+='</div></div>';

  h+='<div class="lfr-detail-body">';

  // Juri metadata
  if(juridiction||solution||formation){
    h+='<div class="lfr-detail-block" style="display:flex;gap:12px;flex-wrap:wrap">';
    if(juridiction)h+='<div class="lfr-meta-chip"><span class="lfr-meta-chip-label">Juridiction</span>'+_escFr(juridiction)+'</div>';
    if(formation)h+='<div class="lfr-meta-chip"><span class="lfr-meta-chip-label">Formation</span>'+_escFr(formation)+'</div>';
    if(solution)h+='<div class="lfr-meta-chip"><span class="lfr-meta-chip-label">Solution</span>'+_escFr(solution)+'</div>';
    h+='</div>';
  }

  if(resume)h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Résumé</div><div class="lfr-detail-content">'+resume+'</div></div>';
  if(visa)h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Visa</div><div class="lfr-detail-content">'+visa+'</div></div>';
  if(notice)h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Notice</div><div class="lfr-detail-content">'+notice+'</div></div>';
  if(body)h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Contenu</div><div class="lfr-detail-content">'+body+'</div></div>';
  if(nota)h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Nota</div><div class="lfr-detail-content" style="font-size:12px;color:var(--gray-text)">'+nota+'</div></div>';

  // Articles at root level
  if(articles.length){
    h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Articles ('+articles.length+')</div>';
    h+=_frRenderArticleList(articles);
    h+='</div>';
  }

  // Sections — recursive tree
  if(sections.length){
    h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Table des matières</div>';
    h+=_frRenderSectionTree(sections,0);
    h+='</div>';
  }

  // Liens
  if(liens.length){
    h+='<div class="lfr-detail-block"><div class="lfr-detail-label">Liens ('+liens.length+')</div>';
    liens.slice(0,30).forEach(function(l){
      var lid=l.id||l.cidTexte||'';
      var lUrl=_legiUrl(lid);
      h+='<div class="lfr-detail-link">';
      h+='<span class="lfr-detail-link-icon">🔗</span>';
      if(lUrl)h+='<a href="'+lUrl+'" target="_blank" rel="noopener noreferrer" style="color:var(--blue);text-decoration:none;flex:1">'+_escFr(_cleanMark(l.title||l.numTexte||lid||'Lien'))+'</a>';
      else h+='<span style="flex:1">'+_escFr(_cleanMark(l.title||l.numTexte||lid||'Lien'))+'</span>';
      if(l.typeLien)h+='<span class="lfr-badge lfr-badge-sm">'+_escFr(l.typeLien)+'</span>';
      if(l.sens)h+='<span class="lfr-badge lfr-badge-sm">'+_escFr(l.sens)+'</span>';
      h+='</div>';
    });
    h+='</div>';
  }

  // Fallback
  if(!body&&!visa&&!notice&&!resume&&!articles.length&&!sections.length){
    h+='<div style="font-size:13px;color:var(--gray-text);line-height:1.65">'+(item.resume?_escFr(item.resume):'Aucun contenu disponible pour ce document.')+'</div>';
  }

  h+='</div>';
  h+=_frFoot(item);
  h+='</div>';
  return h;
}

/* ── Recursive section tree ─────────────────────────────── */
function _frRenderSectionTree(sections,depth){
  if(!sections||!sections.length)return'';
  var h='<div class="lfr-tree" style="'+(depth>0?'margin-left:16px;border-left:2px solid var(--gray-border);padding-left:12px':'')+'">';
  sections.forEach(function(sec){
    var hasChildren=(Array.isArray(sec.sections)&&sec.sections.length)||(Array.isArray(sec.articles)&&sec.articles.length);
    var uid='lfr-sec-'+Math.random().toString(36).substr(2,8);
    h+='<div class="lfr-tree-node">';
    h+='<div class="lfr-tree-header'+(hasChildren?' lfr-tree-expandable':'')+'" data-lfr-toggle="'+uid+'">';
    if(hasChildren)h+='<span class="lfr-tree-arrow" data-lfr-arrow="'+uid+'">▸</span>';
    else h+='<span style="width:16px;display:inline-block"></span>';
    h+='<span style="font-weight:600;color:var(--navy);flex:1">'+_escFr(_cleanMark(sec.title||sec.id||'Section'))+'</span>';
    h+=_frBadge(sec.etat);
    h+='</div>';
    h+='<div class="lfr-tree-children" id="'+uid+'" style="display:none">';
    if(Array.isArray(sec.articles)&&sec.articles.length) h+=_frRenderArticleList(sec.articles);
    if(Array.isArray(sec.sections)&&sec.sections.length) h+=_frRenderSectionTree(sec.sections,depth+1);
    h+='</div></div>';
  });
  h+='</div>';
  return h;
}

/* ── Article list (within a section or at root) ──────────── */
function _frRenderArticleList(articles){
  var h='';
  articles.slice(0,60).forEach(function(art){
    var content=art.content||art.texteHtml||art.texte||'';
    var num=art.num||'';
    var artEtat=art.etat||'';
    var artId=art.id||'';
    var artUrl=_legiUrl(artId);
    var uid='lfr-art-'+Math.random().toString(36).substr(2,8);
    var hasContent=!!content;

    // Date: show dateDebut, ignore placeholder dateFin
    var dateLabel='';
    var dDebut=_fmtDate(art.dateDebut);
    var dFin=_fmtDate(art.dateFin);
    if(dDebut&&dFin) dateLabel='Du '+dDebut+' au '+dFin;
    else if(dDebut) dateLabel='Depuis le '+dDebut;

    // Modification info
    var modInfo='';
    if(art.modificatorTitle||art.modificatorCid){
      var modUrl=_legiUrl(art.modificatorCid||'');
      var modLabel=_cleanMark(art.modificatorTitle||art.modificatorCid||'Texte modificateur');
      var modDate=_fmtDate(art.modificatorDate);
      modInfo='<div class="lfr-article-mod">';
      modInfo+='<span style="font-size:10px;color:var(--gray-text)">Modifié par : </span>';
      if(modUrl)modInfo+='<a href="'+modUrl+'" target="_blank" rel="noopener noreferrer" style="font-size:10px;color:var(--blue);text-decoration:none" onclick="event.stopPropagation()">'+_escFr(modLabel)+'</a>';
      else modInfo+='<span style="font-size:10px;color:var(--text-mid)">'+_escFr(modLabel)+'</span>';
      if(modDate)modInfo+=' <span style="font-size:10px;color:var(--gray-text)">('+modDate+')</span>';
      modInfo+='</div>';
    }

    // Version info
    var versionInfo='';
    if(art.multipleVersions&&art.articleVersion){
      versionInfo='<span class="lfr-badge lfr-badge-sm" style="font-size:9px">v'+_escFr(art.articleVersion)+'</span>';
    }

    h+='<div class="lfr-article-item">';
    h+='<div class="lfr-article-header'+(hasContent?' lfr-article-expandable':'')+'" data-lfr-art-toggle="'+uid+'">';
    h+='<div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;flex-wrap:wrap">';
    if(hasContent)h+='<span class="lfr-tree-arrow" data-lfr-art-arrow="'+uid+'">▸</span>';
    h+='<span style="font-weight:700;color:var(--navy);white-space:nowrap">'+_escFr(num?'Art. '+num:(artId||'Article'))+'</span>';
    h+=_frBadge(artEtat);
    h+=versionInfo;
    if(dateLabel)h+='<span style="font-size:10px;color:var(--gray-text)">'+_escFr(dateLabel)+'</span>';
    h+='</div>';
    if(artUrl)h+='<a href="'+artUrl+'" target="_blank" rel="noopener noreferrer" class="lfr-open-link" onclick="event.stopPropagation()">Ouvrir ↗</a>';
    h+='</div>';
    if(modInfo)h+='<div style="padding:0 14px 6px;background:var(--white)">'+modInfo+'</div>';
    if(hasContent) h+='<div class="lfr-article-content" id="'+uid+'" style="display:none"><div class="lfr-detail-content" style="max-height:300px">'+content+'</div></div>';
    h+='</div>';
  });
  return h;
}

/* ── Bind detail ────────────────────────────────────────── */
function _frBindDetail(item,data){
  _frBindClose();
  // Section toggle
  document.querySelectorAll('.lfr-tree-expandable').forEach(function(el){
    el.addEventListener('click',function(e){
      if(e.target.closest('a'))return;
      var id=el.dataset.lfrToggle;
      var ch=document.getElementById(id);if(!ch)return;
      var open=ch.style.display!=='none';
      ch.style.display=open?'none':'';
      var arrow=document.querySelector('[data-lfr-arrow="'+id+'"]');
      if(arrow)arrow.textContent=open?'▸':'▾';
    });
  });
  // Article toggle
  document.querySelectorAll('.lfr-article-expandable').forEach(function(el){
    el.addEventListener('click',function(e){
      if(e.target.closest('a'))return;
      var id=el.dataset.lfrArtToggle;
      var ch=document.getElementById(id);if(!ch)return;
      var open=ch.style.display!=='none';
      ch.style.display=open?'none':'';
      var arrow=document.querySelector('[data-lfr-art-arrow="'+id+'"]');
      if(arrow)arrow.textContent=open?'▸':'▾';
    });
  });
}

function _frBindClose(){
  var cb=document.getElementById('fr-detail-close-btn');
  if(cb)cb.addEventListener('click',function(){document.getElementById('fr-detail').innerHTML='';document.querySelectorAll('.lfr-result-item').forEach(function(r){r.classList.remove('active');});});
}

/* ── Head / Foot helpers ────────────────────────────────── */
function _frHead(item){
  var url=_legiUrl(item.id);
  var h='<div class="lfr-detail-head"><div class="lfr-detail-head-title">'+_escFr(item.titre)+'</div><div class="lfr-detail-head-meta">';
  if(item.etat)h+='<span class="lfr-detail-head-pill">'+_escFr(item.etat)+'</span>';
  if(item.parentCode)h+='<span class="lfr-detail-head-pill">📖 '+_escFr(item.parentCode)+'</span>';
  if(item.nature)h+='<span class="lfr-detail-head-pill">'+_escFr(item.nature)+'</span>';
  if(item.num)h+='<span class="lfr-detail-head-pill">n° '+_escFr(item.num)+'</span>';
  if(item.date)h+='<span class="lfr-detail-head-pill">'+_escFr(_fmtDate(item.date))+'</span>';
  if(url)h+='<a href="'+url+'" target="_blank" rel="noopener noreferrer" class="lfr-detail-head-pill" style="text-decoration:none;cursor:pointer">Voir sur Légifrance ↗</a>';
  h+='</div></div>';
  return h;
}
function _frFoot(item){
  var url=_legiUrl(item.id);
  var codeUrl=item.parentCodeId?_legiUrl(item.parentCodeId):'';
  return'<div class="lfr-detail-footer"><span style="font-size:11px;color:var(--gray-text);font-family:\'DM Mono\',monospace">'+_escFr(item.id)+(item.cid?' · '+_escFr(item.cid):'')+'</span><div style="display:flex;gap:8px">'+(codeUrl?'<a href="'+codeUrl+'" target="_blank" rel="noopener noreferrer" class="btn btn-ghost" style="font-size:11px;text-decoration:none">📖 Code complet ↗</a>':'')+(url?'<a href="'+url+'" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="font-size:11px;text-decoration:none">Voir sur Légifrance ↗</a>':'')+'<button class="btn btn-ghost" id="fr-detail-close-btn" style="font-size:11px">✕ Fermer</button></div></div>';
}

/* ── Legacy ─────────────────────────────────────────────── */
function _openLegifranceServerConfig(){
  if(typeof isAdmin==='function'&&!isAdmin())return;navigate('configuration');
  setTimeout(function(){if(typeof switchConfigTab==='function')switchConfigTab('serveur');setTimeout(function(){var el=document.getElementById('srv-legifrance-section');if(el){el.scrollIntoView({behavior:'smooth',block:'start'});el.style.boxShadow='0 0 0 2px rgba(43,123,230,.25)';setTimeout(function(){el.style.boxShadow='';},2200);}},120);},60);
}

/* ═══════════════════════════════════════════════════════ */
/*  CSS                                                    */
/* ═══════════════════════════════════════════════════════ */
function _frCSS(){return'<style>\
.lfr-hero{padding:28px 32px;margin-bottom:20px;background:linear-gradient(135deg,#15314b 0%,#1a3d66 40%,#234f82 100%);color:#fff;position:relative;overflow:hidden;border-radius:var(--radius)}\
.lfr-hero::before{content:"";position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(43,123,230,.15)}\
.lfr-hero::after{content:"";position:absolute;right:60px;bottom:-30px;width:120px;height:120px;border-radius:50%;background:rgba(26,179,148,.1)}\
.lfr-hero-sub{font-size:13px;opacity:.75;margin-top:6px;line-height:1.5}\
.lfr-section{background:var(--white);border:1px solid var(--gray-border);border-radius:var(--radius);padding:24px;margin-bottom:20px;box-shadow:var(--shadow-sm)}\
.lfr-section-title{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}\
.lfr-section-sub{font-size:12px;color:var(--gray-text);margin-bottom:16px}\
.lfr-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:14px}\
.lfr-cat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-text);margin:14px 0 8px;padding-bottom:6px;border-bottom:1px solid var(--gray-border)}\
.lfr-cat-label:first-child{margin-top:0}\
.lfr-type-card{padding:16px;border:2px solid var(--gray-border);border-radius:10px;cursor:pointer;transition:all .2s;background:var(--white);text-align:left}\
.lfr-type-card:hover{border-color:var(--blue);background:var(--blue-pale)}\
.lfr-type-card.active{border-color:var(--blue);background:var(--blue-pale);box-shadow:0 0 0 3px rgba(43,123,230,.12)}\
.lfr-type-icon{font-size:22px;margin-bottom:8px}\
.lfr-type-name{font-size:13px;font-weight:700;color:var(--navy);margin-bottom:2px}\
.lfr-type-desc{font-size:11px;color:var(--gray-text);line-height:1.4}\
.lfr-type-examples{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}\
.lfr-type-ex{font-size:10px;padding:2px 7px;border-radius:4px;background:rgba(43,123,230,.08);color:var(--blue);font-family:"DM Mono",monospace;cursor:pointer}\
.lfr-type-ex:hover{background:rgba(43,123,230,.18)}\
.lfr-type-detail-box{display:none;padding:14px 16px;border-radius:10px;background:var(--gray-bg);border:1px solid var(--gray-border);margin-bottom:16px}\
.lfr-search-bar{display:flex;align-items:center;gap:0;border:2px solid var(--gray-border);border-radius:10px;overflow:hidden;background:var(--white);transition:border-color .2s,box-shadow .2s}\
.lfr-search-bar:focus-within{border-color:var(--blue);box-shadow:0 0 0 3px rgba(43,123,230,.1)}\
.lfr-search-bar .lfr-search-icon{padding:0 14px;font-size:16px;color:var(--gray-text);flex-shrink:0}\
.lfr-search-bar input{flex:1;border:none;padding:12px 0;font-size:14px;font-family:"DM Sans",sans-serif;color:var(--text);outline:none;background:transparent}\
.lfr-search-bar input::placeholder{color:var(--gray-text);opacity:.6}\
.lfr-search-bar .lfr-search-submit{padding:8px 20px;margin:4px;border-radius:8px;border:none;background:var(--blue);color:white;font-size:13px;font-weight:700;font-family:"DM Sans",sans-serif;cursor:pointer;transition:background .15s;white-space:nowrap}\
.lfr-search-bar .lfr-search-submit:hover{background:var(--blue-light)}\
.lfr-field label{display:block;font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}\
.lfr-input{width:100%;padding:10px 14px;border:1px solid var(--gray-border);border-radius:var(--radius-sm);font-size:13px;font-family:"DM Sans",sans-serif;color:var(--text);background:var(--white);transition:border-color .2s,box-shadow .2s;outline:none}\
.lfr-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(43,123,230,.1)}\
.lfr-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}\
@media(max-width:700px){.lfr-form-row{grid-template-columns:1fr}}\
.lfr-status-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--gray-border);align-items:center}\
.lfr-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font-size:11px;font-weight:700;border:1px solid}\
.lfr-pill.ok{background:rgba(26,179,148,.08);border-color:rgba(26,179,148,.25);color:#1ab394}\
.lfr-pill.warn{background:rgba(245,166,35,.08);border-color:rgba(245,166,35,.25);color:#f5a623}\
.lfr-pill.off{background:rgba(231,76,60,.08);border-color:rgba(231,76,60,.25);color:#e74c3c}\
.lfr-pill .dot{width:6px;height:6px;border-radius:50%;background:currentColor}\
.lfr-results-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}\
.lfr-results-count{font-size:12px;color:var(--gray-text);padding:4px 12px;background:var(--gray-bg);border-radius:999px}\
.lfr-result-list{display:grid;gap:1px;background:var(--gray-border);border:1px solid var(--gray-border);border-radius:10px;overflow:hidden}\
.lfr-result-item{padding:18px 20px;background:var(--white);cursor:pointer;transition:background .15s;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start}\
.lfr-result-item:hover{background:var(--blue-pale)}\
.lfr-result-item.active{background:var(--blue-pale);box-shadow:inset 3px 0 0 var(--blue)}\
.lfr-result-title{font-size:14px;font-weight:700;color:var(--navy);line-height:1.45;margin-bottom:4px}\
.lfr-result-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px}\
.lfr-result-excerpt{font-size:12px;color:var(--gray-text);line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}\
.lfr-result-arrow{color:var(--gray-text);font-size:18px;transition:transform .15s;align-self:center}\
.lfr-result-item:hover .lfr-result-arrow{transform:translateX(3px);color:var(--blue)}\
.lfr-result-extracts{margin-top:8px;padding-top:8px;border-top:1px dashed var(--gray-border)}\
.lfr-result-extract-item{display:flex;gap:8px;align-items:baseline;margin-bottom:4px;font-size:12px}\
.lfr-extract-num{font-weight:700;color:var(--blue);white-space:nowrap;font-size:11px}\
.lfr-extract-text{color:var(--text-mid);line-height:1.45}\
.lfr-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;border:1px solid var(--gray-border);background:var(--gray-bg);color:var(--text-mid);white-space:nowrap}\
.lfr-badge-sm{font-size:10px;padding:2px 7px}\
.lfr-badge-blue{background:var(--blue-pale);border-color:rgba(43,123,230,.2);color:var(--blue)}\
.lfr-detail{border-radius:var(--radius);border:1px solid var(--gray-border);overflow:hidden;box-shadow:var(--shadow-sm)}\
.lfr-detail-head{padding:20px 24px;background:linear-gradient(135deg,#15314b,#1a3d66);color:#fff}\
.lfr-detail-head-title{font-size:17px;font-weight:700;line-height:1.45}\
.lfr-detail-head-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}\
.lfr-detail-head-pill{padding:4px 12px;border-radius:6px;font-size:11px;font-weight:600;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#fff}\
.lfr-detail-body{padding:24px;background:var(--white)}\
.lfr-detail-block{margin-bottom:20px}\
.lfr-detail-label{font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray-text);margin-bottom:8px;letter-spacing:.5px}\
.lfr-detail-content{font-size:13px;line-height:1.75;color:var(--text);max-height:600px;overflow-y:auto;padding-right:8px}\
.lfr-detail-content h1,.lfr-detail-content h2,.lfr-detail-content h3{font-size:15px;font-weight:700;color:var(--navy);margin:20px 0 8px}\
.lfr-detail-content p{margin-bottom:10px}\
.lfr-detail-content a{color:var(--blue);text-decoration:underline}\
.lfr-detail-content table{width:100%;border-collapse:collapse;margin:12px 0;font-size:12px}\
.lfr-detail-content table td,.lfr-detail-content table th{padding:8px 12px;border:1px solid var(--gray-border)}\
.lfr-detail-content table th{background:var(--gray-bg);font-weight:700;text-align:left}\
.lfr-detail-section-item{padding:12px 16px;border:1px solid var(--gray-border);border-radius:8px;margin-bottom:8px;background:var(--gray-bg);font-size:13px;color:var(--text)}\
.lfr-detail-link{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--gray-border);border-radius:8px;margin-bottom:6px;font-size:12px;color:var(--text);transition:all .15s}\
.lfr-detail-link:hover{border-color:var(--blue);background:var(--blue-pale)}\
.lfr-detail-link-icon{flex-shrink:0;color:var(--gray-text)}\
.lfr-detail-footer{padding:16px 24px;background:var(--gray-bg);border-top:1px solid var(--gray-border);display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}\
.lfr-meta-chip{padding:8px 14px;border-radius:8px;background:var(--gray-bg);border:1px solid var(--gray-border);font-size:12px;color:var(--text)}\
.lfr-meta-chip-label{font-weight:700;color:var(--gray-text);margin-right:6px;font-size:11px;text-transform:uppercase}\
.lfr-open-link{font-size:11px;color:var(--blue);text-decoration:none;white-space:nowrap;font-weight:600}\
.lfr-open-link:hover{text-decoration:underline}\
.lfr-tree{margin-top:4px}\
.lfr-tree-node{margin-bottom:2px}\
.lfr-tree-header{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:13px;transition:background .15s}\
.lfr-tree-expandable{cursor:pointer}\
.lfr-tree-expandable:hover{background:var(--blue-pale)}\
.lfr-tree-arrow{width:16px;text-align:center;font-size:12px;color:var(--gray-text);flex-shrink:0;transition:transform .15s}\
.lfr-tree-children{padding-top:4px}\
.lfr-article-item{border:1px solid var(--gray-border);border-radius:8px;margin-bottom:6px;overflow:hidden;background:var(--white)}\
.lfr-article-header{display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:13px;transition:background .15s}\
.lfr-article-expandable{cursor:pointer}\
.lfr-article-expandable:hover{background:var(--blue-pale)}\
.lfr-article-content{padding:12px 16px;border-top:1px solid var(--gray-border);background:var(--gray-bg)}\
.lfr-article-mod{display:flex;align-items:center;gap:4px;flex-wrap:wrap}\
@keyframes lfr-shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}\
.lfr-skeleton{height:18px;border-radius:6px;background:linear-gradient(90deg,var(--gray-bg) 25%,rgba(255,255,255,.5) 50%,var(--gray-bg) 75%);background-size:800px 100%;animation:lfr-shimmer 1.5s infinite}\
.lfr-empty{text-align:center;padding:48px 24px;color:var(--gray-text)}\
.lfr-empty-icon{font-size:40px;margin-bottom:12px;opacity:.5}\
.lfr-empty-title{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:6px}\
.lfr-empty-desc{font-size:13px;line-height:1.5}\
.lfr-search-options{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px}\
.lfr-search-mode{display:flex;gap:4px;flex-wrap:wrap}\
.lfr-radio-label{cursor:pointer;display:inline-flex}\
.lfr-radio-label input[type="radio"]{display:none}\
.lfr-radio-pill{padding:5px 12px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid var(--gray-border);background:var(--white);color:var(--gray-text);transition:all .15s;font-family:"DM Sans",sans-serif}\
.lfr-radio-pill:hover{border-color:var(--blue);color:var(--blue)}\
.lfr-radio-pill.active{background:var(--blue);border-color:var(--blue);color:white}\
.lfr-page-size-wrap{display:flex;align-items:center;gap:6px}\
.lfr-page-size-select{padding:5px 8px;border:1px solid var(--gray-border);border-radius:6px;font-size:12px;font-family:"DM Sans",sans-serif;color:var(--text);background:var(--white);cursor:pointer;outline:none}\
.lfr-page-size-select:focus{border-color:var(--blue)}\
.lfr-code-picker{margin-bottom:16px;padding:16px;border:2px solid var(--blue);border-radius:10px;background:rgba(43,123,230,.03)}\
.lfr-code-picker-label{font-size:12px;font-weight:700;color:var(--blue);margin-bottom:8px;display:flex;align-items:center;gap:6px}\
.lfr-code-input{font-size:14px !important;padding:12px 14px !important}\
.lfr-code-selected{margin-top:10px}\
.lfr-code-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:var(--blue-pale);border:1px solid rgba(43,123,230,.25);font-size:13px}\
.lfr-code-chip-icon{font-size:16px}\
.lfr-code-chip-label{font-weight:700;color:var(--navy)}\
.lfr-code-chip-id{font-size:10px;color:var(--gray-text);font-family:"DM Mono",monospace}\
.lfr-code-chip-remove{border:none;background:none;color:var(--gray-text);font-size:16px;cursor:pointer;padding:0 0 0 4px;line-height:1}\
.lfr-code-chip-remove:hover{color:var(--red)}\
.lfr-autocomplete-wrap{position:relative}\
.lfr-autocomplete-list{display:none;position:absolute;top:100%;left:0;right:0;z-index:50;background:var(--white);border:1px solid var(--gray-border);border-top:none;border-radius:0 0 8px 8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:340px;overflow-y:auto}\
.lfr-autocomplete-item{padding:10px 14px;font-size:13px;color:var(--text);cursor:pointer;border-bottom:1px solid var(--gray-border);transition:background .1s}\
.lfr-autocomplete-item:last-child{border-bottom:none}\
.lfr-autocomplete-item:hover{background:var(--blue-pale);color:var(--navy)}\
.lfr-autocomplete-item strong{color:var(--blue);font-weight:700}\
.lfr-autocomplete-empty{padding:10px 14px;font-size:12px;color:var(--gray-text);font-style:italic}\
.lfr-advanced-toggle{font-size:12px;color:var(--blue);cursor:pointer;border:none;background:none;font-family:"DM Sans",sans-serif;font-weight:600;padding:0;display:inline-flex;align-items:center;gap:4px}\
.lfr-advanced-toggle:hover{text-decoration:underline}\
.lfr-advanced-panel{display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--gray-border)}\
.lfr-advanced-panel.open{display:block}\
@media(max-width:700px){\
.lfr-hero{padding:20px}.lfr-section{padding:18px}.lfr-types{grid-template-columns:1fr 1fr}\
.lfr-result-item{grid-template-columns:1fr;gap:8px}.lfr-result-arrow{display:none}\
.lfr-detail-head{padding:16px 18px}.lfr-detail-body{padding:18px}\
}\
</style>';}

