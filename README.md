# PAY.JP for WooCommerce

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)](https://www.php.net/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A?logo=woocommerce)](https://woocommerce.com/)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress)](https://wordpress.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHPCS](https://img.shields.io/badge/PHPCS-WordPress%20Standards-green)](https://github.com/WordPress/WordPress-Coding-Standards)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-orange)](https://phpstan.org/)

PAY.JP v2 payment gateway plugin for WooCommerce. Supports credit card and PayPay payments via PAY.JP Payment Widgets. Raw card data never touches your server.

---

## Features

- **Credit Card Payments** — Embedded PAY.JP v2 Payment Widgets. PCI DSS compliant; card data is tokenized in an iframe by `payments.js` and never reaches your server.
- **PayPay Payments** — Embedded PayPay widget via PAY.JP v2 Payment Widgets.
- **Saved Cards** — Customers can save cards via PAY.JP Setup Flow, stored as WooCommerce payment tokens.
- **WooCommerce Subscriptions** — Automatic recurring billing using saved PAY.JP Customer IDs.
- **Block Checkout** — Full support for the WooCommerce Block Checkout (WooCommerce 8.3+).
- **Classic Checkout** — Works with the traditional WooCommerce shortcode checkout.
- **Webhook Processing** — Automatically updates order status from `payment_flow.succeeded`, `payment_flow.payment_failed`, and `refund.created` events.
- **Refunds** — Full and partial refunds from the WooCommerce order admin screen.
- **Test / Live Mode** — Toggle between PAY.JP test and live environments without code changes.
- **HPOS Compatible** — Fully compatible with WooCommerce High-Performance Order Storage.
- **Multisite Compatible** — Each site in a network uses its own settings.

---

## Requirements

| Dependency | Version |
|-----------|---------|
| PHP | 8.3+ |
| WordPress | 6.4+ |
| WooCommerce | 8.0+ |
| PAY.JP account | — |

---

## Installation (End Users)

1. Go to **Plugins > Add New** in WordPress admin.
2. Search for "PAY.JP for WooCommerce" and click **Install Now**.
3. Activate the plugin.
4. Go to **WooCommerce > Settings > Payments > PAY.JP**.
5. Enter your PAY.JP API keys, set the Webhook Secret, and enable the payment methods you want.

**Webhook URL:** `https://yoursite.com/wp-json/payjp/v1/webhook`

Register this URL in the PAY.JP Dashboard under **Webhook** with the following events:
- `payment_flow.succeeded`
- `payment_flow.payment_failed`
- `refund.created`

---

## Development Setup

### Prerequisites

- Node.js 22.x
- PHP 8.3+
- Composer
- Docker (for `wp-env`)

### Clone and install dependencies

```bash
git clone https://github.com/artws/payjp-for-wc.git
cd payjp-for-wc
npm install
composer install
```

### Start the local WordPress environment

```bash
npm run env:start   # Starts WordPress + WooCommerce at http://localhost:8888
```

Default credentials: `admin` / `password`

### Build JavaScript assets

```bash
npm run build       # Production build
npm run start       # Watch mode (development)
```

### Stop the environment

```bash
npm run env:stop
```

---

## Project Structure

```
payjp-for-wc.php                              Bootstrap, constants
uninstall.php                                 Cleanup on plugin deletion
includes/
  class-payjp-loader.php                      Hooks registration, class loading
  class-payjp-settings.php                    Shared settings manager (API keys, webhook secret)
  gateways/payjp/
    class-payjp-api.php                       PAY.JP API wrapper (wp_remote_*)
    class-wc-gateway-payjp.php                Abstract base gateway
    class-wc-gateway-payjp-card.php           Credit card gateway
    class-wc-gateway-payjp-paypay.php         PayPay gateway
    class-payjp-webhook-handler.php           Webhook receiver and router
    class-payjp-token-manager.php             Card save/delete via Setup Flow + WC Token API
    class-payjp-subscriptions.php             WooCommerce Subscriptions recurring payments
    class-payjp-blocks-integration.php        Block Checkout base integration
    class-payjp-blocks-integration-card.php   Block Checkout — card
    class-payjp-blocks-integration-paypay.php Block Checkout — PayPay
  admin/
    class-payjp-admin-settings-page.php       Unified WooCommerce settings page
  jp4wc-framework/
    class-jp4wc-logger.php                    Structured payment logger (WC_Logger)
templates/
  return.php                                  Payment return handler (redirect target)
src/
  blocks/checkout/
    index.js                                  registerPaymentMethod (card + PayPay)
    payment-method-card.js                    Card React component
    payment-method-paypay.js                  PayPay React component
  admin/settings/
    index.js                                  Admin settings JS
languages/                                    Translation files (.pot, .po, .mo, .json)
tests/
  Unit/                                       PHPUnit unit tests
  e2e/                                        Playwright E2E tests
  stubs/                                      WordPress/WooCommerce stubs for unit tests
```

---

## Contributing

We welcome pull requests. Please follow the guidelines below.

### 1. Fork and branch

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/issue-description
```

### 2. Make your changes

Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) for PHP and the [@wordpress/scripts ESLint config](https://github.com/WordPress/gutenberg/tree/trunk/packages/eslint-plugin) for JavaScript.

Key rules:
- **Input sanitization:** `sanitize_text_field( wp_unslash( ... ) )` for strings, `absint()` for integers.
- **Output escaping:** `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- **HPOS:** Use `$order->get_meta()` / `$order->update_meta_data()`. Never use `get_post_meta()` for orders.
- **HTTP requests:** Use `wp_remote_post()` / `wp_remote_get()`. Never call `curl` directly.
- **Webhook token verification:** Use `hash_equals()` only (timing-attack safe).
- **Constants:** Use `defined() || define()` pattern (prevents double-load when bundled).
- **Yoda conditions:** `'yes' === $var`, `null === $x`.

### 3. Run code quality checks

All checks must pass before opening a pull request.

```bash
# PHP Coding Standards
vendor/bin/phpcs --standard=phpcs.xml .

# Auto-fix what can be fixed automatically
vendor/bin/phpcbf --standard=phpcs.xml .

# Static analysis (level 5)
vendor/bin/phpstan analyse --memory-limit=1G

# JavaScript lint
npm run lint:js

# CSS lint
npm run lint:css
```

Expected results: **0 PHPCS errors, 0 PHPStan errors, 0 JS/CSS lint errors**.

### 4. Run tests

```bash
# Unit tests
vendor/bin/phpunit

# E2E tests (requires running environment)
npm run env:start
npx playwright test
```

### 5. Open a Pull Request

- Target the `main` branch.
- Describe what changed and why.
- Reference any related issues.
- All CI checks (PHPCS, PHPStan, JS lint, PHPUnit) must pass.

---

## Testing

### Unit Tests (PHPUnit)

Unit tests live in `tests/Unit/` and use [Brain/Monkey](https://brain-wp.github.io/BrainMonkey/) for WordPress function stubs.

```bash
vendor/bin/phpunit
```

Test suites:
- `CardGatewayRefundTest` — `process_refund()` (order not found, missing flow ID, API error, partial/full refund)
- `WebhookHandlerTest` — webhook event routing and token verification

To add a new test, create a class in `tests/Unit/` extending `PHPUnit\Framework\TestCase`. Set up Brain/Monkey in `setUp()` / `tearDown()`.

### E2E Tests (Playwright)

E2E tests live in `tests/e2e/` and run against a live `wp-env` environment.

```bash
npm run env:start
npx playwright test

# Run a specific spec
npx playwright test tests/e2e/phase10.spec.js

# View test report
npx playwright show-report
```

**PayPay test accounts:** Use phone numbers `080-1111-5912` through `080-1111-5921`. Each transaction is capped at ¥100. Issue a full refund after each test.

**Webhook testing:** Use the PAY.JP Dashboard → **Webhook** → **Send Test** to trigger test events against your local environment (requires an ngrok tunnel or similar).

### Code Quality in CI

The `.github/workflows/ci.yml` workflow runs on every push and pull request:

| Job | Command |
|-----|---------|
| PHPCS | `vendor/bin/phpcs --standard=phpcs.xml .` |
| PHPStan | `vendor/bin/phpstan analyse --memory-limit=1G` |
| JS Lint | `npm run lint:js` |
| CSS Lint | `npm run lint:css` |

---

## Distribution

This plugin is distributed in two forms:

1. **Standalone** — Published on [WordPress.org](https://wordpress.org/plugins/payjp-for-wc/).
2. **Bundled** — Included inside [Japanized for WooCommerce](https://wordpress.org/plugins/woocommerce-for-japan/). The `defined() || define()` constant pattern and `class_exists()` guards prevent conflicts when both are active.

---

## External Services

| Service | URL | Purpose |
|---------|-----|---------|
| PAY.JP API | `https://api.pay.jp/v2` | Payment processing, refunds, customer management |
| PAY.JP payments.js | `https://js.pay.jp/payments.js` | PCI DSS compliant card/PayPay widget |

- [PAY.JP Privacy Policy](https://pay.jp/privacy)
- [PAY.JP Terms of Service](https://pay.jp/terms)

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

Copyright (C) 2024 [Shohei Tanaka / Artisan Workshop](https://artws.info).
