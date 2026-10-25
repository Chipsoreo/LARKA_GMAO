<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Habillage de FORMAT-DECLARATIF-REFERENCE.docx après pandoc
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  La police, les couleurs et les styles (titres, tableaux, code, encadrés)
 *  viennent du modèle reference-larka.docx passé à pandoc (--reference-doc).
 *  pandoc ne sait pas produire le reste ; ce script l'ajoute, sans toucher au
 *  contenu :
 *    • page de garde (logo Larka, version de la référence, version de Larka) ;
 *    • pied de page « Larka · Format déclaratif · référence vX — Page n / N » ;
 *    • format Lettre, marges 1 pouce (comme les manuels) ;
 *    • sommaire intitulé « Sommaire », sur sa propre page ;
 *    • filets « --- » fins et gris au lieu du trait noir de pandoc ;
 *    • tableaux pleine largeur, colonnes proportionnées à leur contenu.
 *
 *  Usage (après la commande pandoc de README.md) :
 *    php Documentations/manuel-source/habiller-reference.php
 *    php Documentations/manuel-source/habiller-reference.php autre.docx
 *
 *  Le sommaire reste un champ Word : il se remplit à l'ouverture (Word propose
 *  de mettre à jour les champs) ou par clic droit → « Mettre à jour les champs ».
 */

const REFERENCE_VERSION = '2.0';

$racine  = dirname(__DIR__, 2);
$fichier = $argv[1] ?? $racine . '/Documentations/FORMAT-DECLARATIF-REFERENCE.docx';
if (!is_file($fichier)) { fwrite(STDERR, "Introuvable : $fichier\n"); exit(1); }
if (!class_exists('ZipArchive')) { fwrite(STDERR, "Extension PHP zip requise.\n"); exit(1); }

require_once $racine . '/api/Version.php';
$v = LarkaVersion::lire();
$mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$date = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v['date'], $m) ? (int)$m[3] . ' ' . $mois[(int)$m[2]] . ' ' . $m[1] : $v['date'];

$zip = new ZipArchive();
if ($zip->open($fichier) !== true) { fwrite(STDERR, "Archive illisible : $fichier\n"); exit(1); }
$doc = $zip->getFromName('word/document.xml');
if ($doc === false) { fwrite(STDERR, "word/document.xml absent.\n"); exit(1); }
if (str_contains($doc, 'rIdFooterLarka')) { echo "Déjà habillé : rien à faire.\n"; exit(0); }

$e = fn(string $t): string => htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$run = function (string $t, array $o = []) use ($e): string {
    $rp = (!empty($o['b']) ? '<w:b/><w:bCs/>' : '') . (!empty($o['i']) ? '<w:i/><w:iCs/>' : '')
        . (isset($o['c']) ? '<w:color w:val="' . $o['c'] . '"/>' : '')
        . (isset($o['sz']) ? '<w:sz w:val="' . $o['sz'] . '"/><w:szCs w:val="' . $o['sz'] . '"/>' : '');
    return '<w:r>' . ($rp ? "<w:rPr>$rp</w:rPr>" : '') . '<w:t xml:space="preserve">' . $e($t) . '</w:t></w:r>';
};
$par = fn(string $runs, int $avant = 0, int $apres = 0): string =>
    '<w:p><w:pPr><w:spacing w:before="' . $avant . '" w:after="' . $apres . '"/><w:jc w:val="center"/></w:pPr>' . $runs . '</w:p>';

// ── Logo ────────────────────────────────────────────────────────────────────
$logo = __DIR__ . '/logo-larka.png';   // marque Larka, coins arrondis
$image = '';
if (is_file($logo)) {
    $zip->addFile($logo, 'word/media/logo_larka.png');
    $cx = (int)(1.2 * 914400);
    $image = '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cx . '"/>'
        . '<wp:docPr id="9001" name="Logo Larka" descr="Logo Larka"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:nvPicPr><pic:cNvPr id="9001" name="Logo Larka"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdLogoLarka"/>'
        . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cx . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

// ── Page de garde ───────────────────────────────────────────────────────────
$encadre = '<w:p><w:pPr><w:spacing w:after="40"/><w:jc w:val="center"/></w:pPr>' . $run('Édition auteurs de modules', ['b' => 1, 'c' => 'FFFFFF', 'sz' => 40]) . '</w:p>'
    . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>' . $run('Squelette, champs, pages, mise en page, ancrages, capacités, partages et calculs — et les modules répartis en plusieurs fichiers JSON ($ref)', ['c' => 'DDE7F5', 'sz' => 20]) . '</w:p>';
$garde = ($image ? $par($image, 1000) : '')
    . $par($run('RÉFÉRENCE TECHNIQUE', ['b' => 1, 'c' => '5A6B82', 'sz' => 30]), 240)
    . $par($run('Format déclaratif Larka', ['b' => 1, 'c' => '14233B', 'sz' => 64]), 60)
    . $par($run('Créer un module complémentaire — sans une ligne de code', ['i' => 1, 'c' => '5A6B82', 'sz' => 24]), 40)
    . '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="16" w:space="10" w:color="2B7BE6"/></w:pBdr><w:spacing w:before="260" w:after="360"/></w:pPr></w:p>'
    . '<w:tbl><w:tblPr><w:tblW w:w="6600" w:type="dxa"/><w:jc w:val="center"/><w:tblLook w:val="04A0"/></w:tblPr><w:tblGrid><w:gridCol w:w="6600"/></w:tblGrid>'
    . '<w:tr><w:trPr><w:jc w:val="center"/></w:trPr><w:tc><w:tcPr><w:tcW w:w="6600" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="14233B"/>'
    . '<w:tcMar><w:top w:w="200" w:type="dxa"/><w:left w:w="200" w:type="dxa"/><w:bottom w:w="200" w:type="dxa"/><w:right w:w="200" w:type="dxa"/></w:tcMar></w:tcPr>'
    . $encadre . '</w:tc></w:tr></w:tbl>'
    . $par($run('Référence version ' . REFERENCE_VERSION . ' — format declaratif/1 · API d\'extensions version 1', ['c' => '5A6B82', 'sz' => 20]), 1100)
    . $par($run('Larka ' . LarkaVersion::libelle($v) . ($date ? ' — ' . $date : ''), ['c' => '5A6B82', 'sz' => 20]), 40)
    . $par($run('Document destiné aux auteurs de modules et aux administrateurs qui les installent.', ['i' => 1, 'c' => '5A6B82', 'sz' => 18]), 40)
    . '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
$doc = preg_replace('/<w:body>/', '<w:body>' . str_replace(['\\', '$'], ['\\\\', '\\$'], $garde), $doc, 1);

// ── Sommaire : titre français ; le contenu commence sur une nouvelle page ──
// (« saut de page avant » sur le premier titre plutôt qu'un paragraphe de saut :
//  si le sommaire remplit exactement sa page, pas de page blanche)
$doc = str_replace('>Table of Contents<', '>Sommaire<', $doc);
$fin = strpos($doc, '</w:sdtContent></w:sdt>');
$h1 = $fin === false ? false : strpos($doc, '<w:pStyle w:val="Heading1" />', $fin);
if ($h1 !== false) $doc = substr_replace($doc, '<w:pStyle w:val="Heading1" /><w:pageBreakBefore />', $h1, strlen('<w:pStyle w:val="Heading1" />'));

// ── Filets « --- » : trait fin gris ─────────────────────────────────────────
$doc = preg_replace('#<w:p><w:r><w:pict><v:rect [^>]*o:hr="t" /></w:pict></w:r></w:p>#',
    '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="D5DCE6"/></w:pBdr><w:spacing w:before="120" w:after="240"/></w:pPr></w:p>', $doc);

// ── Tableaux pleine largeur, colonnes proportionnées ────────────────────────
$texte = fn(string $x): string => html_entity_decode(strip_tags(preg_replace('#<w:instrText[^>]*>.*?</w:instrText>#s', '', $x)), ENT_QUOTES | ENT_XML1, 'UTF-8');
$doc = preg_replace_callback('#<w:tbl><w:tblPr><w:tblStyle w:val="Table" />.*?</w:tbl>#s', function ($m) use ($texte) {
    $t = $m[0];
    preg_match_all('#<w:tr>.*?</w:tr>#s', $t, $lignes);
    $cols = array_map(fn($r) => preg_match_all('#<w:tc>.*?</w:tc>#s', $r, $c) ? $c[0] : [], $lignes[0]);
    $n = max(array_map('count', $cols));
    foreach ($cols as $c) if (count($c) !== $n) return $t;
    if ($n < 2) return $t;
    $poids = [];
    for ($j = 0; $j < $n; $j++) {
        $l = array_map(fn($c) => mb_strlen($texte($c[$j])), $cols); sort($l);
        $poids[] = max(9, min(60, $l[(int)(count($l) * 0.8)])) ** 0.8;
    }
    $tot = array_sum($poids); $ws = array_map(fn($p) => (int)(9360 * $p / $tot), $poids);
    $ws[$n - 1] = 9360 - array_sum(array_slice($ws, 0, $n - 1));
    $t = preg_replace('#<w:tblW w:type="auto" w:w="0" />#', '<w:tblW w:type="dxa" w:w="9360" />', $t, 1);
    $t = preg_replace('#<w:tblGrid>.*?</w:tblGrid>#s', '<w:tblGrid>' . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '" />', $ws)) . '</w:tblGrid>', $t, 1);
    return preg_replace_callback('#<w:tr>.*?</w:tr>#s', function ($r) use ($ws) {
        $k = 0;
        return preg_replace_callback('#<w:tc>.*?</w:tc>#s', function ($c) use ($ws, &$k) {
            $w = $ws[min($k++, count($ws) - 1)];
            return preg_replace('#<w:tcPr />#', '<w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa" /></w:tcPr>', $c[0], 1);
        }, $r[0]);
    }, $t);
}, $doc);

// ── Mise en page et pied de page ────────────────────────────────────────────
$sect = '<w:sectPr><w:footerReference w:type="default" r:id="rIdFooterLarka"/><w:pgSz w:w="12240" w:h="15840"/>'
      . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:titlePg/></w:sectPr>';
$doc = preg_match('#<w:sectPr\s*/>#', $doc)
     ? preg_replace('#<w:sectPr\s*/>#', $sect, $doc, 1)
     : preg_replace('#<w:sectPr\b.*?</w:sectPr>#s', $sect, $doc, 1);
$zip->addFromString('word/document.xml', $doc);

$rp = '<w:rPr><w:color w:val="5A6B82"/><w:sz w:val="17"/><w:szCs w:val="17"/></w:rPr>';
$champ = fn(string $i): string => "<w:r>$rp<w:fldChar w:fldCharType=\"begin\"/></w:r><w:r>$rp<w:instrText xml:space=\"preserve\"> $i </w:instrText></w:r>"
    . "<w:r>$rp<w:fldChar w:fldCharType=\"separate\"/></w:r><w:r>$rp<w:t>1</w:t></w:r><w:r>$rp<w:fldChar w:fldCharType=\"end\"/></w:r>";
$zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="D5DCE6"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="9360"/></w:tabs></w:pPr>'
    . "<w:r>$rp<w:t>Larka · Format déclaratif · référence v" . REFERENCE_VERSION . "</w:t></w:r><w:r>$rp<w:tab/><w:t xml:space=\"preserve\">Page </w:t></w:r>"
    . $champ('PAGE') . "<w:r>$rp<w:t xml:space=\"preserve\"> / </w:t></w:r>" . $champ('NUMPAGES') . '</w:p></w:ftr>');

$rels = $zip->getFromName('word/_rels/document.xml.rels');
$rels = str_replace('</Relationships>',
    '<Relationship Id="rIdFooterLarka" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
    . ($image ? '<Relationship Id="rIdLogoLarka" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo_larka.png"/>' : '')
    . '</Relationships>', $rels);
$zip->addFromString('word/_rels/document.xml.rels', $rels);

$types = $zip->getFromName('[Content_Types].xml');
if (!str_contains($types, 'Extension="png"')) {
    $types = str_replace('<Default Extension="xml"', '<Default Extension="png" ContentType="image/png" /><Default Extension="xml"', $types);
}
$types = str_replace('</Types>', '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml" /></Types>', $types);
$zip->addFromString('[Content_Types].xml', $types);

$zip->close();
echo "✓ " . basename($fichier) . " habillé (page de garde, pied de page, sommaire, tableaux) — référence v" . REFERENCE_VERSION . ", Larka " . LarkaVersion::libelle($v) . "\n";
