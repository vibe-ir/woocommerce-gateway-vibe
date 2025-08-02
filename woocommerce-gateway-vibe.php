<?php

/**
 * Plugin Name: WooCommerce Vibe Payment Gateway
 * Plugin URI: https://vibe.ir
 * Description: Adds the Vibe Payment gateway to your WooCommerce website with dynamic pricing based on referrer detection.
 * Version: 1.2.1
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
define('WC_VIBE_VERSION', '1.2.1');
define('WC_VIBE_PLUGIN_FILE', __FILE__);
define('WC_VIBE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_VIBE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize Plugin Update Checker
require_once WC_VIBE_PLUGIN_PATH . 'includes/update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$vibeUpdateChecker = PucFactory::buildUpdateChecker(
	'https://crm-api.vibe.ir/api/v1/plugin/check-update',
	__FILE__,
	'woocommerce-gateway-vibe'
);

// Enable auto-update for this plugin by default
add_filter('auto_update_plugin', function($update, $item) {
    if ($item->plugin === plugin_basename(__FILE__)) {
        return true;
    }
    return $update;
}, 10, 2);

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

		// Initialize dynamic pricing system
		add_action('plugins_loaded', array(__CLASS__, 'init_dynamic_pricing'), 10);

		// Only show Vibe gateway if all cart items have a dynamic price (vibe price)
		add_filter('woocommerce_available_payment_gateways', function ($available_gateways) {
			// Ensure WooCommerce functions are available
			if (!function_exists('is_checkout') || !function_exists('WC')) {
				return $available_gateways;
			}
			if (!is_checkout() || !isset($available_gateways['vibe'])) {
				return $available_gateways;
			}
			if (!WC()->cart) {
				return $available_gateways;
			}
			// Get pricing engine
			$pricing_engine = null;
			if (class_exists('WC_Vibe_Dynamic_Pricing')) {
				$pricing_engine = \WC_Vibe_Dynamic_Pricing::get_instance()->get_pricing_engine();
			}
			if (!$pricing_engine) {
				return $available_gateways;
			}

			// Save current context
			$previous_method = $pricing_engine->get_current_payment_method();
			// Set context to 'vibe' for the check
			$pricing_engine->set_current_payment_method('vibe');

			$has_dynamic_pricing_for_all = true;
			$cart_items = WC()->cart->get_cart();

			// If cart is empty, don't show Vibe gateway
			if (empty($cart_items)) {
				$has_dynamic_pricing_for_all = false;
			} else {
				foreach ($cart_items as $cart_item) {
					if (!isset($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
						$has_dynamic_pricing_for_all = false;
						break;
					}
					$product = $cart_item['data'];

					// For variable products, check if this is a variation
					if ($product->is_type('variation')) {
						// For variations, also check the parent product rules
						$parent_product = wc_get_product($product->get_parent_id());
						if ($parent_product) {
							$has_parent_rules = $pricing_engine->has_applicable_rules_for_product($parent_product, 'vibe');
							$has_variant_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
							// Product has rules if either parent or variant has rules
							$has_pricing_rules = $has_parent_rules || $has_variant_rules;
						} else {
							$has_pricing_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
						}
					} else {
						// For simple products, check normally
						$has_pricing_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
					}

					if (!$has_pricing_rules) {
						$has_dynamic_pricing_for_all = false;
						break;
					}
				}
			}


			// Remove Vibe gateway if not all products have dynamic pricing
			if (!$has_dynamic_pricing_for_all) {
				unset($available_gateways['vibe']);
			}

			// Restore previous context
			$pricing_engine->set_current_payment_method($previous_method);

			return $available_gateways;
		}, 100);

		// Restrict Vibe gateway in Store API (WooCommerce Blocks) context
		add_filter('woocommerce_store_api_payment_gateways', function ($gateways, $request) {
			return self::filter_vibe_gateway_for_blocks($gateways);
		}, 100, 2);

		// Try additional Store API hooks that might work better
		add_filter('woocommerce_blocks_loaded', function () {
			if (function_exists('woocommerce_store_api_register_endpoint_data')) {
				woocommerce_store_api_register_endpoint_data(array(
					'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace' => 'vibe-dynamic-pricing',
					'data_callback' => function () {
						return self::get_vibe_gateway_availability();
					},
					'schema_callback' => function () {
						return array(
							'vibe_gateway_available' => array(
								'description' => 'Whether Vibe gateway should be available',
								'type' => 'boolean',
								'readonly' => true,
							),
						);
					},
				));
			}
		});

		// Alternative approach - hook into gateway availability directly
		add_filter('woocommerce_payment_gateway_supports', function ($supports, $feature, $gateway) {
			if ($gateway && $gateway->id === 'vibe' && $feature === 'products') {
				$available = self::get_vibe_gateway_availability();
				return $available['vibe_gateway_available'] ? $supports : false;
			}
			return $supports;
		}, 100, 3);
	}

	/**
	 * Filter Vibe gateway for blocks checkout.
	 *
	 * @param array $gateways Available gateways.
	 * @return array Filtered gateways.
	 */
	public static function filter_vibe_gateway_for_blocks($gateways)
	{
		if (!function_exists('WC')) {
			return $gateways;
		}
		if (!isset($gateways['vibe'])) {
			return $gateways;
		}
		if (!WC()->cart) {
			return $gateways;
		}
		$pricing_engine = null;
		if (class_exists('WC_Vibe_Dynamic_Pricing')) {
			$pricing_engine = \WC_Vibe_Dynamic_Pricing::get_instance()->get_pricing_engine();
		}
		if (!$pricing_engine) {
			return $gateways;
		}

		// Save current context
		$previous_method = $pricing_engine->get_current_payment_method();
		// Set context to 'vibe' for the check
		$pricing_engine->set_current_payment_method('vibe');

		$has_dynamic_pricing_for_all = true;
		$cart_items = WC()->cart->get_cart();

		// If cart is empty, don't show Vibe gateway
		if (empty($cart_items)) {
			$has_dynamic_pricing_for_all = false;
		} else {
			foreach ($cart_items as $cart_item) {
				if (!isset($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
					$has_dynamic_pricing_for_all = false;
					break;
				}
				$product = $cart_item['data'];

				// For variable products, check if this is a variation
				if ($product->is_type('variation')) {
					// For variations, also check the parent product rules
					$parent_product = wc_get_product($product->get_parent_id());
					if ($parent_product) {
						$has_parent_rules = $pricing_engine->has_applicable_rules_for_product($parent_product, 'vibe');
						$has_variant_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
						// Product has rules if either parent or variant has rules
						$has_pricing_rules = $has_parent_rules || $has_variant_rules;
					} else {
						$has_pricing_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
					}
				} else {
					// For simple products, check normally
					$has_pricing_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
				}

				if (!$has_pricing_rules) {
					$has_dynamic_pricing_for_all = false;
					break;
				}
			}
		}


		// Remove Vibe gateway if not all products have dynamic pricing
		if (!$has_dynamic_pricing_for_all) {
			unset($gateways['vibe']);
		}

		// Restore previous context
		$pricing_engine->set_current_payment_method($previous_method);

		return $gateways;
	}

	/**
	 * Get Vibe gateway availability status.
	 *
	 * @return array Availability data.
	 */
	public static function get_vibe_gateway_availability()
	{
		if (!function_exists('WC') || !WC()->cart) {
			return array('vibe_gateway_available' => false);
		}

		$pricing_engine = null;
		if (class_exists('WC_Vibe_Dynamic_Pricing')) {
			$pricing_engine = \WC_Vibe_Dynamic_Pricing::get_instance()->get_pricing_engine();
		}
		if (!$pricing_engine) {
			return array('vibe_gateway_available' => false);
		}

		// Save current context
		$previous_method = $pricing_engine->get_current_payment_method();
		// Set context to 'vibe' for the check
		$pricing_engine->set_current_payment_method('vibe');

		$has_dynamic_pricing_for_all = true;
		$cart_items = WC()->cart->get_cart();

		// If cart is empty, don't show Vibe gateway
		if (empty($cart_items)) {
			$has_dynamic_pricing_for_all = false;
		} else {
			foreach ($cart_items as $cart_item) {
				if (!isset($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
					$has_dynamic_pricing_for_all = false;
					break;
				}
				$product = $cart_item['data'];

				// For variable products, check if this is a variation
				if ($product->is_type('variation')) {
					// For variations, also check the parent product rules
					$parent_product = wc_get_product($product->get_parent_id());
					if ($parent_product) {
						$has_parent_rules = $pricing_engine->has_applicable_rules_for_product($parent_product, 'vibe');
						$has_variant_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
						// Product has rules if either parent or variant has rules
						$has_pricing_rules = $has_parent_rules || $has_variant_rules;
					} else {
						$has_pricing_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
					}
				} else {
					// For simple products, check normally
					$has_pricing_rules = $pricing_engine->has_applicable_rules_for_product($product, 'vibe');
				}

				if (!$has_pricing_rules) {
					$has_dynamic_pricing_for_all = false;
					break;
				}
			}
		}

		// Restore previous context
		$pricing_engine->set_current_payment_method($previous_method);


		return array('vibe_gateway_available' => $has_dynamic_pricing_for_all);
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public static function load_plugin_textdomain()
	{
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
	public static function init_tracker()
	{
		// Only load the tracker if WooCommerce is active
		if (class_exists('WC_Payment_Gateway')) {
			require_once 'includes/class-wc-vibe-tracker.php';
			WC_Vibe_Tracker::init();
		}
	}

	/**
	 * Initialize the dynamic pricing system.
	 */
	public static function init_dynamic_pricing()
	{
		// Only load dynamic pricing if WooCommerce is active
		if (!class_exists('WC_Payment_Gateway')) {
			// Add admin notice if WooCommerce is not active
			if (is_admin()) {
				add_action('admin_notices', function () {
					echo '<div class="notice notice-error"><p>' .
						__('WooCommerce Vibe Payment Gateway requires WooCommerce to be active for dynamic pricing features.', 'woocommerce-gateway-vibe') .
						'</p></div>';
				});
			}
			return;
		}

		try {
			// Core dynamic pricing classes
			require_once 'includes/class-wc-vibe-dynamic-pricing.php';
			require_once 'includes/class-wc-vibe-pricing-engine.php';
			require_once 'includes/class-wc-vibe-cache-manager.php';
			require_once 'includes/class-wc-vibe-price-display.php';
			require_once 'includes/class-wc-vibe-payment-integration.php';

			// Admin classes
			if (is_admin()) {
				require_once 'includes/class-wc-vibe-admin-interface.php';
			}

			// Initialize the main dynamic pricing class
			WC_Vibe_Dynamic_Pricing::get_instance();
		} catch (Exception $e) {
			// Log error and show notice
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Vibe Dynamic Pricing] Initialization error: ' . $e->getMessage());
			}

			if (is_admin()) {
				add_action('admin_notices', function () use ($e) {
					echo '<div class="notice notice-error"><p>' .
						/* translators: %s: error message describing the initialization failure */
						sprintf(__('WooCommerce Vibe Dynamic Pricing failed to initialize: %s', 'woocommerce-gateway-vibe'), $e->getMessage()) .
						'</p></div>';
				});
			}
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

// Register activation hook to set activation flag and create database tables
register_activation_hook(__FILE__, 'wc_vibe_activation');

/**
 * Set the activation flag when the plugin is activated.
 * Also create necessary database tables for dynamic pricing.
 */
function wc_vibe_activation()
{
	try {
		// Set the activation flag to pending
		update_option('wc_vibe_activation_pending', 'pending');

		// Create dynamic pricing database tables only if WooCommerce is available
		if (class_exists('WC_Payment_Gateway')) {
			require_once WC_VIBE_PLUGIN_PATH . 'includes/class-wc-vibe-dynamic-pricing.php';
			WC_Vibe_Dynamic_Pricing::create_tables();
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Vibe Tracker] Plugin activated successfully');
		}
	} catch (Exception $e) {
		// Log the error
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Vibe Plugin] Activation error: ' . $e->getMessage());
		}

		// Don't let activation fail completely - set a flag for later processing
		update_option('wc_vibe_activation_error', $e->getMessage());
	}
}

// Register uninstall hook to clean up tracking data
register_uninstall_hook(__FILE__, 'wc_vibe_uninstall');

/**
 * Clean up when the plugin is uninstalled.
 */
function wc_vibe_uninstall()
{
	// Exit early if not in WordPress context
	if (!defined('WP_UNINSTALL_PLUGIN') && !defined('ABSPATH')) {
		return;
	}

	try {
		// Define plugin path if not already defined
		if (!defined('WC_VIBE_PLUGIN_PATH')) {
			define('WC_VIBE_PLUGIN_PATH', plugin_dir_path(__FILE__));
		}

		// Ensure we have WordPress database access
		if (!isset($GLOBALS['wpdb'])) {
			return;
		}

		global $wpdb;

		// Clean up tracker data safely
		$tracker_file = WC_VIBE_PLUGIN_PATH . 'includes/class-wc-vibe-tracker.php';
		if (file_exists($tracker_file)) {
			try {
				if (!class_exists('WC_Vibe_Tracker')) {
					require_once $tracker_file;
				}

				// Only cleanup if class exists and has cleanup method
				if (class_exists('WC_Vibe_Tracker') && method_exists('WC_Vibe_Tracker', 'cleanup')) {
					WC_Vibe_Tracker::cleanup();
				}
			} catch (Exception $e) {
				// Continue with manual cleanup if tracker fails
				if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
					error_log('[Vibe Plugin] Tracker cleanup failed: ' . $e->getMessage());
				}
			}
		}

		// Clean up dynamic pricing data safely
		$pricing_file = WC_VIBE_PLUGIN_PATH . 'includes/class-wc-vibe-dynamic-pricing.php';
		if (file_exists($pricing_file)) {
			try {
				if (!class_exists('WC_Vibe_Dynamic_Pricing')) {
					require_once $pricing_file;
				}

				// Only cleanup if class exists and has cleanup method
				if (class_exists('WC_Vibe_Dynamic_Pricing') && method_exists('WC_Vibe_Dynamic_Pricing', 'cleanup_tables')) {
					WC_Vibe_Dynamic_Pricing::cleanup_tables();
				}
			} catch (Exception $e) {
				// Continue with manual cleanup if dynamic pricing cleanup fails
				if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
					error_log('[Vibe Plugin] Dynamic pricing cleanup failed: ' . $e->getMessage());
				}
			}
		}

		// Manual cleanup as fallback - this should always work
		// Clean up database tables
		$tables_to_drop = array(
			$wpdb->prefix . 'vibe_pricing_rules',
			$wpdb->prefix . 'vibe_pricing_cache'
		);

		foreach ($tables_to_drop as $table) {
			$wpdb->query("DROP TABLE IF EXISTS {$table}");
		}

		// Clean up options (check if function exists first)
		if (function_exists('delete_option')) {
			$options_to_delete = array(
				'wc_vibe_activation_pending',
				'wc_vibe_activation_error',
				'vibe_dynamic_pricing_version',
				'vibe_dynamic_pricing_emergency_disable',
				'vibe_dynamic_pricing_enabled',
				'vibe_dynamic_pricing_apply_mode',
				'vibe_dynamic_pricing_settings',
				'vibe_price_display_settings',
				'wc_vibe_last_heartbeat',
				'wc_vibe_tracking_cache'
			);

			foreach ($options_to_delete as $option) {
				delete_option($option);
			}
		}

		// Clean up transients
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} 
				 WHERE option_name LIKE %s 
				 OR option_name LIKE %s",
				'_transient_vibe_%',
				'_transient_timeout_vibe_%'
			)
		);

		// Clear any scheduled hooks
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook('wc_vibe_heartbeat');
		}

		// Log successful cleanup
		if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
			error_log('[Vibe Plugin] Uninstall completed successfully');
		}
	} catch (Exception $e) {
		// Log the error but don't fail the uninstall - do minimal cleanup
		if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
			error_log('[Vibe Plugin] Uninstall error: ' . $e->getMessage());
		}

		// Minimal cleanup as last resort
		if (isset($GLOBALS['wpdb'])) {
			global $wpdb;
			$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}vibe_pricing_rules");
			$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}vibe_pricing_cache");

			if (function_exists('delete_option')) {
				delete_option('vibe_dynamic_pricing_enabled');
				delete_option('vibe_dynamic_pricing_emergency_disable');
				delete_option('wc_vibe_activation_pending');
			}
		}
	}
}
