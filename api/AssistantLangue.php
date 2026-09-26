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
 * Larka — Assistant : langue française (normalisation, lexiques, formatage)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Tout ce qui touche au français, au même endroit et SANS état : le moteur de
 * compréhension (AssistantComprehension), le moteur de réponse (AssistantMoteur)
 * et l'assistant des demandeurs (AssistantDemandeur) s'en servent.
 *
 * Pourquoi un lexique écrit à la main plutôt que « laisser le modèle comprendre » :
 * un 0,6B et un 7B ne comprennent pas la même chose. Un lexique, si. C'est ce qui
 * garantit qu'une même question produit la même réponse quel que soit le modèle.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class AssistantLangue
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Normalisation
    // ═══════════════════════════════════════════════════════════════════════

    /** Minuscules, sans accents ni ligatures. « Écran Œil » → « ecran oeil ». */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => "'", '‘' => "'", '`' => "'", 'ʼ' => "'"]);
        if (class_exists('Normalizer')) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) return preg_replace('/\p{Mn}+/u', '', $d) ?? $s;
        }
        return strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
        ]);
    }

    /**
     * Texte prêt pour les motifs : minuscules sans accents, apostrophes et
     * ponctuation remplacées par des espaces. Les tirets et barres ne sont
     * conservés qu'au contact d'un chiffre (EXT-002, 2018-2023, 1/2) ;
     * « rez-de-chaussée », « a-t-il », « est-ce » deviennent des mots séparés.
     */
    public static function preparer(string $q): string
    {
        $n = self::norm($q);
        $n = preg_replace('/\bn\s*°\s*/u', ' numero ', $n);
        $n = preg_replace('/\b(?:no|num|nr)\.?\s+(?=\d)/u', ' numero ', $n);
        $n = str_replace(['°', "'", '"', '«', '»', '…'], ' ', $n);
        $n = preg_replace('/(?<![0-9])[-\/](?![0-9])/u', ' ', $n);
        $n = preg_replace('/[^a-z0-9#+\-\/ ]+/u', ' ', $n);
        $n = preg_replace('/\s+/', ' ', $n);
        return trim($n);
    }

    /** Mots d'un texte préparé. */
    public static function mots(string $prepare): array
    {
        return $prepare === '' ? [] : explode(' ', $prepare);
    }

    /** Distance tolérée pour une faute de frappe, selon la longueur du mot. */
    public static function toleranceFaute(string $mot): int
    {
        $l = strlen($mot);
        return $l <= 3 ? 0 : ($l <= 5 ? 1 : ($l <= 8 ? 2 : 3));
    }

    /**
     * Mot le plus proche dans une liste (clés = mots normalisés), à distance
     * tolérée. Égalité de distance : le premier dans l'ordre alphabétique —
     * le résultat ne dépend JAMAIS de l'ordre d'arrivée des données.
     */
    public static function plusProche(string $mot, array $vocab, ?int $max = null): ?string
    {
        if ($mot === '' || isset($vocab[$mot])) return isset($vocab[$mot]) ? $mot : null;
        $max ??= self::toleranceFaute($mot);
        if ($max <= 0) return null;
        $best = null; $bestD = PHP_INT_MAX;
        foreach ($vocab as $v => $_) {
            $v = (string)$v;
            if (abs(strlen($v) - strlen($mot)) > $max) continue;
            // Premier caractère identique exigé pour les mots courts : « pompe »
            // ne doit pas devenir « tombe ».
            if (strlen($mot) <= 6 && $v[0] !== $mot[0]) continue;
            $d = levenshtein($mot, $v);
            if ($d < $bestD || ($d === $bestD && strcmp($v, (string)$best) < 0)) { $bestD = $d; $best = $v; }
        }
        return ($best !== null && $bestD <= $max) ? $best : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Lexiques
    // ═══════════════════════════════════════════════════════════════════════

    /** Mots vides (forme normalisée) : jamais des mots-clés de recherche. */
    public const VIDES = [
        'le', 'la', 'les', 'l', 'un', 'une', 'des', 'de', 'du', 'd', 'au', 'aux', 'a', 'en', 'et', 'ou', 'est', 'sont',
        'ce', 'cet', 'cette', 'ces', 'se', 'sa', 'son', 'ses', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'notre', 'nos',
        'votre', 'vos', 'leur', 'leurs', 'je', 'j', 'tu', 't', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles', 'me',
        'm', 'te', 'y', 'qui', 'que', 'qu', 'quoi', 'dont', 'quel', 'quelle', 'quels', 'quelles', 'lequel', 'laquelle',
        'lesquels', 'lesquelles', 'pour', 'par', 'avec', 'sans', 'sur', 'sous', 'dans', 'chez', 'vers', 'entre', 'pas',
        'ne', 'n', 'plus', 'tres', 'trop', 'peu', 'c', 's', 'ai', 'as', 'avons', 'avez', 'ont', 'avoir', 'etre', 'suis',
        'es', 'sommes', 'etes', 'fait', 'faire', 'peux', 'peut', 'pouvez', 'puis', 'voudrais', 'veux', 'voulez',
        'aimerais', 'stp', 'svp', 'merci', 'please', 'moi', 'toi', 'lui', 'eux', 'alors', 'donc', 'car', 'mais', 'si',
        'actuellement', 'actuel', 'actuelle', 'actuels', 'actuelles', 'ya', 'yen', 'ca', 'cela', 'ceci', 'celui',
        'celle', 'ceux', 'celles', 'la-bas', 'ici', 'dis', 'sais', 'savoir', 'connaitre', 'besoin', 'faut', 'falloir',
        'estce', 'quelqu', 'chose', 'choses', 'truc', 'trucs', 'machin', 'machins', 'bidule', 'bidules', 'trucmuche',
        'engin', 'engins', 'svp', 'the', 'of', 'and', 'what', 'where', 'how',
        'bonjour', 'salut', 'hello', 'coucou', 'bonsoir', 'svp', 'please', 'aussi', 'encore', 'deja', 'juste',
        'exactement', 'precisement', 'environ', 'bien', 'mal', 'etc', 'meme', 'autre', 'autres',
        // Contractions tapées sans apostrophe : « jai », « cest », « quil »…
        'jai', 'cest', 'quil', 'quelle', 'quon', 'quest', 'jsuis', 'chui', 'jpeux', 'jveux', 'tas', 'ta', 'yen',
        'aurais', 'aurait', 'avais', 'avait', 'vu', 'voir', 'sais', 'savez', 'connais', 'dire',
        'jcherche', 'jcherchais', 'chercherais', 'cherchais', 'jvoudrais', 'jaimerais', 'besoin', 'bonne', 'bon',
        'arrive', 'arrivent', 'arriver', 'arrivant', 'bientot', 'prochainement', 'terme', 'termes', 'echeance', 'echeances',
        // Auxiliaires et participes sans contenu (« quand a été faite… », « a-t-elle été… »)
        'ete', 'eu', 'eue', 'etait', 'etaient', 'sera', 'seront', 'serait', 'faite', 'faites', 'faits',
    ];

    /** Mots de question, de commande ou de quantité (ignorés comme mots-clés). */
    public const OUTILS = [
        'combien', 'cb', 'kombien', 'nombre', 'nb', 'total', 'totale', 'totaux', 'quantite', 'quantites', 'compte', 'compter',
        'liste', 'lister', 'listes', 'list', 'tous', 'toutes', 'tout', 'toute', 'affiche', 'afficher', 'affichez',
        'montre', 'montrer', 'montrez', 'donne', 'donner', 'donnez', 'cherche', 'chercher', 'cherchez', 'recherche',
        'rechercher', 'trouve', 'trouver', 'trouvez', 'retrouve', 'retrouver', 'voir', 'consulter', 'ouvre', 'ouvrir',
        'fiche', 'fiches', 'detail', 'details', 'detaille', 'info', 'infos', 'information', 'informations',
        'caracteristique', 'caracteristiques', 'decris', 'decrire', 'resume', 'ou', 'quand', 'comment', 'pourquoi',
        'localiser', 'localise', 'localisation', 'emplacement', 'emplacements', 'situe', 'situee', 'situes', 'situees',
        'trouvent', 'range', 'rangee', 'ranges', 'rangees', 'position', 'numero', 'numeros', 'num', 'existe', 'existent',
        'dispo', 'disponible', 'disponibles', 'reste', 'restent', 'rester', 'possede', 'possedons', 'avoir', 'present',
        'presents', 'presente', 'presentes', 'enregistre', 'enregistres', 'enregistree', 'enregistrees', 'recense',
        'recenses', 'inventorie', 'inventories', 'repertorie', 'repertories', 'actif', 'plan', 'plans', 'sur',
        'statut', 'statuts', 'etat', 'etats', 'avancement', 'suivi', 'date', 'dates', 'dernier', 'derniere', 'derniers',
        'dernieres', 'prochain', 'prochaine', 'prochains', 'prochaines', 'recent', 'recente', 'recents', 'recentes',
        'lie', 'lies', 'liee', 'liees', 'relatif', 'relatifs', 'relative', 'relatives', 'concernant', 'concerne',
        'associe', 'associes', 'associee', 'associees', 'rattache', 'rattaches', 'rattachee', 'rattachees',
        'correspondant', 'correspondants', 'correspondante', 'correspondantes', 'propos', 'niveau',
        'marque', 'marques', 'fabricant', 'fabricants', 'historique', 'historiques', 'liste', 'ensemble',
    ];

    /**
     * Types de données, du plus spécifique au plus général. Motifs appliqués sur
     * le texte préparé (sans accents). Le premier type trouvé l'emporte, sauf
     * « plans » et « stock » qui ne s'imposent que s'ils sont seuls.
     */
    public const TYPES = [
        'interventions' => '/\b(?:interventions?|interv|intervs|inter|ots?|ordres? de travail|bons? de travail|travaux|depannages?|reparations?|maintenances? (?:preventives?|curatives?)|preventives?|curatives?|controles? reglementaires?)\b/',
        'contrats'      => '/\b(?:contrats?|marches? publics?|abonnements?)\b/',
        'demandes'      => '/\b(?:demandes?|di|tickets?|signalements?|requetes?|reclamations?)\b/',
        'equipements'   => '/\b(?:equipements?|equipts?|equip|eqpts?|installations? techniques?)\b/',
        'biens'         => '/\b(?:biens|(?:le|les|des|un|du|ce|ces|mes|nos|vos|de|au|aux|quel|quels|quelle|chaque|votre|notre) bien|immobilisations?|mobiliers?)\b/',
        'documents'     => '/\b(?:documents?|docs?|fichiers?|pdf|notices?|rapports?|factures?|devis|pv|proces verbal|certificats?|attestations?|schemas?)\b/',
        'archives'      => '/\b(?:archives?|dossiers? d archives|boites? d archives|boites? archives?|cartons? d archives)\b/',
        'stock'         => '/\b(?:stocks?|articles?|pieces? (?:detachees?|de rechange)|consommables?|fournitures?|magasin|en stock|references? en stock)\b/',
        'plans'         => '/\b(?:points?|zones?|traits?|calques?|elements? (?:dessines?|du plan|des plans|sur (?:le|les) plans?|graphiques?)|textes? (?:du|sur le|des) plans?)\b/',
    ];

    /** Mots qui désignent un type (retirés des mots-clés une fois le type reconnu). */
    public const MOTS_TYPES = [
        'intervention', 'interventions', 'interv', 'intervs', 'inter', 'ot', 'ots', 'ordre', 'ordres', 'bon', 'bons',
        'travail', 'travaux', 'depannage', 'depannages', 'reparation', 'reparations', 'contrat', 'contrats', 'marche',
        'marches', 'public', 'publics', 'abonnement', 'abonnements', 'demande', 'demandes', 'di', 'ticket', 'tickets',
        'signalement', 'signalements', 'requete', 'requetes', 'reclamation', 'reclamations', 'equipement', 'equipements',
        'equipt', 'equipts', 'equip', 'eqpt', 'eqpts', 'installation', 'installations', 'technique', 'techniques', 'bien',
        'biens', 'immobilisation', 'immobilisations', 'document', 'documents', 'doc', 'docs', 'fichier', 'fichiers',
        'archive', 'archives', 'stock', 'stocks', 'article', 'articles', 'piece', 'pieces', 'detachee', 'detachees',
        'rechange', 'consommable', 'consommables', 'fourniture', 'fournitures', 'magasin', 'reference', 'references',
        'point', 'points', 'zone', 'zones', 'trait', 'traits', 'calque', 'calques', 'element', 'elements', 'dessine',
        'dessines', 'graphique', 'graphiques', 'texte', 'textes',
    ];

    /** Type interne → libellés affichés [singulier, pluriel, féminin ?]. */
    public const LIBELLES = [
        'biens'         => ['bien', 'biens', false],
        'equipements'   => ['équipement', 'équipements', false],
        'interventions' => ['intervention', 'interventions', true],
        'contrats'      => ['contrat', 'contrats', false],
        'demandes'      => ['demande', 'demandes', true],
        'stock'         => ['article de stock', 'articles de stock', false],
        'documents'     => ['document', 'documents', false],
        'plans'         => ['élément de plan', 'éléments de plan', false],
        'archives'      => ['dossier d\'archives', 'dossiers d\'archives', false],
    ];

    /** Type interne → type des liens [FICHE:type:…] compris par l'interface. */
    public const TYPE_FICHE = [
        'biens' => 'bien', 'equipements' => 'equipement', 'interventions' => 'intervention',
        'contrats' => 'contrat', 'demandes' => 'demande', 'stock' => 'stock',
    ];

    /**
     * Synonymes métier : un mot familier ou un sigle et ce qu'il désigne. Chaque
     * forme est une ALTERNATIVE du mot (« clim » est trouvé si la fiche dit
     * « climatiseur »). Les expressions de plusieurs mots sont cherchées telles
     * quelles, jamais mot à mot : « sécurité incendie » ne doit pas trouver toute
     * « centrale » du site.
     */
    public const SYNONYMES = [
        'clim'        => ['climatisation', 'climatiseur'],
        'clims'       => ['climatisation', 'climatiseur'],
        'climatiseur' => ['climatisation', 'clim'],
        'climatisation' => ['climatiseur', 'clim'],
        'frigo'       => ['refrigerateur'],
        'refrigerateur' => ['frigo'],
        'wc'          => ['toilettes', 'sanitaire'],
        'toilette'    => ['wc', 'sanitaire'],
        'toilettes'   => ['wc', 'sanitaire'],
        'neon'        => ['tube', 'luminaire'],
        'lumiere'     => ['eclairage', 'luminaire', 'ampoule'],
        'lampe'       => ['luminaire', 'ampoule', 'eclairage'],
        'pc'          => ['ordinateur'],
        'ordi'        => ['ordinateur', 'pc'],
        'ordinateur'  => ['pc'],
        // Sens large → sens précis seulement : « chauffage » trouve les chaudières,
        // mais « chaudière » ne doit pas ramener tout ce qui parle de chauffage.
        'chauffage'   => ['chaudiere', 'radiateur'],
        'ascenceur'   => ['ascenseur'],
        'ssi'         => ['securite incendie', 'alarme incendie', 'centrale incendie', 'detection incendie'],
        'baes'        => ['bloc autonome', 'eclairage de securite', 'eclairage securite', 'eclairage de secours'],
        'cta'         => ['centrale de traitement d air', 'traitement d air', 'centrale traitement air'],
        'vmc'         => ['ventilation mecanique', 'extraction d air'],
        'ecs'         => ['eau chaude sanitaire', 'ballon d eau chaude', 'ballon eau chaude'],
        'gtb'         => ['gestion technique du batiment', 'supervision'],
        'tgbt'        => ['tableau general basse tension'],
        'cvc'         => ['chauffage ventilation climatisation'],
        'pac'         => ['pompe a chaleur'],
        'pcs'         => ['poste central de securite'],
        'ria'         => ['robinet d incendie arme'],
        'pmr'         => ['personne a mobilite reduite', 'accessibilite'],
        'desenfumage' => ['des'],
    ];

    /**
     * Tournures familières → vocabulaire métier, appliquées AVANT l'analyse :
     * « la bouteille rouge qui éteint le feu » → « extincteur », « le truc qui
     * monte les étages » → « ascenseur », « les trucs à racheter » → « stock sous
     * le seuil ». C'est ce qu'on demandait autrefois au modèle — mais un 0,6B et
     * un 7B ne reformulent pas pareil. Un lexique, si.
     * (motif sur le texte préparé => remplacement)
     */
    public const EXPRESSIONS = [
        // Sécurité incendie
        '/\b(?:(?:bouteilles?|trucs?|machins?|bidules?|appareils?|engins?) )?(?:rouges? )?(?:qui eteint|qui eteignent|pour eteindre|a eteindre|anti ?feux?|anti ?incendies?|contre (?:le |les )?(?:feux?|incendies?))(?: (?:le |les |un |des )?feux?)?\b/' => ' extincteur ',
        '/\b(?:alarmes? (?:feu|incendie)|detection (?:incendie|feu)|detecteurs? (?:de )?fumee|(?:truc |machin )?qui detecte (?:la )?fumee)\b/' => ' ssi ',
        '/\b(?:(?:lumieres?|lampes?|eclairages?|blocs?|veilleuses?) (?:de |d )?(?:secours|urgence|sortie)|panneaux? (?:de )?sortie de secours)\b/' => ' baes ',
        // Chauffage, ventilation, climatisation
        '/\b(?:(?:qui |pour |a )?(?:rafraichi\w*|refroidi\w*) (?:l |la |le |les )?(?:air|pieces?|bureaux?|salles?|locaux)|air (?:frais|conditionne)|(?:(?:trucs?|machins?|appareils?) )?(?:qui fait|pour faire|qui souffle) (?:du )?froid)\b/' => ' climatisation ',
        '/\b(?:(?:qui |pour |a )?(?:souffl\w*|brass\w*|renouvel\w*|extrai\w*|aspir\w*) (?:de |d |l |du )*air|aeration|renouvellement d air)\b/' => ' ventilation ',
        '/\b(?:(?:qui |pour |a )?chauff\w* (?:l |d |de )?eau|chauffe eau|cumulus)\b/' => ' eau chaude ',
        // Électricité
        '/\b(?:(?:pour |qui )?(?:couper|coupe|remettre|retablir) (?:le |du |l )?(?:courant|jus|electricite)|disjoncteurs? (?:general|principal)|armoire electrique|tableau general)\b/' => ' tableau electrique ',
        // Ascenseur
        '/\b(?:(?:trucs?|machins?|appareils?|cabines?|bidules?) )?(?:qui |pour )?(?:monte\w*|grimpe\w*|descend\w*)(?: et (?:descend|monte)\w*)? (?:entre |a |aux |les |des |d )*(?:les )?etages?\b|\bmonte charges?\b/' => ' ascenseur ',
        // Stock
        '/\b(?:(?:trucs?|choses?|articles?|pieces?|produits?|fournitures?) )?(?:a |qu il faut |faut )?(?:racheter|recommander|reapprovisionner)\b/' => ' stock sous le seuil ',
        '/\b(?:en rupture|ruptures? de stock)\b/' => ' stock sous le seuil ',
        // Travaux
        '/\b(?:boulots?|taches?|jobs?|chantiers?|travaux) (?:(?:pas|non) )?(?:encore )?(?:finis?|termines?|faits?|acheves?|boucles?)(?: (?:qui trainent|en retard))?\b|\b(?:boulots?|taches?|jobs?|travaux) qui trainent\b/' => ' interventions en retard ',
        '/\b(?:boulots?|taches?|jobs?|chantiers?) (en cours|planifie\w*|prevus?|a venir)\b/' => ' interventions $1 ',
        // « quand a eu lieu… », « aura lieu » : tournure de date, pas un mot-clé.
        '/\b(?:(?:a|ont|avait|avaient|aurait) eu|aura|auront|aurait) lieu\b/' => ' ',
    ];

    /** Applique les tournures familières au texte préparé. */
    public static function reformuler(string $t): string
    {
        foreach (self::EXPRESSIONS as $re => $rempl) $t = preg_replace($re, $rempl, $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** Nombres et ordinaux écrits en lettres. */
    public const NOMBRES = [
        'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7,
        'huit' => 8, 'neuf' => 9, 'dix' => 10, 'onze' => 11, 'douze' => 12, 'quinze' => 15, 'vingt' => 20,
        'trente' => 30, 'quarante' => 40, 'cinquante' => 50, 'soixante' => 60, 'cent' => 100,
    ];
    public const ORDINAUX = [
        'premier' => 1, 'premiere' => 1, 'deuxieme' => 2, 'second' => 2, 'seconde' => 2, 'troisieme' => 3,
        'quatrieme' => 4, 'cinquieme' => 5, 'sixieme' => 6, 'septieme' => 7, 'huitieme' => 8, 'neuvieme' => 9,
        'dixieme' => 10,
    ];

    // ═══════════════════════════════════════════════════════════════════════
    //  Demandes d'intervention : objets, problèmes, urgence
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Objets d'une demande, du plus précis au plus général.
     * clé => [motif, libellé, genre (m|f), nombre (s|p), indices de catégorie]
     * Les indices sont comparés aux catégories RÉELLES du site : aucune
     * catégorie n'est inventée.
     */
    public const OBJETS = [
        'poignee_porte'   => ['/\bpoigne(?:e|es|t|ts)? (?:de |d )?(?:la |l )?portes?\b/', 'Poignée de porte', 'f', 's', ['serrur', 'menuis', 'batiment']],
        'poignee_fenetre' => ['/\bpoigne(?:e|es|t|ts)? (?:de |d )?(?:la |l )?fenetres?\b/', 'Poignée de fenêtre', 'f', 's', ['menuis', 'serrur', 'batiment']],
        'poignee'      => ['/\bpoigne(?:e|es|t|ts)?\b/', 'Poignée', 'f', 's', ['serrur', 'menuis', 'batiment']],
        'serrure'      => ['/\b(?:serrures?|verrous?|cylindres?|gaches?)\b/', 'Serrure', 'f', 's', ['serrur', 'menuis']],
        'cle'          => ['/\b(?:cle|cles|clef|clefs)\b/', 'Clé', 'f', 's', ['serrur']],
        'badge'        => ['/\b(?:badges?|lecteurs? de badges?|digicodes?|controle d acces)\b/', 'Badge / contrôle d\'accès', 'm', 's', ['acces', 'serrur', 'securit', 'electr']],
        'chasse'       => ['/\bchasses? d eau\b/', 'Chasse d\'eau', 'f', 's', ['plomb', 'sanit']],
        'robinet'      => ['/\b(?:robinets?|mitigeurs?|robinetterie)\b/', 'Robinet', 'm', 's', ['plomb']],
        'lavabo'       => ['/\b(?:lavabos?|eviers?|vasques?)\b/', 'Lavabo', 'm', 's', ['plomb']],
        'toilettes'    => ['/\b(?:toilettes?|wc|w c|cuvettes?|urinoirs?|sanitaires?)\b/', 'Toilettes', 'f', 'p', ['plomb', 'sanit']],
        'douche'       => ['/\bdouches?\b/', 'Douche', 'f', 's', ['plomb']],
        'canalisation' => ['/\b(?:canalisations?|tuyaux?|tuyauteries?|evacuations?|siphons?|egouts?)\b/', 'Canalisation', 'f', 's', ['plomb']],
        'eau_chaude'   => ['/\b(?:eau chaude|ballons? d eau chaude|chauffe eau|cumulus)\b/', 'Eau chaude', 'f', 's', ['plomb', 'chauff']],
        'radiateur'    => ['/\b(?:radiateurs?|convecteurs?)\b/', 'Radiateur', 'm', 's', ['chauff', 'cvc', 'climat']],
        'climatisation'=> ['/\b(?:clim|clims|climatisation|climatiseurs?|splits?|air conditionne|(?:qui |pour )?(?:rafraichi\w*|refroidi\w*) (?:l |la |le )?air)\b/', 'Climatisation', 'f', 's', ['climat', 'cvc', 'chauff']],
        'chauffage'    => ['/\b(?:chauffage|chaudieres?|thermostats?)\b/', 'Chauffage', 'm', 's', ['chauff', 'cvc', 'climat']],
        'ventilation'  => ['/\b(?:ventilation|vmc|aeration|cta|ventilateurs?|(?:qui |pour )?(?:souffl\w*|brass\w*|extrai\w*|aspir\w*) (?:de |d |l |du )*air)\b/', 'Ventilation', 'f', 's', ['ventil', 'cvc', 'climat', 'chauff']],
        'baes'         => ['/\b(?:baes|eclairages? de (?:secours|securite)|blocs? de secours)\b/', 'Éclairage de secours', 'm', 's', ['incend', 'securit', 'electr']],
        'eclairage'    => ['/\b(?:lumieres?|eclairages?|luminaires?|neons?|ampoules?|spots?|lampes?|dalles? led|tubes? led)\b/', 'Éclairage', 'm', 's', ['electr', 'eclair']],
        'prise'        => ['/\bprises?(?: electriques?| de courant)?\b/', 'Prise électrique', 'f', 's', ['electr']],
        'interrupteur' => ['/\binterrupteurs?\b/', 'Interrupteur', 'm', 's', ['electr']],
        'electricite'  => ['/\b(?:electricite|courant|disjoncteurs?|tableau electrique|coupure de courant|court circuit|plus de jus)\b/', 'Électricité', 'f', 's', ['electr']],
        'extincteur'   => ['/\b(?:extincteurs?|anti ?feux?|anti ?incendies?|qui eteint le feu)\b/', 'Extincteur', 'm', 's', ['incend', 'securit']],
        'alarme'       => ['/\b(?:alarmes?(?: incendie)?|centrale ssi|ssi|centrale incendie|detecteurs? de fumee|detecteurs?|sirenes?)\b/', 'Alarme incendie', 'f', 's', ['incend', 'securit', 'ssi']],
        'ascenseur'    => ['/\b(?:ascenseurs?|ascenceurs?|monte charges?|(?:qui )?(?:monte|grimpe) (?:les |aux |entre les )?etages)\b/', 'Ascenseur', 'm', 's', ['ascens', 'elevat']],
        'store'        => ['/\b(?:stores?|volets?|rideaux?|brise soleil)\b/', 'Store', 'm', 's', ['store', 'menuis', 'batiment']],
        'vitre'        => ['/\b(?:vitres?|vitrages?|carreaux?)\b/', 'Vitre', 'f', 's', ['vitr', 'menuis', 'batiment']],
        'fenetre'      => ['/\bfenetres?\b/', 'Fenêtre', 'f', 's', ['menuis', 'batiment']],
        'porte'        => ['/\bportes?\b/', 'Porte', 'f', 's', ['serrur', 'menuis', 'batiment']],
        'plafond'      => ['/\b(?:plafonds?|faux plafonds?|dalles? de plafond)\b/', 'Plafond', 'm', 's', ['batiment', 'second', 'menuis']],
        'sol'          => ['/\b(?:sol|sols|moquettes?|carrelages?|parquets?|lino)\b/', 'Revêtement de sol', 'm', 's', ['batiment', 'second']],
        'mur'          => ['/\b(?:murs?|cloisons?|peintures?)\b/', 'Mur', 'm', 's', ['batiment', 'peint', 'second']],
        'mobilier'     => ['/\b(?:chaises?|fauteuils?|tables?|armoires?|placards?|etageres?|mobilier|meubles?|caissons?|bureau (?:casse|abime|bancal)\w*)\b/', 'Mobilier', 'm', 's', ['mobil', 'menuis']],
        'informatique' => ['/\b(?:ordinateurs?|ordi|pc|ecrans?|imprimantes?|wifi|reseau|internet|photocopieuses?|copieurs?|telephones? fixes?)\b/', 'Matériel informatique', 'm', 's', ['inform', 'reseau']],
        'nuisibles'    => ['/\b(?:souris|rats?|cafards?|guepes?|nuisibles?|insectes?|punaises?|fourmis|pigeons?)\b/', 'Nuisibles', 'm', 'p', ['nuisib', 'hygien', 'nettoy', 'propre']],
        'nettoyage'    => ['/\b(?:nettoyage|menage|salete|poubelles?|dechets?|proprete)\b/', 'Nettoyage', 'm', 's', ['nettoy', 'propre', 'menage']],
        'papier'       => ['/\b(?:papier toilette|papier wc|essuie mains|savon|distributeurs?)\b/', 'Consommables sanitaires', 'm', 'p', ['nettoy', 'propre', 'sanit']],
        'parking'      => ['/\b(?:parking|barrieres?|portails?)\b/', 'Parking / portail', 'm', 's', ['exterieur', 'serrur', 'batiment']],
        'toiture'      => ['/\b(?:toiture|toit|gouttieres?)\b/', 'Toiture', 'f', 's', ['couvert', 'batiment']],
        'demenagement' => ['/\b(?:demenag\w*|deplacer (?:des |du |un |le |les )?(?:meubles?|mobilier|cartons?)|manutention)\b/', 'Déménagement / manutention', 'm', 's', ['demenag', 'manut', 'logist']],
    ];

    /**
     * Problèmes : clé => [motif, forme adjectivale m/f (null = nom), libellé seul].
     * L'ordre compte : le premier trouvé qualifie l'objet.
     */
    public const PROBLEMES = [
        'gaz'      => ['/\b(?:odeur de gaz|sent le gaz|fuite de gaz|gaz)\b/', null, 'Odeur de gaz'],
        'fumee'    => ['/\b(?:fumee|brule|odeur de brule|incendie|feu)\b/', null, 'Fumée / odeur de brûlé'],
        'fuite'    => ['/\b(?:fuites?|fuit|fuient|gouttes?|gouttent|coule|coulent|suinte\w*|inond\w*|degats? des eaux|infiltrations?)\b/', null, 'Fuite d\'eau'],
        'bouche'   => ['/\b(?:bouche\w*|obstrue\w*|deborde\w*|refoule\w*)\b/', ['bouché', 'bouchée'], 'Évacuation bouchée'],
        'bloque'   => ['/\b(?:bloque\w*|coince\w*|ne (?:se )?(?:ferme|s ouvre|ouvre|verrouille) (?:plus|pas)|ferme mal|s ouvre mal|grippe\w*)\b/', ['bloqué', 'bloquée'], 'Blocage'],
        'clignote' => ['/\b(?:clignot\w*|scintill\w*|gresill\w*)\b/', ['qui clignote', 'qui clignote'], 'Clignotement'],
        'alarme'   => ['/\b(?:bip\w*|sonne\w*|en alarme|se declenche\w*|hurle\w*)\b/', null, 'Alarme qui se déclenche'],
        'casse'    => ['/\b(?:casse\w*|brise\w*|arrache\w*|tombe\w*|abime\w*|fissure\w*|fendu\w*|endommage\w*|defonce\w*|decroche\w*|descelle\w*|pete\w*|nique\w*|foutu\w*|mort|morte)\b/', ['cassé', 'cassée'], 'Casse'],
        'panne'    => ['/\b(?:en panne|pannes?|ne (?:marche|fonctionne|s allume|demarre|chauffe|refroidit|repond|marche) (?:plus|pas)|marche (?:plus|pas)|fonctionne (?:plus|pas)|hs|hors service|grille\w*|claque\w*|dysfonction\w*|defaillan\w*|deconne\w*|plante\w*|ne s eteint (?:plus|pas))\b/', ['en panne', 'en panne'], 'Panne'],
        'froid'    => ['/\b(?:trop froid|il fait froid|fait froid|gele\w*|glacial|pas de chauffage|plus de chauffage|on caille|caille)\b/', null, 'Température trop basse'],
        'chaud'    => ['/\b(?:trop chaud|il fait chaud|fait chaud|canicule|surchauffe\w*|etouffant)\b/', null, 'Température trop élevée'],
        'bruit'    => ['/\b(?:bruits?|bruyante?s?|grince\w*|vibre\w*|siffle\w*|tape\w*|boucan|vacarme|raffut|tintamarre|ronfle\w*|claque\w*)\b/', null, 'Bruit anormal'],
        'odeur'    => ['/\b(?:odeurs?|sent mauvais|pue\w*|puanteur)\b/', null, 'Mauvaise odeur'],
        'sale'     => ['/\b(?:sales?|salete|taches?|a nettoyer|degoutant\w*)\b/', null, 'À nettoyer'],
        'manque'   => ['/\b(?:manque\w*|plus de|pas de|vides?|epuise\w*)\b/', null, 'Manque'],
        'remplacer'=> ['/\b(?:remplacer|remplacement|changer|change)\b/', null, 'Remplacement'],
        'installer'=> ['/\b(?:installer|installation|poser|pose|ajouter|monter|fixer|accrocher)\b/', null, 'Installation'],
        'reparer'  => ['/\b(?:reparer|reparation|refaire|remettre en etat)\b/', null, 'Réparation'],
        'regler'   => ['/\b(?:regler|reglage|ajuster)\b/', null, 'Réglage'],
        'deplacer' => ['/\b(?:deplacer|deplacement|demenager)\b/', null, 'Déplacement'],
        'defaut'   => ['/\b(?:problemes?|pb|pbs|souci|soucis|defaut\w*|defectueu\w*|abnormal\w*|anomalie\w*|marche mal|fonctionne mal)\b/', ['défectueux', 'défectueuse'], 'Problème'],
    ];

    /** Urgence, alignée sur les valeurs du formulaire (Basse, Normale, Haute, Urgente). */
    public const URGENCES = [
        'Urgente' => '/\b(?:urgent\w*|urgence|tres urgent|immediat\w*|danger\w*|inond\w*|incendie|feu|fumee|odeur de gaz|sent le gaz|fuite de gaz|electrocut\w*|etincelles?|court circuit|(?:bloque|coince)e?s? dans l ascenseur|personnes? (?:bloquee|coincee)s?|quelqu un (?:dedans|a l interieur|(?:de )?(?:bloque|coince)e?)|(?:avec|il y a) (?:quelqu un|des gens|une personne|du monde) (?:dedans|a l interieur)|enferme\w*|blesse\w*|effondre\w*|ca crame)\b/',
        'Basse'   => '/\b(?:pas (?:tres )?urgent\w*|pas presse\w*|quand vous (?:pouvez|aurez le temps)|quand tu (?:peux|auras le temps)|a l occasion|pas grave|rien de grave|sans urgence|quand possible|esthetique)\b/',
        'Haute'   => '/\b(?:fuite\w*|fuit|degats? des eaux|(?:l )?eau (?:qui )?coule\w*|infiltrations?|plus d eau|plus de chauffage|pas de chauffage|plus de courant|coupure de courant|panne de courant|ascenseurs? (?:en panne|bloque|hs)|bip\w*|sonne\w*|en alarme|au plus vite|rapidement|vite|prioritaire|important\w*|bloquant\w*|des que possible|asap)\b/',
    ];

    /** Accorde une forme adjectivale [m, f] au genre et au nombre. */
    public static function accord(array $formes, string $genre, string $nombre): string
    {
        $a = $genre === 'f' ? $formes[1] : $formes[0];
        if ($nombre === 'p' && !str_starts_with($a, 'en ')) {
            if (str_ends_with($a, 'eux')) return $a;                 // défectueux
            $a .= 's';
        }
        return $a;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Lieux : étage et bâtiment dans un texte préparé
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Retire l'étage cité dans $t (texte préparé, entouré d'espaces) et renvoie
     * son niveau : « 2e étage », « étage 2 », « au 2eme », « RDC », « sous-sol »,
     * « R+1 », « deuxième étage ». null si aucun.
     */
    public static function extraireEtage(string &$t, bool $souple = false): ?int
    {
        $motifs = [
            '/\b(?:au |du |a l |de l |le |l |sur le |niveau |a mon |mon )?(-?\d{1,2}) ?(?:er|ere|e|eme|em|nd|nde)? ?etages?\b/' => 1,
            '/\betages? (?:numero )?(-?\d{1,2}) ?(?:er|ere|e|eme|em|nd|nde)?\b/' => 1,
            '/\b(?:au |du |le |a mon |mon )?(premier|premiere|deuxieme|second|seconde|troisieme|quatrieme|cinquieme|sixieme|septieme|huitieme|neuvieme|dixieme) etages?\b/' => 'ord',
            // « au premier », « du deuxième » en fin de phrase : un étage.
            '/\b(?:au|du) (premier|deuxieme|second|troisieme|quatrieme|cinquieme|sixieme)\b(?= *$| (?:et|ou|du|de|dans|bat|batiment|a|au|pres|a cote)\b)/' => 'ord',
            '/\b(?:au |du |le |en |a l )?(?:rdc|rez de chaussee|rez de chausse|rez de chaussees)\b/' => 0,
            '/\b(?:au |du |le |en )?sous sols?\b/' => -1,
            '/\br\+(\d{1,2})\b/' => 1,
            '/\bniveau (-?\d{1,2})\b/' => 1,
            // « au 2e », « du 1er », « (2eme, bureau 235) » : ordinal suivi d'une ponctuation ou d'un lieu
            '/\b(?:au|du|a mon|mon|etage) (\d{1,2}) ?(?:er|ere|e|eme|em|nd|nde)\b(?! (?:extincteur|point|element|numero|fois|jour|semaine|mois))/' => 1,
            // Demandeur seulement : « (2eme, bureau 235) ». Chez le gestionnaire, « le 2e » désigne un rang.
            '/(?<![a-z0-9])(\d{1,2}) ?(?:er|ere|eme|em|nd|nde)\b(?= (?:bureau|salle|local|porte|couloir|piece|\d)|\s*$)/' => 'souple',
        ];
        foreach ($motifs as $re => $mode) {
            if ($mode === 'souple') { if (!$souple) continue; $mode = 1; }
            if (!preg_match($re, $t, $m)) continue;
            $niv = match (true) {
                $mode === 'ord' => self::ORDINAUX[$m[1]] ?? null,
                $mode === 1     => (int)$m[1],
                default         => (int)$mode,
            };
            if ($niv === null || $niv > 60) continue;
            $t = str_replace($m[0], ' ', $t);
            return $niv;
        }
        return null;
    }

    /**
     * Bâtiment cité dans $t (texte préparé, entouré d'espaces), rapproché des
     * bâtiments du site. $batiments : canon => ['norm' => …, 'court' => …].
     * Retire le passage reconnu. Renvoie [canon connu | null, texte brut | null].
     */
    public static function extraireBatiment(string &$t, array $batiments): array
    {
        $prefixe = '(?:batiments?|bat|bati|bats|bt|immeuble|bloc|aile)';
        if (preg_match('/\b' . $prefixe . '\s+((?!de |du |des |la |le |les |d |l )[a-z0-9]+(?:\s+(?!et |ou |au |en |de |du |des |sur |dans |pour |avec |a |il |elle |je |qui |ne )[a-z0-9]+)?)/', $t, $m)) {
            $cands = explode(' ', $m[1]);
            foreach (array_unique([implode(' ', $cands), $cands[0]]) as $essai) {
                foreach ($batiments as $canon => $b) {
                    if ($essai === $b['court'] || $essai === $b['norm']
                        || (strlen($essai) >= 4 && self::plusProche($essai, [$b['court'] => 1]) !== null)) {
                        $t = preg_replace('/\b' . $prefixe . '\s+' . preg_quote($essai, '/') . '\b/', ' ', $t, 1);
                        return [$canon, $essai];
                    }
                }
            }
            $t = preg_replace('/\b' . $prefixe . '\s+' . preg_quote($cands[0], '/') . '\b/', ' ', $t, 1);
            return [null, $cands[0]];
        }
        // Nom propre du site cité sans préfixe : « centre technique », « hôtel de ville »
        $vides = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd'];
        $mots = array_values(array_filter(self::mots(trim($t)), fn($w) => !in_array($w, $vides, true)));
        $meilleur = null;
        foreach ($batiments as $canon => $b) {
            if (strlen($b['court']) < 4) continue;
            $bm = array_values(array_filter(explode(' ', $b['court']), fn($w) => !in_array($w, $vides, true)));
            $n = count($bm);
            if (!$n) continue;
            for ($i = 0; $i + $n <= count($mots); $i++) {
                $ok = true;
                for ($k = 0; $k < $n; $k++) {
                    $w = $mots[$i + $k]; $x = $bm[$k];
                    if ($w !== $x && !(strlen($x) >= 5 && self::plusProche($w, [$x => 1]) !== null)) { $ok = false; break; }
                }
                if ($ok && (!$meilleur || $n > $meilleur[1])) $meilleur = [$canon, $n, array_slice($mots, $i, $n)];
            }
        }
        if ($meilleur) {
            foreach ($meilleur[2] as $w) $t = preg_replace('/\b' . preg_quote($w, '/') . '\b/', ' ', $t, 1);
            return [$meilleur[0], implode(' ', $meilleur[2])];
        }
        return [null, null];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Étages
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Niveau d'un étage écrit librement : « RDC » → 0, « 1er étage » → 1,
     * « R+2 » → 2, « Sous-sol » → -1, « Niveau 3 » → 3. null si illisible
     * (« Toiture », « Mezzanine » : on compare alors le texte).
     */
    public static function niveau(?string $etage): ?int
    {
        $e = self::preparer((string)$etage);
        if ($e === '') return null;
        if (preg_match('/\b(?:rdc|rez de chaussee|rez de chausse|rez|r 0|r\+0|niveau 0|etage 0)\b/', $e)) return 0;
        if (preg_match('/\b(?:sous sol|ss|sous-sol|r-1|niveau -1|cave)\b/', $e)) return -1;
        if (preg_match('/\br\+(\d{1,2})\b/', $e, $m)) return (int)$m[1];
        if (preg_match('/\b(?:niveau|etage|et|n)\s*(-?\d{1,2})\b/', $e, $m)) return (int)$m[1];
        if (preg_match('/\b(-?\d{1,2})\s*(?:er|ere|e|eme|em|nd|nde)?\b/', $e, $m)) return (int)$m[1];
        foreach (self::ORDINAUX as $mot => $n) if (preg_match('/\b' . $mot . '\b/', $e)) return $n;
        return null;
    }

    /** Libellé d'un niveau : 0 → « RDC », 1 → « 1er étage », -1 → « sous-sol ». */
    public static function libelleNiveau(int $n): string
    {
        return match (true) {
            $n === 0  => 'RDC',
            $n === -1 => 'sous-sol',
            $n < -1   => 'sous-sol ' . abs($n),
            $n === 1  => '1er étage',
            default   => $n . 'e étage',
        };
    }

    /** Deux étages désignent-ils le même niveau ? (texte libre des deux côtés) */
    public static function memeEtage(?string $a, ?string $b): bool
    {
        $na = self::niveau($a); $nb = self::niveau($b);
        if ($na !== null && $nb !== null) return $na === $nb;
        $a = self::preparer((string)$a); $b = self::preparer((string)$b);
        return $a !== '' && $a === $b;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Formatage des réponses
    // ═══════════════════════════════════════════════════════════════════════

    /** « 2026-10-20 » ou « 2026-10-20 09:30:00 » → « 20/10/2026 ». */
    public static function date(?string $d): string
    {
        $d = trim((string)$d);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return "$m[3]/$m[2]/$m[1]";
        return $d;
    }

    /** 15000 → « 15 000 € » ; 38.5 → « 38,50 € ». */
    public static function montant(mixed $m): string
    {
        $v = (float)$m;
        $dec = (abs($v - round($v)) < 0.005) ? 0 : 2;
        return number_format($v, $dec, ',', "\u{202F}") . ' €';
    }

    /** « 3 équipements », « 1 équipement », « aucun équipement ». */
    public static function nombre(int $n, string $type, bool $aucun = true): string
    {
        [$s, $p, $f] = self::LIBELLES[$type] ?? [$type, $type . 's', false];
        if ($n === 0 && $aucun) return ($f ? 'aucune ' : 'aucun ') . $s;
        return $n . ' ' . ($n > 1 ? $p : $s);
    }

    public static function pluriel(int $n, string $singulier, ?string $pluriel = null): string
    {
        return $n > 1 ? ($pluriel ?? $singulier . 's') : $singulier;
    }

    /**
     * Libellé sûr pour un jeton [FICHE:…:libellé] : ni crochet, ni barre, ni
     * retour à la ligne, ni caractère de mise en forme (l'interface les
     * interpréterait). Tronqué proprement.
     */
    public static function libelle(?string $s, int $max = 60): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']', '|', '*', '`', '_', "\n", "\r"], ' ', (string)$s)));
        if (mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
        return $s;
    }

    /** Texte cité dans une phrase (gras, puces) : mêmes précautions, sans troncature forcée. */
    public static function texte(?string $s, int $max = 140): string
    {
        return self::libelle($s, $max);
    }

    /** Première lettre en majuscule. */
    public static function capitale(string $s): string
    {
        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Liste française : « A, B et C ». */
    public static function enumeration(array $items, string $et = 'et'): string
    {
        $items = array_values(array_filter(array_map('strval', $items), fn($s) => $s !== ''));
        $n = count($items);
        if ($n === 0) return '';
        if ($n === 1) return $items[0];
        return implode(', ', array_slice($items, 0, -1)) . " $et " . $items[$n - 1];
    }
}
