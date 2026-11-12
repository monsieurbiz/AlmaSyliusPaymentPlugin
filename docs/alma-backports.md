# Backports locaux Alma — MBIZ-29120

Suivi : <https://support.monsieurbiz.com/issues/29120>.

Ces changements ciblent le fork Sylius 2.1, pas une migration globale vers
l'upstream. Le préflight historique d'implémentation du 12 novembre 2026 a trouvé
`main` propre à `22f99a500cf4132cd87246497f43bde5f34d6f17`.
Le choix 24 échéances était déjà présent dans ce commit utilisateur.

Le 12 novembre 2026, Jacques a autorisé une branche dédiée
`feat/29120-alma-backports` et exactement trois commits locaux ordonnés.
`main` reste inchangé. Aucun push, PR, appel Alma, sandbox, activation ou accès
applicatif hôte n'est autorisé dans cette phase de release.

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

## Lot 01 — Éligibilité

Les deux méthodes du Bridge normalisent le singleton SDK sous la clé
`general_N_0_0` ; les tableaux, les contrats non nullables et le traitement de
`RequestError` restent inchangés. `array_merge` est conservé pour remplacer les
anciennes échéances et URL dans les builders à closures cumulées.

Les tests couvrent les deux méthodes, les réponses vides/inéligibles, les clés,
les erreurs, deux marchands, le même marchand en test/live, les consommateurs
Resolver/Twig et une conversion après éligibilité avec de nouvelles URL.

- `src/Bridge/AlmaBridge.php`
- `tests/Bridge/AlmaBridgeTest.php`
- `tests/Bridge/EligibilityConsumersTest.php`
- `tests/DataBuilder/PaymentDataBuilderTest.php`

Le harnais partagé (`tests/bootstrap.php`, `phpunit.backports.xml`) et le lien
README arrivent dans ce premier lot.

## Lot 02 — Dernière mensualité du crédit

Le choix 24 est préexistant à ces backports : aucun ajout au formulaire de
production dans ce lot. Les tests soumettent explicitement le mode `redirect`,
déjà accepté par le formulaire avant le lot 03.

Le rendu distingue la dernière mensualité lorsqu'elle diffère des mensualités
intermédiaires, avec une nouvelle traduction dans les sept langues. Les tests
couvrent les choix existants et 24, les valeurs inconnues, les clés et le marchand,
le payload entier, les plans courts et crédit, les frais et le TAEG nul/non nul.

- `templates/shop/partials/alma_credit.html.twig`
- `translations/messages.de.yml`, `messages.en.yml`, `messages.es.yml`,
  `messages.fr.yml`, `messages.it.yml`, `messages.nl.yml`, `messages.pt.yml`
  (tous dans `translations/`)
- `tests/Form/AlmaGatewayConfigurationTypeTest.php`
- `tests/DataBuilder/EligibilityDataBuilderTest.php`
- `tests/Twig/AlmaCreditRenderingTest.php`

## Vérification de release isolée — 12 novembre 2026

Avant chaque commit, l'index exact est exporté dans un snapshot temporaire à
liste blanche, sans fichier privé ni bootstrap applicatif. Tous les tests
présents sont exécutés avec la commande ci-dessus, puis les fichiers du snapshot
sont comparés aux blobs de l'arbre indexé. Les tests des lots suivants sont
absents et leurs fichiers de production restent à la baseline.
Le lint `symfony php -l` de chaque fichier PHP de `src` et `tests`, ainsi que
`git diff --cached --check`, font partie du contrôle préalable au commit.
Les empreintes d'arbres, commandes, résultats et chemins de logs sont remis dans
le rapport de release ; une suite finale ne tient pas lieu de preuve intermédiaire.

## Limites et travaux restant à autoriser

Les tests utilisent des réponses et identités fictives, sans réseau Alma.
Ils ne prouvent pas le montage DI, le checkout, la persistance, les transitions
ou la concurrence du consommateur. Aucun test hôte ni `make test.all` n'est lancé.
La CI historique PHP 8.0 / PHPUnit 9 n'est pas vérifiée.
Publication et intégration consommateur nécessitent une autorisation distincte.
