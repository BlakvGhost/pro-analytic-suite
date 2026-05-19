# Brief technique frontend Nuxt - Pro Analytics Suite

## Objectif du projet

Le frontend Nuxt doit remplacer ou compléter l'interface WordPress actuelle de Pro Analytics Suite avec une application moderne, rapide et maintenable.

L'application consommera l'API REST du plugin WordPress `analytic-suite` pour afficher les donnees analytics suivantes :

- ventes WooCommerce,
- reservations FluentBooking,
- contenus suivis,
- donnees Google Analytics 4,
- indicateurs clients,
- rapports et donnees publiques.

Le frontend ne doit pas recalculer les metriques metier. Le plugin WordPress reste la source de verite : il collecte, filtre, agrege et securise les donnees. Nuxt est responsable de l'experience utilisateur, de l'affichage, de la navigation, des graphiques et des interactions.

## Stack cible

- Framework : Nuxt 3
- Langage recommande : TypeScript
- Donnees : WordPress REST API
- Graphiques : Chart.js, ECharts ou ApexCharts
- UI : composants Nuxt/Vue reutilisables
- Authentification : session WordPress avec nonce REST ou Application Passwords selon le mode de deploiement

## API REST

Base URL :

```text
/wp-json/analytic-suite/v1
```

Documentation detaillee :

```text
REST_API.md
```

Endpoints principaux :

| Endpoint | Usage frontend |
| --- | --- |
| `GET /dashboard` | Charge toutes les donnees pour une vue globale. |
| `GET /summary` | Charge uniquement les KPI principaux. |
| `GET /clients` | Vue clients, retention, civilite, pays, emails. |
| `GET /orders` | Vue commandes WooCommerce. |
| `GET /bookings` | Vue reservations FluentBooking. |
| `GET /contents` | Vue contenus, masterclass et livres. |
| `GET /google-analytics` | Vue GA4 complete. |
| `GET /ga` | Alias court de `/google-analytics`. |
| `GET /reports` | Donnees completes pour une page rapport. |
| `GET /export-rows` | Lignes de synthese d'export en JSON. |
| `GET /status` | Etat des integrations et version plugin. |
| `GET /filters` | Options de filtres pour construire les selects. |
| `GET /public` | Donnees du dashboard public. |

Tous les endpoints GET acceptent les filtres suivants :

```ts
type AnalyticSuiteFilters = {
  period?: 'all' | '7-days' | '30-days' | 'year' | 'custom'
  date_from?: string
  date_to?: string
  country?: string
  status?: string
  booking_type?: string
  exclude_booking_type?: string
  duration?: number
  product?: number
  gender?: string
  customer?: string
  page_path?: string
}
```

## Authentification et securite

Les endpoints de lecture demandent la capacite WordPress :

```text
analytic_suite_view_analytics
```

Les actions de gestion demandent :

```text
analytic_suite_manage_analytics
```

Si Nuxt est servi dans le meme domaine que WordPress, utiliser le nonce REST :

```http
X-WP-Nonce: <nonce>
```

Si Nuxt est heberge separement, utiliser une methode compatible WordPress REST API, par exemple Application Passwords. Dans ce cas, prevoir une configuration d'environnement :

```env
NUXT_PUBLIC_WP_API_BASE=https://example.com/wp-json/analytic-suite/v1
WP_APPLICATION_USERNAME=
WP_APPLICATION_PASSWORD=
```

Ne jamais exposer les identifiants serveur dans le code client. Les appels authentifies sensibles doivent passer par des routes serveur Nuxt.

## Architecture Nuxt recommandee

Structure proposee :

```text
app.vue
pages/
  index.vue
  clients.vue
  commandes.vue
  reservations.vue
  contenus.vue
  google-analytics.vue
  rapports.vue
  public.vue
components/
  analytics/
    StatCard.vue
    FilterBar.vue
    ChartPanel.vue
    BreakdownTable.vue
    IntegrationStatus.vue
    ExportRowsTable.vue
composables/
  useAnalyticsApi.ts
  useAnalyticsFilters.ts
  useFormatters.ts
types/
  analytics.ts
server/
  api/
    analytics/
      [...path].get.ts
      ga-cache.delete.ts
```

Principe :

- `useAnalyticsApi.ts` centralise les appels API.
- `useAnalyticsFilters.ts` gere les filtres, la synchronisation query string et les valeurs par defaut.
- `types/analytics.ts` contient les types des reponses REST.
- Les pages restent minces : chargement des donnees, composition des composants, gestion des etats.
- Les composants restent purs autant que possible.

## Pages attendues

### Dashboard

Premiere vue de travail, pas une landing page.

Contenus attendus :

- cartes KPI : clients uniques, commandes, reservations, chiffre d'affaires, panier moyen, clients produits repetes,
- graphique activite principale,
- graphique contenus par mois,
- resume reservations,
- resume contenus,
- tableaux de details operationnels.

Endpoint conseille :

```text
GET /dashboard
```

### Clients

Contenus attendus :

- clients uniques,
- clients recurrents,
- clients ayant repris un produit,
- taux de fidelisation,
- repartition pays reservations,
- repartitions civilite commandes/reservations.

Endpoint conseille :

```text
GET /clients
```

### Commandes

Contenus attendus :

- commandes totales,
- paniers annules,
- chiffre d'affaires,
- panier moyen,
- commandes par statut,
- ventes par produit,
- chiffre d'affaires par produit,
- tableau produits.

Endpoint conseille :

```text
GET /orders
```

### Reservations

Contenus attendus :

- total reservations,
- reservations validees,
- reservations annulees,
- taux d'annulation,
- duree dominante,
- types de reservation,
- categories,
- durees,
- statuts.

Endpoint conseille :

```text
GET /bookings
```

### Contenus

Contenus attendus :

- masterclass publiees,
- masterclass suivies,
- utilisateurs masterclass,
- masterclass avec replay,
- masterclass a venir,
- livres publies,
- livres consultes,
- utilisateurs livres,
- top masterclass,
- top livres,
- repartition mensuelle.

Endpoint conseille :

```text
GET /contents
```

### Google Analytics

Contenus attendus :

- utilisateurs actifs,
- sessions,
- pages vues,
- nouveaux utilisateurs,
- duree moyenne,
- taux de rebond,
- utilisateurs temps reel si disponible,
- sources de trafic,
- appareils,
- top pages,
- villes, pays, ages, genres.

Endpoint conseille :

```text
GET /google-analytics
```

### Rapports

Contenus attendus :

- synthese complete,
- donnees filtrees,
- tableau des lignes exportables,
- bouton de generation cote frontend si necessaire.

Endpoint conseille :

```text
GET /reports
```

### Statut integrations

Contenus attendus :

- WooCommerce detecte ou non,
- FluentBooking detecte ou non,
- table FluentBooking detectee,
- tables contenus detectees,
- statut GA4,
- derniere synchronisation,
- version du plugin.

Endpoint conseille :

```text
GET /status
```

## Filtres frontend

Le frontend doit charger les options via :

```text
GET /filters
```

Champs UI attendus :

- periode : select ou segmented control,
- dates personnalisées : inputs date,
- pays : select,
- statut : select,
- type reservation : select,
- exclusion type reservation : select,
- duree : select,
- produit : select,
- civilite : select,
- client : select searchable.

Les filtres doivent etre synchronises dans l'URL afin de permettre le partage d'une vue filtree.

Exemple :

```text
/reservations?period=custom&date_from=2026-01-01&date_to=2026-05-19&duration=60
```

## Etats UI requis

Chaque page doit gerer :

- chargement initial,
- rechargement apres changement de filtre,
- etat vide,
- erreur API,
- integration indisponible,
- donnees partielles,
- permission refusee.

Les champs `available` renvoyes par l'API doivent etre affiches clairement. Exemple : si WooCommerce est indisponible, la page Commandes doit montrer un message de statut plutot qu'une interface vide.

## Charte graphique

La charte actuelle du plugin WordPress repose sur une interface analytics premium, sobre et professionnelle.

### Couleurs principales

Valeurs par defaut du plugin :

```css
:root {
  --as-primary: #0f766e;
  --as-accent: #d69a3a;
  --as-header-bg: #10231f;
  --as-surface: #ffffff;
}
```

Recommandation Nuxt :

```css
:root {
  --color-primary: #0f766e;
  --color-primary-dark: #0c5f59;
  --color-accent: #d69a3a;
  --color-ink: #10231f;
  --color-muted: #5f6f6a;
  --color-border: #dbe7e2;
  --color-bg: #f5f8f6;
  --color-surface: #ffffff;
  --color-danger: #b42318;
  --color-warning: #b7791f;
  --color-success: #0f766e;
}
```

### Direction visuelle

- Interface dense, claire et orientee travail.
- Pas de landing page marketing comme premier ecran.
- Priorite aux donnees, filtres, graphiques et tableaux.
- Cartes sobres, rayon maximum 8px.
- Pas de decorations abstraites inutiles.
- Espacements reguliers, grilles lisibles, hierarchie typographique nette.
- Les couleurs accent servent aux mises en evidence, pas aux grands aplats dominants.

### Typographie

Recommandation :

- Police systeme ou Inter.
- Titres de page : 28-34px desktop, 24-28px mobile.
- Titres de section : 18-22px.
- Labels et metadonnees : 12-14px.
- Valeurs KPI : 26-36px selon le contexte.

Ne pas utiliser de tailles basees sur la largeur viewport. Les textes doivent rester lisibles et ne jamais deborder de leurs conteneurs.

### Composants visuels

#### StatCard

Doit contenir :

- label court,
- valeur principale,
- note optionnelle,
- variation optionnelle si disponible plus tard.

Style :

- fond blanc,
- bordure legere,
- rayon 8px,
- ombre tres subtile ou aucune ombre,
- valeur bien visible.

#### FilterBar

Doit etre visible sur les pages analytics.

Comportement :

- compact sur desktop,
- repliable sur mobile,
- bouton "Appliquer",
- bouton "Reinitialiser",
- conservation dans l'URL.

#### ChartPanel

Doit contenir :

- titre,
- graphique,
- fallback lisible si aucune donnee,
- legende claire,
- formatage des nombres.

#### Tables

Attentes :

- colonnes compactes,
- alignement numerique a droite,
- lignes zebra ou bordures legeres,
- scroll horizontal sur mobile,
- labels echappes et tronques proprement si trop longs.

## Responsive

Breakpoints conseilles :

```css
sm: 640px
md: 768px
lg: 1024px
xl: 1280px
```

Comportement :

- mobile : une colonne, filtres repliables, tableaux scrollables,
- tablette : deux colonnes pour les KPI,
- desktop : quatre a six cartes KPI par ligne selon la largeur,
- graphiques : hauteur stable pour eviter les sauts de layout.

## Formatage des donnees

Prevoir des helpers :

```ts
formatNumber(value: number): string
formatCurrency(value: number): string
formatPercent(value: number): string
formatDate(value: string): string
```

Devise par defaut a confirmer selon WooCommerce. En attendant, le frontend doit permettre une configuration :

```env
NUXT_PUBLIC_ANALYTICS_CURRENCY=EUR
NUXT_PUBLIC_ANALYTICS_LOCALE=fr-FR
```

## Performance

Recommandations :

- Appeler `/filters` une seule fois au chargement initial ou avec cache court.
- Utiliser les endpoints specialises pour les pages dediees.
- Eviter `/dashboard` sur toutes les pages si seules les commandes ou reservations sont necessaires.
- Debouncer les filtres textuels.
- Afficher les donnees precedentes pendant le rechargement filtre si cela ameliore la perception.

## Accessibilite

Attentes :

- contraste suffisant,
- focus visible,
- labels associes aux champs,
- boutons avec texte explicite,
- tableaux avec headers,
- graphiques accompagnes d'une version textuelle ou tabulaire.

## Gestion des erreurs API

Cas a traiter :

- `401` ou `403` : utilisateur non autorise,
- `404` : route API indisponible ou plugin inactif,
- `500` : erreur serveur WordPress,
- GA4 configure mais erreur `last_error`,
- source metier indisponible (`available: false`).

Les erreurs doivent etre affichees en francais, avec un message sobre et actionnable.

## Variables d'environnement proposees

```env
NUXT_PUBLIC_WP_API_BASE=https://example.com/wp-json/analytic-suite/v1
NUXT_PUBLIC_ANALYTICS_LOCALE=fr-FR
NUXT_PUBLIC_ANALYTICS_CURRENCY=XOF
```

Si le projet utilise un proxy serveur Nuxt :

```env
WP_API_BASE=https://example.com/wp-json/analytic-suite/v1
WP_APPLICATION_USERNAME=
WP_APPLICATION_PASSWORD=
```

## Critere de livraison frontend

Le projet Nuxt sera considere pret quand :

- toutes les pages principales consomment les endpoints REST,
- les filtres fonctionnent et restent synchronises dans l'URL,
- les etats de chargement, vide, erreur et permission sont geres,
- le responsive mobile/tablette/desktop est valide,
- les graphiques ont des fallbacks ou tableaux lisibles,
- la charte graphique est coherente avec les couleurs du plugin,
- aucune cle secrete n'est exposee cote client,
- le build Nuxt passe sans erreur.

## References internes

- Plugin WordPress : `analytic-suite`
- API REST : `REST_API.md`
- Bootstrap plugin : `analytic-suite.php`
- Service metier principal : `includes/class-analytic-suite-dashboard-service.php`
- Controleur REST : `includes/class-analytic-suite-rest-controller.php`
