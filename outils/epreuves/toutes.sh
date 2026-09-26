#!/usr/bin/env bash
# Lance toutes les épreuves de sécurité des add-ons.
# Code de sortie non nul si l'une d'elles échoue — utilisable en intégration.
set -u
cd "$(dirname "$0")/../.." || exit 2
echec=0

for e in invariants expressions conditions layout attaques autorisations execution fonctionnalites reference assistant; do
  f="outils/epreuves/test-$e.php"
  [[ -f "$f" ]] || continue
  echo
  echo "─── $e ───────────────────────────────────────────────────────────"
  php "$f" | tail -6
  [[ ${PIPESTATUS[0]} -eq 0 ]] || echec=1
done


# Les packs de langue sont-ils complets et sans français résiduel ?
echo
echo "─── packs de langue ───────────────────────────────────────────────"
for pack in extensions/larka.langue-*/extension.json; do
  [ -f "$pack" ] || continue
  php outils/langues/verifier-traduction.php "$pack" || echec=1
done

# Des accès « window.X » condamnés à rendre undefined ?
echo
echo "─── liaisons globales ─────────────────────────────────────────────"
node outils/epreuves/verif-globales.js || echec=1

# La mise en page se rend-elle comme elle se déclare ? C'est la seule épreuve
# qui lit le HTML réellement produit par le client. Elle n'a besoin d'aucun
# navigateur : le rendu est une fonction pure, et c'est exprès.
echo
echo "─── rendu de la mise en page ──────────────────────────────────────"
node outils/epreuves/test-rendu-layout.js | tail -3
[[ ${PIPESTATUS[0]} -eq 0 ]] || echec=1

# Les catalogues de la référence sont-ils à jour ? On régénère, et l'on compare :
# une différence signifie qu'une entrée a été ajoutée au code sans reconstruire.
echo
echo "─── catalogues de la référence ────────────────────────────────────"
php Documentations/manuel-source/generer-reference.php >/dev/null 2>&1 \
  && echo "  ✅ catalogues régénérables" \
  || { echo "  ❌ génération en échec"; echec=1; }
rm -f Documentations/FORMAT-DECLARATIF-REFERENCE-CATALOGUES.md

# Les exemples de la documentation valident-ils encore ? Un exemple périmé se
# recopie et se heurte à un refus incompréhensible.
echo
echo "─── exemples déclaratifs ──────────────────────────────────────────"
php Documentations/exemples-declaratifs/verifier.php | tail -2
[[ ${PIPESTATUS[0]} -eq 0 ]] || echec=1

echo
if [[ $echec -eq 0 ]]; then
  echo "✅ Toutes les épreuves sont passées."
  # Il y avait ici un renvoi vers « test-bac-client.html », l'épreuve du bac à
  # sable navigateur. Le fichier a disparu avec le bac à sable lui-même : on
  # envoyait donc l'utilisateur vers une page inexistante après un succès.
else
  echo "❌ Au moins une épreuve a échoué."
fi
exit $echec
