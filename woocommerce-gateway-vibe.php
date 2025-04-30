<?php

/**
 * Plugin Name: WooCommerce Vibe Payment Gateway
 * Plugin URI: https://vibe.ir
 * Description: Adds the Vibe Payment gateway to your WooCommerce website.
 * Version: 1.0.1
 *
 * Author: Vibe
 * Author URI: https://vibe.ir
 *
 * Text Domain: woocommerce-gateway-vibe
 * Domain Path: /i18n/languages/
 *
 * Requires at least: 5.0
 * Tested up to: 6.6
 *
 * Copyright: © 2024 Vibe.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('WC_VIBE_VERSION', '1.0.1');
define('WC_VIBE_PLUGIN_FILE', __FILE__);
define('WC_VIBE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_VIBE_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * WC Vibe Payment gateway plugin class.
 *
 * @class WC_Vibe_Payments
 */
class WC_Vibe_Payments
{

	/**
	 * Plugin bootstrapping.
	 */
	public static function init()
	{
		// Load plugin text domain - move this before plugins_loaded
		self::load_plugin_textdomain();

		// Vibe Payments gateway class.
		add_action('plugins_loaded', array(__CLASS__, 'includes'), 0);

		// Make the Vibe Payments gateway available to WC.
		add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));

		// Registers WooCommerce Blocks integration.
		add_action('woocommerce_blocks_loaded', array(__CLASS__, 'woocommerce_gateway_vibe_woocommerce_block_support'));
		
		// Initialize the tracker if WooCommerce is active
		add_action('plugins_loaded', array(__CLASS__, 'init_tracker'), 20);
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public static function load_plugin_textdomain() {
		load_plugin_textdomain(
			'woocommerce-gateway-vibe',
			false,
			dirname(plugin_basename(__FILE__)) . '/i18n/languages/'
		);
	}

	/**
	 * Add the Vibe Payment gateway to the list of available gateways.
	 *
	 * @param array $gateways Array of payment gateway instances.
	 * @return array Modified array of payment gateway instances.
	 */
	public static function add_gateway($gateways)
	{
		$gateways[] = 'WC_Gateway_Vibe';
		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes()
	{

		// Make the WC_Gateway_Vibe class available.
		if (class_exists('WC_Payment_Gateway')) {
			require_once 'includes/class-wc-gateway-vibe.php';
			
			// Include the API class for product information collection.
			require_once 'includes/class-wc-vibe-api.php';
			
			// Include the API settings class.
			require_once 'includes/class-wc-vibe-api-settings.php';
		}
	}
	
	/**
	 * Initialize the tracker.
	 */
	public static function init_tracker() {
		// Only load the tracker if WooCommerce is active
		if (class_exists('WC_Payment_Gateway')) {
			require_once 'includes/class-wc-vibe-tracker.php';
			WC_Vibe_Tracker::init();
		}
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url()
	{
		return untrailingslashit(plugins_url('/', __FILE__));
	}

	/**
	 * Plugin absolute path.
	 *
	 * @return string
	 */
	public static function plugin_abspath()
	{
		return trailingslashit(plugin_dir_path(__FILE__));
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 *
	 */
	public static function woocommerce_gateway_vibe_woocommerce_block_support()
	{
		if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			require_once 'includes/blocks/class-wc-vibe-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$payment_method_registry->register(new WC_Gateway_Vibe_Blocks_Support());
				}
			);
		}
	}
}

WC_Vibe_Payments::init();

// Register activation hook to set activation flag
register_activation_hook(__FILE__, 'wc_vibe_set_activation_flag');

/**
 * Set the activation flag when the plugin is activated.
 * The tracker will check for this flag and send the activation event.
 */
function wc_vibe_set_activation_flag() {
    // Set the activation flag to pending
    update_option('wc_vibe_activation_pending', 'pending');
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Vibe Tracker] Activation hook executed - flag set to pending');
    }
}

// Register uninstall hook to clean up tracking data
register_uninstall_hook(__FILE__, 'wc_vibe_uninstall');

/**
 * Clean up when the plugin is uninstalled.
 */
function wc_vibe_uninstall() {
	// Only load the tracker class if it doesn't exist yet
	if (!class_exists('WC_Vibe_Tracker')) {
		require_once 'includes/class-wc-vibe-tracker.php';
	}
	
	// Clean up tracker data
	WC_Vibe_Tracker::cleanup();
}
