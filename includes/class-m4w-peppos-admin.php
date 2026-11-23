<?php
/**
 * Peppos settings tab and WooCommerce order integration.
 *
 * @package M4W_SK_Company_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'M4W_Peppos_Admin' ) ) {

	class M4W_Peppos_Admin {

		const SETTINGS_SLUG = 'm4w-peppos';

		const TEST_TRANSIENT_PREFIX = 'm4w_peppos_test_result_';

		/**
		 * @var M4W_Peppos_Invoice
		 */
		private $invoice;

		public function __construct( M4W_Peppos_Invoice $invoice ) {
			$this->invoice = $invoice;

			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_post_m4w_peppos_test_connection', [ $this, 'handle_test_connection' ] );
			add_action( 'init', [ $this, 'schedule_status_poll' ] );
			add_filter( 'option_page_capability_m4w_peppos', [ $this, 'get_settings_capability' ] );
			add_filter( 'option_page_capability_m4w_sk_company_fields', [ $this, 'get_settings_capability' ] );

			add_filter( 'woocommerce_order_actions', [ $this, 'add_order_action' ] );
			add_action( 'woocommerce_order_action_m4w_peppos_send', [ $this, 'handle_order_action' ] );
			add_action( 'woocommerce_order_action_m4w_peppos_refresh_status', [ $this, 'handle_refresh_status_action' ] );
			add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_order_status' ] );
		}

		/**
		 * Register settings.
		 *
		 * @return void
		 */
		public function register_settings() {
			register_setting(
				'm4w_peppos',
				M4W_Peppos_Settings::OPTION,
				[
					'sanitize_callback' => [ $this, 'sanitize_settings' ],
					'default'           => M4W_Peppos_Settings::defaults(),
				]
			);

			add_settings_section(
				'm4w_peppos_api',
				__( 'Peppos API', 'm4w-sk-company-fields' ),
				[ $this, 'render_api_section' ],
				self::SETTINGS_SLUG
			);

			add_settings_field(
				'environment',
				__( 'Prostredie', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_api',
				[
					'key'   => 'environment',
					'type'  => 'select',
					'options' => [
						M4W_Peppos_Settings::ENV_SANDBOX    => __( 'Testovacie (sandbox)', 'm4w-sk-company-fields' ),
						M4W_Peppos_Settings::ENV_PRODUCTION => __( 'Produkcia', 'm4w-sk-company-fields' ),
					],
				]
			);

			add_settings_field(
				'client_id',
				__( 'ID klienta', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_api',
				[
					'key'  => 'client_id',
					'type' => 'text',
				]
			);

			add_settings_field(
				'client_secret',
				__( 'Tajný kľúč klienta', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_api',
				[
					'key'  => 'client_secret',
					'type' => 'password',
				]
			);

			add_settings_field(
				'sender_peppol_id',
				__( 'Peppol ID odosielateľa', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_api',
				[
					'key'         => 'sender_peppol_id',
					'type'        => 'text',
					'placeholder' => '0245:1234567890',
					'description' => __( 'Peppol ID tohto obchodu v tvare schéma:identifikátor. Slovenské subjekty používajú 0245:DIČ, napríklad 0245:2122749607.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'webhook_secret',
				__( 'Tajný kľúč webhooku', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_api',
				[
					'key'         => 'webhook_secret',
					'type'        => 'password',
					'placeholder' => __( 'Nastavený kľúč ponechajte prázdny', 'm4w-sk-company-fields' ),
					'description' => __( 'V Peppose nastavte webhook na zobrazenú URL, skopírujte jeho podpisový kľúč sem a prihláste udalosti document.sent, document.delivered, document.failed a mlr. Udalosti mlr menia stav pôvodnej faktúry na potvrdená alebo zamietnutá.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_section(
				'm4w_peppos_behavior',
				__( 'Správanie odosielania', 'm4w-sk-company-fields' ),
				'__return_false',
				self::SETTINGS_SLUG
			);

			add_settings_field(
				'auto_send',
				__( 'Automatické odosielanie', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_behavior',
				[
					'key'         => 'auto_send',
					'type'        => 'checkbox',
					'description' => __( 'Odoslať e-faktúru automaticky pri vytvorení PDF faktúry.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'wait_for_result',
				__( 'Čakať na výsledok prenosu', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_behavior',
				[
					'key'         => 'wait_for_result',
					'type'        => 'checkbox',
					'description' => __( 'Čakať až 120 sekúnd na výsledok prenosu cez Peppol. Inak sa dokument zaradí do fronty a stav sa overí neskôr.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'send_or_report',
				__( 'Odoslať alebo nahlásiť', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_behavior',
				[
					'key'         => 'send_or_report',
					'type'        => 'checkbox',
					'description' => __( 'Doručiť cez Peppol len ak je zákazník registrovaný; inak použiť príslušný zákonný reportovací kanál.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'poll_max_age_days',
				__( 'Maximálny vek záložného pollingu', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_behavior',
				[
					'key'         => 'poll_max_age_days',
					'type'        => 'number',
					'placeholder' => '30',
					'description' => __( 'Po tomto počte dní sa automatické overovanie stavu zastaví. Udalosti webhooku sa naďalej spracujú; stav môžete obnoviť ručne z objednávky.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'lookup_by_vat',
				__( 'Zistiť Peppol ID podľa IČ DPH', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_behavior',
				[
					'key'         => 'lookup_by_vat',
					'type'        => 'checkbox',
					'description' => __( 'Skúsiť nájsť Peppol ID zákazníka podľa IČ DPH pred vyhľadaním podľa IČO.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'add_order_notes',
				__( 'Pridať poznámky k objednávke', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_behavior',
				[
					'key'         => 'add_order_notes',
					'type'        => 'checkbox',
					'description' => __( 'Pridať poznámku k objednávke pri zmene stavu e-faktúry.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_section(
				'm4w_peppos_notifications',
				__( 'E-mailové upozornenia', 'm4w-sk-company-fields' ),
				[ $this, 'render_notifications_section' ],
				self::SETTINGS_SLUG
			);

			add_settings_field(
				'notify_status_changes',
				__( 'Zmena stavu e-faktúry', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_notifications',
				[
					'key'         => 'notify_status_changes',
					'type'        => 'checkbox',
					'description' => __( 'Poslať e-mail pri zmene stavu odoslanej e-faktúry.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'notify_new_invoices',
				__( 'Nová prijatá faktúra', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_notifications',
				[
					'key'         => 'notify_new_invoices',
					'type'        => 'checkbox',
					'description' => __( 'Poslať e-mail pri prijatí novej faktúry alebo dobropisu cez Peppol.', 'm4w-sk-company-fields' ),
				]
			);

			add_settings_field(
				'notification_recipients',
				__( 'Príjemcovia upozornení', 'm4w-sk-company-fields' ),
				[ $this, 'render_field' ],
				self::SETTINGS_SLUG,
				'm4w_peppos_notifications',
				[
					'key'         => 'notification_recipients',
					'type'        => 'text',
					'placeholder' => 'fakturace@example.com, admin@example.com',
					'description' => __( 'E-mailové adresy oddelené čiarkou. Ak necháte prázdne, použije sa admin e-mail.', 'm4w-sk-company-fields' ),
				]
			);
		}

		/**
		 * Use the same capability for saving options as the settings page uses.
		 *
		 * @return string
		 */
		public function get_settings_capability() {
			return 'manage_woocommerce';
		}

		/**
		 * Render the notifications section description.
		 *
		 * @return void
		 */
		public function render_notifications_section() {
			echo '<p>';
			esc_html_e( 'Upozornenia sa odosielajú pri zmene stavu odoslanej e-faktúry a pri pravidelnom hodinovom načítaní nových prijatých dokladov z Pepposu. Pri prvom načítaní sa existujúce doklady iba označia ako spracované, aby sa neodosielali staré upozornenia.', 'm4w-sk-company-fields' );
			echo '</p>';
		}

		/**
		 * Sanitize settings.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			$input    = is_array( $input ) ? $input : [];
			$defaults = M4W_Peppos_Settings::defaults();
			$clean    = [];

			$checkboxes = [
				'auto_send',
				'wait_for_result',
				'send_or_report',
				'lookup_by_vat',
				'add_order_notes',
				'notify_status_changes',
				'notify_new_invoices',
			];

			foreach ( $checkboxes as $key ) {
				$clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
			}

			$environment = isset( $input['environment'] ) ? sanitize_text_field( $input['environment'] ) : $defaults['environment'];
			$clean['environment'] = in_array( $environment, [ M4W_Peppos_Settings::ENV_SANDBOX, M4W_Peppos_Settings::ENV_PRODUCTION ], true )
				? $environment
				: $defaults['environment'];

		$text_fields = [ 'client_id', 'client_secret', 'sender_peppol_id', 'notification_recipients' ];
			foreach ( $text_fields as $key ) {
				$clean[ $key ] = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			}

			$clean['webhook_secret'] = isset( $input['webhook_secret'] ) && '' !== trim( (string) $input['webhook_secret'] )
				? trim( sanitize_text_field( $input['webhook_secret'] ) )
				: (string) M4W_Peppos_Settings::get( 'webhook_secret' );
			$poll_max_age_days = isset( $input['poll_max_age_days'] ) && '' !== trim( (string) $input['poll_max_age_days'] )
				? absint( $input['poll_max_age_days'] )
				: absint( M4W_Peppos_Settings::get( 'poll_max_age_days' ) );
			$clean['poll_max_age_days'] = min( 365, max( 1, $poll_max_age_days ) );

			if ( '' !== $clean['notification_recipients'] ) {
				$raw_emails   = preg_split( '/[\s,;]+/', $clean['notification_recipients'] );
				$raw_emails   = is_array( $raw_emails ) ? $raw_emails : [];
				$valid_emails = [];

				foreach ( $raw_emails as $raw_email ) {
					$email = sanitize_email( $raw_email );
					if ( '' === $email ) {
						continue;
					}

					if ( ! is_email( $email ) ) {
						add_settings_error(
							M4W_Peppos_Settings::OPTION,
							'm4w_peppos_notification_recipients',
							sprintf(
								/* translators: %s: invalid e-mail address */
								__( 'Neplatná e-mailová adresa pre upozornenia: %s', 'm4w-sk-company-fields' ),
								$raw_email
							),
							'error'
						);
						continue;
					}

					$valid_emails[] = $email;
				}

				$clean['notification_recipients'] = implode( ', ', array_unique( $valid_emails ) );
			}

			if ( '' !== $clean['sender_peppol_id'] ) {
				$parsed = M4W_Peppos_Settings::parse_peppol_id( $clean['sender_peppol_id'] );
				if ( is_wp_error( $parsed ) ) {
					add_settings_error(
						M4W_Peppos_Settings::OPTION,
						'm4w_peppos_sender_peppol_id',
						$parsed->get_error_message(),
						'error'
					);
				}
			}

			if ( empty( $clean['client_id'] ) || empty( $clean['client_secret'] ) ) {
				add_settings_error(
					M4W_Peppos_Settings::OPTION,
					'm4w_peppos_credentials',
					__( 'Peppos ID klienta a tajný kľúč klienta sú povinné pre e-fakturáciu.', 'm4w-sk-company-fields' ),
					'warning'
				);
			}

			return $clean;
		}

		/**
		 * API section description.
		 *
		 * @return void
		 */
		public function render_api_section() {
			$webhook_url = rest_url( 'm4w/v1/peppos-webhook' );
			echo '<p>' . esc_html__( 'Vytvorte API kľúč v Peppose a zadajte jeho prihlasovacie údaje nižšie. Kľúč potrebuje oprávnenia send_documents a read_all_documents (alebo admin).', 'm4w-sk-company-fields' ) . '</p>';
			echo '<p>' . esc_html__( 'Webhook callback URL:', 'm4w-sk-company-fields' ) . ' <code>' . esc_html( $webhook_url ) . '</code></p>';
		}

		/**
		 * Render a settings field.
		 *
		 * @param array $args Field arguments.
		 * @return void
		 */
		public function render_field( $args ) {
			$settings = M4W_Peppos_Settings::get();
			$key      = $args['key'];
			$type     = isset( $args['type'] ) ? $args['type'] : 'text';
			$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
			$name     = M4W_Peppos_Settings::OPTION . '[' . $key . ']';
			if ( 'webhook_secret' === $key ) {
				$value = '';
			}

			if ( 'checkbox' === $type ) {
				printf(
					'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
					esc_attr( $name ),
					checked( 1, $value, false ),
					esc_html__( 'Zapnuté', 'm4w-sk-company-fields' )
				);
			} elseif ( 'select' === $type ) {
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( $args['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( $option_value, $value, false ),
						esc_html( $option_label )
					);
				}
				echo '</select>';
			} else {
				printf(
					'<input type="%1$s" name="%2$s" value="%3$s" class="regular-text" %4$s>',
					esc_attr( $type ),
					esc_attr( $name ),
					esc_attr( $value ),
					isset( $args['placeholder'] ) ? 'placeholder="' . esc_attr( $args['placeholder'] ) . '"' : ''
				);
			}

			if ( ! empty( $args['description'] ) ) {
				echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
			}
		}

		/**
		 * Handle the API connection test.
		 *
		 * @return void
		 */
		public function handle_test_connection() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Na otestovanie pripojenia k Peppos nemáte oprávnenie.', 'm4w-sk-company-fields' ) );
			}

			check_admin_referer( 'm4w_peppos_test_connection' );

			$result = $this->invoice->get_client()->test_connection();

			set_transient(
				self::TEST_TRANSIENT_PREFIX . get_current_user_id(),
				[
					'success' => ! is_wp_error( $result ),
					'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Prihlasovacie údaje Peppos API sú platné. Prístupový token bol úspešne získaný.', 'm4w-sk-company-fields' ),
				],
				60
			);

			wp_safe_redirect(
				add_query_arg(
					[
						'page' => M4W_SK_Company_Fields::PAGE_SLUG,
						'tab'  => M4W_SK_Company_Fields::TAB_PEPPOL,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Render the Peppol tab content.
		 *
		 * @return void
		 */
		public function render_tab_content() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$test_result = get_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
			if ( is_array( $test_result ) ) {
				delete_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
			}

			settings_errors();
			?>
			<?php if ( isset( $test_result['message'] ) ) : ?>
				<div class="notice notice-<?php echo ! empty( $test_result['success'] ) ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $test_result['message'] ); ?></p>
				</div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: %s: Peppos API documentation link */
					esc_html__( 'Odosielajte B2B faktúry do siete Peppol cez Peppos. Dokumentácia API: %s', 'm4w-sk-company-fields' ),
					'<a href="' . esc_url( 'https://app.peppos.cz/openapi/' ) . '" target="_blank" rel="noopener noreferrer">app.peppos.cz/openapi</a>'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'm4w_peppos' );
				do_settings_sections( self::SETTINGS_SLUG );
				submit_button( __( 'Uložiť zmeny', 'm4w-sk-company-fields' ) );
				?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Test pripojenia', 'm4w-sk-company-fields' ); ?></h2>
			<p><?php esc_html_e( 'Najprv uložte nastavenia a potom overte, že sa s prihlasovacími údajmi podarí získať prístupový token z Pepposu.', 'm4w-sk-company-fields' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="m4w_peppos_test_connection">
				<?php
				wp_nonce_field( 'm4w_peppos_test_connection' );
				submit_button( __( 'Otestovať pripojenie k Peppos API', 'm4w-sk-company-fields' ), 'secondary', 'submit', false );
				?>
			</form>
			<?php
		}

		/**
		 * Whether the Peppol invoices tab has content to display.
		 *
		 * @return bool
		 */
		public function has_documents_to_show() {
			return M4W_Peppos_Settings::is_configured()
				&& ! is_wp_error( M4W_Peppos_Settings::get_sender_endpoint() );
		}

		/**
		 * Render the Peppol invoices tab.
		 *
		 * @return void
		 */
		public function render_documents_tab() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if ( ! $this->has_documents_to_show() ) {
				$settings_url = add_query_arg(
					[
						'page' => M4W_SK_Company_Fields::PAGE_SLUG,
						'tab'  => M4W_SK_Company_Fields::TAB_PEPPOL,
					],
					admin_url( 'admin.php' )
				);

				echo '<div class="notice notice-warning"><p>';
				printf(
					/* translators: %s: link to the Peppol settings tab */
					esc_html__( 'Pre zobrazenie faktúr najprv nastavte Peppos API a Peppol ID odosielateľa v záložke %s.', 'm4w-sk-company-fields' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Peppol', 'm4w-sk-company-fields' ) . '</a>'
				);
				echo '</p></div>';
				return;
			}

			$direction = isset( $_GET['peppos_direction'] ) ? sanitize_key( wp_unslash( $_GET['peppos_direction'] ) ) : '';
			if ( ! in_array( $direction, [ '', 'outbound', 'inbound' ], true ) ) {
				$direction = '';
			}

			$status         = isset( $_GET['peppos_status'] ) ? sanitize_key( wp_unslash( $_GET['peppos_status'] ) ) : '';
			$allowed_status = [ 'draft', 'accepted', 'processing', 'pending', 'sent', 'delivered', 'confirmed', 'received', 'rejected', 'failed' ];
			if ( ! in_array( $status, $allowed_status, true ) ) {
				$status = '';
			}

			$search = isset( $_GET['peppos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['peppos_search'] ) ) : '';
			$offset = isset( $_GET['peppos_offset'] ) ? max( 0, absint( wp_unslash( $_GET['peppos_offset'] ) ) ) : 0;
			$limit  = 25;

			$request_args = [
				'direction'     => $direction,
				'documentTypes' => 'invoice,creditnote',
				'limit'         => $limit,
				'offset'        => $offset,
				'sortBy'        => 'createdAt',
				'sortDirection' => 'desc',
			];

			if ( '' !== $status ) {
				$request_args['status'] = $status;
			}

			if ( '' !== $search ) {
				$request_args['search'] = $search;
			}

			$persistent_args = [];
			if ( '' !== $status ) {
				$persistent_args['peppos_status'] = $status;
			}
			if ( '' !== $search ) {
				$persistent_args['peppos_search'] = $search;
			}

			$status_labels = [
				'draft'    => __( 'Návrh', 'm4w-sk-company-fields' ),
				'accepted' => __( 'Prijatá Pepposom', 'm4w-sk-company-fields' ),
				'processing' => __( 'Spracúva sa', 'm4w-sk-company-fields' ),
				'pending'  => __( 'Čaká na odoslanie', 'm4w-sk-company-fields' ),
				'sent'     => __( 'Odoslaná', 'm4w-sk-company-fields' ),
				'delivered' => __( 'Doručená do prístupového bodu', 'm4w-sk-company-fields' ),
				'confirmed' => __( 'Potvrdená', 'm4w-sk-company-fields' ),
				'received' => __( 'Prijatá', 'm4w-sk-company-fields' ),
				'rejected' => __( 'Zamietnutá', 'm4w-sk-company-fields' ),
				'failed'   => __( 'Zlyhala', 'm4w-sk-company-fields' ),
			];

			$type_labels = [
				'invoice'    => __( 'Faktúra', 'm4w-sk-company-fields' ),
				'creditnote' => __( 'Dobropis', 'm4w-sk-company-fields' ),
			];

			$validation_labels = [
				'valid'           => __( 'Platná', 'm4w-sk-company-fields' ),
				'invalid'         => __( 'Neplatná', 'm4w-sk-company-fields' ),
				'need-validation' => __( 'Na overenie', 'm4w-sk-company-fields' ),
			];

			$direction_labels = [
				'outbound' => __( 'Odoslaná', 'm4w-sk-company-fields' ),
				'inbound'  => __( 'Prijatá', 'm4w-sk-company-fields' ),
			];

			$status_colors = [
				'draft'     => '#646970',
				'accepted'  => '#2271b1',
				'processing' => '#b26200',
				'pending'   => '#b26200',
				'sent'      => '#2271b1',
				'delivered' => '#007017',
				'confirmed' => '#007017',
				'received'  => '#007017',
				'rejected'  => '#b32d2e',
				'failed'    => '#b32d2e',
			];

			$documents = $this->invoice->get_client()->get_documents( $request_args );

			$all_url       = $this->documents_url( $persistent_args );
			$outbound_url  = $this->documents_url( array_merge( $persistent_args, [ 'peppos_direction' => 'outbound' ] ) );
			$inbound_url   = $this->documents_url( array_merge( $persistent_args, [ 'peppos_direction' => 'inbound' ] ) );
			$form_action   = admin_url( 'admin.php' );
			$clear_url     = $this->documents_url( [ 'peppos_direction' => $direction ] );
			?>
			<h2><?php esc_html_e( 'Peppol faktúry', 'm4w-sk-company-fields' ); ?></h2>
			<p><?php esc_html_e( 'Prehľad odoslaných a prijatých faktúr a dobropisov načítaných z Pepposu.', 'm4w-sk-company-fields' ); ?></p>

			<ul class="subsubsub" style="margin:0 0 12px;">
				<li>
					<a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo '' === $direction ? 'current' : ''; ?>">
						<?php esc_html_e( 'Všetky', 'm4w-sk-company-fields' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $outbound_url ); ?>" class="<?php echo 'outbound' === $direction ? 'current' : ''; ?>">
						<?php esc_html_e( 'Odoslané', 'm4w-sk-company-fields' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $inbound_url ); ?>" class="<?php echo 'inbound' === $direction ? 'current' : ''; ?>">
						<?php esc_html_e( 'Prijaté', 'm4w-sk-company-fields' ); ?>
					</a>
				</li>
			</ul>
			<div class="clear"></div>

			<form method="get" action="<?php echo esc_url( $form_action ); ?>" style="margin:0 0 12px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
				<input type="hidden" name="page" value="<?php echo esc_attr( M4W_SK_Company_Fields::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="<?php echo esc_attr( M4W_SK_Company_Fields::TAB_INVOICES ); ?>">
				<?php if ( '' !== $direction ) : ?>
					<input type="hidden" name="peppos_direction" value="<?php echo esc_attr( $direction ); ?>">
				<?php endif; ?>
				<label>
					<span style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Stav', 'm4w-sk-company-fields' ); ?></span>
					<select name="peppos_status">
						<option value=""><?php esc_html_e( 'Všetky stavy', 'm4w-sk-company-fields' ); ?></option>
						<?php foreach ( $status_labels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Hľadať', 'm4w-sk-company-fields' ); ?></span>
					<input type="search" name="peppos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Číslo faktúry, partner, Peppol ID', 'm4w-sk-company-fields' ); ?>">
				</label>
				<?php submit_button( __( 'Filtrovať', 'm4w-sk-company-fields' ), 'secondary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Zrušiť filtre', 'm4w-sk-company-fields' ); ?></a>
			</form>

			<?php if ( is_wp_error( $documents ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $documents->get_error_message() ); ?></p></div>
			<?php else : ?>
				<?php
				$show_amount = false;
				foreach ( $documents as $document ) {
					if ( isset( $document['amount'] ) && '' !== $document['amount'] && null !== $document['amount'] ) {
						$show_amount = true;
						break;
					}
				}
				$colspan = $show_amount ? 8 : 7;
				?>
				<div style="overflow-x:auto;">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Dátum', 'm4w-sk-company-fields' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Smer', 'm4w-sk-company-fields' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Číslo dokladu', 'm4w-sk-company-fields' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Typ', 'm4w-sk-company-fields' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Partner', 'm4w-sk-company-fields' ); ?></th>
								<?php if ( $show_amount ) : ?>
									<th scope="col"><?php esc_html_e( 'Suma', 'm4w-sk-company-fields' ); ?></th>
								<?php endif; ?>
								<th scope="col"><?php esc_html_e( 'Stav', 'm4w-sk-company-fields' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Validácia', 'm4w-sk-company-fields' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $documents ) ) : ?>
								<tr>
									<td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e( 'Žiadne faktúry sa nenašli.', 'm4w-sk-company-fields' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $documents as $document ) : ?>
									<?php
									$doc_direction = isset( $document['direction'] ) ? (string) $document['direction'] : '';
									$doc_status    = isset( $document['status'] ) ? (string) $document['status'] : '';
									$doc_type      = isset( $document['documentType'] ) ? (string) $document['documentType'] : '';
									$validation    = isset( $document['validationStatus'] ) ? (string) $document['validationStatus'] : '';

									$partner_id = 'inbound' === $doc_direction
										? ( isset( $document['senderPeppolId'] ) ? (string) $document['senderPeppolId'] : '' )
										: ( isset( $document['receiverPeppolId'] ) ? (string) $document['receiverPeppolId'] : '' );

									if ( '' === $partner_id ) {
										$partner_id = isset( $document['receiverPeppolId'] ) ? (string) $document['receiverPeppolId'] : '';
									}
									if ( '' === $partner_id && isset( $document['senderPeppolId'] ) ) {
										$partner_id = (string) $document['senderPeppolId'];
									}

									$partner_name = isset( $document['company'] ) ? (string) $document['company'] : '';
									$created_at   = isset( $document['createdAt'] ) ? strtotime( (string) $document['createdAt'] ) : false;
									$number       = isset( $document['documentNumber'] ) && '' !== (string) $document['documentNumber']
										? (string) $document['documentNumber']
										: ( isset( $document['extId'] ) ? (string) $document['extId'] : '' );

									$direction_label  = isset( $direction_labels[ $doc_direction ] ) ? $direction_labels[ $doc_direction ] : $doc_direction;
									$type_label       = isset( $type_labels[ $doc_type ] ) ? $type_labels[ $doc_type ] : $doc_type;
									$validation_label = isset( $validation_labels[ $validation ] ) ? $validation_labels[ $validation ] : $validation;
									?>
									<tr>
										<td><?php echo $created_at ? esc_html( wp_date( 'd.m.Y H:i', $created_at ) ) : '&mdash;'; ?></td>
										<td><?php echo '' !== $direction_label ? esc_html( $direction_label ) : '&mdash;'; ?></td>
										<td><?php echo '' !== $number ? esc_html( $number ) : '&mdash;'; ?></td>
										<td><?php echo '' !== $type_label ? esc_html( $type_label ) : '&mdash;'; ?></td>
										<td>
											<?php if ( '' !== $partner_name ) : ?>
												<strong><?php echo esc_html( $partner_name ); ?></strong><br>
											<?php endif; ?>
											<?php if ( '' !== $partner_id ) : ?>
												<code><?php echo esc_html( $partner_id ); ?></code>
											<?php else : ?>
												&mdash;
											<?php endif; ?>
										</td>
										<?php if ( $show_amount ) : ?>
											<td>
												<?php
												$currency = isset( $document['currency'] ) ? (string) $document['currency'] : '';
												if ( isset( $document['amount'] ) && '' !== $document['amount'] && null !== $document['amount'] ) {
													echo esc_html( number_format_i18n( (float) $document['amount'], 2 ) );
													if ( '' !== $currency ) {
														echo ' ' . esc_html( $currency );
													}
												} else {
													echo '&mdash;';
												}
												?>
											</td>
										<?php endif; ?>
										<td>
											<?php
											$status_label = isset( $status_labels[ $doc_status ] ) ? $status_labels[ $doc_status ] : $doc_status;
											$status_color = isset( $status_colors[ $doc_status ] ) ? $status_colors[ $doc_status ] : '#646970';
											?>
											<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:<?php echo esc_attr( $status_color ); ?>;color:#fff;font-size:12px;">
												<?php echo '' !== $status_label ? esc_html( $status_label ) : '&mdash;'; ?>
											</span>
										</td>
										<td><?php echo '' !== $validation_label ? esc_html( $validation_label ) : '&mdash;'; ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php
				$count        = count( $documents );
				$has_prev     = $offset > 0;
				$has_next     = $count >= $limit;
				$page_number  = (int) floor( $offset / $limit ) + 1;
				$prev_url     = $this->documents_url( array_merge( $persistent_args, [ 'peppos_direction' => $direction, 'peppos_offset' => max( 0, $offset - $limit ) ] ) );
				$next_url     = $this->documents_url( array_merge( $persistent_args, [ 'peppos_direction' => $direction, 'peppos_offset' => $offset + $limit ] ) );
				?>
				<div style="display:flex;gap:8px;align-items:center;margin-top:12px;">
					<span><?php printf( esc_html__( 'Strana %d', 'm4w-sk-company-fields' ), (int) $page_number ); ?></span>
					<?php if ( $has_prev ) : ?>
						<a class="button" href="<?php echo esc_url( $prev_url ); ?>"><?php esc_html_e( 'Predchádzajúca', 'm4w-sk-company-fields' ); ?></a>
					<?php endif; ?>
					<?php if ( $has_next ) : ?>
						<a class="button" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Ďalšia', 'm4w-sk-company-fields' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php
		}

		/**
		 * Build a URL for the Peppol invoices tab.
		 *
		 * @param array $args Query arguments.
		 * @return string
		 */
		private function documents_url( array $args = [] ) {
			$args = array_merge(
				[
					'page' => M4W_SK_Company_Fields::PAGE_SLUG,
					'tab'  => M4W_SK_Company_Fields::TAB_INVOICES,
				],
				$args
			);

			$args = array_filter(
				$args,
				static function ( $value ) {
					return '' !== $value && null !== $value;
				}
			);

			return add_query_arg( $args, admin_url( 'admin.php' ) );
		}

		/**
		 * Add an order action for manual sending.
		 *
		 * @param array $actions Order actions.
		 * @return array
		 */
		public function add_order_action( $actions ) {
			if ( ! M4W_Peppos_Settings::is_enabled() ) {
				return $actions;
			}

			$actions['m4w_peppos_send'] = __( 'Odoslať e-faktúru cez Peppos', 'm4w-sk-company-fields' );
			$actions['m4w_peppos_refresh_status'] = __( 'Obnoviť stav e-faktúry cez Peppos', 'm4w-sk-company-fields' );

			return $actions;
		}

		/**
		 * Handle the manual send order action.
		 *
		 * @param WC_Order $order Order.
		 * @return void
		 */
		public function handle_order_action( $order ) {
			if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$this->invoice->send_order( $order, 'manual' );
		}

		/**
		 * Handle a manual status refresh for an order.
		 *
		 * @param WC_Order $order Order.
		 * @return void
		 */
		public function handle_refresh_status_action( $order ) {
			if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$result = $this->invoice->refresh_document_status( $order );
			if ( is_wp_error( $result ) ) {
				$order->add_order_note( sprintf( __( 'Peppos status refresh failed: %s', 'm4w-sk-company-fields' ), $result->get_error_message() ), false, true );
			}
		}

		/**
		 * Render Peppos status in the order details.
		 *
		 * @param WC_Order $order Order.
		 * @return void
		 */
		public function render_order_status( $order ) {
			if ( ! $order instanceof WC_Order || ! M4W_Peppos_Settings::is_enabled() ) {
				return;
			}

			$status = (string) $order->get_meta( M4W_Peppos_Invoice::META_STATUS );

			if ( '' === $status ) {
				return;
			}

			$receiver   = (string) $order->get_meta( M4W_Peppos_Invoice::META_RECEIVER_PEPPOL_ID );
			$document_id = (string) $order->get_meta( M4W_Peppos_Invoice::META_DOCUMENT_ID );
			$message    = (string) $order->get_meta( M4W_Peppos_Invoice::META_MESSAGE );
			$error      = (string) $order->get_meta( M4W_Peppos_Invoice::META_LAST_ERROR );
			$attempts   = (int) $order->get_meta( M4W_Peppos_Invoice::META_ATTEMPTS );
			$stopped_at = (int) $order->get_meta( M4W_Peppos_Invoice::META_POLL_STOPPED_AT );

			echo '<div class="m4w-peppos-order-status" style="margin-top:12px;padding:10px 12px;background:#f8fafc;border:1px solid #dcdcde;border-radius:4px;">';
			echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Peppos e-faktúra', 'm4w-sk-company-fields' ) . '</strong></p>';
			echo '<p style="margin:0 0 4px;">' . esc_html__( 'Stav:', 'm4w-sk-company-fields' ) . ' <code>' . esc_html( $status ) . '</code></p>';

			if ( '' !== $receiver ) {
				echo '<p style="margin:0 0 4px;">' . esc_html__( 'Príjemca:', 'm4w-sk-company-fields' ) . ' ' . esc_html( $receiver ) . '</p>';
			}

			if ( '' !== $document_id ) {
				echo '<p style="margin:0 0 4px;">' . esc_html__( 'ID dokumentu:', 'm4w-sk-company-fields' ) . ' <code>' . esc_html( $document_id ) . '</code></p>';
			}

			if ( '' !== $message ) {
				echo '<p style="margin:0 0 4px;">' . esc_html( $message ) . '</p>';
			}

			if ( '' !== $error ) {
				echo '<p style="margin:0 0 4px;color:#b32d2e;">' . esc_html( $error ) . '</p>';
			}

			if ( $stopped_at ) {
				echo '<p style="margin:0 0 4px;color:#996800;">' . esc_html__( 'Automatické overovanie sa po uplynutí nastavenej lehoty zastavilo. Obnovte stav ručne alebo počkajte na webhook.', 'm4w-sk-company-fields' ) . '</p>';
			}

			if ( $attempts > 0 ) {
				echo '<p style="margin:0;color:#646970;">' . sprintf(
					/* translators: %d: number of attempts */
					esc_html__( 'Pokusy: %d', 'm4w-sk-company-fields' ),
					(int) $attempts
				) . '</p>';
			}

			echo '</div>';
		}

		/**
		 * Schedule the status polling event.
		 *
		 * @return void
		 */
		public function schedule_status_poll() {
			$hook = 'm4w_peppos_poll_status';

			if ( ! M4W_Peppos_Settings::is_enabled() ) {
				$timestamp = wp_next_scheduled( $hook );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, $hook );
				}
				return;
			}

			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 300, 'hourly', $hook );
			}
		}
	}
}
