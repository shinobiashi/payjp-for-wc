<?php
/**
 * PAY.JP admin alert notifications.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Admin_Notifier' ) ) {
	return;
}

/**
 * Sends plain-text payment-anomaly alert emails to the store administrator.
 *
 * Uses wp_mail() directly rather than a WC_Email subclass so the recipient
 * address is managed solely from the PAY.JP settings page, not duplicated
 * under WooCommerce > Settings > Emails.
 */
class Payjp_Admin_Notifier {

	/**
	 * Send a payment-anomaly alert email to the store administrator.
	 *
	 * @param WC_Order $order   Order the anomaly relates to.
	 * @param string   $subject Translated subject line (without site-name prefix).
	 * @param string[] $lines   Translated body lines; imploded with "\n".
	 * @return bool Whether wp_mail() reported success.
	 */
	public static function send_alert( WC_Order $order, string $subject, array $lines ): bool {
		if ( ! apply_filters( 'payjp_for_wc_alert_email_enabled', true, $order ) ) {
			return false;
		}

		$recipient = apply_filters( 'payjp_for_wc_alert_email_recipient', Payjp_Settings::get_alert_email(), $order );

		$full_subject = sprintf(
			'[%s] %s',
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$subject
		);

		$body_lines   = $lines;
		$body_lines[] = '';
		$body_lines[] = $order->get_edit_order_url();
		$body         = implode( "\n", $body_lines );

		return wp_mail( $recipient, $full_subject, $body );
	}
}
