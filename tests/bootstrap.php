<?php
/**
 * PHPUnit bootstrap for PAY.JP for WooCommerce unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Plugin constants required by included classes.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'PAYJP_FOR_WC_VERSION', '1.0.0' );
define( 'PAYJP_FOR_WC_FILE', dirname( __DIR__ ) . '/payjp-for-wc.php' );
define( 'PAYJP_FOR_WC_DIR', dirname( __DIR__ ) . '/' );
define( 'PAYJP_FOR_WC_URL', 'https://example.com/wp-content/plugins/payjp-for-wc/' );
define( 'PAYJP_API_BASE', 'https://api.pay.jp/v2' );

// Minimal WordPress / WooCommerce class stubs.
require_once __DIR__ . '/stubs/class-wp-error.php';
require_once __DIR__ . '/stubs/class-wp-rest-request.php';
require_once __DIR__ . '/stubs/class-wp-rest-response.php';
require_once __DIR__ . '/stubs/class-wc-order.php';
require_once __DIR__ . '/stubs/class-wc-payment-gateway.php';
require_once __DIR__ . '/stubs/class-payjp-api.php';

// Plugin classes under test.
require_once dirname( __DIR__ ) . '/includes/class-payjp-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-payjp-webhook-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-gateway-payjp.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-gateway-payjp-card.php';
