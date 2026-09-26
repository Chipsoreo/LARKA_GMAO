# outils/epreuves/

> **Ne part pas en production.** `deploy/install.sh` exclut `outils/` de la
> copie. Ces épreuves décrivent, une par une, tout ce contre quoi Larka se
> défend — c'est-à-dire par où l'attaquer. Cette carte n'a aucune raison de
> voyager avec le produit ; elle sert ici, au dépôt.

## Vous n'avez rien à lancer

Les épreuves rapides tournent **automatiquement avant chaque mise en
production** :

```bash
./start.sh prod          # vérifie, puis déploie — refuse si une protection tombe
```

Une vérification qu'il faut penser à lancer n'est jamais lancée. Elle est donc
branchée au seul moment où elle compte, et où l'on accepte d'attendre trois
secondes.

Si une protection ne répond plus, le déploiement **s'arrête** avec le code 60.
`--sans-epreuves` existe pour l'urgence : il faut l'écrire, donc le décider.

À la demande :

```bash
./start.sh epreuves      # les rapides + les liaisons globales + le rendu
make epreuves            # idem
bash outils/epreuves/toutes.sh   # toutes, dont celles qui exigent une base
```

## À quoi elles servent, concrètement

Elles ne testent **pas** du code extérieur : il n'y en a plus. Elles vérifient
que *votre* code fait ce qu'il prétend. Elles ont attrapé trois régressions
pendant la refonte de septembre 2026 — dont deux que rien d'autre n'aurait
signalées — puis six défauts d'autorisation sur un module livré, qu'aucune
relecture n'avait vus parce qu'ils ne sont visibles qu'à l'exécution, avec un
rôle donné.

| Épreuve | Ce qu'elle vérifie | Base requise |
|---|---|---|
| `test-invariants.php` | Les propriétés dont dépend toute la sécurité sont encore vraies **dans le code** : le chargement échoue fermé si la sécurité manque, aucune capacité du catalogue n'est inatteignable, aucune requête ne s'écrit sans condition. Des garde-fous contre une modification bien intentionnée qui ferait tomber une frontière. | non |
| `test-layout.php` | La **mise en page déclarative** est close, bornée, et sans effet sur les droits : toute propriété hors catalogue est refusée (`style`, `onclick`, `html`, `css`…), les bornes tiennent (300 composants, 4 niveaux, 24 colonnes), et surtout — ajouter un `layout` laisse capacités, actions, rôles et champs **strictement identiques**. C'est cette dernière propriété qui rend la primitive sûre. | non |
| `test-rendu-layout.js` | Le **rendu** de la mise en page, lu dans le HTML réellement produit : colonnes, placements, sections, paliers responsive, et l'échappement du texte d'auteur. C'était le dernier morceau du format que rien ne vérifiait. Tourne sans navigateur — le rendu est une fonction pure, et c'est exprès. | non |
| `test-expressions.php` | Le moteur de formules **calcule juste**, et aucune expression ne peut atteindre PHP. 30 cas, dont des tentatives d'appel de fonctions système. | non |
| `test-conditions.php` | Les 33 opérateurs de condition répondent juste, et une déclaration fautive est refusée : opérateur inventé, imbrication déraisonnable, champ inexistant. | non |
| `test-attaques.php` | Une déclaration **hostile** est refusée à l'installation, et une requête hostile à l'exécution. C'est la suite qui pense comme un attaquant. | moitié |
| `test-autorisations.php` | Les rôles et les actions qu'un module déclare font bien **frontière**, à l'exécution. Sa particularité : elle n'éprouve aucune déclaration hostile, mais une déclaration parfaitement légitime dont les frontières n'étaient pas appliquées — un demandeur lisait le jeu d'une page réservée, y écrivait, modifiait la fiche d'autrui et reconfigurait le module. Chaque cas a d'abord été reproduit sur un module livré. | oui |
| `test-reference.php` | La documentation de référence et le code disent la même chose : tout identifiant cité existe, et tout composant du code est documenté. | non |
| `test-fonctionnalites.php` | Chaque primitive du format produit vraiment son effet. Née d'un défaut réel : le `format` d'un champ calculé était ignoré, la déclaration passait, l'écran s'affichait, et la fonctionnalité manquait — sans rien pour le signaler. | oui |
| `test-execution.php` | Le chemin complet — installer, examiner, écrire, lire, ancrer — **avertissements PHP transformés en erreurs**. Une variable non définie fait échouer le test au lieu de passer inaperçue. | oui |
| `test-assistant.php` | L'assistant donne **la même réponse à la même question, quel que soit le modèle local** (0,6B, 1B, 7B… ou aucun). Plus de 120 formulations (fautes, langage parlé, dates relatives, suites de conversation) sont rejouées avec des modèles simulés — parfait, médiocre, aberrant, injoignable — et doivent produire un texte identique à l'octet près, sans même appeler le modèle ; aucun modèle ne peut faire citer un élément inexistant ni injecter un lien. `LARKA_EPREUVE_OLLAMA=<modèle>` y ajoute un vrai modèle Ollama. Hermétique : recopie `api/` et crée sa propre base SQLite jetable. | non (pdo_sqlite) |
| `verif-globales.js` | Aucun accès `window.X` vers une liaison `const`/`let`. C'est ainsi que l'écran Apparence annonçait « couche extensions indisponible » alors qu'elle était chargée, et que le bouton de dépôt n'apparaissait à personne. | non |

## Les quatre qui exigent une base

`test-attaques` (sa seconde moitié), `test-autorisations`,
`test-fonctionnalites` et `test-execution` ont besoin d'une base configurée
(SQLite suffit). Sans elle, elles échouent — ce n'est pas une régression. Toutes
les autres tournent partout, y compris sur un poste sans serveur.

`test-assistant` est à part : elle ne lit **pas** votre base, elle s'en fabrique
une (site fictif de `assistant/fixture.php`, supprimé en fin d'épreuve). Il lui
faut seulement l'extension `pdo_sqlite` ; sans elle, `./start.sh epreuves` la
signale et l'ignore. Pour interroger le moteur à la main sur ce site fictif :

```bash
php outils/epreuves/assistant/essai.php -v "où est l'extincteur 2 du centre technique ?"
php outils/epreuves/assistant/essai.php --role=Demandeur "la clim ne marche plus au 2e"
LARKA_EPREUVE_OLLAMA=qwen2.5:0.5b php outils/epreuves/test-assistant.php   # avec un vrai modèle
```

`test-autorisations` installe elle-même les modules dont elle a besoin. Elle a
d'abord compté sur `test-execution`, qui s'exécute après elle : sur une base
déjà peuplée elle passait ses 31 contrôles, sur une installation neuve elle en
sautait onze **et restait verte**. Une épreuve qui vérifie moins sans le dire
est le défaut que `test-execution` avait déjà payé.

`test-execution` a longtemps été l'exception silencieuse : elle cherchait les
paquets dans `extensions/` alors qu'ils sont rangés par famille depuis, ne
trouvait rien, et **sortait en succès sans rien parcourir**. Une épreuve verte
qui ne teste rien est pire qu'une épreuve absente — celle-ci parcourt désormais
`extensions/` en profondeur, et refuse de conclure quand elle n'a rien trouvé.

## Ce qu'il faut faire en ajoutant une primitive

Ajouter un type de champ, un opérateur ou une capacité **sans** l'ajouter à
`test-fonctionnalites.php` produit exactement le défaut qui a fait naître cette
épreuve : la déclaration passe, l'écran s'affiche, et la fonctionnalité manque.

`test-invariants.php` s'en aperçoit désormais pour les capacités : il construit
une déclaration qui exerce tout ce que le format sait produire et demande au
schéma quelles capacités elle engendre. Une capacité que rien ne peut obtenir est
signalée. Les autres primitives n'ont pas encore cet automatisme.

**Pour la mise en page, il y a DEUX épreuves à nourrir, et elles ne se
remplacent pas.** `test-layout.php` dit qu'une déclaration est acceptée ou
refusée ; `test-rendu-layout.js` dit ce que le navigateur en fait. Une propriété
ajoutée au catalogue sans être rendue passerait la première et échouerait la
seconde — et c'est exactement le défaut qu'on cherche à empêcher : un réglage
qu'on déclare, que le schéma valide, et qui ne produit rien à l'écran.

## Le modèle de sécurité

`api/extensions/securite/` applique les règles à l'exécution ; `Capacites.php`
définit le contrat. Ces épreuves vérifient que les deux tiennent.
