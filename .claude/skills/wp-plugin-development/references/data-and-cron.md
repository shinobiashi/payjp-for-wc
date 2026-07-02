# Data storage, cron, and upgrades

Use this file when adding persistent storage, background jobs, or upgrade routines.

## Data storage

- Prefer Options API for small config/state.
- Use custom tables only when needed; store schema version and provide upgrade paths.

## Cron

- Ensure tasks are idempotent (may run late or multiple times).
- Provide a manual trigger path for debugging (WP-CLI or admin-only action).

## Database safety note

If using `$wpdb->prepare()`, avoid building queries with concatenated user input.
WordPress 6.2+ supports the identifier placeholder (`%i`) for table/column names; confirm the plugin's minimum supported WordPress version before relying on it.

