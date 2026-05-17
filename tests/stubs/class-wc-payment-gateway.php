<?php
/**
 * Minimal WC_Payment_Gateway and WC_Payment_Gateway_CC stubs for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WC_Payment_Gateway used in PHPUnit tests.
 * Provides only the properties and methods called by WC_Gateway_Payjp and subclasses.
 */
abstract class WC_Payment_Gateway {

	/** @var string */
	public string $id = '';

	/** @var string */
	public string $title = '';

	/** @var string */
	public string $description = '';

	/** @var string */
	public string $enabled = 'no';

	/** @var string */
	public string $method_title = '';

	/** @var string */
	public string $method_description = '';

	/** @var bool */
	public bool $has_fields = false;

	/** @var string[] */
	public array $supports = [];

	/** @var array<string, mixed> */
	public array $form_fields = [];

	/** @var array<string, mixed> */
	protected array $settings = [];

	/**
	 * Initialise form fields (no-op in stub).
	 */
	public function init_form_fields(): void {}

	/**
	 * Initialise settings from the DB option (stub returns empty array).
	 */
	public function init_settings(): void {
		$this->settings = [];
	}

	/**
	 * @param string $key         Setting key.
	 * @param mixed  $empty_value Default value when key not found.
	 * @return mixed
	 */
	public function get_option( string $key, $empty_value = null ): mixed {
		return $this->settings[ $key ] ?? ( $empty_value ?? '' );
	}

	/**
	 * @return bool
	 */
	public function process_admin_options(): bool {
		return true;
	}

	/**
	 * @return bool
	 */
	public function is_available(): bool {
		return 'yes' === $this->enabled;
	}

	/**
	 * @param string $feature Feature to check.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return in_array( $feature, $this->supports, true );
	}
}

/**
 * Stub for WC_Payment_Token used in PHPUnit tests.
 */
class WC_Payment_Token {

	/** @var string */
	protected string $token = '';

	/** @var string */
	protected string $gateway_id = '';

	/** @var int */
	protected int $user_id = 0;

	/**
	 * @return string
	 */
	public function get_token(): string {
		return $this->token;
	}

	/**
	 * @param string $token Token value.
	 */
	public function set_token( string $token ): void {
		$this->token = $token;
	}

	/**
	 * @return string
	 */
	public function get_gateway_id(): string {
		return $this->gateway_id;
	}

	/**
	 * @param string $gateway_id Gateway ID.
	 */
	public function set_gateway_id( string $gateway_id ): void {
		$this->gateway_id = $gateway_id;
	}

	/**
	 * @return int
	 */
	public function get_user_id(): int {
		return $this->user_id;
	}

	/**
	 * @param int $user_id WordPress user ID.
	 */
	public function set_user_id( int $user_id ): void {
		$this->user_id = $user_id;
	}

	/**
	 * Persist the token (no-op in stub).
	 */
	public function save(): void {}
}

/**
 * Stub for WC_Payment_Token_CC used in PHPUnit tests.
 */
class WC_Payment_Token_CC extends WC_Payment_Token {

	/**
	 * @param string $type Card type (visa, mastercard, etc.).
	 */
	public function set_card_type( string $type ): void {}

	/**
	 * @param string $last4 Last 4 digits.
	 */
	public function set_last4( string $last4 ): void {}

	/**
	 * @param string $month Expiry month (1-12).
	 */
	public function set_expiry_month( string $month ): void {}

	/**
	 * @param string $year Expiry year (4 digits).
	 */
	public function set_expiry_year( string $year ): void {}
}

/**
 * Stub for WC_Payment_Gateway_CC used in PHPUnit tests.
 * Extends WC_Payment_Gateway and adds tokenization-related no-op methods.
 */
abstract class WC_Payment_Gateway_CC extends WC_Payment_Gateway {

	/**
	 * Enqueue tokenization scripts (no-op in stub).
	 */
	public function tokenization_script(): void {}

	/**
	 * Output saved payment methods HTML (no-op in stub).
	 */
	public function saved_payment_methods(): void {}

	/**
	 * Output the save-payment-method checkbox HTML (no-op in stub).
	 */
	public function save_payment_method_checkbox(): void {}

	/**
	 * @return WC_Payment_Token[]
	 */
	public function get_tokens(): array {
		return [];
	}
}
