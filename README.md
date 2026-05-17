# Pro Analytics Suite

Plugin WordPress d'analytics premium pour centraliser les ventes WooCommerce, les reservations FluentBooking, les contenus suivis et les donnees Google Analytics 4.

## Fonctionnalites

- Dashboard admin organise par sections : vue d'ensemble, activite, reservations, contenus et details operationnels.
- Onglets dedies : Clients, Reservations, Commandes, Contenus, Google Analytics, Rapports, Exports et Parametres.
- Graphiques interactifs avec fallback HTML si JavaScript ne s'execute pas.
- Integration WooCommerce : commandes, chiffre d'affaires, panier moyen, produits, statuts, clients recurrents.
- Integration FluentBooking : reservations, annulations, types, durees, pays, civilite.
- Integration contenus : masterclass, livres blancs, suivis, consultations et tops contenus.
- Integration GA4 via Service Account : utilisateurs actifs, sessions, pages vues, sources de trafic, appareils, pays, villes, ages et sexes quand disponibles.
- Shortcode public `[analytics_public]` avec interface premium et statistiques GA4 filtrees sur une page selectionnee.
- Exports CSV, Excel et PDF.
- Personnalisation de l'apparence depuis les parametres : couleurs principales, accent, header, surfaces et badge.

## Installation

1. Copier le dossier du plugin dans `wp-content/plugins/analytic-suite`.
2. Activer le plugin depuis WordPress ou avec WP-CLI :

```bash
wp plugin activate analytic-suite
```

3. Ouvrir `Pro Analytics` dans l'administration WordPress.

## Configuration GA4

Dans `Pro Analytics > Parametres` :

1. Renseigner le `Property ID GA4`.
2. Coller le JSON complet du Service Account Google.
3. Donner a l'email du Service Account un acces lecteur sur la propriete GA4.
4. Selectionner la page publique suivie pour le shortcode.
5. Cocher `Vider le cache GA`, puis enregistrer.

Les donnees demographiques GA4, comme l'age et le sexe, dependent de la configuration GA4, du consentement utilisateur, de Google Signals et des seuils de confidentialite Google. Si GA4 ne renvoie rien, le shortcode conserve les donnees WordPress disponibles en fallback.

## Shortcode Public

Utiliser le shortcode suivant dans une page WordPress :

```text
[analytics_public]
```

Le shortcode affiche :

- statistiques utilisateurs issues de WordPress,
- progression et engagement contenus,
- donnees GA4 de la page selectionnee dans les parametres,
- repartitions age, sexe, pays et villes si GA4 les fournit,
- graphiques interactifs et listes detaillees.

## Structure

```text
analytic-suite.php
includes/
  class-analytic-suite.php
  class-analytic-suite-dashboard-service.php
  repositories/
  services/
admin/
  class-analytic-suite-admin.php
  class-analytic-suite-export-controller.php
assets/
  css/admin.css
  js/admin.js
tests/
```

## Commandes Dev

Verifier la syntaxe PHP :

```bash
php -l analytic-suite.php
php -l includes/class-analytic-suite.php
php -l admin/class-analytic-suite-admin.php
php -l includes/services/class-analytic-suite-google-analytics.php
```

Verifier l'etat Git :

```bash
git status --short
```

Activer le plugin avec WP-CLI :

```bash
wp plugin activate analytic-suite
```

## Securite

- Les actions admin utilisent les capacites WordPress du plugin.
- Les entrees `$_GET` et `$_POST` sont sanitisees.
- Les sorties HTML sont echappees.
- Les exports verifient les permissions.
- Les identifiants et donnees clients ne doivent pas etre commits.

## Notes

Le plugin n'utilise pas encore Composer ni npm. Si un gestionnaire de dependances est introduit, documenter les commandes dans ce README et dans `AGENTS.md`.
