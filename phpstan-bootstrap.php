<?php
/**
 * PHPStan bootstrap: defines plugin constants for static analysis.
 * This file is NOT loaded at runtime — only by PHPStan via phpstan.neon.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'PAYJP_FOR_WC_VERSION' ) || define( 'PAYJP_FOR_WC_VERSION', '1.0.0' );
defined( 'PAYJP_FOR_WC_FILE' ) || define( 'PAYJP_FOR_WC_FILE', dirname( __FILE__ ) . '/payjp-for-woocommerce.php' );
defined( 'PAYJP_FOR_WC_DIR' ) || define( 'PAYJP_FOR_WC_DIR', dirname( __FILE__ ) . '/' );
defined( 'PAYJP_FOR_WC_URL' ) || define( 'PAYJP_FOR_WC_URL', 'http://example.com/wp-content/plugins/payjp-for-woocommerce/' );
defined( 'PAYJP_API_BASE' ) || define( 'PAYJP_API_BASE', 'https://api.pay.jp/v2' );
