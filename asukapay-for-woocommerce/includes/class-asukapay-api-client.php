<?php

defined( 'ABSPATH' ) || exit;

/**
 * Client HTTP pour l'API publique marchand AsukaPay (/api/v1).
 *
 * Auth par Bearer token (la clé secrète) — l'environnement (live/test) est
 * déterminé côté AsukaPay par le préfixe de la clé (sk_test_… vs sk_…), pas
 * par un paramètre de requête. Voir docs.asukapay.com/authentification.html.
 */
class Asukapay_Api_Client {

	const BASE_URL = 'https://api.asukapay.com/api/v1';

	/** @var string */
	private $secret_key;

	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * @return array|WP_Error Le contenu de `data` en cas de succès.
	 */
	public function create_payment( array $payload ) {
		return $this->request( 'POST', '/payments', $payload );
	}

	/**
	 * @return array|WP_Error
	 */
	public function get_payment( string $reference ) {
		return $this->request( 'GET', '/payments/' . rawurlencode( $reference ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public function refund_payment( string $reference, ?int $amount_minor = null, ?string $reason = null ) {
		$payload = array();
		if ( null !== $amount_minor ) {
			$payload['amount'] = $amount_minor;
		}
		if ( null !== $reason && '' !== $reason ) {
			$payload['reason'] = $reason;
		}

		return $this->request( 'POST', '/payments/' . rawurlencode( $reference ) . '/refund', $payload );
	}

	/**
	 * @return array|WP_Error Le tableau des moyens de paiement du compte.
	 */
	public function get_payment_modes() {
		return $this->request( 'GET', '/payment-modes' );
	}

	/**
	 * Enregistre (ou change) l'URL de webhook du compte pour l'environnement
	 * de cette clé, et renvoie le secret servant à vérifier les signatures.
	 *
	 * @return array|WP_Error
	 */
	public function register_webhook_endpoint( string $url ) {
		return $this->request( 'PUT', '/webhooks/endpoint', array( 'url' => $url ) );
	}

	/**
	 * @return array|WP_Error
	 */
	private function request( string $method, string $path, ?array $payload = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $payload && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $payload );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $body ) && array_key_exists( 'data', $body ) ? $body['data'] : array();
		}

		$error = is_array( $body ) && isset( $body['error'] ) ? $body['error'] : array();

		return new WP_Error(
			$error['code'] ?? 'server_error',
			$error['message'] ?? __( 'AsukaPay est momentanément indisponible, réessayez.', 'asukapay-for-woocommerce' ),
			array(
				'status'  => $status,
				'details' => $error['details'] ?? null,
			)
		);
	}
}
