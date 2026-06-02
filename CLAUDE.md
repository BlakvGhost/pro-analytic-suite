# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

**Pro Analytics Suite** is a WordPress plugin (v0.1.3) that aggregates analytics data from WooCommerce orders, FluentBooking bookings, custom content tables, and Google Analytics 4. It exposes data via a REST API and renders an admin dashboard with charts and export capabilities.

No Composer or npm is used. All dependencies are either WordPress core, WooCommerce/FluentBooking (runtime optionals), or the GA4 Data API v1 called directly via cURL.

## Development Commands

```bash
# PHP syntax validation (no build step required)
php -l analytic-suite.php
php -l includes/class-analytic-suite.php
php -l admin/class-analytic-suite-admin.php
php -l includes/services/class-analytic-suite-google-analytics.php

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
Dashboard Service (includes/class-analytic-suite-dashboard-service.php)
    ↓
REST Controller   (includes/class-analytic-suite-rest-controller.php)
Admin Renderer    (admin/class-analytic-suite-admin.php)
Export Controller (admin/class-analytic-suite-export-controller.php)
```

### Key Classes

| Class | File | Role |
|---|---|---|
| `Analytic_Suite` | `includes/class-analytic-suite.php` | Bootstrap: loads all classes, registers every WP hook |
| `Analytic_Suite_Dashboard_Service` | `includes/class-analytic-suite-dashboard-service.php` | Aggregates and filters all metrics; central data layer |
| `Analytic_Suite_Admin` | `admin/class-analytic-suite-admin.php` | Renders admin pages; tab navigation; chart rendering |
| `Analytic_Suite_REST_Controller` | `includes/class-analytic-suite-rest-controller.php` | REST endpoints under `/wp-json/analytic-suite/v1` |
| `Analytic_Suite_Order_Repository` | `includes/repositories/` | Wraps `wc_get_orders()` queries |
| `Analytic_Suite_Booking_Repository` | `includes/repositories/` | Queries FluentBooking custom tables with WC order fallback |
| `Analytic_Suite_Content_Repository` | `includes/repositories/` | Queries `wp_user_masterclass` and `wp_user_livres` |
| `Analytic_Suite_Google_Analytics` | `includes/services/` | GA4 Data API v1 via OAuth2; WP transient caching |
| `Analytic_Suite_Export_Controller` | `admin/` | Streams CSV, Excel, PDF exports |
| `Analytic_Suite_Activator` | `includes/` | Grants capabilities, creates `wp_analytic_suite_snapshots` table |

### Plugin Constants

Defined in `analytic-suite.php` and available everywhere:
- `ANALYTIC_SUITE_VERSION` — current version string
- `ANALYTIC_SUITE_FILE` — absolute path to the main plugin file
- `ANALYTIC_SUITE_PATH` — absolute path to the plugin directory (with trailing slash)
- `ANALYTIC_SUITE_URL` — URL to the plugin directory (with trailing slash)

### REST API

- Base namespace: `/wp-json/analytic-suite/v1`
- Main endpoints: `/dashboard`, `/summary`, `/clients`, `/orders`, `/bookings`, `/contents`, `/google-analytics` (alias `/ga`), `/reports`, `/export-rows`, `/status`, `/filters`, `/public`, `/google-analytics/cache` (DELETE)
- Read permission: `analytic_suite_view_analytics`
- Manage permission: `analytic_suite_manage_analytics`
- All endpoints accept common filter params: `period`, `date_from`, `date_to`, `country`, `status`, `booking_type`, `duration`, `product`, `gender`, `customer`, `page_path`
- Full endpoint reference: `REST_API.md`

### Filters & Periods

The Dashboard Service normalizes periods (`all`, `7-days`, `30-days`, `year`, `custom`) and supports faceted filters across all data sources. Always go through `get_filters_from_request()` — it sanitizes every field and resolves period dates.

### WordPress Options

All options use the `analytic_suite_` prefix:
- `analytic_suite_ga_property_id` — GA4 property ID
- `analytic_suite_ga_client_id`, `analytic_suite_ga_client_secret`, `analytic_suite_ga_refresh_token` — OAuth2 credentials for GA4 API
- `analytic_suite_public_ga_page_id` — page for `[analytics_public]` shortcode
- `analytic_suite_color_primary/accent/header/surface`, `analytic_suite_header_badge` — appearance
- `analytic_suite_version`, `analytic_suite_last_sync` — internal state

### Transients

GA4 API responses are cached using transients keyed as `analytic_suite_ga_<md5(filters)>`. Clear via the DELETE `/google-analytics/cache` endpoint or directly with `delete_transient()`.

### Capabilities

Granted on activation to `administrator` and `shop_manager` roles:
- `analytic_suite_view_analytics`
- `analytic_suite_manage_analytics`

### Booking Repository

`Analytic_Suite_Booking_Repository` is defensive by design: it auto-detects the FluentBooking table name at runtime (`find_booking_table()`), introspects available columns, and merges results with a WooCommerce order metadata fallback (`get_booking_rows_from_orders()`). When adding booking filters, use `value_from_columns()` to read fields rather than hardcoding column names.

### Dashboard Service Instantiation

`Analytic_Suite::get_dashboard_service()` creates a **new** `Analytic_Suite_Dashboard_Service` (and fresh repository instances) on every call — there is no singleton. The REST controller and export controller each receive their own instance.

### Public Shortcode Content Paths

The `[analytics_public]` shortcode filters GA4 data to hardcoded paths in `Analytic_Suite::get_public_content_paths()`: `/contenus-gratuits/`, `/expert-session/`, `/livre/`. Update this method if the tracked public routes change.

### WP-Cron

The `analytic_suite_daily_sync` hook is registered but currently only updates `analytic_suite_last_sync`. It is a placeholder for future precomputed analytics.

## Naming Conventions

- PHP classes: `Analytic_Suite_*` (e.g., `Analytic_Suite_Order_Repository`)
- WordPress options, hooks, transients, tables: `analytic_suite_` prefix
- Shortcodes: `[analytics_public]`
- CSS/JS handles: `analytic-suite-*`

## Security Checklist

- Sanitize all user input with `sanitize_text_field()`, `absint()`, `sanitize_key()`, etc.
- Escape all output with `esc_html()`, `esc_attr()`, `esc_url()`
- Validate nonces on all admin POST actions — each export type has its own nonce action: `analytic_suite_export_csv`, `analytic_suite_export_excel`, `analytic_suite_export_pdf`
- REST endpoints enforce capability checks in the `permission_callback`
- GA4 OAuth2 credentials are stored in `wp_options` — never expose them in frontend output

## Frontend Integration

A Nuxt 3 + TypeScript frontend consuming the REST API is planned. See `FRONTEND_NUXT_TECHNICAL_BRIEF.md` for the full spec (component structure, composables, chart library recommendations, CSS design tokens, environment variables).
