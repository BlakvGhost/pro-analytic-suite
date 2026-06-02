# API REST — Pro Analytics Suite

Pipeline de données : **WordPress (N sites) → n8n (horaire) → BigQuery → Keycloak → Looker Studio**

> **Architecture :** Le plugin ne possède pas de dashboard WordPress. Toutes les statistiques sont exposées exclusivement via cette API REST. Le plugin est installé sur **plusieurs sites WordPress** ; n8n collecte chacun d'eux, identifie la source via le champ `site_id`, agrège dans BigQuery, et Looker Studio visualise depuis BigQuery.

Base URL :

```
https://<domaine>/wp-json/analytic-suite/v1
```

---

## Multi-sites — identification des sources

Chaque réponse API porte trois champs d'identification injectés automatiquement :

| Champ | Valeur | Configuré dans |
|---|---|---|
| `site_id` | Identifiant court unique (ex: `entourage`) | WordPress > Pro Analytics > Paramètres > Identité du site |
| `site_name` | Nom lisible (ex: `Entourage`) | WordPress > Pro Analytics > Paramètres > Identité du site |
| `site_url` | URL du site (ex: `https://entourage.fr`) | Automatique (`get_bloginfo('url')`) |

> **Important :** configurer `site_id` avant la première synchronisation. C'est la clé de partition dans BigQuery. Un `site_id` non configuré prend la valeur du slug du nom du site.

### `site_id` dans les lignes brutes (`/sync/*`)

Le champ `site_id` est injecté en **première position de chaque ligne** pour que n8n puisse l'utiliser directement comme partition sans transformation :

```json
{
  "meta": { "site_id": "entourage", "site_url": "https://entourage.fr", ... },
  "rows": [
    { "site_id": "entourage", "booking_id": 456, "user_email": "...", ... },
    { "site_id": "entourage", "booking_id": 457, "user_email": "...", ... }
  ]
}
```

### `site_id` dans les métriques agrégées (`/dashboard`, `/summary`, etc.)

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { ... },
  "data": { ... }
}
```

---

## Vue d'ensemble des endpoints

| Endpoint | Type | Usage |
|---|---|---|
| `GET /dashboard` | Agrégat | Toutes les métriques filtrables en un appel |
| `GET /summary` | Agrégat | Indicateurs clés uniquement |
| `GET /bookings` | Agrégat | Réservations : catégories, durées, pays, civilité |
| `GET /orders` | Agrégat | Commandes : CA, produits, statuts, civilité |
| `GET /contents` | Agrégat | Masterclass, livres, contenus suivis |
| `GET /filters` | Meta | Options disponibles pour les filtres |
| `GET /status` | Health | État des intégrations + identité du site |
| `GET /sync/masterclass-registrations` | Lignes brutes | → BigQuery `masterclass_registrations` |
| `GET /sync/expert-sessions` | Lignes brutes | → BigQuery `expert_sessions` |
| `GET /sync/orders` | Lignes brutes | → BigQuery `wc_orders` |
| `GET /sync/users` | Lignes brutes | → BigQuery `wp_users` |
| `DELETE /google-analytics/cache` | Action | Vide le cache GA4 |

---

## Authentification

L'API utilise les **WordPress Application Passwords** (HTTP Basic Auth).

1. Dans WordPress, aller dans **Utilisateurs > Votre profil > Mots de passe d'application**.
2. Créer un mot de passe dédié pour n8n (un par site).
3. Passer les credentials en Basic Auth sur chaque requête :

```http
Authorization: Basic base64(username:application_password)
```

> Toujours appeler l'API via HTTPS.

| Capacité requise | Endpoints |
|---|---|
| `analytic_suite_view_analytics` | Tous les endpoints `GET` |
| `analytic_suite_manage_analytics` | `DELETE /google-analytics/cache` |

---

## Endpoints d'analyse (métriques agrégées)

Ces endpoints calculent des métriques agrégées filtrables. Idéaux pour que n8n pousse des snapshots horaires dans BigQuery, ou pour alimenter directement Looker Studio via un connecteur REST.

### Paramètres de filtre (communs à tous les endpoints d'analyse)

| Paramètre | Type | Description |
|---|---|---|
| `period` | string | `all`, `7-days`, `30-days`, `year`, `custom`. Défaut : `all`. |
| `date_from` | string | Date de début `YYYY-MM-DD` (requis si `period=custom`). |
| `date_to` | string | Date de fin `YYYY-MM-DD`. |
| `booking_type` | string | Inclure uniquement les réservations de ce type (ex: `Session`, `Diagnostic stratégique`). |
| `exclude_booking_type` | string | Exclure les réservations de ce type (ex: pour isoler sessions + dîners). |
| `country` | string | Code pays ISO (ex: `FR`, `BJ`). |
| `gender` | string | Civilité (ex: `Homme`, `Femme`, `monsieur`, `madame`). |
| `status` | string | Statut WooCommerce ou FluentBooking. |
| `duration` | integer | Durée de session en minutes (ex: `30`, `60`). |
| `product` | integer | ID produit WooCommerce. |
| `customer` | string | Email client. |
| `page_path` | string | Chemin de page GA4 (ex: `/formations/`). |

---

### `GET /dashboard`

Retourne toutes les métriques en un seul appel. Point d'entrée principal pour n8n.

```http
GET /wp-json/analytic-suite/v1/dashboard?period=30-days
GET /wp-json/analytic-suite/v1/dashboard?period=custom&date_from=2026-01-01&date_to=2026-05-31
GET /wp-json/analytic-suite/v1/dashboard?booking_type=Session&period=year
GET /wp-json/analytic-suite/v1/dashboard?exclude_booking_type=Diagnostic+stratégique
```

**Structure de réponse :**

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { "period": "30-days", "date_from": "2026-05-03", "date_to": "2026-06-02" },
  "data": {
    "summary": {
      "orders": 320,
      "bookings": 1216,
      "revenue": 48000.00,
      "average_order_value": 150.00,
      "unique_customers": 500,
      "recurring_customers": 120,
      "repeat_product_customers": 45,
      "cancelled_carts": 28,
      "cancellation_rate": 59.21,
      "cancelled_bookings": 720,
      "masterclass_users": 380,
      "book_users": 210,
      "ga_active_users": 1800,
      "ga_sessions": 3200,
      "ga_page_views": 9600
    },
    "orders":   { "..." : "voir GET /orders" },
    "bookings": { "..." : "voir GET /bookings" },
    "contents": { "..." : "voir GET /contents" },
    "ga":       { "available": true, "summary": { "..." : "..." } },
    "generated_at": "2026-06-02 10:00:00"
  }
}
```

---

### `GET /summary`

Indicateurs clés uniquement (sous-ensemble de `/dashboard`).

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { ... },
  "summary": {
    "orders": 320,
    "bookings": 1216,
    "revenue": 48000.00,
    "average_order_value": 150.00,
    "unique_customers": 500,
    "recurring_customers": 120,
    "repeat_product_customers": 45,
    "cancelled_carts": 28,
    "cancellation_rate": 59.21,
    "cancelled_bookings": 720,
    "masterclass_users": 380,
    "book_users": 210,
    "ga_active_users": 1800,
    "ga_sessions": 3200,
    "ga_page_views": 9600
  }
}
```

---

### `GET /bookings`

Métriques réservations avec tous les breakdowns.

```http
GET /wp-json/analytic-suite/v1/bookings?period=year
GET /wp-json/analytic-suite/v1/bookings?booking_type=Diagnostic+stratégique
GET /wp-json/analytic-suite/v1/bookings?duration=30
GET /wp-json/analytic-suite/v1/bookings?country=FR&period=30-days
```

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { ... },
  "bookings": {
    "available": true,
    "total_bookings": 1216,
    "cancelled_bookings": 720,
    "confirmed_bookings": 496,
    "cancellation_rate": 59.21,
    "unique_customers": 480,
    "category_breakdown": {
      "Session": 400,
      "Dîner": 80,
      "Diagnostic stratégique": 220,
      "Autres": 516
    },
    "duration_breakdown": { "30 min": 280, "60 min": 120 },
    "duration_summary": { "30 min": 280, "1h": 120, "leader": "30 min" },
    "type_breakdown": {
      "Session stratégique 1h": 120,
      "Dîner découverte": 80
    },
    "country_breakdown": { "FR": 800, "BJ": 200, "CI": 100 },
    "gender_breakdown": { "Homme": 600, "Femme": 616 },
    "status_breakdown": { "completed": 496, "cancelled": 720 },
    "customer_emails": ["client1@exemple.fr", "..."]
  }
}
```

> **Cas d'usage :**
> - Diagnostics stratégiques uniquement → `?booking_type=Diagnostic+stratégique`
> - Exclure les diagnostics (sessions + dîners) → `?exclude_booking_type=Diagnostic+stratégique`
> - Sessions 30 min uniquement → `?duration=30`
> - Comparer 30 min vs 1h → lire `duration_summary.leader`

---

### `GET /orders`

Métriques commandes WooCommerce.

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { ... },
  "orders": {
    "available": true,
    "total_orders": 320,
    "cancelled_orders": 28,
    "revenue": 48000.00,
    "average_order_value": 150.00,
    "unique_customers": 280,
    "recurring_customers": 120,
    "repeat_product_customers": 45,
    "retention_rate": 42.86,
    "country_sales": { "FR": 32000.00, "BJ": 8000.00 },
    "gender_breakdown": { "Homme": 160, "Femme": 160 },
    "product_sales": [
      { "name": "Masterclass Premium", "quantity": 200, "revenue": 30000.00 },
      { "name": "Session 1h", "quantity": 120, "revenue": 18000.00 }
    ],
    "status_breakdown": { "completed": 280, "cancelled": 28, "refunded": 12 }
  }
}
```

---

### `GET /contents`

Métriques contenus (masterclass, livres).

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { ... },
  "contents": {
    "available": true,
    "masterclass_table": true,
    "books_table": true,
    "total_masterclasses": 45,
    "total_books": 12,
    "masterclass_users": 380,
    "book_users": 210,
    "masterclass_follows": 1200,
    "book_downloads": 640,
    "top_masterclasses": { "Session leadership avancé": 180, "Masterclass gestion de projet": 140 },
    "top_books": { "Guide stratégie 2026": 120, "Leadership et influence": 95 },
    "masterclass_by_month": { "2026-01": 80, "2026-02": 110, "2026-03": 95 },
    "books_by_month": { "2026-01": 40, "2026-02": 55 },
    "upcoming_masterclasses": 8,
    "masterclass_replays": 32
  }
}
```

---

### `GET /filters`

Retourne les valeurs disponibles pour construire des filtres dynamiques côté n8n ou frontend.

```json
{
  "site_id": "entourage",
  "site_url": "https://entourage.fr",
  "filters": { ... },
  "options": {
    "countries":             ["FR", "BJ", "CI", "SN"],
    "statuses":              ["completed", "cancelled", "scheduled"],
    "booking_types":         ["Session stratégique 1h", "Dîner découverte", "Diagnostic stratégique"],
    "exclude_booking_types": ["Session stratégique 1h", "Dîner découverte", "Diagnostic stratégique"],
    "durations":             { "30": "30 min", "60": "60 min" },
    "products":              { "12": "Masterclass Premium", "15": "Session 1h" },
    "genders":               ["Homme", "Femme"],
    "customers":             { "client@ex.fr": "client@ex.fr" }
  }
}
```

---

## Endpoints de synchronisation (lignes brutes → BigQuery)

Les quatre endpoints `/sync/*` retournent des **lignes plates** prêtes à être insérées dans BigQuery sans transformation. Chaque ligne contient `site_id` en première position pour l'agrégation multi-sites.

### Paramètres communs

| Paramètre | Type | Description |
|---|---|---|
| `updated_after` | string | ISO 8601. Retourne uniquement les enregistrements créés/modifiés **après** cette date. Laisser vide pour un export complet. |
| `per_page` | integer | Lignes par page. Max 500, défaut 200. |
| `page` | integer | Numéro de page (base 1). Défaut 1. |

### Enveloppe de réponse

```json
{
  "meta": {
    "site_id":      "entourage",
    "site_url":     "https://entourage.fr",
    "total":        1234,
    "page":         1,
    "per_page":     200,
    "total_pages":  7,
    "generated_at": "2026-06-02T10:00:00+00:00",
    "source":       "wp_user_masterclass"
  },
  "rows": [
    { "site_id": "entourage", "id": 123, "user_id": 45, "..." : "..." },
    { "site_id": "entourage", "id": 124, "..." : "..." }
  ]
}
```

Pour parcourir toutes les pages dans n8n, boucler tant que `meta.page < meta.total_pages`.

---

### `GET /sync/masterclass-registrations`

**Table BigQuery cible :** `masterclass_registrations`

```http
GET /wp-json/analytic-suite/v1/sync/masterclass-registrations?updated_after=2026-06-01T00:00:00Z&per_page=200
```

**Schéma d'une ligne :**

```json
{
  "site_id":            "entourage",
  "id":                 123,
  "user_id":            45,
  "post_id":            789,
  "masterclass_title":  "Session leadership avancé",
  "registered_at":      "2026-05-15T14:30:00+00:00",
  "user_email":         "jean.dupont@exemple.fr",
  "user_display_name":  "Jean Dupont",
  "user_registered_at": "2025-01-10T09:00:00+00:00",
  "user_experience":    "5-10 ans",
  "user_gender":        "homme",
  "user_disability":    null
}
```

| Champ | Type BQ | Source |
|---|---|---|
| `site_id` | STRING | Option `analytic_suite_site_id` |
| `id` | INTEGER | `wp_user_masterclass.id` |
| `user_id` | INTEGER | `wp_user_masterclass.user_id` |
| `post_id` | INTEGER | `wp_user_masterclass.post_id` |
| `masterclass_title` | STRING | `wp_posts.post_title` |
| `registered_at` | TIMESTAMP | `wp_user_masterclass.created_at` |
| `user_email` | STRING | `wp_users.user_email` |
| `user_display_name` | STRING | `wp_users.display_name` |
| `user_registered_at` | TIMESTAMP | `wp_users.user_registered` |
| `user_experience` | STRING | `wp_usermeta.field_experience` |
| `user_gender` | STRING | `wp_usermeta.genders` |
| `user_disability` | STRING | `wp_usermeta.handicap` |

---

### `GET /sync/expert-sessions`

**Table BigQuery cible :** `expert_sessions`

```http
GET /wp-json/analytic-suite/v1/sync/expert-sessions?updated_after=2026-06-01T00:00:00Z&per_page=200
```

**Schéma d'une ligne :**

```json
{
  "site_id":         "entourage",
  "booking_id":      456,
  "order_id":        789,
  "user_email":      "marie.martin@exemple.fr",
  "service_label":   "Session stratégique 1h",
  "category":        "Session",
  "status":          "completed",
  "duration_minutes": 60,
  "scheduled_at":    "2026-05-20T10:00:00+00:00",
  "country":         "FR",
  "gender":          "Femme",
  "created_at":      "2026-05-10T08:00:00+00:00",
  "updated_at":      "2026-05-20T11:05:00+00:00"
}
```

| Champ | Type BQ | Source |
|---|---|---|
| `site_id` | STRING | Option `analytic_suite_site_id` |
| `booking_id` | INTEGER | Table FluentBooking |
| `order_id` | INTEGER | `wp_woocommerce_order_items` via `__fcal_booking_id` |
| `user_email` | STRING | FluentBooking ou commande WC |
| `service_label` | STRING | Nom de l'événement FluentBooking ou produit WC |
| `category` | STRING | `Session` \| `Dîner` \| `Diagnostic stratégique` \| `Autres` |
| `status` | STRING | Statut FluentBooking ou statut WC |
| `duration_minutes` | INTEGER | `slot_minutes` ou calculé (end − start) |
| `scheduled_at` | TIMESTAMP | `start_at` |
| `country` | STRING | FluentBooking ou facturation WC |
| `gender` | STRING | Métadonnée WC `gender_` |
| `created_at` | TIMESTAMP | `created_at` |
| `updated_at` | TIMESTAMP | `updated_at` \| `modified_at` \| `created_at` |

> `updated_after` s'applique sur `updated_at` si la colonne existe, sinon sur `created_at`.

---

### `GET /sync/orders`

**Table BigQuery cible :** `wc_orders`

```http
GET /wp-json/analytic-suite/v1/sync/orders?updated_after=2026-06-01T00:00:00Z&per_page=200
```

**Schéma d'une ligne :**

```json
{
  "site_id":         "entourage",
  "order_id":        1042,
  "status":          "completed",
  "total":           150.00,
  "currency":        "EUR",
  "billing_email":   "client@exemple.fr",
  "billing_country": "FR",
  "gender":          "Homme",
  "created_at":      "2026-05-10T08:00:00+00:00",
  "modified_at":     "2026-05-10T09:15:00+00:00",
  "items_json":      "[{\"product_id\":12,\"name\":\"Masterclass Premium\",\"quantity\":1,\"total\":150.00}]"
}
```

| Champ | Type BQ | Source |
|---|---|---|
| `site_id` | STRING | Option `analytic_suite_site_id` |
| `order_id` | INTEGER | `wc_orders.id` |
| `status` | STRING | Statut WC sans préfixe (`completed`, `cancelled`…) |
| `total` | FLOAT | Montant total TTC |
| `currency` | STRING | Devise ISO 4217 |
| `billing_email` | STRING | Email de facturation |
| `billing_country` | STRING | Code pays ISO 3166-1 |
| `gender` | STRING | Métadonnée commande `gender_` |
| `created_at` | TIMESTAMP | Date de création |
| `modified_at` | TIMESTAMP | Date de modification |
| `items_json` | STRING | JSON des lignes (parseable avec `JSON_EXTRACT` en BigQuery) |

> `updated_after` s'applique sur `date_modified`.

---

### `GET /sync/users`

**Table BigQuery cible :** `wp_users`

```http
GET /wp-json/analytic-suite/v1/sync/users?updated_after=2026-06-01T00:00:00Z&per_page=200
```

**Schéma d'une ligne :**

```json
{
  "site_id":           "entourage",
  "user_id":           45,
  "user_email":        "jean.dupont@exemple.fr",
  "user_display_name": "Jean Dupont",
  "registered_at":     "2025-01-10T09:00:00+00:00",
  "experience":        "5-10 ans",
  "gender":            "homme",
  "disability":        null,
  "last_login":        "2026-05-30T18:45:22+00:00"
}
```

| Champ | Type BQ | Source |
|---|---|---|
| `site_id` | STRING | Option `analytic_suite_site_id` |
| `user_id` | INTEGER | `wp_users.ID` |
| `user_email` | STRING | `wp_users.user_email` |
| `user_display_name` | STRING | `wp_users.display_name` |
| `registered_at` | TIMESTAMP | `wp_users.user_registered` |
| `experience` | STRING | `wp_usermeta.field_experience` |
| `gender` | STRING | `wp_usermeta.genders` |
| `disability` | STRING | `wp_usermeta.handicap` |
| `last_login` | STRING | `wp_usermeta.last_login` |

> `updated_after` filtre sur `user_registered` (nouveaux inscrits). Pour les mises à jour de meta, planifier un sync complet quotidien sans `updated_after`.

---

## Endpoints utilitaires

### `GET /status`

Health check pour n8n. Retourne l'identité du site et l'état de chaque intégration.

```json
{
  "site_id":        "entourage",
  "site_name":      "Entourage",
  "site_url":       "https://entourage.fr",
  "plugin_version": "0.1.3",
  "generated_at":   "2026-06-02T10:00:00+00:00",
  "last_sync":      "2026-06-02T09:00:00",
  "integrations": {
    "woocommerce":      { "available": true },
    "fluentbooking":    { "available": true, "table": "wp_fcal_bookings" },
    "user_masterclass": { "available": true },
    "user_livres":      { "available": false },
    "google_analytics": { "configured": true, "property_id": "123456789", "last_error": "" }
  },
  "sync_endpoints": [
    "analytic-suite/v1/sync/masterclass-registrations",
    "analytic-suite/v1/sync/expert-sessions",
    "analytic-suite/v1/sync/orders",
    "analytic-suite/v1/sync/users"
  ]
}
```

---

### `DELETE /google-analytics/cache`

Vide le cache GA4 (transients WordPress). Requiert `analytic_suite_manage_analytics`.

```json
{ "success": true, "message": "Cache Google Analytics vidé." }
```

---

## Configuration n8n — multi-sites

### Credentials (une par site)

Créer dans n8n une credential **HTTP Basic Auth** par site :

| Site | URL de base | Utilisateur | Mot de passe |
|---|---|---|---|
| Entourage | `https://entourage.fr/wp-json/analytic-suite/v1` | `n8n-sync` | Application Password WP |
| Étrelabs | `https://etrelabs.fr/wp-json/analytic-suite/v1` | `n8n-sync` | Application Password WP |
| Site C | `https://sitec.fr/wp-json/analytic-suite/v1` | `n8n-sync` | Application Password WP |

### Workflow recommandé

```
Trigger : Schedule (toutes les heures)
    ↓
Code node : définir la liste des sites
  sites = [
    { id: "entourage", baseUrl: "https://entourage.fr/wp-json/analytic-suite/v1", credentialId: "..." },
    { id: "etrelabs",  baseUrl: "https://etrelabs.fr/wp-json/analytic-suite/v1",  credentialId: "..." }
  ]
    ↓
Loop Over Items (un item = un site)
    ↓
    GET {baseUrl}/status
      → vérifier que les intégrations nécessaires sont disponibles
      → si non : Slack/email d'alerte + continuer avec le site suivant
    ↓
    Pour chaque endpoint sync :
      Boucle pages :
        GET {baseUrl}/sync/{endpoint}
          ?updated_after={{ $now.minus(1, "hour").toISO() }}
          &per_page=200
          &page={{ $pageNum }}
            ↓
          BigQuery Insert Rows
          (chaque ligne contient déjà site_id — pas de transformation nécessaire)
            ↓
          Si meta.page < meta.total_pages → incrémenter page et reboucler
```

### Expression `updated_after`

```js
// Dans le nœud HTTP Request :
{{ $now.minus(1, "hour").toISO() }}
// Exemple : 2026-06-02T09:00:00.000Z
```

Pour le **sync complet initial** (premier lancement), ne pas passer `updated_after` et boucler sur toutes les pages.

---

## Schémas BigQuery (multi-sites)

Le champ `site_id STRING` est présent dans toutes les tables. Il permet de filtrer ou croiser les données par site dans Looker Studio.

```sql
-- masterclass_registrations
CREATE TABLE IF NOT EXISTS `projet.dataset.masterclass_registrations` (
  site_id            STRING    NOT NULL,
  id                 INT64,
  user_id            INT64,
  post_id            INT64,
  masterclass_title  STRING,
  registered_at      TIMESTAMP,
  user_email         STRING,
  user_display_name  STRING,
  user_registered_at TIMESTAMP,
  user_experience    STRING,
  user_gender        STRING,
  user_disability    STRING
)
PARTITION BY DATE(registered_at)
CLUSTER BY site_id;

-- expert_sessions
CREATE TABLE IF NOT EXISTS `projet.dataset.expert_sessions` (
  site_id          STRING    NOT NULL,
  booking_id       INT64,
  order_id         INT64,
  user_email       STRING,
  service_label    STRING,
  category         STRING,
  status           STRING,
  duration_minutes INT64,
  scheduled_at     TIMESTAMP,
  country          STRING,
  gender           STRING,
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP
)
PARTITION BY DATE(scheduled_at)
CLUSTER BY site_id, category;

-- wc_orders
CREATE TABLE IF NOT EXISTS `projet.dataset.wc_orders` (
  site_id         STRING    NOT NULL,
  order_id        INT64,
  status          STRING,
  total           FLOAT64,
  currency        STRING,
  billing_email   STRING,
  billing_country STRING,
  gender          STRING,
  created_at      TIMESTAMP,
  modified_at     TIMESTAMP,
  items_json      STRING
)
PARTITION BY DATE(created_at)
CLUSTER BY site_id, status;

-- wp_users
CREATE TABLE IF NOT EXISTS `projet.dataset.wp_users` (
  site_id           STRING    NOT NULL,
  user_id           INT64,
  user_email        STRING,
  user_display_name STRING,
  registered_at     TIMESTAMP,
  experience        STRING,
  gender            STRING,
  disability        STRING,
  last_login        STRING
)
PARTITION BY DATE(registered_at)
CLUSTER BY site_id;
```

> Les tables sont **partitionnées** par date et **clusterisées** par `site_id` pour optimiser les coûts de requête dans BigQuery lorsque Looker Studio filtre par site ou par période.

> Les données GA4 alimentent BigQuery via l'**export natif Google** (connexion directe GA4 → BigQuery dans la console GA4), indépendamment de ce plugin.
