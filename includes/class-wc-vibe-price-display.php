<?php

/**
 * Vibe Price Display Class
 *
 * Handles frontend price display with configurable options for dynamic pricing.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.1.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Price Display for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Price_Display
 * @version  1.1.0
 */
class WC_Vibe_Price_Display
{

	/**
	 * Pricing engine instance.
	 *
	 * @var WC_Vibe_Pricing_Engine
	 */
	private $pricing_engine;

	/**
	 * Display settings.
	 *
	 * @var array
	 */
	private $display_settings;

	/**
	 * Constructor.
	 *
	 * @param WC_Vibe_Pricing_Engine $pricing_engine Pricing engine instance.
	 */
	public function __construct($pricing_engine)
	{
		$this->pricing_engine = $pricing_engine;
		$this->load_display_settings();
		$this->init_hooks();
	}

	/**
	 * Migrate deprecated settings.
	 *
	 * @param array $saved_settings Current saved settings.
	 * @return array Migrated settings.
	 */
	private function migrate_deprecated_settings($saved_settings)
	{
		$migrated = false;
		
		// Remove deprecated keys
		$deprecated_keys = array('conditional_display', 'strike_through_original');
		foreach ($deprecated_keys as $key) {
			if (isset($saved_settings[$key])) {
				unset($saved_settings[$key]);
				$migrated = true;
			}
		}
		
		// Migrate display_layout from 'inline' to 'two_line'
		if (isset($saved_settings['display_layout']) && $saved_settings['display_layout'] === 'inline') {
			$saved_settings['display_layout'] = 'two_line';
			$migrated = true;
		}
		
		// Save migrated settings if changes were made
		if ($migrated) {
			update_option('vibe_price_display_settings', $saved_settings);
			$this->log_debug('Settings migrated', array(
				'deprecated_keys_removed' => $deprecated_keys,
				'display_layout_updated' => isset($saved_settings['display_layout']) ? $saved_settings['display_layout'] : 'not_set'
			));
		}
		
		return $saved_settings;
	}

	/**
	 * Load display settings.
	 */
	private function load_display_settings()
	{
		$defaults = array(
			'show_both_prices' => true,
			'display_layout' => 'two_line',
			'price_order' => 'original_first', // new_first or original_first
			'new_price_font_size' => '100%',
			'original_price_font_size' => '85%',
			'new_price_prefix' => 'قیمت اقساطی ',
			'original_price_prefix' => 'قیمت نقدی ',
		);

		$saved_settings = get_option('vibe_price_display_settings', array());
		
		// Migrate deprecated settings
		$saved_settings = $this->migrate_deprecated_settings($saved_settings);
		
		$this->display_settings = wp_parse_args(
			$saved_settings,
			$defaults
		);

		// Debug log the loaded settings
		$this->log_debug('load_display_settings', array(
			'saved_settings' => $saved_settings,
			'final_settings' => $this->display_settings,
			'show_both_prices' => $this->display_settings['show_both_prices']
		));
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks()
	{
		// Frontend price display hooks
		add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), 99, 2);
		add_filter('woocommerce_cart_item_price', array($this, 'modify_cart_item_price_html'), 99, 3);

		// Enqueue styles for price display
		add_action('wp_enqueue_scripts', array($this, 'enqueue_price_display_styles'));

		// AJAX handler for price updates
		add_action('wp_ajax_update_dynamic_prices', array($this, 'ajax_update_prices'));
		add_action('wp_ajax_nopriv_update_dynamic_prices', array($this, 'ajax_update_prices'));

		// Add debugging capabilities
		add_action('wp_footer', array($this, 'output_debug_info'));
	}

	/**
	 * Modify price HTML on product pages and shop.
	 *
	 * @param string $price_html Original price HTML.
	 * @param WC_Product $product Product object.
	 * @return string Modified price HTML.
	 */
	public function modify_price_html($price_html, $product)
	{
		$this->log_debug('modify_price_html called', array(
			'product_id' => $product ? $product->get_id() : 'no_product',
			'is_admin' => is_admin(),
			'is_ajax' => wp_doing_ajax(),
			'current_payment_method' => $this->pricing_engine->get_current_payment_method(),
			'current_referrer' => $this->pricing_engine->get_current_referrer()
		));

		// Skip if not a valid product
		if (!$product || !is_a($product, 'WC_Product')) {
			$this->log_debug('modify_price_html: Invalid product, returning original');
			return $price_html;
		}

		// Skip in admin
		if (is_admin() && !wp_doing_ajax()) {
			$this->log_debug('modify_price_html: In admin, returning original');
			return $price_html;
		}

		// Get original price
		$original_price = $product->get_price();

		// Get dynamic price for DISPLAY purposes
		$dynamic_price = $this->pricing_engine->get_dynamic_price($product, $original_price, 'display');

		$this->log_debug('modify_price_html: Price calculation', array(
			'product_id' => $product->get_id(),
			'original_price' => $original_price,
			'dynamic_price' => $dynamic_price,
			'context' => 'display'
		));

		// If no dynamic pricing applies, return original
		if (false === $dynamic_price || $dynamic_price == $original_price) {
			$this->log_debug('modify_price_html: No dynamic pricing, returning original');
			return $price_html;
		}

		$this->log_debug('modify_price_html: Generating dynamic price HTML');
		// Generate dynamic price HTML
		return $this->generate_dynamic_price_html($original_price, $dynamic_price, $product);
	}

	/**
	 * Modify cart item price HTML.
	 *
	 * @param string $price_html Original price HTML.
	 * @param array $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Modified price HTML.
	 */
	public function modify_cart_item_price_html($price_html, $cart_item, $cart_item_key)
	{
		if (!isset($cart_item['data'])) {
			return $price_html;
		}

		$product = $cart_item['data'];

		// Get original price
		$original_price = $product->get_price();

		// Get dynamic price for APPLICATION purposes (cart/checkout)
		$dynamic_price = $this->pricing_engine->get_dynamic_price($product, $original_price, 'application');

		$this->log_debug('modify_cart_item_price_html: Price calculation', array(
			'product_id' => $product->get_id(),
			'original_price' => $original_price,
			'dynamic_price' => $dynamic_price,
			'context' => 'application'
		));

		// If no dynamic pricing applies, return original
		if (false === $dynamic_price || $dynamic_price == $original_price) {
			return $price_html;
		}

		// Generate dynamic price HTML for cart
		return $this->generate_dynamic_price_html($original_price, $dynamic_price, $product, 'cart');
	}

	/**
	 * Generate dynamic price HTML.
	 *
	 * @param float $original_price Original price.
	 * @param float $dynamic_price Dynamic price.
	 * @param WC_Product $product Product object.
	 * @param string $context Display context (shop, single, cart).
	 * @return string Generated price HTML.
	 */
	private function generate_dynamic_price_html($original_price, $dynamic_price, $product, $context = 'shop')
	{
		// Check if WooCommerce functions are available
		if (!function_exists('wc_price')) {
			$this->log_debug('generate_dynamic_price_html: WooCommerce functions not available');
			// Fallback when WC functions not available
			return '<span class="price">' . get_woocommerce_currency_symbol() . number_format($original_price) . '</span>';
		}

		// Format prices
		$formatted_original = wc_price($original_price);
		$formatted_dynamic = wc_price($dynamic_price);

		$this->log_debug('generate_dynamic_price_html', array(
			'product_id' => $product->get_id(),
			'original_price' => $original_price,
			'dynamic_price' => $dynamic_price,
			'context' => $context,
			'show_both_prices' => $this->display_settings['show_both_prices'],
			'display_settings' => $this->display_settings
		));

		// Apply prefixes and suffixes
		$new_price_display = $this->display_settings['new_price_prefix'] . $formatted_dynamic;

		$original_price_display = $this->display_settings['original_price_prefix'] . $formatted_original;

		// Apply styling
		$new_price_style = $this->get_price_style('new');
		$original_price_style = $this->get_price_style('original');

		// Build HTML based on settings
		$html_parts = array();

		// Determine price order and layout
		if ($this->display_settings['show_both_prices']) {
			$this->log_debug('generate_dynamic_price_html: Building both prices HTML', array(
				'new_price_display' => $new_price_display,
				'original_price_display' => $original_price_display
			));

			$prices_html = $this->build_both_prices_html(
				$new_price_display,
				$original_price_display,
				$new_price_style,
				$original_price_style
			);
		} else {
			$this->log_debug('generate_dynamic_price_html: Building single price HTML');

			$prices_html = sprintf(
				'<span class="vibe-dynamic-price-new" style="%s">%s</span>',
				$new_price_style,
				$new_price_display
			);
		}

		$html_parts[] = $prices_html;

		// Wrap everything in container
		$container_class = 'vibe-dynamic-price-container vibe-context-' . $context;
		$container_html = sprintf(
			'<span class="%s" data-product-id="%d" data-original-price="%s" data-dynamic-price="%s">%s</span>',
			$container_class,
			$product->get_id(),
			$original_price,
			$dynamic_price,
			implode(' ', $html_parts)
		);

		$this->log_debug('generate_dynamic_price_html: Final HTML', array(
			'container_html' => $container_html
		));

		return $container_html;
	}

	/**
	 * Build HTML for both prices.
	 *
	 * @param string $new_price_display Formatted new price.
	 * @param string $original_price_display Formatted original price.
	 * @param string $new_price_style New price styles.
	 * @param string $original_price_style Original price styles.
	 * @return string Built HTML.
	 */
	private function build_both_prices_html($new_price_display, $original_price_display, $new_price_style, $original_price_style)
	{
		$this->log_debug('build_both_prices_html called', array(
			'new_price_display' => $new_price_display,
			'original_price_display' => $original_price_display,
			'price_order' => $this->display_settings['price_order'],
			'display_layout' => $this->display_settings['display_layout']
		));

		$new_price_html = sprintf(
			'<span class="vibe-dynamic-price-new" style="%s">%s</span>',
			$new_price_style,
			$new_price_display
		);

		$original_price_html = sprintf(
			'<span class="vibe-dynamic-price-original" style="%s">%s</span>',
			$original_price_style,
			$original_price_display
		);

		// Determine order
		$first_price = $this->display_settings['price_order'] === 'new_first' ? $new_price_html : $original_price_html;
		$second_price = $this->display_settings['price_order'] === 'new_first' ? $original_price_html : $new_price_html;

		// Determine layout
		if ($this->display_settings['display_layout'] === 'two_line') {
			$both_prices_html = $first_price . '<br>' . $second_price;
		} else {
			$both_prices_html = $first_price . ' ' . $second_price;
		}

		$this->log_debug('build_both_prices_html result', array(
			'first_price' => $first_price,
			'second_price' => $second_price,
			'final_html' => $both_prices_html
		));

		return $both_prices_html;
	}

	/**
	 * Get price style for new or original price.
	 *
	 * @param string $type Price type ('new' or 'original').
	 * @return string CSS styles.
	 */
	private function get_price_style($type)
	{
		$styles = array();

		if ($type === 'new') {
			$styles[] = sprintf('font-size: %s', $this->display_settings['new_price_font_size']);
			$styles[] = 'font-weight: bold';
		} else {
			$styles[] = sprintf('font-size: %s', $this->display_settings['original_price_font_size']);

			// Strike-through is no longer supported - always remove any inherited strikethrough
			$styles[] = 'text-decoration: none';

			$styles[] = 'color: #999';
		}

		$final_styles = implode('; ', $styles);

		$this->log_debug('get_price_style result', array(
			'type' => $type,
			'final_styles' => $final_styles
		));

		return $final_styles;
	}

	/**
	 * Check if dynamic price should be shown based on current context.
	 *
	 * @return bool True if dynamic price should be shown.
	 */
	private function should_show_dynamic_price()
	{
		$current_payment_method = $this->pricing_engine->get_current_payment_method();
		$current_referrer = $this->pricing_engine->get_current_referrer();

		$this->log_debug('should_show_dynamic_price called', array(
			'is_product' => function_exists('is_product') ? is_product() : 'function_not_available',
			'is_cart' => function_exists('is_cart') ? is_cart() : 'function_not_available',
			'is_checkout' => function_exists('is_checkout') ? is_checkout() : 'function_not_available',
			'current_payment_method' => $current_payment_method,
			'current_referrer' => $current_referrer
		));

		// On product pages: Show ONLY if user is from vibe.ir (regardless of payment method)
		if (function_exists('is_product') && is_product()) {
			$from_vibe = $current_referrer && strpos($current_referrer, 'vibe.ir') !== false;
			$result = $from_vibe;

			$this->log_debug('should_show_dynamic_price: On product page', array(
				'from_vibe' => $from_vibe,
				'current_payment_method' => $current_payment_method,
				'logic' => 'show only for vibe.ir referrers',
				'showing_teaser' => $result
			));

			return $result;
		}

		// In cart/checkout: Show dynamic price ONLY when Vibe payment is selected
		if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout())) {
			$result = ('vibe' === $current_payment_method);
			$this->log_debug('should_show_dynamic_price: On cart/checkout', array(
				'payment_method' => $current_payment_method,
				'logic' => 'show only when vibe payment selected',
				'showing_dynamic_price' => $result
			));
			return $result;
		}

		// Elsewhere (shop loops etc.): Show ONLY if user is from vibe.ir (same as product pages)
		$from_vibe = $current_referrer && strpos($current_referrer, 'vibe.ir') !== false;
		$result = $from_vibe;

		$this->log_debug('should_show_dynamic_price: Elsewhere', array(
			'payment_method' => $current_payment_method,
			'from_vibe' => $from_vibe,
			'logic' => 'show only for vibe.ir referrers',
			'showing_dynamic_price' => $result
		));

		return $result;
	}

	/**
	 * Render cart price (called from main dynamic pricing class).
	 *
	 * @param string $price_html Original price HTML.
	 * @param array $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Modified price HTML.
	 */
	public function render_cart_price($price_html, $cart_item, $cart_item_key)
	{
		return $this->modify_cart_item_price_html($price_html, $cart_item, $cart_item_key);
	}

	/**
	 * Enqueue price display styles.
	 */
	public function enqueue_price_display_styles()
	{
		// Enqueue custom CSS for price display
		wp_add_inline_style('woocommerce-general', $this->get_inline_css());
	}

	/**
	 * Get inline CSS for price display.
	 *
	 * @return string CSS styles.
	 */
	private function get_inline_css()
	{
		return "
			.vibe-dynamic-price-container {
				display: inline-block;
			}
			
			.vibe-dynamic-price-container .vibe-dynamic-price-new {
				font-weight: bold;
				color: #2ea44f;
			}
			
			.vibe-dynamic-price-container .vibe-dynamic-price-original {
				margin-left: 8px;
				color: #999;
			}
			
			/* Ensure strikethrough can be properly controlled */
			.vibe-dynamic-price-container .vibe-dynamic-price-original {
				text-decoration: inherit !important;
			}
			
			.vibe-pricing-badge {
				display: inline-block;
				vertical-align: top;
			}
			
			.vibe-context-cart .vibe-pricing-badge {
				display: none;
			}
			
			@media (max-width: 768px) {
				.vibe-dynamic-price-container {
					font-size: 0.9em;
				}
				
				.vibe-pricing-badge {
					font-size: 0.7em;
					padding: 1px 4px;
				}
			}
		";
	}

	/**
	 * AJAX handler for updating prices dynamically.
	 */
	public function ajax_update_prices()
	{
		$this->log_debug('ajax_update_prices called', $_POST);

		// Verify nonce
		if (!wp_verify_nonce($_POST['nonce'], 'vibe_dynamic_pricing_nonce')) {
			$this->log_debug('ajax_update_prices: Nonce verification failed');
			wp_die('Security check failed');
		}

		$payment_method = sanitize_text_field($_POST['payment_method']);
		$product_ids = array_map('intval', $_POST['product_ids']);

		$this->log_debug('ajax_update_prices: Processing', array(
			'payment_method' => $payment_method,
			'product_ids' => $product_ids
		));

		// Update payment method in pricing engine
		$this->pricing_engine->set_current_payment_method($payment_method);

		$updated_prices = array();

		foreach ($product_ids as $product_id) {
			$product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
			if ($product) {
				$original_price = $product->get_price();
				$dynamic_price = $this->pricing_engine->get_dynamic_price($product, $original_price);

				$updated_prices[$product_id] = array(
					'original' => $original_price,
					'dynamic' => $dynamic_price,
					'html' => $dynamic_price !== false ?
						$this->generate_dynamic_price_html($original_price, $dynamic_price, $product, 'ajax') : (function_exists('wc_price') ? wc_price($original_price) : '$' . $original_price)
				);
			}
		}

		$this->log_debug('ajax_update_prices: Sending response', $updated_prices);
		wp_send_json_success($updated_prices);
	}

	/**
	 * Get display settings.
	 *
	 * @return array Display settings.
	 */
	public function get_display_settings()
	{
		return $this->display_settings;
	}

	/**
	 * Update display settings.
	 *
	 * @param array $settings New settings.
	 */
	public function update_display_settings($settings)
	{
		$this->display_settings = wp_parse_args($settings, $this->display_settings);
		update_option('vibe_price_display_settings', $this->display_settings);
	}

	/**
	 * Reset display settings to defaults.
	 */
	public function reset_display_settings()
	{
		delete_option('vibe_price_display_settings');
		$this->load_display_settings();
	}

	/**
	 * Log debug information if debugging is enabled.
	 *
	 * @param string $message Debug message.
	 * @param array $data Additional data to log.
	 */
	private function log_debug($message, $data = array())
	{
		if (!$this->is_debug_enabled()) {
			return;
		}

		$log_entry = array(
			'timestamp' => current_time('mysql'),
			'class' => 'WC_Vibe_Price_Display',
			'message' => $message,
			'data' => $data,
			'backtrace' => wp_debug_backtrace_summary()
		);

		error_log('VIBE_DEBUG: ' . json_encode($log_entry));

		// Also store in option for admin review
		$debug_logs = get_option('vibe_debug_logs', array());
		$debug_logs[] = $log_entry;

		// Keep only last 100 entries
		if (count($debug_logs) > 100) {
			$debug_logs = array_slice($debug_logs, -100);
		}

		update_option('vibe_debug_logs', $debug_logs);
	}

	/**
	 * Check if debugging is enabled.
	 *
	 * @return bool True if debugging is enabled.
	 */
	private function is_debug_enabled()
	{
		return defined('WP_DEBUG') && WP_DEBUG && get_option('vibe_enable_debug_logging', false);
	}

	/**
	 * Output debug information in footer for admin users.
	 */
	public function output_debug_info()
	{
		if (!current_user_can('manage_options') || !$this->is_debug_enabled()) {
			return;
		}

		$debug_info = array(
			'current_payment_method' => $this->pricing_engine->get_current_payment_method(),
			'current_referrer' => $this->pricing_engine->get_current_referrer(),
			'display_settings' => $this->display_settings,
			'is_product' => function_exists('is_product') ? is_product() : 'N/A',
			'is_cart' => function_exists('is_cart') ? is_cart() : 'N/A',
			'is_checkout' => function_exists('is_checkout') ? is_checkout() : 'N/A',
			'wc_functions_available' => array(
				'wc_price' => function_exists('wc_price'),
				'wc_get_product' => function_exists('wc_get_product'),
				'is_product' => function_exists('is_product'),
				'is_cart' => function_exists('is_cart'),
				'is_checkout' => function_exists('is_checkout')
			)
		);

		echo "<!-- VIBE DEBUG INFO -->\n";
		echo "<script>console.log('Vibe Price Display Debug:', " . json_encode($debug_info) . ");</script>\n";
		echo "<!-- END VIBE DEBUG INFO -->\n";
	}
}
