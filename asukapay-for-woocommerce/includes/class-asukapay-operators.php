<?php

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue des moyens de paiement du compte marchand.
 *
 * docs.asukapay.com/operateurs.html est explicite : « La liste qui fait foi
 * est celle de l'API… interrogez GET /payment-modes plutôt que de coder
 * cette liste en dur. » On appelle donc l'API et on met le résultat en
 * cache (transient WP, 1h) plutôt que de figer les opérateurs dans le code.
 *
 * Le repli statique ci-dessous ne sert que si l'appel échoue (compte non
 * encore configuré, API indisponible) : il reflète l'état de la doc au
 * 2026-09-30 et ne doit jamais être préféré au catalogue réel.
 */
class Asukapay_Operators {

	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Opérateurs Mobile Money exigeant un OTP saisi par le client
	 * (docs.asukapay.com/operateurs.html#operateurs-a-code-otp), utilisé
	 * uniquement en repli si le catalogue live ne renvoie pas requires_otp.
	 */
	const FALLBACK_OTP_OPERATORS = array( 'coris', 'orange_bf', 'wave_bf', 'orange_sn' );

	/**
	 * @return array{modes: array<int, array>, error: ?string}
	 */
	public static function get_catalog( string $secret_key, bool $force_refresh = false ): array {
		$cache_key = 'asukapay_modes_' . md5( $secret_key );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return array(
					'modes' => $cached,
					'error' => null,
				);
			}
		}

		$client = new Asukapay_Api_Client( $secret_key );
		$modes  = $client->get_payment_modes();

		if ( is_wp_error( $modes ) ) {
			return array(
				'modes' => array(),
				'error' => $modes->get_error_message(),
			);
		}

		$modes = is_array( $modes ) ? $modes : array();
		set_transient( $cache_key, $modes, self::CACHE_TTL );

		return array(
			'modes' => $modes,
			'error' => null,
		);
	}

	public static function flush_cache( string $secret_key ): void {
		delete_transient( 'asukapay_modes_' . md5( $secret_key ) );
	}

	/**
	 * Regroupe le catalogue par pays pour l'affichage dans le <select> du
	 * tunnel de commande. Les moyens de type `card` sont fusionnés sous une
	 * seule entrée (pas de numéro de téléphone à collecter pour eux).
	 *
	 * @param array<int, array> $modes
	 * @return array{groups: array<string, array<int, array>>, card_operators: array<int, string>}
	 */
	public static function group_for_checkout( array $modes ): array {
		$groups         = array();
		$card_operators = array();

		foreach ( $modes as $mode ) {
			$type = $mode['type'] ?? 'mobile_money';

			if ( 'card' === $type ) {
				$card_operators[] = $mode['operator'] ?? $mode['id'] ?? '';
				continue;
			}

			$country            = $mode['country'] ?? __( 'Autres', 'asukapay-for-woocommerce' );
			$groups[ $country ] = $groups[ $country ] ?? array();
			$groups[ $country ][] = $mode;
		}

		return array(
			'groups'         => $groups,
			'card_operators' => array_values( array_filter( array_unique( $card_operators ) ) ),
		);
	}

	public static function requires_otp( array $mode ): bool {
		if ( array_key_exists( 'requires_otp', $mode ) ) {
			return (bool) $mode['requires_otp'];
		}

		return in_array( strtolower( (string) ( $mode['operator'] ?? '' ) ), self::FALLBACK_OTP_OPERATORS, true );
	}
}
