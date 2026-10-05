#!/usr/bin/env bash
# Lance toutes les épreuves de sécurité des add-ons.
# Code de sortie non nul si l'une d'elles échoue — utilisable en intégration.
set -u
cd "$(dirname "$0")/../.." || exit 2
echec=0
# ⚠️ FIX : une suite qui ne trouve pas de base (pas de config.json) s'arrête
# avec « ⏭️ Section sautée » et le code 0. Le script concluait alors
# « ✅ Toutes les épreuves sont passées » alors que quatre suites n'avaient
# rien vérifié. Les suites sautées sont désormais comptées et annoncées.
# LARKA_EPREUVES_STRICT=1 : une suite sautée fait échouer (intégration).
sautees=()
avertissements=()

for e in invariants expressions conditions layout attaques autorisations execution fonctionnalites reference assistant acces-coeur mise-a-jour csp; do
  f="outils/epreuves/test-$e.php"
  [[ -f "$f" ]] || continue
  echo
  echo "─── $e ───────────────────────────────────────────────────────────"
  sortie=$(php "$f" 2>&1); code=$?
  printf '%s\n' "$sortie" | tail -6
  if grep -q 'config.json introuvable' <<<"$sortie"; then
    # Suite qui exige une installation configurée : sautée, pas réussie.
    sautees+=("$e"); continue
  fi
  [[ $code -eq 0 ]] || echec=1
  if [[ $code -eq 0 ]] && grep -q '⏭️' <<<"$sortie"; then sautees+=("$e"); fi
done


# Les packs de langue sont-ils complets et sans français résiduel ?
# ⚠️ FIX : le motif cherchait extensions/larka.langue-*/ alors que les packs
# vivent dans extensions/langues/ : aucun pack n'était jamais vérifié, et la
# section restait muette. Les écarts sont signalés ; ils ne bloquent que si
# LARKA_LANGUES_STRICT=1 (un pack en cours de traduction n'est pas une
# régression du moteur).
echo
echo "─── packs de langue ───────────────────────────────────────────────"
packs=0
for pack in extensions/langues/larka.langue-*/extension.json extensions/larka.langue-*/extension.json; do
  [ -f "$pack" ] || continue
  packs=$((packs + 1))
  if ! php outils/langues/verifier-traduction.php "$pack"; then
    if [[ "${LARKA_LANGUES_STRICT:-0}" == "1" ]]; then echec=1
    else avertissements+=("pack $(basename "$(dirname "$pack")") incomplet"); fi
  fi
done
[[ $packs -gt 0 ]] || echo "  (aucun pack de langue trouvé)"

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
# (forme « ${t[@]+…} » : un tableau vide sous « set -u » interrompt bash < 4.4)
for a in ${avertissements[@]+"${avertissements[@]}"}; do echo "⚠️  $a (non bloquant)"; done
if [[ ${#sautees[@]} -gt 0 ]]; then
  echo "⚠️  ${#sautees[@]} suite(s) SAUTÉE(S), donc non vérifiée(s) : ${sautees[*]}"
  echo "    (aucune base accessible — lancez les épreuves depuis une installation configurée)"
  [[ "${LARKA_EPREUVES_STRICT:-0}" == "1" ]] && echec=1
fi
if [[ $echec -eq 0 && ${#sautees[@]} -eq 0 ]]; then
  echo "✅ Toutes les épreuves sont passées."
  # Il y avait ici un renvoi vers « test-bac-client.html », l'épreuve du bac à
  # sable navigateur. Le fichier a disparu avec le bac à sable lui-même : on
  # envoyait donc l'utilisateur vers une page inexistante après un succès.
elif [[ $echec -eq 0 ]]; then
  echo "✅ Aucune épreuve en échec — mais toutes n'ont pas tourné (voir ci-dessus)."
else
  echo "❌ Au moins une épreuve a échoué."
fi
exit $echec
