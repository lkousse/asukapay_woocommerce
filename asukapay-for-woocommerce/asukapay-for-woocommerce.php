<?php
/**
 * Plugin Name: AsukaPay pour WooCommerce
 * Plugin URI: https://asukapay.com
 * Description: Encaissez Mobile Money (MTN, Moov, Orange, Wave, Coris, Free, Togocom...) et carte bancaire directement dans le tunnel de commande WooCommerce, via l'API AsukaPay.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.3
 * Author: AsukaPay
 * Author URI: https://asukapay.com
 * Text Domain: asukapay-for-woocommerce
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'ASUKAPAY_WC_VERSION', '1.0.0' );
define( 'ASUKAPAY_WC_FILE', __FILE__ );
define( 'ASUKAPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASUKAPAY_WC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Tout démarre après le chargement des plugins : on a besoin de savoir si
 * WooCommerce est actif, et WC_Payment_Gateway ne peut être étendue avant
 * que la classe existe.
 */
add_action( 'plugins_loaded', 'asukapay_wc_init', 11 );

function asukapay_wc_init() {
	load_plugin_textdomain( 'asukapay-for-woocommerce', false, dirname( plugin_basename( ASUKAPAY_WC_FILE ) ) . '/languages' );

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'asukapay_wc_missing_woocommerce_notice' );
		return;
	}

	require_once ASUKAPAY_WC_PATH . 'includes/class-asukapay-api-client.php';
	require_once ASUKAPAY_WC_PATH . 'includes/class-asukapay-operators.php';
	require_once ASUKAPAY_WC_PATH . 'includes/class-asukapay-webhook-handler.php';
	require_once ASUKAPAY_WC_PATH . 'includes/class-wc-gateway-asukapay.php';

	Asukapay_Webhook_Handler::instance();

	add_filter( 'woocommerce_payment_gateways', 'asukapay_wc_add_gateway' );
	add_filter( 'plugin_action_links_' . plugin_basename( ASUKAPAY_WC_FILE ), 'asukapay_wc_settings_link' );
}

function asukapay_wc_add_gateway( array $gateways ): array {
	$gateways[] = 'WC_Gateway_Asukapay';
	return $gateways;
}

function asukapay_wc_settings_link( array $links ): array {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=asukapay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Réglages', 'asukapay-for-woocommerce' ) . '</a>' );
	return $links;
}

function asukapay_wc_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'AsukaPay pour WooCommerce nécessite WooCommerce actif pour fonctionner.', 'asukapay-for-woocommerce' );
	echo '</p></div>';
}

/**
 * Déclare la compatibilité HPOS (High-Performance Order Storage) — sans quoi
 * WooCommerce affiche un avertissement d'incompatibilité dans l'admin depuis
 * la 8.x, même si le plugin n'accède qu'aux méthodes publiques de WC_Order.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ASUKAPAY_WC_FILE, true );
	}
} );
