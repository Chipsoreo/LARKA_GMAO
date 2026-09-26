<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Assistant des Demandeurs (déterministe)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Même principe que AssistantMoteur : la réponse ne dépend pas du modèle.
 *
 *   « probleme de poignet a mon etage (2eme, bureau 235) »
 *     → titre « Poignée de porte défectueuse », bureau « Bureau 235, 2e étage »,
 *       catégorie prise dans les catégories RÉELLES du site, urgence alignée
 *       sur le formulaire (Basse, Normale, Haute, Urgente)
 *     → jeton [ACTION:nouvelle_demande|…] : le formulaire s'ouvre pré-rempli.
 *
 * Suivi de ses demandes, contacts des gestionnaires, rôles, catégories et
 * modules : lus dans le contexte du demandeur, jamais inventés.
 *
 * RGPD : aucune recherche dans la base au-delà du contexte du demandeur
 * (ses propres demandes, la liste des gestionnaires, les listes de référence).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/AssistantLangue.php';

final class AssistantDemandeur
{
    /** Phrase de relance : si l'assistant l'a dite, le message suivant est la description du problème. */
    private const DEMANDE_DETAILS = 'Décris-moi le problème';

    private array $ctx;

    public function __construct(array $contexte)
    {
        $this->ctx = $contexte + ['nom' => '', 'service' => '', 'poste' => '', 'services' => [], 'batiments' => [],
            'categories' => [], 'gestionnaires' => [], 'demandes' => [], 'modules' => []];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Contexte (lu une fois par requête)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Contexte du demandeur. Corrige deux défauts de l'ancien prompt : la table
     * « ListesReferences » n'existe pas (les bâtiments de référence sont dans
     * Listes) et les catégories de référence n'étaient jamais lues.
     *
     * @param array $modules  catalogue des modules visibles (id => [nom, pages…])
     */
    public static function contexte($db, array $user, array $modules = []): array
    {
        $q = function (string $sql, array $p = []) use ($db): array {
            try { return $db->fetchAll($sql, $p); } catch (\Throwable $_) { return []; }
        };
        $col = fn(array $rows, string $k) => array_values(array_unique(array_filter(array_map(fn($r) => trim((string)($r[$k] ?? '')), $rows), fn($v) => $v !== '')));

        $services = $col($q("SELECT DISTINCT Service FROM Utilisateurs WHERE Actif = 1 AND Service IS NOT NULL AND Service != '' ORDER BY Service LIMIT 60"), 'Service');
        $batsListe = $col($q("SELECT Valeur FROM Listes WHERE Categorie = 'Batiment' AND (Actif = 1 OR Actif IS NULL) ORDER BY Ordre, Valeur LIMIT 60"), 'Valeur');
        $batsAutres = array_merge(
            $col($q("SELECT Nom FROM PlanBatiments ORDER BY Nom LIMIT 60"), 'Nom'),
            $col($q("SELECT DISTINCT Batiment FROM DemandesIntervention WHERE Batiment IS NOT NULL AND Batiment != '' ORDER BY Batiment LIMIT 60"), 'Batiment'));
        $cats = array_values(array_unique(array_merge(
            $col($q("SELECT Valeur FROM Listes WHERE Categorie = 'CategorieDemande' AND (Actif = 1 OR Actif IS NULL) ORDER BY Ordre, Valeur LIMIT 60"), 'Valeur'),
            $col($q("SELECT DISTINCT Categorie FROM DemandesIntervention WHERE Categorie IS NOT NULL AND Categorie != '' ORDER BY Categorie LIMIT 60"), 'Categorie'))));

        // Bâtiments : valeur du formulaire d'abord (liste de référence), autres graphies rattachées.
        $bats = [];
        foreach (array_merge($batsListe, $batsAutres) as $b) {
            $n = AssistantLangue::preparer($b);
            $court = trim(preg_replace('/^(?:batiments?|bat|bati|bt)\s+/', '', $n));
            if ($court === '') continue;
            $dejaVu = false;
            foreach ($bats as $x) if ($x['court'] === $court) { $dejaVu = true; break; }
            if (!$dejaVu) $bats[$b] = ['norm' => $n, 'court' => $court, 'formulaire' => in_array($b, $batsListe, true)];
        }

        $gest = [];
        foreach ($q("SELECT Prenom, Nom, Poste, Service, Role, Email, Tel, TelMobile, TelPro
                     FROM Utilisateurs WHERE Actif = 1 AND Role IN ('Gestionnaire','Admin')
                     ORDER BY Role DESC, Nom, Prenom LIMIT 20") as $r) {
            $nom = trim(($r['Prenom'] ?? '') . ' ' . ($r['Nom'] ?? ''));
            if ($nom === '') continue;
            $gest[] = [
                'nom'    => $nom,
                'detail' => trim((string)($r['Poste'] ?? '')) ?: trim((string)($r['Service'] ?? '')) ?: trim((string)($r['Role'] ?? '')),
                'tel'    => trim((string)($r['TelPro'] ?? '')) ?: trim((string)($r['TelMobile'] ?? '')) ?: trim((string)($r['Tel'] ?? '')),
                'email'  => trim((string)($r['Email'] ?? '')),
                'role'   => (string)($r['Role'] ?? ''),
            ];
        }
        // Pour « qui prévenir en urgence » : d'abord ceux qu'on peut appeler, les gestionnaires avant les admins.
        usort($gest, fn($a, $b) => [$a['tel'] === '' ? 1 : 0, $a['role'] === 'Gestionnaire' ? 0 : 1, $a['nom']]
                                 <=> [$b['tel'] === '' ? 1 : 0, $b['role'] === 'Gestionnaire' ? 0 : 1, $b['nom']]);
        $demandes = $q(
            "SELECT d.Id, d.Titre, d.Statut, d.Urgence, d.Categorie, d.Batiment, d.Bureau, d.DateCreation, d.CommentaireAdmin,
                    i.Numero AS InterventionNumero, i.Statut AS InterventionStatut,
                    i.DateIntervention AS InterventionDate, i.AgentPrenom, i.AgentNom
             FROM DemandesIntervention d
             LEFT JOIN Interventions i ON i.DemandeId = d.Id
             WHERE d.UtilisateurId = :uid
             ORDER BY d.DateCreation DESC, d.Id DESC LIMIT 10",
            ['uid' => (int)($user['Id'] ?? 0)]
        );
        // PostgreSQL rend les alias en minuscules.
        $demandes = array_map(function ($r) {
            $out = [];
            foreach ($r as $k => $v) {
                $k = ['interventionnumero' => 'InterventionNumero', 'interventionstatut' => 'InterventionStatut',
                      'interventiondate' => 'InterventionDate'][strtolower((string)$k)] ?? $k;
                $out[$k] = $v;
            }
            return $out;
        }, $demandes);

        $pages = [];
        foreach ($modules as $id => $m) foreach ($m['pages'] ?? [] as $titre) if ($titre !== '') $pages[] = ['id' => $id, 'nom' => $m['nom'] ?? $id, 'titre' => $titre];

        return [
            'nom'           => trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? '')),
            'service'       => trim((string)($user['Service'] ?? '')),
            'poste'         => trim((string)($user['Poste'] ?? '')),
            'services'      => $services,
            'batiments'     => $bats,
            'categories'    => $cats,
            'gestionnaires' => $gest,
            'demandes'      => $demandes,
            'modules'       => $pages,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Point d'entrée
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array{texte:string, trouve:bool, intention:string, comprehension:array}
     */
    /** Objet suggéré par l'interprète (reconnu par le lexique, sinon ignoré). */
    private ?string $indiceObjet = null;

    public function repondre(string $message, array $historique = [], ?string $intentionImposee = null, ?string $indiceObjet = null): array
    {
        $this->indiceObjet = $indiceObjet !== null && $indiceObjet !== '' ? ' ' . AssistantLangue::preparer($indiceObjet) . ' ' : null;
        $t = ' ' . AssistantLangue::preparer($message) . ' ';
        $i = $intentionImposee ?? $this->intention($t, $historique);
        $r = match ($i) {
            'politesse'   => $this->politesse($t),
            'aide'        => $this->aide(),
            'roles'       => $this->roles(),
            'suivi'       => $this->suivi($t),
            'contacts'    => $this->contacts($t),
            'categories'  => $this->categories($t),
            'modules'     => $this->modules($t),
            'archive'     => $this->archive($message, $t),
            'creer'       => $this->creer($message, $historique),
            'demande_vide'=> ['texte' => "D'accord ! " . self::DEMANDE_DETAILS . " en une phrase : quoi (porte, lumière, fuite…), où (bâtiment, étage, bureau) et si c'est urgent. Je préparerai le formulaire pour toi.", 'trouve' => true],
            'hors_sujet'  => ['texte' => "Je ne peux t'aider que pour Larka : préparer une demande d'intervention, suivre tes demandes ou trouver qui contacter.", 'trouve' => true],
            default       => ['texte' => "Je n'ai pas bien compris. Tu veux signaler un problème ? " . self::DEMANDE_DETAILS . " (quoi, où). Tu peux aussi me demander où en est ta demande, ou qui contacter.", 'trouve' => false],
        };
        return ['texte' => trim($r['texte']), 'trouve' => $r['trouve'], 'intention' => $i,
                'comprehension' => ['intention' => $i, 'question' => $message] + ($r['champs'] ?? [])];
    }

    /** Intention du message (règles fixes, dans un ordre fixe). */
    public function intention(string $t, array $historique = []): string
    {
        $has = static fn(string $re): bool => (bool)preg_match($re, $t);
        if ($has('/^ (?:(?:bonjour|bonsoir|salut|hello|coucou|hey|merci|ok|okay|d accord|parfait|super|top|genial|cool|au revoir|bye|a plus|bonne (?:journee|soiree)|c est bon|ca marche|nickel)\s*)+(?:beaucoup|bien|a toi|a vous)? $/')) return 'politesse';
        if ($has('/^ (?:aide|help|\?+|menu) $/') || $has('/\b(?:que (?:sais|peux) tu|tu (?:sais|peux) faire|comment (?:ca marche|t utiliser|te servir)|a quoi (?:tu sers|sers tu)|tu sers a quoi)\b/')) return 'aide';
        if ($has('/\b(?:roles?|c est quoi un (?:gestionnaire|technicien|demandeur|admin\w*)|qui fait quoi|difference entre)\b/')) return 'roles';
        $probleme = $this->decritProbleme($t);
        if ($has('/\b(?:ma demande|mes demandes|ma derniere demande|mon ticket|mes tickets|ou en est|ou en sont|statut de|avancement|suivi de|qui s occupe de ma|quand (?:sera|va|est ce que) (?:ma|elle)|a ete (?:traitee|prise)|traitee|prise en charge|ca avance|ca en est ou|des nouvelles|du nouveau)\b/')
            || $has('/(?:#\s*\d+|\bdemande (?:numero )?\d+\b)/')) {
            if (!$has('/\b(?:creer|faire|nouvelle|ouvrir|deposer|preparer) (?:une |ma |la )?demande\b/')) return 'suivi';
        }
        if ($has('/\b(?:qui (?:contacter|appeler|joindre|previens?|prevenir)|contacter|joindre|appeler|numero de|telephone|coordonnees|gestionnaires?|responsables?|interlocuteur\w*|en cas d urgence qui)\b/')
            && !$has('/\b(?:creer|faire|preparer) (?:une )?demande\b/')) return 'contacts';
        if ($has('/\bcategories?\b/')) return 'categories';
        if ($has('/\b(?:modules?|reserv\w+|pret de|emprunt\w*)\b/') || $this->moduleCite($t)) return 'modules';
        if ($has('/\b(?:archiv\w*|desarchiv\w*|(?:sortir|recuperer|consulter|ressortir) (?:un |des |le |les |mon |mes )?dossiers?)\b/')) return 'archive';
        if ($probleme) return 'creer';
        if ($has('/\b(?:creer|faire|nouvelle|ouvrir|deposer|preparer|signaler|declarer|remplir) (?:une |un |ma |la |le )?(?:demande|signalement|ticket|probleme|incident)\b|\bsignaler\b/')) return 'demande_vide';
        if ($has('/\b(?:meteo|blague|recette|foot\w*|match|politique|president|poeme|chanson|film|horoscope|tradui\w+|capitale|bitcoin|bourse|l heure|quelle heure|quel jour)\b/')) return 'hors_sujet';
        // Le lieu seul, juste après une demande préparée (« c'est au bâtiment A ») : on la complète.
        if ($this->echangePrecedent($historique) !== null && $this->estLieuSeul($t)) return 'creer';
        // L'assistant venait de demander la description : c'est elle.
        $prec = $this->derniereReponse($historique);
        if ($prec !== null && str_contains($prec, self::DEMANDE_DETAILS) && count(AssistantLangue::mots(trim($t))) >= 2) return 'creer';
        return 'inconnue';
    }

    /**
     * Une demande peut-elle être préparée à partir de ce message ? Il faut un
     * objet ou un problème reconnu — dans le message, ou dans l'objet suggéré
     * par l'interprète. Un charabia ne fabrique pas de formulaire.
     */
    public function peutCreer(string $message, ?string $indice = null): bool
    {
        $t = ' ' . AssistantLangue::preparer($message) . ' ';
        if ($this->objet($t) || $this->probleme($t)) return true;
        return $indice !== null && $indice !== '' && $this->objet(' ' . AssistantLangue::preparer($indice) . ' ') !== null;
    }

    /** Le message décrit-il un problème ou un besoin d'intervention ? */
    private function decritProbleme(string $t): bool
    {
        foreach (AssistantLangue::OBJETS as [$re]) if (preg_match($re, $t)) {
            // Un objet seul (« porte ») ne suffit pas si le message est une question sur autre chose.
            if (preg_match('/^ (?:qui|quel|quelle|quels|quelles|comment|pourquoi|est ce que tu) /', $t) && !$this->probleme($t)) return false;
            return true;
        }
        return $this->probleme($t) !== null && !preg_match('/^ (?:qui|quel|quelle|quels|quelles) /', $t);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Création de demande
    // ═══════════════════════════════════════════════════════════════════════

    private function creer(string $message, array $historique): array
    {
        $t = ' ' . AssistantLangue::preparer($message) . ' ';
        // Complément d'une demande préparée juste avant (« c'est au bureau 204 ») : on reprend sa description.
        $prec = $this->echangePrecedent($historique);
        if ($prec && !$this->objet($t) && !$this->probleme($t) && $this->estLieuSeul($t)) {
            $message = rtrim($prec, " .!") . '. ' . AssistantLangue::capitale($message);
            $t = ' ' . AssistantLangue::preparer($message) . ' ';
        }
        $f = $this->champs($message, $t);
        $jeton = '[ACTION:nouvelle_demande|type=Technique|titre=' . self::val($f['titre']) . '|description=' . self::val($f['description'])
               . '|batiment=' . self::val($f['batiment']) . '|bureau=' . self::val($f['bureau'])
               . '|categorie=' . self::val($f['categorie']) . '|urgence=' . $f['urgence'] . ']';
        $txt = "Je te prépare la demande « " . $f['titre'] . " », tu n'auras plus qu'à vérifier et valider.";
        if ($f['batiment'] === '' && $f['bureau'] === '') $txt .= "\nPense à préciser le lieu (bâtiment, étage, bureau) dans le formulaire.";
        if ($f['urgence'] === 'Urgente') {
            $g = $this->ctx['gestionnaires'][0] ?? null;
            $txt .= "\n⚠️ Si la situation est dangereuse, préviens aussi directement un gestionnaire"
                  . ($g ? ' : **' . AssistantLangue::texte($g['nom']) . '**' . ($g['tel'] !== '' ? ' (📞 ' . AssistantLangue::texte($g['tel']) . ')' : '') : '') . '.';
        }
        return ['texte' => $txt . "\n" . $jeton, 'trouve' => true, 'champs' => ['demande' => $f]];
    }

    /** Champs du formulaire, extraits du message. */
    public function champs(string $message, ?string $t = null): array
    {
        $t ??= ' ' . AssistantLangue::preparer($message) . ' ';
        $lieu = $t;
        $etage = AssistantLangue::extraireEtage($lieu, true);
        [$batiment, $batBrut] = AssistantLangue::extraireBatiment($lieu, $this->ctx['batiments']);
        $salle = null;
        if (preg_match('/\b(bureau|salle|local|piece|box)\s+(?:numero\s+)?([a-z]?\d{1,4}[a-z]?)\b/', $lieu, $m)) {
            $salle = AssistantLangue::capitale(['piece' => 'pièce'][$m[1]] ?? $m[1]) . ' ' . strtoupper($m[2]);
        } elseif (preg_match('/\b(accueil|hall(?: d entree)?|couloir|cafeteria|cuisine|salle de pause|salle de reunion(?: [a-z0-9]+)?|salle (?:informatique|serveurs?|de sport|polyvalente|de repos)|open space|escaliers?|local (?:technique|menage|poubelles?|electrique|velos?|archives?)|reprographie|standard|parking|restaurant|self|cantine|refectoire|vestiaires?|chaufferie|atelier|magasin|infirmerie|bibliotheque|gymnase|garage|cave|grenier|terrasse|cour|jardin|entree|quai|sas)\b/', $lieu, $m)) {
            $salle = AssistantLangue::capitale(strtr($m[1], ['cafeteria' => 'cafétéria', 'salle de reunion' => 'salle de réunion', 'hall d entree' => 'hall d\'entrée',
                'local menage' => 'local ménage', 'local electrique' => 'local électrique', 'refectoire' => 'réfectoire', 'bibliotheque' => 'bibliothèque',
                'escaliers' => 'escaliers', 'entree' => 'entrée', 'local velos' => 'local vélos', 'local velo' => 'local vélo', 'salle serveurs' => 'salle serveurs']));
        }
        $bureau = implode(', ', array_filter([$salle, $etage !== null ? AssistantLangue::libelleNiveau($etage) : null]));
        // Bâtiment cité mais inconnu de la liste : on le garde dans le champ lieu (la liste du formulaire ne l'accepterait pas).
        if ($batiment === null && $batBrut !== null) $bureau = trim('Bâtiment ' . (strlen($batBrut) <= 2 ? strtoupper($batBrut) : AssistantLangue::capitale($batBrut)) . ($bureau !== '' ? ', ' . $bureau : ''));

        $objet = $this->objet($t) ?? ($this->indiceObjet !== null ? $this->objet($this->indiceObjet) : null);
        $pb = $this->probleme($t);
        return [
            'titre'       => $this->titre($objet, $pb, $message, $t),
            'description' => $this->description($message),
            'batiment'    => $batiment ?? '',
            'bureau'      => $bureau,
            'categorie'   => $this->categorie($objet, $pb),
            'urgence'     => $this->urgence($t),
        ];
    }

    /** Objet principal : [clé, libellé, genre, nombre, indices] ou null. */
    private function objet(string $t): ?array
    {
        foreach (AssistantLangue::OBJETS as $k => [$re, $lib, $g, $n, $ind]) {
            if (preg_match($re, $t)) return [$k, $lib, $g, $n, $ind];
        }
        return null;
    }

    /** Problème principal : [clé, formes, libellé] ou null. */
    private function probleme(string $t): ?array
    {
        foreach (AssistantLangue::PROBLEMES as $k => [$re, $formes, $lib]) {
            if (preg_match($re, $t)) return [$k, $formes, $lib];
        }
        return null;
    }

    /** Titre court : l'objet et son problème, sans lieu (« Poignée de porte défectueuse »). */
    private function titre(?array $objet, ?array $pb, string $message, string $t): string
    {
        if ($objet && $pb) {
            [, $lib, $g, $n] = $objet;
            [$k, $formes, $plib] = $pb;
            $titre = match (true) {
                $k === 'fuite' && in_array($objet[0], ['robinet', 'lavabo', 'toilettes', 'chasse', 'douche', 'canalisation', 'eau_chaude', 'radiateur', 'plafond', 'climatisation', 'toiture'], true)
                    => "Fuite d'eau (" . mb_strtolower($lib) . ')',
                in_array($k, ['gaz', 'fumee'], true) => $plib . ' (' . mb_strtolower($lib) . ')',
                $k === 'alarme'   => $lib . ($objet[0] === 'alarme' ? ' qui se déclenche' : ' en alarme'),
                $k === 'froid'    => in_array($objet[0], ['chauffage', 'radiateur'], true) ? $lib . ' insuffisant' : 'Température trop basse (' . mb_strtolower($lib) . ')',
                $k === 'chaud'    => in_array($objet[0], ['climatisation', 'ventilation'], true) ? $lib . ' insuffisante' : 'Température trop élevée (' . mb_strtolower($lib) . ')',
                $k === 'manque'   => 'Manque : ' . mb_strtolower($lib),
                in_array($k, ['remplacer', 'installer', 'reparer', 'regler', 'deplacer'], true) => $plib . ' : ' . mb_strtolower($lib),
                in_array($k, ['bruit', 'odeur', 'sale'], true) => $plib . ' (' . mb_strtolower($lib) . ')',
                $formes !== null => $lib . ' ' . AssistantLangue::accord($formes, $g, $n),
                default => $lib . ' : ' . mb_strtolower($plib),
            };
        } elseif ($objet) {
            [, $lib, $g, $n] = $objet;
            $titre = $lib . ' ' . AssistantLangue::accord(['défectueux', 'défectueuse'], $g, $n);
            if (in_array($objet[0], ['nettoyage', 'nuisibles', 'demenagement', 'papier'], true)) $titre = $lib;
        } elseif ($pb) {
            $titre = $pb[2] === 'Problème' ? '' : $pb[2];
        } else {
            $titre = '';
        }
        if ($titre === '') {
            // Rien de connu : les premiers mots utiles du message, lieu retiré.
            $x = $t;
            AssistantLangue::extraireEtage($x, true);
            AssistantLangue::extraireBatiment($x, $this->ctx['batiments']);
            $x = preg_replace('/\b(?:bureau|salle|local|piece|box)\s+(?:numero\s+)?[a-z]?\d{1,4}[a-z]?\b/', ' ', $x);
            $vides = array_flip(array_merge(AssistantLangue::VIDES, ['bonjour', 'salut', 'probleme', 'problemes', 'souci', 'pb', 'il', 'y', 'a', 'dans', 'mon', 'ma', 'au', 'du']));
            $mots = array_values(array_filter(AssistantLangue::mots(trim(preg_replace('/\s+/', ' ', $x))), fn($w) => !isset($vides[$w]) && !ctype_digit($w)));
            $titre = $mots ? AssistantLangue::capitale(implode(' ', array_slice($mots, 0, 6))) : 'Demande d\'intervention';
        }
        return AssistantLangue::libelle($titre, 70);
    }

    /** Description fidèle au message : fautes métier corrigées, rien d'inventé. */
    private function description(string $message): string
    {
        $d = trim(preg_replace('/\s+/u', ' ', $message));
        $d = preg_replace('/^(?:bonjour|bonsoir|salut|hello|coucou)[\s,!.]*/iu', '', $d);
        $d = preg_replace('/[\s,]*(?:merci(?: beaucoup| d avance| d\'avance)?|svp|stp)[\s!.]*$/iu', '', $d);
        // Contexte bâtiment : « poignet » est une poignée ; quelques accents courants.
        $d = preg_replace_callback('/\b(poignets?|probleme|problemes|etage|etages|2eme|3eme|4eme|1ere|electricite|fenetre|fenetres|chaudiere|ascenceur|ascenceurs)\b/iu', function ($m) {
            $map = ['poignet' => 'poignée', 'poignets' => 'poignées', 'probleme' => 'problème', 'problemes' => 'problèmes',
                    'etage' => 'étage', 'etages' => 'étages', '2eme' => '2e', '3eme' => '3e', '4eme' => '4e', '1ere' => '1re',
                    'electricite' => 'électricité', 'fenetre' => 'fenêtre', 'fenetres' => 'fenêtres', 'chaudiere' => 'chaudière',
                    'ascenceur' => 'ascenseur', 'ascenceurs' => 'ascenseurs'];
            return $map[mb_strtolower($m[1])] ?? $m[1];
        }, $d);
        $d = AssistantLangue::capitale($d);
        if ($d !== '' && !preg_match('/[.!?]$/u', $d)) $d .= '.';
        return mb_substr($d, 0, 500);
    }

    /** Catégorie du site correspondant à l'objet (jamais inventée). */
    private function categorie(?array $objet, ?array $pb): string
    {
        // Le problème l'emporte quand il désigne un métier (une fuite au plafond relève de la plomberie).
        $parProbleme = match ($pb[0] ?? '') {
            'fuite', 'bouche' => ['plomb'], 'gaz' => ['chauff', 'gaz'], 'fumee' => ['incend', 'securit'], default => [],
        };
        $indices = array_merge($parProbleme, $objet[4] ?? []);
        if (!$objet && $pb && !$parProbleme) $indices = match ($pb[0]) {
            'froid', 'chaud' => ['chauff', 'climat', 'cvc'], 'alarme' => ['incend', 'securit'],
            'sale', 'odeur' => ['nettoy', 'propre'], default => [],
        };
        if (!$indices) return '';
        foreach ($indices as $ind) {
            foreach ($this->ctx['categories'] as $cat) {
                if (str_contains(AssistantLangue::norm($cat), $ind)) return $cat;
            }
        }
        return '';
    }

    /** Urgence : Basse, Normale, Haute ou Urgente (les valeurs du formulaire). */
    private function urgence(string $t): string
    {
        if (preg_match(AssistantLangue::URGENCES['Basse'], $t)) return 'Basse';
        if (preg_match(AssistantLangue::URGENCES['Urgente'], $t)) return 'Urgente';
        if (preg_match(AssistantLangue::URGENCES['Haute'], $t)) return 'Haute';
        return 'Normale';
    }

    /** Valeur sûre dans le jeton : ni |, ni ], ni =, ni retour à la ligne. */
    private static function val(?string $v): string
    {
        return trim(str_replace(['|', ']', '[', '=', "\n", "\r"], [' ', ' ', ' ', ' ', ' ', ' '], (string)$v));
    }

    private function estLieuSeul(string $t): bool
    {
        $x = $t;
        $e = AssistantLangue::extraireEtage($x, true);
        [$b, $bb] = AssistantLangue::extraireBatiment($x, $this->ctx['batiments']);
        $s = preg_match('/\b(?:bureau|salle|local|piece|box)\s+(?:numero\s+)?[a-z]?\d{1,4}[a-z]?\b/', $x);
        return $e !== null || $b !== null || $bb !== null || $s;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Archives
    // ═══════════════════════════════════════════════════════════════════════

    private function archive(string $message, string $t): array
    {
        $desarch = (bool)preg_match('/\b(?:desarchiv\w*|sortir|recuperer|consulter|ressortir|besoin du dossier|besoin des dossiers)\b/', $t);
        $desc = $this->description($message);
        if ($desarch) {
            preg_match_all('/\b([a-z]{2,6}-?\d[\w-]*)\b/i', $message, $mm);
            $dossiers = array_values(array_unique(array_map('strtoupper', array_filter($mm[1] ?? [], fn($c) => preg_match('/[a-z]/i', $c) && preg_match('/\d/', $c)))));
            $solde = '';
            if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\b/', $message, $m)) $solde = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            elseif (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $message, $m)) $solde = "$m[1]-$m[2]-$m[3]";
            $jeton = '[ACTION:nouvelle_demande|type=Archive|prestation=Desarchivage|description=' . self::val($desc)
                   . '|dossiers=' . self::val(implode(',', $dossiers)) . '|date_solde=' . $solde . ']';
            $txt = "C'est noté, je te prépare la demande de désarchivage." . (!$dossiers ? "\nIndique les numéros de dossier dans le formulaire." : '');
            return ['texte' => $txt . "\n" . $jeton, 'trouve' => true, 'champs' => ['demande' => ['type' => 'Archive', 'prestation' => 'Desarchivage', 'dossiers' => $dossiers, 'date_solde' => $solde]]];
        }
        $nb = '';
        if (preg_match('/\b(\d{1,5})\s+(?:dossiers?|boites?|cartons?|classeurs?)\b/', $t, $m)) $nb = $m[1];
        elseif (preg_match('/\bune? (dizaine|quinzaine|vingtaine|trentaine|quarantaine|cinquantaine|soixantaine|centaine)\b/', $t, $m)) {
            $nb = (string)['dizaine' => 10, 'quinzaine' => 15, 'vingtaine' => 20, 'trentaine' => 30, 'quarantaine' => 40, 'cinquantaine' => 50, 'soixantaine' => 60, 'centaine' => 100][$m[1]];
        }
        $periode = '';
        if (preg_match('/\b(?:de |entre |du )?((?:19|20)\d{2})\s*(?:a|au|et|-)\s*((?:19|20)\d{2})\b/', $t, $m)) $periode = "$m[1]-$m[2]";
        elseif (preg_match('/\b(?:de |en |annee )((?:19|20)\d{2})\b/', $t, $m)) $periode = $m[1];
        $service = $this->serviceCite($t);
        $jeton = '[ACTION:nouvelle_demande|type=Archive|prestation=Archivage|description=' . self::val($desc)
               . '|nb_dossiers=' . $nb . '|service=' . self::val($service) . '|periode=' . $periode . ']';
        return ['texte' => "C'est noté, je te prépare la demande d'archivage.\n" . $jeton, 'trouve' => true,
                'champs' => ['demande' => ['type' => 'Archive', 'prestation' => 'Archivage', 'nb_dossiers' => $nb, 'service' => $service, 'periode' => $periode]]];
    }

    /** Service cité (« dossiers RH » → « Ressources Humaines » s'il existe dans l'annuaire). */
    private function serviceCite(string $t): string
    {
        $abr = ['rh' => 'ressources humaines', 'drh' => 'ressources humaines', 'compta' => 'comptab', 'dsi' => 'informatique',
                'si' => 'informatique', 'daf' => 'financ', 'st' => 'technique', 'com' => 'communication'];
        foreach ($this->ctx['services'] as $s) {
            $n = AssistantLangue::preparer($s);
            if ($n !== '' && str_contains($t, ' ' . $n . ' ')) return $s;
        }
        foreach ($abr as $a => $cible) {
            if (!preg_match('/\b' . $a . '\b/', $t)) continue;
            foreach ($this->ctx['services'] as $s) if (str_contains(AssistantLangue::norm($s), $cible)) return $s;
            return $a === 'rh' || $a === 'drh' ? 'Ressources Humaines' : '';
        }
        if (preg_match('/\bservice (?:des |du |de la |de l )?([a-z]+(?: [a-z]+)?)\b/', $t, $m)) return AssistantLangue::capitale($m[1]);
        return '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Suivi, contacts, rôles, catégories, modules
    // ═══════════════════════════════════════════════════════════════════════

    private function suivi(string $t): array
    {
        $ds = $this->ctx['demandes'];
        if (!$ds) return ['texte' => "Tu n'as encore aucune demande enregistrée. Décris-moi ton problème et je te prépare la première !", 'trouve' => true];
        $id = null;
        if (preg_match('/#\s*(\d+)/', $t, $m) || preg_match('/\bdemande (?:numero )?(\d+)\b/', $t, $m) || preg_match('/\bnumero (\d+)\b/', $t, $m)) $id = (int)$m[1];
        if ($id !== null) {
            foreach ($ds as $d) if ((int)$d['Id'] === $id) return ['texte' => $this->etatDemande($d), 'trouve' => true];
            return ['texte' => "Je ne trouve pas la demande #$id parmi tes 10 dernières demandes. Vérifie le numéro, ou contacte un gestionnaire.", 'trouve' => true];
        }
        if (preg_match('/\b(?:mes demandes|mes tickets|toutes? mes|liste)\b/', $t)) {
            $l = array_map(fn($d) => '- **#' . (int)$d['Id'] . ' ' . AssistantLangue::texte($d['Titre'] ?? '') . '** — ' . AssistantLangue::texte($d['Statut'] ?: '?')
                . ', ' . AssistantLangue::date((string)($d['DateCreation'] ?? '')), $ds);
            return ['texte' => "Tes dernières demandes :\n" . implode("\n", $l), 'trouve' => true];
        }
        // Mot-clé cité (« ma demande pour la fuite ») : la plus récente qui le contient.
        $mots = array_filter(AssistantLangue::mots(trim($t)), fn($w) => strlen($w) >= 4 && !in_array($w, ['demande', 'demandes', 'derniere', 'dernier', 'statut', 'avancement', 'suivi', 'traitee', 'occupe', 'quand', 'sera'], true));
        foreach ($ds as $d) {
            $h = AssistantLangue::norm(($d['Titre'] ?? '') . ' ' . ($d['Categorie'] ?? '') . ' ' . ($d['Batiment'] ?? ''));
            foreach ($mots as $w) if (strlen($w) >= 4 && str_contains($h, $w)) return ['texte' => $this->etatDemande($d), 'trouve' => true];
        }
        return ['texte' => $this->etatDemande($ds[0]), 'trouve' => true];
    }

    private function etatDemande(array $d): string
    {
        $statut = (string)($d['Statut'] ?? '');
        $etat = ['Nouveau' => 'en attente de prise en charge', 'Relancé' => 'relancée, en attente de prise en charge',
                 'En cours' => 'en cours de traitement', 'Traité' => 'traitée', 'Refusé' => 'refusée'][$statut] ?? ($statut ?: 'sans statut');
        $txt = 'Ta demande **#' . (int)$d['Id'] . ' « ' . AssistantLangue::texte($d['Titre'] ?? '') . ' »** (créée le ' . AssistantLangue::date((string)($d['DateCreation'] ?? ''))
             . ') est **' . AssistantLangue::texte($etat) . '**' . (!empty($d['Urgence']) ? ' (urgence : ' . mb_strtolower((string)$d['Urgence']) . ')' : '') . '.';
        if (!empty($d['InterventionNumero'])) {
            $agent = trim(($d['AgentPrenom'] ?? '') . ' ' . ($d['AgentNom'] ?? ''));
            $txt .= "\nUne intervention est prévue : **" . AssistantLangue::texte($d['InterventionNumero']) . '** (' . AssistantLangue::texte($d['InterventionStatut'] ?: '?')
                  . (!empty($d['InterventionDate']) ? ', le ' . AssistantLangue::date((string)$d['InterventionDate']) : '') . ')'
                  . ($agent !== '' ? ', suivie par **' . AssistantLangue::texte($agent) . '**' : '') . '.';
        } elseif (in_array($statut, ['Nouveau', 'Relancé', 'Demandeur'], true)) {
            $g = $this->ctx['gestionnaires'][0] ?? null;
            if ($g) $txt .= "\nSi c'est pressé, contacte **" . AssistantLangue::texte($g['nom']) . '**' . ($g['tel'] !== '' ? ' (📞 ' . AssistantLangue::texte($g['tel']) . ')' : '') . '.';
        }
        if (!empty($d['CommentaireAdmin'])) $txt .= "\nCommentaire du gestionnaire : « " . AssistantLangue::texte($d['CommentaireAdmin'], 200) . ' »';
        return $txt;
    }

    private function contacts(string $t): array
    {
        $g = $this->ctx['gestionnaires'];
        if (!$g) return ['texte' => "Aucun gestionnaire n'est enregistré. En cas d'urgence, contacte ton responsable de service.", 'trouve' => true];
        $l = array_map(function ($p) {
            $coord = array_filter([$p['tel'] !== '' ? '📞 ' . $p['tel'] : '', $p['email'] !== '' ? '✉️ ' . $p['email'] : '']);
            return '- **' . AssistantLangue::texte($p['nom']) . '**' . ($p['detail'] !== '' ? ' (' . AssistantLangue::texte($p['detail']) . ')' : '')
                 . ($coord ? ' — ' . AssistantLangue::texte(implode(', ', $coord), 160) : '');
        }, $g);
        $txt = "Tu peux contacter un gestionnaire :\n" . implode("\n", $l);
        if ($this->decritProbleme($t)) $txt .= "\nJe peux aussi te préparer la demande tout de suite : décris-moi le problème et le lieu.";
        return ['texte' => $txt, 'trouve' => true];
    }

    private function roles(): array
    {
        return ['texte' => "Dans Larka :\n- **Demandeur** : crée et suit ses demandes d'intervention (c'est ton rôle).\n"
            . "- **Gestionnaire** : reçoit les demandes, les valide et les transforme en interventions.\n"
            . "- **Technicien** : réalise les interventions (en interne ou chez un prestataire).\n"
            . "- **Admin** : configure l'application et gère les comptes.\nEn cas d'urgence, contacte un gestionnaire.", 'trouve' => true];
    }

    private function categories(string $t): array
    {
        $cats = $this->ctx['categories'];
        if (!$cats) return ['texte' => "Aucune catégorie n'est définie : laisse le champ vide, le gestionnaire la choisira.", 'trouve' => true];
        $objet = $this->objet($t); $pb = $this->probleme($t);
        if ($objet || ($pb && !in_array($pb[0], ['defaut', 'manque'], true))) {
            $c = $this->categorie($objet, $pb);
            if ($c !== '') return ['texte' => 'Pour ' . ($objet ? mb_strtolower($objet[1]) : mb_strtolower($pb[2])) . ", choisis la catégorie **" . AssistantLangue::texte($c) . "**.\nVeux-tu que je prépare la demande ? Décris-moi le problème et le lieu.", 'trouve' => true];
        }
        return ['texte' => 'Catégories disponibles : ' . AssistantLangue::texte(implode(', ', $cats), 400) . '.', 'trouve' => true];
    }

    private function moduleCite(string $t): ?array
    {
        foreach ($this->ctx['modules'] as $p) {
            $n = AssistantLangue::preparer($p['titre']);
            if ($n !== '' && strlen($n) >= 4 && str_contains($t, ' ' . $n . ' ')) return $p;
        }
        return null;
    }

    private function modules(string $t): array
    {
        $p = $this->ctx['modules'];
        if (!$p) return ['texte' => "Aucun module complémentaire ne t'est ouvert. Pour tout besoin d'intervention, je peux te préparer une demande.", 'trouve' => true];
        $cite = $this->moduleCite($t);
        if ($cite) $p = [$cite];
        $l = array_map(fn($x) => '- ' . AssistantLangue::texte($x['titre']) . ' (' . AssistantLangue::texte($x['nom']) . ') → [MODULE:' . $x['id'] . ':' . AssistantLangue::libelle($x['titre']) . ']', $p);
        return ['texte' => "Modules accessibles :\n" . implode("\n", $l), 'trouve' => true];
    }

    private function politesse(string $t): array
    {
        if (preg_match('/\b(?:merci|parfait|super|top|genial|nickel|cool)\b/', $t)) return ['texte' => "Avec plaisir ! Besoin d'autre chose ?", 'trouve' => true];
        if (preg_match('/\b(?:au revoir|bye|a plus|bonne (?:journee|soiree))\b/', $t)) return ['texte' => 'Bonne journée !', 'trouve' => true];
        return ['texte' => "Bonjour ! Décris-moi ton problème (quoi, où) et je prépare la demande pour toi. Tu peux aussi me demander où en est ta demande, ou qui contacter.", 'trouve' => true];
    }

    private function aide(): array
    {
        return ['texte' => "Je peux :\n- **préparer une demande** : décris le problème et le lieu (« la lumière du couloir du 2e ne marche plus ») ;\n"
            . "- **suivre tes demandes** : « où en est ma dernière demande ? » ;\n- te dire **qui contacter** en cas d'urgence ;\n"
            . "- t'aider à choisir une **catégorie**.", 'trouve' => true];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Historique
    // ═══════════════════════════════════════════════════════════════════════

    private function derniereReponse(array $historique): ?string
    {
        for ($i = count($historique) - 1; $i >= 0; $i--) {
            if (($historique[$i]['role'] ?? '') === 'assistant') return (string)($historique[$i]['content'] ?? '');
            if (($historique[$i]['role'] ?? '') === 'user') return null;
        }
        return null;
    }

    /** Dernière description de problème de l'utilisateur, si l'assistant y a répondu par une demande préparée. */
    private function echangePrecedent(array $historique): ?string
    {
        $rep = null;
        for ($i = count($historique) - 1; $i >= 0; $i--) {
            $h = $historique[$i];
            if (($h['role'] ?? '') === 'assistant' && $rep === null) { $rep = (string)($h['content'] ?? ''); continue; }
            if (($h['role'] ?? '') === 'user') {
                if ($rep !== null && str_contains($rep, 'Je te prépare la demande')) {
                    $q = trim((string)($h['content'] ?? ''));
                    return $q !== '' ? $q : null;
                }
                return null;
            }
        }
        return null;
    }
}
