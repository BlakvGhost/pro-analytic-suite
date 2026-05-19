# Documentation API REST - Pro Analytics Suite

Base URL :

```text
/wp-json/analytic-suite/v1
```

L'API expose les donnees utilisees par le dashboard WordPress afin de permettre la creation d'un frontend separe.

## Authentification

Les endpoints de lecture demandent la capacite WordPress `analytic_suite_view_analytics`.

Les actions de gestion demandent `analytic_suite_manage_analytics`.

Depuis un frontend connecte a WordPress, envoyer le nonce REST :

```http
X-WP-Nonce: <nonce>
```

Depuis une application externe, utiliser une methode supportee par WordPress REST API, par exemple les Application Passwords.

## Filtres

Tous les endpoints GET acceptent les memes filtres :

| Parametre | Type | Description |
| --- | --- | --- |
| `period` | string | `all`, `7-days`, `30-days`, `year`, `custom`. Defaut : `all`. |
| `date_from` | string | Date de debut `YYYY-MM-DD`. |
| `date_to` | string | Date de fin `YYYY-MM-DD`. |
| `country` | string | Filtre pays sur commandes/reservations quand disponible. |
| `status` | string | Statut WooCommerce ou FluentBooking. |
| `booking_type` | string | Type/categorie de reservation a inclure. |
| `exclude_booking_type` | string | Type/categorie de reservation a exclure. |
| `duration` | integer | Duree de reservation en minutes, ex. `30` ou `60`. |
| `product` | integer | ID produit WooCommerce. |
| `gender` | string | Civilite/genre, ex. `Homme`, `Femme`, `monsieur`, `madame`. |
| `customer` | string | Email client. |
| `page_path` | string | Chemin de page pour filtrer GA4, ex. `/formations/`. |

Exemple :

```http
GET /wp-json/analytic-suite/v1/dashboard?period=custom&date_from=2026-01-01&date_to=2026-05-19&country=BJ
```

## Endpoints

### `GET /dashboard`

Retourne toutes les donnees du dashboard.

```json
{
  "filters": {
    "period": "30-days",
    "date_from": "2026-04-20",
    "date_to": "2026-05-19"
  },
  "data": {
    "summary": {},
    "orders": {},
    "bookings": {},
    "contents": {},
    "ga": {},
    "ga_status": {},
    "generated_at": "2026-05-19 12:00:00"
  }
}
```

### `GET /summary`

Retourne les indicateurs globaux : commandes, reservations, chiffre d'affaires, panier moyen, clients, annulations, contenus et indicateurs GA4 principaux.

### `GET /clients`

Retourne les donnees utiles a une vue clients : clients uniques, clients recurrents, clients ayant repris un produit, taux de fidelisation, repartitions pays/civilite et emails clients.

### `GET /orders`

Retourne les donnees WooCommerce : commandes, chiffre d'affaires, panier moyen, clients, ventes par pays, ventes par produit, statuts, civilites et emails.

### `GET /bookings`

Retourne les donnees FluentBooking et les reservations liees aux commandes WooCommerce : totaux, annulations, statuts, types, categories, durees, pays, civilites et emails.

### `GET /contents`

Retourne les donnees de contenus : masterclass, livres, suivis, consultations, utilisateurs uniques, tops contenus, repartition mensuelle, replays et contenus a venir.

### `GET /google-analytics`

Alias : `GET /ga`.

Retourne les donnees GA4 : statut de configuration, synthese, pages les plus vues, demographics, sources de trafic et temps reel.

Exemple avec filtre page :

```http
GET /wp-json/analytic-suite/v1/ga?period=30-days&page_path=/ma-page/
```

### `GET /reports`

Retourne les donnees completes du dashboard avec `export_rows`, utile pour construire une page de rapport ou une previsualisation d'export.

### `GET /export-rows`

Retourne uniquement les lignes de synthese actuellement utilisees par les exports CSV/Excel/PDF, au format JSON.

### `GET /status`

Retourne l'etat des integrations :

- WooCommerce,
- FluentBooking,
- tables de contenus,
- Google Analytics,
- derniere synchronisation,
- version du plugin.

### `GET /filters`

Retourne les filtres normalises et les options disponibles pour construire l'interface de filtrage.

```json
{
  "filters": {},
  "options": {
    "countries": [],
    "statuses": [],
    "booking_types": [],
    "exclude_booking_types": [],
    "durations": {},
    "products": {},
    "genders": [],
    "customers": {}
  }
}
```

### `GET /public`

Retourne les donnees utilisees par le shortcode public `[analytics_public]` : statistiques demographiques WordPress, page GA4 publique configuree et donnees GA4 filtrees sur cette page.

### `DELETE /google-analytics/cache`

Vide le cache Google Analytics. Capacite requise : `analytic_suite_manage_analytics`.

```json
{
  "success": true,
  "message": "Cache Google Analytics vidé."
}
```

## Exemple JavaScript WordPress

```js
const response = await fetch('/wp-json/analytic-suite/v1/dashboard?period=30-days', {
  headers: {
    'X-WP-Nonce': window.wpApiSettings.nonce
  }
});

const payload = await response.json();
console.log(payload.data.summary);
```

## Notes frontend

- Utiliser `GET /filters` au chargement pour remplir les selects.
- Utiliser `GET /dashboard` si une page a besoin de toutes les donnees.
- Utiliser les endpoints de section (`/orders`, `/bookings`, `/contents`, `/ga`) pour limiter le volume quand une vue est specialisee.
- Les champs `available` indiquent si WooCommerce, FluentBooking ou les tables de contenus sont detectes.
