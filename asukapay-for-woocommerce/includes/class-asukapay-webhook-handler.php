<?php

defined( 'ABSPATH' ) || exit;

/**
 * Réception des notifications sortantes AsukaPay (payment.succeeded,
 * payment.failed, payment.refunded, refund.*, webhook.test).
 *
 * Route REST plutôt que ?wc-api=… : WP_REST_Request expose le corps brut
 * sans réencodage JSON, ce qui est indispensable pour vérifier la signature
 * (docs.asukapay.com/webhooks.html#verifier-la-signature — « le corps brut,
 * pas le JSON réencodé »).
 */
class Asukapay_Webhook_Handler {

	const NAMESPACE = 'asukapay/v1';
	const ROUTE     = '/webhook';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// L'authenticité est prouvée par la signature HMAC, pas par
				// les mécanismes d'auth WP (l'appelant n'a ni cookie ni clé
				// applicative WordPress).
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		$raw       = $request->get_body();
		$signature = $request->get_header( 'x-asukapay-signature' );

		if ( empty( $signature ) ) {
			// Compat intégrations pré-renommage AsukaPay, mêmes valeurs.
			$signature = $request->get_header( 'x-mivaapay-signature' );
		}

		$secret = $this->matching_secret( (string) $signature, $raw );

		if ( null === $secret ) {
			return new WP_REST_Response( array( 'message' => 'invalid signature' ), 400 );
		}

		$event = json_decode( $raw, true );

		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['event'] ) ) {
			return new WP_REST_Response( array( 'message' => 'malformed payload' ), 400 );
		}

		$this->dispatch( $event );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Essaie le secret live puis le secret test (l'unique route reçoit les
	 * deux environnements) ; renvoie celui qui vérifie la signature, ou null.
	 */
	private function matching_secret( string $header, string $raw ): ?string {
		if ( '' === $header || '' === $raw ) {
			return null;
		}

		$parts = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$pair = explode( '=', $pair, 2 );
			if ( 2 === count( $pair ) ) {
				$parts[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		$timestamp = $parts['t'] ?? '';
		$received  = $parts['v1'] ?? '';

		if ( '' === $timestamp || '' === $received || abs( time() - (int) $timestamp ) > 300 ) {
			return null;
		}

		$settings = get_option( 'woocommerce_asukapay_settings', array() );

		foreach ( array( 'webhook_secret_live', 'webhook_secret_test' ) as $key ) {
			$secret = $settings[ $key ] ?? '';
			if ( '' === $secret ) {
				continue;
			}

			$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );

			if ( hash_equals( $expected, $received ) ) {
				return $secret;
			}
		}

		return null;
	}

	private function dispatch( array $event ): void {
		$data       = $event['data'] ?? array();
		$order_id   = isset( $data['external_id'] ) ? absint( $data['external_id'] ) : 0;
		$order      = $order_id ? wc_get_order( $order_id ) : false;

		if ( 'webhook.test' === $event['event'] ) {
			return;
		}

		if ( ! $order instanceof WC_Order || 'asukapay' !== $order->get_payment_method() ) {
			return;
		}

		if ( $this->already_processed( $order, $event['id'] ) ) {
			return;
		}

		switch ( $event['event'] ) {
			case 'payment.succeeded':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $data['reference'] ?? '' );
					$order->add_order_note( __( 'AsukaPay : paiement confirmé par webhook.', 'asukapay-for-woocommerce' ) );
				}
				break;

			case 'payment.failed':
				$order->update_status( 'failed', __( 'AsukaPay : paiement refusé par l’opérateur.', 'asukapay-for-woocommerce' ) );
				break;

			case 'payment.refunded':
				if ( ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', __( 'AsukaPay : paiement remboursé.', 'asukapay-for-woocommerce' ) );
				}
				break;

			case 'refund.succeeded':
				$order->add_order_note( __( 'AsukaPay : remboursement confirmé par l’opérateur.', 'asukapay-for-woocommerce' ) );
				break;

			case 'refund.failed':
				$order->add_order_note( __( 'AsukaPay : le remboursement a échoué chez l’opérateur — vérifiez le tableau de bord AsukaPay.', 'asukapay-for-woocommerce' ) );
				break;

			default:
				return;
		}

		$this->remember_processed( $order, $event['id'] );
	}

	private function already_processed( WC_Order $order, string $delivery_id ): bool {
		$processed = $order->get_meta( '_asukapay_processed_deliveries', true );
		return is_array( $processed ) && in_array( $delivery_id, $processed, true );
	}

	private function remember_processed( WC_Order $order, string $delivery_id ): void {
		$processed   = $order->get_meta( '_asukapay_processed_deliveries', true );
		$processed   = is_array( $processed ) ? $processed : array();
		$processed[] = $delivery_id;

		// Un ordre ne reçoit jamais des dizaines d'événements : borner évite
		// juste une dérive si un jour ce n'est plus vrai.
		if ( count( $processed ) > 20 ) {
			$processed = array_slice( $processed, -20 );
		}

		$order->update_meta_data( '_asukapay_processed_deliveries', $processed );
		$order->save();
	}
}
