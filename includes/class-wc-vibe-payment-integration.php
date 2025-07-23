<?php

/**
 * Vibe Payment Integration Class
 *
 * Handles integration between dynamic pricing and payment method selection,
 * specifically for Vibe payment gateway.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.1.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Payment Integration for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Payment_Integration
 * @version  1.1.0
 */
class WC_Vibe_Payment_Integration {

	/**
	 * Pricing engine instance.
	 *
	 * @var WC_Vibe_Pricing_Engine
	 */
	private $pricing_engine;

	/**
	 * Constructor.
	 *
	 * @param WC_Vibe_Pricing_Engine $pricing_engine Pricing engine instance.
	 */
	public function __construct($pricing_engine) {
		$this->pricing_engine = $pricing_engine;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Checkout and cart hooks
		add_action('woocommerce_review_order_before_payment', array($this, 'add_payment_method_handler'));
		add_action('woocommerce_checkout_update_order_review', array($this, 'handle_checkout_update'));
		
		// AJAX hooks for dynamic price updates
		add_action('wp_ajax_vibe_update_payment_prices', array($this, 'ajax_update_payment_prices'));
		add_action('wp_ajax_nopriv_vibe_update_payment_prices', array($this, 'ajax_update_payment_prices'));
		
		// Enqueue frontend scripts
		add_action('wp_enqueue_scripts', array($this, 'enqueue_payment_scripts'));
		
		// Filter cart totals when Vibe payment is selected
		add_action('woocommerce_cart_calculate_fees', array($this, 'apply_dynamic_pricing_to_cart'));
		
		// Session management
		add_action('woocommerce_checkout_init', array($this, 'init_checkout_session'));

		// WooCommerce Blocks: Register update callback for cart recalc when payment method changes
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_dynamic_pricing_callback' ) );

		// Enqueue helper script for block-based checkout dynamic pricing
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_dynamic_pricing_script' ) );
	}

	/**
	 * Add payment method change handler to checkout.
	 */
	public function add_payment_method_handler() {
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Handle payment method changes
			$('body').on('change', 'input[name="payment_method"]', function() {
				var selectedPaymentMethod = $(this).val();
				updateDynamicPrices(selectedPaymentMethod);
			});
			
			function updateDynamicPrices(paymentMethod) {
				// Collect all product IDs on the page
				var productIds = [];
				$('.vibe-dynamic-price-container').each(function() {
					var productId = $(this).data('product-id');
					if (productId && productIds.indexOf(productId) === -1) {
						productIds.push(productId);
					}
				});
				
				if (productIds.length === 0) return;
				
				// Make AJAX request to update prices
				$.ajax({
					url: vibe_payment_integration.ajax_url,
					type: 'POST',
					data: {
						action: 'vibe_update_payment_prices',
						nonce: vibe_payment_integration.nonce,
						payment_method: paymentMethod,
						product_ids: productIds
					},
					success: function(response) {
						if (response.success) {
							updatePriceDisplays(response.data);
							// Trigger checkout update to recalculate totals
							$('body').trigger('update_checkout');
						}
					},
					error: function() {
						console.log('Error updating dynamic prices');
					}
				});
			}
			
			function updatePriceDisplays(priceData) {
				$.each(priceData, function(productId, data) {
					var container = $('.vibe-dynamic-price-container[data-product-id="' + productId + '"]');
					if (container.length && data.html) {
						container.replaceWith(data.html);
					}
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * Handle checkout update.
	 *
	 * @param string $posted_data Posted checkout data.
	 */
	public function handle_checkout_update($posted_data) {
		// Parse posted data to get payment method
		parse_str($posted_data, $checkout_data);
		
		if (isset($checkout_data['payment_method'])) {
			$payment_method = sanitize_text_field($checkout_data['payment_method']);
			$this->pricing_engine->set_current_payment_method($payment_method);
			
			// Store in session for persistence
			if (function_exists('WC') && WC()->session) {
				WC()->session->set('chosen_payment_method', $payment_method);
			}
		}
	}

	/**
	 * Handle payment method changes via AJAX.
	 */
	public function handle_payment_method_change() {
		// This method is called from the main dynamic pricing class
		// We'll store the new payment method and clear relevant caches
		
		if (!empty($_POST['payment_method'])) {
			$payment_method = sanitize_text_field($_POST['payment_method']);
			$this->pricing_engine->set_current_payment_method($payment_method);
			
			// Store in session
			if (function_exists('WC') && WC()->session) {
				WC()->session->set('chosen_payment_method', $payment_method);
			}
		}
	}

	/**
	 * AJAX handler for updating prices based on payment method.
	 */
	public function ajax_update_payment_prices() {
		// Verify nonce
		if (!wp_verify_nonce($_POST['nonce'], 'vibe_dynamic_pricing_nonce')) {
			wp_die('Security check failed');
		}

		$payment_method = sanitize_text_field($_POST['payment_method']);
		$product_ids = array_map('intval', $_POST['product_ids']);

		// Update payment method in pricing engine
		$this->pricing_engine->set_current_payment_method($payment_method);

		// Store in session
		if (function_exists('WC') && WC()->session) {
			WC()->session->set('chosen_payment_method', $payment_method);
		}

		$updated_prices = array();

		foreach ($product_ids as $product_id) {
			if (function_exists('wc_get_product')) {
				$product = wc_get_product($product_id);
				if ($product) {
					$original_price = $product->get_price();
					$dynamic_price = $this->pricing_engine->get_dynamic_price($product, $original_price);
					
					$updated_prices[$product_id] = array(
						'original' => $original_price,
						'dynamic' => $dynamic_price,
						'should_show' => $this->should_show_dynamic_price_for_payment($payment_method),
						'html' => $this->generate_price_html_for_payment($original_price, $dynamic_price, $product, $payment_method)
					);
				}
			}
		}

		wp_send_json_success($updated_prices);
	}

	/**
	 * Check if dynamic price should be shown for the given payment method.
	 *
	 * @param string $payment_method Payment method.
	 * @return bool True if dynamic price should be shown.
	 */
	private function should_show_dynamic_price_for_payment($payment_method) {
		// Only show dynamic pricing details when Vibe payment gateway is active.
		return ( 'vibe' === $payment_method );
	}

	/**
	 * Generate price HTML for payment method context.
	 *
	 * @param float $original_price Original price.
	 * @param float $dynamic_price Dynamic price.
	 * @param WC_Product $product Product object.
	 * @param string $payment_method Payment method.
	 * @return string Generated HTML.
	 */
	private function generate_price_html_for_payment($original_price, $dynamic_price, $product, $payment_method) {
		// If dynamic pricing shouldn't be shown or no dynamic price, return original
		if (!$this->should_show_dynamic_price_for_payment($payment_method) || 
			false === $dynamic_price || 
			$dynamic_price == $original_price) {
			
			if (function_exists('wc_price')) {
				return wc_price($original_price);
			}
			return $original_price;
		}

		// Get price display instance to generate dynamic price HTML
		$dynamic_pricing = WC_Vibe_Dynamic_Pricing::get_instance();
		$price_display = $dynamic_pricing->get_price_display();
		
		return $price_display->generate_dynamic_price_html($original_price, $dynamic_price, $product, 'checkout');
	}

	/**
	 * Enqueue payment integration scripts.
	 */
	public function enqueue_payment_scripts() {
		// Only enqueue on checkout and cart pages
		if (!function_exists('is_checkout') || (!is_checkout() && !is_cart())) {
			return;
		}

		wp_enqueue_script(
			'vibe-payment-integration',
			WC_VIBE_PLUGIN_URL . 'assets/js/payment-integration.js',
			array('jquery'),
			WC_VIBE_VERSION,
			true
		);

		// Localize script
		wp_localize_script('vibe-payment-integration', 'vibe_payment_integration', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('vibe_dynamic_pricing_nonce'),
			'debug' => defined('WP_DEBUG') && WP_DEBUG,
		));
	}

	/**
	 * Apply dynamic pricing to cart when calculating totals.
	 */
	public function apply_dynamic_pricing_to_cart() {
		// Skip if not WooCommerce context
		if (!function_exists('WC') || !WC()->cart) {
			return;
		}

		$current_payment_method = $this->pricing_engine->get_current_payment_method();
		
		// Only apply if Vibe payment is selected for Vibe referrers
		if (!$this->should_apply_dynamic_pricing_to_cart($current_payment_method)) {
			return;
		}

		// Calculate cart adjustments
		$total_adjustment = 0;
		
		foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
			$product = $cart_item['data'];
			$quantity = $cart_item['quantity'];
			
			$original_price = $product->get_price();
			$dynamic_price = $this->pricing_engine->get_dynamic_price($product, $original_price);
			
			if (false !== $dynamic_price && $dynamic_price != $original_price) {
				$adjustment = ($dynamic_price - $original_price) * $quantity;
				$total_adjustment += $adjustment;
			}
		}

		// Add fee/discount to cart
		if ($total_adjustment != 0) {
			$fee_name = $total_adjustment > 0 ? 
				__('Dynamic Pricing Adjustment', 'woocommerce-gateway-vibe') : 
				__('Dynamic Pricing Discount', 'woocommerce-gateway-vibe');
			
			WC()->cart->add_fee($fee_name, $total_adjustment);
		}
	}

	/**
	 * Check if dynamic pricing should be applied to cart.
	 *
	 * @param string $payment_method Current payment method.
	 * @return bool True if should apply.
	 */
	private function should_apply_dynamic_pricing_to_cart($payment_method) {
		// Apply adjustments only when Vibe gateway is chosen.
		return ( 'vibe' === $payment_method );
	}

	/**
	 * Initialize checkout session.
	 */
	public function init_checkout_session() {
		// Ensure session is started and payment method is detected
		if (function_exists('WC') && WC()->session) {
			$chosen_payment_method = WC()->session->get('chosen_payment_method');
			if ($chosen_payment_method) {
				$this->pricing_engine->set_current_payment_method($chosen_payment_method);
			}
		}
	}

	/**
	 * Get Vibe payment gateway instance.
	 *
	 * @return WC_Gateway_Vibe|null Vibe gateway instance or null.
	 */
	private function get_vibe_gateway() {
		if (!function_exists('WC')) {
			return null;
		}

		$payment_gateways = WC()->payment_gateways()->payment_gateways();
		return isset($payment_gateways['vibe']) ? $payment_gateways['vibe'] : null;
	}

	/**
	 * Check if Vibe payment gateway is available.
	 *
	 * @return bool True if Vibe gateway is available.
	 */
	public function is_vibe_gateway_available() {
		$gateway = $this->get_vibe_gateway();
		return $gateway && $gateway->is_available();
	}

	/**
	 * Get available payment methods.
	 *
	 * @return array Available payment methods.
	 */
	public function get_available_payment_methods() {
		if (!function_exists('WC')) {
			return array();
		}

		$payment_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$methods = array();

		foreach ($payment_gateways as $gateway) {
			$methods[$gateway->id] = $gateway->get_title();
		}

		return $methods;
	}

	/**
	 * Force recalculation of cart totals.
	 */
	public function recalculate_cart_totals() {
		if (function_exists('WC') && WC()->cart) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Clear payment method related caches.
	 */
	public function clear_payment_caches() {
		// Clear pricing caches when payment method changes
		$cache_manager = $this->pricing_engine->get_cache_manager();
		if ($cache_manager) {
			$cache_manager->clear_pricing_cache();
		}
	}

	/**
	 * Log payment integration events.
	 *
	 * @param string $message Log message.
	 * @param string $level Log level.
	 */
	private function log($message, $level = 'info') {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log("[Vibe Payment Integration - {$level}] {$message}");
		}
	}

	/**
	 * Registers a Store API update callback so that changing the payment method
	 * in the block-based checkout triggers a fresh cart total calculation which
	 * in turn runs our pricing engine and fee logic.
	 */
	public function register_blocks_dynamic_pricing_callback() {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback( array(
				'namespace' => 'vibe-dynamic-pricing',
				'callback'  => array( $this, 'blocks_dynamic_pricing_recalculate_cart' ),
			) );
		}
	}

	/**
	 * Callback executed by WooCommerce Blocks when extensionCartUpdate is called
	 * from the frontend. We need to detect the current payment method and update
	 * the pricing engine before recalculating cart totals.
	 *
	 * @param \Automattic\WooCommerce\StoreApi\Route\Controllers\CartController|null $cart Cart controller instance (not used).
	 * @return void
	 */
	public function blocks_dynamic_pricing_recalculate_cart( $cart = null ) {
		// Try to detect the current payment method from various sources
		$payment_method = $this->detect_current_payment_method_for_blocks();
		
		// Debug logging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Vibe Dynamic Pricing] blocks_dynamic_pricing_recalculate_cart called. Detected payment method: ' . ($payment_method ?: 'null'));
		}
		
		// Update the pricing engine with the detected payment method
		if ( $payment_method ) {
			$this->pricing_engine->set_current_payment_method( $payment_method );
			
			// Store in session for persistence
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'chosen_payment_method', $payment_method );
			}
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Vibe Dynamic Pricing] Updated pricing engine with payment method: ' . $payment_method);
			}
		} else {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Vibe Dynamic Pricing] No payment method detected, keeping current context');
			}
		}
		
		$this->clear_payment_caches();
		$this->recalculate_cart_totals();
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$current_method = $this->pricing_engine->get_current_payment_method();
			error_log('[Vibe Dynamic Pricing] Cart recalculated. Final payment method context: ' . ($current_method ?: 'null'));
		}
	}

	/**
	 * Detect the current payment method in the WooCommerce Blocks/Store API context.
	 *
	 * @return string|null The detected payment method or null if not found.
	 */
	private function detect_current_payment_method_for_blocks() {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Vibe Dynamic Pricing] detect_current_payment_method_for_blocks() called');
		}
		
		// First, try to get from the Store API request data
		$request_body = file_get_contents( 'php://input' );
		if ( $request_body ) {
			$request_data = json_decode( $request_body, true );
			
			if (defined('WP_DEBUG') && WP_DEBUG && $request_data) {
				error_log('[Vibe Dynamic Pricing] Request data found: ' . json_encode($request_data));
			}
			
			// Check if this is a batch request with cart extension data
			if ( isset( $request_data['requests'] ) && is_array( $request_data['requests'] ) ) {
				foreach ( $request_data['requests'] as $request ) {
					// Check for our vibe-dynamic-pricing namespace data
					if ( isset( $request['data']['namespace'] ) && 
						 $request['data']['namespace'] === 'vibe-dynamic-pricing' &&
						 isset( $request['data']['data']['payment_method'] ) ) {
						$detected_method = sanitize_text_field( $request['data']['data']['payment_method'] );
						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('[Vibe Dynamic Pricing] Found payment method in vibe-dynamic-pricing namespace: ' . $detected_method);
						}
						return $detected_method;
					}
					
					// Also check for direct payment method in request data
					if ( isset( $request['data']['payment_method'] ) ) {
						$detected_method = sanitize_text_field( $request['data']['payment_method'] );
						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('[Vibe Dynamic Pricing] Found payment method in direct request data: ' . $detected_method);
						}
						return $detected_method;
					}
				}
			}
			
			// Check direct payment method in request data
			if ( isset( $request_data['payment_method'] ) ) {
				$detected_method = sanitize_text_field( $request_data['payment_method'] );
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[Vibe Dynamic Pricing] Found payment method in direct request: ' . $detected_method);
				}
				return $detected_method;
			}
		}
		
		// Fallback to session data
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_payment_method = WC()->session->get( 'chosen_payment_method' );
			if ( $session_payment_method ) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[Vibe Dynamic Pricing] Found payment method in session: ' . $session_payment_method);
				}
				return $session_payment_method;
			}
		}
		
		// Fallback to POST data
		if ( ! empty( $_POST['payment_method'] ) ) {
			$detected_method = sanitize_text_field( $_POST['payment_method'] );
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[Vibe Dynamic Pricing] Found payment method in POST: ' . $detected_method);
			}
			return $detected_method;
		}
		
		// Last resort: try to get from the current checkout data
		if ( function_exists( 'WC' ) && WC()->checkout() ) {
			$posted_data = WC()->checkout()->get_posted_data();
			if ( ! empty( $posted_data['payment_method'] ) ) {
				$detected_method = sanitize_text_field( $posted_data['payment_method'] );
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[Vibe Dynamic Pricing] Found payment method in checkout data: ' . $detected_method);
				}
				return $detected_method;
			}
		}
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[Vibe Dynamic Pricing] No payment method found in any source');
		}
		
		return null;
	}

	/**
	 * Enqueue lightweight helper that observes payment method changes in the
	 * block checkout and calls extensionCartUpdate (namespace: vibe-dynamic-pricing).
	 */
	public function enqueue_blocks_dynamic_pricing_script() {
		// Only enqueue on the checkout page when WooCommerce Blocks assets are present.
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// Ensure the wc global with blocks exists to avoid unnecessary enqueue.
		wp_enqueue_script(
			'vibe-blocks-dynamic-pricing',
			WC_VIBE_PLUGIN_URL . 'assets/js/frontend/block-dynamic-pricing.js',
			array( 'wp-data', 'wc-blocks-checkout' ),
			WC_VIBE_VERSION,
			true
		);
	}
} 