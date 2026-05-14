# Repository Guidelines

## Project Structure & Module Organization

This repository is the early scaffold for a WordPress analytics plugin, described in `README.md` as Ferray Analytics Suite. It currently contains project requirements only; add implementation files at the plugin root using standard WordPress plugin conventions.

Recommended layout as the plugin grows:

- `analytic-suite.php`: main plugin bootstrap and metadata header.
- `includes/`: PHP services, data sync, repositories, cron jobs, and shared helpers.
- `admin/`: WordPress admin pages for Dashboard, Clients, Reservations, Orders, Reports, Exports, and Settings.
- `assets/`: JavaScript, CSS, images, and chart assets for admin screens.
- `tests/`: automated tests and fixtures when test tooling is introduced.

Keep WooCommerce and FluentBooking integrations behind service classes so analytics calculations can be tested independently.

## Build, Test, and Development Commands

No package manager, build script, or test runner is currently committed. Current commands:

- `git status --short`: check local changes.
- `php -l path/to/file.php`: lint a PHP file once source files exist.
- `wp plugin activate analytic-suite`: activate the plugin when WP-CLI is available.

If Composer, npm, or a Makefile is added later, document reproducible root-level commands here.

## Coding Style & Naming Conventions

Use WordPress PHP conventions: 4-space indentation, snake_case functions, escaped output, sanitized input, and nonce checks for admin actions. Prefix global functions, hooks, options, transients, cron events, and tables with `analytic_suite_`.

Prefer small domain classes, for example `Analytic_Suite_Order_Repository` or `Analytic_Suite_Booking_Sync`. Keep admin UI separate from collection and aggregation logic.

## Testing Guidelines

Add tests for revenue, recurrence, cancellations, filters, and exports. Name test files after the behavior, such as `OrderAnalyticsTest.php` or `BookingCancellationTest.php`. Until a framework is committed, verify PHP syntax with `php -l` and manually test activation, admin pages, filters, and exports locally.

## Commit & Pull Request Guidelines

Git history currently contains only `init project`, so no detailed convention is established. Use short imperative commits such as `Add analytics bootstrap`.

Pull requests should include a concise description, affected WordPress/WooCommerce/FluentBooking areas, manual test notes, screenshots for admin UI changes, and database or cron changes.

## Security & Configuration Tips

Never commit production credentials, customer data, or generated reports containing personal data. Use WordPress capabilities for admin access, sanitize request data, escape rendered values, and validate export permissions before generating CSV, XLSX, or PDF files.
