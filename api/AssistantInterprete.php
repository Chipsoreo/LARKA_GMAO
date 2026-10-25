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
 * Larka — Assistant : interprète (le modèle, en second recours seulement)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Quand le moteur déterministe n'a rien trouvé ou n'a pas compris, on donne UNE
 * chance au modèle de reformuler la question — sans jamais le laisser répondre :
 *
 *   1. il remplit un petit formulaire JSON (intention, type, 1 à 3 mots-clés,
 *      bâtiment, étage, numéro), en sortie contrainte, à température 0 ;
 *   2. ce formulaire est VÉRIFIÉ ici : un mot-clé doit figurer dans la question
 *      ou dans le vocabulaire réel du site, un bâtiment doit exister, un étage
 *      ou un numéro doit être écrit dans la question. Ce qui ne passe pas est
 *      jeté ;
 *   3. le moteur relance sa recherche avec cette reformulation et rédige la
 *      réponse lui-même, liens compris.
 *
 * Un petit modèle qui délire produit donc, au pire, un formulaire rejeté — et la
 * réponse reste celle du moteur seul. Les prompts et schémas sont FIXES : le
 * préfixe reste en cache (KV) d'une question à l'autre.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/AssistantLangue.php';
require_once __DIR__ . '/AssistantLLM.php';

final class AssistantInterprete
{
    public const INTENTIONS = ['localiser', 'compter', 'chercher', 'fiche', 'alertes', 'personne', 'aide', 'hors_sujet'];
    public const TYPES = ['equipement', 'bien', 'intervention', 'contrat', 'demande', 'stock', 'document', 'plan', 'archive', 'tout'];
    public const INTENTIONS_DEMANDEUR = ['creer_demande', 'suivi', 'contacts', 'categories', 'roles', 'aide', 'hors_sujet'];

    public const PROMPT_GESTIONNAIRE = <<<'TXT'
Tu analyses une question posée à Larka, un logiciel de maintenance de bâtiments (GMAO).
Tu ne réponds PAS à la question : tu la traduis en JSON, rien d'autre.
intention :
- localiser : où se trouve un objet
- compter : combien
- chercher : liste, recherche
- fiche : détail, état ou statut d'un élément précis
- alertes : retards, échéances, contrats qui expirent, stock bas, demandes non traitées
- personne : qui est quelqu'un, contact, responsable
- aide : ce que l'assistant sait faire
- hors_sujet : sans rapport avec la maintenance
type : equipement, bien, intervention, contrat, demande, stock, document, plan, archive, ou tout.
mots : 1 à 3 mots qui désignent l'objet, avec le vocabulaire habituel de la maintenance.
batiment, etage, numero : recopiés de la question s'ils y figurent, sinon "".
Exemples :
Q : ou j'ai mis la bouteille rouge anti feu du 1er
{"intention":"localiser","type":"equipement","mots":["extincteur"],"batiment":"","etage":"1","numero":""}
Q : y a combien de machins pour rafraichir l'air au bat B
{"intention":"compter","type":"equipement","mots":["climatisation"],"batiment":"B","etage":"","numero":""}
Q : les boulots pas encore faits qui trainent
{"intention":"alertes","type":"intervention","mots":[],"batiment":"","etage":"","numero":""}
Q : c'est qui le gars qui gere le chauffage
{"intention":"personne","type":"tout","mots":["chauffage"],"batiment":"","etage":"","numero":""}
TXT;

    public const PROMPT_DEMANDEUR = <<<'TXT'
Tu analyses un message envoyé à l'assistant Larka par un agent (maintenance de bâtiments).
Tu ne réponds PAS : tu classes le message en JSON, rien d'autre.
intention :
- creer_demande : l'agent signale un problème, une panne, un besoin (réparation, installation, nettoyage…)
- suivi : il veut savoir où en est sa demande
- contacts : il cherche qui contacter
- categories : il demande quelle catégorie choisir
- roles : il demande les rôles de Larka
- aide : il demande ce que l'assistant sait faire
- hors_sujet : sans rapport avec Larka
objet : 1 à 3 mots, la chose concernée par le problème (vide si aucune).
Exemples :
Q : le machin qui souffle de l'air fait un boucan pas possible
{"intention":"creer_demande","objet":"ventilation"}
Q : ca avance mon truc de la semaine derniere ?
{"intention":"suivi","objet":""}
TXT;

    public static function schemaGestionnaire(): array
    {
        return ['type' => 'object', 'properties' => [
            'intention' => ['type' => 'string', 'enum' => self::INTENTIONS],
            'type'      => ['type' => 'string', 'enum' => self::TYPES],
            'mots'      => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 30], 'maxItems' => 3],
            'batiment'  => ['type' => 'string', 'maxLength' => 40],
            'etage'     => ['type' => 'string', 'maxLength' => 20],
            'numero'    => ['type' => 'string', 'maxLength' => 20],
        ], 'required' => ['intention', 'type', 'mots', 'batiment', 'etage', 'numero'], 'additionalProperties' => false];
    }

    public static function schemaDemandeur(): array
    {
        return ['type' => 'object', 'properties' => [
            'intention' => ['type' => 'string', 'enum' => self::INTENTIONS_DEMANDEUR],
            'objet'     => ['type' => 'string', 'maxLength' => 40],
        ], 'required' => ['intention', 'objet'], 'additionalProperties' => false];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Gestionnaires
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Demande au modèle une reformulation de la question, la vérifie, et
     * renvoie une compréhension utilisable par AssistantMoteur — ou null.
     *
     * @param array $c  compréhension déterministe (point de départ)
     * @return array{comprehension:?array, brut:?array, usage:array, erreur:?string}
     */
    public static function gestionnaire(AssistantModeleJson $llm, AssistantTools $tools, array $c): array
    {
        $r = $llm->json(self::PROMPT_GESTIONNAIRE, 'Q : ' . $c['question'], self::schemaGestionnaire(), ['max_tokens' => 160]);
        $out = ['comprehension' => null, 'brut' => $r['data'], 'usage' => $r['usage'] ?? [], 'erreur' => $r['_error'] ?? null];
        if (!is_array($r['data'])) return $out;
        $out['comprehension'] = self::valider($r['data'], $c, $tools);
        return $out;
    }

    /**
     * Vérification du formulaire rendu par le modèle. Tout ce qui n'est pas
     * attesté par la question ou par le site est écarté : le modèle peut
     * reformuler, il ne peut rien inventer.
     */
    public static function valider(array $d, array $c, AssistantTools $tools): ?array
    {
        $intention = is_string($d['intention'] ?? null) ? $d['intention'] : '';
        if (!in_array($intention, self::INTENTIONS, true)) return null;
        $q = ' ' . AssistantLangue::preparer($c['question']) . ' ';
        $motsQ = array_flip(AssistantLangue::mots(trim($q)));
        $site = $tools->vocabulaireSite();
        $vocab = $site['mots'] ?? [];

        $n = $c;
        $n['interprete'] = true;
        // « Aide » n'apporte rien de plus que la réponse du moteur à une question non comprise.
        if ($intention === 'aide') return null;
        if ($intention === 'hors_sujet') {
            // « Hors sujet » ne l'emporte pas sur une question qui contient un mot du site.
            foreach (array_keys($motsQ) as $w) if (strlen($w) >= 4 && isset($vocab[$w])) return null;
            $n['intention'] = $intention;
            return $n;
        }
        // Une intention lue dans la question par le moteur (« combien », « où »…) ne se discute pas.
        $lue = ($c['confiance'] ?? '') === 'haute' && !in_array($c['intention'], ['chercher', 'inconnue'], true);
        // L'annuaire seulement si la question parle de quelqu'un : un petit modèle y range tout ce qu'il ne comprend pas.
        if ($intention === 'personne' && !$lue && !preg_match('/\b(?:qui|personne|contact\w*|responsable\w*|gars|mec|dame|monsieur|madame|collegue\w*|technicien\w*|gestionnaire\w*)\b/', $q)) return null;
        $n['intention'] = $lue ? $c['intention'] : $intention;
        if ($n['intention'] === 'alertes') $n['mots'] = [];

        $type = is_string($d['type'] ?? null) ? $d['type'] : 'tout';
        if ($type !== 'tout' && in_array($type, self::TYPES, true)) {
            $t = AssistantTools::normType($type);
            if (!$c['type'] || $c['confiance'] === 'basse') $n['type'] = $t;
        }

        // Mots-clés : présents dans la question (même approximativement) ou connus du site.
        $mots = [];
        foreach (array_slice(is_array($d['mots'] ?? null) ? $d['mots'] : [], 0, 3) as $m) {
            if (!is_string($m)) continue;
            foreach (AssistantLangue::mots(AssistantLangue::preparer($m)) as $w) {
                if (strlen($w) < 3 || in_array($w, AssistantLangue::VIDES, true) || in_array($w, AssistantLangue::MOTS_TYPES, true)) continue;
                $atteste = isset($motsQ[$w]) || AssistantLangue::plusProche($w, $motsQ) !== null
                        || isset($vocab[$w]) || isset(AssistantLangue::SYNONYMES[$w]);
                if ($atteste && !in_array($w, $mots, true)) $mots[] = $w;
            }
        }
        // Mot recopié d'un exemple du prompt sur une question qui ne lui ressemble
        // pas : c'est le défaut typique des petits modèles (« extincteur » pour
        // « rafraîchir l'air »). On l'écarte.
        $mots = array_values(array_filter($mots, fn($w) => isset($motsQ[$w]) || AssistantLangue::plusProche($w, $motsQ) !== null
            || !self::recopieDExemple($w, array_keys($motsQ))));
        $mots = array_slice($mots, 0, 3);
        if ($mots) { $n['mots'] = $mots; $n['codes'] = []; }

        // Bâtiment : doit exister sur le site.
        $bat = trim((string)($d['batiment'] ?? ''));
        if ($bat !== '' && empty($c['filtres']['batiment'])) {
            $x = ' bat ' . AssistantLangue::preparer($bat) . ' ';
            [$canon] = AssistantLangue::extraireBatiment($x, $site['batiments'] ?? []);
            if ($canon === null) { $x = ' ' . AssistantLangue::preparer($bat) . ' '; [$canon] = AssistantLangue::extraireBatiment($x, $site['batiments'] ?? []); }
            if ($canon !== null) $n['filtres']['batiment'] = $canon;
        }
        // Étage et numéro : écrits dans la question.
        $et = trim((string)($d['etage'] ?? ''));
        if ($et !== '' && !isset($c['filtres']['etage'])) {
            $niv = AssistantLangue::niveau($et);
            $ord = $niv !== null ? array_search($niv, AssistantLangue::ORDINAUX, true) : false;
            $ecrit = $niv !== null && (($niv === 0 && preg_match('/\b(?:rdc|rez)\b/', $q)) || ($niv < 0 && preg_match('/\bsous sol\b/', $q))
                || ($niv > 0 && preg_match('/(?<!\d)' . $niv . '(?!\d)/', $q)) || ($niv > 0 && $ord !== false && preg_match('/\b' . $ord . '\b/', $q)));
            if ($ecrit) $n['filtres']['etage'] = $niv;
        }
        $num = preg_replace('/\D/', '', (string)($d['numero'] ?? ''));
        if ($num !== '' && preg_match('/(?<!\d)' . (int)$num . '(?!\d)/', $q) && !$n['numeros']) $n['numeros'] = [(string)(int)$num];

        // Rien d'exploitable : pas de reformulation.
        if (!$n['mots'] && !$n['type'] && !$n['codes'] && $intention !== 'alertes') return null;
        return $n;
    }

    /** Exemples du prompt : [mots de la question, mots attendus]. */
    private const EXEMPLES = [
        [['mis', 'bouteille', 'rouge', 'anti', 'feu'], ['extincteur']],
        [['combien', 'machins', 'rafraichir', 'air', 'bat'], ['climatisation']],
        [['boulots', 'pas', 'encore', 'faits', 'trainent'], []],
        [['gars', 'gere', 'chauffage'], ['chauffage']],
    ];

    /** Le mot vient-il d'un exemple du prompt, sans que la question ressemble à cet exemple ? */
    private static function recopieDExemple(string $mot, array $motsQuestion): bool
    {
        $q = array_filter($motsQuestion, fn($w) => strlen($w) >= 3);
        foreach (self::EXEMPLES as [$ex, $sortie]) {
            if (!in_array($mot, $sortie, true)) continue;
            $commun = count(array_intersect($ex, $q));
            $union = count(array_unique(array_merge($ex, $q)));
            if ($union > 0 && $commun / $union >= 0.25) return false;   // question voisine de l'exemple : plausible
            return true;
        }
        return false;
    }

    /** Résumé lisible de la reformulation (affiché au-dessus de la réponse). */
    public static function resume(array $n): string
    {
        $verbe = ['localiser' => 'où est', 'compter' => 'combien de', 'chercher' => 'recherche', 'fiche' => 'fiche de',
                  'alertes' => 'alertes', 'personne' => 'qui est'][$n['intention']] ?? $n['intention'];
        $objet = $n['mots'] ? '« ' . AssistantLangue::texte(implode(' ', $n['mots'])) . ' »' : '';
        $precision = array_filter([
            $n['type'] ? (AssistantLangue::LIBELLES[$n['type']][1] ?? $n['type']) : '',
            !empty($n['filtres']['batiment']) ? AssistantLangue::texte($n['filtres']['batiment']) : '',
            isset($n['filtres']['etage']) ? AssistantLangue::libelleNiveau((int)$n['filtres']['etage']) : '',
        ]);
        return trim($verbe . ' ' . $objet . ($precision ? ' (' . implode(', ', $precision) . ')' : ''));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Demandeurs
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array{intention:?string, objet:string, usage:array, erreur:?string} */
    public static function demandeur(AssistantModeleJson $llm, string $message): array
    {
        $r = $llm->json(self::PROMPT_DEMANDEUR, 'Q : ' . $message, self::schemaDemandeur(), ['max_tokens' => 80]);
        $d = is_array($r['data']) ? $r['data'] : [];
        $i = is_string($d['intention'] ?? null) && in_array($d['intention'], self::INTENTIONS_DEMANDEUR, true) ? $d['intention'] : null;
        $map = ['creer_demande' => 'creer', 'suivi' => 'suivi', 'contacts' => 'contacts', 'categories' => 'categories',
                'roles' => 'roles', 'aide' => 'aide', 'hors_sujet' => 'hors_sujet'];
        return ['intention' => $i ? $map[$i] : null, 'objet' => AssistantLangue::texte(is_string($d['objet'] ?? null) ? $d['objet'] : '', 40),
                'usage' => $r['usage'] ?? [], 'erreur' => $r['_error'] ?? null];
    }
}
