<?php
/**
 * Minimal Peppos/Dativery API client.
 *
 * @package M4W_SK_Company_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'M4W_Peppos_Client' ) ) {

	class M4W_Peppos_Client {

		const TOKEN_TRANSIENT_PREFIX = 'm4w_peppos_access_token_';

		/**
		 * Get the API base URL.
		 *
		 * @return string
		 */
		public function get_base_url() {
			return M4W_Peppos_Settings::get_api_base_url();
		}

		/**
		 * Whether the client has credentials.
		 *
		 * @return bool
		 */
		public function is_configured() {
			return M4W_Peppos_Settings::is_configured();
		}

		/**
		 * Verify that the configured API credentials can obtain a token.
		 *
		 * @return true|WP_Error
		 */
		public function test_connection() {
			$token = $this->get_access_token( true );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			if ( '' === (string) $token ) {
				return new WP_Error(
					'm4w_peppos_empty_token',
					__( 'Peppos vrátil prázdny prístupový token.', 'm4w-sk-company-fields' )
				);
			}

			return true;
		}

		/**
		 * Get an access token (cached for its lifetime).
		 *
		 * @param bool $force_refresh Force a new token.
		 * @return string|WP_Error
		 */
		public function get_access_token( $force_refresh = false ) {
			$settings = M4W_Peppos_Settings::get();

			if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
				return new WP_Error(
					'm4w_peppos_missing_credentials',
					__( 'Peppos ID klienta a tajný kľúč klienta nie sú nastavené.', 'm4w-sk-company-fields' )
				);
			}

			$cache_key = self::TOKEN_TRANSIENT_PREFIX . md5(
				$settings['environment'] . '|' . $settings['client_id'] . '|' . $settings['client_secret']
			);

			if ( ! $force_refresh ) {
				$cached_token = get_transient( $cache_key );
				if ( ! empty( $cached_token ) ) {
					return $cached_token;
				}
			}

			$response = wp_remote_post(
				$this->get_base_url() . '/api/auth/token',
				[
					'timeout' => 25,
					'headers' => [
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					],
					'body'    => wp_json_encode(
						[
							'grant_type'    => 'client_credentials',
							'client_id'     => $settings['client_id'],
							'client_secret' => $settings['client_secret'],
						]
					),
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $status_code < 200 || $status_code >= 300 || empty( $body['access_token'] ) ) {
				return new WP_Error(
					'm4w_peppos_auth_failed',
					$this->extract_error_message( $body, __( 'Prihlásenie do Pepposu zlyhalo.', 'm4w-sk-company-fields' ) ),
					[
						'status_code' => $status_code,
						'response'    => $body,
					]
				);
			}

			$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 900;
			$expires_in = max( 60, $expires_in - 60 );

			set_transient( $cache_key, $body['access_token'], $expires_in );

			return $body['access_token'];
		}

		/**
		 * Clear the cached access token.
		 *
		 * @return void
		 */
		public function clear_access_token() {
			$settings = M4W_Peppos_Settings::get();
			$cache_key = self::TOKEN_TRANSIENT_PREFIX . md5(
				$settings['environment'] . '|' . $settings['client_id'] . '|' . $settings['client_secret']
			);

			delete_transient( $cache_key );
		}

		/**
		 * Look up a Peppol participant by country and ICO.
		 *
		 * @param string $country Two-letter country code.
		 * @param string $ico     Company registration number.
		 * @return array|WP_Error
		 */
		public function lookup_by_ico( $country, $ico ) {
			$country = strtolower( trim( (string) $country ) );
			$ico     = preg_replace( '/\s+/', '', (string) $ico );

			if ( '' === $country || '' === $ico ) {
				return new WP_Error( 'm4w_peppos_invalid_lookup', __( 'Krajina a IČO sú povinné pre vyhľadanie v Peppole.', 'm4w-sk-company-fields' ) );
			}

			return $this->request(
				'GET',
				'/api/lookup/' . rawurlencode( $country ) . '/' . rawurlencode( $ico ),
				[ 'timeout' => 20 ]
			);
		}

		/**
		 * Look up a Peppol participant by VAT ID.
		 *
		 * @param string $vat_id VAT ID, including country prefix.
		 * @return array|WP_Error
		 */
		public function lookup_by_vat( $vat_id ) {
			$vat_id = preg_replace( '/\s+/', '', (string) $vat_id );

			if ( '' === $vat_id ) {
				return new WP_Error( 'm4w_peppos_invalid_lookup', __( 'IČ DPH je povinné pre vyhľadanie v Peppole.', 'm4w-sk-company-fields' ) );
			}

			return $this->request(
				'GET',
				'/api/lookup/vat/' . rawurlencode( $vat_id ),
				[ 'timeout' => 20 ]
			);
		}

		/**
		 * Look up a Peppol participant by its Peppol ID.
		 *
		 * @param string $peppol_id Peppol ID in scheme:identifier format.
		 * @return array|WP_Error
		 */
		public function lookup_by_peppol_id( $peppol_id ) {
			$peppol_id = trim( (string) $peppol_id );

			if ( '' === $peppol_id ) {
				return new WP_Error( 'm4w_peppos_invalid_lookup', __( 'Peppol ID je povinné pre vyhľadanie v Peppole.', 'm4w-sk-company-fields' ) );
			}

			return $this->request(
				'GET',
				'/api/lookup/peppol/' . rawurlencode( $peppol_id ),
				[ 'timeout' => 20 ]
			);
		}

		/**
		 * Send a UBL XML document via Peppos.
		 *
		 * @param string $xml                 UBL XML content.
		 * @param array  $args                Optional arguments.
		 * @return array|WP_Error
		 */
		public function send_document( $xml, array $args = [] ) {
			$settings = M4W_Peppos_Settings::get();

			$query = [];

			if ( ! empty( $args['receiver_peppol_id'] ) ) {
				$query['receiverParticipantId'] = $args['receiver_peppol_id'];
			}

			if ( ! empty( $args['document_number'] ) ) {
				$query['documentNumber'] = $args['document_number'];
			}

			if ( ! empty( $args['overwrite'] ) ) {
				$query['overwrite'] = 'true';
			}

			$wait_for_result = ! empty( $args['wait_for_result'] );
			if ( $wait_for_result ) {
				$query['wait-for-result'] = 'true';
			}

			if ( ! empty( $args['send_or_report'] ) ) {
				$query['send-or-report'] = 'true';
			}

			$path = '/api/documents/send';
			if ( ! empty( $query ) ) {
				$path .= '?' . http_build_query( $query );
			}

			$headers = [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/xml; charset=utf-8',
			];

			$sender = M4W_Peppos_Settings::get_sender_endpoint();
			if ( ! is_wp_error( $sender ) ) {
				$headers['X-Peppol-Participant-Id'] = $sender['peppol_id'];
			}

			return $this->request(
				'POST',
				$path,
				[
					'headers' => $headers,
					// The API can hold a wait-for-result request for up to 120 seconds.
					'timeout' => $wait_for_result ? 135 : 45,
					'body'    => $xml,
				]
			);
		}

		/**
		 * Get outbound document status.
		 *
		 * @param string $document_id Peppos document ID.
		 * @return array|WP_Error
		 */
		public function get_document_status( $document_id ) {
			$document_id = trim( (string) $document_id );

			if ( '' === $document_id ) {
				return new WP_Error( 'm4w_peppos_invalid_document_id', __( 'ID dokumentu je prázdne.', 'm4w-sk-company-fields' ) );
			}

			return $this->request(
				'GET',
				'/api/documents/' . rawurlencode( $document_id ) . '/status',
				[ 'timeout' => 30 ]
			);
		}

		/**
		 * List documents visible to the configured participant.
		 *
		 * @param array $args Filter arguments.
		 * @return array|WP_Error
		 */
		public function get_documents( array $args = [] ) {
			$query = [];

			$direction = isset( $args['direction'] ) ? sanitize_key( $args['direction'] ) : '';
			if ( in_array( $direction, [ 'inbound', 'outbound' ], true ) ) {
				$query['direction'] = $direction;
			}

			$status         = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
			$allowed_status = [ 'draft', 'accepted', 'processing', 'pending', 'sent', 'delivered', 'confirmed', 'received', 'rejected', 'failed' ];
			if ( in_array( $status, $allowed_status, true ) ) {
				$query['status'] = $status;
			}

			if ( ! empty( $args['search'] ) ) {
				$query['search'] = sanitize_text_field( $args['search'] );
			}

			if ( ! empty( $args['documentTypes'] ) ) {
				$query['documentTypes'] = sanitize_text_field( $args['documentTypes'] );
			}

			$limit         = isset( $args['limit'] ) ? absint( $args['limit'] ) : 25;
			$query['limit'] = min( 100, max( 1, $limit ) );

			if ( isset( $args['offset'] ) ) {
				$query['offset'] = max( 0, absint( $args['offset'] ) );
			}

			$sort_by = isset( $args['sortBy'] ) ? sanitize_text_field( $args['sortBy'] ) : 'createdAt';
			$allowed_sort = [ 'status', 'documentType', 'company', 'documentNumber', 'amount', 'validation', 'costCenter', 'issueDate', 'dueDate', 'messageSentAt', 'messageReceivedAt', 'createdAt', 'updatedAt', 'readAt' ];
			$query['sortBy'] = in_array( $sort_by, $allowed_sort, true ) ? $sort_by : 'createdAt';

			$sort_direction = isset( $args['sortDirection'] ) ? sanitize_key( $args['sortDirection'] ) : 'desc';
			$query['sortDirection'] = in_array( $sort_direction, [ 'asc', 'desc' ], true ) ? $sort_direction : 'desc';

			$path = '/api/documents';
			if ( ! empty( $query ) ) {
				$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
			}

			$headers = [];
			$sender  = M4W_Peppos_Settings::get_sender_endpoint();
			if ( ! is_wp_error( $sender ) ) {
				$headers['X-Peppol-Participant-Id'] = $sender['peppol_id'];
			}

			$response = $this->request(
				'GET',
				$path,
				[
					'timeout' => 30,
					'headers' => $headers,
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['success'] ) ) {
				return new WP_Error(
					'm4w_peppos_documents_failed',
					$this->extract_error_message(
						$response['body'],
						__( 'Nepodarilo sa načítať dokumenty z Pepposu.', 'm4w-sk-company-fields' )
					),
					[ 'status_code' => isset( $response['status_code'] ) ? $response['status_code'] : 0 ]
				);
			}

			if ( isset( $response['body']['data'] ) && is_array( $response['body']['data'] ) ) {
				return $response['body']['data'];
			}

			return [];
		}

		/**
		 * Validate a UBL document without storing it.
		 *
		 * @param string $xml UBL XML content.
		 * @return array|WP_Error
		 */
		public function validate_ubl( $xml ) {
			return $this->request(
				'POST',
				'/api/validate/ubl',
				[
					'headers' => [
						'Accept'       => 'application/json',
						'Content-Type' => 'application/xml; charset=utf-8',
					],
					'timeout' => 45,
					'body'    => $xml,
				]
			);
		}

		/**
		 * Execute an authenticated API request.
		 *
		 * @param string $method HTTP method.
		 * @param string $path   API path, including query string.
		 * @param array  $args   Request arguments.
		 * @return array|WP_Error
		 */
		private function request( $method, $path, array $args = [] ) {
			$token = $this->get_access_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = $this->do_request( $method, $path, $args, $token );

			// A cached token can expire or be revoked. Retry once with a fresh token.
			if ( ! is_wp_error( $response ) && 401 === (int) $response['status_code'] ) {
				$this->clear_access_token();
				$token = $this->get_access_token( true );

				if ( is_wp_error( $token ) ) {
					return $token;
				}

				$response = $this->do_request( $method, $path, $args, $token );
			}

			return $response;
		}

		/**
		 * Execute a single HTTP request.
		 *
		 * @param string $method HTTP method.
		 * @param string $path   API path.
		 * @param array  $args   Request arguments.
		 * @param string $token  Access token.
		 * @return array|WP_Error
		 */
		private function do_request( $method, $path, array $args, $token ) {
			$headers = [
				'Authorization'   => 'Bearer ' . $token,
				'Accept'          => 'application/json',
				'Accept-Language' => $this->get_accept_language(),
				'User-Agent'      => 'M4W-SK-Company-Fields/' . ( defined( 'M4W_SK_COMPANY_FIELDS_VERSION' ) ? M4W_SK_COMPANY_FIELDS_VERSION : '1.2.0' ),
			];

			if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
				$headers = array_merge( $headers, $args['headers'] );
			}

			$request_args = [
				'method'  => strtoupper( $method ),
				'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 30,
				'headers' => $headers,
			];

			if ( array_key_exists( 'body', $args ) && null !== $args['body'] ) {
				$request_args['body'] = $args['body'];
			}

			$response = wp_remote_request( $this->get_base_url() . $path, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );
			$body        = json_decode( $raw_body, true );

			if ( ! is_array( $body ) ) {
				$body = [
					'raw' => $raw_body,
				];
			}

			return [
				'status_code' => $status_code,
				'body'        => $body,
				'success'     => $status_code >= 200 && $status_code < 300,
			];
		}

		/**
		 * Get the Accept-Language header value.
		 *
		 * @return string
		 */
		private function get_accept_language() {
			$locale = str_replace( '_', '-', get_locale() );

			return apply_filters( 'm4w_peppos_accept_language', $locale );
		}

		/**
		 * Extract a human readable error message from an API response.
		 *
		 * @param mixed  $body            Decoded response body.
		 * @param string $fallback_message Fallback message.
		 * @return string
		 */
		public function extract_error_message( $body, $fallback_message = '' ) {
			if ( is_array( $body ) ) {
				if ( ! empty( $body['error']['message'] ) ) {
					$message = $body['error']['message'];
					if ( ! empty( $body['error']['code'] ) ) {
						$message .= ' (' . $body['error']['code'] . ')';
					}
					return $message;
				}

				if ( ! empty( $body['message'] ) && is_string( $body['message'] ) ) {
					return $body['message'];
				}

				if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
					return $body['error'];
				}
			}

			return $fallback_message ? $fallback_message : __( 'Neznáma chyba Peppos API.', 'm4w-sk-company-fields' );
		}
	}
}
