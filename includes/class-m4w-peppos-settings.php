<?php
/**
 * Peppos e-invoicing settings.
 *
 * @package M4W_SK_Company_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'M4W_Peppos_Settings' ) ) {

	class M4W_Peppos_Settings {

		const OPTION = 'm4w_peppos_settings';

		const ENV_SANDBOX    = 'sandbox';
		const ENV_PRODUCTION = 'production';

		/**
		 * Default settings.
		 *
		 * @return array
		 */
		public static function defaults() {
			return [
				'environment'      => self::ENV_SANDBOX,
				'client_id'        => '',
				'client_secret'    => '',
				'sender_peppol_id' => '',
				'webhook_secret'   => '',
				'poll_max_age_days' => 30,
				'auto_send'        => 1,
				'wait_for_result'  => 0,
				'send_or_report'   => 0,
				'lookup_by_vat'    => 1,
				'add_order_notes'  => 1,
				'notify_status_changes'   => 1,
				'notify_new_invoices'     => 1,
				'notification_recipients' => '',
			];
		}

		/**
		 * Get all settings or a single setting.
		 *
		 * @param string|null $key Setting key.
		 * @return mixed
		 */
		public static function get( $key = null ) {
			$settings = get_option( self::OPTION, [] );

			if ( ! is_array( $settings ) ) {
				$settings = [];
			}

			$settings = wp_parse_args( $settings, self::defaults() );

			if ( null === $key ) {
				return $settings;
			}

			return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
		}

		/**
		 * Update settings.
		 *
		 * @param array $settings Settings.
		 * @return void
		 */
		public static function update( array $settings ) {
			update_option( self::OPTION, wp_parse_args( $settings, self::defaults() ) );
		}

		/**
		 * Whether e-invoicing is enabled.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			return class_exists( 'M4W_SK_Company_Fields' ) && M4W_SK_Company_Fields::is_peppol_enabled();
		}

		/**
		 * Whether the API credentials are configured.
		 *
		 * @return bool
		 */
		public static function is_configured() {
			$settings = self::get();

			return ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );
		}

		/**
		 * Get the Peppos API base URL.
		 *
		 * @return string
		 */
		public static function get_api_base_url() {
			return self::ENV_PRODUCTION === self::get( 'environment' )
				? 'https://app.peppos.cz'
				: 'https://sandbox.peppos.cz';
		}

		/**
		 * Parse a Peppol ID in the form scheme:identifier.
		 *
		 * @param string $peppol_id Peppol ID.
		 * @return array|WP_Error
		 */
		public static function parse_peppol_id( $peppol_id ) {
			$peppol_id = trim( (string) $peppol_id );

			if ( false !== strpos( $peppol_id, '::' ) ) {
				$parts     = explode( '::', $peppol_id );
				$peppol_id = trim( (string) end( $parts ) );
			}

			if ( '' === $peppol_id ) {
				return new WP_Error( 'm4w_peppos_invalid_peppol_id', __( 'Peppol ID je prázdne.', 'm4w-sk-company-fields' ) );
			}

			if ( false === strpos( $peppol_id, ':' ) ) {
				return new WP_Error( 'm4w_peppos_invalid_peppol_id', __( 'Peppol ID musí byť v tvare schéma:identifikátor (napr. 0245:1234567890).', 'm4w-sk-company-fields' ) );
			}

			list( $scheme, $identifier ) = array_map( 'trim', explode( ':', $peppol_id, 2 ) );

			if ( '' === $scheme || '' === $identifier ) {
				return new WP_Error( 'm4w_peppos_invalid_peppol_id', __( 'Peppol ID musí byť v tvare schéma:identifikátor (napr. 0245:1234567890).', 'm4w-sk-company-fields' ) );
			}

			return [
				'peppol_id' => $scheme . ':' . $identifier,
				'scheme'    => $scheme,
				'identifier' => $identifier,
			];
		}

		/**
		 * Get the sender endpoint parsed from settings.
		 *
		 * @return array|WP_Error
		 */
		public static function get_sender_endpoint() {
			$sender = self::get( 'sender_peppol_id' );

			if ( empty( $sender ) ) {
				return new WP_Error( 'm4w_peppos_missing_sender', __( 'Peppol ID odosielateľa nie je nastavené.', 'm4w-sk-company-fields' ) );
			}

			return self::parse_peppol_id( $sender );
		}
	}
}
