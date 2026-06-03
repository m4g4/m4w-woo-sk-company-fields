<?php
/*
 * Plugin Name: M4W SK Company Fields
 * Description: IČO, DIČ, IČ DPH a "Nakupujem na firmu" polia pre WooCommerce checkout (SK/CZ).
 * Version: 1.1.1
 * Author: m4g4
 * License: MIT
 * Text Domain: m4w-sk-company-fields
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'M4W_SK_COMPANY_FIELDS_VERSION', '1.1.0' );
define( 'M4W_SK_COMPANY_FIELDS_DIR', __DIR__ );

class M4W_SK_Company_Fields {

	const FIELD_BUY_ON_COMPANY = 'billing_company_customer';
	const FIELD_ICO            = 'billing_ico';
	const FIELD_DIC            = 'billing_dic';
	const FIELD_IC_DPH         = 'billing_ic_dph';

	const OPTION_COMPANY_FIELDS_ENABLED = 'm4w_company_fields_enabled';

	private $country_settings = [];

	public function __construct() {
		$this->country_settings = [
			'SK' => [
				'address_format' => "{company}\n{ico}\n{dic}\n{ic_dph}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
				'show_ico_dic'   => true,
			],
			'CZ' => [
				'address_format' => "{company}\n{ico}\n{dic}\n{ic_dph}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
				'show_ico_dic'   => true,
			],
			'default' => [],
		];

		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		add_filter( 'woocommerce_checkout_fields', [ $this, 'add_vat_fields_to_checkout' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_fields' ] );
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_user_fields' ] );
		add_action( 'woocommerce_admin_order_data_before_billing_address', [ $this, 'display_admin_checkbox_field' ] );
		add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_admin_checkbox_field' ] );

		add_action( 'woocommerce_edit_account_form', [ $this, 'add_account_fields' ] );
		add_action( 'woocommerce_save_account_details', [ $this, 'save_account_fields' ] );
		add_action( 'woocommerce_checkout_init', [ $this, 'prefill_checkout_fields' ] );

		add_filter( 'woocommerce_order_formatted_billing_address', [ $this, 'add_ico_dic_to_order_address' ], 10, 2 );
		add_filter( 'woocommerce_formatted_address_replacements', [ $this, 'add_formatted_address_replacements' ], 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', [ $this, 'override_address_formats' ] );
		add_filter( 'woocommerce_admin_billing_fields', [ $this, 'add_admin_billing_fields' ], 10, 3 );

		if ( ! is_admin() ) {
			add_action( 'wp_footer', [ $this, 'enqueue_styles' ] );
			add_action( 'wp_footer', [ $this, 'enqueue_scripts' ] );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'm4w-sk-company-fields',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	public function enqueue_styles() {
		if ( ! is_checkout() && ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'm4w-sk-company-fields',
			plugins_url( 'css/m4w-sk-company-fields.css', __FILE__ ),
			[],
			M4W_SK_COMPANY_FIELDS_VERSION
		);
	}

	public function enqueue_scripts() {
		if ( ! is_checkout() && ! is_account_page() ) {
			return;
		}

		wp_enqueue_script(
			'm4w-sk-company-fields',
			plugins_url( 'js/m4w-sk-company-fields.js', __FILE__ ),
			[ 'jquery' ],
			M4W_SK_COMPANY_FIELDS_VERSION,
			true
		);
	}

	private function get_settings( $country = null ) {
		if ( $country === null ) {
			$country = $this->get_shop_country();
		}
		return isset( $this->country_settings[ $country ] )
			? $this->country_settings[ $country ]
			: $this->country_settings['default'];
	}

	private function get_shop_country() {
		$country = get_option( 'woocommerce_default_country', 'SK' );
		return substr( $country, 0, 2 );
	}

	public function register_settings() {
		register_setting(
			'm4w_sk_company_fields',
			self::OPTION_COMPANY_FIELDS_ENABLED
		);

		add_settings_section(
			'm4w_company_fields_section',
			__( 'Fakturačné údaje firmy', 'm4w-sk-company-fields' ),
			'__return_false',
			'm4w-sk-company-fields'
		);

		add_settings_field(
			self::OPTION_COMPANY_FIELDS_ENABLED,
			__( 'Zobraziť polia pre firmu na pokladni', 'm4w-sk-company-fields' ),
			[ $this, 'render_company_fields_enabled_field' ],
			'm4w-sk-company-fields',
			'm4w_company_fields_section'
		);
	}

	public function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Firemné polia', 'm4w-sk-company-fields' ),
			__( 'Firemné polia', 'm4w-sk-company-fields' ),
			'manage_woocommerce',
			'm4w-sk-company-fields',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Firemné polia (IČO, DIČ, IČ DPH)', 'm4w-sk-company-fields' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'm4w_sk_company_fields' );
				do_settings_sections( 'm4w-sk-company-fields' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_company_fields_enabled_field() {
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_COMPANY_FIELDS_ENABLED ); ?>"
				value="1"
				<?php checked( 1, get_option( self::OPTION_COMPANY_FIELDS_ENABLED ) ); ?>
			/>
			<?php esc_html_e( 'Zobraziť polia IČO, DIČ, IČ DPH a "Nakupujem na firmu" na pokladni', 'm4w-sk-company-fields' ); ?>
		</label>
		<?php
	}

	public function override_address_formats( $address_formats ) {
		$country  = $this->get_shop_country();
		$settings = $this->get_settings( $country );
		$address  = $settings['address_format'] ?? '';

		if ( ! empty( $address ) ) {
			$address_formats[ $country ] = $address;
		}

		return $address_formats;
	}

	public function add_formatted_address_replacements( $args, $address ) {
		$new_args = [];
		foreach ( $args as $key => $value ) {
			$new_args[ $key ] = $value;
		}
		
		$new_args['{ico}']          = ! empty( $address['ico'] ) ? __( 'IČO', 'm4w-sk-company-fields' ) . ': ' . $address['ico'] : '';
		$new_args['{dic}']          = ! empty( $address['dic'] ) ? __( 'DIČ', 'm4w-sk-company-fields' ) . ': ' . $address['dic'] : '';
		$new_args['{ic_dph}']       = ! empty( $address['ic_dph'] ) ? __( 'IČ DPH', 'm4w-sk-company-fields' ) . ': ' . $address['ic_dph'] : '';
		
		return $new_args;
	}

	public function add_ico_dic_to_order_address( $address, $order ) {
		$address['ico']    = self::get_ico( $order );
		$address['dic']    = self::get_dic( $order );
		$address['ic_dph'] = self::get_ic_dph( $order );
		return $address;
	}

	public function add_admin_billing_fields( $fields, $order = null, $context = '' ) {
		$country  = $order && is_callable( [ $order, 'get_billing_country' ] ) ? $order->get_billing_country() : $this->get_shop_country();
		$settings = $this->get_settings( $country );

		if ( empty( $settings['show_ico_dic'] ) ) {
			return $fields;
		}

		$ico_field = [
			'label' => __( 'IČO', 'm4w-sk-company-fields' ),
			'show'  => false,
		];

		$dic_field = [
			'label' => __( 'DIČ', 'm4w-sk-company-fields' ),
			'show'  => false,
		];

		$ic_dph_field = [
			'label' => __( 'IČ DPH', 'm4w-sk-company-fields' ),
			'show'  => false,
		];

		$new_fields = [];
		$inserted   = false;
		foreach ( $fields as $key => $value ) {
			$new_fields[ $key ] = $value;
			if ( $key === 'company' ) {
				$new_fields['ico'] = $ico_field;
				$new_fields['dic'] = $dic_field;
				$new_fields['ic_dph'] = $ic_dph_field;
				$inserted = true;
			}
		}

		if ( ! $inserted ) {
			$new_fields['ico'] = $ico_field;
			$new_fields['dic'] = $dic_field;
			$new_fields['ic_dph'] = $ic_dph_field;
		}

		return $new_fields;
	}

	public function add_vat_fields_to_checkout( $fields ) {
		if ( ! isset( $fields['billing'] ) ) {
			return $fields;
		}

		if ( ! get_option( self::OPTION_COMPANY_FIELDS_ENABLED ) ) {
			return $fields;
		}

		$billingFields = $fields['billing'];
		$newFields     = [];
		$inserted      = false;

		foreach ( $billingFields as $key => $value ) {
			$newFields[ $key ] = $value;

			if ( $key === 'billing_last_name' ) {
				$newFields[ self::FIELD_BUY_ON_COMPANY ] = [
					'type'        => 'checkbox',
					'id'          => 'billing_company_customer',
					'label'       => __( 'Nakupujem na firmu', 'm4w-sk-company-fields' ),
					'input_class' => [ 'wc-enhanced-checkbox' ],
					'priority'    => 20,
					'class'       => [ 'form-row-wide' ],
				];
			} elseif ( $key === 'billing_company' && !$inserted) {
				$newFields[ self::FIELD_ICO ] = [
					'type'        => 'text',
					'label'       => __( 'IČO', 'm4w-sk-company-fields' ),
					'placeholder' => __( 'IČO', 'm4w-sk-company-fields' ),
					'priority'    => 30,
					'class'       => [ 'form-row-wide', 'company-field' ],
				];
				$newFields[ self::FIELD_DIC ] = [
					'type'        => 'text',
					'label'       => __( 'DIČ', 'm4w-sk-company-fields' ),
					'placeholder' => __( 'DIČ', 'm4w-sk-company-fields' ),
					'priority'    => 31,
					'class'       => [ 'form-row-wide', 'company-field' ],
				];
				$newFields[ self::FIELD_IC_DPH ] = [
					'type'        => 'text',
					'label'       => __( 'IČ DPH', 'm4w-sk-company-fields' ),
					'placeholder' => __( 'IČ DPH', 'm4w-sk-company-fields' ),
					'priority'    => 32,
					'class'       => [ 'form-row-wide', 'company-field' ],
				];
				$inserted = true;
			}
		}

		$fields['billing'] = $newFields;
		return $fields;
	}

	public function prefill_checkout_fields() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$ico = $this->get_user_company_field( $user_id, self::FIELD_ICO );

		if ( ! empty( $ico ) ) {
			add_filter( 'woocommerce_checkout_get_value', function ( $value, $key ) use ( $user_id ) {
				switch ( $key ) {
					case self::FIELD_BUY_ON_COMPANY:
						return $this->get_user_company_field( $user_id, self::FIELD_BUY_ON_COMPANY ) ? '1' : '';
					case self::FIELD_ICO:
						return $this->get_user_company_field( $user_id, self::FIELD_ICO );
					case self::FIELD_DIC:
						return $this->get_user_company_field( $user_id, self::FIELD_DIC );
					case self::FIELD_IC_DPH:
						return $this->get_user_company_field( $user_id, self::FIELD_IC_DPH );
				}
				return $value;
			}, 10, 2 );
		}
	}

	public function validate_checkout_fields() {
		if ( ! get_option( self::OPTION_COMPANY_FIELDS_ENABLED ) ) {
			return;
		}

		$is_company = ! empty( $_POST[ self::FIELD_BUY_ON_COMPANY ] ) || ! empty( $_POST[ self::get_field_meta_key( self::FIELD_BUY_ON_COMPANY ) ] );
		if ( ! $is_company ) {
			return;
		}

		$ico    = $this->get_posted_field( self::FIELD_ICO );
		$dic    = $this->get_posted_field( self::FIELD_DIC );
		$ic_dph = $this->get_posted_field( self::FIELD_IC_DPH );

		if ( empty( $ico ) ) {
			wc_add_notice(
				__( 'Ak nakupujete na firmu, zadajte aspoň IČO.', 'm4w-sk-company-fields' ),
				'error'
			);
		} else {
			$ico_error = self::validate_ico( $ico );
			if ( $ico_error ) {
				wc_add_notice( $ico_error, 'error' );
			}
		}

		if ( ! empty( $dic ) ) {
			$dic_error = self::validate_dic( $dic );
			if ( $dic_error ) {
				wc_add_notice( $dic_error, 'error' );
			}
		}

		if ( ! empty( $ic_dph ) ) {
			$ic_dph_error = self::validate_ic_dph( $ic_dph );
			if ( $ic_dph_error ) {
				wc_add_notice( $ic_dph_error, 'error' );
			}
		}
	}

	public function save_checkout_user_fields( $_order_id ) {
		$ico    = $this->get_posted_field( self::FIELD_ICO );
		$dic    = $this->get_posted_field( self::FIELD_DIC );
		$ic_dph = $this->get_posted_field( self::FIELD_IC_DPH );

		$user_id = get_current_user_id();
		if ( $user_id && ! empty( $ico ) ) {
			update_user_meta( $user_id, self::FIELD_BUY_ON_COMPANY, ! empty( $_POST[ self::FIELD_BUY_ON_COMPANY ] ) ? '1' : '0' );
			update_user_meta( $user_id, self::FIELD_ICO, $ico );
			update_user_meta( $user_id, self::FIELD_DIC, $dic );
			update_user_meta( $user_id, self::FIELD_IC_DPH, $ic_dph );
		}
	}

	public function display_admin_checkbox_field( $order ) {
		$country  = $order->get_billing_country();
		$settings = $this->get_settings( $country );

		if ( empty( $settings['show_ico_dic'] ) ) {
			return;
		}

		$company_customer = $order->get_meta( self::get_field_meta_key( self::FIELD_BUY_ON_COMPANY ) );
		?>
		<div class="order-vat-checkbox">
			<p class="form-field form-field-wide">
				<input type="checkbox"
					name="<?php echo esc_attr( self::FIELD_BUY_ON_COMPANY ); ?>"
					id="<?php echo esc_attr( self::FIELD_BUY_ON_COMPANY ); ?>"
					value="1"
					<?php checked( $company_customer, '1' ); ?>>
				<label for="<?php echo esc_attr( self::FIELD_BUY_ON_COMPANY ); ?>">
					<?php _e( 'Nakupujem na firmu', 'm4w-sk-company-fields' ); ?>
				</label>
			</p>
		</div>
		<?php
	}

	public function save_admin_checkbox_field( $order_id ) {
		$value = isset( $_POST[ self::FIELD_BUY_ON_COMPANY ] ) ? '1' : '0';
		update_post_meta( $order_id, self::get_field_meta_key( self::FIELD_BUY_ON_COMPANY ), $value );
	}

	public function add_account_fields() {
		if ( ! get_option( self::OPTION_COMPANY_FIELDS_ENABLED ) ) {
			return;
		}

		$user_id         = get_current_user_id();
		$company_customer = $this->get_user_company_field( $user_id, self::FIELD_BUY_ON_COMPANY );
		$ico             = $this->get_user_company_field( $user_id, self::FIELD_ICO );
		$dic             = $this->get_user_company_field( $user_id, self::FIELD_DIC );
		$ic_dph          = $this->get_user_company_field( $user_id, self::FIELD_IC_DPH );

		woocommerce_form_field( self::FIELD_BUY_ON_COMPANY, [
			'type'        => 'checkbox',
			'label'       => __( 'Nakupujem na firmu', 'm4w-sk-company-fields' ),
			'input_class' => [ 'wc-enhanced-checkbox' ],
			'class'       => [ 'form-row-wide' ],
		], $company_customer ? '1' : '' );

		echo '<div>';

		woocommerce_form_field( self::FIELD_ICO, [
			'type'  => 'text',
			'label' => __( 'IČO', 'm4w-sk-company-fields' ),
			'class' => [ 'form-row-wide' ],
		], $ico );

		woocommerce_form_field( self::FIELD_DIC, [
			'type'  => 'text',
			'label' => __( 'DIČ', 'm4w-sk-company-fields' ),
			'class' => [ 'form-row-wide' ],
		], $dic );

		woocommerce_form_field( self::FIELD_IC_DPH, [
			'type'  => 'text',
			'label' => __( 'IČ DPH', 'm4w-sk-company-fields' ),
			'class' => [ 'form-row-wide' ],
		], $ic_dph );

		echo '</div>';
	}

	public function save_account_fields( $user_id ) {
		update_user_meta( $user_id, self::FIELD_BUY_ON_COMPANY, ! empty( $_POST[ self::FIELD_BUY_ON_COMPANY ] ) ? '1' : '0' );
		update_user_meta( $user_id, self::FIELD_ICO, $this->get_posted_field( self::FIELD_ICO ) );
		update_user_meta( $user_id, self::FIELD_DIC, $this->get_posted_field( self::FIELD_DIC ) );
		update_user_meta( $user_id, self::FIELD_IC_DPH, $this->get_posted_field( self::FIELD_IC_DPH ) );
	}

	/*_emails( $type, $order, $sent_to_admin, $plain_text ) {
		if ( $type !== 'billing' || $plain_text ) {
			return;
		}

		$ico    = self::get_ico( $order );
		$dic    = self::get_dic( $order );
		$ic_dph = self::get_ic_dph( $order );

		if ( ! $ico && ! $dic && ! $ic_dph ) {
			return;
		}

		echo '<br/>';
		if ( $ico ) {
			echo esc_html__( 'IČO', 'm4w-sk-company-fields' ) . ': ' . esc_html( $ico ) . '<br/>';
		}
		if ( $dic ) {
			echo esc_html__( 'DIČ', 'm4w-sk-company-fields' ) . ': ' . esc_html( $dic ) . '<br/>';
		}
		if ( $ic_dph ) {
			echo esc_html__( 'IČ DPH', 'm4w-sk-company-fields' ) . ': ' . esc_html( $ic_dph ) . '<br/>';
		}
	}*/

	public static function validate_ico( $ico ) {
		$ico = preg_replace( '/\s+/', '', $ico );

		if ( ! preg_match( '/^\d{8}$/', $ico ) ) {
			return __( 'IČO musí mať presne 8 číslic.', 'm4w-sk-company-fields' );
		}

		$weights = array( 8, 7, 6, 5, 4, 3, 2 );
		$sum     = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += intval( $ico[ $i ] ) * $weights[ $i ];
		}

		$remainder = $sum % 11;

		if ( $remainder === 0 ) {
			$check = 1;
		} elseif ( $remainder === 1 ) {
			return __( 'Zadané IČO nie je platné (neplatný kontrolný súčet).', 'm4w-sk-company-fields' );
		} else {
			$check = 11 - $remainder;
		}

		if ( intval( $ico[7] ) !== $check ) {
			return __( 'Zadané IČO nie je platné (neplatný kontrolný súčet).', 'm4w-sk-company-fields' );
		}

		return '';
	}

	public static function validate_dic( $dic ) {
		$dic = preg_replace( '/\s+/', '', $dic );

		if ( ! preg_match( '/^\d{10}$/', $dic ) ) {
			return __( 'DIČ musí mať presne 10 číslic.', 'm4w-sk-company-fields' );
		}

		return '';
	}

	public static function validate_ic_dph( $ic_dph ) {
		$ic_dph = preg_replace( '/\s+/', '', strtoupper( $ic_dph ) );

		if ( preg_match( '/^SK\d{10}$/', $ic_dph ) ) {
			return '';
		}

		if ( preg_match( '/^CZ\d{8,10}$/', $ic_dph ) ) {
			return '';
		}

		return __( 'IČ DPH musí byť v tvare SK za ktorým nasleduje 10 číslic (napr. SK1234567890).', 'm4w-sk-company-fields' );
	}

	public static function get_ico( $order ) {
		return self::get_order_meta( $order, self::FIELD_ICO );
	}

	public static function get_dic( $order ) {
		return self::get_order_meta( $order, self::FIELD_DIC );
	}

	public static function get_ic_dph( $order ) {
		return self::get_order_meta( $order, self::FIELD_IC_DPH );
	}

	private static function get_order_meta( $order, $field ) {
		$meta_key = self::get_field_meta_key( $field );

		if ( is_numeric( $order ) ) {
			return get_post_meta( $order, $meta_key, true );
		}

		if ( $order && is_callable( [ $order, 'get_meta' ] ) ) {
			return $order->get_meta( $meta_key );
		}

		return '';
	}

	private static function get_field_meta_key( $field ) {
		return '_' . $field;
	}

	private function get_posted_field( $field ) {
		if ( isset( $_POST[ $field ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		}

		$meta_key = self::get_field_meta_key( $field );
		if ( isset( $_POST[ $meta_key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) );
		}

		return '';
	}

	private function get_user_company_field( $user_id, $field ) {
		$value = get_user_meta( $user_id, $field, true );
		if ( $value !== '' ) {
			return $value;
		}

		return get_user_meta( $user_id, self::get_field_meta_key( $field ), true );
	}
}

if ( ! function_exists( 'm4w_sk_company_fields_bootstrap' ) ) {
	function m4w_sk_company_fields_bootstrap() {
		static $loaded = false;

		if ( $loaded || ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) ) {
			return;
		}

		$loaded = true;
		new M4W_SK_Company_Fields();
	}
}

if ( did_action( 'plugins_loaded' ) ) {
	m4w_sk_company_fields_bootstrap();
} else {
	add_action( 'plugins_loaded', 'm4w_sk_company_fields_bootstrap', 0 );
}
