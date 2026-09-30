<?php

defined( 'ABSPATH' ) || exit;

/**
 * Passerelle de paiement AsukaPay : Mobile Money collecté directement dans
 * le tunnel WooCommerce (pas de page hébergée), carte bancaire redirigée
 * vers la page hébergée renvoyée par l'API (payment_url), comme documenté
 * dans docs.asukapay.com/operateurs.html.
 */
class WC_Gateway_Asukapay extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'asukapay';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'AsukaPay', 'asukapay-for-woocommerce' );
		$this->method_description = __( 'Mobile Money et carte bancaire pour l’Afrique de l’Ouest et du Centre, via AsukaPay.', 'asukapay-for-woocommerce' );
		$this->supports            = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Activer/désactiver', 'asukapay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activer AsukaPay', 'asukapay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Titre', 'asukapay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Affiché au client pendant le paiement.', 'asukapay-for-woocommerce' ),
				'default'     => __( 'Mobile Money & carte bancaire', 'asukapay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'asukapay-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'MTN, Moov, Orange, Wave, Coris… et carte bancaire, via AsukaPay.', 'asukapay-for-woocommerce' ),
			),
			'testmode'         => array(
				'title'       => __( 'Mode test', 'asukapay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Utiliser la clé de test (bac à sable AsukaPay, aucun argent réel)', 'asukapay-for-woocommerce' ),
				'default'     => 'yes',
			),
			'secret_key_live'  => array(
				'title'       => __( 'Clé secrète — production', 'asukapay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Commence par sk_…. Tableau de bord AsukaPay → Développeurs → Clés API.', 'asukapay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'secret_key_test'  => array(
				'title'       => __( 'Clé secrète — test', 'asukapay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Commence par sk_test_….', 'asukapay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'webhook_info'     => array(
				'title'       => __( 'URL de notification (webhook)', 'asukapay-for-woocommerce' ),
				'type'        => 'title',
				/* translators: %s: webhook URL */
				'description' => sprintf(
					__( 'Enregistrée automatiquement auprès d’AsukaPay à chaque enregistrement de ces réglages : %s', 'asukapay-for-woocommerce' ),
					'<code>' . esc_html( Asukapay_Webhook_Handler::url() ) . '</code>'
				),
			),
			'debug'            => array(
				'title'   => __( 'Journalisation', 'asukapay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Consigner les échanges avec l’API AsukaPay (WooCommerce → Statut → Journaux)', 'asukapay-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		return '' !== $this->active_secret_key();
	}

	public function process_admin_options() {
		$saved = parent::process_admin_options();

		foreach ( array(
			'live' => $this->get_option( 'secret_key_live' ),
			'test' => $this->get_option( 'secret_key_test' ),
		) as $env => $secret_key ) {
			if ( '' === $secret_key ) {
				continue;
			}

			$client = new Asukapay_Api_Client( $secret_key );
			$result = $client->register_webhook_endpoint( Asukapay_Webhook_Handler::url() );

			if ( is_wp_error( $result ) ) {
				$this->log( 'Échec enregistrement webhook (' . $env . ') : ' . $result->get_error_message() );
			} elseif ( ! empty( $result['secret'] ) ) {
				$this->update_option( 'webhook_secret_' . $env, $result['secret'] );
			}

			Asukapay_Operators::flush_cache( $secret_key );
		}

		return $saved;
	}

	private function active_secret_key(): string {
		$env = $this->testmode() ? 'secret_key_test' : 'secret_key_live';
		return (string) $this->get_option( $env, '' );
	}

	private function testmode(): bool {
		return 'yes' === $this->get_option( 'testmode', 'yes' );
	}

	public function enqueue_checkout_assets() {
		if ( ! is_checkout() || ! $this->is_available() ) {
			return;
		}

		wp_enqueue_style( 'asukapay-checkout', ASUKAPAY_WC_URL . 'assets/css/checkout.css', array(), ASUKAPAY_WC_VERSION );
		wp_enqueue_script( 'asukapay-checkout', ASUKAPAY_WC_URL . 'assets/js/checkout.js', array( 'jquery' ), ASUKAPAY_WC_VERSION, true );
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}

		if ( $this->testmode() ) {
			echo '<p class="asukapay-testmode-notice">' . esc_html__( 'Mode test AsukaPay : aucun débit réel ne sera effectué.', 'asukapay-for-woocommerce' ) . '</p>';
		}

		$catalog = Asukapay_Operators::get_catalog( $this->active_secret_key() );

		if ( $catalog['error'] || empty( $catalog['modes'] ) ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Les moyens de paiement AsukaPay sont momentanément indisponibles, merci de réessayer.', 'asukapay-for-woocommerce' ) . '</p>';
			return;
		}

		$grouped = Asukapay_Operators::group_for_checkout( $catalog['modes'] );

		echo '<fieldset id="asukapay-fields">';

		echo '<p class="form-row form-row-wide"><label for="asukapay_operator">' . esc_html__( 'Opérateur', 'asukapay-for-woocommerce' ) . ' <span class="required">*</span></label>';
		echo '<select id="asukapay_operator" name="asukapay_operator" class="asukapay-operator-select" required>';
		echo '<option value="">' . esc_html__( 'Choisissez votre opérateur…', 'asukapay-for-woocommerce' ) . '</option>';

		foreach ( $grouped['groups'] as $country => $modes ) {
			echo '<optgroup label="' . esc_attr( $country ) . '">';
			foreach ( $modes as $mode ) {
				$operator = (string) ( $mode['operator'] ?? '' );
				if ( '' === $operator ) {
					continue;
				}
				$requires_otp = Asukapay_Operators::requires_otp( $mode );
				printf(
					'<option value="%1$s" data-otp="%2$s">%3$s</option>',
					esc_attr( $operator ),
					$requires_otp ? '1' : '0',
					esc_html( $mode['name'] ?? $operator )
				);
			}
			echo '</optgroup>';
		}

		if ( ! empty( $grouped['card_operators'] ) ) {
			echo '<optgroup label="' . esc_attr__( 'Carte bancaire', 'asukapay-for-woocommerce' ) . '">';
			foreach ( $grouped['card_operators'] as $operator ) {
				printf( '<option value="%1$s" data-card="1">%1$s</option>', esc_attr( $operator ) );
			}
			echo '</optgroup>';
		}

		echo '</select></p>';

		echo '<p class="form-row form-row-wide" id="asukapay-phone-row">';
		echo '<label for="asukapay_phone">' . esc_html__( 'Numéro Mobile Money', 'asukapay-for-woocommerce' ) . ' <span class="required">*</span></label>';
		echo '<input type="tel" id="asukapay_phone" name="asukapay_phone" placeholder="' . esc_attr__( 'Ex. 22997000000', 'asukapay-for-woocommerce' ) . '" />';
		echo '</p>';

		echo '<p class="form-row form-row-wide" id="asukapay-otp-row" style="display:none">';
		echo '<label for="asukapay_otp">' . esc_html__( 'Code OTP', 'asukapay-for-woocommerce' ) . ' <span class="required">*</span></label>';
		echo '<input type="text" id="asukapay_otp" name="asukapay_otp" inputmode="numeric" autocomplete="one-time-code" />';
		echo '<span class="asukapay-field-hint">' . esc_html__( 'Composez le code USSD indiqué par votre opérateur, puis saisissez le code reçu.', 'asukapay-for-woocommerce' ) . '</span>';
		echo '</p>';

		echo '</fieldset>';
	}

	public function validate_fields() {
		$operator = wc_clean( wp_unslash( $_POST['asukapay_operator'] ?? '' ) );

		if ( '' === $operator ) {
			wc_add_notice( __( 'Choisissez un opérateur de paiement.', 'asukapay-for-woocommerce' ), 'error' );
			return false;
		}

		$catalog          = Asukapay_Operators::get_catalog( $this->active_secret_key() );
		$grouped          = Asukapay_Operators::group_for_checkout( $catalog['modes'] );
		$is_card          = in_array( $operator, $grouped['card_operators'], true );

		if ( $is_card ) {
			return true;
		}

		$phone = preg_replace( '/[^0-9+]/', '', wp_unslash( $_POST['asukapay_phone'] ?? '' ) );

		if ( ! preg_match( '/^\+?[0-9]{8,15}$/', $phone ) ) {
			wc_add_notice( __( 'Saisissez un numéro Mobile Money valide (indicatif pays compris).', 'asukapay-for-woocommerce' ), 'error' );
			return false;
		}

		$mode = $this->find_mode( $catalog['modes'], $operator );

		if ( $mode && Asukapay_Operators::requires_otp( $mode ) ) {
			$otp = wc_clean( wp_unslash( $_POST['asukapay_otp'] ?? '' ) );
			if ( '' === $otp ) {
				wc_add_notice( __( 'Ce moyen de paiement demande le code reçu par USSD.', 'asukapay-for-woocommerce' ), 'error' );
				return false;
			}
		}

		return true;
	}

	private function find_mode( array $modes, string $operator ): ?array {
		foreach ( $modes as $mode ) {
			if ( strtolower( (string) ( $mode['operator'] ?? '' ) ) === strtolower( $operator ) ) {
				return $mode;
			}
		}
		return null;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$secret_key = $this->active_secret_key();
		$catalog    = Asukapay_Operators::get_catalog( $secret_key );
		$grouped    = Asukapay_Operators::group_for_checkout( $catalog['modes'] );

		$operator = wc_clean( wp_unslash( $_POST['asukapay_operator'] ?? '' ) );
		$is_card  = in_array( $operator, $grouped['card_operators'], true );
		$mode     = $this->find_mode( $catalog['modes'], $operator );

		$payload = array(
			'amount'      => (int) round( (float) $order->get_total() ),
			'currency'    => $order->get_currency(),
			'operator'    => $operator,
			'description' => sprintf( __( 'Commande #%s', 'asukapay-for-woocommerce' ), $order->get_order_number() ),
			'external_id' => (string) $order->get_id(),
			'return_url'  => $this->get_return_url( $order ),
			'callback_url' => Asukapay_Webhook_Handler::url(),
			'customer'    => array(
				'firstname' => $order->get_billing_first_name(),
				'lastname'  => $order->get_billing_last_name(),
				'email'     => $order->get_billing_email(),
				'address'   => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
				'country'   => $order->get_billing_country(),
			),
			'metadata'    => array(
				'woocommerce_order_id' => $order->get_id(),
				'order_key'            => $order->get_order_key(),
			),
		);

		if ( ! $is_card ) {
			$payload['phone_number'] = preg_replace( '/[^0-9+]/', '', wp_unslash( $_POST['asukapay_phone'] ?? '' ) );

			if ( $mode && Asukapay_Operators::requires_otp( $mode ) ) {
				$payload['otp'] = wc_clean( wp_unslash( $_POST['asukapay_otp'] ?? '' ) );
			}
		}

		$client = new Asukapay_Api_Client( $secret_key );
		$result = $client->create_payment( $payload );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Échec création paiement commande #' . $order->get_id() . ' : ' . $result->get_error_message() );
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_asukapay_reference', $result['reference'] ?? '' );
		$order->update_meta_data( '_asukapay_environment', $this->testmode() ? 'test' : 'live' );
		$order->save();

		$response_type = $result['payment_mode']['type'] ?? ( $is_card ? 'card' : 'mobile_money' );

		if ( 'card' === $response_type && ! empty( $result['payment_url'] ) ) {
			$order->update_status( 'on-hold', __( 'AsukaPay : en attente du paiement par carte.', 'asukapay-for-woocommerce' ) );

			return array(
				'result'   => 'success',
				'redirect' => $result['payment_url'],
			);
		}

		$order->update_status( 'on-hold', sprintf(
			/* translators: %s: payment reference */
			__( 'AsukaPay : paiement Mobile Money initié (réf. %s), en attente de confirmation.', 'asukapay-for-woocommerce' ),
			$result['reference'] ?? '—'
		) );

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'on-hold' !== $order->get_status() ) {
			return;
		}

		echo '<p class="asukapay-thankyou-notice">' . esc_html__( 'Validez le paiement sur votre téléphone pour finaliser la commande. Vous recevrez une confirmation dès que le paiement sera reçu.', 'asukapay-for-woocommerce' ) . '</p>';
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'asukapay_refund', __( 'Commande introuvable.', 'asukapay-for-woocommerce' ) );
		}

		$reference = $order->get_meta( '_asukapay_reference', true );

		if ( '' === $reference ) {
			return new WP_Error( 'asukapay_refund', __( 'Aucune référence de paiement AsukaPay sur cette commande.', 'asukapay-for-woocommerce' ) );
		}

		$environment = $order->get_meta( '_asukapay_environment', true ) ?: ( $this->testmode() ? 'test' : 'live' );
		$secret_key  = (string) $this->get_option( 'secret_key_' . $environment, '' );

		if ( '' === $secret_key ) {
			return new WP_Error( 'asukapay_refund', __( 'Clé API AsukaPay manquante pour l’environnement de ce paiement.', 'asukapay-for-woocommerce' ) );
		}

		$client = new Asukapay_Api_Client( $secret_key );
		$result = $client->refund_payment(
			$reference,
			null !== $amount ? (int) round( (float) $amount ) : null,
			$reason
		);

		if ( is_wp_error( $result ) ) {
			$this->log( 'Échec remboursement commande #' . $order->get_id() . ' : ' . $result->get_error_message() );
			return $result;
		}

		$order->add_order_note( __( 'AsukaPay : remboursement demandé.', 'asukapay-for-woocommerce' ) );

		return true;
	}

	private function log( string $message ): void {
		if ( 'yes' !== $this->get_option( 'debug', 'no' ) ) {
			return;
		}

		wc_get_logger()->debug( $message, array( 'source' => 'asukapay' ) );
	}
}
