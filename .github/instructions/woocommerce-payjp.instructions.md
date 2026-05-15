---
applyTo: "includes/**/*.php"
---

# PAY.JP for WooCommerce — Copilot Review Guidelines

## WooCommerce Settings API

- `WC_Settings_Page` field definitions: use the `desc` key (not `description`) for help text when `desc_tip: true`. `WC_Admin_Settings::get_field_description()` reads `desc`, not `description`.
- `apply_filters('woocommerce_get_settings_{id}', $fields, $current_section)` must always pass `$current_section` as the second argument so section-aware extension callbacks receive it.
- `WC_Settings_Page` subclasses must be required inside the `woocommerce_get_settings_pages` filter callback (where WC admin has booted), not at `plugins_loaded` where `WC_Settings_Page` may not yet be defined.

## WordPress Security

- All `$_POST` text/password reads: guard with `is_string()` before passing to `sanitize_text_field()` + `wp_unslash()` to prevent a PHP TypeError when the value is a nested array.
- Checkbox booleans from `$_POST`: use `! empty( $_POST['key'] ) && is_scalar( $_POST['key'] )`.
- `$_POST` array values (e.g. `payjp_enabled_methods[]`): apply `array_filter( ..., 'is_string' )` before `array_map( 'sanitize_key', ... )` to reject nested arrays.
- Webhook token verification: `hash_equals()` only — never `===`, `==`, or `strcmp()` (timing-attack risk).
- Admin AJAX / save handlers: always verify `current_user_can( 'manage_woocommerce' )`.
- DB queries: `$wpdb->prepare()` required for any user-supplied value.

## HPOS Compliance

- Never use `get_post_meta()` / `update_post_meta()` / `add_post_meta()` for order data.
- Always use `$order->get_meta()` / `$order->update_meta_data()` followed by `$order->save()`.

## Plugin Architecture

- `enabled_methods` sync: seed the current list from `Payjp_Settings::get_enabled_methods()` (not `[]` or a partial array) to avoid silently dropping gateways that are not the one being toggled.
- On settings save: `array_merge( Payjp_Settings::get_all(), $new_settings )` to preserve custom keys added by extensions via filter hooks.
- HTTP requests: `wp_remote_post()` / `wp_remote_get()` only — never `curl_*` directly (violates WP proxy settings).
- Always check `is_wp_error()` on every `wp_remote_*` response before reading the body.
- Payment Flow IDs must be verified server-side via the PAY.JP API — never trust a client-supplied ID alone.

## Code Style

- Short array syntax `[]` required everywhere; `array()` is banned project-wide.
- All output escaped: `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_kses_post()` as appropriate.
- Text domain: `payjp-for-wc` on every translatable string; no bare `__( 'text' )` without the domain.
- No `curl`, no hardcoded secret keys, no `var_dump` / `error_log` in committed code.
