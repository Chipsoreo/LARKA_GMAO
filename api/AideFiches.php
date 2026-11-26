<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Contenu de l'écran Aide : fiches pratiques, en français et en anglais.
 *
 * Une fiche répond à « comment faire… ? » pour un ou plusieurs rôles :
 *   id      identifiant stable
 *   pour    groupes concernés : demandeur, visionneur (et technicien),
 *           gestionnaire (et admin), sa (Super Admin), exploitant (Super Admin,
 *           ou gestionnaire d'une installation simple)
 *   cat     catégorie (voir « categories »)
 *   page    écran à ouvrir (bouton de la fiche) ; la fiche n'est montrée
 *           que si cet écran est ouvert à l'utilisateur
 *   action  fenêtre à ouvrir à la place d'un écran (profil, interface…)
 *   si      condition : assistant (activé), consultation (onglets ouverts au
 *           demandeur), mono / multi (installation simple ou multi-tenant)
 *   fr, en  titre, mots (synonymes pour la recherche), texte, etapes, note,
 *           bouton — « **gras** » et « `code` » sont permis
 *
 * Les fiches propres au serveur (démarrer, diagnostiquer, sauvegarder…)
 * dépendent du mode de fonctionnement : elles sont construites par
 * api/routes/aide.php. Le serveur ne renvoie à chacun que les fiches de son rôle.
 */
return json_decode(<<<'JSON'
{
 "categories": {
  "debut": {
   "icone": "",
   "fr": "Premiers pas",
   "en": "Getting started"
  },
  "compte": {
   "icone": "",
   "fr": "Mon compte",
   "en": "My account"
  },
  "demandes": {
   "icone": "",
   "fr": "Mes demandes",
   "en": "My requests"
  },
  "quotidien": {
   "icone": "",
   "fr": "Au quotidien",
   "en": "Everyday tools"
  },
  "consulter": {
   "icone": "",
   "fr": "Consulter",
   "en": "Viewing"
  },
  "maintenance": {
   "icone": "",
   "fr": "Demandes et interventions",
   "en": "Requests and work orders"
  },
  "patrimoine": {
   "icone": "",
   "fr": "Patrimoine et stock",
   "en": "Assets and stock"
  },
  "plans": {
   "icone": "",
   "fr": "Plans et annuaire",
   "en": "Floor plans and directory"
  },
  "energie": {
   "icone": "",
   "fr": "Énergie et carbone",
   "en": "Energy and carbon"
  },
  "connect": {
   "icone": "",
   "fr": "Bâtiments connectés",
   "en": "Connected buildings"
  },
  "modules": {
   "icone": "",
   "fr": "Autres modules",
   "en": "Other modules"
  },
  "admin": {
   "icone": "",
   "fr": "Administration",
   "en": "Administration"
  },
  "serveur": {
   "icone": "",
   "fr": "Serveur et maintenance",
   "en": "Server and maintenance"
  },
  "sa_orga": {
   "icone": "",
   "fr": "Organisations",
   "en": "Organisations"
  },
  "sa_comptes": {
   "icone": "",
   "fr": "Comptes",
   "en": "Accounts"
  },
  "sa_donnees": {
   "icone": "",
   "fr": "Sauvegardes et données",
   "en": "Backups and data"
  },
  "sa_plateforme": {
   "icone": "",
   "fr": "Plateforme",
   "en": "Platform"
  },
  "sa_conformite": {
   "icone": "",
   "fr": "Conformité et sécurité",
   "en": "Compliance and security"
  },
  "faq": {
   "icone": "",
   "fr": "Problèmes courants",
   "en": "Common problems"
  }
 },
 "fiches": [
  {
   "id": "connexion",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "fr": {
    "titre": "Se connecter",
    "mots": "connexion connecter identifiant login compte accès entrer microsoft google sso",
    "texte": "Larka s'ouvre dans un navigateur (Chrome, Edge, Firefox, Safari), à l'adresse fournie par votre organisation.",
    "etapes": [
     "**Compte Larka** : saisissez votre identifiant (ou votre e-mail) et votre mot de passe, puis validez.",
     "**Compte Microsoft ou Google** : cliquez sur le bouton correspondant et connectez-vous avec votre compte professionnel."
    ],
    "note": "Après plusieurs essais infructueux rapprochés, la connexion est bloquée quelques minutes : patientez, puis réessayez."
   },
   "en": {
    "titre": "Sign in",
    "mots": "sign in log in login account access microsoft google sso",
    "texte": "Larka opens in a web browser (Chrome, Edge, Firefox, Safari), at the address provided by your organisation.",
    "etapes": [
     "**Larka account**: enter your username (or email) and your password, then confirm.",
     "**Microsoft or Google account**: click the matching button and sign in with your work account."
    ],
    "note": "After several failed attempts in a row, sign-in is blocked for a few minutes: wait, then try again."
   }
  },
  {
   "id": "mdp-oublie",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "fr": {
    "titre": "J'ai oublié mon mot de passe",
    "mots": "oublié oubli perdu mot de passe mdp réinitialiser récupérer password",
    "texte": "Sur l'écran de connexion, cliquez sur **Mot de passe oublié** (comptes Larka uniquement).",
    "etapes": [
     "Indiquez votre identifiant et l'adresse e-mail liée au compte.",
     "Ouvrez le lien reçu par e-mail : il n'est valable qu'un temps limité.",
     "Choisissez un nouveau mot de passe."
    ],
    "note": "Pas d'e-mail ? L'envoi n'est peut-être pas configuré dans votre organisation : adressez-vous à votre gestionnaire. Un compte Microsoft ou Google se gère chez son fournisseur."
   },
   "en": {
    "titre": "I forgot my password",
    "mots": "forgot forgotten lost password reset recover",
    "texte": "On the sign-in screen, click **Forgotten password** (Larka accounts only).",
    "etapes": [
     "Enter your username and the email address linked to the account.",
     "Open the link you receive by email: it is only valid for a limited time.",
     "Choose a new password."
    ],
    "note": "No email? Sending may not be set up in your organisation: contact your manager. A Microsoft or Google account is managed by its provider."
   }
  },
  {
   "id": "installer",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "fr": {
    "titre": "Installer Larka sur mon téléphone ou mon ordinateur",
    "mots": "installer application appli pwa écran d'accueil raccourci mobile téléphone tablette",
    "texte": "Larka peut s'installer comme une application : elle s'ouvre alors en plein écran et peut afficher des notifications.",
    "etapes": [
     "Sur ordinateur : icône **Installer l'application** dans la barre d'adresse du navigateur.",
     "Sur téléphone ou tablette : menu du navigateur → **Ajouter à l'écran d'accueil**."
    ],
    "note": "Sur iPhone et iPad, les notifications ne fonctionnent qu'une fois Larka ajouté à l'écran d'accueil (iOS 16.4 ou plus récent)."
   },
   "en": {
    "titre": "Install Larka on my phone or computer",
    "mots": "install app application pwa home screen shortcut mobile phone tablet",
    "texte": "Larka can be installed like an app: it then opens full screen and can show notifications.",
    "etapes": [
     "On a computer: **Install app** icon in the browser's address bar.",
     "On a phone or tablet: browser menu → **Add to Home Screen**."
    ],
    "note": "On iPhone and iPad, notifications only work once Larka has been added to the home screen (iOS 16.4 or later)."
   }
  },
  {
   "id": "se-reperer",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "fr": {
    "titre": "Se repérer dans l'écran",
    "mots": "menu écran interface navigation barre rubrique où trouver mobile téléphone",
    "texte": "L'écran se compose de trois zones :",
    "etapes": [
     "**Le menu** (à gauche) : vos rubriques, regroupées par thème.",
     "**L'en-tête** (en haut) : le titre de la page, les boutons **Interface** et **Mon profil**, et la cloche des notifications.",
     "**La zone de travail** (au centre) : le contenu de la rubrique choisie."
    ],
    "note": "Sur téléphone, le menu se replie : une barre apparaît en bas de l'écran, et le bouton ☰ donne accès à toutes les rubriques."
   },
   "en": {
    "titre": "Find your way around the screen",
    "mots": "menu screen interface navigation bar section where find mobile phone",
    "texte": "The screen has three areas:",
    "etapes": [
     "**The menu** (left): your sections, grouped by theme.",
     "**The header** (top): the page title, the **Interface** and **My profile** buttons, and the notification bell.",
     "**The work area** (centre): the content of the selected section."
    ],
    "note": "On a phone the menu folds away: a bar appears at the bottom of the screen, and the ☰ button opens every section."
   }
  },
  {
   "id": "rechercher",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "fr": {
    "titre": "Retrouver un élément dans une liste",
    "mots": "chercher rechercher trouver filtrer trier liste colonne filtre tri retrouver",
    "texte": "Toutes les listes se pilotent de la même façon :",
    "etapes": [
     "**Rechercher** : tapez quelques lettres d'un nom, d'un numéro ou d'un mot-clé dans le champ de recherche.",
     "**Trier** : cliquez sur l'en-tête d'une colonne (une seconde fois pour inverser l'ordre).",
     "**Filtrer** : ouvrez les filtres proposés (statut, catégorie, période…).",
     "**Ouvrir une fiche** : cliquez sur sa ligne."
    ]
   },
   "en": {
    "titre": "Find an item in a list",
    "mots": "search find filter sort list column",
    "texte": "Every list works the same way:",
    "etapes": [
     "**Search**: type a few letters of a name, number or keyword in the search field.",
     "**Sort**: click a column header (click again to reverse the order).",
     "**Filter**: open the available filters (status, category, period…).",
     "**Open a record**: click its row."
    ]
   }
  },
  {
   "id": "exporter",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "fr": {
    "titre": "Exporter une liste (Excel, PDF)",
    "mots": "exporter export excel csv pdf télécharger extraire tableau imprimer",
    "texte": "Le bouton **Exporter** d'une liste enregistre ses données dans un fichier.",
    "etapes": [
     "Filtrez d'abord la liste si vous ne voulez qu'une partie des lignes.",
     "Cliquez sur **Exporter**, puis choisissez **CSV** (s'ouvre dans Excel) ou **PDF**, pour les données filtrées ou pour toutes."
    ]
   },
   "en": {
    "titre": "Export a list (Excel, PDF)",
    "mots": "export excel csv pdf download extract table print",
    "texte": "A list's **Export** button saves its data to a file.",
    "etapes": [
     "Filter the list first if you only want some of the rows.",
     "Click **Export**, then choose **CSV** (opens in Excel) or **PDF**, for the filtered data or for all of it."
    ]
   }
  },
  {
   "id": "notifications",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "debut",
   "action": "notifications",
   "fr": {
    "titre": "Recevoir les notifications",
    "mots": "notification notifications cloche alerte pastille prévenir avertir push",
    "texte": "La cloche de l'en-tête signale ce qui demande votre attention ; une pastille indique le nombre d'éléments non vus.",
    "etapes": [
     "Cliquez sur la cloche pour voir la liste ; **Tout marquer lu** la vide.",
     "**Paramètres** choisit ce qui vous est signalé (réponses à vos demandes ; pour les gestionnaires : nouvelles demandes, stock en alerte, contrats proches de l'échéance).",
     "Pour être prévenu hors de Larka, acceptez les notifications quand votre navigateur vous le propose."
    ],
    "note": "Rien n'arrive ? Vérifiez que les notifications sont autorisées pour Larka dans le navigateur ou le téléphone.",
    "bouton": "Ouvrir les notifications"
   },
   "en": {
    "titre": "Get notifications",
    "mots": "notification notifications bell alert badge notify warn push",
    "texte": "The bell in the header shows what needs your attention; a badge gives the number of unseen items.",
    "etapes": [
     "Click the bell to see the list; **Mark all as read** clears it.",
     "**Settings** chooses what you are told about (replies to your requests; for managers: new requests, stock alerts, contracts nearing their end).",
     "To be notified outside Larka, accept notifications when your browser offers them."
    ],
    "note": "Nothing arrives? Check that notifications are allowed for Larka in the browser or on the phone.",
    "bouton": "Open notifications"
   }
  },
  {
   "id": "profil",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "compte",
   "action": "profil",
   "fr": {
    "titre": "Modifier mon profil",
    "mots": "profil modifier coordonnées téléphone service poste nom e-mail mes informations",
    "texte": "Le bouton **Mon profil** (en haut à droite) ouvre vos informations : nom, téléphones, service, poste…",
    "etapes": [
     "Modifiez ce qui doit l'être, puis **Enregistrer**."
    ],
    "note": "Avec un compte Microsoft ou Google, certaines informations viennent de ce compte et ne se modifient pas dans Larka.",
    "bouton": "Ouvrir mon profil"
   },
   "en": {
    "titre": "Edit my profile",
    "mots": "profile edit details phone department job name email my information",
    "texte": "The **My profile** button (top right) opens your details: name, phone numbers, department, job…",
    "etapes": [
     "Change what needs changing, then **Save**."
    ],
    "note": "With a Microsoft or Google account, some details come from that account and cannot be changed in Larka.",
    "bouton": "Open my profile"
   }
  },
  {
   "id": "mot-de-passe",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "compte",
   "action": "profil",
   "fr": {
    "titre": "Changer mon mot de passe",
    "mots": "changer modifier mot de passe mdp sécurité password nouveau",
    "texte": "Pour un compte Larka (pas Microsoft ni Google) :",
    "etapes": [
     "Ouvrez **Mon profil** (en haut à droite).",
     "Section **Changer le mot de passe** : saisissez le mot de passe actuel, puis le nouveau (8 caractères au moins).",
     "**Enregistrer**."
    ],
    "note": "Choisissez un mot de passe long, mêlant lettres, chiffres et symboles, que vous n'utilisez nulle part ailleurs.",
    "bouton": "Ouvrir mon profil"
   },
   "en": {
    "titre": "Change my password",
    "mots": "change password security new",
    "texte": "For a Larka account (not Microsoft or Google):",
    "etapes": [
     "Open **My profile** (top right).",
     "**Change the password** section: enter your current password, then the new one (at least 8 characters).",
     "**Save**."
    ],
    "note": "Choose a long password mixing letters, numbers and symbols, that you use nowhere else.",
    "bouton": "Open my profile"
   }
  },
  {
   "id": "langue",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire",
    "sa"
   ],
   "cat": "compte",
   "action": "interface",
   "fr": {
    "titre": "Passer l'interface en anglais",
    "mots": "langue anglais english français traduction traduire language",
    "texte": "Le bouton **Interface** (en haut à droite) → **Langue** → **en — English**.",
    "note": "Le choix ne concerne que vous ; il s'applique aussi à l'écran de connexion de ce navigateur. Revenir au français : même chemin, **Français**.",
    "bouton": "Ouvrir Interface"
   },
   "en": {
    "titre": "Switch the interface language",
    "mots": "language english french translation translate langue",
    "texte": "The **Interface** button (top right) → **Language** → **en — English**, or **Français** for French.",
    "note": "The choice only affects you; it also applies to the sign-in screen in this browser.",
    "bouton": "Open Interface"
   }
  },
  {
   "id": "affichage",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "compte",
   "action": "interface",
   "fr": {
    "titre": "Personnaliser l'affichage",
    "mots": "affichage personnaliser menu ordre masquer rubrique position barre mode sombre thème couleurs préférences",
    "texte": "Le bouton **Interface** (en haut à droite) règle l'affichage pour vous seul :",
    "etapes": [
     "**Position de la navigation** : menu à gauche, en haut, à droite ou en bas.",
     "Ordre et visibilité des rubriques du menu, thème et langue.",
     "Le **Mode sombre** se trouve en bas du menu."
    ],
    "note": "Ces réglages sont propres à votre compte et n'affectent pas les autres utilisateurs.",
    "bouton": "Ouvrir Interface"
   },
   "en": {
    "titre": "Customise the display",
    "mots": "display customise menu order hide section position bar dark mode theme colours preferences",
    "texte": "The **Interface** button (top right) sets the display for you alone:",
    "etapes": [
     "**Navigation position**: menu on the left, top, right or bottom.",
     "Order and visibility of the menu sections, theme and language.",
     "**Dark mode** is at the bottom of the menu."
    ],
    "note": "These settings belong to your account and do not affect other users.",
    "bouton": "Open Interface"
   }
  },
  {
   "id": "donnees-perso",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "compte",
   "action": "rgpd",
   "fr": {
    "titre": "Mes données personnelles (RGPD)",
    "mots": "rgpd données personnelles vie privée confidentialité droits effacement accès cnil",
    "texte": "La politique de protection des données explique ce que Larka enregistre sur vous, pourquoi, pour combien de temps, et comment exercer vos droits.",
    "etapes": [
     "Ouvrez **Protection des données** (bas du menu, ou lien sous le formulaire de connexion).",
     "Pour exercer vos droits (accès, rectification, effacement…), écrivez au contact indiqué dans la politique ; à défaut, à votre gestionnaire."
    ],
    "note": "Si votre organisation a complété la politique, un bandeau daté le signale, et chaque section modifiée porte la mention « Personnalisé par l'entreprise ».",
    "bouton": "Lire la politique"
   },
   "en": {
    "titre": "My personal data (GDPR)",
    "mots": "gdpr personal data privacy confidentiality rights erasure access",
    "texte": "The data protection policy explains what Larka records about you, why, for how long, and how to exercise your rights.",
    "etapes": [
     "Open **Data protection** (bottom of the menu, or the link under the sign-in form).",
     "To exercise your rights (access, correction, erasure…), write to the contact given in the policy; failing that, to your manager."
    ],
    "note": "If your organisation has completed the policy, a dated banner says so, and each modified section is marked “Customised by the organisation”.",
    "bouton": "Read the policy"
   }
  },
  {
   "id": "assistant-demandeur",
   "pour": [
    "demandeur"
   ],
   "cat": "compte",
   "action": "assistant",
   "si": "assistant",
   "fr": {
    "titre": "Préparer une demande avec l'assistant",
    "mots": "assistant ia intelligence artificielle chat bulle question préparer demande aide",
    "texte": "La bulle en bas à droite ouvre l'assistant. Décrivez simplement votre problème, par exemple « poignée cassée, 2ᵉ étage, bureau 235 ».",
    "etapes": [
     "Il rédige un titre, range le bâtiment et le lieu dans les bons champs, puis ouvre le formulaire de demande pré-rempli.",
     "Relisez, corrigez si besoin et envoyez : rien ne part sans vous.",
     "Il répond aussi à « Où en est ma dernière demande ? » ou « Qui contacter pour un problème de plomberie ? »."
    ],
    "note": "Avant tout envoi à un modèle d'IA, les noms, e-mails et numéros de téléphone sont remplacés par des marqueurs anonymes.",
    "bouton": "Ouvrir l'assistant"
   },
   "en": {
    "titre": "Prepare a request with the assistant",
    "mots": "assistant ai artificial intelligence chat bubble question prepare request help",
    "texte": "The bubble at the bottom right opens the assistant. Just describe your problem, for example “broken handle, 2nd floor, office 235”.",
    "etapes": [
     "It writes a title, puts the building and location in the right fields, then opens the pre-filled request form.",
     "Check, correct if needed and send: nothing goes without you.",
     "It also answers “Where is my last request?” or “Who should I contact about a plumbing problem?”."
    ],
    "note": "Before anything is sent to an AI model, names, emails and phone numbers are replaced with anonymous markers.",
    "bouton": "Open the assistant"
   }
  },
  {
   "id": "assistant",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "compte",
   "action": "assistant",
   "si": "assistant",
   "fr": {
    "titre": "Poser une question à l'assistant",
    "mots": "assistant ia intelligence artificielle chat bulle question chercher trouver où combien",
    "texte": "La bulle en bas à droite ouvre l'assistant. Posez votre question en langage courant, même approximatif : « combien d'interventions en cours ? », « où est l'extincteur 2 du centre technique ? », « quels contrats arrivent à échéance ? ».",
    "etapes": [
     "Il cherche dans le patrimoine, la maintenance, les plans et les modules installés, et répond avec des liens vers les fiches.",
     "Le bouton **■** arrête une réponse en cours."
    ],
    "note": "Il ne voit que ce que votre compte peut déjà voir, et ne modifie rien : créer, modifier ou supprimer se fait dans les rubriques.",
    "bouton": "Ouvrir l'assistant"
   },
   "en": {
    "titre": "Ask the assistant a question",
    "mots": "assistant ai artificial intelligence chat bubble question search find where how many",
    "texte": "The bubble at the bottom right opens the assistant. Ask in everyday language, even loosely: “how many work orders in progress?”, “where is fire extinguisher 2 in the technical centre?”, “which contracts end soon?”.",
    "etapes": [
     "It searches assets, maintenance, floor plans and installed modules, and answers with links to the records.",
     "The **■** button stops an answer in progress."
    ],
    "note": "It only sees what your account can already see, and changes nothing: creating, editing or deleting happens in the sections.",
    "bouton": "Open the assistant"
   }
  },
  {
   "id": "demande-creer",
   "pour": [
    "demandeur",
    "visionneur"
   ],
   "cat": "demandes",
   "page": "demandes",
   "fr": {
    "titre": "Signaler un problème (nouvelle demande)",
    "mots": "demande signaler panne problème réparer travaux nouvelle créer déclarer incident fuite",
    "texte": "Une demande prévient l'équipe de maintenance d'un problème ou d'un besoin.",
    "etapes": [
     "Ouvrez **Nouvelle demande** (menu **Mes demandes**), ou **+ Nouvelle demande** dans la rubrique Demandes.",
     "Type **Technique** (ou un autre type proposé par votre organisation).",
     "Donnez un **titre** court et décrivez le problème : quoi, où, depuis quand.",
     "Indiquez le **bâtiment** et le **bureau / emplacement**, l'**urgence**, et ajoutez des **photos** si c'est utile (4 au plus).",
     "**Envoyer**."
    ],
    "note": "Décrivez le problème (« le radiateur du bureau 204 ne chauffe plus »), pas la solution. Pour quelqu'un d'autre : cochez « Je fais cette demande pour quelqu'un d'autre ».",
    "bouton": "Nouvelle demande"
   },
   "en": {
    "titre": "Report a problem (new request)",
    "mots": "request report fault problem repair works new create incident leak",
    "texte": "A request tells the maintenance team about a problem or a need.",
    "etapes": [
     "Open **New request** (**My requests** menu), or **+ New request** in the Requests section.",
     "Type **Technical** (or another type offered by your organisation).",
     "Give a short **title** and describe the problem: what, where, since when.",
     "Fill in the **building** and the **office / location**, the **urgency**, and add **photos** if useful (up to 4).",
     "**Send**."
    ],
    "note": "Describe the problem (“the radiator in office 204 no longer heats”), not the solution. On someone else's behalf: tick “I am making this request for someone else”.",
    "bouton": "New request"
   }
  },
  {
   "id": "demande-suivre",
   "pour": [
    "demandeur"
   ],
   "cat": "demandes",
   "page": "historique",
   "fr": {
    "titre": "Suivre mes demandes",
    "mots": "suivre suivi demande état statut avancement où en est réponse historique passées",
    "texte": "Vos demandes en cours s'affichent sur l'écran **Nouvelle demande** ; les demandes terminées sont dans **Demandes passées**.",
    "etapes": [
     "Chaque demande indique son état : **Nouveau**, **En cours**, **Traité** ou **Refusé**, et la réponse du gestionnaire.",
     "Si une intervention a été planifiée, elle apparaît sous la demande avec son avancement."
    ],
    "note": "Activez « Réponses à mes demandes » dans les paramètres de la cloche pour être prévenu.",
    "bouton": "Mes demandes passées"
   },
   "en": {
    "titre": "Track my requests",
    "mots": "track follow request state status progress where reply history past",
    "texte": "Your current requests are shown on the **New request** screen; finished requests are in **Past requests**.",
    "etapes": [
     "Each request shows its state: **New**, **In progress**, **Handled** or **Refused**, and the manager's reply.",
     "If a work order has been scheduled, it appears under the request with its progress."
    ],
    "note": "Turn on “Replies to my requests” in the bell's settings to be notified.",
    "bouton": "My past requests"
   }
  },
  {
   "id": "demande-suivre-v",
   "pour": [
    "visionneur"
   ],
   "cat": "demandes",
   "page": "demandes",
   "fr": {
    "titre": "Suivre mes demandes",
    "mots": "suivre suivi demande état statut avancement où en est réponse",
    "texte": "La rubrique **Demandes** affiche l'état de chaque demande : **Nouveau**, **En cours**, **Traité** ou **Refusé**, avec la réponse du gestionnaire.",
    "note": "Activez « Réponses à mes demandes » dans les paramètres de la cloche pour être prévenu.",
    "bouton": "Ouvrir les demandes"
   },
   "en": {
    "titre": "Track my requests",
    "mots": "track follow request state status progress where reply",
    "texte": "The **Requests** section shows each request's state: **New**, **In progress**, **Handled** or **Refused**, with the manager's reply.",
    "note": "Turn on “Replies to my requests” in the bell's settings to be notified.",
    "bouton": "Open requests"
   }
  },
  {
   "id": "demande-relancer",
   "pour": [
    "demandeur",
    "visionneur"
   ],
   "cat": "demandes",
   "page": "demandes",
   "fr": {
    "titre": "Relancer une demande qui n'avance pas",
    "mots": "relancer relance rappel attente bloquée n'avance pas lente urgent",
    "texte": "Quand une demande reste sans suite au-delà du délai prévu (7 jours par défaut), un bouton **Relancer** apparaît sur sa carte.",
    "etapes": [
     "Cliquez sur **Relancer** : le gestionnaire est prévenu, et la demande passe à l'état **Relancé**."
    ],
    "note": "Pour une urgence réelle (fuite, danger), ne vous contentez pas d'une demande : appelez votre référent ou consultez les procédures d'urgence.",
    "bouton": "Ouvrir mes demandes"
   },
   "en": {
    "titre": "Send a reminder about a stalled request",
    "mots": "reminder follow up chase waiting stuck slow urgent",
    "texte": "When a request gets no response beyond the expected delay (7 days by default), a **Send a reminder** button appears on its card.",
    "etapes": [
     "Click **Send a reminder**: the manager is notified and the request moves to the **Reminder sent** state."
    ],
    "note": "For a real emergency (leak, danger), do not rely on a request alone: call your contact person or check the emergency procedures.",
    "bouton": "Open my requests"
   }
  },
  {
   "id": "demande-archives",
   "pour": [
    "demandeur",
    "visionneur"
   ],
   "cat": "demandes",
   "page": "demandes",
   "fr": {
    "titre": "Demander un archivage ou sortir un dossier des archives",
    "mots": "archives archivage désarchivage dossier boîte sortir consulter versement",
    "texte": "La nouvelle demande propose un type **Archive**.",
    "etapes": [
     "Ouvrez une nouvelle demande et choisissez **Archive**.",
     "**Archivage** : nombre de dossiers estimé, service concerné, période couverte.",
     "**Désarchivage** : date de solde et numéro de chaque dossier demandé (**+ Ajouter un N°** pour plusieurs).",
     "Décrivez la recherche, puis **Envoyer**."
    ],
    "bouton": "Nouvelle demande"
   },
   "en": {
    "titre": "Request archiving or retrieve a file from the archives",
    "mots": "archives archiving retrieval file box retrieve consult transfer",
    "texte": "A new request offers an **Archive** type.",
    "etapes": [
     "Open a new request and choose **Archive**.",
     "**Archiving**: estimated number of files, department concerned, period covered.",
     "**Retrieval**: closing date and the number of each file requested (**+ Add a number** for several).",
     "Describe the search, then **Send**."
    ],
    "bouton": "New request"
   }
  },
  {
   "id": "mobilite",
   "pour": [
    "demandeur",
    "visionneur"
   ],
   "cat": "quotidien",
   "page": "mobilite_carbone",
   "fr": {
    "titre": "Déclarer mes trajets (mobilité carbone)",
    "mots": "mobilité carbone trajet déplacement transport voiture vélo train bus empreinte co2 domicile travail",
    "texte": "**Ma mobilité** estime l'empreinte carbone de vos déplacements.",
    "etapes": [
     "Ouvrez la rubrique et cliquez sur **+ Ajouter un trajet**.",
     "Choisissez le **moyen de transport**, la **distance par trajet** (km), le **nombre de trajets** et la **fréquence**.",
     "Enregistrez : l'empreinte est calculée avec les facteurs d'émission de référence."
    ],
    "bouton": "Ouvrir ma mobilité"
   },
   "en": {
    "titre": "Log my journeys (carbon mobility)",
    "mots": "mobility carbon journey trip travel transport car bike train bus footprint co2 commute",
    "texte": "**My mobility** estimates the carbon footprint of your travel.",
    "etapes": [
     "Open the section and click **+ Add a journey**.",
     "Choose the **means of transport**, the **distance per journey** (km), the **number of journeys** and the **frequency**.",
     "Save: the footprint is calculated with reference emission factors."
    ],
    "bouton": "Open my mobility"
   }
  },
  {
   "id": "mes-biens",
   "pour": [
    "demandeur"
   ],
   "cat": "quotidien",
   "page": "inventaire_agent",
   "fr": {
    "titre": "Déclarer mes biens (inventaire)",
    "mots": "inventaire biens déclarer matériel ordinateur affecté campagne scanner étiquette",
    "texte": "Pendant une campagne d'inventaire, **Mes biens** liste le matériel qui vous est affecté.",
    "etapes": [
     "Onglet **Mes biens** : vérifiez la liste.",
     "Onglet **Déclarer** : identifiez un bien (**Scanner** son étiquette ou **Rechercher**), puis déclarez que vous l'avez — ou indiquez où il se trouve (autre bureau, donné ou prêté, retourné au magasin, hors service).",
     "Onglet **Déclarations** : suivez vos déclarations (en attente, validées, refusées avec le motif)."
    ],
    "note": "Chaque déclaration est validée par un gestionnaire.",
    "bouton": "Ouvrir mes biens"
   },
   "en": {
    "titre": "Report my assets (inventory)",
    "mots": "inventory assets report equipment computer assigned campaign scan label",
    "texte": "During an inventory campaign, **My assets** lists the equipment assigned to you.",
    "etapes": [
     "**My assets** tab: check the list.",
     "**Report** tab: identify an asset (**Scan** its label or **Search**), then confirm you have it — or say where it is (another office, given or lent, returned to stores, out of order).",
     "**Declarations** tab: follow your declarations (pending, approved, or refused with the reason)."
    ],
    "note": "Each declaration is approved by a manager.",
    "bouton": "Open my assets"
   }
  },
  {
   "id": "urgences",
   "pour": [
    "demandeur",
    "visionneur"
   ],
   "cat": "quotidien",
   "page": "urgences",
   "fr": {
    "titre": "Consulter les procédures d'urgence",
    "mots": "urgence urgences procédure incendie évacuation secours sécurité contact appeler danger",
    "texte": "**Procédures d'urgence** réunit les consignes à suivre et les contacts à appeler, par type de situation.",
    "etapes": [
     "Ouvrez la rubrique et choisissez la situation.",
     "Suivez la **procédure à suivre** ; appelez ou écrivez aux contacts d'un clic.",
     "Les **numéros d'urgence officiels** sont toujours affichés."
    ],
    "note": "Prenez le temps de la parcourir à l'avance : le jour venu, vous saurez où chercher.",
    "bouton": "Ouvrir les procédures"
   },
   "en": {
    "titre": "Read the emergency procedures",
    "mots": "emergency procedure fire evacuation rescue safety contact call danger",
    "texte": "**Emergency procedures** gathers the instructions to follow and the contacts to call, by type of situation.",
    "etapes": [
     "Open the section and choose the situation.",
     "Follow the **procedure to follow**; call or email the contacts in one click.",
     "The **official emergency numbers** are always shown."
    ],
    "note": "Take the time to browse it in advance: on the day, you will know where to look.",
    "bouton": "Open the procedures"
   }
  },
  {
   "id": "consultation",
   "pour": [
    "demandeur"
   ],
   "cat": "quotidien",
   "si": "consultation",
   "fr": {
    "titre": "Les onglets ouverts en consultation",
    "mots": "consultation lecture seule onglet ouvert tableau de bord biens stock voir œil",
    "texte": "Votre gestionnaire peut vous ouvrir, **en lecture seule**, des rubriques comme le tableau de bord, les biens ou l'énergie. Elles apparaissent dans la section **Consultation** de votre menu, marquées.",
    "etapes": [
     "Elles se consultent, se filtrent et se recherchent comme les autres listes.",
     "Aucun bouton de création ou de modification n'y est proposé : c'est normal."
    ],
    "note": "Une rubrique attendue manque ? Elle ne vous a pas été ouverte : adressez-vous à votre gestionnaire."
   },
   "en": {
    "titre": "Tabs opened to you for viewing",
    "mots": "view read-only tab dashboard assets stock eye",
    "texte": "Your manager can open sections such as the dashboard, assets or energy to you, **read-only**. They appear in the **View** section of your menu, marked.",
    "etapes": [
     "You can browse, filter and search them like any other list.",
     "No create or edit buttons are offered there: that is normal."
    ],
    "note": "A section you expected is missing? It has not been opened to you: ask your manager."
   }
  },
  {
   "id": "tableau-de-bord",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "consulter",
   "page": "dashboard",
   "fr": {
    "titre": "Lire le tableau de bord",
    "mots": "tableau de bord accueil synthèse indicateurs chiffres résumé vue d'ensemble",
    "texte": "Le **Tableau de bord** résume l'activité : indicateurs clés, répartitions, interventions à venir et points à surveiller.",
    "etapes": [
     "Cliquez sur une tuile ou un élément pour ouvrir la liste ou la fiche correspondante."
    ],
    "bouton": "Ouvrir le tableau de bord"
   },
   "en": {
    "titre": "Read the dashboard",
    "mots": "dashboard home summary indicators figures overview",
    "texte": "The **Dashboard** sums up the activity: key indicators, breakdowns, upcoming work orders and items to watch.",
    "etapes": [
     "Click a tile or an item to open the matching list or record."
    ],
    "bouton": "Open the dashboard"
   }
  },
  {
   "id": "lecture-fiche",
   "pour": [
    "visionneur"
   ],
   "cat": "consulter",
   "fr": {
    "titre": "Consulter une fiche (bien, équipement, intervention…)",
    "mots": "consulter fiche détail voir bien équipement intervention contrat stock lire historique",
    "texte": "Toutes les rubriques s'utilisent de la même façon : une liste que l'on filtre, trie et ouvre.",
    "etapes": [
     "Ouvrez la rubrique dans le menu (Biens, Équipements, Interventions, Contrats, Stock…).",
     "Recherchez ou filtrez, puis cliquez sur une ligne pour voir tout le détail, ses documents et son historique."
    ],
    "note": "Votre profil consulte sans modifier : l'absence de boutons « + Nouveau » ou de crayon est normale."
   },
   "en": {
    "titre": "View a record (asset, equipment, work order…)",
    "mots": "view record detail asset equipment work order contract stock history",
    "texte": "Every section works the same way: a list you filter, sort and open.",
    "etapes": [
     "Open the section from the menu (Assets, Equipment, Work orders, Contracts, Stock…).",
     "Search or filter, then click a row to see all the details, its documents and its history."
    ],
    "note": "Your profile views without editing: the absence of “+ New” buttons or pencils is normal."
   }
  },
  {
   "id": "scanner",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "consulter",
   "page": "scan",
   "fr": {
    "titre": "Scanner un QR code ou un code-barres",
    "mots": "scanner scan qr code barre étiquette caméra photo identifier",
    "texte": "**Scanner** utilise la caméra de votre téléphone ou de votre tablette pour ouvrir directement la fiche d'un bien, d'un équipement ou d'une boîte d'archives.",
    "etapes": [
     "Ouvrez **Scanner** (barre du bas, ou menu ☰ → Outils).",
     "Autorisez la caméra si le navigateur le demande.",
     "Présentez le code : la fiche s'ouvre dès qu'il est reconnu."
    ],
    "note": "La caméra se coupe dès que vous quittez la page.",
    "bouton": "Ouvrir le scanner"
   },
   "en": {
    "titre": "Scan a QR code or barcode",
    "mots": "scan qr code barcode label camera photo identify",
    "texte": "**Scan** uses your phone's or tablet's camera to open an asset, piece of equipment or archive box directly.",
    "etapes": [
     "Open **Scan** (bottom bar, or ☰ menu → Tools).",
     "Allow the camera if the browser asks.",
     "Show the code: the record opens as soon as it is recognised."
    ],
    "note": "The camera switches off as soon as you leave the page.",
    "bouton": "Open the scanner"
   }
  },
  {
   "id": "statistiques",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "consulter",
   "page": "statsAvancees",
   "fr": {
    "titre": "Analyser l'activité (statistiques avancées)",
    "mots": "statistiques stats graphiques analyse indicateurs coûts tendances rapport",
    "texte": "**Stats avancées** propose des analyses détaillées et des graphiques sur la maintenance, l'énergie et le parc.",
    "etapes": [
     "Choisissez l'analyse et la période ; les graphiques se mettent à jour."
    ],
    "bouton": "Ouvrir les statistiques"
   },
   "en": {
    "titre": "Analyse the activity (advanced statistics)",
    "mots": "statistics stats charts analysis indicators costs trends report",
    "texte": "**Advanced statistics** offers detailed analyses and charts on maintenance, energy and assets.",
    "etapes": [
     "Choose the analysis and the period; the charts update."
    ],
    "bouton": "Open the statistics"
   }
  },
  {
   "id": "affichage-casse",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire",
    "sa"
   ],
   "cat": "faq",
   "fr": {
    "titre": "L'application s'affiche mal après une mise à jour",
    "mots": "affichage cassé bug page blanche ne charge pas erreur mise à jour cache rafraîchir recharger",
    "texte": "Le navigateur garde peut-être d'anciens fichiers.",
    "etapes": [
     "Forcez un rechargement complet : **Ctrl+Maj+R** (Windows, Linux) ou **Cmd+Maj+R** (Mac).",
     "Sur téléphone : fermez complètement l'application installée, puis rouvrez-la."
    ]
   },
   "en": {
    "titre": "The application looks broken after an update",
    "mots": "display broken bug blank page not loading error update cache refresh reload",
    "texte": "Your browser may be keeping old files.",
    "etapes": [
     "Force a full reload: **Ctrl+Shift+R** (Windows, Linux) or **Cmd+Shift+R** (Mac).",
     "On a phone: fully close the installed app, then open it again."
    ]
   }
  },
  {
   "id": "connexion-impossible",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "faq",
   "fr": {
    "titre": "Je n'arrive pas à me connecter",
    "mots": "connexion impossible refusée bloqué erreur identifiant mot de passe incorrect accès",
    "texte": "Vérifiez l'identifiant et le mot de passe (attention aux majuscules).",
    "etapes": [
     "Après plusieurs essais, la connexion est bloquée quelques minutes : patientez.",
     "Utilisez **Mot de passe oublié** sur l'écran de connexion.",
     "Toujours rien ? Votre compte est peut-être désactivé : contactez votre gestionnaire."
    ]
   },
   "en": {
    "titre": "I cannot sign in",
    "mots": "cannot sign in refused blocked error username password incorrect access",
    "texte": "Check the username and password (mind capital letters).",
    "etapes": [
     "After several attempts, sign-in is blocked for a few minutes: wait.",
     "Use **Forgotten password** on the sign-in screen.",
     "Still nothing? Your account may be disabled: contact your manager."
    ]
   }
  },
  {
   "id": "rubrique-absente",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "faq",
   "fr": {
    "titre": "Je ne vois pas une rubrique",
    "mots": "rubrique absente manquante disparue onglet menu introuvable caché module",
    "texte": "Les rubriques dépendent de votre rôle et des modules activés pour votre organisation.",
    "etapes": [
     "Vérifiez qu'elle n'est pas masquée dans **Interface** (visibilité des rubriques du menu).",
     "Sinon, elle n'est pas ouverte à votre profil : adressez-vous à votre gestionnaire."
    ]
   },
   "en": {
    "titre": "I cannot see a section",
    "mots": "section missing gone tab menu not found hidden module",
    "texte": "Sections depend on your role and on the modules enabled for your organisation.",
    "etapes": [
     "Check that it is not hidden in **Interface** (visibility of menu sections).",
     "Otherwise it is not open to your profile: ask your manager."
    ]
   }
  },
  {
   "id": "lecture-seule",
   "pour": [
    "demandeur",
    "visionneur"
   ],
   "cat": "faq",
   "fr": {
    "titre": "Je ne peux rien modifier",
    "mots": "modifier impossible lecture seule bouton absent crayon droits permission",
    "texte": "C'est normal : votre profil consulte (visionneur) ou demande (demandeur). La création et la modification des fiches relèvent des gestionnaires.",
    "etapes": [
     "Pour signaler une erreur dans une fiche, faites une demande en la décrivant."
    ]
   },
   "en": {
    "titre": "I cannot change anything",
    "mots": "edit impossible read-only button missing pencil rights permission",
    "texte": "That is normal: your profile views (viewer) or requests (requester). Creating and editing records is the managers' job.",
    "etapes": [
     "To report a mistake in a record, make a request describing it."
    ]
   }
  },
  {
   "id": "demande-traiter",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "demandes",
   "fr": {
    "titre": "Traiter une demande",
    "mots": "demande traiter qualifier répondre refuser accepter statut catégorie priorité réponse file attente badge",
    "texte": "Les nouvelles demandes arrivent dans **Demandes** ; la pastille rouge du menu compte celles qui attendent.",
    "etapes": [
     "Cliquez sur **Traiter** sur la carte de la demande.",
     "Ajustez le titre ou la description si besoin, choisissez la **catégorie** et la **priorité**.",
     "Passez le **statut** à **En cours**, **Traité** ou **Refusé**, et écrivez la réponse dans **Commentaire / Réponse** : le demandeur la voit.",
     "**Enregistrer**."
    ],
    "note": "Le **délai de relance** (7 jours par défaut) fixe le moment où le demandeur pourra relancer une demande restée sans suite.",
    "bouton": "Ouvrir les demandes"
   },
   "en": {
    "titre": "Handle a request",
    "mots": "request handle qualify reply refuse accept status category priority queue badge",
    "texte": "New requests arrive in **Requests**; the red badge in the menu counts those waiting.",
    "etapes": [
     "Click **Handle** on the request card.",
     "Adjust the title or description if needed, choose the **category** and the **priority**.",
     "Set the **status** to **In progress**, **Handled** or **Refused**, and write the reply in **Comment / Reply**: the requester sees it.",
     "**Save**."
    ],
    "note": "The **reminder delay** (7 days by default) sets when the requester may send a reminder about a request left unanswered.",
    "bouton": "Open requests"
   }
  },
  {
   "id": "demande-intervention",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "demandes",
   "fr": {
    "titre": "Transformer une demande en intervention",
    "mots": "demande intervention créer transformer convertir planifier travaux bon",
    "texte": "Une demande technique qui appelle des travaux devient une intervention, liée à la demande.",
    "etapes": [
     "Sur la carte de la demande, cliquez sur **Créer intervention** : le formulaire reprend le titre, la description, le lieu et le contact.",
     "Complétez le type, la priorité, la date et l'agent, puis enregistrez.",
     "Le demandeur suit l'intervention sous sa demande ; la clôturer peut clôturer la demande en même temps."
    ],
    "bouton": "Ouvrir les demandes"
   },
   "en": {
    "titre": "Turn a request into a work order",
    "mots": "request work order create convert schedule works",
    "texte": "A technical request that calls for work becomes a work order linked to the request.",
    "etapes": [
     "On the request card, click **Create work order**: the form takes over the title, description, location and contact.",
     "Fill in the type, priority, date and agent, then save.",
     "The requester follows the work order under their request; closing it can close the request at the same time."
    ],
    "bouton": "Open requests"
   }
  },
  {
   "id": "intervention-creer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "interventions",
   "fr": {
    "titre": "Créer une intervention",
    "mots": "intervention créer nouvelle travaux maintenance préventive curative contrôle réglementaire bon de travail planifier",
    "texte": "Une intervention décrit une opération de maintenance curative ou diverse. Le préventif et les contrôles réglementaires ont leurs écrans : **Maintenance → Préventif** et **Contrôle réglementaire**.",
    "etapes": [
     "**Interventions** → **+ Nouvelle**.",
     "Choisissez le **type**, la **priorité**, le **statut** (Planifiée, En cours, Réalisée) et les dates (demande, **date prévue**, réalisation). Le **sommaire** en haut de la fiche mène à chaque section.",
     "Rattachez le **bien** ou l'**équipement**, le contrat ou la société, et l'agent qui intervient.",
     "Ajoutez les **pièces consommées (stock)** ; après un premier enregistrement, les devis et les documents."
    ],
    "note": "Une intervention curative ou diverse s'affiche sur le tableau de bord quelques jours avant sa date prévue (réglable dans la fiche). Bouton **Affichage** de la liste : fond coloré selon l'état (vert fait, orange en cours, rouge en retard) et délai demande → réalisation.",
    "bouton": "Ouvrir les interventions"
   },
   "en": {
    "titre": "Create a work order",
    "mots": "work order create new maintenance preventive corrective regulatory inspection job schedule",
    "texte": "A work order describes a corrective or miscellaneous maintenance operation. Preventive maintenance and regulatory inspections have their own screens: **Maintenance → Preventive** and **Regulatory inspection**.",
    "etapes": [
     "**Work orders** → **+ New**.",
     "Choose the **type**, the **priority**, the **status** (Scheduled, In progress, Done) and the dates (request, **planned date**, completion). The **summary** at the top of the form leads to each section.",
     "Link the **asset** or **equipment**, the contract or company, and the agent doing the work.",
     "Add the **parts used (stock)**; after a first save, quotes and documents."
    ],
    "note": "A corrective or miscellaneous work order shows on the dashboard a few days before its planned date (set in the form). **Display** button of the list: background colour by status (green done, orange in progress, red overdue) and request → completion time.",
    "bouton": "Open work orders"
   }
  },
  {
   "id": "maintenance-plan",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "preventif",
   "fr": {
    "titre": "Prévoir le préventif ou les contrôles réglementaires de l'année",
    "mots": "préventif préventive plan maintenance contrôle réglementaire vérification périodique passages année trimestriel semestriel annuel mensuel prix unique forfait réparti reconduire",
    "texte": "Un **plan** prévoit en une fois plusieurs passages du même genre dans l'année (entretien CTA 4 fois par an, vérification ascenseur 2 fois par an). Chaque passage devient une intervention planifiée, que l'on prend en charge et clôture comme les autres.",
    "etapes": [
     "**Maintenance → Préventif** (ou **Contrôle réglementaire**) → **＋ Nouveau plan**.",
     "Titre, travaux, bien ou équipement, contrat ou prestataire.",
     "Choisissez la **fréquence** et le **premier passage**, puis **Générer** ; ajoutez, déplacez ou retirez des dates si besoin.",
     "Le prix : **un prix par passage**, ou **un prix unique** — réparti à parts égales entre les passages, ou au forfait (porté par le plan, passages à 0 €).",
     "Cliquez une date pour ouvrir le passage ; **↻** reconduit le plan l'année suivante."
    ],
    "note": "Un passage commencé, réalisé ou terminé ne bouge plus depuis le plan. Un passage s'affiche sur le tableau de bord quelques jours avant sa date (30 par défaut). Les archives sont rangées à part dans l'Historique : interventions, préventif, contrôles.",
    "bouton": "Ouvrir le préventif"
   },
   "en": {
    "titre": "Plan the year's preventive maintenance or regulatory inspections",
    "mots": "preventive plan maintenance regulatory inspection periodic visits year quarterly half-yearly yearly monthly single price flat fee split renew",
    "texte": "A **plan** schedules several visits of the same kind in the year at once (air handler servicing 4 times a year, lift inspection twice a year). Each visit becomes a scheduled work order, taken in charge and closed like the others.",
    "etapes": [
     "**Maintenance → Preventive** (or **Regulatory inspection**) → **＋ New plan**.",
     "Title, work, asset or equipment, contract or contractor.",
     "Choose the **frequency** and the **first visit**, then **Generate**; add, move or remove dates if needed.",
     "The price: **a price per visit**, or **a single price** — split evenly between the visits, or as a flat fee (carried by the plan, visits at €0).",
     "Click a date to open the visit; **↻** renews the plan for the next year."
    ],
    "note": "A visit that is started, done or closed no longer moves from the plan. A visit shows on the dashboard a few days before its date (30 by default). Archives are kept apart in History: work orders, preventive, inspections.",
    "bouton": "Open preventive"
   }
  },
  {
   "id": "intervention-agent",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "interventions",
   "fr": {
    "titre": "Prendre en charge, déléguer ou délaisser une intervention",
    "mots": "prendre en charge affecter agent technicien déléguer délaisser assigner attribuer confier",
    "texte": "Sur la carte d'une intervention :",
    "etapes": [
     "**Je prends en charge** : vous en devenez l'agent.",
     "**Déléguer** : vous la confiez à quelqu'un d'autre.",
     "**Délaisser** : l'agent est retiré sans changer le statut ; l'historique est noté dans le commentaire."
    ],
    "bouton": "Ouvrir les interventions"
   },
   "en": {
    "titre": "Take on, delegate or release a work order",
    "mots": "take on assign agent technician delegate release allocate",
    "texte": "On a work order card:",
    "etapes": [
     "**I'll take it on**: you become its agent.",
     "**Delegate**: you hand it to someone else.",
     "**Release**: the agent is removed without changing the status; the history is noted in the comment."
    ],
    "bouton": "Open work orders"
   }
  },
  {
   "id": "intervention-cloturer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "interventions",
   "fr": {
    "titre": "Clôturer une intervention",
    "mots": "clôturer clôture terminer finir valider fermer intervention réalisée suivi administratif archiver",
    "texte": "Une intervention **En cours** ou **Réalisée** se clôture avec **Clôturer**.",
    "etapes": [
     "Renseignez d'abord le suivi administratif (budget CBDC / CHMA, date d'envoi du mail, date de réalisation), ou cochez l'absence de suivi administratif si elle n'en demande pas.",
     "**Clôturer** : ajoutez un commentaire de clôture ; si une demande est liée, elle peut être clôturée en même temps (statut Traité).",
     "L'intervention passe à **Terminée** ; 7 jours plus tard, **Archiver** la range dans l'historique."
    ],
    "note": "Tant qu'il manque un champ, le bouton reste grisé : son info-bulle dit lequel.",
    "bouton": "Ouvrir les interventions"
   },
   "en": {
    "titre": "Close a work order",
    "mots": "close closure finish complete validate work order done administrative tracking archive",
    "texte": "A work order that is **In progress** or **Done** is closed with **Close**.",
    "etapes": [
     "First fill in the administrative tracking (CBDC / CHMA budget, email sending date, completion date), or tick that it needs no administrative tracking.",
     "**Close**: add a closing comment; if a request is linked, it can be closed at the same time (Handled status).",
     "The work order becomes **Completed**; 7 days later, **Archive** moves it to the history."
    ],
    "note": "While a field is missing the button stays greyed out: its tooltip says which one.",
    "bouton": "Open work orders"
   }
  },
  {
   "id": "contrats",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "contrats",
   "fr": {
    "titre": "Suivre un contrat et ses échéances",
    "mots": "contrat échéance fin renouvellement résiliation alerte prestataire date expiration",
    "texte": "**Contrats** centralise vos contrats de maintenance, de contrôle, d'énergie ou de location.",
    "etapes": [
     "**+ Nouveau** : numéro, société, type, dates de début et de fin, montant annuel.",
     "**Alerte (jours avant fin)** : Larka prévient à l'approche de l'échéance.",
     "Rattachez le contrat aux interventions qu'il couvre."
    ],
    "note": "Activez « Contrats proches de l'expiration » dans les paramètres de la cloche.",
    "bouton": "Ouvrir les contrats"
   },
   "en": {
    "titre": "Track a contract and its deadlines",
    "mots": "contract deadline end renewal termination alert supplier date expiry",
    "texte": "**Contracts** brings together your maintenance, inspection, energy or rental contracts.",
    "etapes": [
     "**+ New**: number, company, type, start and end dates, annual amount.",
     "**Alert (days before end)**: Larka warns you as the end date approaches.",
     "Link the contract to the work orders it covers."
    ],
    "note": "Turn on “Contracts close to expiry” in the bell's settings.",
    "bouton": "Open contracts"
   }
  },
  {
   "id": "historique",
   "pour": [
    "gestionnaire"
   ],
   "cat": "maintenance",
   "page": "historique",
   "fr": {
    "titre": "Retrouver une demande traitée ou une intervention archivée",
    "mots": "historique archive archivée clôturée terminée retrouver ancienne passée supprimé suppression",
    "texte": "**Historique** rassemble les demandes terminées (traitées ou refusées), les interventions archivées, les fiches comptables clôturées et la trace des suppressions.",
    "etapes": [
     "Recherchez ou filtrez (dates, module, statut, auteur), puis ouvrez la ligne."
    ],
    "bouton": "Ouvrir l'historique"
   },
   "en": {
    "titre": "Find a handled request or an archived work order",
    "mots": "history archive archived closed completed find old past deleted deletion",
    "texte": "**History** gathers finished requests (handled or refused), archived work orders, closed accounting records and the record of deletions.",
    "etapes": [
     "Search or filter (dates, module, status, author), then open the row."
    ],
    "bouton": "Open history"
   }
  },
  {
   "id": "bien-creer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "biens",
   "fr": {
    "titre": "Créer un bien",
    "mots": "bien créer nouveau patrimoine mobilier informatique véhicule local fiche ajouter",
    "texte": "**Biens** recense votre patrimoine : mobilier, informatique, véhicules, locaux…",
    "etapes": [
     "**Biens** → **+ Nouveau**.",
     "Renseignez le nom, la localisation et les caractéristiques, puis enregistrez.",
     "La fiche peut ensuite recevoir des documents, des équipements et des interventions."
    ],
    "bouton": "Ouvrir les biens"
   },
   "en": {
    "titre": "Create an asset",
    "mots": "asset create new property furniture computer vehicle premises record add",
    "texte": "**Assets** lists your property: furniture, IT, vehicles, premises…",
    "etapes": [
     "**Assets** → **+ New**.",
     "Fill in the name, location and characteristics, then save.",
     "The record can then hold documents, equipment and work orders."
    ],
    "bouton": "Open assets"
   }
  },
  {
   "id": "equipement",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "equipements",
   "fr": {
    "titre": "Ajouter un équipement",
    "mots": "équipement créer ajouter chaudière ascenseur cvc chauffage technique installation sécurité incendie",
    "texte": "Les **Équipements** sont les installations techniques suivies par la maintenance : chaudières, ascenseurs, CVC, sécurité incendie…",
    "etapes": [
     "**Équipements** → **+ Nouveau**.",
     "Renseignez la famille, la localisation et les caractéristiques ; rattachez-le au bien concerné.",
     "Ses interventions et ses documents s'affichent ensuite sur sa fiche."
    ],
    "bouton": "Ouvrir les équipements"
   },
   "en": {
    "titre": "Add a piece of equipment",
    "mots": "equipment create add boiler lift elevator hvac heating technical installation fire safety",
    "texte": "**Equipment** covers the technical installations followed by maintenance: boilers, lifts, HVAC, fire safety…",
    "etapes": [
     "**Equipment** → **+ New**.",
     "Fill in the family, location and characteristics; link it to the asset concerned.",
     "Its work orders and documents then show on its record."
    ],
    "bouton": "Open equipment"
   }
  },
  {
   "id": "import",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "biens",
   "fr": {
    "titre": "Importer des biens ou des équipements depuis Excel",
    "mots": "importer import excel csv fichier tableur reprise données masse colonnes",
    "texte": "Le bouton **Importer** des rubriques Biens et Équipements lit un fichier Excel ou CSV, en cinq étapes :",
    "etapes": [
     "**Cible** : biens ou équipements (Larka propose selon les familles).",
     "**Fichier** : le fichier et la feuille ; la ligne des en-têtes est détectée, modifiable.",
     "**Correspondance** : pour chaque colonne, le champ Larka à remplir (détecté, retenu de votre dernier import, ou **proposé par l'IA** si l'assistant est configuré), et la conversion des valeurs de statut ou d'état. Une colonne sans équivalent (ex. « Date de sortie administrative ») devient une **case de la fiche** : choix « Nouvelle case », ou **Créer les cases** pour toutes les colonnes restantes.",
     "**Sélection** : une case par ligne. Tout cocher ou décocher, par famille, **seuil de prix** (ex. 500 € minimum), sans les biens sortis ; les lignes en erreur et les avertissements (date ou montant illisible, doublon, ligne de total) sont signalés avec la valeur en cause.",
     "**Aperçu** : vérifiez le résultat simulé, choisissez les nouvelles valeurs de listes à créer, puis importez. Le rapport se télécharge en CSV pour corriger le fichier."
    ],
    "note": "Les biens déjà présents sont mis à jour plutôt que dupliqués. Seules les lignes cochées sont importées.",
    "bouton": "Ouvrir les biens"
   },
   "en": {
    "titre": "Import assets or equipment from Excel",
    "mots": "import excel csv file spreadsheet migration bulk data columns",
    "texte": "The **Import** button in Assets and Equipment reads an Excel or CSV file, in five steps:",
    "etapes": [
     "**Target**: assets or equipment (Larka suggests one from the families).",
     "**File**: the file and the sheet; the header row is detected, and can be changed.",
     "**Mapping**: for each column, the Larka field to fill (detected, remembered from your last import, or **suggested by AI** when the assistant is configured), and how status or condition values convert. A column with no equivalent (e.g. “Administrative disposal date”) becomes a **record field**: choose “ New field”, or **Create fields** for all remaining columns.",
     "**Selection**: one checkbox per row. Check or uncheck all, by family, by **price threshold** (e.g. €500 minimum), without disposed assets; rows in error and warnings (unreadable date or amount, duplicate, total row) are flagged with the value at fault.",
     "**Preview**: check the simulated result, choose which new list values to create, then import. The report downloads as CSV to fix the file."
    ],
    "note": "Assets already present are updated rather than duplicated. Only checked rows are imported.",
    "bouton": "Open assets"
   }
  },
  {
   "id": "cases-fiches",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "biens",
   "fr": {
    "titre": "Ajouter des cases aux fiches (biens, équipements, interventions, stock)",
    "mots": "case champ personnalisé supplémentaire information complémentaire fiche colonne fourre-tout date sortie administrative",
    "texte": "Une information que Larka ne prévoit pas (date de sortie administrative, code analytique, n° de l'ancien logiciel…) peut devenir une case des fiches, dans la section **Informations complémentaires**.",
    "etapes": [
     "Bouton **Cases des fiches** des listes Biens, Équipements, Interventions ou Stock : ajoutez, renommez, ordonnez les cases, et choisissez leur type (texte, texte long, date, nombre).",
     "Ou à l'import : une colonne du fichier sans champ Larka devient une case (**Nouvelle case**), remplie pour chaque ligne importée.",
     "La recherche des listes porte aussi sur ces cases."
    ],
    "note": "Retirer une case ne supprime pas les valeurs déjà saisies : recréée sous le même nom, elle les retrouve.",
    "bouton": "Ouvrir les biens"
   },
   "en": {
    "titre": "Add fields to records (assets, equipment, work orders, stock)",
    "mots": "field custom extra additional information record column catch-all administrative disposal date",
    "texte": "Information Larka does not provide (administrative disposal date, cost code, number from the previous software…) can become a record field, in the **Additional information** section.",
    "etapes": [
     "**Record fields** button in the Assets, Equipment, Work orders or Stock lists: add, rename and order fields, and choose their type (text, long text, date, number).",
     "Or when importing: a file column with no Larka field becomes a record field (**New field**), filled for each imported row.",
     "List search also covers these fields."
    ],
    "note": "Removing a field does not delete values already entered: recreated with the same name, it finds them again.",
    "bouton": "Open assets"
   }
  },
  {
   "id": "stock",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "stock",
   "fr": {
    "titre": "Gérer le stock et ses alertes",
    "mots": "stock article pièce consommable quantité seuil alerte réapprovisionner magasin importer import excel csv inventaire livraison",
    "texte": "**Stock** suit vos articles (pièces, consommables) et leurs quantités.",
    "etapes": [
     "**+ Nouvel article** : désignation, référence, catégorie, quantité, **seuil d'alerte**, prix, emplacement, fournisseur.",
     "Les pièces saisies dans une intervention (**pièces consommées**) sont décomptées du stock.",
     "Un article passe en alerte dès que sa quantité atteint le seuil.",
     "**Importer** : un fichier Excel ou CSV d'articles. Un article déjà là (même référence) est mis à jour ; ses quantités sont **remplacées** (inventaire) ou **ajoutées** (livraison), au choix."
    ],
    "note": "Activez « Articles en alerte de stock » dans les paramètres de la cloche.",
    "bouton": "Ouvrir le stock"
   },
   "en": {
    "titre": "Manage stock and its alerts",
    "mots": "stock inventory item part consumable quantity threshold alert reorder store import excel csv stocktake delivery",
    "texte": "**Stock** tracks your items (parts, consumables) and their quantities.",
    "etapes": [
     "**+ New item**: name, reference, category, quantity, **alert threshold**, price, location, supplier.",
     "Parts entered in a work order (**parts used**) are deducted from stock.",
     "An item goes into alert as soon as its quantity reaches the threshold.",
     "**Import**: an Excel or CSV file of items. An item already there (same reference) is updated; its quantities are **replaced** (stocktake) or **added** (delivery), as you choose."
    ],
    "note": "Turn on “Items in stock alert” in the bell's settings.",
    "bouton": "Open stock"
   }
  },
  {
   "id": "documents",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "fr": {
    "titre": "Joindre un document ou une photo à une fiche",
    "mots": "document photo pièce jointe pdf fichier joindre ajouter attacher notice facture devis image",
    "texte": "Les fiches (bien, équipement, intervention, contrat…) ont une zone de documents.",
    "etapes": [
     "Ouvrez la fiche en modification.",
     "Déposez photos, PDF ou autres fichiers dans la zone de documents.",
     "Cliquez sur une miniature pour l'afficher en grand."
    ],
    "note": "Un même fichier déposé deux fois n'est stocké qu'une fois."
   },
   "en": {
    "titre": "Attach a document or photo to a record",
    "mots": "document photo attachment pdf file attach add manual invoice quote image",
    "texte": "Records (asset, equipment, work order, contract…) have a documents area.",
    "etapes": [
     "Open the record for editing.",
     "Drop photos, PDFs or other files into the documents area.",
     "Click a thumbnail to view it full size."
    ],
    "note": "The same file dropped twice is only stored once."
   }
  },
  {
   "id": "gestion-materiel",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "gestion_materiel",
   "fr": {
    "titre": "Suivre les immobilisations (gestion matériel)",
    "mots": "gestion matériel immobilisation amortissement valeur comptabilité clôture achat comptable",
    "texte": "**Gestion matériel** suit le volet comptable du matériel : immobilisation, valeur d'achat, amortissement, clôture.",
    "etapes": [
     "**+ Nouvelle fiche** : rattachez le bien ou l'équipement, puis renseignez le numéro d'immobilisation, la date et la valeur d'achat, la durée d'amortissement.",
     "Le cadenas clôture une fiche (vente, don, mise au rebut)."
    ],
    "bouton": "Ouvrir la gestion matériel"
   },
   "en": {
    "titre": "Track fixed assets (equipment management)",
    "mots": "equipment management fixed asset depreciation value accounting closure purchase",
    "texte": "**Equipment management** tracks the accounting side of equipment: fixed asset, purchase value, depreciation, closure.",
    "etapes": [
     "**+ New record**: link the asset or equipment, then fill in the fixed-asset number, purchase date and value, depreciation period.",
     "The padlock closes a record (sale, donation, disposal)."
    ],
    "bouton": "Open equipment management"
   }
  },
  {
   "id": "inventaire-campagne",
   "pour": [
    "gestionnaire"
   ],
   "cat": "patrimoine",
   "page": "configuration",
   "fr": {
    "titre": "Lancer un inventaire par les agents",
    "mots": "inventaire campagne agents déclaration mes biens valider refuser recensement",
    "texte": "Les agents (demandeurs) peuvent déclarer eux-mêmes le matériel qu'ils détiennent.",
    "etapes": [
     "**Configuration → Inventaire** : activez l'onglet **Mes biens** pour les demandeurs.",
     "Les agents déclarent leurs biens (scan ou recherche) ; une pastille sur Configuration signale les déclarations en attente.",
     "Validez (**Valider**) ou refusez (**Refuser**, avec un motif) chaque déclaration."
    ],
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Run an inventory by the agents",
    "mots": "inventory campaign agents declaration my assets approve reject census",
    "texte": "Agents (requesters) can declare the equipment they hold themselves.",
    "etapes": [
     "**Settings → Inventory**: enable the **My assets** tab for requesters.",
     "Agents declare their assets (scan or search); a badge on Settings shows pending declarations.",
     "Approve (**Confirm**) or refuse (**Reject**, with a reason) each declaration."
    ],
    "bouton": "Open settings"
   }
  },
  {
   "id": "annuaire",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "plans",
   "page": "annuaire",
   "fr": {
    "titre": "Trouver un collègue sur le plan",
    "mots": "annuaire collègue personne trouver localiser bureau téléphone e-mail plan qui où",
    "texte": "L'annuaire situe les personnes sur le plan de leur étage.",
    "etapes": [
     "Ouvrez **Plans** (onglet Annuaire), puis choisissez le bâtiment et l'étage.",
     "Cherchez avec **Rechercher une personne** (nom, service ou poste) ou parcourez **Personnes sur cet étage**.",
     "Cliquez sur la personne : son point s'allume et sa fiche s'affiche (service, poste, bureau, téléphone, e-mail)."
    ],
    "note": "Avec Microsoft 365, la fiche peut indiquer sa disponibilité et ses créneaux occupés du jour, sans le détail des rendez-vous.",
    "bouton": "Ouvrir l'annuaire"
   },
   "en": {
    "titre": "Find a colleague on the floor plan",
    "mots": "directory colleague person find locate office phone email plan who where",
    "texte": "The directory shows people on their floor's plan.",
    "etapes": [
     "Open **Floor plans** (Directory tab), then choose the building and the floor.",
     "Use **Search for a person** (name, department or job) or browse **People on this floor**.",
     "Click the person: their point lights up and their card shows (department, job, office, phone, email)."
    ],
    "note": "With Microsoft 365, the card can show their availability and today's busy slots, without meeting details.",
    "bouton": "Open the directory"
   }
  },
  {
   "id": "presence",
   "pour": [
    "demandeur",
    "visionneur",
    "gestionnaire"
   ],
   "cat": "plans",
   "page": "annuaire",
   "fr": {
    "titre": "Masquer ma présence dans l'annuaire",
    "mots": "présence masquer cacher partage disponibilité agenda statut confidentialité",
    "texte": "Votre statut de présence et vos créneaux occupés sont partagés par défaut avec les collègues qui vous cherchent.",
    "etapes": [
     "Ouvrez **Plans** et cliquez sur **Mon partage**.",
     "Décochez l'option ; vous pouvez la réactiver à tout moment."
    ],
    "bouton": "Ouvrir l'annuaire"
   },
   "en": {
    "titre": "Hide my presence in the directory",
    "mots": "presence hide sharing availability calendar status privacy",
    "texte": "Your presence status and busy slots are shared by default with colleagues looking for you.",
    "etapes": [
     "Open **Floor plans** and click **My sharing**.",
     "Untick the option; you can turn it back on at any time."
    ],
    "bouton": "Open the directory"
   }
  },
  {
   "id": "plan-creer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "plans",
   "page": "plans",
   "fr": {
    "titre": "Créer le plan d'un bâtiment",
    "mots": "plan créer bâtiment étage fond image pdf échelle dessiner zone point carte",
    "texte": "Dans **Plans**, onglet **Édition** :",
    "etapes": [
     "**+ Bâtiment**, puis **+ Étage** pour chaque niveau.",
     "Bouton **Fond** : importez l'image ou le PDF du plan ; réglez l'échelle si vous avez besoin des mesures.",
     "Dessinez points techniques, traits (murs, cloisons) et zones (surfaces) ; **+ Rattacher** relie un point à un bien ou un équipement."
    ],
    "bouton": "Ouvrir les plans"
   },
   "en": {
    "titre": "Create a building's floor plan",
    "mots": "plan create building floor background image pdf scale draw zone point map",
    "texte": "In **Floor plans**, **Editing** tab:",
    "etapes": [
     "**+ Building**, then **+ Floor** for each level.",
     "**Background** button: import the plan's image or PDF; set the scale if you need measurements.",
     "Draw technical points, lines (walls, partitions) and zones (areas); **+ Attach** links a point to an asset or piece of equipment."
    ],
    "bouton": "Open floor plans"
   }
  },
  {
   "id": "plan-personne",
   "pour": [
    "gestionnaire"
   ],
   "cat": "plans",
   "page": "plans",
   "fr": {
    "titre": "Placer une personne sur le plan",
    "mots": "plan personne bureau placer annuaire collègue agent",
    "texte": "Pour qu'un bureau apparaisse dans l'annuaire :",
    "etapes": [
     "Outil **Personne** (silhouette) de la barre d'outils, puis cliquez à l'emplacement du bureau.",
     "Choisissez un compte existant (recherche par nom, service ou poste) ou saisissez les informations à la main."
    ],
    "note": "Seuls les points créés avec l'outil Personne reçoivent une personne. Après un ajout, les utilisateurs rechargent l'étage pour le voir.",
    "bouton": "Ouvrir les plans"
   },
   "en": {
    "titre": "Place a person on the floor plan",
    "mots": "plan person office place directory colleague agent",
    "texte": "For an office to appear in the directory:",
    "etapes": [
     "**Person** tool (silhouette) on the toolbar, then click where the office is.",
     "Choose an existing account (search by name, department or job) or enter the details by hand."
    ],
    "note": "Only points created with the Person tool can hold a person. After adding one, users reload the floor to see it.",
    "bouton": "Open floor plans"
   }
  },
  {
   "id": "plan-demandeurs",
   "pour": [
    "gestionnaire"
   ],
   "cat": "plans",
   "page": "configuration",
   "fr": {
    "titre": "Ouvrir l'onglet Plans aux demandeurs",
    "mots": "plans annuaire demandeurs activer onglet ouvrir visible",
    "texte": "L'onglet **Plans** n'apparaît chez les demandeurs que si vous l'activez.",
    "etapes": [
     "**Configuration → Plans** : cochez **Activer l'onglet Plans**.",
     "Les demandeurs rechargent la page (ou se reconnectent) pour le voir."
    ],
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Open the Floor plans tab to requesters",
    "mots": "floor plans directory requesters enable tab open visible",
    "texte": "The **Floor plans** tab only appears for requesters once you enable it.",
    "etapes": [
     "**Settings → Floor plans**: tick **Enable the Floor plans tab**.",
     "Requesters reload the page (or sign in again) to see it."
    ],
    "bouton": "Open settings"
   }
  },
  {
   "id": "energie",
   "pour": [
    "gestionnaire"
   ],
   "cat": "energie",
   "page": "energie",
   "fr": {
    "titre": "Suivre les consommations d'énergie",
    "mots": "énergie compteur relevé facture consommation électricité gaz eau chauffage fluide kwh index",
    "texte": "Le module **Énergie** suit les fluides, compteur par compteur.",
    "etapes": [
     "**+ Compteur** pour chaque point de mesure (électricité, gaz, eau, réseau de chaleur…).",
     "Saisissez au fil de l'eau les **relevés** (index) et les **factures** (abonnement et consommation, HT et TTC).",
     "Consultez les graphiques de consommation et de coût ; le CO₂ est calculé automatiquement."
    ],
    "note": "Pour le chauffage et les réseaux de chaleur, suivez les libellés du formulaire : parts fixe et variable sont distinguées.",
    "bouton": "Ouvrir l'énergie"
   },
   "en": {
    "titre": "Track energy consumption",
    "mots": "energy meter reading invoice consumption electricity gas water heating utility kwh index",
    "texte": "The **Energy** module tracks utilities, meter by meter.",
    "etapes": [
     "**+ Meter** for each measuring point (electricity, gas, water, heat network…).",
     "Enter **readings** (index) and **invoices** (standing charge and consumption, excl. and incl. VAT) as they come.",
     "Check the consumption and cost charts; CO₂ is calculated automatically."
    ],
    "note": "For heating and heat networks, follow the form's labels: fixed and variable parts are kept apart.",
    "bouton": "Open energy"
   }
  },
  {
   "id": "carbone",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "energie",
   "page": "carbone",
   "fr": {
    "titre": "Consulter le bilan carbone",
    "mots": "carbone bilan co2 émissions empreinte gaz à effet de serre ademe facteurs",
    "texte": "**Bilan Carbone** estime l'empreinte de l'activité (énergie, mobilité…) avec les facteurs d'émission de la Base Carbone de l'ADEME.",
    "etapes": [
     "Choisissez l'année : la répartition par poste et l'évolution s'affichent."
    ],
    "bouton": "Ouvrir le bilan carbone"
   },
   "en": {
    "titre": "View the carbon footprint",
    "mots": "carbon footprint co2 emissions greenhouse gas ademe factors",
    "texte": "**Carbon footprint** estimates the activity's footprint (energy, mobility…) with the emission factors of the ADEME Base Carbone.",
    "etapes": [
     "Choose the year: the breakdown by item and the trend appear."
    ],
    "bouton": "Open the carbon footprint"
   }
  },
  {
   "id": "mobilite-gestion",
   "pour": [
    "gestionnaire"
   ],
   "cat": "energie",
   "page": "mobilite_carbone",
   "fr": {
    "titre": "Voir la mobilité déclarée",
    "mots": "mobilité carbone trajets déplacements consolidé agents organisation",
    "texte": "**Ma mobilité** présente aussi, pour les gestionnaires, une vue consolidée des trajets déclarés par les agents.",
    "bouton": "Ouvrir la mobilité"
   },
   "en": {
    "titre": "View declared mobility",
    "mots": "mobility carbon journeys travel consolidated agents organisation",
    "texte": "For managers, **My mobility** also shows a consolidated view of the journeys declared by agents.",
    "bouton": "Open mobility"
   }
  },
  {
   "id": "connect-supervision",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect",
   "fr": {
    "titre": "Voir l'état des équipements raccordés",
    "mots": "supervision connect gtb gtc équipement raccordé valeur température consigne compteur capteur borne état en ligne défaut courbe historique temps réel consommation conso du mois index import énergie relevé export excel csv filtre mode avancé graphique",
    "texte": "**Bâtiments → Supervision** affiche la dernière valeur de chaque point suivi (température, index, état…). C'est le serveur qui interroge les équipements, à son rythme : ouvrir l'écran ne les sollicite pas.",
    "etapes": [
     "Les **cartes des bâtiments**, en haut, résument chacun : connexions en service ou en défaut, alarmes, bornes en charge, dernier échange. Cliquez sur une carte pour n'afficher que ce bâtiment, ou cherchez un équipement ou un point.",
     "Cliquez sur une valeur pour voir sa **courbe** sur 24 heures, 7 jours, 30 jours ou 1 an ; les flèches gauche et droite parcourent les valeurs.",
     "Une valeur en alarme porte une cloche ; le détail est dans **Alarmes techniques**.",
     "**Simple / Avancé** : le mode avancé ajoute la tendance des dernières 24 h sur chaque point et les consommations des 12 derniers mois en graphique. Les filtres **type**, **nature** et **état** réduisent les cartes aux points qui comptent.",
     "**Consommations** : pour chaque compteur, la conso du mois choisi (index de fin de mois moins index de fin du mois précédent) et l'écart avec le mois d'avant. Gestionnaire : **Importer** écrit cette conso dans un compteur du module Énergie, en relevé manuel, après vérification et ajustement des index ; la case « automatiquement chaque mois » pose un relevé automatique pour la suite.",
     "**Exporter** : valeurs actuelles, consommations mensuelles ou historique des mesures (brut, par heure, par jour ou par mois) des points affichés, en Excel ou en CSV."
    ],
    "note": "Le titre **Bâtiments** du menu replie ou déplie ses entrées. Supervision, Alarmes techniques et Connexions n'y figurent que si Larka Connect est ouvert pour votre organisation.",
    "bouton": "Ouvrir la supervision"
   },
   "en": {
    "titre": "See the status of connected devices",
    "mots": "supervision connect bms building management device connected value temperature setpoint meter sensor charger status online fault chart history real time consumption monthly index import energy reading export excel csv filter advanced mode graph",
    "texte": "**Buildings → Supervision** shows the latest value of each monitored point (temperature, index, state…). The server polls the devices at its own pace: opening the screen does not query them.",
    "etapes": [
     "The **building cards**, at the top, sum up each one: connections in service or faulty, alarms, chargers charging, last exchange. Click a card to show only that building, or search for a device or a point.",
     "Click a value to see its **chart** over 24 hours, 7 days, 30 days or 1 year; the left and right arrows browse the values.",
     "A value in alarm carries a bell; the details are in **Technical alarms**.",
     "**Simple / Advanced**: advanced mode adds the last 24 hours' trend on each point and the last 12 months' consumption as a chart. The **type**, **kind** and **state** filters narrow the cards down to the points that matter.",
     "**Consumption**: for each meter, the chosen month's consumption (end-of-month index minus the previous month's end index) and the change from the month before. Manager: **Import** writes it to an Energy module meter as a manual reading, after checking and adjusting the indexes; the “every month automatically” box sets up an automatic reading for what follows.",
     "**Export**: current values, monthly consumption or measurement history (raw, per hour, per day or per month) of the points shown, as Excel or CSV."
    ],
    "note": "The **Buildings** menu title folds or unfolds its entries. Supervision, Technical alarms and Connections only appear there when Larka Connect is open for your organisation.",
    "bouton": "Open supervision"
   }
  },
  {
   "id": "connect-alarmes",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_alarmes",
   "fr": {
    "titre": "Suivre les alarmes techniques",
    "mots": "alarme alarmes techniques défaut acquitter acquittement seuil surchauffe panne capteur gtb notification",
    "texte": "Une **règle d'alarme** surveille un point : seuil dépassé, état anormal, valeur qui n'arrive plus. **Bâtiments → Alarmes techniques** liste les alarmes en cours et passées ; leur nombre s'affiche dans le menu.",
    "etapes": [
     "Chaque alarme indique sa gravité, le point, la valeur relevée et, si la règle le prévoit, la demande créée (cliquez pour l'ouvrir).",
     "Gestionnaire : **✓ Acquitter** (avec un commentaire) signale que l'alarme est prise en charge ; cochez-en plusieurs pour **Acquitter la sélection**.",
     "Une alarme se termine d'elle-même quand la condition cesse ; une alarme déjà ouverte ne crée pas de seconde demande."
    ],
    "note": "Les règles se créent dans **Connexions → Points**, colonne : seuil, durée minimale, gravité, notification des gestionnaires, demande sur l'équipement rattaché.",
    "bouton": "Ouvrir les alarmes"
   },
   "en": {
    "titre": "Follow technical alarms",
    "mots": "alarm alarms technical fault acknowledge acknowledgement threshold overheating failure sensor bms notification",
    "texte": "An **alarm rule** watches a point: threshold exceeded, abnormal state, value no longer arriving. **Buildings → Technical alarms** lists current and past alarms; their number shows in the menu.",
    "etapes": [
     "Each alarm shows its severity, the point, the value read and, if the rule says so, the request created (click to open it).",
     "Manager: **✓ Acknowledge** (with a comment) signals that the alarm is being handled; tick several to **Acknowledge selected**.",
     "An alarm ends by itself when the condition stops; an alarm already open does not create a second request."
    ],
    "note": "Rules are created in **Connections → Points**, column: threshold, minimum duration, severity, manager notification, request on the linked equipment.",
    "bouton": "Open alarms"
   }
  },
  {
   "id": "connect-raccorder",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Raccorder un équipement (GTB, compteur, capteur)",
    "mots": "raccorder connecter connexion gtb gtc automate bacnet modbus mqtt lorawan api rest csv compteur capteur passerelle pare-feu tester découvrir points simulateur simulation démonstration essayer historique",
    "texte": "Dans **Bâtiments → Connexions**, onglet **Connexions** :",
    "etapes": [
     "**Détecter les équipements** : choisissez où chercher (un bâtiment, tous les bâtiments ou d'autres adresses) ; Larka propose ce qu'il reconnaît, déjà rangé dans son bâtiment. Pour un équipement qu'il ne voit pas : **＋ Nouvelle connexion**, puis le protocole (Modbus TCP, BACnet/IP, MQTT, API REST, CSV, borne OCPP) — ou **Simulateur intégré** pour essayer sans matériel.",
     "Renseignez l'adresse et les réglages, puis **Tester la connexion** : le test vérifie le réseau (pare-feu compris), le dialogue et les droits, sans rien enregistrer.",
     "Choisissez le rythme (**Interroger toutes les…**) : c'est le serveur qui interroge, même quand personne n'a Larka ouvert.",
     "Ajoutez les points à suivre : **Découvrir** les propose (BACnet, MQTT, API, CSV) ; pour Modbus, reportez les registres de la documentation de l'équipement — **Explorer** aide à les trouver."
    ],
    "note": "Si le test échoue, Larka dit ce qui bloque et rédige le **message à transmettre au service informatique** (adresse, port et protocole à ouvrir). Pour découvrir le module avec des semaines de courbes, d'alarmes et de relevés déjà en place : `./start.sh connect-demo` (Windows : `Larka.cmd connect-demo`) sur le serveur.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Connect a device (BMS, meter, sensor)",
    "mots": "connect connection bms building management controller bacnet modbus mqtt lorawan api rest csv meter sensor gateway firewall test discover points simulator simulation demo try history",
    "texte": "In **Buildings → Connections**, **Connections** tab:",
    "etapes": [
     "**Detect devices**: choose where to look (one building, all buildings or other addresses); Larka suggests what it recognises, already filed in its building. For a device it does not see: **＋ New connection**, then the protocol (Modbus TCP, BACnet/IP, MQTT, REST API, CSV, OCPP charger) — or **Built-in simulator** to try without hardware.",
     "Enter the address and settings, then **Test the connection**: the test checks the network (firewall included), the dialogue and the permissions, without saving anything.",
     "Choose the pace (**Poll every…**): the server polls, even when nobody has Larka open.",
     "Add the points to monitor: **Discover** suggests them (BACnet, MQTT, API, CSV); for Modbus, copy the registers from the device documentation — **Explore** helps find them."
    ],
    "note": "If the test fails, Larka says what is blocking and drafts the **message to send to the IT department** (address, port and protocol to open). To discover the module with weeks of curves, alarms and readings already in place: `./start.sh connect-demo` (Windows: `Larka.cmd connect-demo`) on the server.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "connect-releves",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Programmer un relevé automatique de compteur",
    "mots": "relevé automatique compteur index énergie programmer planifier automatiser chaque jour mois heure électricité eau gaz écrire valider anomalie",
    "texte": "Un relevé automatique écrit, à l'heure prévue, la valeur d'un point dans un compteur du module **Énergie** — comme une saisie, sans saisie.",
    "etapes": [
     "Onglet **Relevés automatiques** → **＋ Nouveau relevé automatique** : choisissez la **valeur à relever** et le **compteur** qui la reçoit (coefficient possible, ex. 0,001 pour passer des Wh aux kWh).",
     "Fixez quand : chaque jour, certains jours de la semaine, une fois par mois, seulement certains mois — ou une expression cron. L'aperçu montre les prochaines échéances.",
     "La **tolérance** fixe l'écart admis autour de l'heure prévue : la valeur la plus proche est retenue.",
     "Cochez **Faire valider chaque relevé par un gestionnaire** pour relire chaque relevé avant qu'il soit écrit (**Relevés à valider**)."
    ],
    "note": "Un index qui baisse ou une consommation hors norme met le relevé en attente : validez-le (valeur corrigée possible) ou rejetez-le. Sans valeur dans la tolérance, le relevé est noté manquant et une alarme mineure est levée.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Schedule an automatic meter reading",
    "mots": "automatic reading meter index energy schedule plan automate every day month time electricity water gas write confirm anomaly",
    "texte": "An automatic reading writes, at the scheduled time, the value of a point into a meter of the **Energy** module — like a manual entry, without typing.",
    "etapes": [
     "**Automatic readings** tab → **＋ New automatic reading**: choose the **value to read** and the **meter** that receives it (a factor is possible, e.g. 0.001 to go from Wh to kWh).",
     "Set when: every day, some days of the week, once a month, only some months — or a cron expression. The preview shows the next due dates.",
     "The **tolerance** sets the allowed gap around the scheduled time: the closest value is used.",
     "Tick **Have each reading confirmed by a manager before writing it** to check each reading before it is written (**Readings to confirm**)."
    ],
    "note": "An index that drops or abnormal consumption puts the reading on hold: confirm it (a corrected value is possible) or reject it. With no value within the tolerance, the reading is marked missing and a minor alarm is raised.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "connect-consigne",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect",
   "fr": {
    "titre": "Modifier une consigne (thermostat, GTB)",
    "mots": "consigne modifier écrire température thermostat chauffage climatisation régler commande gtb écriture identité mot de passe",
    "texte": "Seuls les points déclarés **inscriptibles**, avec une plage permise, se modifient depuis Larka. Lire ne demande jamais d'identité ; écrire, toujours.",
    "etapes": [
     "Dans la fiche du point (**Connexions → Points**), cochez **Autoriser la modification de cette valeur (consigne) depuis Larka** et fixez le **minimum** et le **maximum permis**.",
     "Dans **Supervision**, cliquez sur à côté de la valeur (ou **Modifier la consigne** sous sa courbe), puis saisissez la nouvelle valeur.",
     "Confirmez votre identité (mot de passe, ou connexion Microsoft ou Google récente) : elle vaut 5 minutes pour les écritures suivantes."
    ],
    "note": "Hors de la plage, l'écriture est refusée. Chaque tentative, réussie ou non, est inscrite au journal de sécurité avec le compte, la valeur précédente et le résultat (onglet **Journal** de Connexions).",
    "bouton": "Ouvrir la supervision"
   },
   "en": {
    "titre": "Change a setpoint (thermostat, BMS)",
    "mots": "setpoint change write temperature thermostat heating air conditioning adjust command bms writing identity password",
    "texte": "Only points declared **writable**, with an allowed range, can be changed from Larka. Reading never asks for identity; writing always does.",
    "etapes": [
     "In the point's settings (**Connections → Points**), tick **Allow this value (setpoint) to be changed from Larka** and set the **minimum** and **maximum allowed**.",
     "In **Supervision**, click next to the value (or **Change the setpoint** under its chart), then enter the new value.",
     "Confirm your identity (password, or a recent Microsoft or Google sign-in): it lasts 5 minutes for the following writes."
    ],
    "note": "Outside the range, the write is refused. Every attempt, successful or not, is recorded in the security log with the account, the previous value and the result (**Audit log** tab of Connections).",
    "bouton": "Open supervision"
   }
  },
  {
   "id": "connect-bornes",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Raccorder des bornes de recharge (OCPP)",
    "mots": "borne bornes recharge véhicule électrique ve irve ocpp chargebox badge rfid session charge démarrer arrêter déverrouiller câble",
    "texte": "Les bornes OCPP 1.6 (JSON) se connectent d'elles-mêmes au **service OCPP** de Larka, que l'administrateur du serveur active (Configuration → Serveur → Larka Connect).",
    "etapes": [
     "Onglet **Bornes de recharge** → **＋ Déclarer une borne** : son identifiant (ChargeBoxId), un mot de passe (recommandé) et les badges acceptés.",
     "Saisissez dans la borne l'**adresse** que Larka affiche alors ; ses points (état, énergie, puissance, défaut) se créent à sa première connexion.",
     "Les **sessions de recharge** s'affichent dans l'onglet ; **Commandes** démarre ou arrête une charge, déverrouille un câble, met la borne hors service ou la redémarre (identité confirmée)."
    ],
    "note": "Le pare-feu doit laisser les bornes joindre le port du service OCPP (9000 par défaut, ou Nginx en wss://). Une borne non déclarée qui appelle est refusée et signalée dans l'onglet, avec un lien pour la déclarer.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Connect EV chargers (OCPP)",
    "mots": "charger chargers charging electric vehicle ev evse ocpp chargebox badge rfid session charge start stop unlock cable",
    "texte": "OCPP 1.6 (JSON) chargers connect by themselves to Larka's **OCPP service**, which the server administrator enables (Settings → Server → Larka Connect).",
    "etapes": [
     "**EV chargers** tab → **＋ Declare a charger**: its identifier (ChargeBoxId), a password (recommended) and the accepted badges.",
     "Enter in the charger the **address** that Larka then shows; its points (state, energy, power, fault) are created on its first connection.",
     "**Charging sessions** appear in the tab; **Commands** starts or stops a charge, unlocks a cable, puts the charger out of service or restarts it (identity confirmed)."
    ],
    "note": "The firewall must let the chargers reach the OCPP service port (9000 by default, or Nginx over wss://). An undeclared charger that calls is refused and reported in the tab, with a link to declare it.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "connect-demarrer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Bien démarrer avec Larka Connect (guide pas à pas)",
    "mots": "démarrer commencer premiers pas guide parcours étapes larka connect bâtiments connectés gtb raccordement par où commencer",
    "texte": "En tête de **Bâtiments → Connexions**, le **Guide de démarrage** liste les étapes du raccordement et les coche d'après ce qui est réellement fait. Son bouton bleu mène toujours à la prochaine étape.",
    "etapes": [
     "**Créez vos bâtiments** (menu Plans), puis indiquez le **réseau** de chacun dans l'onglet **Bâtiments**.",
     "**Détectez** les équipements : Larka les range d'office dans leur bâtiment.",
     "**Réglez** les connexions en défaut avec **Tester** (identifiants, pare-feu).",
     "**Choisissez les points** à suivre, puis posez des **règles d'alarme** sur ceux qui comptent."
    ],
    "note": "Chaque écran commence par un encadré **À quoi sert cet écran ?** (repliable) et chaque réglage porte une bulle **?** qui explique son effet. Le guide se masque, et se rouvre par le bouton **Guide**.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Get started with Larka Connect (step-by-step guide)",
    "mots": "start begin first steps guide path steps larka connect connected buildings bms connection where to start",
    "texte": "At the top of **Buildings → Connections**, the **Getting started guide** lists the connection steps and ticks them according to what is actually done. Its blue button always leads to the next step.",
    "etapes": [
     "**Create your buildings** (Plans menu), then give each one's **network** in the **Buildings** tab.",
     "**Detect** the devices: Larka files them straight into their building.",
     "**Fix** the faulty connections with **Test** (credentials, firewall).",
     "**Choose the points** to follow, then set **alarm rules** on those that matter."
    ],
    "note": "Each screen starts with a **What is this screen for?** box (foldable) and each setting has a **?** bubble explaining its effect. The guide can be hidden, and reopens from the **Guide** button.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "connect-batiments",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Indiquer le réseau de chaque bâtiment (détection par bâtiment)",
    "mots": "réseau adresse ip plage sous-réseau bâtiment ranger automatiquement mots-clés sigle détection par bâtiment vlan informatique",
    "texte": "Onglet **Bâtiments** de **Connexions** : pour chaque bâtiment, les **adresses IP** de ses équipements techniques et des **mots-clés**. Larka s'en sert pour détecter bâtiment par bâtiment et ranger tout seul ce qu'il trouve.",
    "etapes": [
     "**** sur la ligne du bâtiment : saisissez son réseau — une plage (`192.168.21.0/24`), un intervalle (`192.168.21.10-60`) ou des adresses, séparés par des virgules.",
     "Ajoutez si besoin des **mots-clés** : sigle, ancien nom, repère des bornes (`HDV, mairie`).",
     "**** sur la ligne du bâtiment détecte dans ce bâtiment ; **Détecter dans tous les bâtiments** parcourt tous les réseaux et range chaque équipement dans le sien.",
     "**Voir et ranger** (bandeau en haut de l'onglet) range d'un coup les connexions qui n'ont pas encore de bâtiment."
    ],
    "note": "Larka apprend aussi tout seul : un sous-réseau dont toutes les connexions déjà rangées vont au même bâtiment apparaît **appris** (« garder » l'inscrit). Ordre de priorité : réseau déclaré, réseau appris, puis nom de l'équipement (nom annoncé, emplacement BACnet, identifiant de borne, dossier d'exports). Dans le doute, Larka ne range rien.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Give each building's network (detection by building)",
    "mots": "network ip address range subnet building file automatically keywords acronym detection by building vlan it",
    "texte": "**Buildings** tab of **Connections**: for each building, the **IP addresses** of its technical devices and **keywords**. Larka uses them to detect building by building and to file what it finds by itself.",
    "etapes": [
     "**** on the building's line: enter its network — a range (`192.168.21.0/24`), an interval (`192.168.21.10-60`) or addresses, separated by commas.",
     "Add **keywords** if needed: acronym, former name, charger labels (`TH, town hall`).",
     "**** on the building's line detects in that building; **Detect in all buildings** scans every network and files each device in its own.",
     "**Review and file** (banner at the top of the tab) files at once the connections that do not have a building yet."
    ],
    "note": "Larka also learns by itself: a subnet whose already filed connections all go to the same building shows as **learned** (“keep” saves it). Priority order: declared network, learned network, then the device's name (announced name, BACnet location, charger identifier, export folder). When in doubt, Larka files nothing.",
    "bouton": "Open connections"
   }
  },
 {
  "id": "connect-hotspot",
  "pour": [
   "gestionnaire"
  ],
  "cat": "connect",
  "page": "connect_config",
  "fr": {
   "titre": "Relever un appareil hors réseau (hotspot Wi-Fi ou câble direct)",
   "mots": "hors réseau hotspot wi-fi wifi ssid câble rj45 direct ethernet hors ligne autonome pc releveur collecteur portable enregistreur station météo centrale double-clic jeton configuration alternative détecter détection protocole inconnu sans documentation points trouvés",
   "texte": "Un appareil que le serveur ne joint pas — il diffuse **son propre Wi-Fi**, ou l'on s'y branche **par câble RJ45** — se relève par un **PC releveur** : il relève l'appareil, garde les valeurs, et les envoie à Larka dès qu'il retrouve le réseau, avec leur heure réelle.",
   "etapes": [
    "Onglet **Hors réseau** de **Connexions** : **＋ Déclarer un appareil hors réseau**, puis « Il diffuse son propre Wi-Fi » (nom du Wi-Fi et clé) ou « On branche un PC dessus, par câble », et le protocole. Une connexion existante y passe aussi : ****, puis « Comment Larka joint-il l'appareil ? ».",
    "Ajoutez ses **points** comme d'habitude (registres Modbus, objets BACnet…) d'après sa documentation. Sans documentation, choisissez le protocole **À détecter** : au relevé suivant, le PC regarde ce que l'appareil expose (lecture seule) ; **** dans la liste propose alors les réglages et tous les points lus — cochez ceux à suivre, **Appliquer**.",
    "Même onglet : **＋ Ajouter un PC releveur**, puis **** pour télécharger son collecteur, déjà réglé (votre mot de passe est demandé : il contient les clés Wi-Fi).",
    "Sur ce PC, une seule fois : décompressez le zip dans Documents, double-cliquez **releveur.cmd** (premier réglage). Ensuite, pour relever, double-cliquez à nouveau **releveur.cmd** (câble : une fois branché) : rien ne se lance tout seul."
   ],
   "note": "Câble direct : le PC doit avoir une adresse dans le réseau de l'appareil. Une fois, sur la carte Ethernet du PC (droits d'administrateur) : Protocole Internet version 4 → onglet « Configuration alternative » → une adresse du même réseau (Larka la propose dans la fiche de l'appareil), masque 255.255.255.0, passerelle vide. Sur le réseau habituel rien ne change ; branché sur l'appareil, le PC garde Internet par le Wi-Fi. Hotspot : un PC à une seule carte Wi-Fi quitte son réseau environ une minute pendant le relevé, puis le reprend. Pour envoyer chaque relevé au module Énergie : relevé automatique de fréquence « À chaque relevé du PC releveur ».",
   "bouton": "Ouvrir les connexions"
  },
  "en": {
   "titre": "Read an off-network device (Wi-Fi hotspot or direct cable)",
   "mots": "off network hotspot wi-fi wifi ssid cable rj45 direct ethernet offline standalone reader pc collector laptop logger weather station meter double-click token alternate configuration",
   "texte": "A device the server cannot reach — it broadcasts **its own Wi-Fi**, or you plug into it **with an RJ45 cable** — is read by a **reader PC**: it reads the device, keeps the values and sends them to Larka as soon as it is back on the network, with their real time.",
   "etapes": [
    "**Off the network** tab of **Connections**: **＋ Declare an off-network device**, then “It broadcasts its own Wi-Fi” (Wi-Fi name and key) or “A PC is plugged into it by cable”, and the protocol. An existing connection can switch too: ****, then “How does Larka reach the device?”.",
    "Add its **points** as usual (Modbus registers, BACnet objects…) from its documentation. Without documentation, choose the **To detect** protocol: at the next reading, the PC looks at what the device exposes (read only); **** in the list then suggests the settings and every value read — tick the ones to monitor, **Apply**.",
    "Same tab: **＋ Add a reader PC**, then **** to download its collector, already set up (your password is asked: it contains the Wi-Fi keys).",
    "On that PC, once: unzip the file into Documents, double-click **releveur.cmd** (first setup). Then, to read, double-click **releveur.cmd** again (cable: once plugged in): nothing starts by itself."
   ],
   "note": "Direct cable: the PC needs an address in the device's network. Once, on the PC's Ethernet adapter (administrator rights): Internet Protocol version 4 → “Alternate Configuration” tab → an address in the same network (Larka suggests it in the device's form), mask 255.255.255.0, empty gateway. On the usual network nothing changes; plugged into the device, the PC keeps Internet over Wi-Fi. Hotspot: a PC with a single Wi-Fi card leaves its network for about a minute during the reading, then gets it back. To send each reading to the Energy module: automatic reading with the “At each reader PC reading” frequency.",
   "bouton": "Open connections"
  }
 },
  {
   "id": "connect-points",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Choisir les valeurs à suivre (points)",
    "mots": "point points valeur mesure index température état découvrir explorer registre modbus coefficient échelle bande morte historique rattacher équipement inventaire",
    "texte": "Un **point** est une valeur lue sur une connexion : c'est lui qui s'affiche dans la supervision, s'historise et déclenche les alarmes.",
    "etapes": [
     "Onglet **Points** : filtrez sur une connexion puis **Découvrir**, et cochez les valeurs utiles (BACnet, MQTT, API, CSV).",
     "Modbus : **＋ Nouveau point**, avec le registre indiqué par la documentation de l'équipement ; **Explorer** lit une plage de registres pour vérifier.",
     "Réglez la **nature** (mesure, compteur, état), le **coefficient** (0,1 pour des dixièmes) et la **bande morte** (variation ignorée dans l'historique).",
     "Rattachez le point à un **équipement de l'inventaire** : une alarme créera sa demande d'intervention sur cet équipement."
    ],
    "note": "Gardez peu de points, mais les bons : la température de départ et le défaut brûleur valent mieux que deux cents variables internes de l'automate. La bulle **?** de chaque réglage explique son effet.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Choose the values to follow (points)",
    "mots": "point points value measurement index temperature state discover explore register modbus factor scaling deadband history link equipment inventory",
    "texte": "A **point** is a value read on a connection: it is what shows in supervision, is recorded and triggers alarms.",
    "etapes": [
     "**Points** tab: filter on a connection then **Discover**, and tick the useful values (BACnet, MQTT, API, CSV).",
     "Modbus: **＋ New point**, with the register given by the device's documentation; **Explore** reads a range of registers to check.",
     "Set the **nature** (measurement, meter, state), the **factor** (0.1 for tenths) and the **deadband** (change ignored in the history).",
     "Link the point to an **inventory equipment**: an alarm will create its work request on that equipment."
    ],
    "note": "Keep few points, but the right ones: flow temperature and burner fault are worth more than the controller's two hundred internal variables. Each setting's **?** bubble explains its effect.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "connect-regles",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Créer une règle d'alarme sur une valeur",
    "mots": "règle alarme seuil dépassement hystérésis délai gravité critique notification demande intervention automatique capteur muet absence palier heures de marche",
    "texte": "Une **règle** surveille un point et ouvre une alarme quand sa condition est remplie ; elle peut prévenir les gestionnaires et créer d'elle-même la demande d'intervention.",
    "etapes": [
     "Onglet **Points**, colonne **** du point → **＋ Ajouter une règle**.",
     "Choisissez la condition : au-dessus ou au-dessous d'un seuil, hors d'une plage, égal à un état (défaut = 1), sans valeur depuis N minutes, ou palier franchi (heures de marche).",
     "Fixez **Pendant au moins** (un pic passager ne déclenche rien) et la **gravité** (critique : demande urgente).",
     "Cochez **Créer une demande d'intervention** et **Prévenir les gestionnaires** selon le besoin."
    ],
    "note": "Pour un seuil haut, **Retour à la normale sous** évite que l'alarme s'ouvre et se ferme sans cesse autour du seuil. Toutes les règles se relisent dans l'onglet **Règles d'alarme**.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Create an alarm rule on a value",
    "mots": "rule alarm threshold exceeded hysteresis delay severity critical notification work request automatic silent sensor absence step running hours",
    "texte": "A **rule** watches a point and opens an alarm when its condition is met; it can notify the managers and create the work request by itself.",
    "etapes": [
     "**Points** tab, the point's **** column → **＋ Add a rule**.",
     "Choose the condition: above or below a threshold, outside a range, equal to a state (fault = 1), no value for N minutes, or step crossed (running hours).",
     "Set **For at least** (a passing spike triggers nothing) and the **severity** (critical: urgent request).",
     "Tick **Create a work request** and **Notify managers** as needed."
    ],
    "note": "For a high threshold, **Back to normal below** stops the alarm from opening and closing over and over around the threshold. All rules can be reviewed in the **Alarm rules** tab.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "connect-journal",
   "pour": [
    "gestionnaire"
   ],
   "cat": "connect",
   "page": "connect_config",
   "fr": {
    "titre": "Retrouver qui a modifié une consigne (journal)",
    "mots": "journal historique trace écriture commande consigne qui a modifié audit fichiers csv importés lus",
    "texte": "Onglet **Journal** de **Connexions** : chaque écriture de consigne et chaque commande de borne, avec le compte, l'heure, la valeur envoyée et la réponse de l'équipement.",
    "etapes": [
     "Le tableau liste les écritures et commandes, les plus récentes en tête, rangées par bâtiment.",
     "Pour une connexion CSV, la liste des **fichiers traités** (importé, incomplet, doublon, rejeté) dit si un export a bien été lu."
    ],
    "note": "Chaque écriture est aussi inscrite au journal de sécurité du serveur, avec la valeur précédente.",
    "bouton": "Ouvrir les connexions"
   },
   "en": {
    "titre": "Find who changed a setpoint (log)",
    "mots": "log history trace write command setpoint who changed audit csv files imported read",
    "texte": "**Log** tab of **Connections**: every setpoint write and every charger command, with the account, the time, the value sent and the device's answer.",
    "etapes": [
     "The table lists writes and commands, most recent first, grouped by building.",
     "For a CSV connection, the list of **processed files** (imported, incomplete, duplicate, rejected) tells whether an export was read."
    ],
    "note": "Each write is also recorded in the server's security log, with the previous value.",
    "bouton": "Open connections"
   }
  },
  {
   "id": "urgences-gerer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "modules",
   "page": "configuration",
   "fr": {
    "titre": "Publier les procédures d'urgence",
    "mots": "urgence urgences procédure publier contacts consignes visible configurer",
    "texte": "Les procédures se préparent dans **Configuration → Urgences** : situations, procédure à suivre, contacts, photos et vidéos.",
    "etapes": [
     "Rédigez chaque situation et ses contacts (appel ou e-mail en un clic).",
     "Choisissez **qui voit l'onglet** : tous les rôles, ou seulement les gestionnaires.",
     "Vérifiez le rendu dans **Procédures d'urgence**."
    ],
    "note": "Gestionnaires et admins voient toujours l'onglet.",
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Publish the emergency procedures",
    "mots": "emergency procedure publish contacts instructions visible configure",
    "texte": "Procedures are prepared in **Settings → Emergencies**: situations, procedure to follow, contacts, photos and videos.",
    "etapes": [
     "Write each situation and its contacts (call or email in one click).",
     "Choose **who sees the tab**: every role, or managers only.",
     "Check the result in **Emergency procedures**."
    ],
    "note": "Managers and admins always see the tab.",
    "bouton": "Open settings"
   }
  },
  {
   "id": "archives",
   "pour": [
    "gestionnaire"
   ],
   "cat": "modules",
   "page": "archives",
   "fr": {
    "titre": "Gérer les archives (boîtes, dossiers, bordereaux)",
    "mots": "archives boîte dossier bordereau versement élimination classement recherche étiquette",
    "texte": "Le module **Archives** gère l'archivage physique.",
    "etapes": [
     "**+ Nouvelle boîte**, puis **+ Nouveau dossier** pour décrire et ranger.",
     "**+ Bordereau de versement** ou **+ Bordereau d'élimination** pour tracer les mouvements.",
     "**Recherche avancée** retrouve un dossier ; **Imprimer la fiche** édite la fiche d'une boîte.",
     "Les demandes d'archivage et de désarchivage des agents se traitent avec **Traiter**."
    ],
    "bouton": "Ouvrir les archives"
   },
   "en": {
    "titre": "Manage the archives (boxes, files, transfer sheets)",
    "mots": "archives box file transfer destruction sheet filing search label",
    "texte": "The **Archives** module manages physical archiving.",
    "etapes": [
     "**+ New box**, then **+ New file** to describe and store.",
     "**+ Transfer sheet** or **+ Destruction sheet** to record movements.",
     "**Advanced search** finds a file; **Print the sheet** prints a box's sheet.",
     "Agents' archiving and retrieval requests are dealt with using **Handle**."
    ],
    "bouton": "Open archives"
   }
  },
  {
   "id": "legifrance",
   "pour": [
    "visionneur",
    "gestionnaire"
   ],
   "cat": "modules",
   "page": "legifrance",
   "fr": {
    "titre": "Rechercher un texte juridique (Légifrance)",
    "mots": "légifrance loi décret code juridique texte réglementation",
    "texte": "Si votre organisation l'a activé, **Légifrance** recherche et affiche les textes juridiques : lois, décrets, codes.",
    "etapes": [
     "Saisissez des mots-clés ou une référence, puis ouvrez le texte."
    ],
    "bouton": "Ouvrir Légifrance"
   },
   "en": {
    "titre": "Search for a legal text (Légifrance)",
    "mots": "legifrance law decree code legal text regulation",
    "texte": "If your organisation has enabled it, **Légifrance** searches and shows French legal texts: laws, decrees, codes.",
    "etapes": [
     "Enter keywords or a reference, then open the text."
    ],
    "bouton": "Open Légifrance"
   }
  },
  {
   "id": "chorus",
   "pour": [
    "gestionnaire"
   ],
   "cat": "modules",
   "page": "chorus",
   "fr": {
    "titre": "Suivre les factures publiques (Chorus Pro)",
    "mots": "chorus pro facture facturation publique statut",
    "texte": "Si votre organisation l'a activé, **Chorus Pro** recherche les factures de la facturation publique et indique leur statut.",
    "bouton": "Ouvrir Chorus Pro"
   },
   "en": {
    "titre": "Track public invoices (Chorus Pro)",
    "mots": "chorus pro invoice public invoicing status",
    "texte": "If your organisation has enabled it, **Chorus Pro** searches invoices on the French public invoicing platform and shows their status.",
    "bouton": "Open Chorus Pro"
   }
  },
  {
   "id": "comptes",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "fr": {
    "titre": "Créer un compte et choisir son rôle",
    "mots": "compte utilisateur créer ajouter rôle droits gestionnaire visionneur demandeur désactiver accès",
    "texte": "**Configuration → Comptes** gère les comptes de l'organisation.",
    "etapes": [
     "**+ Nouveau** : nom, prénom, login, e-mail, **rôle**, type de compte (Local, Microsoft ou Google), statut.",
     "Rôles : **Gestionnaire** (crée et modifie), **Visionneur** (consulte), **Demandeur** (fait des demandes).",
     "Pour retirer un accès sans perdre l'historique : statut **Désactivé**."
    ],
    "note": "Donnez à chacun le rôle le plus juste. Un changement de rôle s'applique à la prochaine connexion.",
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Create an account and choose its role",
    "mots": "account user create add role rights manager viewer requester disable access",
    "texte": "**Settings → Accounts** manages the organisation's accounts.",
    "etapes": [
     "**+ New**: last name, first name, login, email, **role**, account type (Local, Microsoft or Google), status.",
     "Roles: **Manager** (creates and edits), **Viewer** (views), **Requester** (makes requests).",
     "To remove access without losing the history: **Disabled** status."
    ],
    "note": "Give everyone the narrowest suitable role. A role change applies at the next sign-in.",
    "bouton": "Open settings"
   }
  },
  {
   "id": "consultation-ouvrir",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "fr": {
    "titre": "Ouvrir des rubriques en lecture seule à un demandeur",
    "mots": "consultation lecture seule onglet ouvrir demandeur accès voir droits",
    "texte": "Un demandeur peut consulter, sans rien modifier, certaines rubriques : tableau de bord, biens, stock, interventions, énergie…",
    "etapes": [
     "**Configuration → Comptes** : ouvrez la fiche du demandeur.",
     "Section **Onglets consultables par cette personne** : cochez les rubriques.",
     "Elles apparaissent dans sa section **Consultation**, marquées."
    ],
    "note": "Le réglage est propre à chaque compte : il n'existe pas de réglage collectif.",
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Open sections read-only to a requester",
    "mots": "view read-only tab open requester access rights",
    "texte": "A requester can view certain sections without changing anything: dashboard, assets, stock, work orders, energy…",
    "etapes": [
     "**Settings → Accounts**: open the requester's record.",
     "**Tabs this person can view** section: tick the sections.",
     "They appear in their **View** section, marked."
    ],
    "note": "The setting belongs to each account: there is no collective setting.",
    "bouton": "Open settings"
   }
  },
  {
   "id": "listes",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "fr": {
    "titre": "Adapter les listes déroulantes",
    "mots": "listes déroulantes valeurs catégories familles statuts types bâtiments champs obligatoires paramétrer",
    "texte": "**Configuration → Listes** règle les valeurs proposées dans les formulaires : familles, statuts, types, bâtiments, catégories de demandes…",
    "etapes": [
     "Choisissez le sous-onglet (Biens, Équipements, Interventions, Contrats, Stock, Demandes, Plans).",
     "Ajoutez, modifiez ou désactivez une valeur : elle n'est jamais supprimée, les fiches qui l'utilisent la gardent.",
     "Indiquez si le champ est obligatoire et si la saisie libre est permise."
    ],
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Adapt the drop-down lists",
    "mots": "drop-down lists values categories families statuses types buildings required fields configure",
    "texte": "**Settings → Lists** sets the values offered in forms: families, statuses, types, buildings, request categories…",
    "etapes": [
     "Choose the sub-tab (Assets, Equipment, Work orders, Contracts, Stock, Requests, Floor plans).",
     "Add, edit or disable a value: it is never deleted, and records using it keep it.",
     "Say whether the field is required and whether free entry is allowed."
    ],
    "bouton": "Open settings"
   }
  },
  {
   "id": "notes-info",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "fr": {
    "titre": "Afficher un message aux demandeurs",
    "mots": "note info message bandeau banderole annonce information demandeurs travaux coupure",
    "texte": "**Configuration → Notes info** publie des messages en banderole sur l'écran des demandeurs : travaux, coupure, rappel…",
    "etapes": [
     "Rédigez le message et activez-le ; désactivez-le quand il n'a plus lieu d'être."
    ],
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Show a message to requesters",
    "mots": "information note message banner announcement requesters works outage",
    "texte": "**Settings → Information notes** publishes scrolling messages on requesters' screens: works, outage, reminder…",
    "etapes": [
     "Write the message and enable it; disable it when it no longer applies."
    ],
    "bouton": "Open settings"
   }
  },
  {
   "id": "sauvegardes",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "si": "mono",
   "fr": {
    "titre": "Régler les sauvegardes automatiques",
    "mots": "sauvegarde sauvegardes backup automatique restaurer copie sécurité données planifier",
    "texte": "**Configuration → Serveur → Sauvegardes automatiques** : Larka sauvegarde la base et les fichiers à l'intervalle choisi.",
    "etapes": [
     "**Activez**, puis choisissez l'**intervalle** en minutes (360 = toutes les 6 heures, 1440 = une fois par jour).",
     "**Sauvegardes conservées** : au-delà, les plus anciennes sont supprimées, base et fichiers ensemble.",
     "**Dossier** : data/backups par défaut, ou un chemin absolu vers un disque réseau ; compression conseillée.",
     "L'état indique la dernière sauvegarde, la prochaine et le dernier échec ; **Sauvegarder maintenant** en fait une tout de suite."
    ],
    "note": "Copiez régulièrement les sauvegardes hors du serveur, ainsi que les fichiers .env et config.key.",
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Set up automatic backups",
    "mots": "backup backups automatic restore copy safety data schedule",
    "texte": "**Settings → Server → Automatic backups**: Larka backs up the database and files at the chosen interval.",
    "etapes": [
     "**Enable**, then choose the **interval** in minutes (360 = every 6 hours, 1440 = once a day).",
     "**Backups kept**: beyond that, the oldest are deleted, database and files together.",
     "**Folder**: data/backups by default, or an absolute path to a network drive; compression recommended.",
     "The status shows the last backup, the next one and the last failure; **Back up now** makes one straight away."
    ],
    "note": "Regularly copy the backups off the server, together with the .env and config.key files.",
    "bouton": "Open settings"
   }
  },
  {
   "id": "sauvegardes-multi",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "si": "multi",
   "fr": {
    "titre": "Les sauvegardes de mon organisation",
    "mots": "sauvegarde sauvegardes backup automatique restaurer",
    "texte": "Votre organisation partage le serveur avec d'autres : ses sauvegardes sont réglées et surveillées par le Super Admin de la plateforme. Adressez-vous à lui pour une restauration."
   },
   "en": {
    "titre": "My organisation's backups",
    "mots": "backup backups automatic restore",
    "texte": "Your organisation shares the server with others: its backups are set and monitored by the platform's Super Admin. Contact them for a restore."
   }
  },
  {
   "id": "mises-a-jour",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "si": "mono",
   "fr": {
    "titre": "Installer une mise à jour de Larka",
    "mots": "mise à jour version nouvelle installer upgrade bandeau disponible",
    "texte": "Quand une nouvelle version est publiée, un bandeau l'annonce.",
    "etapes": [
     "**Voir la mise à jour** : nouveautés et contrôles (empreinte, signature, compatibilité du serveur).",
     "Cochez la confirmation, puis **Installer maintenant** : rien ne s'installe sans vous.",
     "À tout moment : **Configuration → Serveur → Version et mises à jour** → **Vérifier maintenant**."
    ],
    "note": "Les données ne sont pas touchées ; une sauvegarde est faite juste avant, et **Historique / restaurer** permet de revenir en arrière.",
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Install a Larka update",
    "mots": "update version new install upgrade banner available",
    "texte": "When a new version is published, a banner announces it.",
    "etapes": [
     "**View the update**: what's new and the checks (fingerprint, signature, server compatibility).",
     "Tick the confirmation, then **Install now**: nothing installs without you.",
     "At any time: **Settings → Server → Version and updates** → **Check now**."
    ],
    "note": "Your data is not touched; a backup is made just before, and **History / restore** lets you go back.",
    "bouton": "Open settings"
   }
  },
  {
   "id": "assistant-config",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "configuration",
   "si": "mono",
   "fr": {
    "titre": "Configurer l'assistant IA",
    "mots": "assistant ia configurer fournisseur modèle ollama lm studio anthropic openai mistral gemini clé",
    "texte": "**Configuration → Serveur → Assistant IA** : fournisseur local (Ollama, LM Studio) ou distant (Anthropic, OpenAI, Mistral, Google Gemini), modèle et clé d'accès.",
    "note": "En mode distant, les données envoyées au modèle quittent votre serveur : préférez le mode local pour des données sensibles.",
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Configure the AI assistant",
    "mots": "assistant ai configure provider model ollama lm studio anthropic openai mistral gemini key",
    "texte": "**Settings → Server → AI assistant**: local provider (Ollama, LM Studio) or remote one (Anthropic, OpenAI, Mistral, Google Gemini), model and access key.",
    "note": "In remote mode, data sent to the model leaves your server: prefer local mode for sensitive data.",
    "bouton": "Open settings"
   }
  },
  {
   "id": "extension-installer",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "page": "extensions",
   "fr": {
    "titre": "Installer un module complémentaire",
    "mots": "module extension complémentaire installer paquet larka ajouter fonctionnalité permissions",
    "texte": "Les modules (fichiers **.larka**) ajoutent des écrans, des tuiles ou des types de demande, sans exécuter de code.",
    "etapes": [
     "**Extensions** : la première fois, activez les modules complémentaires.",
     "Déposez le fichier .larka : l'écran dit **ce que le module va faire** et **ce à quoi il aura accès**.",
     "Cochez chaque permission (aucune n'est pré-cochée), choisissez **qui verra ce module**, puis **Installer et activer**."
    ],
    "note": "Un module se désactive ou se désinstalle à tout moment, sans perte de ses données.",
    "bouton": "Ouvrir les extensions"
   },
   "en": {
    "titre": "Install an add-on module",
    "mots": "module extension add-on install package larka add feature permissions",
    "texte": "Modules (**.larka** files) add screens, tiles or request types, without running any code.",
    "etapes": [
     "**Extensions**: the first time, enable add-on modules.",
     "Drop the .larka file: the screen shows **what this module will do** and **what it will have access to**.",
     "Tick each permission (none is pre-ticked), choose **who will see this module**, then **Install and enable**."
    ],
    "note": "A module can be disabled or uninstalled at any time, without losing its data.",
    "bouton": "Open extensions"
   }
  },
  {
   "id": "rgpd-orga",
   "pour": [
    "gestionnaire"
   ],
   "cat": "admin",
   "action": "rgpd",
   "fr": {
    "titre": "La politique de protection des données de l'organisation",
    "mots": "rgpd données personnelles politique dpo responsable traitement personnaliser conformité",
    "texte": "La politique est commune à toutes les organisations et s'adapte d'elle-même à l'installation (assistant IA, connexion Microsoft ou Google, notifications, durée des journaux).",
    "etapes": [
     "Pour la compléter (responsable de traitement, DPO, contact) ou réécrire une section : console Super Admin → **Conformité RGPD**, ou demandez-le à son administrateur.",
     "Toute personnalisation est signalée aux utilisateurs."
    ],
    "note": "Le modèle est indicatif : faites valider la base légale et les durées de conservation par votre DPO.",
    "bouton": "Lire la politique"
   },
   "en": {
    "titre": "The organisation's data protection policy",
    "mots": "gdpr personal data policy dpo controller customise compliance",
    "texte": "The policy is shared by all organisations and adapts itself to the installation (AI assistant, Microsoft or Google sign-in, notifications, log retention).",
    "etapes": [
     "To complete it (data controller, DPO, contact) or rewrite a section: Super Admin console → **GDPR compliance**, or ask its administrator.",
     "Any customisation is shown to users."
    ],
    "note": "The model is a guide: have the legal basis and retention periods validated by your DPO.",
    "bouton": "Read the policy"
   }
  },
  {
   "id": "faq-droits",
   "pour": [
    "gestionnaire"
   ],
   "cat": "faq",
   "page": "configuration",
   "fr": {
    "titre": "Un utilisateur n'a pas les bons droits",
    "mots": "droits rôle permission accès utilisateur refusé interdit",
    "texte": "Le rôle détermine ce que chacun voit et fait.",
    "etapes": [
     "**Configuration → Comptes** : vérifiez son rôle et son statut (actif).",
     "Pour un demandeur, cochez au besoin des **onglets consultables**.",
     "Le changement s'applique à sa prochaine connexion."
    ],
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "A user does not have the right permissions",
    "mots": "rights role permission access user refused forbidden",
    "texte": "The role decides what each person sees and does.",
    "etapes": [
     "**Settings → Accounts**: check their role and status (active).",
     "For a requester, tick **tabs this person can view** if needed.",
     "The change applies at their next sign-in."
    ],
    "bouton": "Open settings"
   }
  },
  {
   "id": "faq-sauvegardes",
   "pour": [
    "gestionnaire"
   ],
   "cat": "faq",
   "page": "configuration",
   "si": "mono",
   "fr": {
    "titre": "Les sauvegardes automatiques ne partent pas",
    "mots": "sauvegarde échec planificateur inactif ne marche pas erreur backup",
    "texte": "**Configuration → Serveur → Sauvegardes automatiques** : l'état dit si le planificateur tourne et donne la dernière erreur.",
    "etapes": [
     "« Planificateur inactif » : Larka n'est pas lancé par un moyen qui déclenche les sauvegardes. Voyez la fiche **Démarrer, arrêter Larka** de cette aide.",
     "Une erreur d'écriture : vérifiez le dossier de sauvegarde et la place disque."
    ],
    "bouton": "Ouvrir la configuration"
   },
   "en": {
    "titre": "Automatic backups do not run",
    "mots": "backup failure scheduler inactive not working error",
    "texte": "**Settings → Server → Automatic backups**: the status says whether the scheduler runs and gives the last error.",
    "etapes": [
     "“Scheduler inactive”: Larka is not started in a way that triggers backups. See the **Start, stop Larka** card in this help.",
     "A write error: check the backup folder and disk space."
    ],
    "bouton": "Open settings"
   }
  },
  {
   "id": "faq-maj-refusee",
   "pour": [
    "gestionnaire",
    "sa"
   ],
   "cat": "faq",
   "fr": {
    "titre": "Une mise à jour est refusée : version incompatible",
    "mots": "mise à jour refusée incompatible prérequis php extension postgresql sqlite erreur",
    "texte": "La nouvelle version exige un composant plus récent (PHP, extension, SQLite, PostgreSQL) : le message dit lequel.",
    "etapes": [
     "Faites mettre à jour ce composant sur le serveur, puis relancez l'installation de la mise à jour."
    ]
   },
   "en": {
    "titre": "An update is refused: incompatible version",
    "mots": "update refused incompatible prerequisites php extension postgresql sqlite error",
    "texte": "The new version needs a newer component (PHP, extension, SQLite, PostgreSQL): the message says which.",
    "etapes": [
     "Have that component updated on the server, then start the update installation again."
    ]
   }
  },
  {
   "id": "sa-tenant-creer",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Créer une organisation (tenant)",
    "mots": "tenant organisation client créer nouveau ajouter provisionner domaine base",
    "texte": "Un tenant est une organisation cloisonnée, avec sa propre base de données.",
    "etapes": [
     "**Multi-Tenant** → **+ Nouveau tenant**.",
     "**Clé unique** (identifiant technique), **nom affiché**, couleur.",
     "**Domaines web** (adresses par lesquelles on joint l'organisation) et **domaines e-mail** — au moins un ; « * » accepte toutes les adresses.",
     "Base de données : moteur (PostgreSQL conseillé), hôte, port, nom, identifiants ; **Tester la connexion**.",
     "Créez le **compte gestionnaire** initial, puis validez : la base est créée et initialisée."
    ],
    "note": "Si la création échoue, le message dit quoi corriger : droit de créer la base, mot de passe refusé, domaine manquant…",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Create an organisation (tenant)",
    "mots": "tenant organisation client create new add provision domain database",
    "texte": "A tenant is a walled-off organisation with its own database.",
    "etapes": [
     "**Multi-tenant** → **+ New tenant**.",
     "**Unique key** (technical identifier), **display name**, colour.",
     "**Web domains** (addresses used to reach the organisation) and **email domains** — at least one; “*” accepts every address.",
     "Database: engine (PostgreSQL recommended), host, port, name, credentials; **Test the connection**.",
     "Create the initial **manager account**, then confirm: the database is created and initialised."
    ],
    "note": "If creation fails, the message says what to fix: right to create the database, password refused, missing domain…",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-tenant-modifier",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Modifier ou désactiver une organisation",
    "mots": "tenant organisation modifier désactiver suspendre bloquer domaines base",
    "texte": "Sur la carte du tenant, **** ouvre sa fiche : nom, domaines, base de données, sauvegardes.",
    "etapes": [
     "Décochez **Actif** pour bloquer la connexion de ses utilisateurs sans rien supprimer.",
     "Un mot de passe de base laissé vide reste inchangé."
    ],
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Edit or disable an organisation",
    "mots": "tenant organisation edit disable suspend block domains database",
    "texte": "On the tenant card, **** opens its record: name, domains, database, backups.",
    "etapes": [
     "Untick **Active** to block its users from signing in, without deleting anything.",
     "A database password left blank stays unchanged."
    ],
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-modules",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Choisir les modules d'une organisation",
    "mots": "modules organisation tenant activer désactiver rubriques offre",
    "texte": "Sur la carte du tenant, **** liste les modules (énergie, archives, plans, journal…) : cochez ceux qu'elle utilise.",
    "etapes": [
     "**Tout activer** ou **Tout désactiver** pour aller vite."
    ],
    "note": "Un module désactivé disparaît du menu de tous ses utilisateurs ; ses données sont conservées.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Choose an organisation's modules",
    "mots": "modules organisation tenant enable disable sections offer",
    "texte": "On the tenant card, **** lists the modules (energy, archives, floor plans, log…): tick those it uses.",
    "etapes": [
     "**Enable all** or **Disable all** to go faster."
    ],
    "note": "A disabled module disappears from all its users' menus; its data is kept.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-basculer",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Entrer dans une organisation (support)",
    "mots": "basculer accéder entrer organisation tenant support administrer bascule",
    "texte": "**Accéder** sur la carte d'un tenant ouvre Larka dans son contexte, pour l'administrer ou dépanner ses utilisateurs.",
    "etapes": [
     "Faites ce qu'il y a à faire, puis revenez à la console (**?superadmin**)."
    ],
    "note": "Chaque bascule est enregistrée dans le journal.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Enter an organisation (support)",
    "mots": "switch open enter organisation tenant support administer",
    "texte": "**Open** on a tenant card opens Larka in its context, to administer it or help its users.",
    "etapes": [
     "Do what needs doing, then go back to the console (**?superadmin**)."
    ],
    "note": "Each switch is recorded in the log.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-comptes-tenant",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Gérer les comptes d'une organisation",
    "mots": "comptes utilisateurs organisation tenant créer rôle gestionnaire",
    "texte": "Sur la carte du tenant, **** liste ses comptes : **+ Nouveau compte**, modification (), suppression ().",
    "note": "Au quotidien, c'est le gestionnaire de l'organisation qui gère ses comptes.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Manage an organisation's accounts",
    "mots": "accounts users organisation tenant create role manager",
    "texte": "On the tenant card, **** lists its accounts: **+ New account**, edit (), delete ().",
    "note": "Day to day, the organisation's manager looks after its accounts.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-tenant-supprimer",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Supprimer une organisation",
    "mots": "supprimer tenant organisation effacer retirer définitif",
    "texte": "**** sur la carte retire le tenant de la plateforme ; selon l'option choisie, sa base peut être purgée.",
    "etapes": [
     "Faites d'abord une sauvegarde, et obtenez l'accord de l'organisation."
    ],
    "note": "Action irréversible. En cas de doute, désactivez plutôt le tenant.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Delete an organisation",
    "mots": "delete tenant organisation erase remove permanent",
    "texte": "**** on the card removes the tenant from the platform; depending on the option chosen, its database can be purged.",
    "etapes": [
     "Make a backup first, and get the organisation's agreement."
    ],
    "note": "Irreversible. If in doubt, disable the tenant instead.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-routage",
   "pour": [
    "sa"
   ],
   "cat": "sa_orga",
   "page": "superadmin",
   "fr": {
    "titre": "Un utilisateur arrive sur la mauvaise organisation",
    "mots": "mauvaise organisation tenant routage domaine e-mail web aiguillage connexion",
    "texte": "Ce sont les domaines déclarés qui aiguillent les utilisateurs.",
    "etapes": [
     "Vérifiez les **domaines web** et **domaines e-mail** des tenants concernés : un domaine mal saisi ou présent dans deux tenants est la cause la plus fréquente.",
     "Une adresse isolée (prestataire, stagiaire) se range dans **Exceptions email**."
    ],
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "A user lands in the wrong organisation",
    "mots": "wrong organisation tenant routing domain email web sign-in",
    "texte": "Declared domains route users.",
    "etapes": [
     "Check the **web domains** and **email domains** of the tenants concerned: a mistyped domain, or one present in two tenants, is the most common cause.",
     "A one-off address (contractor, intern) goes into **Email exceptions**."
    ],
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-connexion",
   "pour": [
    "sa"
   ],
   "cat": "sa_comptes",
   "fr": {
    "titre": "Se connecter à la console Super Admin",
    "mots": "console super admin connexion superadmin adresse première connexion compte",
    "texte": "La console s'ouvre à l'adresse de Larka suivie de **?superadmin**.",
    "etapes": [
     "À la toute première visite, faite depuis le serveur lui-même, Larka fait choisir l'identifiant et le mot de passe : aucun mot de passe par défaut n'est accepté.",
     "Ensuite, connectez-vous avec ce compte (ou un compte Microsoft / Google autorisé, si c'est configuré)."
    ],
    "note": "Ce compte est distinct des comptes des organisations."
   },
   "en": {
    "titre": "Sign in to the Super Admin console",
    "mots": "console super admin sign in superadmin address first sign-in account",
    "texte": "The console opens at Larka's address followed by **?superadmin**.",
    "etapes": [
     "On the very first visit, made from the server itself, Larka asks you to choose the username and password: no default password is accepted.",
     "After that, sign in with this account (or an authorised Microsoft / Google account, if configured)."
    ],
    "note": "This account is separate from the organisations' accounts."
   }
  },
  {
   "id": "sa-mdp",
   "pour": [
    "sa"
   ],
   "cat": "sa_comptes",
   "action": "sa_mdp",
   "fr": {
    "titre": "Changer le mot de passe Super Admin",
    "mots": "mot de passe super admin changer modifier sécurité",
    "texte": "Menu de la console → **Changer mot de passe**.",
    "note": "Choisissez un mot de passe long et unique : ce compte pilote toute la plateforme.",
    "bouton": "Changer le mot de passe"
   },
   "en": {
    "titre": "Change the Super Admin password",
    "mots": "password super admin change security",
    "texte": "Console menu → **Change password**.",
    "note": "Choose a long, unique password: this account controls the whole platform.",
    "bouton": "Change the password"
   }
  },
  {
   "id": "sa-comptes-sa",
   "pour": [
    "sa"
   ],
   "cat": "sa_comptes",
   "page": "superadmin",
   "fr": {
    "titre": "Ajouter ou retirer un Super Admin",
    "mots": "super admin comptes ajouter retirer supprimer administrateurs plateforme",
    "texte": "**Multi-Tenant** → **Paramètres** → **Comptes Super Admin locaux**.",
    "note": "Seul le Super Admin principal peut ajouter ou supprimer des comptes. Limitez ce cercle au strict nécessaire.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Add or remove a Super Admin",
    "mots": "super admin accounts add remove delete platform administrators",
    "texte": "**Multi-tenant** → **Settings** → **Local Super Admin accounts**.",
    "note": "Only the primary Super Admin can add or delete accounts. Keep this circle as small as possible.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-registre",
   "pour": [
    "sa"
   ],
   "cat": "sa_comptes",
   "action": "sa_comptes",
   "fr": {
    "titre": "Retrouver un compte, toutes organisations confondues",
    "mots": "registre comptes recherche utilisateur toutes organisations login",
    "texte": "**Registre comptes** liste tous les comptes locaux de la plateforme, avec leur organisation.",
    "bouton": "Ouvrir le registre"
   },
   "en": {
    "titre": "Find an account across all organisations",
    "mots": "account register search user all organisations login",
    "texte": "**Account register** lists every local account on the platform, with its organisation.",
    "bouton": "Open the register"
   }
  },
  {
   "id": "sa-sauvegardes",
   "pour": [
    "sa"
   ],
   "cat": "sa_donnees",
   "page": "superadmin",
   "fr": {
    "titre": "Sauvegarder une organisation",
    "mots": "sauvegarde sauvegardes backup automatique tenant organisation restaurer intervalle",
    "texte": "Chaque organisation a sa politique de sauvegarde : fiche du tenant (****) → **Sauvegardes**.",
    "etapes": [
     "Activez, choisissez l'**intervalle** (minutes), le nombre de **sauvegardes à conserver**, le **dossier** et la compression.",
     "**Sauvegarde manuelle…** en fait une tout de suite ; **Voir les sauvegardes** les liste.",
     "La carte du tenant affiche la dernière et la prochaine sauvegarde, et le dernier échec."
    ],
    "note": "Une sauvegarde complète, ce sont deux fichiers : la base et …-fichiers.tar.gz. Un bandeau prévient si le planificateur ne tourne pas.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Back up an organisation",
    "mots": "backup backups automatic tenant organisation restore interval",
    "texte": "Each organisation has its own backup policy: tenant record (****) → **Backups**.",
    "etapes": [
     "Enable, choose the **interval** (minutes), the number of **backups to keep**, the **folder** and compression.",
     "**Manual backup…** makes one straight away; **View backups** lists them.",
     "The tenant card shows the last and next backup, and the last failure."
    ],
    "note": "A complete backup is two files: the database and …-fichiers.tar.gz. A banner warns if the scheduler is not running.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-export-import",
   "pour": [
    "sa"
   ],
   "cat": "sa_donnees",
   "page": "superadmin",
   "fr": {
    "titre": "Exporter ou importer la base d'une organisation",
    "mots": "export import base données migrer migration transférer copier tenant",
    "texte": "Fiche du tenant (****) → **Export BDD complète** ou **Import BDD complète**.",
    "etapes": [
     "L'export récupère toute la base (migration, archivage, analyse).",
     "L'import remplace les données de la cible : vérifiez le tenant, et sauvegardez-le avant."
    ],
    "note": "L'import ne remplace jamais les comptes de la base cible.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Export or import an organisation's database",
    "mots": "export import database migrate migration transfer copy tenant",
    "texte": "Tenant record (****) → **Full database export** or **Full database import**.",
    "etapes": [
     "Export retrieves the whole database (migration, archiving, analysis).",
     "Import replaces the target's data: check the tenant, and back it up first."
    ],
    "note": "Import never replaces the target database's accounts.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-purge",
   "pour": [
    "sa"
   ],
   "cat": "sa_donnees",
   "page": "superadmin",
   "fr": {
    "titre": "Vider la base d'une organisation",
    "mots": "purger purge vider base effacer données réinitialiser tenant",
    "texte": "**** sur la carte du tenant vide sa base, en gardant le tenant.",
    "note": "Opération irréversible : exigez une confirmation explicite et une sauvegarde récente.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Empty an organisation's database",
    "mots": "purge empty database erase data reset tenant",
    "texte": "**** on the tenant card empties its database, keeping the tenant.",
    "note": "Irreversible: require explicit confirmation and a recent backup.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-maj",
   "pour": [
    "sa"
   ],
   "cat": "sa_plateforme",
   "page": "superadmin",
   "fr": {
    "titre": "Mettre à jour la plateforme",
    "mots": "mise à jour version installer upgrade plateforme nouvelle signature",
    "texte": "Larka vérifie toutes les 12 heures si une version est publiée ; un bandeau l'annonce dans la console.",
    "etapes": [
     "**Voir la mise à jour** (ou **Mises à jour** sur la page Multi-Tenant) : nouveautés, empreinte, signature de l'éditeur, compatibilité du serveur.",
     "Cochez la confirmation, puis **Installer maintenant**.",
     "**Config serveur → Version et mises à jour** : **Vérifier maintenant** et **Historique / restaurer**."
    ],
    "note": "Une version incompatible avec le serveur (PHP, extensions, SQLite, PostgreSQL) n'est pas installée ; chaque base est sauvegardée avant l'installation.",
    "bouton": "Ouvrir Multi-Tenant"
   },
   "en": {
    "titre": "Update the platform",
    "mots": "update version install upgrade platform new signature",
    "texte": "Larka checks every 12 hours whether a version has been published; a banner announces it in the console.",
    "etapes": [
     "**View the update** (or **Updates** on the Multi-tenant page): what's new, fingerprint, publisher's signature, server compatibility.",
     "Tick the confirmation, then **Install now**.",
     "**Server config → Version and updates**: **Check now** and **History / restore**."
    ],
    "note": "A version that does not suit the server (PHP, extensions, SQLite, PostgreSQL) is not installed; each database is backed up before installing.",
    "bouton": "Open Multi-tenant"
   }
  },
  {
   "id": "sa-config",
   "pour": [
    "sa"
   ],
   "cat": "sa_plateforme",
   "action": "sa_serveur",
   "fr": {
    "titre": "Régler le serveur (Config serveur)",
    "mots": "configuration serveur config réseau base sécurité sessions journaux microsoft google smtp assistant",
    "texte": "**Config serveur** modifie les réglages du serveur : réseau, base, sécurité, sessions, journaux, connexions Microsoft / Google, version et mises à jour, assistant IA…",
    "note": "Les secrets (mots de passe, clés) sont rangés dans le fichier .env, lisible du seul serveur.",
    "bouton": "Ouvrir Config serveur"
   },
   "en": {
    "titre": "Configure the server (Server config)",
    "mots": "server configuration config network database security sessions logs microsoft google smtp assistant",
    "texte": "**Server config** changes the server settings: network, database, security, sessions, logs, Microsoft / Google sign-in, version and updates, AI assistant…",
    "note": "Secrets (passwords, keys) are kept in the .env file, readable by the server only.",
    "bouton": "Open Server config"
   }
  },
  {
   "id": "sa-branding",
   "pour": [
    "sa"
   ],
   "cat": "sa_plateforme",
   "page": "superadmin_branding",
   "fr": {
    "titre": "Personnaliser l'écran de connexion",
    "mots": "personnalisation écran connexion logo couleurs fond thème css titre",
    "texte": "**Personnalisation** : titre, sous-titre, logo, couleurs, fond animé ou CSS décoratif, avec un aperçu en direct.",
    "note": "La mention de droit d'auteur et de licence de l'écran de connexion n'est pas modifiable.",
    "bouton": "Ouvrir Personnalisation"
   },
   "en": {
    "titre": "Customise the sign-in screen",
    "mots": "customisation sign-in screen logo colours background theme css title",
    "texte": "**Customisation**: title, subtitle, logo, colours, animated background or decorative CSS, with a live preview.",
    "note": "The copyright and licence notice on the sign-in screen cannot be changed.",
    "bouton": "Open Customisation"
   }
  },
  {
   "id": "sa-rgpd",
   "pour": [
    "sa"
   ],
   "cat": "sa_conformite",
   "page": "superadmin_rgpd",
   "fr": {
    "titre": "Personnaliser la politique RGPD d'une organisation",
    "mots": "rgpd conformité politique données personnelles dpo personnaliser organisation section",
    "texte": "**Conformité RGPD** liste les organisations et l'état de leur politique : modèle commun, ou personnalisée.",
    "etapes": [
     "**Personnaliser** : coordonnées (organisation, adresse, contact, DPO), sections du modèle à réécrire, sections ajoutées (six au plus).",
     "**Aperçu** montre la politique telle que la verront les utilisateurs.",
     "**↺ Modèle commun** efface la personnalisation."
    ],
    "note": "Toute personnalisation est signalée aux utilisateurs : bandeau daté et mention « Personnalisé par l'entreprise » sur chaque section concernée.",
    "bouton": "Ouvrir Conformité RGPD"
   },
   "en": {
    "titre": "Customise an organisation's GDPR policy",
    "mots": "gdpr compliance policy personal data dpo customise organisation section",
    "texte": "**GDPR compliance** lists the organisations and the state of their policy: common model, or customised.",
    "etapes": [
     "**Customise**: details (organisation, address, contact, DPO), model sections to rewrite, added sections (six at most).",
     "**Preview** shows the policy as users will see it.",
     "**↺ Common model** removes the customisation."
    ],
    "note": "Any customisation is shown to users: a dated banner and “Customised by the organisation” on each section concerned.",
    "bouton": "Open GDPR compliance"
   }
  },
  {
   "id": "sa-rgpd-modele",
   "pour": [
    "sa"
   ],
   "cat": "sa_conformite",
   "page": "superadmin_rgpd",
   "fr": {
    "titre": "Modifier le modèle commun de la politique",
    "mots": "rgpd modèle commun politique toutes organisations variables",
    "texte": "**Conformité RGPD** → **Modifier le modèle commun** : retouches valables pour toutes les organisations qui ne réécrivent pas la section, sans mention « personnalisé ».",
    "note": "Variables remplacées à l'affichage : {organisation}, {contact}, {dpo}, {journaux_jours}, {session_heures}, {fournisseur_ia}, {fournisseurs_sso}.",
    "bouton": "Ouvrir Conformité RGPD"
   },
   "en": {
    "titre": "Edit the policy's common model",
    "mots": "gdpr common model policy all organisations variables",
    "texte": "**GDPR compliance** → **Edit the common model**: changes that apply to every organisation that does not rewrite the section, without a “customised” mark.",
    "note": "Variables replaced on display: {organisation}, {contact}, {dpo}, {journaux_jours}, {session_heures}, {fournisseur_ia}, {fournisseurs_sso}.",
    "bouton": "Open GDPR compliance"
   }
  },
  {
   "id": "sa-journal",
   "pour": [
    "sa"
   ],
   "cat": "sa_conformite",
   "page": "journal",
   "fr": {
    "titre": "Consulter le journal d'audit",
    "mots": "journal audit trace qui a fait quoi historique modifications connexions erreurs filtre",
    "texte": "**Journal d'audit** trace tout ce qui se passe dans l'organisation courante : connexions, pages, créations, modifications, suppressions, erreurs.",
    "etapes": [
     "La synthèse sur 7 jours repère l'anormal (erreurs fréquentes, routes lentes, utilisateurs actifs).",
     "Filtrez par niveau, canal, utilisateur, texte ou date, puis **Filtrer**.",
     "**Détail** montre une entrée complète, avec l'avant / après d'une modification."
    ],
    "note": "Le journal affiché est celui de l'organisation par laquelle vous êtes entré, ou de celle où vous avez basculé.",
    "bouton": "Ouvrir le journal"
   },
   "en": {
    "titre": "Read the audit log",
    "mots": "audit log trail who did what history changes sign-ins errors filter",
    "texte": "**Audit log** records everything that happens in the current organisation: sign-ins, pages, creations, changes, deletions, errors.",
    "etapes": [
     "The 7-day summary spots the unusual (frequent errors, slow routes, active users).",
     "Filter by level, channel, user, text or date, then **Filter**.",
     "**Details** shows a full entry, with the before / after of a change."
    ],
    "note": "The log shown is that of the organisation you came in through, or the one you switched into.",
    "bouton": "Open the log"
   }
  },
  {
   "id": "sa-securite",
   "pour": [
    "sa"
   ],
   "cat": "sa_conformite",
   "page": "journal",
   "fr": {
    "titre": "Repérer les événements de sécurité",
    "mots": "sécurité événements connexions échouées bruteforce blocage refus incident attaque",
    "texte": "Les événements sensibles (échecs de connexion, blocages, refus d'accès, opérations Super Admin, changements de configuration) sont dans le **Journal d'audit**.",
    "etapes": [
     "Filtrez sur le canal **securite** et le niveau **warning et +**."
    ],
    "bouton": "Ouvrir le journal"
   },
   "en": {
    "titre": "Spot security events",
    "mots": "security events failed sign-ins brute force blocking refusal incident attack",
    "texte": "Sensitive events (failed sign-ins, blocks, access refusals, Super Admin operations, configuration changes) are in the **Audit log**.",
    "etapes": [
     "Filter on the **securite** channel and the **warning and up** level."
    ],
    "bouton": "Open the log"
   }
  },
  {
   "id": "sa-monitoring",
   "pour": [
    "sa"
   ],
   "cat": "sa_conformite",
   "action": "sa_monitoring",
   "fr": {
    "titre": "Superviser la plateforme",
    "mots": "monitoring supervision état santé indicateurs organisations activité",
    "texte": "**Monitoring** donne l'état de la plateforme et des organisations : activité, indicateurs techniques, points d'attention.",
    "bouton": "Ouvrir Monitoring"
   },
   "en": {
    "titre": "Monitor the platform",
    "mots": "monitoring supervision state health indicators organisations activity",
    "texte": "**Monitoring** gives the state of the platform and organisations: activity, technical indicators, points to watch.",
    "bouton": "Open Monitoring"
   }
  },
  {
   "id": "sa-secrets",
   "pour": [
    "sa"
   ],
   "cat": "sa_conformite",
   "fr": {
    "titre": "Protéger les secrets (config.key, .env)",
    "mots": "secrets clé config.key env mot de passe chiffrement sauvegarde coffre",
    "texte": "Les mots de passe des bases des organisations sont chiffrés dans le registre avec la clé **config.key**.",
    "etapes": [
     "Sauvegardez **config.key** et **.env** à part, en lieu sûr : sans la clé, les mots de passe des bases sont à ressaisir.",
     "Plusieurs serveurs partagent le même registre ? Copiez la même config.key sur chacun."
    ],
    "note": "Ces mots de passe ne sont jamais affichés, ni dans l'interface ni par l'installation."
   },
   "en": {
    "titre": "Protect the secrets (config.key, .env)",
    "mots": "secrets key config.key env password encryption backup vault",
    "texte": "The organisations' database passwords are encrypted in the register with the **config.key** key.",
    "etapes": [
     "Back up **config.key** and **.env** separately, somewhere safe: without the key, database passwords must be entered again.",
     "Several servers share the same register? Copy the same config.key to each."
    ],
    "note": "These passwords are never displayed, neither in the interface nor by the installer."
   }
  },
  {
   "id": "sa-domaine-requis",
   "pour": [
    "sa"
   ],
   "cat": "faq",
   "fr": {
    "titre": "« Domaine e-mail requis » à l'enregistrement d'une organisation",
    "mots": "domaine e-mail requis obligatoire erreur enregistrement tenant",
    "texte": "Indiquez au moins un domaine e-mail (par exemple mairie-exemple.fr), ou « * » pour accepter toutes les adresses : c'est lui qui dirige les utilisateurs vers l'organisation à la connexion."
   },
   "en": {
    "titre": "“Email domain required” when saving an organisation",
    "mots": "email domain required mandatory error save tenant",
    "texte": "Enter at least one email domain (for example town-example.org), or “*” to accept every address: it routes users to the organisation when they sign in."
   }
  }
 ]
}
JSON, true, 64, JSON_THROW_ON_ERROR);
