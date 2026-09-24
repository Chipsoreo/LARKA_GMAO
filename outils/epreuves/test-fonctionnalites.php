<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — inventaire fonctionnel du format déclaratif.
 *
 * Un pack déclare ; encore faut-il que Larka SERVE ce qui est déclaré. Trois
 * primitives étaient validées sans jamais être appliquées : « par_page » était
 * jeté à la validation, « grouper_par » et « totaux » n'étaient pas rendus, et
 * le « format » d'un champ calculé était ignoré. Rien ne le signalait : la
 * déclaration passait, l'écran s'affichait, et la fonctionnalité manquait.
 *
 * Cette épreuve construit un module qui exerce TOUTES les primitives, et vérifie
 * chacune. Une primitive ajoutée au format doit y être ajoutée ici.
 *
 *   php outils/epreuves/test-fonctionnalites.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
require $racine . '/api/config.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/extensions/Registre.php';

try {
    $db = new Database();
    $pdo = $db->getPdo();
} catch (\Throwable $e) {
    echo "  ⏭️  Section sautée : aucune base accessible (" . get_class($e) . ").\n";
    exit(0);
}
// Repartir d'un état propre : l'épreuve doit pouvoir être rejouée. Sans ce
// ménage, le second passage échoue sur « Titre déjà utilisé » — un faux échec
// qui ferait douter d'un code correct, et qu'on finirait par ignorer.
//
// ⚠️ LE MÉNAGE ÉTAIT INCOMPLET, ET L'ÉPREUVE MENTAIT AU SECOND PASSAGE.
// Deux états survivaient : le fichier de réglages posés
// (data/extensions/<id>.reglages.json) et la configuration du module
// (extensions/config/<id>/). Le premier faisait échouer « Réglages : valeurs
// par défaut » — le passage précédent y avait écrit seuil=42, et l'épreuve
// lisait 42 là où elle attendait le défaut déclaré. Un échec qui n'existait
// que parce que l'épreuve avait déjà tourné : exactement le genre de faux
// négatif qu'on finit par ignorer, et qui masque ensuite un vrai.
try {
    $db->getPdo()->exec('DROP TABLE IF EXISTS "ext_audit_tout_fiches"');
    $db->getPdo()->exec("DELETE FROM Listes WHERE Categorie IN ('AuditListe','AuditAmorce')");
    ExtPolitiqueDonnees::oublier();
} catch (\Throwable $e) {}
ExtPaquet::supprimerRecursif($racine . '/data/extensions/audit.tout');
ExtPaquet::supprimerRecursif($racine . '/extensions/config/audit.tout');
@unlink($racine . '/data/extensions/audit.tout.reglages.json');

// Le compteur de surveillance comportementale, aussi : dix refus dans la
// fenêtre BLOQUENT le module. Un passage qui échoue en accumule largement plus,
// et le passage suivant se heurtait alors à « operation_refusee » sur tout —
// y compris sur ce qui fonctionne. L'épreuve accusait le code d'une panne
// qu'elle avait elle-même provoquée au tour d'avant.
@unlink($racine . '/data/securite/compteurs/audit.tout.json');

$eq = $db->addEquipement(['numero'=>'EQ-A','famille'=>'CVC','marque'=>'M','modele'=>'X'],'t');

$decl = [
 'identifiant'=>'audit.tout','format'=>'declaratif/1','nom'=>'Audit','version'=>'1.0.0',
 'auteur'=>'T','description'=>'Exerce toutes les primitives.',
 'reglages'=>[
   'seuil'   =>['type'=>'entier','libelle'=>'Seuil','defaut'=>10],
   'teinte'  =>['type'=>'couleur','libelle'=>'Teinte','defaut'=>'#1b3a5c'],
   'mode'    =>['type'=>'choix','libelle'=>'Mode','valeurs'=>['A','B'],'defaut'=>'A'],
   'accent'  =>['type'=>'couleur','libelle'=>'Accent','defaut'=>'#0d9488',
                'applique'=>'couleur_accent'],
   'dense'   =>['type'=>'choix','libelle'=>'Densité','valeurs'=>['Compact','Confortable'],
                'defaut'=>'Compact','applique'=>'densite'],
   'theme'   =>['type'=>'choix','libelle'=>'Thème',
                'valeurs'=>['Larka','Sobre','Contrasté','Papier','Nuit','Pastel'],
                'defaut'=>'Larka','applique'=>'theme'],
   'langue'  =>['type'=>'choix','libelle'=>'Langue','defaut'=>'fr','applique'=>'langue']],
 'langues'=>['en'=>['Fiche'=>'Record','Titre'=>'Title']],
 'donnees'=>['fiches'=>['libelle'=>'Fiche','champs'=>[
   'titre'   =>['type'=>'texte','libelle'=>'Titre','obligatoire'=>true,'unique'=>true,'max'=>40,'groupe'=>'Base'],
   'personne'=>['type'=>'texte','libelle'=>'Personne','suggestions'=>true,'groupe'=>'Base'],
   'fourn'   =>['type'=>'texte','libelle'=>'Fournisseur','suggestions'=>true,
                'suggestions_source'=>'fournisseurs','groupe'=>'Base'],
   'notes'   =>['type'=>'texte_long','libelle'=>'Notes','max'=>200,'groupe'=>'Base'],
   'qte'     =>['type'=>'entier','libelle'=>'Quantité','min'=>1,'max'=>99,'defaut'=>1,'groupe'=>'Chiffres'],
   'prix'    =>['type'=>'decimal','libelle'=>'Prix','min'=>0,'groupe'=>'Chiffres'],
   'jour'    =>['type'=>'date','libelle'=>'Jour','defaut'=>'{{today}}','groupe'=>'Chiffres'],
   'statut'  =>['type'=>'choix','libelle'=>'Statut','valeurs'=>['A','B','C'],'defaut'=>'A','groupe'=>'Base',
                'couleurs'=>['A'=>'vert','B'=>'orange','C'=>'rouge']],
   'categorie'=>['type'=>'choix','libelle'=>'Catégorie','liste'=>'AuditListe','groupe'=>'Base',
                 'ajout_autorise'=>true],
   'libre'    =>['type'=>'choix','libelle'=>'Libre','liste'=>'AuditListe','libre'=>true,'groupe'=>'Base'],
   'amorce'   =>['type'=>'choix','libelle'=>'Amorcée','liste'=>'AuditAmorce','groupe'=>'Base',
                 'valeurs_initiales'=>['Un','Deux','Trois']],
   'actif'   =>['type'=>'booleen','libelle'=>'Actif','defaut'=>true,'groupe'=>'Base'],
   'equip'   =>['type'=>'lien','vers'=>'equipements','libelle'=>'Équipement','groupe'=>'Liens'],
   'detail'  =>['type'=>'texte','libelle'=>'Détail','groupe'=>'Base',
                'visible_si'=>['champ'=>'statut','operateur'=>'egal','valeur'=>'B']],
   'courriel'=>['type'=>'texte','libelle'=>'Courriel','format_saisie'=>'email',
                'exemple'=>'nom@exemple.fr','groupe'=>'Base'],
   'duree'   =>['type'=>'entier','libelle'=>'Durée','unite'=>'mois','groupe'=>'Chiffres'],
   'fige'    =>['type'=>'texte','libelle'=>'Figé','lecture_seule'=>true,'groupe'=>'Base'],
   'heure'   =>['type'=>'heure','libelle'=>'Heure','groupe'=>'Chiffres'],
   'quand'   =>['type'=>'horodatage','libelle'=>'Horodatage','groupe'=>'Chiffres'],
   'teinte'  =>['type'=>'couleur','libelle'=>'Couleur','groupe'=>'Base'],
   'avis'    =>['type'=>'note','libelle'=>'Note','groupe'=>'Base'],
   'taux'    =>['type'=>'pourcentage','libelle'=>'Taux','groupe'=>'Chiffres'],
   'temps'   =>['type'=>'duree','libelle'=>'Durée','groupe'=>'Chiffres'],
   'tags'    =>['type'=>'choix_multiple','libelle'=>'Étiquettes',
                'valeurs'=>['Urgent','Externe','Récurrent'],'groupe'=>'Base'],
   'limite'  =>['type'=>'date','libelle'=>'Limite','max_date'=>'today','groupe'=>'Chiffres'],
   'total'   =>['type'=>'formule','libelle'=>'Total','format'=>'monnaie',
                'expression'=>'{{round(record.qte * record.prix, 2)}}'],
 ]]],
 'ancrages'=>[
   ['emplacement'=>'dashboard.tuiles','type'=>'compteur','source'=>'fiches','libelle'=>'Fiches actives','filtre'=>['statut'=>'A']],
   ['emplacement'=>'equipement.fiche','type'=>'liste_liee','source'=>'fiches','champ_lien'=>'equip','colonnes'=>['titre','statut']],
 ],
 'partage'=>[['nom'=>'public','source'=>'fiches','champs'=>['titre','statut']]],
 'pages'=>[['cle'=>'f','titre'=>'Fiches','roles'=>['Gestionnaire'],'vue'=>[
   'type'=>'liste','source'=>'fiches',
   'colonnes'=>['titre','statut','categorie','qte','prix','total','actif','equip'],
   'recherche'=>['titre','notes'],'filtres'=>[['champ'=>'statut']],
   'tri'=>['titre'=>'ASC'],'totaux'=>['prix'],'grouper_par'=>'statut',
   'par_page'=>5,'actions'=>['creer','modifier','supprimer','exporter']]],
   /**
    * Un SECOND écran sur le même jeu, celui-là avec une mise en page.
    *
    * Il est à part, et pas greffé sur le premier, pour deux raisons : le
    * premier éprouve le formulaire historique — groupes, colonnes, largeurs
    * par type — et doit continuer de le faire ; et deux écrans sur un même jeu
    * sont précisément le cas qui faisait dépendre l'autorisation de l'ordre
    * de déclaration.
    */
   ['cle'=>'f2','titre'=>'Fiche mise en page','roles'=>['Gestionnaire'],'vue'=>[
     'type'=>'liste','source'=>'fiches',
     'colonnes'=>['titre','statut'],
     'actions'=>['creer','modifier'],
     'layout'=>[
       'type'=>'grille','colonnes'=>12,'gap_px'=>16,'marges_px'=>4,
       'responsive'=>[['en_dessous_de_px'=>640,'colonnes'=>1]],
       'composants'=>[
         ['texte'=>'Identification','style'=>'titre','largeur'=>12],
         ['champ'=>'titre','x'=>1,'y'=>2,'largeur'=>8,'hauteur_px'=>40],
         ['champ'=>'statut','x'=>9,'y'=>2,'largeur'=>4,'hauteur_px'=>40],
         ['espace'=>true,'largeur'=>12,'hauteur_px'=>16],
         ['section'=>'Chiffres','style'=>'carte','couleur'=>'bleu',
          'x'=>1,'y'=>4,'largeur'=>12,'colonnes'=>12,'gap_px'=>12,
          'repliable'=>true,'repliee'=>false,
          'composants'=>[
            ['champ'=>'qte','x'=>1,'y'=>1,'largeur'=>4,'hauteur_px'=>40,
             'alignement_h'=>'droite','largeur_max_px'=>200],
            ['champ'=>'prix','x'=>5,'y'=>1,'largeur'=>4,'hauteur_px'=>40],
          ]],
         ['section'=>'Détail','style'=>'panneau',
          'x'=>1,'y'=>5,'largeur'=>12,'colonnes'=>12,
          'visible_si'=>['champ'=>'statut','operateur'=>'egal','valeur'=>'B'],
          'composants'=>[
            ['champ'=>'detail','largeur'=>12,'hauteur_px'=>60,'masquer_libelle'=>true],
          ]],
       ]]]],
 ],
];

$tmp='/tmp/audit.tout'; @mkdir($tmp,0755,true);
file_put_contents("$tmp/extension.json", json_encode($decl, JSON_UNESCAPED_UNICODE));
ExtPaquet::construire($tmp,'/tmp/audit.larka');
ExtPaquet::installer('/tmp/audit.larka','audit.tout');
$reg = ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire']);
$man = ExtManifeste::charger(ExtPaquet::dossierCode('audit.tout'));
$reg->installer('audit.tout', $man->capacites(), 't');
$reg = ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire']);
$ap = fn($a,$c=[]) => $reg->executerAction('audit.tout',$a,$c);

$ok=[]; $ko=[];
function v(string $t, callable $f, array &$ok, array &$ko) {
  try { $r=$f(); if ($r===false) { $ko[]=$t; printf("  ❌ %-42s\n",$t); }
        else { $ok[]=$t; printf("  ✅ %-42s %s\n",$t,is_string($r)?$r:''); } }
  catch (Throwable $e) { $ko[]=$t.' : '.$e->getMessage(); printf("  ❌ %-42s %s\n",$t,mb_substr($e->getMessage(),0,42)); }
}

echo "\nSERVEUR\n";
v('Créer (défauts appliqués)', function() use($ap,$eq){
  $r=$ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'Un','prix'=>10,'equip'=>$eq,'categorie'=>'Alpha']]);
  return 'id '.$r['id']; }, $ok,$ko);
v('Défaut dynamique {{today}}', function() use($ap){
  $l=$ap('dp_lister',['jeu'=>'fiches'])['lignes'][0]; return $l['jour']===date('Y-m-d') ?: false; }, $ok,$ko);
v('Défaut statique (qte=1, statut=A)', function() use($ap){
  $l=$ap('dp_lister',['jeu'=>'fiches'])['lignes'][0];
  return ((int)$l['qte']===1 && $l['statut']==='A') ?: false; }, $ok,$ko);
v('Audit cree_par', function() use($ap){
  $l=$ap('dp_lister',['jeu'=>'fiches'])['lignes'][0]; return $l['cree_par']==='t' ?: false; }, $ok,$ko);
v('Formule calculée', function() use($ap){
  $l=$ap('dp_lister',['jeu'=>'fiches'])['lignes'][0]; return abs($l['total']-10)<0.01 ?: false; }, $ok,$ko);
v('Libellé de lien', function() use($ap){
  $l=$ap('dp_lister',['jeu'=>'fiches'])['lignes'][0]; return $l['equip_libelle'] ?: false; }, $ok,$ko);
v('Liste depuis Configuration', function() use($reg){
  // ⚠️ CETTE ÉPREUVE VÉRIFIAIT L'ANCIEN MODÈLE.
  // Une nomenclature ouverte ne vit plus dans la table « Listes » du cœur mais
  // dans le FICHIER de configuration du module — pour être versionnable,
  // livrable, et retrouvable après réinstallation. L'épreuve semait donc des
  // valeurs dans une table que plus rien ne lit, puis constatait leur absence.
  //
  // On simule ce que fait réellement l'administrateur — il édite la catégorie
  // — et l'on vérifie que le champ sert ces valeurs-là.
  $listes = ExtConfig::lire('audit.tout', 'listes.json');
  if (!isset($listes['AuditListe'])) return false;   // catégorie non amorcée
  $listes['AuditListe']['valeurs'] = ['Alpha', 'Beta'];
  ExtConfig::ecrire('audit.tout', 'listes.json', $listes);

  // descriptionClient() renvoie une LISTE : l'index 0 dépend des modules déjà
  // installés. Chercher par identifiant, sinon l'épreuve dépend de son décor.
  foreach ($reg->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    $d = $e['declaration']['donnees']['fiches']['champs']['categorie'];
    return implode(',', $d['valeurs'] ?? []) ?: false;
  }
  return false; }, $ok,$ko);
/**
 * ── Mise en page ─────────────────────────────────────────────────────────
 *
 * La question n'est pas « le schéma l'accepte-t-il » — c'est test-layout.php
 * qui y répond — mais « arrive-t-elle jusqu'au client ». Une primitive validée
 * puis perdue en route est le défaut qui a fait naître cette épreuve.
 */
$pageDeclaree = function(string $cle) use($reg) {
  foreach ($reg->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    foreach ($e['declaration']['pages'] ?? [] as $p) {
      if (($p['cle'] ?? '') === $cle) return $p;
    }
  }
  return null;
};

v('Mise en page transmise au client', function() use($pageDeclaree){
  $l = $pageDeclaree('f2')['vue']['layout'] ?? null;
  return is_array($l) && $l['colonnes'] === 12 ? '12 colonnes' : false; }, $ok,$ko);

v('Mise en page : champs placés', function() use($pageDeclaree){
  $l = $pageDeclaree('f2')['vue']['layout'] ?? [];
  $attendus = ['titre','statut','qte','prix','detail'];
  return ($l['_champs_places'] ?? []) === $attendus
    ? implode(',', $attendus) : false; }, $ok,$ko);

v('Mise en page : condition de section compilée', function() use($pageDeclaree){
  $l = $pageDeclaree('f2')['vue']['layout'] ?? [];
  foreach ($l['composants'] ?? [] as $c) {
    if (($c['nature'] ?? '') !== 'section') continue;
    if (($c['visible_si']['t'] ?? '') === 'clause') return 'compilée';
  }
  return false; }, $ok,$ko);

v('Mise en page : alignement résolu en CSS', function() use($pageDeclaree){
  $l = $pageDeclaree('f2')['vue']['layout'] ?? [];
  foreach ($l['composants'] ?? [] as $c) {
    foreach ($c['composants'] ?? [] as $s) {
      if (($s['champ'] ?? '') === 'qte') return $s['alignement_h'] === 'end' ? 'end' : false;
    }
  }
  return false; }, $ok,$ko);

v('Mise en page : palier responsive conservé', function() use($pageDeclaree){
  $l = $pageDeclaree('f2')['vue']['layout'] ?? [];
  return ($l['responsive'][0]['en_dessous_de_px'] ?? 0) === 640 ? '640 px' : false; }, $ok,$ko);

v('Sans mise en page, le formulaire d avant', function() use($pageDeclaree){
  // array_key_exists, et non « ?? » : l'opérateur de coalescence confond une
  // clé absente et une valeur nulle, or c'est exactement la distinction qu'on
  // vérifie ici — la clé DOIT être présente, et valoir null.
  $vue = $pageDeclaree('f')['vue'] ?? [];
  return array_key_exists('layout', $vue) && $vue['layout'] === null
    ? 'layout = null' : false; }, $ok,$ko);

v('Unicité refusée', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'Un']]); return false; }
  catch(Throwable $e){ return 'refusée'; } }, $ok,$ko);
v('Borne min/max refusée', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'Z','qte'=>500]]); return false; }
  catch(Throwable $e){ return 'refusée'; } }, $ok,$ko);
for ($i=2;$i<=5;$i++) $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>"T$i",'prix'=>$i,'statut'=>$i%2?'B':'C']]);
v('Recherche', function() use($ap){ return $ap('dp_lister',['jeu'=>'fiches','recherche'=>'T3'])['nombre']===1 ?: false; }, $ok,$ko);
v('Filtre', function() use($ap){ return $ap('dp_lister',['jeu'=>'fiches','filtres'=>['statut'=>'B']])['nombre']===2 ?: false; }, $ok,$ko);
v('Tri', function() use($ap){ $l=$ap('dp_lister',['jeu'=>'fiches'])['lignes']; return $l[0]['titre']==='T2' ?: false; }, $ok,$ko);
v('Pagination (par_page=5)', function() use($ap){ return $ap('dp_lister',['jeu'=>'fiches','page'=>1])['nombre']<=5 ?: false; }, $ok,$ko);
v('Totaux', function() use($ap){ $r=$ap('dp_lister',['jeu'=>'fiches']); return isset($r['totaux']['prix']) ? number_format($r['totaux']['prix'],2) : false; }, $ok,$ko);
v('Export CSV', function() use($ap){ return strlen($ap('dp_exporter',['jeu'=>'fiches'])['contenu']).' o'; }, $ok,$ko);
v('Suppression', function() use($ap){ $id=$ap('dp_lister',['jeu'=>'fiches'])['lignes'][0]['id'];
  $ap('dp_supprimer',['jeu'=>'fiches','id'=>$id]); return 'ok'; }, $ok,$ko);
v('Ancrage compteur', function() use($ap){ return (string)$ap('dp_ancrage',['index'=>0])['nombre']; }, $ok,$ko);
v('Ancrage liste_liee', function() use($ap,$eq){ return count($ap('dp_ancrage',['index'=>1,'valeur_lien'=>$eq]) ['lignes']).' ligne(s)'; }, $ok,$ko);
v('Format de saisie refusé', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'Fmt','courriel'=>'pas-valide']]);
        return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusé'; } }, $ok,$ko);
v('Format de saisie accepté', function() use($ap){
  $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'Fmt2','courriel'=>'a@b.fr']]);
  return 'ok'; }, $ok,$ko);
v('Champ en lecture seule non modifiable', function() use($ap){
  $id = $ap('dp_lister',['jeu'=>'fiches','recherche'=>'Fmt2'])['lignes'][0]['id'];
  $ap('dp_enregistrer',['jeu'=>'fiches','id'=>$id,'valeurs'=>['titre'=>'Fmt2','fige'=>'tentative']]);
  $l = $ap('dp_lister',['jeu'=>'fiches','recherche'=>'Fmt2'])['lignes'][0];
  return empty($l['fige']) ? 'ignoré' : false; }, $ok,$ko);

v('Heure : format refusé', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'H1','heure'=>'25:99']]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);
v('Durée : 1h30 → 90 minutes', function() use($ap){
  $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'D1','temps'=>'1h30']]);
  $l = $ap('dp_lister',['jeu'=>'fiches','recherche'=>'D1'])['lignes'][0];
  return (int)$l['temps'] === 90 ? '90' : false; }, $ok,$ko);
v('Note hors bornes refusée', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'N1','avis'=>9]]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);
v('Couleur : format refusé', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'C1','teinte'=>'rouge']]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);
v('Choix multiple : valeur inconnue refusée', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'M1','tags'=>'Inventé']]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);
v('Choix multiple : plusieurs valeurs', function() use($ap){
  $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'M2','tags'=>'Urgent, Externe']]);
  $l = $ap('dp_lister',['jeu'=>'fiches','recherche'=>'M2'])['lignes'][0];
  return $l['tags']; }, $ok,$ko);
v('Borne de date (max_date=today)', function() use($ap){
  try { $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'L1','limite'=>'2099-01-01']]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);
v('Tri demandé par l utilisateur', function() use($ap){
  $r = $ap('dp_lister',['jeu'=>'fiches','tri'=>['titre'=>'DESC']]);
  return $r['nombre'] > 0 ? 'appliqué' : false; }, $ok,$ko);

v('Réglages : valeurs par défaut', function() use($ap){
  $r = $ap('dp_reglages');
  return ((int)$r['seuil'] === 10 && $r['mode'] === 'A') ? 'lus' : false; }, $ok,$ko);
v('Réglages : enregistrement', function() use($ap){
  $ap('dp_definir_reglages',['valeurs'=>['seuil'=>42]]);
  return (int)$ap('dp_reglages')['seuil'] === 42 ? '42' : false; }, $ok,$ko);
v('Réglages : valeur invalide refusée', function() use($ap){
  try { $ap('dp_definir_reglages',['valeurs'=>['teinte'=>'rouge']]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);
v('Réglages : lisibles dans une formule', function() {
  // valider() reçoit l'expression SANS les accolades : celles-ci délimitent
  // la formule dans le JSON, elles ne font pas partie du langage.
  $r = ExtExpression::valider('reglages.seuil * 2');
  if (!$r['ok']) return false;
  return (int)ExtExpression::evaluer($r['arbre'], ['reglages'=>['seuil'=>21]]) === 42
       ? '42' : false; }, $ok,$ko);
v('Réglage pilotant l apparence', function() use($db){
  foreach (ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire'])
           ->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    $d = $e['declaration']['declaration_reglages'] ?? [];
    return (($d['accent']['applique'] ?? '') === 'couleur_accent'
         && ($d['dense']['applique'] ?? '') === 'densite') ? 'accent + densité' : false;
  }
  return false; }, $ok,$ko);
v('Apparence : type incohérent refusé', function() {
  $d = ['format'=>'declaratif/1','identifiant'=>'a.b','nom'=>'T','version'=>'1.0.0',
        'auteur'=>'A','description'=>'D',
        'reglages'=>['x'=>['type'=>'entier','libelle'=>'X','applique'=>'couleur_accent']],
        'donnees'=>['j'=>['libelle'=>'J','champs'=>['n'=>['type'=>'texte','libelle'=>'N']]]],
        'pages'=>[['cle'=>'p','titre'=>'P','vue'=>['type'=>'liste','source'=>'j']]]];
  try { ExtSchemaDeclaratif::valider($d); return false; }
  catch (\Throwable $e) { return 'refusé'; } }, $ok,$ko);

v('Thème posant une palette', function() use($db){
  foreach (ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire'])
           ->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    return count($e['declaration']['themes'] ?? []) >= 6 ? '6 thèmes' : false;
  }
  return false; }, $ok,$ko);
v('Langue : réglage du module', function() use($ap, $db){
  // Les valeurs du réglage « langue » sont composées à partir des traductions
  // fournies : les faire répéter par l'auteur créerait deux listes.
  foreach (ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire'])
           ->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    $lg = $e['declaration']['declaration_reglages']['langue']['valeurs'] ?? [];
    return (in_array('fr', $lg, true) && in_array('en', $lg, true)) ? implode(',', $lg) : false;
  }
  return false; }, $ok,$ko);
v('Langue inconnue refusée', function() use($ap){
  try { $ap('dp_definir_reglages',['valeurs'=>['langue'=>'jp']]); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusée'; } }, $ok,$ko);

v('Traduction des libellés', function() use($db){
  $reg = ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire','Langue'=>'en']);
  foreach ($reg->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    $lib = $e['declaration']['donnees']['fiches']['libelle'];
    return $lib === 'Record' ? $lib : false;
  }
  return false; }, $ok,$ko);
v('Traduction : noms techniques intacts', function() use($db){
  $reg = ExtRegistre::initialiser($db, ['Login'=>'t','Role'=>'Gestionnaire','Langue'=>'en']);
  foreach ($reg->descriptionClient() as $e) {
    if (($e['identifiant'] ?? '') !== 'audit.tout') continue;
    return isset($e['declaration']['donnees']['fiches']['champs']['titre']) ? 'intacts' : false;
  }
  return false; }, $ok,$ko);
v('Colonne ajoutée à la mise à jour', function() use($db, $racine){
  // Une table d'une version antérieure doit recevoir les colonnes déclarées
  // depuis. Sans cela, toute écriture est refusée après une mise à jour.
  $man = ExtManifeste::charger(ExtPaquet::dossierCode('audit.tout'));
  $sec = new ExtSecurite($db, 'audit.tout', $man->capacites(), ['Login'=>'t']);
  $db->getPdo()->exec('ALTER TABLE "ext_audit_tout_fiches" DROP COLUMN "notes"');
  ExtPolitiqueDonnees::oublier();
  $ajoutees = $sec->completerTable('fiches', ['notes'=>'texte']);
  return in_array('notes', $ajoutees, true) ? 'notes' : false; }, $ok,$ko);

v('Suggestions : filtrage progressif', function() use($ap){
  foreach (['Jean Dupont','Jeanne Martin','Marie Durand'] as $i => $n) {
    $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'S'.$i,'personne'=>$n]]);
  }
  $tous = $ap('dp_suggestions',['jeu'=>'fiches','champ'=>'personne','recherche'=>'']);
  $j    = $ap('dp_suggestions',['jeu'=>'fiches','champ'=>'personne','recherche'=>'jea']);
  return (count($tous['valeurs']) === 3 && count($j['valeurs']) === 2)
       ? '3 → 2' : false; }, $ok,$ko);
v('Suggestions : champ non déclaré refusé', function() use($ap){
  try { $ap('dp_suggestions',['jeu'=>'fiches','champ'=>'titre']); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusé'; } }, $ok,$ko);
v('Choix libre : valeur hors liste acceptée', function() use($ap){
  $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'L1','libre'=>'Hors liste']]);
  $l = $ap('dp_lister',['jeu'=>'fiches','recherche'=>'L1'])['lignes'][0];
  return $l['libre'] === 'Hors liste' ? 'acceptée' : false; }, $ok,$ko);
v('Ajout à la liste gérée', function() use($ap){
  $r = $ap('dp_ajouter_valeur',['jeu'=>'fiches','champ'=>'categorie','valeur'=>'Gamma']);
  if (empty($r['ajoutee'])) return false;
  $ap('dp_enregistrer',['jeu'=>'fiches','valeurs'=>['titre'=>'G1','categorie'=>'Gamma']]);
  return 'Gamma'; }, $ok,$ko);
v('Ajout refusé sur un champ non autorisé', function() use($ap){
  try { $ap('dp_ajouter_valeur',['jeu'=>'fiches','champ'=>'libre','valeur'=>'X']); return false; }
  catch (ExtErreurUtilisateur $e) { return 'refusé'; } }, $ok,$ko);

v('Suggestions depuis une source du cœur', function() use($ap, $db){
  // Un registre vide n'a rien à proposer : la source du cœur prend le relais.
  $db->addEquipement(['numero'=>'EQ-S1','famille'=>'CVC','fournisseur'=>'Apave'], 't');
  $r = $ap('dp_suggestions',['jeu'=>'fiches','champ'=>'fourn','recherche'=>'apa']);
  return in_array('Apave', $r['valeurs'], true) ? 'Apave' : false; }, $ok,$ko);
v('Source de suggestions inconnue refusée', function() {
  $d = ['format'=>'declaratif/1','identifiant'=>'a.b','nom'=>'T','version'=>'1.0.0',
        'auteur'=>'A','description'=>'D',
        'donnees'=>['j'=>['libelle'=>'J','champs'=>[
          'x'=>['type'=>'texte','libelle'=>'X','suggestions'=>true,
                'suggestions_source'=>'inventee']]]],
        'pages'=>[['cle'=>'p','titre'=>'P','vue'=>['type'=>'liste','source'=>'j']]]];
  try { ExtSchemaDeclaratif::valider($d); return false; }
  catch (\Throwable $e) { return 'refusée'; } }, $ok,$ko);

v('Liste amorcée à l installation', function(){
  // Une catégorie vide donne un menu déroulant vide, et le module paraît
  // cassé alors qu'il attend une donnée.
  //
  // ⚠️ L'AMORÇAGE ÉCRIT LE FICHIER, PLUS LA TABLE « Listes ».
  // L'épreuve comptait des lignes dans une table que l'amorçage n'alimente
  // plus depuis que les nomenclatures sont passées au fichier. Elle est donc
  // restée rouge sans qu'aucun code soit fautif — jusqu'à ce qu'on cesse de
  // la lire. On vérifie désormais là où les valeurs atterrissent vraiment.
  $v = ExtConfig::lire('audit.tout', 'listes.json')['AuditAmorce']['valeurs'] ?? [];
  return $v !== [] ? count($v) . ' valeur(s)' : false; }, $ok,$ko);

v('Sélecteur de lien', function() use($ap){ return count($ap('dp_cibles',['jeu'=>'fiches','champ'=>'equip'])['valeurs']).' cible(s)'; }, $ok,$ko);

// ── Chaque cible de lien désigne-t-elle des colonnes réelles ? ──────────────
// Une colonne inexistante rend la cible inutilisable, et le défaut ne se voit
// qu'en ouvrant le sélecteur — trop tard.
echo "\nCIBLES DE LIEN\n";
foreach (ExtSchemaDeclaratif::CIBLES_LIEN as $cible => $def) {
    $reel = ExtPolitiqueDonnees::nomReel($db, $cible);
    if ($reel === null) {
        $ko[] = "cible « $cible » : table absente";
        printf("  ❌ %-14s table absente\n", $cible);
        continue;
    }
    $cols = ExtPolitiqueDonnees::colonnesExistantes($db, $reel);
    $manque = [];
    foreach (array_merge(['Id'], $def['affichage']) as $c) {
        $trouve = false;
        foreach ($cols as $x) if (strcasecmp($x, $c) === 0) { $trouve = true; break; }
        if (!$trouve) $manque[] = $c;
    }
    if ($manque) {
        $ko[] = "cible « $cible » : " . implode(', ', $manque);
        printf("  ❌ %-14s colonne(s) absente(s) : %s\n", $cible, implode(', ', $manque));
    } else {
        $ok[] = $cible;
        printf("  ✅ %-14s %s\n", $cible, implode(', ', $def['affichage']));
    }
}

// ── Le catalogue de libellés est-il exploitable ? ──────────────────────────
//
// lang/fr.json sert de modèle à un traducteur : s'il contient des fragments de
// code, le traducteur perd son temps sur des lignes qui ne s'afficheront
// jamais — et un catalogue pollué décourage plus qu'il n'aide.
echo "\nCATALOGUE DE LIBELLÉS\n";
$cat = $racine . '/lang/fr.json';
if (!is_file($cat)) {
    $ko[] = 'lang/fr.json absent';
    printf("  ❌ lang/fr.json absent\n");
} else {
    $j = json_decode((string)file_get_contents($cat), true);
    $lib = $j['libelles'] ?? [];
    $pollue = [];
    foreach (array_keys($lib) as $t) {
        if (preg_match('/[<>{}$\\\\]|\+\s*\(|\bfunction\b|\bvar\b/', $t)) {
            $pollue[] = $t;
        }
    }
    if ($lib !== [] && $pollue === []) {
        $ok[] = 'catalogue';
        printf("  ✅ %d libellé(s), aucun fragment de code\n", count($lib));
    } else {
        $ko[] = 'catalogue pollué : ' . count($pollue) . ' entrée(s)';
        printf("  ❌ %d fragment(s) de code : %s\n", count($pollue),
               implode(' | ', array_slice($pollue, 0, 3)));
    }
}

// ── Les variables d'un thème sont-elles réellement lues ? ──────────────────
//
// Un vocabulaire peut déclarer « --sidebar-bg » sans que la feuille de style
// s'en serve : l'auteur du thème règle la couleur du menu, et rien ne change.
// Une variable promise et sans effet est pire qu'une variable absente.
echo "\nVARIABLES D'APPARENCE\n";
$vocFichier = $racine . '/extensions/larka.moteur-apparence/extension.json';
if (is_file($vocFichier)) {
    $voc = json_decode((string)file_get_contents($vocFichier), true)['vocabulaire'] ?? [];
    $css = '';
    foreach (glob($racine . '/css/*.css') as $f) $css .= file_get_contents($f);
    $orphelines = [];
    foreach ($voc as $nom => $d) {
        if (empty($d['variable'])) continue;
        if (!str_contains($css, 'var(' . $d['variable'])) {
            $orphelines[] = $nom . ' → ' . $d['variable'];
        }
    }
    if ($orphelines === []) {
        $ok[] = 'variables lues';
        printf("  ✅ %d variable(s) déclarée(s), toutes lues par le CSS\n", count($voc));
    } else {
        $ko[] = 'variables sans effet : ' . implode(', ', $orphelines);
        printf("  ❌ déclarée(s) mais jamais lue(s) : %s\n", implode(', ', $orphelines));
    }
}

// ── Le client rend-il ce que le serveur envoie ? ────────────────────────────
// Contrôle statique : on ne peut pas exécuter le navigateur ici, mais on peut
// vérifier que chaque primitive a bien du code qui la dessine.
echo "\nCLIENT\n";
$js = (string)@file_get_contents($racine . '/js/declaratif.js')
    . (string)@file_get_contents($racine . '/js/extensions.js');
foreach ([
    'grouper_par' => 'regroupement des lignes',
    'totaux'      => 'totaux en pied de tableau',
    'data-page'   => 'barre de pagination',
    'monnaie'     => 'format monnaie',
    '_libelle'    => 'libellé des liens',
    'visible_si'  => 'visibilité conditionnelle',
    'dp_cibles'   => 'sélecteur de lien',
    'groupe'      => 'groupes de champs',
    'couleurs'    => 'badges de couleur',
    'unite'       => 'unité affichée',
    'lecture_seule' => 'champ non modifiable',
    'MOTIFS'      => 'motif de saisie côté client',
    'data-multi'  => 'cases à cocher multiples',
    'data-tri'    => 'tri par en-tête de colonne',
    'surlignage'  => 'mise en évidence des lignes',
    'type="color"' => 'sélecteur de couleur',
    '★'           => 'affichage des notes',
    'brancherRecherche' => 'recherche filtrante partagée',
    'dp_suggestions'    => 'suggestions au fil de la frappe',
    'data-suggere'      => 'champ à saisie assistée',
    'data-ajout'        => 'bouton d ajout à la liste',
    'Aucune valeur dans la liste' => 'liste vide signalée',
    'function apparence' => 'réglages d apparence appliqués',
    'construireLayout'  => 'moteur de mise en page',
    'dp-col-'           => 'grille en colonnes',
    'dp-w-'             => 'portée d un composant',
    'data-si'           => 'affichage conditionnel de la mise en page',
    '@media (max-width' => 'paliers responsive',
    'dp-sans-libelle'   => 'libellé de champ masqué',
    'STYLES_SECTION'    => 'habillages de section',
    'lignes_alternees'   => 'lignes alternées',
    'POLICES'            => 'polices',
    'ARRONDIS'           => 'arrondis',
    'appliquerHabillage' => 'habillage appliqué',
] as $motif => $libelle) {
    if (str_contains($js, $motif)) { $ok[] = $libelle; printf("  ✅ %s\n", $libelle); }
    else { $ko[] = $libelle . ' (client)'; printf("  ❌ %-42s non rendu\n", $libelle); }
}

$total = count($ok) + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — tout ce qui est déclarable est servi.\n", count($ok), $total);
} else {
    printf(" ❌ %d manque(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $k) echo "      • $k\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);
