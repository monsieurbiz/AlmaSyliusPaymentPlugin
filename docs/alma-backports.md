# Backports locaux Alma — MBIZ-29120

Suivi : <https://support.monsieurbiz.com/issues/29120>.

Ces changements ciblent le fork Sylius 2.1, pas une migration globale vers
l'upstream. Le préflight historique d'implémentation du 12 novembre 2026 a trouvé
`main` propre à
`22f99a500cf4132cd87246497f43bde5f34d6f17`. Depuis la baseline `a7dcef9`, le seul
changement était déjà l'ajout du choix 24 échéances au formulaire.

Pour la phase de release du 12 novembre 2026, Jacques a explicitement autorisé
la branche `feat/29120-alma-backports` et exactement trois commits locaux dans
l'ordre 01 → 02 → 03. `main` reste à la baseline ci-dessus. Aucun push ni PR
n'a été effectué par cette phase de travail.

Le contrôle public du 16 novembre confirme désormais la branche distante à
`d795967b87f7092d0d6ce9f8c20b742b797dd860` ; les mentions d'absence de push du
12 novembre ne décrivent pas l'état distant actuel. Une quatrième étape locale,
distincte, est autorisée pour le widget natif V4 :
[provenance, migration et vérifications](alma-widget-v4.md), suivi
[MBIZ-29091](https://support.monsieurbiz.com/issues/29091). Cette nouvelle
intervention n'autorise ni push, ni PR, ni modification du consommateur.

## Périmètre des lots

1. Normaliser les deux réponses d'éligibilité du SDK sans rendre les contrats
   nullables, perdre les clés de plans ou changer la gestion de `RequestError`.
   Conserver `array_merge` : les builders accumulent des closures et les valeurs
   du dernier appel doivent remplacer les anciennes échéances et URL.
2. Vérifier le choix 24 existant, les choix antérieurs, la validation des clés et
   le marchand, le payload entier et le rendu crédit. Afficher une dernière
   mensualité distincte lorsqu'elle diffère des mensualités intermédiaires.
3. Utiliser la redirection pour les configurations nouvelles et historiques,
   normalisées à l'ouverture/enregistrement sans migration de masse. Conserver
   les constantes publiques de compatibilité et les validations de paiement.
   L'identifiant Alma créé est lié au paiement avant la redirection ; un PID de
   retour/notification différent ou mal formé est refusé sans modifier les
   détails ou marquer le paiement échoué.

## Tests ciblés, sans application ni accès Alma

Le dépôt n'a pas de `vendor`, de lock ni de `Makefile` au préflight. Sa
configuration PHPUnit historique vise un bootstrap d'application absent. Le
harnais `phpunit.backports.xml` est indépendant de ce bootstrap. Il charge les
dépendances existantes et donne la priorité au `src` du fork testé.

Depuis la racine du plugin, avec un autoload de dépendances compatible :

```bash
ALMA_TEST_AUTOLOAD=/chemin/vers/vendor/autoload.php \
  symfony php /chemin/vers/vendor/bin/phpunit -c phpunit.backports.xml
```

Ce chemin désigne uniquement l'autoload Composer, jamais le bootstrap applicatif.
Les clés et réponses marchandes des tests sont fictives. Aucun appel de paiement,
activation marchande ou test sandbox n'est nécessaire pour cette suite. Ne pas
installer/rénover les dépendances de développement ou modifier un `vendor` pour
la faire fonctionner.

La CI historique PHP 8.0 et les anciennes dépendances de développement ne sont
pas une preuve de compatibilité : le manifeste courant exige PHP `^8.2` et
Sylius `~2.1`. La rénovation de CI reste hors de ces lots.

## Vérifications de release — 12 novembre 2026

Chaque index est exporté dans un snapshot temporaire à liste blanche, sans
fichier privé ni bootstrap applicatif. Tous les tests présents y sont exécutés.
Les tests des lots suivants sont absents ; leurs fichiers de production restent
à la baseline. Les blobs et modes de tous les fichiers sont vérifiés avant et
après les tests, puis l'arbre du commit est comparé à l'arbre testé.

Répertoire des preuves (hors dépôt) :
`/private/var/folders/kz/sn8jyxjs4hb_tqw1bp_qn91w0000gn/T/opencode/alma-backports-release-ryyzwrr7`.
Il contient `backup/` (patch explicite, copies autorisées et SHA-256 vérifiés),
`snapshot-01/`, `snapshot-02/`, `snapshot-03/`, les logs
`lot-NN-phpunit.log`, `lot-NN-lint.log` et les empreintes complètes dans
`lot-NN-receipt.json`. Ces preuves temporaires doivent être conservées pour la
revue indépendante ; elles ne sont pas publiées.

Commande exacte, exécutée dans chacun des trois snapshots :

```bash
ALMA_TEST_AUTOLOAD=/Users/jacques/.local/share/opencode/worktree/7c45d5ce94cd9ce6ef5716f171e8f67b14e0f00c/feat-29120-update-alma/apps/sylius/vendor/autoload.php symfony php /Users/jacques/.local/share/opencode/worktree/7c45d5ce94cd9ce6ef5716f171e8f67b14e0f00c/feat-29120-update-alma/apps/sylius/vendor/bin/phpunit -c phpunit.backports.xml
```

Résultats : PHPUnit 11.5.55 / PHP 8.3.36, dépendances hôte existantes en lecture
seule (SDK Alma 2.6.1, Sylius 2.1.17).

- Lot 01 : **8 tests, 110 assertions, OK** ; arbre
  `d0dc8b108deafd650dbc6820fe0daf2fd372b842`.
- Lot 02 (cumulatif) : **18 tests, 282 assertions, OK** ; arbre
  `6189b1f6860e3127e426bb7160a1310053a3b68f`.
- Lot 03 (suite complète) : **40 tests, 728 assertions, OK** ; arbre exact
  consigné dans le reçu externe et dans le rapport final (pas de SHA autoréférent
  dans le commit).

Pour chaque état : `symfony php -l <fichier>` sur tous les fichiers PHP de `src`
et `tests`, puis `git diff --cached --check` : OK. Aucun hook de commit
exécutable n'est présent au contrôle préalable. Aucun fichier de production ni
test n'est réécrit pour la partition de release ; seule la documentation évolue
par lot. Une suite finale ne remplace pas les preuves intermédiaires.
Aucune exécution sous PHP 8.0 ou PHPUnit 9 n'est revendiquée.

### Revue et corrections

Lors de la phase d'implémentation antérieure, le coordinateur a rapporté :
implémentation et tests par les spécialistes backend/QA, audit sécurité en
lecture seule et revue indépendante finale. La revue a conduit à :

- compléter le scénario d'un même marchand dans les modes test et live ;
- corriger une régression de reprise introduite pendant le développement :
  `is_valid=false` ne doit pas figer les notifications. Échec `false → true`
  reproduit avant correction, puis retour au contrat historique : chaque
  notification revalide tant que le paiement reste `new/processing`. Les
  progressions `false → true` et `true → false` sont testées ;
- ajouter les refus de validation avec les vrais Bridge/Action et un SDK simulé.

Verdict rapporté par le coordinateur pour cette revue : aucun défaut bloquant
restant confirmé dans le diff local. La revue indépendante des trois arbres
de release reste à effectuer après leur création. Ce verdict ne couvre pas le runtime hôte ou les garanties financières
externes listées plus bas.

## Fichiers changés par lot

### 01 — Éligibilité

- `src/Bridge/AlmaBridge.php`
- `tests/Bridge/AlmaBridgeTest.php`
- `tests/Bridge/EligibilityConsumersTest.php`
- `tests/DataBuilder/PaymentDataBuilderTest.php`

### 02 — 24 échéances et affichage crédit

Le choix 24 était déjà présent dans HEAD ; pas de second ajout.

- `templates/shop/partials/alma_credit.html.twig`
- `translations/messages.de.yml`, `messages.en.yml`, `messages.es.yml`,
  `messages.fr.yml`, `messages.it.yml`, `messages.nl.yml`, `messages.pt.yml`
  (tous dans `translations/`)
- `tests/Form/AlmaGatewayConfigurationTypeTest.php`
- `tests/DataBuilder/EligibilityDataBuilderTest.php`
- `tests/Twig/AlmaCreditRenderingTest.php`

### 03 — Redirection

- `src/Form/Type/AlmaGatewayConfigurationType.php`
- `src/Payum/Action/CaptureAction.php`
- `src/Payum/Action/RedirectToPaymentPageAction.php`
- `src/Payum/Action/NotifyAction.php`
- `src/Payum/Action/StatusAction.php`
- `src/Payum/Gateway/AlmaGatewayFactory.php`
- `src/Payum/Gateway/GatewayConfig.php`
- `config/services/actions.yaml`
- Suppression de `src/Payum/Action/RenderInPagePaymentAction.php`,
  `src/Payum/Request/RenderInPagePayment.php` et
  `templates/shop/action/payment_form.html.twig`
- `tests/Form/RedirectConfigurationTest.php`
- `tests/Payum/RedirectFlowTest.php`
- `tests/Payum/PaymentValidationTest.php`

### Harnais et documentation communs (introduits au lot 01)

- `tests/bootstrap.php`
- `phpunit.backports.xml`
- `README.md`
- `docs/alma-backports.md`

## Matrice risque → preuve

| Invariant | Vérification locale attendue | Preuve restant côté hôte |
| --- | --- | --- |
| Objet singleton et tableaux SDK | Deux méthodes, clés, vide, inéligible, erreur API | Montage DI et checkout du consommateur |
| Séparation des marchands et modes | Deux groupes simulés, payload entier, conversion après éligibilité | Moyens et canaux réels, absence de fuite dans le runtime hôte |
| Choix 24 distinct | Formulaire réel, choix antérieurs et rejet des valeurs inconnues | Création d'un nouveau moyen, conditions et activation Alma |
| Crédit affiché | Rendu Twig, frais, TAEG nul/non nul, dernière mensualité | Recette visuelle et validation des mentions par Alma |
| Ancien `inpage` | Normalisation du formulaire et capture redirigée | Ouverture/enregistrement des moyens historiques en admin |
| Retour et notification | Actions simulées, identifiant, montant, expiration, état, première échéance, rejeu et ordre séquentiel | HTTP, tokens, transactions et transitions réelles |

### Autorité et identité stable

L'identifiant Alma stocké dans les détails du paiement et le `payment_id` retourné
par l'API restent les références de rapprochement. Une donnée de retour client
ne remplace pas la vérification serveur du paiement Alma.

### Vérifications de confiance

Préserver la vérification de l'identifiant interne, du montant, de l'absence
d'expiration, de l'état Alma et du paiement de la première échéance. Ne pas
intégrer le HMAC de la branche debug ni le SDK InPage de la PR 37.

### Machine de reprise minimale

Conserver les actions et transitions Payum/Sylius existantes. Les tests de rejeu
et d'ordre callback/retour portent sur l'exécution séquentielle simulée, pas sur
une nouvelle garantie d'idempotence transactionnelle.

### Transactions, verrous et compensation

Aucun schéma, verrou, transaction ou mécanisme de compensation n'est ajouté.
Les garanties de persistance et de transition de l'hôte restent à vérifier.

### Replay / concurrence / crash partiel

Un test local de second passage ne prouve ni l'exclusion de deux workers ni la
reprise après crash entre la réponse Alma et la sauvegarde. Aucun résultat de
concurrence réelle ou d'injection de crash n'est revendiqué.

### Channel-locale-devise

Aucun nouveau canal ou moyen n'est créé automatiquement. Conserver les moyens
existants ; préparer le 24 fois comme un moyen distinct. Les tests locaux ne
valident pas la configuration multicanal de Baguetterie. Le rendu conserve sa
devise EUR préexistante.

### Diagnostic et logs

Conserver le comportement des erreurs API et les logs existants. Ne pas archiver
de payload marchand, clé, donnée personnelle ou signature dans les fixtures ou
les diagnostics.

## Intégration et recette restant à autoriser

- Les trois premiers commits ont été produits localement le 12 novembre.
  Leur présence distante est constatée le 16 novembre ; aucun push ni PR
  n'est effectué par l'étape widget V4.
- Publier le commit final du fork sur demande, puis faire consommer **exactement** ce
  commit par Baguetterie, sans mise à jour transitive. Aucun Composer hôte n'est
  modifié dans ces lots.
- Exécuter les tests du consommateur et son `make test.all` dans un contexte
  autorisé. La cible hôte inclut boot applicatif, validation de schéma et
  container ; elle n'est pas lancée depuis le plugin et peut charger sa
  configuration privée. Le plugin ne possède pas cette cible.
- Faire une recette autorisée : acceptation, refus, abandon, retour, annulation,
  notification et remboursement. Confirmer les conditions et l'activation 24X
  avant d'activer le nouveau moyen.
- Le widget en mode LIVE forcé est préexistant et n'est pas exercé par ces tests.
  Ne pas ouvrir un parcours connecté ou appeler le sandbox sans autorisation
  spécifique, même si les tickets préparatoires le prévoyaient.
- En repli, désactiver seulement le nouveau moyen 24X, sans supprimer de moyen ni
  de paiement en cours.

Les critères d'intégration, sandbox, activation, conformité et publication ne
sont pas validés par des tests locaux du fork.

## Points de vigilance préexistants

La capture en redirection crée un paiement Alma à chaque exécution. Les lots ne
créent pas de garantie d'idempotence distante : captures concurrentes, crash et
persistance doivent être vérifiés côté consommateur avant de conclure sur ce
risque. Plusieurs créations ne prouvent pas plusieurs débits.

L'extension de remboursement sur échec accepte encore des données Alma absentes.
Ce comportement préexistant n'est pas rénové ici. Ne pas considérer la simple
protection du PID au retour comme une preuve complète de sûreté du remboursement
ou de ses transitions en base ; prévoir une vérification ciblée côté hôte.
