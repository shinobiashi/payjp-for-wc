# Plugin/theme operations

Use this file for installs, activation, updates, and listing state.

## Common commands

- Plugins:
  - `wp plugin list`
  - `wp plugin status <slug>`
  - `wp plugin activate <slug>`
  - `wp plugin deactivate <slug>`
  - `wp plugin update --all`
- Themes:
  - `wp theme list`
  - `wp theme activate <slug>`
  - `wp theme update --all`

## Version-related behavior (WP-CLI 2.12+)

- Install/update commands respect `Requires at least` / `Requires PHP` headers; incompatible updates appear as `unavailable` in `wp plugin list`.
- `wp plugin list` / `wp theme list` force a fresh update check by default; pass `--skip-update-check` to avoid it (faster, uses cached data).

## Guardrails

- On production, avoid `update --all` without a maintenance window.
- On multisite, plugin activation may be per-site or network-wide; confirm intent.

