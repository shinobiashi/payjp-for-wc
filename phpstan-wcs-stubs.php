<?php
/**
 * Minimal WooCommerce Subscriptions stubs for PHPStan static analysis.
 * NOT loaded at runtime — only referenced via phpstan.neon bootstrapFiles.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WC_Subscription (WooCommerce Subscriptions plugin).
 * Extends WC_Order; only the methods used by Payjp_Subscriptions are declared.
 */
class WC_Subscription extends WC_Order {}

/**
 * Minimal stub for WC_Subscriptions (used only for class_exists() guard).
 */
class WC_Subscriptions {}

/**
 * Return all subscriptions associated with a renewal order.
 *
 * @param WC_Order $renewal_order The renewal order.
 * @return WC_Subscription[]
 */
function wcs_get_subscriptions_for_renewal_order( WC_Order $renewal_order ): array {
	return [];
}

/**
 * Return all subscriptions associated with a given order ID.
 *
 * @param int $order_id WooCommerce order ID.
 * @return WC_Subscription[]
 */
function wcs_get_subscriptions_for_order( int $order_id ): array {
	return [];
}
