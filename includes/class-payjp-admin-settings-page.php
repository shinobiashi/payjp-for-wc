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
	 * Register the woocommerce_get_settings_pages filter.
	 * Called by Payjp_Loader on plugins_loaded (admin-only).
	 */
	public static function init(): void {
		add_filter( 'woocommerce_get_settings_pages', [ self::class, 'register_page' ] );
	}

	/**
	 * Append this page instance to WooCommerce's settings pages list.
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

		add_action( 'woocommerce_admin_field_payjp_enabled_methods', [ $this, 'output_enabled_methods_field' ] );
	}

	/**
	 * Field definitions for the settings page.
	 * Values are injected in output() via pre_option_* filters, not read
	 * individually from wp_options, because all settings share one option key.
	 *
	 * @param string $current_section Current section slug (single section; unused).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings( $current_section = '' ): array {
		return apply_filters(
			'woocommerce_get_settings_payjp',
			[
				// ── API / Mode ──────────────────────────────────────────────────
				[
					'title' => __( 'API 設定', 'payjp-for-wc' ),
					'type'  => 'title',
					'id'    => 'payjp_api_settings',
				],
				[
					'title'   => __( 'テストモード', 'payjp-for-wc' ),
					'type'    => 'checkbox',
					'id'      => 'payjp_test_mode',
					'default' => 'yes',
					'label'   => __( 'テスト環境の API キーを使用する', 'payjp-for-wc' ),
				],
				[
					'title'       => __( 'テスト公開鍵', 'payjp-for-wc' ),
					'type'        => 'text',
					'id'          => 'payjp_test_public_key',
					'default'     => '',
					'placeholder' => 'pk_test_',
					'desc_tip'    => true,
					'description' => __( 'PAY.JP ダッシュボード（テスト環境）から取得した公開鍵（pk_test_xxx）', 'payjp-for-wc' ),
				],
				[
					'title'       => __( 'テスト秘密鍵', 'payjp-for-wc' ),
					'type'        => 'password',
					'id'          => 'payjp_test_secret_key',
					'default'     => '',
					'placeholder' => 'sk_test_',
					'desc_tip'    => true,
					'description' => __( 'PAY.JP ダッシュボード（テスト環境）から取得した秘密鍵（sk_test_xxx）', 'payjp-for-wc' ),
				],
				[
					'title'       => __( '本番公開鍵', 'payjp-for-wc' ),
					'type'        => 'text',
					'id'          => 'payjp_live_public_key',
					'default'     => '',
					'placeholder' => 'pk_live_',
					'desc_tip'    => true,
					'description' => __( 'PAY.JP ダッシュボード（本番環境）から取得した公開鍵（pk_live_xxx）', 'payjp-for-wc' ),
				],
				[
					'title'       => __( '本番秘密鍵', 'payjp-for-wc' ),
					'type'        => 'password',
					'id'          => 'payjp_live_secret_key',
					'default'     => '',
					'placeholder' => 'sk_live_',
					'desc_tip'    => true,
					'description' => __( 'PAY.JP ダッシュボード（本番環境）から取得した秘密鍵（sk_live_xxx）', 'payjp-for-wc' ),
				],
				[
					'title'       => __( 'Webhook シークレット', 'payjp-for-wc' ),
					'type'        => 'password',
					'id'          => 'payjp_webhook_secret',
					'default'     => '',
					'desc_tip'    => true,
					'description' => __( 'PAY.JP の Webhook 認証トークン（X-Payjp-Webhook-Token ヘッダーの値）', 'payjp-for-wc' ),
				],
				[
					'type' => 'sectionend',
					'id'   => 'payjp_api_settings',
				],
				// ── Enabled payment methods ──────────────────────────────────
				[
					'title' => __( '有効にする決済手段', 'payjp-for-wc' ),
					'type'  => 'title',
					'id'    => 'payjp_methods_settings',
				],
				[
					'type' => 'payjp_enabled_methods',
					'id'   => 'payjp_enabled_methods',
				],
				[
					'type' => 'sectionend',
					'id'   => 'payjp_methods_settings',
				],
			]
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

		$option_map = [
			'payjp_test_mode'       => $test_mode ? 'yes' : 'no',
			'payjp_test_public_key' => (string) ( $current['test_public_key'] ?? '' ),
			'payjp_test_secret_key' => (string) ( $current['test_secret_key'] ?? '' ),
			'payjp_live_public_key' => (string) ( $current['live_public_key'] ?? '' ),
			'payjp_live_secret_key' => (string) ( $current['live_secret_key'] ?? '' ),
			'payjp_webhook_secret'  => (string) ( $current['webhook_secret'] ?? '' ),
		];

		$closures = [];
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
	 * Output the enabled payment methods checkbox rows.
	 * Called via the woocommerce_admin_field_payjp_enabled_methods action,
	 * which WC fires from output_fields() when it encounters our custom type.
	 *
	 * @param array<string, mixed> $value Field definition array (not used directly).
	 */
	public function output_enabled_methods_field( array $value ): void {
		$enabled = Payjp_Settings::get_enabled_methods();
		$methods = [
			'card'   => __( 'クレジットカード（PAY.JP）', 'payjp-for-wc' ),
			'paypay' => __( 'PayPay（PAY.JP）', 'payjp-for-wc' ),
		];
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( '決済手段', 'payjp-for-wc' ); ?></th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php esc_html_e( '有効にする決済手段', 'payjp-for-wc' ); ?></span>
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
				</fieldset>
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
		$enabled_methods_raw = isset( $_POST['payjp_enabled_methods'] ) ? (array) wp_unslash( $_POST['payjp_enabled_methods'] ) : [];
		$settings            = [
			'test_mode'       => ! empty( $_POST['payjp_test_mode'] ),
			'test_public_key' => sanitize_text_field( wp_unslash( (string) ( $_POST['payjp_test_public_key'] ?? '' ) ) ),
			'test_secret_key' => sanitize_text_field( wp_unslash( (string) ( $_POST['payjp_test_secret_key'] ?? '' ) ) ),
			'live_public_key' => sanitize_text_field( wp_unslash( (string) ( $_POST['payjp_live_public_key'] ?? '' ) ) ),
			'live_secret_key' => sanitize_text_field( wp_unslash( (string) ( $_POST['payjp_live_secret_key'] ?? '' ) ) ),
			'webhook_secret'  => sanitize_text_field( wp_unslash( (string) ( $_POST['payjp_webhook_secret'] ?? '' ) ) ),
			'enabled_methods' => array_values(
				array_intersect(
					array_map( 'sanitize_key', $enabled_methods_raw ),
					[ 'card', 'paypay' ]
				)
			),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( Payjp_Settings::OPTION_KEY, $settings );

		// Sync each gateway's own WC 'enabled' flag so WC_Payment_Gateway::is_available()
		// returns true when the method is enabled from this unified settings page.
		$gateway_option_keys = [
			'card'   => 'woocommerce_payjp_card_settings',
			'paypay' => 'woocommerce_payjp_paypay_settings',
		];
		foreach ( $gateway_option_keys as $method => $option_key ) {
			$gateway_settings            = (array) get_option( $option_key, [] );
			$gateway_settings['enabled'] = in_array( $method, $settings['enabled_methods'], true ) ? 'yes' : 'no';
			update_option( $option_key, $gateway_settings );
		}

		WC_Admin_Settings::add_message( __( 'PAY.JP の設定を保存しました。', 'payjp-for-wc' ) );
	}
}
