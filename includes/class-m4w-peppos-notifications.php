<?php
/**
 * Peppos e-mail notifications.
 *
 * @package M4W_SK_Company_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'M4W_Peppos_Notifications' ) ) {

	class M4W_Peppos_Notifications {

		const OPTION_STATE = 'm4w_peppos_notification_state';

		const INBOX_PAGE_LIMIT = 100;
		const INBOX_MAX_PAGES  = 5;
		const SEEN_LIMIT       = 1000;

		/**
		 * @var M4W_Peppos_Client
		 */
		private $client;

		public function __construct( M4W_Peppos_Client $client ) {
			$this->client = $client;
		}

		/**
		 * Register notification hooks.
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'm4w_peppos_status_changed', [ $this, 'handle_status_changed' ], 10, 5 );
			add_action( 'm4w_peppos_status_changes', [ $this, 'handle_status_changes' ], 10, 1 );
			add_action( 'm4w_peppos_poll_status', [ $this, 'poll_inbox' ], 20 );
		}

		/**
		 * Handle a single status change.
		 *
		 * @param WC_Order $order      Order.
		 * @param string   $old_status Previous status.
		 * @param string   $new_status New status.
		 * @param array    $data       Peppos response data.
		 * @param string   $context    Context.
		 * @return void
		 */
		public function handle_status_changed( $order, $old_status, $new_status, $data, $context ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$this->handle_status_changes(
				[
					[
						'order'      => $order,
						'old_status' => (string) $old_status,
						'new_status' => (string) $new_status,
						'data'       => is_array( $data ) ? $data : [],
						'context'    => (string) $context,
					],
				]
			);
		}

		/**
		 * Handle one or more status changes.
		 *
		 * @param array $changes Status changes.
		 * @return void
		 */
		public function handle_status_changes( $changes ) {
			if ( ! is_array( $changes ) || ! M4W_Peppos_Settings::get( 'notify_status_changes' ) ) {
				return;
			}

			$normalized = [];

			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) || empty( $change['order'] ) || ! $change['order'] instanceof WC_Order ) {
					continue;
				}

				$old_status = isset( $change['old_status'] ) ? (string) $change['old_status'] : '';
				$new_status = isset( $change['new_status'] ) ? (string) $change['new_status'] : '';

				if ( '' === $new_status || $old_status === $new_status ) {
					continue;
				}

				$change['old_status'] = $old_status;
				$change['new_status'] = $new_status;
				$change['data']       = isset( $change['data'] ) && is_array( $change['data'] ) ? $change['data'] : [];

				$normalized[] = $change;
			}

			if ( empty( $normalized ) ) {
				return;
			}

			$this->send_status_email( $normalized );
		}

		/**
		 * Poll inbound documents and notify about newly received ones.
		 *
		 * The first successful poll only stores the current inbox as the initial
		 * state, so existing documents do not trigger a flood of e-mails.
		 *
		 * @return void
		 */
		public function poll_inbox() {
			if (
				! M4W_Peppos_Settings::is_enabled()
				|| ! $this->client->is_configured()
				|| is_wp_error( M4W_Peppos_Settings::get_sender_endpoint() )
			) {
				return;
			}

			$documents = $this->fetch_inbound_documents();
			if ( is_wp_error( $documents ) ) {
				return;
			}

			$state       = $this->get_state();
			$seen        = $state['inbound_seen'];
			$initialized = ! empty( $state['inbound_initialized'] );
			$new_docs    = [];

			foreach ( $documents as $document ) {
				if ( ! is_array( $document ) ) {
					continue;
				}

				$document_id = isset( $document['id'] ) ? (string) $document['id'] : '';
				if ( '' === $document_id ) {
					continue;
				}

				if ( $initialized && ! isset( $seen[ $document_id ] ) ) {
					$new_docs[ $document_id ] = $document;
				}

				$seen[ $document_id ] = time();
			}

			$state['inbound_seen']        = $this->prune_seen( $seen );
			$state['inbound_initialized'] = 1;

			update_option( self::OPTION_STATE, $state, false );

			if ( $initialized && ! empty( $new_docs ) && M4W_Peppos_Settings::get( 'notify_new_invoices' ) ) {
				$this->send_new_documents_email( array_values( $new_docs ) );
			}
		}

		/**
		 * Fetch recent inbound invoices and credit notes.
		 *
		 * @return array|WP_Error
		 */
		private function fetch_inbound_documents() {
			$documents = [];

			for ( $page = 0; $page < self::INBOX_MAX_PAGES; $page++ ) {
				$batch = $this->client->get_documents(
					[
						'direction'     => 'inbound',
						'documentTypes' => 'invoice,creditnote',
						'limit'         => self::INBOX_PAGE_LIMIT,
						'offset'        => $page * self::INBOX_PAGE_LIMIT,
						'sortBy'        => 'createdAt',
						'sortDirection' => 'desc',
					]
				);

				if ( is_wp_error( $batch ) ) {
					return empty( $documents ) ? $batch : $documents;
				}

				if ( empty( $batch ) ) {
					break;
				}

				$documents = array_merge( $documents, $batch );

				if ( count( $batch ) < self::INBOX_PAGE_LIMIT ) {
					break;
				}
			}

			return $documents;
		}

		/**
		 * Get notification state.
		 *
		 * @return array
		 */
		private function get_state() {
			$state = get_option( self::OPTION_STATE, [] );

			if ( ! is_array( $state ) ) {
				$state = [];
			}

			return [
				'inbound_initialized' => ! empty( $state['inbound_initialized'] ) ? 1 : 0,
				'inbound_seen'        => isset( $state['inbound_seen'] ) && is_array( $state['inbound_seen'] )
					? $state['inbound_seen']
					: [],
			];
		}

		/**
		 * Keep only the most recently seen document IDs.
		 *
		 * @param array $seen Seen document IDs.
		 * @return array
		 */
		private function prune_seen( array $seen ) {
			arsort( $seen );

			return array_slice( $seen, 0, self::SEEN_LIMIT, true );
		}

		/**
		 * Send a status change notification.
		 *
		 * @param array $changes Status changes.
		 * @return void
		 */
		private function send_status_email( array $changes ) {
			$recipients = $this->get_recipients();
			if ( empty( $recipients ) ) {
				return;
			}

			$count     = count( $changes );
			$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject   = sprintf(
				/* translators: 1: site name, 2: number of changes */
				_n(
					'[%1$s] Peppos: aktualizácia e-faktúry (%2$d)',
					'[%1$s] Peppos: aktualizácie e-faktúr (%2$d)',
					$count,
					'm4w-sk-company-fields'
				),
				$site_name,
				$count
			);

			$lines   = [];
			$lines[] = __( 'Dobrý deň,', 'm4w-sk-company-fields' );
			$lines[] = '';
			$lines[] = 1 === $count
				? __( 'V Peppose došlo k zmene stavu e-faktúry:', 'm4w-sk-company-fields' )
				: __( 'V Peppose došlo k zmenám stavu e-faktúr:', 'm4w-sk-company-fields' );
			$lines[] = '';

			foreach ( $changes as $change ) {
				$order = $change['order'];

				$lines[] = sprintf(
					/* translators: %s: order number */
					__( 'Objednávka: #%s', 'm4w-sk-company-fields' ),
					$order->get_order_number()
				);

				$invoice_number = (string) $order->get_meta( M4W_Peppos_Invoice::META_INVOICE_NUMBER );
				if ( '' !== $invoice_number ) {
					$lines[] = sprintf(
						/* translators: %s: invoice number */
						__( 'Faktúra: %s', 'm4w-sk-company-fields' ),
						$invoice_number
					);
				}

				$receiver = (string) $order->get_meta( M4W_Peppos_Invoice::META_RECEIVER_PEPPOL_ID );
				if ( '' !== $receiver ) {
					$lines[] = sprintf(
						/* translators: %s: Peppol ID */
						__( 'Príjemca: %s', 'm4w-sk-company-fields' ),
						$receiver
					);
				}

				$lines[] = sprintf(
					/* translators: 1: old status, 2: new status */
					__( 'Stav: %1$s -> %2$s', 'm4w-sk-company-fields' ),
					$this->format_status( $change['old_status'] ),
					$this->format_status( $change['new_status'] )
				);

				$message = $this->get_change_message( $change );
				if ( '' !== $message ) {
					$lines[] = sprintf(
						/* translators: %s: status message */
						__( 'Správa: %s', 'm4w-sk-company-fields' ),
						$message
					);
				}

				$lines[] = sprintf(
					/* translators: %s: order edit URL */
					__( 'Detail objednávky: %s', 'm4w-sk-company-fields' ),
					$order->get_edit_order_url()
				);
				$lines[] = '';
			}

			$lines[] = sprintf(
				/* translators: %s: invoices overview URL */
				__( 'Prehľad odoslaných faktúr: %s', 'm4w-sk-company-fields' ),
				$this->get_invoices_url( 'outbound' )
			);

			$this->send_email( $recipients, $subject, implode( "\n", $lines ) );
		}

		/**
		 * Send a new inbound document notification.
		 *
		 * @param array $documents New documents.
		 * @return void
		 */
		private function send_new_documents_email( array $documents ) {
			$recipients = $this->get_recipients();
			if ( empty( $recipients ) ) {
				return;
			}

			$count     = count( $documents );
			$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject   = sprintf(
				/* translators: 1: site name, 2: number of new documents */
				_n(
					'[%1$s] Peppos: nový prijatý doklad (%2$d)',
					'[%1$s] Peppos: nové prijaté doklady (%2$d)',
					$count,
					'm4w-sk-company-fields'
				),
				$site_name,
				$count
			);

			$lines   = [];
			$lines[] = __( 'Dobrý deň,', 'm4w-sk-company-fields' );
			$lines[] = '';
			$lines[] = 1 === $count
				? __( 'V Peppose bol prijatý nový doklad:', 'm4w-sk-company-fields' )
				: __( 'V Peppose boli prijaté nové doklady:', 'm4w-sk-company-fields' );
			$lines[] = '';

			foreach ( $documents as $document ) {
				$number = $this->get_document_number( $document );

				$lines[] = sprintf(
					/* translators: %s: document number */
					__( 'Dokument: %s', 'm4w-sk-company-fields' ),
					'' !== $number ? $number : '-'
				);

				$type       = isset( $document['documentType'] ) ? (string) $document['documentType'] : '';
				$type_label = $this->format_document_type( $type );
				$lines[]    = sprintf(
					/* translators: %s: document type */
					__( 'Typ: %s', 'm4w-sk-company-fields' ),
					'' !== $type_label ? $type_label : '-'
				);

				$partner_name = isset( $document['company'] ) ? (string) $document['company'] : '';
				if ( '' !== $partner_name ) {
					$lines[] = sprintf(
						/* translators: %s: sender company name */
						__( 'Odosielateľ: %s', 'm4w-sk-company-fields' ),
						$partner_name
					);
				}

				$partner_id = $this->get_document_partner( $document );
				if ( '' !== $partner_id ) {
					$lines[] = sprintf(
						/* translators: %s: Peppol ID */
						__( 'Peppol ID odosielateľa: %s', 'm4w-sk-company-fields' ),
						$partner_id
					);
				}

				$date = $this->get_document_date( $document );
				if ( '' !== $date ) {
					$lines[] = sprintf(
						/* translators: %s: date and time */
						__( 'Prijaté: %s', 'm4w-sk-company-fields' ),
						$date
					);
				}

				$status = isset( $document['status'] ) ? (string) $document['status'] : '';
				$lines[] = sprintf(
					/* translators: %s: document status */
					__( 'Stav: %s', 'm4w-sk-company-fields' ),
					$this->format_status( $status )
				);

				$validation = isset( $document['validationStatus'] ) ? (string) $document['validationStatus'] : '';
				if ( '' !== $validation ) {
					$lines[] = sprintf(
						/* translators: %s: validation status */
						__( 'Validácia: %s', 'm4w-sk-company-fields' ),
						$this->format_validation( $validation )
					);
				}

				$lines[] = '';
			}

			$lines[] = sprintf(
				/* translators: %s: received documents overview URL */
				__( 'Prehľad prijatých dokladov: %s', 'm4w-sk-company-fields' ),
				$this->get_invoices_url( 'inbound' )
			);

			$this->send_email( $recipients, $subject, implode( "\n", $lines ) );
		}

		/**
		 * Get notification recipients.
		 *
		 * @return array
		 */
		private function get_recipients() {
			$raw    = (string) M4W_Peppos_Settings::get( 'notification_recipients' );
			$emails = preg_split( '/[\s,;]+/', $raw );
			$emails = is_array( $emails ) ? $emails : [];
			$emails = array_map( 'sanitize_email', $emails );
			$emails = array_filter( $emails, 'is_email' );

			if ( empty( $emails ) ) {
				$admin_email = sanitize_email( get_option( 'admin_email' ) );
				if ( is_email( $admin_email ) ) {
					$emails = [ $admin_email ];
				}
			}

			$emails = array_values( array_unique( $emails ) );

			return (array) apply_filters( 'm4w_peppos_notification_recipients', $emails );
		}

		/**
		 * Send an e-mail.
		 *
		 * @param array  $recipients Recipients.
		 * @param string $subject    Subject.
		 * @param string $message    Message.
		 * @return bool
		 */
		private function send_email( array $recipients, $subject, $message ) {
			$recipients = array_map( 'sanitize_email', $recipients );
			$recipients = array_filter( $recipients, 'is_email' );
			if ( empty( $recipients ) ) {
				return false;
			}

			$sent = wp_mail(
				$recipients,
				$subject,
				$message,
				[ 'Content-Type: text/plain; charset=UTF-8' ]
			);

			do_action( 'm4w_peppos_notification_sent', $sent, $recipients, $subject, $message );

			return $sent;
		}

		/**
		 * Get a status change message from API data.
		 *
		 * @param array $change Change data.
		 * @return string
		 */
		private function get_change_message( array $change ) {
			if ( ! empty( $change['data']['messageError'] ) ) {
				return (string) $change['data']['messageError'];
			}

			if ( ! empty( $change['data']['message'] ) ) {
				return (string) $change['data']['message'];
			}

			return '';
		}

		/**
		 * Format a status for display.
		 *
		 * @param string $status Status.
		 * @return string
		 */
		private function format_status( $status ) {
			if ( '' === $status ) {
				return __( 'bez stavu', 'm4w-sk-company-fields' );
			}

			$labels = [
				'draft'      => __( 'návrh', 'm4w-sk-company-fields' ),
				'pending'    => __( 'čaká na odoslanie', 'm4w-sk-company-fields' ),
				'accepted'   => __( 'prijatá Pepposom', 'm4w-sk-company-fields' ),
				'processing' => __( 'spracúva sa', 'm4w-sk-company-fields' ),
				'sent'       => __( 'odoslaná', 'm4w-sk-company-fields' ),
				'delivered'  => __( 'doručená do prístupového bodu', 'm4w-sk-company-fields' ),
				'confirmed'  => __( 'potvrdená', 'm4w-sk-company-fields' ),
				'received'   => __( 'prijatá', 'm4w-sk-company-fields' ),
				'rejected'   => __( 'zamietnutá', 'm4w-sk-company-fields' ),
				'failed'     => __( 'zlyhala', 'm4w-sk-company-fields' ),
				'skipped'    => __( 'preskočená', 'm4w-sk-company-fields' ),
			];

			return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		}

		/**
		 * Format a document type for display.
		 *
		 * @param string $type Document type.
		 * @return string
		 */
		private function format_document_type( $type ) {
			$labels = [
				'invoice'    => __( 'Faktúra', 'm4w-sk-company-fields' ),
				'creditnote' => __( 'Dobropis', 'm4w-sk-company-fields' ),
			];

			return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
		}

		/**
		 * Format a validation status for display.
		 *
		 * @param string $validation Validation status.
		 * @return string
		 */
		private function format_validation( $validation ) {
			$labels = [
				'valid'           => __( 'Platná', 'm4w-sk-company-fields' ),
				'invalid'         => __( 'Neplatná', 'm4w-sk-company-fields' ),
				'need-validation' => __( 'Na overenie', 'm4w-sk-company-fields' ),
			];

			return isset( $labels[ $validation ] ) ? $labels[ $validation ] : $validation;
		}

		/**
		 * Get a document number.
		 *
		 * @param array $document Document.
		 * @return string
		 */
		private function get_document_number( array $document ) {
			if ( ! empty( $document['documentNumber'] ) ) {
				return (string) $document['documentNumber'];
			}

			return isset( $document['extId'] ) ? (string) $document['extId'] : '';
		}

		/**
		 * Get the partner Peppol ID for a document.
		 *
		 * @param array $document Document.
		 * @return string
		 */
		private function get_document_partner( array $document ) {
			$direction = isset( $document['direction'] ) ? (string) $document['direction'] : '';

			if ( 'inbound' === $direction && ! empty( $document['senderPeppolId'] ) ) {
				return (string) $document['senderPeppolId'];
			}

			if ( ! empty( $document['receiverPeppolId'] ) ) {
				return (string) $document['receiverPeppolId'];
			}

			return isset( $document['senderPeppolId'] ) ? (string) $document['senderPeppolId'] : '';
		}

		/**
		 * Get a formatted document date.
		 *
		 * @param array $document Document.
		 * @return string
		 */
		private function get_document_date( array $document ) {
			if ( empty( $document['createdAt'] ) ) {
				return '';
			}

			$timestamp = strtotime( (string) $document['createdAt'] );

			return $timestamp ? wp_date( 'd.m.Y H:i', $timestamp ) : '';
		}

		/**
		 * Get the invoices overview URL.
		 *
		 * @param string $direction Direction.
		 * @return string
		 */
		private function get_invoices_url( $direction ) {
			return add_query_arg(
				[
					'page'             => M4W_SK_Company_Fields::PAGE_SLUG,
					'tab'              => M4W_SK_Company_Fields::TAB_INVOICES,
					'peppos_direction' => $direction,
				],
				admin_url( 'admin.php' )
			);
		}
	}
}
