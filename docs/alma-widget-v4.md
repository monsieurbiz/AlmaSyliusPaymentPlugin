# Widget natif Alma V4 — MBIZ-29091

Suivi : <https://support.monsieurbiz.com/issues/29091>. Cette étape complète les
[trois lots précédents](alma-backports.md), sans les réécrire. Le widget et le
ticket 29091 sont distincts du support 24 échéances suivi par 29120.

## Préflight du 16 novembre 2026

Branche existante `feat/29120-alma-backports`, index et arbre de travail propres,
HEAD `d795967b87f7092d0d6ce9f8c20b742b797dd860`, après `a44aaf8` et `78b4813`,
base `22f99a5`. La référence locale de suivi et la
[branche publique du fork](https://api.github.com/repos/monsieurbiz/AlmaSyliusPaymentPlugin/branches/feat%2F29120-alma-backports)
pointent à ce même HEAD lors du contrôle. Aucun fetch ou push n'est nécessaire.

Les **40 tests / 728 assertions** antérieurs sont rejoués et passent avant la
migration. Aucun nouveau worktree, branche, Composer, dépendance ou hôte
Baguetterie n'est modifié.

## Provenance : migration directe, pas backport officiel

Le [plugin officiel](https://github.com/alma/AlmaSyliusPaymentPlugin), confirmé
par [Packagist](https://packagist.org/packages/alma/sylius-payment-plugin), a été
examiné dans son état public :

- `main` et `v2.3.0` :
  `f1ec09a600c520cdbc260f901561d3c4dba9f139`, manifeste Sylius `<2.0`.
- `develop` : `5c5fcb0f04fd1993ac769541e5a49d27183d7a9b`.
- Listes publiques des [branches](https://api.github.com/repos/alma/AlmaSyliusPaymentPlugin/branches?per_page=100),
  [tags](https://api.github.com/repos/alma/AlmaSyliusPaymentPlugin/tags?per_page=100)
  et [PR ouvertes/fermées](https://api.github.com/repos/alma/AlmaSyliusPaymentPlugin/pulls?state=all&per_page=100) ; messages des
  162 commits accessibles depuis `main`, recherches widget / 4.x / v4.
- Arbres non tronqués de `main`, `develop`, des branches de compatibilité
  Sylius 2, de `feature/ecom-3377-…` et de `feat/productWidget` ; diffs des
  PR 26, 30 et 37.
- [PR 30](https://github.com/alma/AlmaSyliusPaymentPlugin/pull/30), tête
  `52ee04a2e7d1a9d4341ffceb4954f762024dca91` : adaptation Sylius 2, pas de
  migration widget V4 ni de LiveComponent équivalent à celui du fork.
- `feat/productWidget` à `032fee1301ccc531ff486ad8bb5aa0a45e0035cb` utilise un
  ancien widget 1.x avec jQuery/Sonata. La PR 37 concerne `@alma/in-page`,
  pas `@alma/widgets` : elle reste exclue.

**Aucun changement V4 adapté n'a été trouvé sur ce périmètre.** Cela ne prouve
pas l'absence de travaux privés ou de branches supprimées. Aucun commit
officiel n'est repris et aucun merge global n'est fait.

## Références actuelles et compatibilité

- [Intégration directe Alma](https://docs.almapay.com/docs/custom-integration-alma-widget),
  relue le 16 novembre, mise à jour affichée le 27 octobre : JS et CSS
  `@alma/widgets@4.x`.
- [Guide DCC2](https://docs.almapay.com/docs/dcc2-le-guide-technique-pas-%C3%A0-pas),
  relu pour le choix du widget natif. Cela ne constitue pas un audit légal du site.
- [Documentation V4.7.0](https://github.com/alma/widgets/blob/v4.7.0/documentation.md)
  et [contrôleur V4.7.0](https://github.com/alma/widgets/blob/v4.7.0/src/widgets_controller.tsx) :
  `initialize(merchantId, mode)` et `add(PaymentPlans, options)` restent présents.
  Un nouvel `add` démonte/remonte nativement le widget du même conteneur.
- Le [package public](https://cdn.jsdelivr.net/npm/@alma/widgets@4/package.json)
  résout alors vers 4.7.0. Le ciblage majeur `4.x` suit le guide Alma et conserve
  la politique préexistante de mises à jour mineures, plutôt que créer un verrou
  de version distinct.
- Context7 `/websites/almapay` a aussi été interrogé : son exemple CDN reste
  indexé en V3. La page Alma live et la documentation versionnée V4 priment.

## Changement minimal

Les seuls fichiers de production modifiés sont :

- `templates/shop/widget/scripts.html.twig` : JS V4 et remplacement de `_` par
  `-` dans la locale, sans tronquer la région (`nl_BE` → `nl-BE`,
  `pt_PT` → `pt-PT`, `fr_FR` → `fr-FR`).
- `templates/shop/widget/styles.html.twig` : CSS V4 assorti.

Le contrat `alma:plans:mounted`, le LiveComponent et les hooks produit/panier
restent en place. Les événements réutilisent les valeurs actualisées du montant
entier en centimes, des plans et de la locale. Quantité × prix de la variante
pour le produit et total de commande pour le panier restent les sources serveur.
Les plans et seuils marchands transmis par `FeePlansHelper` ne sont pas modifiés.
Aucune méthode `update`/`destroy`, modale maison ou fonctionnalité supplémentaire
n'est ajoutée. Le mode LIVE forcé préexistant n'est pas changé ni exercé.

## Tests hors réseau

Les tests utilisent Twig et le helper/extension réels avec bridge simulé.
Le JavaScript rendu est exécuté par Node avec `document` et l'API Alma simulés,
sans charger le script CDN ni effectuer d'appel API. Node est un prérequis
local du test ; aucun paquet npm n'est installé.

- `tests/Twig/AlmaWidgetRenderingTest.php` : assets JS/CSS V4 assortis, absence
  d'assets sans marchand, échappement d'un identifiant fictif, produit/panier,
  changements de quantités/prix/variantes et de plans/locales.
- `tests/Twig/Component/PlansComponentTest.php` : événements initiaux et répétés.
- `tests/Widget/run-rendered-widget.cjs` : exécution du JS rendu, entiers en
  centimes, locales régionales/courtes et même conteneur lors des mises à jour.

Avant correction : **5 tests, 77 assertions, 2 échecs attendus**, montrant les
assets V3 et `fr_FR` tronqué en `fr`. Le remontage est simulé : il ne prouve pas
le comportement d'un vrai navigateur chargé avec le SDK V4.

### Résultats après correction

Tests ciblés : **5 tests / 81 assertions, OK**. Suite complète rejouée par QA puis
par le coordinateur : **45 tests / 809 assertions, OK**, PHPUnit 11.5.55 /
PHP 8.3.36. Le test Node est appelé automatiquement par PHPUnit ; il traite
12 événements et 11 remontages simulés. Aucun accès connecté au widget Alma.

Commande complète exécutée depuis la racine du plugin :

```bash
ALMA_TEST_AUTOLOAD=/Users/jacques/.local/share/opencode/worktree/7c45d5ce94cd9ce6ef5716f171e8f67b14e0f00c/feat-29120-update-alma/apps/sylius/vendor/autoload.php symfony php /Users/jacques/.local/share/opencode/worktree/7c45d5ce94cd9ce6ef5716f171e8f67b14e0f00c/feat-29120-update-alma/apps/sylius/vendor/bin/phpunit -c phpunit.backports.xml
```

Pour la sélection ciblée, la même commande reçoit les chemins
`tests/Twig/AlmaWidgetRenderingTest.php` et
`tests/Twig/Component/PlansComponentTest.php`.

Autres contrôles exécutés :

```bash
symfony php -l tests/Twig/AlmaWidgetRenderingTest.php
symfony php -l tests/Twig/Component/PlansComponentTest.php
node --check tests/Widget/run-rendered-widget.cjs
git diff --check
```

Résultats : OK. Revue indépendante du diff, des contrats V4 et de la couverture :
avis favorable, aucun défaut confirmé ou bloquant. L'intégration d'application
et le SDK réel en navigateur ne font pas partie de cette preuve locale.

## Limites et suite consommateur

Un commit local distinct est autorisé sur la branche existante, pas un push ou
une PR. Les trois commits précédents restent inchangés.

Restent à autoriser et réaliser : consommation du commit exact dans Baguetterie,
tests hôte et `make test.all`, montage réel LiveComponent/AJAX après changement de
quantité/variante/panier, recette visuelle mobile et validation des mentions.
Le plugin n'a toujours pas de Makefile. Aucun chargement connecté d'un widget LIVE,
appel sandbox/paiement, activation marchande 12X/24X, déploiement ou changement
Redmine n'est effectué. **La V4 dans le fork ne prouve pas la V4 en production.**
