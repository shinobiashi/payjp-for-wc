<?php
/**
 * PAY.JP unified admin settings page.
 *
 * Provides a single settings tab under WooCommerce > Settings > PAY.JP
 * for API keys, test/live mode, webhook secret, and enabling/disabling each
 * payment method. All settings are stored in a single 'payjp_settings' option
 * managed by Payjp_Settings, so output() and save() are fully overridden
 * instead of relying on WC's default per-field option storage.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Admin_Settings_Page' ) ) {
	return;
}

/**
 * Registers the PAY.JP settings tab using the WooCommerce Settings API.
 *
 * Hooks registered in __construct() (by parent WC_Settings_Page):
 *   - woocommerce_settings_tabs_array   → adds "PAY.JP" tab
 *   - woocommerce_settings_payjp        → calls output()
 *   - woocommerce_settings_save_payjp   → calls save()
 */
class Payjp_Admin_Settings_Page extends WC_Settings_Page {

	/**
	 * Append this page instance to WooCommerce's settings pages list.
	 * Called from Payjp_Loader inside the woocommerce_get_settings_pages filter,
	 * where WC_Settings_Page is guaranteed to be defined.
	 *
	 * @param array<int, WC_Settings_Page> $pages Registered settings pages.
	 * @return array<int, WC_Settings_Page>
	 */
	public static function register_page( array $pages ): array {
		$pages[] = new self();
		return $pages;
	}

	/**
	 * Set up page ID/label and register the custom field type action.
	 */
	public function __construct() {
		$this->id    = 'payjp';
		$this->label = __( 'PAY.JP', 'payjp-for-wc' );

		parent::__construct();

		add_action( 'woocommerce_admin_field_payjp_enabled_methods', array( $this, 'output_enabled_methods_field' ) );
		add_action( 'woocommerce_admin_field_payjp_webhook_info', array( $this, 'output_webhook_info_field' ) );
		add_action( 'woocommerce_admin_field_payjp_test_mode_notice', array( $this, 'output_test_mode_notice_field' ) );
		add_action( 'woocommerce_admin_field_payjp_dashboard_link', array( $this, 'output_dashboard_link_field' ) );
	}

	/**
	 * Field definitions for the settings page.
	 * Values are injected in output() via pre_option_* filters, not read
	 * individually from wp_options, because all settings share one option key.
	 *
	 * @param string $current_section Current section slug (single section; unused here but passed to the filter for extension compatibility).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings( $current_section = '' ): array {
		return apply_filters(
			'payjp_for_wc_get_settings',
			array(
				// ── API / Mode ──────────────────────────────────────────────────
				array(
					'title' => __( 'API Settings', 'payjp-for-wc' ),
					'type'  => 'title',
					'id'    => 'payjp_api_settings',
				),
				array(
					'type' => 'payjp_dashboard_link',
					'id'   => 'payjp_dashboard_link',
				),
				array(
					'title'       => __( 'Test Secret Key', 'payjp-for-wc' ),
					'type'        => 'password',
					'id'          => 'payjp_test_secret_key',
					'default'     => '',
					'placeholder' => 'sk_test_',
					'desc_tip'    => false,
					'desc'        => __( 'Found on the API keys page of your PAY.JP dashboard (test). Starts with <code>sk_test_</code>. Keep this key secret and never expose it publicly.', 'payjp-for-wc' ),
				),
				array(
					'title'       => __( 'Test Public Key', 'payjp-for-wc' ),
					'type'        => 'text',
					'id'          => 'payjp_test_public_key',
					'default'     => '',
					'placeholder' => 'pk_test_',
					'desc_tip'    => false,
					'desc'        => __( 'Found on the API keys page of your PAY.JP dashboard (test). Starts with <code>pk_test_</code>.', 'payjp-for-wc' ),
				),
				array(
					'title'       => __( 'Live Secret Key', 'payjp-for-wc' ),
					'type'        => 'password',
					'id'          => 'payjp_live_secret_key',
					'default'     => '',
					'placeholder' => 'sk_live_',
					'desc_tip'    => false,
					'desc'        => __( 'Found on the API keys page of your PAY.JP dashboard (live). Starts with <code>sk_live_</code>. Keep this key secret and never expose it publicly.', 'payjp-for-wc' ),
				),
				array(
					'title'       => __( 'Live Public Key', 'payjp-for-wc' ),
					'type'        => 'text',
					'id'          => 'payjp_live_public_key',
					'default'     => '',
					'placeholder' => 'pk_live_',
					'desc_tip'    => false,
					'desc'        => __( 'Found on the API keys page of your PAY.JP dashboard (live). Starts with <code>pk_live_</code>.', 'payjp-for-wc' ),
				),
				array(
					'title'    => __( 'Webhook Secret', 'payjp-for-wc' ),
					'type'     => 'password',
					'id'       => 'payjp_webhook_secret',
					'default'  => '',
					'desc_tip' => false,
					'desc'     => __( 'The authentication token configured in PAY.JP Dashboard &gt; Webhooks. Required for receiving payment and refund events on your server. Without this, automatic order status updates via webhook will not work.', 'payjp-for-wc' ),
				),
				array(
					'title'    => __( 'Test Mode', 'payjp-for-wc' ),
					'type'     => 'checkbox',
					'id'       => 'payjp_test_mode',
					'default'  => 'yes',
					'desc_tip' => false,
					'desc'     => __( 'When enabled, test API keys are used and no real payments are processed. Uncheck this and use live API keys for production.', 'payjp-for-wc' ),
				),
				array(
					'type' => 'payjp_test_mode_notice',
					'id'   => 'payjp_test_mode_notice',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payjp_api_settings',
				),
				// ── Enabled payment methods ──────────────────────────────────
				array(
					'title' => __( 'Payment Methods', 'payjp-for-wc' ),
					'type'  => 'title',
					'id'    => 'payjp_methods_settings',
				),
				array(
					'type' => 'payjp_enabled_methods',
					'id'   => 'payjp_enabled_methods',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payjp_methods_settings',
				),
				// ── Debug ─────────────────────────────────────────────────────
				array(
					'title' => __( 'Debug', 'payjp-for-wc' ),
					'type'  => 'title',
					'id'    => 'payjp_debug_settings',
					'desc'  => __( 'Useful for diagnosing payment issues during development. Do not leave enabled in production.', 'payjp-for-wc' ),
				),
				array(
					'title'    => __( 'Debug Log', 'payjp-for-wc' ),
					'type'     => 'checkbox',
					'id'       => 'payjp_debug_log',
					'default'  => 'no',
					'label'    => __( 'Enable logging', 'payjp-for-wc' ),
					'desc_tip' => false,
					'desc'     => sprintf(
						/* translators: 1: Opening <a> tag linking to WooCommerce log viewer, 2: Closing </a> tag */
						__( 'Logs PAY.JP API requests, responses, payment events, and received webhooks. Enable only when debugging; keep off in production. Logs are available at %1$sWooCommerce &gt; Status &gt; Logs%2$s.', 'payjp-for-wc' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=payjp-for-wc' ) ) . '">',
						'</a>'
					),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payjp_debug_settings',
				),
				// ── Webhook URL ───────────────────────────────────────────────
				array(
					'title' => __( 'Webhook', 'payjp-for-wc' ),
					'type'  => 'title',
					'id'    => 'payjp_webhook_info',
				),
				array(
					'type' => 'payjp_webhook_info',
					'id'   => 'payjp_webhook_info_field',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payjp_webhook_info',
				),
			),
			$current_section
		);
	}

	/**
	 * Render the settings page.
	 *
	 * Temporarily injects current Payjp_Settings values via pre_option_* filters
	 * so WC's standard output_fields() displays stored values rather than DB lookups
	 * against individual option keys (which we don't use).
	 */
	public function output(): void {
		$current   = Payjp_Settings::get_all();
		$test_mode = isset( $current['test_mode'] ) ? (bool) $current['test_mode'] : true;

		$option_map = array(
			'payjp_test_mode'       => $test_mode ? 'yes' : 'no',
			'payjp_test_public_key' => (string) ( $current['test_public_key'] ?? '' ),
			'payjp_test_secret_key' => (string) ( $current['test_secret_key'] ?? '' ),
			'payjp_live_public_key' => (string) ( $current['live_public_key'] ?? '' ),
			'payjp_live_secret_key' => (string) ( $current['live_secret_key'] ?? '' ),
			'payjp_webhook_secret'  => (string) ( $current['webhook_secret'] ?? '' ),
			'payjp_debug_log'       => ! empty( $current['debug_log'] ) ? 'yes' : 'no',
		);

		$closures = array();
		foreach ( $option_map as $key => $val ) {
			$closures[ $key ] = static function () use ( $val ): string {
				return $val;
			};
			add_filter( "pre_option_{$key}", $closures[ $key ] );
		}

		WC_Admin_Settings::output_fields( $this->get_settings() );

		foreach ( array_keys( $option_map ) as $key ) {
			remove_filter( "pre_option_{$key}", $closures[ $key ] );
		}
	}

	/**
	 * Output a test mode notice with a link to PAY.JP test card documentation.
	 * Rendered only when test mode is currently enabled.
	 *
	 * @param array<string, mixed> $value Field definition array (unused).
	 */
	public function output_test_mode_notice_field( array $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! Payjp_Settings::is_test_mode() ) {
			return;
		}

		$allowed = array(
			'a' => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		);
		?>
		<tr valign="top">
			<th scope="row"></th>
			<td class="forminp">
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL to PAY.JP test mode documentation */
							__( 'Test mode is active. <a href="%s" target="_blank" rel="noopener noreferrer">View test card numbers and test accounts &rarr;</a>', 'payjp-for-wc' ),
							'https://docs.pay.jp/v2/guide/developers/testmode-livemode'
						),
						$allowed
					);
					?>
				</p>
				<?php if ( Payjp_Settings::is_method_enabled( 'paypay' ) ) : ?>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL to PAY.JP PayPay test documentation */
							__( 'PayPay is enabled. <a href="%s" target="_blank" rel="noopener noreferrer">View PayPay test accounts and instructions &rarr;</a>', 'payjp-for-wc' ),
							'https://docs.pay.jp/v2/guide/payments/methods/paypay'
						),
						$allowed
					);
					?>
				</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output a button linking to the PAY.JP Dashboard (keys configured) or
	 * the PAY.JP sign-up page (no keys configured yet).
	 *
	 * @param array<string, mixed> $value Field definition array (unused).
	 */
	public function output_dashboard_link_field( array $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$all      = Payjp_Settings::get_all();
		$has_keys = ! empty( $all['test_secret_key'] ) || ! empty( $all['live_secret_key'] );

		if ( $has_keys ) {
			$url   = 'https://console.pay.jp/d/login';
			$label = __( 'Open PAY.JP Dashboard', 'payjp-for-wc' );
			$desc  = __( 'Manage API keys, webhooks, and payments on the PAY.JP Dashboard.', 'payjp-for-wc' );
			$class = 'button button-primary';
		} else {
			$url   = 'https://console.pay.jp/d/signup';
			$label = __( 'Create a PAY.JP Account', 'payjp-for-wc' );
			$desc  = __( 'You need a PAY.JP account to accept payments. Sign up for free.', 'payjp-for-wc' );
			$class = 'button button-secondary';
		}
		?>
		<tr valign="top">
			<th scope="row"></th>
			<td class="forminp">
				<a
					href="<?php echo esc_url( $url ); ?>"
					class="<?php echo esc_attr( $class ); ?>"
					target="_blank"
					rel="noopener noreferrer"
				><?php echo esc_html( $label ); ?> &rarr;</a>
				<p class="description" style="margin-top:.5em;">
					<?php echo esc_html( $desc ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output the enabled payment methods checkbox rows.
	 * Called via the woocommerce_admin_field_payjp_enabled_methods action,
	 * which WC fires from output_fields() when it encounters our custom type.
	 *
	 * @param array<string, mixed> $value Field definition array (not used directly).
	 */
	public function output_enabled_methods_field( array $value ): void {
		$enabled = Payjp_Settings::get_enabled_methods();
		$methods = array(
			'card'   => __( 'Credit Card (PAY.JP)', 'payjp-for-wc' ),
			'paypay' => __( 'PayPay (PAY.JP)', 'payjp-for-wc' ),
		);
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Payment Method', 'payjp-for-wc' ); ?></th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php esc_html_e( 'Enable Payment Methods', 'payjp-for-wc' ); ?></span>
					</legend>
					<?php foreach ( $methods as $method_id => $label ) : ?>
					<label for="payjp_enabled_methods_<?php echo esc_attr( $method_id ); ?>">
						<input
							type="checkbox"
							name="payjp_enabled_methods[]"
							id="payjp_enabled_methods_<?php echo esc_attr( $method_id ); ?>"
							value="<?php echo esc_attr( $method_id ); ?>"
							<?php checked( in_array( $method_id, $enabled, true ) ); ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label><br />
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( 'Checked payment methods will appear on the checkout page. API keys must also be configured for them to be available.', 'payjp-for-wc' ); ?>
					</p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output the Webhook URL display and configuration instructions.
	 * Called via the woocommerce_admin_field_payjp_webhook_info action.
	 *
	 * @param array<string, mixed> $value Field definition array (unused).
	 */
	public function output_webhook_info_field( array $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$webhook_url = rest_url( Payjp_Webhook_Handler::REST_NAMESPACE . Payjp_Webhook_Handler::REST_ROUTE );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'Webhook URL', 'payjp-for-wc' ); ?></label>
			</th>
			<td class="forminp">
				<input
					type="text"
					class="input-text regular-input"
					value="<?php echo esc_url( $webhook_url ); ?>"
					readonly="readonly"
					style="min-width:420px;"
					onclick="this.select();"
				/>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: Opening <strong> tag, 2: Closing </strong> tag */
							__( 'Register the URL above in %1$sPAY.JP Dashboard &gt; Webhooks%2$s.', 'payjp-for-wc' ),
							'<strong>',
							'</strong>'
						),
						array( 'strong' => array() )
					);
					?>
					<?php esc_html_e( 'Enable the following events:', 'payjp-for-wc' ); ?>
				</p>
				<ul style="margin:.4em 0 0 1.4em;list-style:disc;">
					<li><code>payment_flow.succeeded</code></li>
					<li><code>payment_flow.payment_failed</code></li>
					<li><code>refund.created</code></li>
				</ul>
				<p class="description" style="margin-top:.6em;">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: Opening <strong> tag, 2: Closing </strong> tag */
							__( 'Set the %1$sWebhook Secret%2$s from PAY.JP Dashboard into the "Webhook Secret" field above and save.', 'payjp-for-wc' ),
							'<strong>',
							'</strong>'
						),
						array( 'strong' => array() )
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist settings from POST to the payjp_settings option.
	 * WooCommerce verifies the 'woocommerce-settings' nonce before this fires.
	 *
	 * Also syncs each gateway's individual WC 'enabled' option so that
	 * WC_Payment_Gateway::is_available() (called by parent) correctly reflects
	 * the chosen enabled_methods without requiring a separate gateway toggle.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked by WooCommerce before woocommerce_settings_save_payjp fires.
		$settings = array(
			'test_mode'       => ! empty( $_POST['payjp_test_mode'] ) && is_scalar( $_POST['payjp_test_mode'] ),
			'test_public_key' => sanitize_text_field( wp_unslash( is_string( $_POST['payjp_test_public_key'] ?? '' ) ? $_POST['payjp_test_public_key'] : '' ) ),
			'test_secret_key' => sanitize_text_field( wp_unslash( is_string( $_POST['payjp_test_secret_key'] ?? '' ) ? $_POST['payjp_test_secret_key'] : '' ) ),
			'live_public_key' => sanitize_text_field( wp_unslash( is_string( $_POST['payjp_live_public_key'] ?? '' ) ? $_POST['payjp_live_public_key'] : '' ) ),
			'live_secret_key' => sanitize_text_field( wp_unslash( is_string( $_POST['payjp_live_secret_key'] ?? '' ) ? $_POST['payjp_live_secret_key'] : '' ) ),
			'webhook_secret'  => sanitize_text_field( wp_unslash( is_string( $_POST['payjp_webhook_secret'] ?? '' ) ? $_POST['payjp_webhook_secret'] : '' ) ),
			'debug_log'       => ! empty( $_POST['payjp_debug_log'] ) && is_scalar( $_POST['payjp_debug_log'] ),
			'enabled_methods' => array_values(
				array_intersect(
					array_map(
						'sanitize_key',
						array_filter(
							isset( $_POST['payjp_enabled_methods'] ) && is_array( $_POST['payjp_enabled_methods'] )
								? array_map( 'sanitize_text_field', wp_unslash( $_POST['payjp_enabled_methods'] ) )
								: array(),
							'is_string'
						)
					),
					array( 'card', 'paypay' )
				)
			),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Merge with existing settings so custom keys added by extensions via the
		// woocommerce_get_settings_payjp filter are not erased on each save.
		// Extensions that render extra fields must hook woocommerce_settings_save_payjp
		// to persist their own POST values; this merge preserves already-saved custom keys.
		update_option( Payjp_Settings::OPTION_KEY, array_merge( Payjp_Settings::get_all(), $settings ) );
		Payjp_Settings::flush_cache();

		// Sync each gateway's own WC 'enabled' flag so WC_Payment_Gateway::is_available()
		// returns true when the method is enabled from this unified settings page.
		$gateway_option_keys = array(
			'card'   => 'woocommerce_payjp_card_settings',
			'paypay' => 'woocommerce_payjp_paypay_settings',
		);
		foreach ( $gateway_option_keys as $method => $option_key ) {
			$gateway_settings            = (array) get_option( $option_key, array() );
			$gateway_settings['enabled'] = in_array( $method, $settings['enabled_methods'], true ) ? 'yes' : 'no';
			update_option( $option_key, $gateway_settings );
		}
	}
}
