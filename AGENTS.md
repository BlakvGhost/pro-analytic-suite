# Repository Guidelines

## Structure

```
analytic-suite.php     # Plugin bootstrap, defines ANALYTIC_SUITE_* constants
includes/              # Core: activator, deactivator, main class
admin/                 # Admin UI: menu, pages, export controller
assets/{js,css}/       # Frontend assets
tests/                 # Test fixtures (empty)
```

Repositories exist under `includes/repositories/`: Order, Booking, Content.

## Dev Commands

- `php -l path/to/file.php` - syntax check
- `git status --short` - local changes
- `wp plugin activate analytic-suite` - activate (requires WP-CLI + WordPress)

No package manager yet. Add Composer/npm commands here when introduced.

## Naming & Style

- Prefix: `analytic_suite_` for functions, hooks, options, transients, tables
- Classes: `Analytic_Suite_*` (CamelCase)
- Indentation: 4 spaces
- Always: sanitized input, escaped output, nonce checks for admin actions

Keep WooCommerce/FluentBooking integrations behind service classes for testability.

## Testing

Until a test framework is added:
- `php -l` for syntax validation
- Manual testing on local WordPress instance

## Security

- Never commit credentials, customer data, or generated reports
- Use WordPress capabilities for access control
- Sanitize `$_POST`/`$_GET`, escape on output
- Validate export permissions before CSV/XLSX/PDF generation
