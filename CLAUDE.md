# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

**Pro Analytics Suite** is a WordPress plugin (v0.1.3) acting as a **data extraction layer** for a BigQuery analytics pipeline:

```
WordPress (WooCommerce / FluentBooking / custom tables)
    → REST API (this plugin)
    → n8n job (hourly)
    → BigQuery (masterclass_registrations · expert_sessions · wc_orders · wp_users)
    → Keycloak (ori.cyberka.com) → Looker Studio
```

GA4 data goes to BigQuery via Google's native export — not through this plugin.

The plugin has **no graphical dashboard**. The only admin UI is a Settings page (integration status + GA4 OAuth2 config).

No Composer or npm is used. All dependencies are WordPress core, WooCommerce/FluentBooking (runtime optionals), or GA4 Data API v1 via cURL.

## Development Commands

```bash
# PHP syntax validation (no build step required)
php -l analytic-suite.php
php -l includes/class-analytic-suite.php
php -l admin/class-analytic-suite-admin.php

# Lint all PHP files at once
find . -name "*.php" -not -path "./.git/*" | xargs -I{} php -l {}

# Plugin activation via WP-CLI
wp plugin activate analytic-suite

# No test runner is configured yet — tests/ directory is empty
```

## Architecture

### Data Flow

```
WordPress / WooCommerce / FluentBooking / Custom Tables
    ↓
Repository Layer  (includes/repositories/)
    ↓
REST Controller   (includes/class-analytic-suite-rest-controller.php)
    ↓  ← consumed by n8n hourly job
BigQuery
```

### Key Classes

| Class | File | Role |
|---|---|---|
| `Analytic_Suite` | `includes/class-analytic-suite.php` | Bootstrap: loads all classes, registers WP hooks |
| `Analytic_Suite_Dashboard_Service` | `includes/class-analytic-suite-dashboard-service.php` | Aggregates metrics; used only for GA cache clear |
| `Analytic_Suite_Admin` | `admin/class-analytic-suite-admin.php` | Settings page only (integration status + GA4 config) |
| `Analytic_Suite_REST_Controller` | `includes/class-analytic-suite-rest-controller.php` | 4 sync endpoints + `/status` + GA cache clear |
| `Analytic_Suite_Order_Repository` | `includes/repositories/` | `get_metrics()` (aggregated) + `get_export_rows()` (flat, paginated) |
| `Analytic_Suite_Booking_Repository` | `includes/repositories/` | `get_metrics()` + `get_export_rows()` (flat, paginated) |
| `Analytic_Suite_Content_Repository` | `includes/repositories/` | `get_metrics()` + `get_masterclass_rows()` (flat, paginated) |
| `Analytic_Suite_User_Repository` | `includes/repositories/` | `get_export_rows()` for WordPress users + profile meta |
| `Analytic_Suite_Google_Analytics` | `includes/services/` | GA4 Data API v1 via OAuth2; WP transient caching |
| `Analytic_Suite_Activator` | `includes/` | Grants capabilities, creates `wp_analytic_suite_snapshots` table |

### REST API

- Base namespace: `/wp-json/analytic-suite/v1`
- Authentication: WordPress Application Passwords (Basic Auth)
- Read permission: `analytic_suite_view_analytics`
- Manage permission: `analytic_suite_manage_analytics`
- Full reference: `REST_API.md`

#### Sync endpoints (n8n → BigQuery)

| Endpoint | BigQuery target | Filter column |
|---|---|---|
| `GET /sync/masterclass-registrations` | `masterclass_registrations` | `created_at` |
| `GET /sync/expert-sessions` | `expert_sessions` | `updated_at` or `created_at` |
| `GET /sync/orders` | `wc_orders` | `date_modified` |
| `GET /sync/users` | `wp_users` | `user_registered` |

All sync endpoints accept `updated_after` (ISO 8601), `per_page` (max 500, default 200), `page`.

Response envelope: `{ meta: { total, page, per_page, total_pages, generated_at, source }, rows: [...] }`.

#### Utility endpoints

| Endpoint | Purpose |
|---|---|
| `GET /status` | Health check for n8n; reports integration availability |
| `DELETE /google-analytics/cache` | Clears GA4 transient cache |

### Plugin Constants

Defined in `analytic-suite.php` and available everywhere:
- `ANALYTIC_SUITE_VERSION` — current version string
- `ANALYTIC_SUITE_FILE` — absolute path to the main plugin file
- `ANALYTIC_SUITE_PATH` — absolute path to the plugin directory (with trailing slash)
- `ANALYTIC_SUITE_URL` — URL to the plugin directory (with trailing slash)

### WordPress Options

All options use the `analytic_suite_` prefix:
- `analytic_suite_ga_property_id` — GA4 property ID
- `analytic_suite_ga_client_id`, `analytic_suite_ga_client_secret`, `analytic_suite_ga_refresh_token` — OAuth2 credentials for GA4 API
- `analytic_suite_color_primary/accent/header/surface`, `analytic_suite_header_badge` — appearance
- `analytic_suite_version`, `analytic_suite_last_sync` — internal state

### Transients

GA4 API responses are cached using transients keyed as `analytic_suite_ga_<md5(filters)>`. Clear via `DELETE /google-analytics/cache`.

### Capabilities

Granted on activation to `administrator` and `shop_manager` roles:
- `analytic_suite_view_analytics`
- `analytic_suite_manage_analytics`

### Booking Repository

`Analytic_Suite_Booking_Repository` is defensive by design: it auto-detects the FluentBooking table name at runtime (`find_booking_table()`), introspects available columns, and merges results with a WooCommerce order metadata fallback (`get_booking_rows_from_orders()`). When adding booking filters, use `value_from_columns()` to read fields rather than hardcoding column names.

The new `get_export_rows()` uses `updated_at` (or `modified_at`, then `created_at`) for the `updated_after` filter. If none of these columns exist in the detected table, no time filter is applied.

### Dashboard Service Instantiation

`Analytic_Suite::get_dashboard_service()` creates a **new** `Analytic_Suite_Dashboard_Service` (and fresh repository instances) on every call — there is no singleton. The REST controller receives its own instance.

### WP-Cron

The `analytic_suite_daily_sync` hook is registered but currently only updates `analytic_suite_last_sync`. It is a placeholder for future precomputed analytics.

## Naming Conventions

- PHP classes: `Analytic_Suite_*` (e.g., `Analytic_Suite_User_Repository`)
- WordPress options, hooks, transients, tables: `analytic_suite_` prefix

## Security Checklist

- Sanitize all user input with `sanitize_text_field()`, `absint()`, `sanitize_key()`, etc.
- Escape all output with `esc_html()`, `esc_attr()`, `esc_url()`
- GA settings form uses `check_admin_referer('analytic_suite_ga_settings')` nonce
- REST endpoints enforce capability checks in the `permission_callback`
- GA4 OAuth2 credentials are stored in `wp_options` — never expose them in API responses
