<?php

/**
 * WC Vibe Dynamic Pricing Main Class
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.1.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Main class for WC Vibe Dynamic Pricing.
 *
 * @class    WC_Vibe_Dynamic_Pricing
 * @version  1.1.0
 */
class WC_Vibe_Dynamic_Pricing {

	/**
	 * Single instance of the class.
	 *
	 * @var WC_Vibe_Dynamic_Pricing
	 */
	private static $instance = null;

	/**
	 * Pricing engine instance.
	 *
	 * @var WC_Vibe_Pricing_Engine
	 */
	private $pricing_engine;

	/**
	 * Cache manager instance.
	 *
	 * @var WC_Vibe_Cache_Manager
	 */
	private $cache_manager;

	/**
	 * Price display instance.
	 *
	 * @var WC_Vibe_Price_Display
	 */
	private $price_display;

	/**
	 * Payment integration instance.
	 *
	 * @var WC_Vibe_Payment_Integration
	 */
	private $payment_integration;

	/**
	 * Admin interface instance.
	 *
	 * @var WC_Vibe_Admin_Interface
	 */
	private $admin_interface;

	/**
	 * Emergency disable flag.
	 *
	 * @var bool
	 */
	private $emergency_disabled = false;

	/**
	 * Get the single instance of the class.
	 *
	 * @return WC_Vibe_Dynamic_Pricing
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the dynamic pricing system.
	 */
	private function init() {
		// Check if dynamic pricing is enabled
		if ('no' === get_option('wc_vibe_dynamic_pricing_enabled', 'yes')) {
			return; // Exit early if disabled
		}
		
		// Check if emergency disabled
		$this->emergency_disabled = 'yes' === get_option('wc_vibe_dynamic_pricing_emergency_disable', 'no');
		
		if ($this->emergency_disabled) {
			return; // Exit early if emergency disabled
		}

		// Initialize core components
		$this->init_components();

		// Hook into WooCommerce
		$this->init_hooks();

		// Initialize admin interface if in admin
		if (is_admin()) {
			$this->init_admin();
		}
	}

	/**
	 * Initialize core components.
	 */
	private function init_components() {
		// Initialize cache manager first
		$this->cache_manager = new WC_Vibe_Cache_Manager();

		// Initialize pricing engine
		$this->pricing_engine = new WC_Vibe_Pricing_Engine($this->cache_manager);

		// Initialize price display
		$this->price_display = new WC_Vibe_Price_Display($this->pricing_engine);

		// Initialize payment integration
		$this->payment_integration = new WC_Vibe_Payment_Integration($this->pricing_engine);
	}

	/**
	 * Initialize WooCommerce hooks.
	 */
	private function init_hooks() {
		// Product price modification hooks
		add_filter('woocommerce_product_get_price', array($this, 'modify_product_price'), 99, 2);
		add_filter('woocommerce_product_get_regular_price', array($this, 'modify_product_regular_price'), 99, 2);
		add_filter('woocommerce_product_variation_get_price', array($this, 'modify_product_price'), 99, 2);
		add_filter('woocommerce_product_variation_get_regular_price', array($this, 'modify_product_regular_price'), 99, 2);

		// Cart and checkout hooks
		add_filter('woocommerce_cart_item_price', array($this, 'modify_cart_item_price'), 99, 3);
		add_action('woocommerce_checkout_update_order_review', array($this, 'update_checkout_prices'));
		
		// Enhanced AJAX hooks for payment method changes
		add_action('wp_ajax_woocommerce_checkout_update_order_review', array($this, 'handle_payment_method_change'), 5);
		add_action('wp_ajax_nopriv_woocommerce_checkout_update_order_review', array($this, 'handle_payment_method_change'), 5);
		
		// Payment method change hooks
		add_action('woocommerce_review_order_before_payment', array($this, 'setup_payment_method_js'));
		add_action('wp_ajax_vibe_update_payment_pricing', array($this, 'ajax_update_payment_pricing'));
		add_action('wp_ajax_nopriv_vibe_update_payment_pricing', array($this, 'ajax_update_payment_pricing'));

		// Enqueue frontend scripts
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
	}

	/**
	 * Initialize admin interface.
	 */
	private function init_admin() {
		$this->admin_interface = new WC_Vibe_Admin_Interface($this->pricing_engine, $this->cache_manager);
	}

	/**
	 * Modify product price based on dynamic pricing rules.
	 *
	 * @param float $price Original price.
	 * @param WC_Product $product Product object.
	 * @return float Modified price.
	 */
	public function modify_product_price($price, $product) {
		// Skip if emergency disabled or no product
		if ($this->emergency_disabled || !$product) {
			return $price;
		}

		// Get the actual original price from the product object to prevent double application
		$original_price = $this->get_product_original_price($product);
		
		// If we can't get the original price, fall back to the passed price
		if ($original_price === false || $original_price <= 0) {
			$original_price = $price;
		}

		// Get dynamic price using the original price as base
		$dynamic_price = $this->pricing_engine->get_dynamic_price($product, $original_price);
		
		return $dynamic_price !== false ? $dynamic_price : $price;
	}

	/**
	 * Modify product regular price based on dynamic pricing rules.
	 *
	 * @param float $regular_price Original regular price.
	 * @param WC_Product $product Product object.
	 * @return float Modified regular price.
	 */
	public function modify_product_regular_price($regular_price, $product) {
		// For regular price, we typically don't modify it to preserve the original
		// unless specifically configured to do so
		return $regular_price;
	}

	/**
	 * Modify cart item price display.
	 *
	 * @param string $price_html Price HTML.
	 * @param array $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Modified price HTML.
	 */
	public function modify_cart_item_price($price_html, $cart_item, $cart_item_key) {
		if ($this->emergency_disabled) {
			return $price_html;
		}

		return $this->price_display->render_cart_price($price_html, $cart_item, $cart_item_key);
	}

	/**
	 * Update checkout prices when order review is updated.
	 */
	public function update_checkout_prices() {
		if ($this->emergency_disabled) {
			return;
		}

		// Clear pricing cache to recalculate with new conditions
		$this->cache_manager->clear_pricing_cache();
	}

	/**
	 * Handle payment method changes via AJAX.
	 */
	public function handle_payment_method_change() {
		if ($this->emergency_disabled) {
			return;
		}

		// Detect payment method from POST data
		$payment_method = '';
		if (!empty($_POST['payment_method'])) {
			$payment_method = sanitize_text_field($_POST['payment_method']);
		}

		// Update pricing engine with new payment method
		if ($payment_method) {
			$this->pricing_engine->set_current_payment_method($payment_method);
			
			// Store in session for persistence
			if (function_exists('WC') && WC()->session) {
				WC()->session->set('chosen_payment_method', $payment_method);
			}
		}

		// Clear pricing cache to recalculate with new payment method
		$this->cache_manager->clear_pricing_cache();
		
		// Force recalculation of cart totals
		if (function_exists('WC') && WC()->cart) {
			// Remove any existing pricing filters temporarily to prevent conflicts
			remove_filter('woocommerce_product_get_price', array($this, 'modify_product_price'), 99);
			remove_filter('woocommerce_product_variation_get_price', array($this, 'modify_product_price'), 99);
			
			// Clear cart cache
			WC()->cart->empty_cart(false);
			
			// Restore cart contents
			if (function_exists('WC') && WC()->session) {
				WC()->cart->get_cart_from_session();
			}
			
			// Re-add pricing filters
			add_filter('woocommerce_product_get_price', array($this, 'modify_product_price'), 99, 2);
			add_filter('woocommerce_product_variation_get_price', array($this, 'modify_product_price'), 99, 2);
			
			// Recalculate totals with new pricing
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Setup JavaScript for payment method changes.
	 */
	public function setup_payment_method_js() {
		if ($this->emergency_disabled || is_admin()) {
			return;
		}
		
		?>
		<script type="text/javascript">
		(function($) {
			'use strict';
			
			$(document.body).on('change', 'input[name="payment_method"]', function() {
				var selectedMethod = $(this).val();
				
				// Update pricing via AJAX
				$.ajax({
					url: '<?php echo admin_url('admin-ajax.php'); ?>',
					type: 'POST',
					data: {
						action: 'vibe_update_payment_pricing',
						payment_method: selectedMethod,
						nonce: '<?php echo wp_create_nonce('vibe_dynamic_pricing_nonce'); ?>'
					},
					success: function(response) {
						if (response.success) {
							// Trigger checkout update
							$(document.body).trigger('update_checkout');
						}
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX handler for payment method pricing updates.
	 */
	public function ajax_update_payment_pricing() {
		// Verify nonce - FIXED: Use correct nonce name to match frontend
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vibe_dynamic_pricing_nonce')) {
			wp_send_json_error(array(
				'message' => 'Security check failed',
				'code' => 'nonce_failed'
			));
		}

		if (!isset($_POST['payment_method'])) {
			wp_send_json_error(array(
				'message' => 'Payment method not provided',
				'code' => 'missing_payment_method'
			));
		}

		$payment_method = sanitize_text_field($_POST['payment_method']);
		
		try {
			// Update pricing engine with new payment method
			$this->pricing_engine->set_current_payment_method($payment_method);
			
			// Store in session
			if (function_exists('WC') && WC()->session) {
				WC()->session->set('chosen_payment_method', $payment_method);
			}
			
			// Clear pricing cache
			$this->cache_manager->clear_pricing_cache();
			
			// Force cart recalculation
			if (function_exists('WC') && WC()->cart) {
				WC()->cart->calculate_totals();
			}
			
			// Determine if dynamic pricing was applied
			$is_vibe_payment = ($payment_method === 'vibe');
			$apply_mode = get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined');
			$pricing_applied = false;
			
			// Check if pricing should be applied based on current context
			if ($apply_mode === 'combined') {
				// Check referrer or payment method
				$referrer = $this->pricing_engine->get_current_referrer();
				$vibe_referrer = $referrer && (strpos($referrer, 'vibe.ir') !== false);
				$pricing_applied = $is_vibe_payment || $vibe_referrer;
			} elseif ($apply_mode === 'payment_method') {
				$pricing_applied = $is_vibe_payment;
			} elseif ($apply_mode === 'referrer') {
				$referrer = $this->pricing_engine->get_current_referrer();
				$pricing_applied = $referrer && (strpos($referrer, 'vibe.ir') !== false);
			} elseif ($apply_mode === 'always') {
				$pricing_applied = true;
			}
			
			$response_data = array(
				'message' => $pricing_applied ? 
					'Special pricing applied for ' . $this->get_payment_method_display_name($payment_method) : 
					'Standard pricing applied for ' . $this->get_payment_method_display_name($payment_method),
				'payment_method' => $payment_method,
				'pricing_applied' => $pricing_applied,
				'apply_mode' => $apply_mode,
				'trigger_checkout_update' => true
			);
			
			wp_send_json_success($response_data);
			
		} catch (Exception $e) {
			wp_send_json_error(array(
				'message' => 'Failed to update pricing: ' . $e->getMessage(),
				'code' => 'update_failed'
			));
		}
	}

	/**
	 * Get display name for payment method.
	 *
	 * @param string $payment_method Payment method ID.
	 * @return string Display name.
	 */
	private function get_payment_method_display_name($payment_method) {
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		
		if (isset($payment_gateways[$payment_method])) {
			return $payment_gateways[$payment_method]->get_title();
		}
		
		// Fallback names
		$fallback_names = array(
			'vibe' => 'Vibe Payment',
			'cod' => 'Cash on Delivery',
			'bacs' => 'Bank Transfer',
			'cheque' => 'Check Payment',
			'paypal' => 'PayPal'
		);
		
		return isset($fallback_names[$payment_method]) ? $fallback_names[$payment_method] : ucfirst($payment_method);
	}

	/**
	 * Enqueue frontend scripts and styles.
	 */
	public function enqueue_frontend_scripts() {
		if ($this->emergency_disabled) {
			return;
		}

		// Check if JS file exists before enqueuing
		$js_file = WC_VIBE_PLUGIN_PATH . 'assets/js/frontend-dynamic-pricing.js';
		if (file_exists($js_file)) {
			// Enqueue price display scripts
			wp_enqueue_script(
				'vibe-dynamic-pricing-frontend',
				WC_VIBE_PLUGIN_URL . 'assets/js/frontend-dynamic-pricing.js',
				array('jquery'),
				WC_VIBE_VERSION,
				true
			);

			// Localize script
			wp_localize_script('vibe-dynamic-pricing-frontend', 'vibe_dynamic_pricing', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('vibe_dynamic_pricing_nonce'),
			));
		}

		// Check if CSS file exists before enqueuing
		$css_file = WC_VIBE_PLUGIN_PATH . 'assets/css/frontend-dynamic-pricing.css';
		if (file_exists($css_file)) {
			// Enqueue styles
			wp_enqueue_style(
				'vibe-dynamic-pricing-frontend',
				WC_VIBE_PLUGIN_URL . 'assets/css/frontend-dynamic-pricing.css',
				array(),
				WC_VIBE_VERSION
			);
		}
	}

	/**
	 * Create database tables for dynamic pricing.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create pricing rules table
		$table_name = $wpdb->prefix . 'vibe_pricing_rules';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			priority int(11) DEFAULT 0,
			status enum('active','inactive') DEFAULT 'active',
			referrer_conditions longtext,
			product_conditions longtext,
			price_adjustment longtext,
			discount_integration enum('apply','ignore') DEFAULT 'apply',
			display_options longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_priority (priority),
			KEY idx_status (status)
		) $charset_collate;";

		// Create cache table
		$cache_table_name = $wpdb->prefix . 'vibe_pricing_cache';
		$cache_sql = "CREATE TABLE $cache_table_name (
			cache_key varchar(255) NOT NULL,
			cache_value longtext,
			expiry_time datetime,
			PRIMARY KEY (cache_key),
			KEY idx_expiry (expiry_time)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		dbDelta($cache_sql);

		// Set initial options
		add_option('wc_vibe_dynamic_pricing_emergency_disable', 'no');
	}

	/**
	 * Clean up database tables and options.
	 */
	public static function cleanup_tables() {
		try {
			global $wpdb;

			// Drop tables with proper error handling
			$tables_to_drop = array(
				$wpdb->prefix . 'vibe_pricing_rules',
				$wpdb->prefix . 'vibe_pricing_cache'
			);
			
			foreach ($tables_to_drop as $table_name) {
				$result = $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
				if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[Vibe Plugin] Failed to drop table: ' . $table_name);
				}
			}

			// Remove options with error handling - old option names (pre-migration)
			$options_to_delete = array(
				'vibe_dynamic_pricing_version',
				'vibe_dynamic_pricing_emergency_disable',
				'vibe_dynamic_pricing_enabled',
				'vibe_dynamic_pricing_apply_mode',
				'vibe_dynamic_pricing_settings',
				'vibe_price_display_settings'
			);
			
			// Also remove new standardized option names
			$new_options_to_delete = array(
				'wc_vibe_dynamic_pricing_emergency_disable',
				'wc_vibe_dynamic_pricing_enabled',
				'wc_vibe_dynamic_pricing_apply_mode',
				'wc_vibe_price_display_settings'
			);
			
			foreach ($options_to_delete as $option_name) {
				delete_option($option_name);
			}
			
			foreach ($new_options_to_delete as $option_name) {
				delete_option($option_name);
			}
			
			// Clean up any remaining transients
			$wpdb->query(
				"DELETE FROM {$wpdb->options} 
				 WHERE option_name LIKE '_transient_vibe_%' 
				 OR option_name LIKE '_transient_timeout_vibe_%'"
			);
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Vibe Plugin] Database cleanup completed successfully');
			}
			
		} catch (Exception $e) {
			// Log error but don't throw exception to prevent uninstall failure
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Vibe Plugin] Cleanup error: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Get pricing engine instance.
	 *
	 * @return WC_Vibe_Pricing_Engine
	 */
	public function get_pricing_engine() {
		return $this->pricing_engine;
	}

	/**
	 * Get cache manager instance.
	 *
	 * @return WC_Vibe_Cache_Manager
	 */
	public function get_cache_manager() {
		return $this->cache_manager;
	}

	/**
	 * Get price display instance.
	 *
	 * @return WC_Vibe_Price_Display
	 */
	public function get_price_display() {
		return $this->price_display;
	}

	/**
	 * Get payment integration instance.
	 *
	 * @return WC_Vibe_Payment_Integration
	 */
	public function get_payment_integration() {
		return $this->payment_integration;
	}

	/**
	 * Get admin interface instance.
	 *
	 * @return WC_Vibe_Admin_Interface
	 */
	public function get_admin_interface() {
		return $this->admin_interface;
	}

	/**
	 * Check if emergency disabled.
	 *
	 * @return bool
	 */
	public function is_emergency_disabled() {
		return $this->emergency_disabled;
	}

	/**
	 * Enable emergency disable mode.
	 */
	public function enable_emergency_disable() {
		update_option('wc_vibe_dynamic_pricing_emergency_disable', 'yes');
		$this->emergency_disabled = true;
	}

	/**
	 * Disable emergency disable mode.
	 */
	public function disable_emergency_disable() {
		update_option('wc_vibe_dynamic_pricing_emergency_disable', 'no');
		$this->emergency_disabled = false;
	}

	/**
	 * Get the actual original price from the product object.
	 *
	 * @param WC_Product $product Product object.
	 * @return float|false Original price or false if not available.
	 */
	private function get_product_original_price($product) {
		if (!$product || !is_a($product, 'WC_Product')) {
			return false;
		}

		// Temporarily remove our own filters to get the actual original price
		$removed_filters = array();
		
		// Store which filters we removed so we can restore them
		if (has_filter('woocommerce_product_get_price', array($this, 'modify_product_price'))) {
			remove_filter('woocommerce_product_get_price', array($this, 'modify_product_price'), 99);
			$removed_filters[] = 'woocommerce_product_get_price';
		}
		
		if (has_filter('woocommerce_product_variation_get_price', array($this, 'modify_product_price'))) {
			remove_filter('woocommerce_product_variation_get_price', array($this, 'modify_product_price'), 99);
			$removed_filters[] = 'woocommerce_product_variation_get_price';
		}

		// Get the original price without our modifications
		$original_price = false;
		
		// For sale price, use sale price if available, otherwise regular price
		if ($product->get_sale_price()) {
			$original_price = floatval($product->get_sale_price());
		} else {
			$original_price = floatval($product->get_regular_price());
		}

		// Restore the filters we removed
		foreach ($removed_filters as $filter_name) {
			if ($filter_name === 'woocommerce_product_get_price') {
				add_filter($filter_name, array($this, 'modify_product_price'), 99, 2);
			} elseif ($filter_name === 'woocommerce_product_variation_get_price') {
				add_filter($filter_name, array($this, 'modify_product_price'), 99, 2);
			}
		}

		return $original_price;
	}
} 