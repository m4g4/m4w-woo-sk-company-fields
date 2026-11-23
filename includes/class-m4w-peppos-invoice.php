<?php
/**
 * B2B e-invoice interceptor and Peppos document handler.
 *
 * @package M4W_SK_Company_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'M4W_Peppos_Invoice' ) ) {

	class M4W_Peppos_Invoice {

		const META_DOCUMENT_ID       = '_m4w_peppos_document_id';
		const META_STATUS            = '_m4w_peppos_status';
		const META_MESSAGE           = '_m4w_peppos_message';
		const META_RECEIVER_PEPPOL_ID = '_m4w_peppos_receiver_peppol_id';
		const META_SENDER_PEPPOL_ID  = '_m4w_peppos_sender_peppol_id';
		const META_INVOICE_NUMBER    = '_m4w_peppos_invoice_number';
		const META_SENT_AT           = '_m4w_peppos_sent_at';
		const META_LAST_ERROR        = '_m4w_peppos_last_error';
		const META_ATTEMPTS          = '_m4w_peppos_attempts';
		const META_NEXT_POLL_AT      = '_m4w_peppos_next_poll_at';
		const META_POLL_ATTEMPTS     = '_m4w_peppos_poll_attempts';
		const META_POLL_STOPPED_AT   = '_m4w_peppos_poll_stopped_at';
		const META_WEBHOOK_EVENT_AT  = '_m4w_peppos_webhook_event_at';

		const WEBHOOK_EVENT_META = '_m4w_peppos_webhook_events';
		const MAX_WEBHOOK_EVENTS = 25;

		const STATUS_SKIPPED = 'skipped';
		const STATUS_FAILED  = 'failed';

		/**
		 * @var M4W_Peppos_Client
		 */
		private $client;

		/**
		 * Flag to prevent recursion when the interceptor creates a document itself.
		 *
		 * @var bool
		 */
		private $suppress_auto_send = false;

		/**
		 * Receiver resolved during the current UBL build.
		 *
		 * @var array|null
		 */
		private $current_receiver = null;

		public function __construct( M4W_Peppos_Client $client ) {
			$this->client = $client;
		}

		/**
		 * Get the Peppos API client.
		 *
		 * @return M4W_Peppos_Client
		 */
		public function get_client() {
			return $this->client;
		}

		/**
		 * Register the invoice-created interceptor.
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'wpo_wcpdf_save_document', [ $this, 'maybe_send_invoice' ], 20, 2 );
			add_action( 'm4w_peppos_poll_status', [ $this, 'poll_pending_documents' ] );
			add_action( 'rest_api_init', [ $this, 'register_webhook_route' ] );
		}

		/**
		 * Register the signed Peppos lifecycle webhook endpoint.
		 *
		 * @return void
		 */
		public function register_webhook_route() {
			register_rest_route(
				'm4w/v1',
				'/peppos-webhook',
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle_webhook' ],
					'permission_callback' => '__return_true',
				]
			);
		}

		/**
		 * Accept an authenticated Peppos lifecycle event.
		 *
		 * @param WP_REST_Request $request Webhook request.
		 * @return WP_REST_Response
		 */
		public function handle_webhook( $request ) {
			$secret    = (string) M4W_Peppos_Settings::get( 'webhook_secret' );
			$signature = trim( (string) $request->get_header( 'x-webhook-signature' ) );
			$body      = (string) $request->get_body();

			if ( '' === $secret || ! $this->is_valid_webhook_signature( $signature, $body, $secret ) ) {
				return new WP_REST_Response( [ 'error' => 'Invalid webhook signature.' ], 401 );
			}

			$event = json_decode( $body, true );
			if ( ! is_array( $event ) || empty( $event['event'] ) || empty( $event['data'] ) || ! is_array( $event['data'] ) ) {
				return new WP_REST_Response( [ 'error' => 'Invalid webhook payload.' ], 400 );
			}

			$data       = $event['data'];
			$document_id = isset( $data['documentId'] ) ? sanitize_text_field( (string) $data['documentId'] ) : '';
			$status      = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
			$event_type  = sanitize_text_field( (string) $event['event'] );

			$ignored_events = [
				'document.received',
				'invoice.response',
				'order.response',
				'account.verified',
				'sk.portal.received',
				'certificate.expiring',
			];
			if ( in_array( $event_type, $ignored_events, true ) ) {
				return new WP_REST_Response( [ 'received' => true, 'ignored' => true ], 200 );
			}

			if ( '' === $status ) {
				$status_map = [
					'document.sent'      => 'sent',
					'document.delivered' => 'delivered',
					'document.failed'    => 'failed',
				];
				$status = isset( $status_map[ $event_type ] ) ? $status_map[ $event_type ] : '';
			}

			if ( '' === $document_id || '' === $status ) {
				return new WP_REST_Response( [ 'error' => 'Webhook event is missing documentId or status.' ], 400 );
			}

			$orders = wc_get_orders(
				[
					'limit'      => 1,
					'meta_query' => [
						[
							'key'     => self::META_DOCUMENT_ID,
							'value'   => $document_id,
							'compare' => '=',
						],
					],
				]
			);

			if ( empty( $orders ) ) {
				// Peppos retries non-2xx responses. This covers the short race where the
				// webhook arrives before send_order() has saved its document ID.
				return new WP_REST_Response( [ 'received' => false, 'matched' => false ], 503 );
			}

			$order    = $orders[0];
			$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';

			if ( '' !== $event_id && $this->has_seen_webhook_event( $order, $event_id ) ) {
				return new WP_REST_Response( [ 'received' => true, 'duplicate' => true ], 200 );
			}

			$event_timestamp = $this->get_webhook_timestamp( $event, $data );
			$change = $this->apply_status_update( $order, $status, $data, 'webhook', $event_timestamp );
			$this->remember_webhook_event( $order, $event_id );
			if ( is_array( $change ) ) {
				do_action( 'm4w_peppos_status_changed', $order, $change['old_status'], $change['new_status'], $data, 'webhook' );
			}

			return new WP_REST_Response( [ 'received' => true, 'matched' => true ], 200 );
		}

		/**
		 * Verify Peppos' HMAC-SHA256 signature against the raw request body.
		 *
		 * @param string $signature Signature header.
		 * @param string $body      Raw request body.
		 * @param string $secret    Webhook secret.
		 * @return bool
		 */
		private function is_valid_webhook_signature( $signature, $body, $secret ) {
			if ( '' === $signature ) {
				return false;
			}

			$signature = preg_replace( '/^sha256=/i', '', $signature );
			$hex       = hash_hmac( 'sha256', $body, $secret );
			$base64    = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );

			return hash_equals( $hex, $signature ) || hash_equals( $base64, $signature );
		}

		/**
		 * Read the provider event timestamp when present.
		 *
		 * @param array $event Webhook envelope.
		 * @param array $data  Webhook data.
		 * @return int Unix timestamp, or zero when absent/unparseable.
		 */
		private function get_webhook_timestamp( array $event, array $data ) {
			foreach ( [ 'timestamp', 'createdAt', 'created_at', 'occurredAt', 'occurred_at' ] as $key ) {
				$value = isset( $event[ $key ] ) ? $event[ $key ] : ( isset( $data[ $key ] ) ? $data[ $key ] : '' );
				if ( is_numeric( $value ) ) {
					$timestamp = (int) $value;
					// Accept both Unix seconds and Unix milliseconds.
					return $timestamp > 9999999999 ? (int) floor( $timestamp / 1000 ) : $timestamp;
				}
				if ( is_string( $value ) && '' !== $value ) {
					$timestamp = strtotime( $value );
					if ( false !== $timestamp ) {
						return $timestamp;
					}
				}
			}
			return 0;
		}

		/**
		 * Check whether this webhook event has already been processed for the order.
		 *
		 * @param WC_Order $order    Order.
		 * @param string   $event_id Event ID.
		 * @return bool
		 */
		private function has_seen_webhook_event( WC_Order $order, $event_id ) {
			$seen = $order->get_meta( self::WEBHOOK_EVENT_META );
			return is_array( $seen ) && in_array( $event_id, $seen, true );
		}

		/**
		 * Store a bounded set of processed webhook event IDs.
		 *
		 * @param WC_Order $order    Order.
		 * @param string   $event_id Event ID.
		 * @return void
		 */
		private function remember_webhook_event( WC_Order $order, $event_id ) {
			if ( '' === $event_id ) {
				return;
			}

			$seen   = $order->get_meta( self::WEBHOOK_EVENT_META );
			$seen   = is_array( $seen ) ? $seen : [];
			$seen[] = $event_id;
			$order->update_meta_data( self::WEBHOOK_EVENT_META, array_slice( array_values( array_unique( $seen ) ), -self::MAX_WEBHOOK_EVENTS ) );
			$order->save();
		}

		/**
		 * Whether the given order is a B2B order.
		 *
		 * @param WC_Order $order Order.
		 * @return bool
		 */
		public function is_b2b_order( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return false;
			}

			$is_company = '1' === (string) $order->get_meta( '_billing_company_customer' );

			if ( ! $is_company && class_exists( 'M4W_SK_Company_Fields' ) ) {
				$is_company = '' !== (string) M4W_SK_Company_Fields::get_ico( $order )
					|| '' !== (string) M4W_SK_Company_Fields::get_dic( $order )
					|| '' !== (string) M4W_SK_Company_Fields::get_ic_dph( $order );
			}

			return (bool) apply_filters( 'm4w_peppos_is_b2b_order', $is_company, $order );
		}

		/**
		 * Interceptor: send the invoice when the PDF invoice is created.
		 *
		 * @param object $document PDF document.
		 * @param mixed  $order    Order object or ID.
		 * @return void
		 */
		public function maybe_send_invoice( $document, $order ) {
			if ( $this->suppress_auto_send || ! M4W_Peppos_Settings::is_enabled() ) {
				return;
			}

			if ( ! M4W_Peppos_Settings::get( 'auto_send' ) ) {
				return;
			}

			if ( ! $this->client->is_configured() || ! function_exists( 'wpo_ips_edi_write_file' ) ) {
				return;
			}

			if ( is_wp_error( M4W_Peppos_Settings::get_sender_endpoint() ) ) {
				return;
			}

			if ( is_object( $document ) && is_callable( [ $document, 'get_type' ] ) && 'invoice' !== $document->get_type() ) {
				return;
			}

			$order = $this->get_order( $order );
			if ( ! $order ) {
				return;
			}

			if ( ! $this->is_b2b_order( $order ) ) {
				return;
			}

			$this->send_order( $order, 'auto' );
		}

		/**
		 * Send an order invoice through Peppos.
		 *
		 * @param int|WC_Order $order   Order or order ID.
		 * @param string       $context Context (auto/manual/poll).
		 * @return true|WP_Error
		 */
		public function send_order( $order, $context = 'manual' ) {
			$order = $this->get_order( $order );

			if ( ! $order ) {
				return new WP_Error( 'm4w_peppos_missing_order', __( 'Order not found.', 'm4w-sk-company-fields' ) );
			}

			if ( ! M4W_Peppos_Settings::is_enabled() ) {
				return new WP_Error( 'm4w_peppos_disabled', __( 'Peppos e-invoicing is disabled.', 'm4w-sk-company-fields' ) );
			}

			if ( ! $this->client->is_configured() ) {
				return new WP_Error( 'm4w_peppos_missing_credentials', __( 'Peppos Client ID and Client Secret are not configured.', 'm4w-sk-company-fields' ) );
			}

			$sender = M4W_Peppos_Settings::get_sender_endpoint();
			if ( is_wp_error( $sender ) ) {
				return $sender;
			}

			if ( ! $this->is_b2b_order( $order ) ) {
				$this->set_status( $order, self::STATUS_SKIPPED, __( 'Order is not a B2B order.', 'm4w-sk-company-fields' ) );
				return new WP_Error( 'm4w_peppos_not_b2b', __( 'Order is not a B2B order.', 'm4w-sk-company-fields' ) );
			}

			$document = $this->ensure_invoice( $order );
			if ( is_wp_error( $document ) ) {
				$this->record_failure( $order, $document->get_error_message(), $context );
				return $document;
			}

			$invoice_number = (string) $order->get_meta( '_wcpdf_invoice_number' );
			if ( '' === $invoice_number && is_callable( [ $document, 'get_number' ] ) ) {
				$invoice_number = (string) $document->get_number();
			}

			$previous_status = (string) $order->get_meta( self::META_STATUS );
			$previous_number = (string) $order->get_meta( self::META_INVOICE_NUMBER );

			if (
				$invoice_number === $previous_number
				&& in_array( $previous_status, [ 'accepted', 'processing', 'pending', 'sent', 'delivered', 'confirmed', 'received' ], true )
			) {
				if ( 'manual' === $context ) {
					$this->add_note(
						$order,
						sprintf(
							/* translators: 1: invoice number, 2: status */
							__( 'Peppos e-invoice for invoice %1$s was already submitted (status: %2$s). Skipping duplicate.', 'm4w-sk-company-fields' ),
							$invoice_number,
							$previous_status
						)
					);
				}

				return true;
			}

			$receiver = $this->resolve_receiver( $order );
			if ( is_wp_error( $receiver ) ) {
				$this->record_failure( $order, $receiver->get_error_message(), $context );
				return $receiver;
			}

			$order->update_meta_data( self::META_RECEIVER_PEPPOL_ID, $receiver['peppol_id'] );
			$order->update_meta_data( self::META_SENDER_PEPPOL_ID, $sender['peppol_id'] );
			$order->update_meta_data( self::META_INVOICE_NUMBER, $invoice_number );
			$order->update_meta_data( self::META_ATTEMPTS, (int) $order->get_meta( self::META_ATTEMPTS ) + 1 );
			$order->delete_meta_data( self::META_LAST_ERROR );
			$order->save();

			$xml = $this->build_ubl_xml( $order, $receiver );
			if ( is_wp_error( $xml ) ) {
				$this->record_failure( $order, $xml->get_error_message(), $context );
				return $xml;
			}

			$response = $this->client->send_document(
				$xml,
				[
					'receiver_peppol_id' => $receiver['peppol_id'],
					'document_number'    => $invoice_number,
					'wait_for_result'    => (bool) M4W_Peppos_Settings::get( 'wait_for_result' ),
					'send_or_report'     => (bool) M4W_Peppos_Settings::get( 'send_or_report' ),
					'overwrite'          => in_array( $previous_status, [ 'failed', 'rejected' ], true ),
				]
			);

			if ( is_wp_error( $response ) ) {
				$this->record_failure( $order, $response->get_error_message(), $context );
				return $response;
			}

			$status_code = isset( $response['status_code'] ) ? (int) $response['status_code'] : 0;

			if ( ! $response['success'] ) {
				$message = $this->client->extract_error_message(
					$response['body'],
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Peppos rejected the document (HTTP %d).', 'm4w-sk-company-fields' ),
						$status_code
					)
				);

				$this->record_failure( $order, $message, $context );
				return new WP_Error( 'm4w_peppos_send_failed', $message, [ 'response' => $response['body'] ] );
			}

			$data          = isset( $response['body']['data'] ) && is_array( $response['body']['data'] ) ? $response['body']['data'] : [];
			$document_id   = isset( $data['documentId'] ) ? (string) $data['documentId'] : '';
			$remote_status = isset( $data['status'] ) ? (string) $data['status'] : 'accepted';
			$message       = $this->get_status_message( $remote_status, $data );
			$previous_document_id = (string) $order->get_meta( self::META_DOCUMENT_ID );

			if ( ! empty( $document_id ) ) {
				$order->update_meta_data( self::META_DOCUMENT_ID, $document_id );
			}
			if ( '' !== $document_id && $document_id !== $previous_document_id ) {
				$order->delete_meta_data( self::META_WEBHOOK_EVENT_AT );
				$order->delete_meta_data( self::WEBHOOK_EVENT_META );
			}

			$order->update_meta_data( self::META_STATUS, $remote_status );
			$order->update_meta_data( self::META_MESSAGE, $message );
			$order->update_meta_data( self::META_SENT_AT, current_time( 'mysql', true ) );
			$order->update_meta_data( self::META_POLL_ATTEMPTS, 0 );

			$transmission_error = $this->get_transmission_error( $remote_status, $data );

			if ( '' !== $transmission_error ) {
				$order->update_meta_data( self::META_LAST_ERROR, $transmission_error );
			} else {
				$order->delete_meta_data( self::META_LAST_ERROR );
			}

			$schedule_changed = false;
			$this->schedule_next_poll( $order, $remote_status, $schedule_changed );

			$order->save();

			if ( in_array( $remote_status, [ 'failed', 'rejected' ], true ) ) {
				$error_message = '' !== $transmission_error ? $transmission_error : $message;

				$this->add_note(
					$order,
					sprintf(
						/* translators: 1: receiver Peppol ID, 2: status, 3: error message */
						__( 'Peppos e-invoice for %1$s ended with status %2$s: %3$s', 'm4w-sk-company-fields' ),
						$receiver['peppol_id'],
						$remote_status,
						$error_message
					)
				);

				do_action( 'm4w_peppos_document_failed', $order, $error_message, $context );
				do_action( 'm4w_peppos_status_changed', $order, $previous_status, $remote_status, $data, $context );

				return new WP_Error( 'm4w_peppos_document_' . $remote_status, $error_message, [ 'response' => $response['body'] ] );
			}

			$this->add_note(
				$order,
				sprintf(
					/* translators: 1: receiver Peppol ID, 2: status, 3: document ID */
					__( 'Peppos e-invoice sent to %1$s. Status: %2$s. Document ID: %3$s', 'm4w-sk-company-fields' ),
					$receiver['peppol_id'],
					$remote_status,
					$document_id ? $document_id : '-'
				)
			);

			do_action( 'm4w_peppos_document_sent', $order, $response['body'], $context );
			do_action( 'm4w_peppos_status_changed', $order, $previous_status, $remote_status, $data, $context );

			return true;
		}

		/**
		 * Poll Peppos for pending document statuses.
		 *
		 * @return void
		 */
		public function poll_pending_documents() {
			if ( ! M4W_Peppos_Settings::is_enabled() || ! $this->client->is_configured() ) {
				return;
			}

			$now        = time();
			$query_args = [
				'limit'      => 25,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => [
					'relation' => 'AND',
					[
						'key'     => self::META_DOCUMENT_ID,
						'compare' => 'EXISTS',
					],
					[
						'key'     => self::META_STATUS,
						'value'   => [ 'accepted', 'processing', 'pending', 'sent', 'delivered' ],
						'compare' => 'IN',
					],
					[
						'relation' => 'OR',
						[
							'key'     => self::META_NEXT_POLL_AT,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => self::META_NEXT_POLL_AT,
							'value'   => $now,
							'compare' => '<=',
							'type'    => 'NUMERIC',
						],
					],
					[
						'key'     => self::META_POLL_STOPPED_AT,
						'compare' => 'NOT EXISTS',
					],
				],
			];
			$orders = wc_get_orders( $query_args );

			$changes = [];

			foreach ( $orders as $order ) {
				$document_id = (string) $order->get_meta( self::META_DOCUMENT_ID );
				if ( '' === $document_id ) {
					continue;
				}

				$response = $this->client->get_document_status( $document_id );
				if ( is_wp_error( $response ) || ! $response['success'] ) {
					$changed = true;
					$this->schedule_next_poll( $order, (string) $order->get_meta( self::META_STATUS ), $changed, true );
					$order->save();
					continue;
				}

				$data = isset( $response['body']['data'] ) && is_array( $response['body']['data'] )
					? $response['body']['data']
					: [];

				$new_status = isset( $data['status'] ) ? (string) $data['status'] : '';
				if ( '' === $new_status ) {
					$changed = true;
					$this->schedule_next_poll( $order, (string) $order->get_meta( self::META_STATUS ), $changed, true );
					$order->save();
					continue;
				}

				$change = $this->apply_status_update( $order, $new_status, $data, 'poll' );
				if ( is_array( $change ) ) {
					$changes[] = $change;
				}
			}

			if ( ! empty( $changes ) ) {
				do_action( 'm4w_peppos_status_changes', $changes );
			}
		}

		/**
		 * Manually refresh an order's Peppos status.
		 *
		 * @param WC_Order $order Order.
		 * @return true|WP_Error
		 */
		public function refresh_document_status( WC_Order $order ) {
			$document_id = (string) $order->get_meta( self::META_DOCUMENT_ID );
			if ( '' === $document_id ) {
				return new WP_Error( 'm4w_peppos_missing_document_id', __( 'This order has no Peppos document ID.', 'm4w-sk-company-fields' ) );
			}

			$response = $this->client->get_document_status( $document_id );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['success'] ) ) {
				return new WP_Error( 'm4w_peppos_status_failed', $this->client->extract_error_message( $response['body'], __( 'Could not refresh the Peppos document status.', 'm4w-sk-company-fields' ) ) );
			}

			$data = isset( $response['body']['data'] ) && is_array( $response['body']['data'] ) ? $response['body']['data'] : [];
			$status = isset( $data['status'] ) ? (string) $data['status'] : '';
			if ( '' === $status ) {
				return new WP_Error( 'm4w_peppos_status_missing', __( 'Peppos did not return a document status.', 'm4w-sk-company-fields' ) );
			}

			$change = $this->apply_status_update( $order, $status, $data, 'manual_refresh' );
			if ( is_array( $change ) ) {
				do_action( 'm4w_peppos_status_changed', $order, $change['old_status'], $change['new_status'], $data, 'manual_refresh' );
			}
			return true;
		}

		/**
		 * Apply a status update once and return a notification payload when changed.
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $status New status.
		 * @param array    $data   Peppos response data.
		 * @param string   $source Update source.
		 * @return array|false
		 */
		private function apply_status_update( WC_Order $order, $status, array $data, $source, $event_timestamp = 0 ) {
			$old_status = (string) $order->get_meta( self::META_STATUS );
			$terminal   = [ 'confirmed', 'rejected', 'failed', 'received' ];
			$last_event_timestamp = (int) $order->get_meta( self::META_WEBHOOK_EVENT_AT );

			if ( 'webhook' === $source && $event_timestamp && $last_event_timestamp && $event_timestamp < $last_event_timestamp ) {
				return false;
			}

			// Ignore a delayed non-terminal event after a final result was recorded.
			if ( in_array( $old_status, $terminal, true ) && ! in_array( $status, $terminal, true ) ) {
				return false;
			}

			// Two different final outcomes are only ordered when Peppos supplies an
			// event timestamp. A current API snapshot may still correct the status.
			if ( 'webhook' === $source && in_array( $old_status, $terminal, true ) && in_array( $status, $terminal, true ) && $old_status !== $status && ( ! $event_timestamp || ( $last_event_timestamp && $event_timestamp <= $last_event_timestamp ) ) ) {
				return false;
			}

			// Transport progress is monotonic even when event timestamps are missing.
			$status_rank = [ 'accepted' => 0, 'processing' => 0, 'pending' => 0, 'sent' => 1, 'delivered' => 2, 'confirmed' => 3, 'rejected' => 3, 'failed' => 3, 'received' => 3 ];
			if ( 'webhook' === $source && isset( $status_rank[ $old_status ], $status_rank[ $status ] ) && $status_rank[ $status ] < $status_rank[ $old_status ] ) {
				return false;
			}

			$old_message = (string) $order->get_meta( self::META_MESSAGE );
			$old_error   = (string) $order->get_meta( self::META_LAST_ERROR );
			$new_message = $this->get_status_message( $status, $data );
			$new_error   = $this->get_transmission_error( $status, $data );
			$changed     = false;

			if ( $old_status !== $status ) {
				$order->update_meta_data( self::META_STATUS, $status );
				$order->update_meta_data( self::META_POLL_ATTEMPTS, 0 );
				$changed = true;
			}

			if ( 'webhook' === $source && $event_timestamp && $event_timestamp > $last_event_timestamp ) {
				$order->update_meta_data( self::META_WEBHOOK_EVENT_AT, $event_timestamp );
				$changed = true;
			}

			if ( $old_message !== $new_message ) {
				$order->update_meta_data( self::META_MESSAGE, $new_message );
				$changed = true;
			}

			if ( $old_error !== $new_error ) {
				if ( '' !== $new_error ) {
					$order->update_meta_data( self::META_LAST_ERROR, $new_error );
				} else {
					$order->delete_meta_data( self::META_LAST_ERROR );
				}

				$changed = true;
			}

			$this->schedule_next_poll( $order, $status, $changed, 'poll' === $source && $old_status === $status );

			if ( $changed ) {
				$order->save();
			}

			if ( $old_status !== $status ) {
				$this->add_note(
					$order,
					sprintf(
						/* translators: 1: old status, 2: new status */
						__( 'Peppos e-invoice status changed from %1$s to %2$s.', 'm4w-sk-company-fields' ),
						$old_status,
						$status
					)
				);

				return [
					'order'      => $order,
					'old_status' => $old_status,
					'new_status' => $status,
					'data'       => $data,
					'context'    => $source,
				];
			}

			return false;
		}

		/**
		 * Schedule the next fallback poll, or stop polling after the configured age.
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $status Current status.
		 * @param bool     $changed Updated by reference if scheduling metadata changed.
		 * @param bool     $count_attempt Whether this call represents an actual status poll.
		 * @return void
		 */
		private function schedule_next_poll( WC_Order $order, $status, &$changed, $count_attempt = false ) {
			$terminal = [ 'confirmed', 'rejected', 'failed', 'received' ];
			$next_poll = (int) $order->get_meta( self::META_NEXT_POLL_AT );

			if ( in_array( $status, $terminal, true ) ) {
				if ( $next_poll ) {
					$order->delete_meta_data( self::META_NEXT_POLL_AT );
					$changed = true;
				}
				if ( $order->get_meta( self::META_POLL_STOPPED_AT ) ) {
					$order->delete_meta_data( self::META_POLL_STOPPED_AT );
					$changed = true;
				}
				return;
			}

			$max_age_days = max( 1, absint( M4W_Peppos_Settings::get( 'poll_max_age_days' ) ) );
			$sent_at      = (string) $order->get_meta( self::META_SENT_AT );
			$sent_time    = '' !== $sent_at ? strtotime( $sent_at . ' UTC' ) : false;
			if ( false === $sent_time && $order->get_date_created() ) {
				$sent_time = $order->get_date_created()->getTimestamp();
			}

			if ( false !== $sent_time && $sent_time < time() - ( DAY_IN_SECONDS * $max_age_days ) ) {
				if ( ! $order->get_meta( self::META_POLL_STOPPED_AT ) ) {
					$order->update_meta_data( self::META_POLL_STOPPED_AT, time() );
					$changed = true;
				}
				if ( $next_poll ) {
					$order->delete_meta_data( self::META_NEXT_POLL_AT );
					$changed = true;
				}
				return;
			}

			$attempts = (int) $order->get_meta( self::META_POLL_ATTEMPTS );
			if ( $count_attempt ) {
				++$attempts;
				$order->update_meta_data( self::META_POLL_ATTEMPTS, $attempts );
			}
			$delay    = in_array( $status, [ 'sent', 'delivered' ], true )
				? min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** min( 5, $attempts ) ) )
				: HOUR_IN_SECONDS;

			$order->update_meta_data( self::META_NEXT_POLL_AT, time() + $delay );
			$order->delete_meta_data( self::META_POLL_STOPPED_AT );
			$changed = true;
		}

		/**
		 * Ensure the PDF invoice exists for an order.
		 *
		 * @param WC_Order $order Order.
		 * @return object|WP_Error
		 */
		private function ensure_invoice( WC_Order $order ) {
			if ( ! function_exists( 'WPO_WCPDF' ) ) {
				return new WP_Error(
					'm4w_peppos_missing_pdf_plugin',
					__( 'WooCommerce PDF Invoices & Packing Slips is not available.', 'm4w-sk-company-fields' )
				);
			}

			$wpo_wcpdf = WPO_WCPDF();

			if ( empty( $wpo_wcpdf->documents ) || ! is_callable( [ $wpo_wcpdf->documents, 'get_document' ] ) ) {
				return new WP_Error(
					'm4w_peppos_missing_pdf_plugin',
					__( 'WooCommerce PDF Invoices & Packing Slips is not available.', 'm4w-sk-company-fields' )
				);
			}

			$document = $wpo_wcpdf->documents->get_document( 'invoice', $order );

			if ( ! $document ) {
				return new WP_Error(
					'm4w_peppos_missing_invoice_document',
					__( 'The PDF invoice document is not available.', 'm4w-sk-company-fields' )
				);
			}

			if ( ! $document->exists() ) {
				$this->suppress_auto_send = true;

				try {
					$document->init();
					$document->save();
				} catch ( Throwable $e ) {
					return new WP_Error( 'm4w_peppos_invoice_create_failed', $e->getMessage() );
				} finally {
					$this->suppress_auto_send = false;
				}
			}

			return $document;
		}

		/**
		 * Resolve the receiver Peppol ID for an order.
		 *
		 * @param WC_Order $order Order.
		 * @return array|WP_Error
		 */
		private function resolve_receiver( WC_Order $order ) {
			$manual = $order->get_meta( self::META_RECEIVER_PEPPOL_ID );
			if ( ! empty( $manual ) ) {
				$parsed = M4W_Peppos_Settings::parse_peppol_id( $manual );
				if ( ! is_wp_error( $parsed ) ) {
					$parsed['source'] = 'order_meta';
					return $parsed;
				}
			}

			$existing_id  = (string) $order->get_meta( '_peppol_endpoint_id' );
			$existing_eas = (string) $order->get_meta( '_peppol_endpoint_eas' );

			if ( '' !== $existing_id && false !== strpos( $existing_id, ':' ) ) {
				$parsed = M4W_Peppos_Settings::parse_peppol_id( $existing_id );
				if ( ! is_wp_error( $parsed ) ) {
					$parsed['source'] = 'pdf_plugin';
					return $parsed;
				}
			}

			if ( '' !== $existing_id && '' !== $existing_eas ) {
				$parsed = M4W_Peppos_Settings::parse_peppol_id( $existing_eas . ':' . $existing_id );
				if ( ! is_wp_error( $parsed ) ) {
					$parsed['source'] = 'pdf_plugin';
					return $parsed;
				}
			}

			$ic_dph = class_exists( 'M4W_SK_Company_Fields' ) ? (string) M4W_SK_Company_Fields::get_ic_dph( $order ) : '';

			if ( '' !== $ic_dph && M4W_Peppos_Settings::get( 'lookup_by_vat' ) ) {
				$result = $this->lookup_peppol( 'vat', $ic_dph );
				if ( ! is_wp_error( $result ) ) {
					return $result;
				}
			}

			$country = strtoupper( (string) $order->get_billing_country() );
			$ico     = class_exists( 'M4W_SK_Company_Fields' ) ? (string) M4W_SK_Company_Fields::get_ico( $order ) : '';

			if ( '' !== $ico && in_array( $country, [ 'SK', 'CZ' ], true ) ) {
				$result = $this->lookup_peppol( 'ico', $ico, $country );
				if ( ! is_wp_error( $result ) ) {
					return $result;
				}
			}

			if ( 'SK' === $country ) {
				$dic = class_exists( 'M4W_SK_Company_Fields' ) ? preg_replace( '/\s+/', '', (string) M4W_SK_Company_Fields::get_dic( $order ) ) : '';

				if ( preg_match( '/^\d{10}$/', $dic ) ) {
					$result = $this->lookup_peppol( 'peppol', '0245:' . $dic );

					if ( ! is_wp_error( $result ) ) {
						return $result;
					}

					if ( M4W_Peppos_Settings::get( 'send_or_report' ) ) {
						return [
							'peppol_id'  => '0245:' . $dic,
							'scheme'     => '0245',
							'identifier' => $dic,
							'source'     => 'derived_dic',
						];
					}
				}
			}

			return new WP_Error(
				'm4w_peppos_receiver_not_found',
				__( 'The customer Peppol ID could not be resolved. Add the customer IČ DPH or IČO, or set the Peppol ID on the order.', 'm4w-sk-company-fields' )
			);
		}

		/**
		 * Look up a Peppol participant.
		 *
		 * @param string $type    Lookup type (vat/ico/peppol).
		 * @param string $value   Lookup value.
		 * @param string $country Country for ICO lookup.
		 * @return array|WP_Error
		 */
		private function lookup_peppol( $type, $value, $country = '' ) {
			if ( 'vat' === $type ) {
				$response = $this->client->lookup_by_vat( $value );
			} elseif ( 'peppol' === $type ) {
				$response = $this->client->lookup_by_peppol_id( $value );
			} else {
				$response = $this->client->lookup_by_ico( $country, $value );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 404 === (int) $response['status_code'] ) {
				return new WP_Error( 'm4w_peppos_receiver_not_found', __( 'Customer is not registered in the Peppol network.', 'm4w-sk-company-fields' ) );
			}

			if ( ! $response['success'] ) {
				return new WP_Error(
					'm4w_peppos_lookup_failed',
					$this->client->extract_error_message( $response['body'], __( 'Peppol lookup failed.', 'm4w-sk-company-fields' ) )
				);
			}

			$data       = isset( $response['body']['data'] ) && is_array( $response['body']['data'] ) ? $response['body']['data'] : [];
			$peppol_id  = isset( $data['peppolId'] ) ? (string) $data['peppolId'] : '';
			$exists     = ! empty( $data['exists'] );

			if ( ! $exists || '' === $peppol_id ) {
				return new WP_Error( 'm4w_peppos_receiver_not_found', __( 'Customer is not registered in the Peppol network.', 'm4w-sk-company-fields' ) );
			}

			$parsed = M4W_Peppos_Settings::parse_peppol_id( $peppol_id );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			$parsed['source'] = $type;

			return $parsed;
		}

		/**
		 * Build a Peppol BIS 3.0 UBL invoice using the PDF plugin EDI generator.
		 *
		 * @param WC_Order $order    Order.
		 * @param array    $receiver Resolved receiver.
		 * @return string|WP_Error
		 */
		private function build_ubl_xml( WC_Order $order, array $receiver ) {
			if ( ! function_exists( 'wpo_ips_edi_write_file' ) ) {
				return new WP_Error(
					'm4w_peppos_missing_edi',
					__( 'The PDF plugin EDI generator is not available. Enable EDI documents in the PDF plugin settings.', 'm4w-sk-company-fields' )
				);
			}

			$document = $this->ensure_invoice( $order );
			if ( is_wp_error( $document ) ) {
				return $document;
			}

			$sender      = M4W_Peppos_Settings::get_sender_endpoint();
			$sender_scheme = is_wp_error( $sender ) ? '' : $sender['scheme'];
			$sender_id     = is_wp_error( $sender ) ? '' : $sender['identifier'];

			$this->current_receiver = $receiver;

			$filters = [];

			$filters[] = [
				'wpo_ips_edi_is_available',
				static function () {
					return true;
				},
			];

			$filters[] = [
				'wpo_ips_edi_peppol_is_available',
				static function () {
					return true;
				},
			];

			$filters[] = [
				'wpo_ips_edi_current_syntax',
				static function () {
					return 'ubl';
				},
			];

			$filters[] = [
				'wpo_ips_edi_current_format',
				static function () {
					return 'peppol-bis-3p0';
				},
			];

			$filters[] = [
				'wpo_wcpdf_order_customer_vat_number_meta_keys',
				static function ( $keys ) {
					array_unshift( $keys, '_billing_ic_dph' );
					return array_values( array_unique( $keys ) );
				},
			];

			$filters[] = [
				'wpo_ips_edi_ubl_supplier_party',
				function ( $party ) use ( $sender_scheme, $sender_id ) {
					if ( '' === $sender_scheme || '' === $sender_id ) {
						return $party;
					}
					return $this->with_party_endpoint( $party, $sender_scheme, $sender_id );
				},
			];

			$filters[] = [
				'wpo_ips_edi_ubl_customer_party',
				function ( $party ) use ( $receiver ) {
					return $this->with_party_endpoint( $party, $receiver['scheme'], $receiver['identifier'] );
				},
			];

			foreach ( $filters as $filter ) {
				add_filter( $filter[0], $filter[1], 10, 3 );
			}

			$xml = null;

			try {
				$xml = wpo_ips_edi_write_file( $document, false, true );
			} catch ( Throwable $e ) {
				$xml = new WP_Error( 'm4w_peppos_ubl_failed', $e->getMessage() );
			} finally {
				foreach ( $filters as $filter ) {
					remove_filter( $filter[0], $filter[1], 10 );
				}
				$this->current_receiver = null;
			}

			if ( is_wp_error( $xml ) ) {
				return $xml;
			}

			if ( empty( $xml ) || false === strpos( $xml, '<Invoice' ) ) {
				return new WP_Error(
					'm4w_peppos_ubl_empty',
					__( 'The PDF plugin did not generate a UBL invoice. Check the EDI settings of the PDF plugin.', 'm4w-sk-company-fields' )
				);
			}

			return $xml;
		}

		/**
		 * Replace the party EndpointID element.
		 *
		 * @param array  $party      UBL party element.
		 * @param string $scheme     Peppol scheme.
		 * @param string $identifier Peppol identifier.
		 * @return array
		 */
		private function with_party_endpoint( $party, $scheme, $identifier ) {
			if ( ! is_array( $party ) || empty( $party['value'] ) || ! is_array( $party['value'] ) ) {
				return $party;
			}

			$elements = [];

			foreach ( $party['value'] as $element ) {
				if ( is_array( $element ) && isset( $element['name'] ) && 'cbc:EndpointID' === $element['name'] ) {
					continue;
				}
				$elements[] = $element;
			}

			array_unshift(
				$elements,
				[
					'name'       => 'cbc:EndpointID',
					'value'      => $identifier,
					'attributes' => [
						'schemeID' => $scheme,
					],
				]
			);

			$party['value'] = $elements;

			return $party;
		}

		/**
		 * Get a human readable status message.
		 *
		 * @param string $status Remote status.
		 * @param array  $data   Response data.
		 * @return string
		 */
		private function get_status_message( $status, array $data = [] ) {
			if ( ! empty( $data['waitForResultTimedOut'] ) ) {
				return __( 'The document was accepted and is still being processed by Peppos.', 'm4w-sk-company-fields' );
			}

			$messages = [
				'accepted'   => __( 'The document was accepted by Peppos.', 'm4w-sk-company-fields' ),
				'processing' => __( 'The document is being processed by Peppos.', 'm4w-sk-company-fields' ),
				'pending'    => __( 'The document is waiting to be sent.', 'm4w-sk-company-fields' ),
				'sent'       => __( 'The document was sent to the Peppol network.', 'm4w-sk-company-fields' ),
				'delivered'  => __( 'The receiving Access Point confirmed transport delivery; the buyer response may still follow.', 'm4w-sk-company-fields' ),
				'confirmed'  => __( 'The document was confirmed by the recipient.', 'm4w-sk-company-fields' ),
				'received'   => __( 'The document was received by the recipient.', 'm4w-sk-company-fields' ),
				'failed'     => __( 'The document could not be sent.', 'm4w-sk-company-fields' ),
				'rejected'   => __( 'The document was rejected.', 'm4w-sk-company-fields' ),
			];

			return isset( $messages[ $status ] ) ? $messages[ $status ] : $status;
		}

		/**
		 * Extract the transmission error from a Peppos document payload.
		 *
		 * @param string $status Document status.
		 * @param array  $data   Document data.
		 * @return string
		 */
		private function get_transmission_error( $status, array $data = [] ) {
			if ( isset( $data['error'] ) && is_scalar( $data['error'] ) && '' !== trim( (string) $data['error'] ) ) {
				return (string) $data['error'];
			}

			if ( isset( $data['messageError'] ) && is_scalar( $data['messageError'] ) && '' !== trim( (string) $data['messageError'] ) ) {
				return (string) $data['messageError'];
			}

			if (
				in_array( $status, [ 'failed', 'rejected' ], true )
				&& isset( $data['message'] )
				&& is_scalar( $data['message'] )
				&& '' !== trim( (string) $data['message'] )
			) {
				return (string) $data['message'];
			}

			return '';
		}

		/**
		 * Store a failure on the order.
		 *
		 * @param WC_Order $order   Order.
		 * @param string   $message Error message.
		 * @param string   $context Context.
		 * @return void
		 */
		private function record_failure( WC_Order $order, $message, $context = 'auto' ) {
			$previous_status = (string) $order->get_meta( self::META_STATUS );

			$order->update_meta_data( self::META_STATUS, self::STATUS_FAILED );
			$order->update_meta_data( self::META_MESSAGE, $message );
			$order->update_meta_data( self::META_LAST_ERROR, $message );
			$order->save();

			$this->add_note(
				$order,
				sprintf(
					/* translators: %s: error message */
					__( 'Peppos e-invoice was not sent: %s', 'm4w-sk-company-fields' ),
					$message
				)
			);

			do_action( 'm4w_peppos_document_failed', $order, $message, $context );
			do_action( 'm4w_peppos_status_changed', $order, $previous_status, self::STATUS_FAILED, [ 'messageError' => $message ], $context );
		}

		/**
		 * Store a skipped status on the order.
		 *
		 * @param WC_Order $order   Order.
		 * @param string   $status  Status.
		 * @param string   $message Message.
		 * @return void
		 */
		private function set_status( WC_Order $order, $status, $message ) {
			$order->update_meta_data( self::META_STATUS, $status );
			$order->update_meta_data( self::META_MESSAGE, $message );
			$order->save();
		}

		/**
		 * Add an order note when enabled.
		 *
		 * @param WC_Order $order Order.
		 * @param string   $note  Note.
		 * @return void
		 */
		private function add_note( WC_Order $order, $note ) {
			if ( ! M4W_Peppos_Settings::get( 'add_order_notes' ) ) {
				return;
			}

			$order->add_order_note( $note, false, true );
		}

		/**
		 * Resolve an order object.
		 *
		 * @param mixed $order Order or ID.
		 * @return WC_Order|null
		 */
		private function get_order( $order ) {
			if ( $order instanceof WC_Order ) {
				return $order;
			}

			if ( is_numeric( $order ) ) {
				$order = wc_get_order( (int) $order );
				return $order instanceof WC_Order ? $order : null;
			}

			return null;
		}
	}
}
