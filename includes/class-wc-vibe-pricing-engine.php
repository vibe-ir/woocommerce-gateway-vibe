<?php

/**
 * Vibe Pricing Engine Class
 *
 * Core engine for dynamic pricing calculations based on referrer detection
 * and configurable pricing rules.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.1.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Pricing Engine for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Pricing_Engine
 * @version  1.1.0
 */
class WC_Vibe_Pricing_Engine {

	/**
	 * Cache manager instance.
	 *
	 * @var WC_Vibe_Cache_Manager
	 */
	private $cache_manager;

	/**
	 * Current referrer domain.
	 *
	 * @var string
	 */
	private $current_referrer;

	/**
	 * Current payment method.
	 *
	 * @var string
	 */
	private $current_payment_method;

	/**
	 * Compiled pricing rules.
	 *
	 * @var array
	 */
	private $compiled_rules = null;

	/**
	 * Rule evaluation context.
	 *
	 * @var array
	 */
	private $evaluation_context = array();

	/**
	 * Constructor.
	 *
	 * @param WC_Vibe_Cache_Manager $cache_manager Cache manager instance.
	 */
	public function __construct($cache_manager) {
		$this->cache_manager = $cache_manager;
		$this->init();
	}

	/**
	 * Initialize the pricing engine.
	 */
	private function init() {
		// Detect current referrer
		$this->detect_referrer();
		
		// Detect current payment method
		$this->detect_payment_method();
		
		// Set up evaluation context
		$this->setup_evaluation_context();
		
		// Add debugging hook
		add_action('wp_footer', array($this, 'output_debug_info'));
	}

	/**
	 * Get dynamic price for a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param float $original_price Original product price.
	 * @param string $context_type Context type: 'display' or 'application'.
	 * @return float|false Dynamic price or false if no rule applies.
	 */
	public function get_dynamic_price($product, $original_price, $context_type = 'application') {
		// Ensure original_price is a valid number
		$original_price = floatval($original_price);
		
		$this->log_debug('get_dynamic_price called', array(
			'product_id' => $product ? $product->get_id() : 'no_product',
			'original_price' => $original_price,
			'context_type' => $context_type,
			'current_payment_method' => $this->current_payment_method,
			'current_referrer' => $this->current_referrer
		));

		// Skip if product is not valid
		if (!$product || !is_a($product, 'WC_Product')) {
			$this->log_debug('get_dynamic_price: Invalid product');
			return false;
		}
		
		// Skip if original price is not valid
		if ($original_price <= 0) {
			$this->log_debug('get_dynamic_price: Invalid original price', array(
				'original_price' => $original_price,
				'product_id' => $product->get_id()
			));
			return false;
		}

		$product_id = $product->get_id();
		
		// Generate cache context
		$cache_context = $this->generate_cache_context($context_type);
		
		// Try to get cached price
		$cached_price = $this->cache_manager->get_dynamic_price($product_id, $cache_context);
		if (false !== $cached_price) {
			$this->log_debug('get_dynamic_price: Using cached price', array(
				'product_id' => $product_id,
				'cached_price' => $cached_price
			));
			return $cached_price;
		}

		// Get applicable rules for this product
		$applicable_rules = $this->get_applicable_rules($product, $context_type);
		
		$this->log_debug('get_dynamic_price: Applicable rules', array(
			'product_id' => $product_id,
			'rule_count' => count($applicable_rules),
			'rules' => array_map(function($rule) { return $rule['name']; }, $applicable_rules)
		));

		if (empty($applicable_rules)) {
			// Cache the original price to avoid repeated calculations
			$this->cache_manager->set_dynamic_price($product_id, $cache_context, $original_price, 1800); // 30 minutes
			$this->log_debug('get_dynamic_price: No applicable rules');
			return false;
		}

		// Get the highest priority rule (first in sorted array)
		$winning_rule = reset($applicable_rules);
		
		$this->log_debug('get_dynamic_price: Using winning rule', array(
			'rule_name' => $winning_rule['name'],
			'rule_priority' => $winning_rule['priority']
		));

		// Calculate dynamic price
		$dynamic_price = $this->calculate_dynamic_price($original_price, $winning_rule, $product);
		
		$this->log_debug('get_dynamic_price: Calculated price', array(
			'product_id' => $product_id,
			'original_price' => $original_price,
			'dynamic_price' => $dynamic_price
		));

		// Cache the result
		$this->cache_manager->set_dynamic_price($product_id, $cache_context, $dynamic_price, 1800);
		
		return $dynamic_price;
	}

	/**
	 * Get dynamic price or false if no dynamic rule applies (price unchanged).
	 *
	 * @param WC_Product $product Product object.
	 * @param float $original_price Original product price.
	 * @param string $context_type Context type: 'display' or 'application'.
	 * @return float|false Dynamic price if rule applies, false if no rule applies or price unchanged.
	 */
	public function get_dynamic_price_or_false($product, $original_price, $context_type = 'application') {
		$dynamic_price = $this->get_dynamic_price($product, $original_price, $context_type);
		if ($dynamic_price === false) {
			return false;
		}
		// If the dynamic price is the same as the original, treat as no rule applied
		if (floatval($dynamic_price) == floatval($original_price)) {
			return false;
		}
		return $dynamic_price;
	}

	/**
	 * Get applicable rules for a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param string $context_type Context type: 'display' or 'application'.
	 * @return array Applicable rules sorted by priority.
	 */
	private function get_applicable_rules($product, $context_type = 'application') {
		$product_id = $product->get_id();
		
		// Try to get cached product rules
		$cached_rules = $this->cache_manager->get_product_rules($product_id);
		if (false !== $cached_rules) {
			return $this->filter_rules_by_context($cached_rules, $context_type);
		}

		// Get all active rules
		$all_rules = $this->get_compiled_rules();
		$applicable_rules = array();

		foreach ($all_rules as $rule) {
			if ($this->is_rule_applicable_to_product($rule, $product)) {
				$applicable_rules[] = $rule;
			}
		}

		// Sort by priority (highest first)
		usort($applicable_rules, function($a, $b) {
			return $b['priority'] - $a['priority'];
		});

		// Cache product rules
		$this->cache_manager->set_product_rules($product_id, $applicable_rules, 3600); // 1 hour

		// Filter by current context (referrer, payment method)
		return $this->filter_rules_by_context($applicable_rules, $context_type);
	}

	/**
	 * Filter rules by current context (referrer, payment method, etc.).
	 *
	 * @param array $rules Pricing rules.
	 * @param string $context_type Context type: 'display' or 'application'.
	 * @return array Filtered rules.
	 */
	private function filter_rules_by_context($rules, $context_type = 'application') {
		$apply_mode = get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined');
		$filtered_rules = array();

		$this->log_debug('filter_rules_by_context', array(
			'apply_mode' => $apply_mode,
			'context_type' => $context_type,
			'total_rules' => count($rules),
			'current_payment_method' => $this->current_payment_method,
			'current_referrer' => $this->current_referrer
		));

		foreach ($rules as $rule) {
			if ($this->does_rule_match_context($rule, $apply_mode, $context_type)) {
				$filtered_rules[] = $rule;
			}
		}

		$this->log_debug('filter_rules_by_context result', array(
			'filtered_rules_count' => count($filtered_rules),
			'rules' => array_map(function($rule) { return $rule['name']; }, $filtered_rules)
		));

		return $filtered_rules;
	}

	/**
	 * Check if rule matches current context.
	 *
	 * @param array $rule Pricing rule.
	 * @param string $apply_mode Apply mode setting.
	 * @param string $context_type Context type: 'display' or 'application'.
	 * @return bool True if rule matches context.
	 */
	private function does_rule_match_context($rule, $apply_mode = null, $context_type = 'application') {
		if (null === $apply_mode) {
			$apply_mode = get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined');
		}

		$this->log_debug('does_rule_match_context', array(
			'rule_name' => isset($rule['name']) ? $rule['name'] : 'unknown',
			'apply_mode' => $apply_mode,
			'context_type' => $context_type,
			'current_payment_method' => $this->current_payment_method,
			'current_referrer' => $this->current_referrer
		));

		switch ($apply_mode) {
			case 'always':
				// Apply to all visitors regardless of referrer or payment method
				$this->log_debug('does_rule_match_context: always mode = true');
				return true;

			case 'combined':
				// Combined mode: Different logic for display vs application
				
				// Check if Vibe payment method is selected
				$vibe_payment_selected = ($this->current_payment_method === 'vibe');
				
				// Check if user came from vibe.ir
				$vibe_referrer = $this->check_referrer_conditions(array(
					'domains' => array('vibe.ir', '*.vibe.ir'),
					'match_type' => 'ends_with'
				));

				if ($context_type === 'display') {
					// For display (product pages): Show ONLY if vibe referrer (regardless of payment method)
					$result = $vibe_referrer;
					$this->log_debug('does_rule_match_context: combined mode - DISPLAY', array(
						'vibe_payment_selected' => $vibe_payment_selected,
						'vibe_referrer' => $vibe_referrer,
						'logic' => 'show only for vibe.ir referrers',
						'result' => $result
					));
				} else {
					// For application (cart/checkout pricing): ONLY when vibe payment selected (regardless of referrer)
					$result = $vibe_payment_selected;
					$this->log_debug('does_rule_match_context: combined mode - APPLICATION', array(
						'vibe_payment_selected' => $vibe_payment_selected,
						'vibe_referrer' => $vibe_referrer,
						'logic' => 'apply only when vibe payment selected',
						'result' => $result
					));
				}
				
				return $result;

			case 'payment_method':
				// Payment method mode: Apply when Vibe payment method is selected
				if ($this->current_payment_method === 'vibe') {
					$this->log_debug('does_rule_match_context: payment_method mode, vibe selected = true');
					return true;
				}
				
				$this->log_debug('does_rule_match_context: payment_method mode, non-vibe payment = false');
				return false;

			case 'referrer':
				// Legacy mode: Check referrer conditions only
				if (empty($rule['referrer_conditions']['domains'])) {
					$this->log_debug('does_rule_match_context: referrer mode, no restrictions = true');
					return true; // No referrer restrictions
				}

				$referrer_result = $this->check_referrer_conditions($rule['referrer_conditions']);
				$this->log_debug('does_rule_match_context: referrer mode result', array(
					'referrer_result' => $referrer_result
				));
				return $referrer_result;
		}

		$this->log_debug('does_rule_match_context: unknown apply mode = false');
		return false;
	}

	/**
	 * Check referrer conditions.
	 *
	 * @param array $conditions Referrer conditions.
	 * @return bool True if conditions are met.
	 */
	private function check_referrer_conditions($conditions) {
		if (empty($this->current_referrer)) {
			return false;
		}

		$required_domains = isset($conditions['domains']) ? $conditions['domains'] : array();
		$match_type = isset($conditions['match_type']) ? $conditions['match_type'] : 'exact';

		foreach ($required_domains as $domain) {
			$domain = trim($domain);
			
			switch ($match_type) {
				case 'exact':
					if ($this->current_referrer === $domain) {
						return true;
					}
					break;
				
				case 'contains':
					if ($this->current_referrer && strpos($this->current_referrer, $domain) !== false) {
						return true;
					}
					break;
				
				case 'ends_with':
					if (substr($this->current_referrer, -strlen($domain)) === $domain) {
						return true;
					}
					break;
			}
		}

		return false;
	}

	/**
	 * Check if rule is applicable to product.
	 *
	 * @param array $rule Rule to check.
	 * @param WC_Product $product Product object.
	 * @return bool True if rule applies to product.
	 */
	private function is_rule_applicable_to_product($rule, $product) {
		$conditions = isset($rule['product_conditions']) ? $rule['product_conditions'] : array();
		
		// If no conditions, rule applies to all products
		if (empty($conditions)) {
			return true;
		}

		$target_type = isset($conditions['target_type']) ? $conditions['target_type'] : 'all';

		switch ($target_type) {
			case 'all':
				return true;
			
			case 'specific':
				return $this->check_specific_products($conditions, $product);
			
			case 'categories':
				return $this->check_category_conditions($conditions, $product);
			
			case 'tags':
				return $this->check_tag_conditions($conditions, $product);
			
			case 'complex':
				return $this->evaluate_complex_conditions($conditions, $product);
			
			case 'price_range':
				return $this->check_price_range_conditions($conditions, $product);
		}

		return false;
	}

	/**
	 * Check specific product conditions.
	 *
	 * @param array $conditions Product conditions.
	 * @param WC_Product $product Product object.
	 * @return bool True if product matches.
	 */
	private function check_specific_products($conditions, $product) {
		$product_ids = isset($conditions['product_ids']) ? $conditions['product_ids'] : array();
		return in_array($product->get_id(), $product_ids);
	}

	/**
	 * Check category conditions.
	 *
	 * @param array $conditions Category conditions.
	 * @param WC_Product $product Product object.
	 * @return bool True if product matches categories.
	 */
	private function check_category_conditions($conditions, $product) {
		$required_categories = isset($conditions['categories']) ? $conditions['categories'] : array();
		$logic = isset($conditions['category_logic']) ? $conditions['category_logic'] : 'OR';
		
		if (empty($required_categories)) {
			return true;
		}

		$product_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'ids'));
		$matches = array_intersect($required_categories, $product_categories);

		if ($logic === 'AND') {
			return count($matches) === count($required_categories);
		} else {
			return count($matches) > 0;
		}
	}

	/**
	 * Check tag conditions.
	 *
	 * @param array $conditions Tag conditions.
	 * @param WC_Product $product Product object.
	 * @return bool True if product matches tags.
	 */
	private function check_tag_conditions($conditions, $product) {
		$required_tags = isset($conditions['tags']) ? $conditions['tags'] : array();
		$logic = isset($conditions['tag_logic']) ? $conditions['tag_logic'] : 'OR';
		
		if (empty($required_tags)) {
			return true;
		}

		$product_tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'ids'));
		$matches = array_intersect($required_tags, $product_tags);

		if ($logic === 'AND') {
			return count($matches) === count($required_tags);
		} else {
			return count($matches) > 0;
		}
	}

	/**
	 * Evaluate complex conditions with boolean logic.
	 *
	 * @param array $conditions Complex conditions.
	 * @param WC_Product $product Product object.
	 * @return bool True if complex conditions are met.
	 */
	private function evaluate_complex_conditions($conditions, $product) {
		// Implementation for complex boolean logic like:
		// ((Category A AND Tag X) OR (Category B AND Tag Y)) AND Price > $50
		
		$expression = isset($conditions['expression']) ? $conditions['expression'] : array();
		
		if (empty($expression)) {
			return true;
		}

		return $this->evaluate_expression($expression, $product);
	}

	/**
	 * Evaluate a boolean expression.
	 *
	 * @param array $expression Expression to evaluate.
	 * @param WC_Product $product Product object.
	 * @return bool Result of expression evaluation.
	 */
	private function evaluate_expression($expression, $product) {
		$type = isset($expression['type']) ? $expression['type'] : 'AND';
		$conditions = isset($expression['conditions']) ? $expression['conditions'] : array();

		$results = array();
		
		foreach ($conditions as $condition) {
			if (isset($condition['type']) && $condition['type'] === 'group') {
				// Nested group
				$results[] = $this->evaluate_expression($condition, $product);
			} else {
				// Single condition
				$results[] = $this->evaluate_single_condition($condition, $product);
			}
		}

		// Apply logic
		if ($type === 'AND') {
			return !in_array(false, $results, true);
		} else {
			return in_array(true, $results, true);
		}
	}

	/**
	 * Evaluate a single condition.
	 *
	 * @param array $condition Condition to evaluate.
	 * @param WC_Product $product Product object.
	 * @return bool Result of condition evaluation.
	 */
	private function evaluate_single_condition($condition, $product) {
		$condition_type = isset($condition['condition_type']) ? $condition['condition_type'] : '';
		
		switch ($condition_type) {
			case 'category':
				return $this->check_category_conditions($condition, $product);
			
			case 'tag':
				return $this->check_tag_conditions($condition, $product);
			
			case 'price':
				return $this->check_price_condition($condition, $product);
			
			case 'product':
				return $this->check_specific_products($condition, $product);
		}

		return false;
	}

	/**
	 * Check price range conditions.
	 *
	 * @param array $conditions Price conditions.
	 * @param WC_Product $product Product object.
	 * @return bool True if price conditions are met.
	 */
	private function check_price_range_conditions($conditions, $product) {
		return $this->check_price_condition($conditions, $product);
	}

	/**
	 * Check individual price condition.
	 *
	 * @param array $condition Price condition.
	 * @param WC_Product $product Product object.
	 * @return bool True if price condition is met.
	 */
	private function check_price_condition($condition, $product) {
		$operator = isset($condition['price_operator']) ? $condition['price_operator'] : '>';
		$value = isset($condition['price_value']) ? floatval($condition['price_value']) : 0;
		
		$product_price = floatval($product->get_price());

		switch ($operator) {
			case '>':
				return $product_price > $value;
			case '>=':
				return $product_price >= $value;
			case '<':
				return $product_price < $value;
			case '<=':
				return $product_price <= $value;
			case '=':
			case '==':
				return abs($product_price - $value) < 0.01; // Float comparison
			case '!=':
				return abs($product_price - $value) >= 0.01;
		}

		return false;
	}

	/**
	 * Calculate dynamic price based on rule.
	 *
	 * @param float $original_price Original price.
	 * @param array $rule Pricing rule.
	 * @param WC_Product $product Product object.
	 * @return float Calculated dynamic price.
	 */
	private function calculate_dynamic_price($original_price, $rule, $product) {
		// Ensure original_price is a float to prevent type errors
		$original_price = floatval($original_price);
		
		// Validate that we have a valid price
		if ($original_price <= 0) {
			$this->log_debug('calculate_dynamic_price: Invalid original price', array(
				'original_price' => $original_price,
				'product_id' => $product ? $product->get_id() : 'no_product'
			));
			return false;
		}
		
		$adjustment = isset($rule['price_adjustment']) ? $rule['price_adjustment'] : array();
		$type = isset($adjustment['type']) ? $adjustment['type'] : 'percentage';
		$value = isset($adjustment['value']) ? floatval($adjustment['value']) : 0;

		$dynamic_price = $original_price;

		switch ($type) {
			case 'percentage':
				// Apply percentage adjustment. Positive values INCREASE the price, negative values DECREASE it.
				if ( $value >= 0 ) {
					$dynamic_price = $original_price * ( 1 + ( $value / 100 ) );
				} else {
					$dynamic_price = $original_price * ( 1 - ( abs( $value ) / 100 ) );
				}
				break;
			
			case 'fixed':
				// Apply fixed adjustment. Positive values INCREASE the price, negative values DECREASE it.
				$dynamic_price = $original_price + $value;
				break;
			
			case 'fixed_price':
				// Set absolute price (this one was correct)
				$dynamic_price = $value;
				break;
		}

		// Ensure price is not negative
		$dynamic_price = max(0, $dynamic_price);

		// Apply currency-specific rounding
		$dynamic_price = $this->round_price($dynamic_price);

		return $dynamic_price;
	}

	/**
	 * Round price according to currency settings.
	 *
	 * @param float $price Price to round.
	 * @return float Rounded price.
	 */
	private function round_price($price) {
		if (function_exists('wc_get_price_decimals') && function_exists('WC')) {
			$decimals = wc_get_price_decimals();
			return round($price, $decimals);
		}
		return round($price, 2);
	}

	/**
	 * Get compiled pricing rules.
	 *
	 * @return array Compiled rules.
	 */
	private function get_compiled_rules() {
		if (null !== $this->compiled_rules) {
			return $this->compiled_rules;
		}

		// Try to get from cache
		$cached_rules = $this->cache_manager->get_pricing_rules();
		if (false !== $cached_rules) {
			$this->compiled_rules = $cached_rules;
			return $this->compiled_rules;
		}

		// Compile rules from database
		$this->compiled_rules = $this->compile_rules_from_database();
		
		// Cache compiled rules
		$this->cache_manager->set_pricing_rules($this->compiled_rules, 3600); // 1 hour

		return $this->compiled_rules;
	}

	/**
	 * Compile rules from database.
	 *
	 * @return array Compiled rules.
	 */
	private function compile_rules_from_database() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'vibe_pricing_rules';
		
		$results = $wpdb->get_results("
			SELECT * FROM {$table_name} 
			WHERE status = 'active' 
			ORDER BY priority DESC, id ASC
		");

		$compiled_rules = array();

		foreach ($results as $row) {
			$rule = array(
				'id' => $row->id,
				'name' => $row->name,
				'description' => $row->description,
				'priority' => intval($row->priority),
				'referrer_conditions' => !empty($row->referrer_conditions) ? json_decode($row->referrer_conditions, true) : array(),
				'product_conditions' => !empty($row->product_conditions) ? json_decode($row->product_conditions, true) : array(),
				'price_adjustment' => !empty($row->price_adjustment) ? json_decode($row->price_adjustment, true) : array(),
				'discount_integration' => $row->discount_integration,
				'display_options' => !empty($row->display_options) ? json_decode($row->display_options, true) : array(),
			);

			// Add payment method requirement for Vibe referrer
			if (!empty($rule['referrer_conditions']['domains'])) {
				foreach ($rule['referrer_conditions']['domains'] as $domain) {
					if (strpos($domain, 'vibe.ir') !== false) {
						$rule['payment_method_required'] = 'vibe';
						break;
					}
				}
			}

			$compiled_rules[] = $rule;
		}

		return $compiled_rules;
	}

	/**
	 * Detect current referrer.
	 */
	private function detect_referrer() {
		// Check session first (for persistence across pages)
		if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['vibe_referrer'])) {
			$this->current_referrer = $_SESSION['vibe_referrer'];
			return;
		}

		// Check HTTP referrer
		if (!empty($_SERVER['HTTP_REFERER'])) {
			$referrer_url = $_SERVER['HTTP_REFERER'];
			$parsed_url = parse_url($referrer_url);
			
			if (isset($parsed_url['host'])) {
				$this->current_referrer = $parsed_url['host'];
				
				// Store in session for persistence
				if (session_status() === PHP_SESSION_NONE) {
					session_start();
				}
				$_SESSION['vibe_referrer'] = $this->current_referrer;
			}
		}

		// Check for URL parameter override
		if (!empty($_GET['ref'])) {
			$this->current_referrer = sanitize_text_field($_GET['ref']);
			
			// Store in session
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}
			$_SESSION['vibe_referrer'] = $this->current_referrer;
		}
	}

	/**
	 * Detect current payment method.
	 */
	private function detect_payment_method() {
		// Check if we're in checkout context
		if (is_admin() || !function_exists('WC')) {
			$this->log_debug('detect_payment_method: Not in frontend or WC not available');
			return;
		}

		// Check session/POST data for selected payment method
		if (!empty($_POST['payment_method'])) {
			$this->current_payment_method = sanitize_text_field($_POST['payment_method']);
			$this->log_debug('detect_payment_method: From POST', array(
				'payment_method' => $this->current_payment_method
			));
		} elseif (WC()->session && WC()->session->get('chosen_payment_method')) {
			$this->current_payment_method = WC()->session->get('chosen_payment_method');
			$this->log_debug('detect_payment_method: From WC session', array(
				'payment_method' => $this->current_payment_method
			));
		} else {
			$this->log_debug('detect_payment_method: No payment method detected');
		}
	}

	/**
	 * Setup evaluation context.
	 */
	private function setup_evaluation_context() {
		$this->evaluation_context = array(
			'referrer' => $this->current_referrer,
			'payment_method' => $this->current_payment_method,
			'timestamp' => time(),
			'user_id' => get_current_user_id(),
		);
	}

	/**
	 * Generate cache context string.
	 *
	 * @param string $context_type Context type: 'display' or 'application'.
	 * @return string Cache context.
	 */
	private function generate_cache_context($context_type = 'application') {
		// Include more specific context to ensure cache is invalidated when payment method changes
		$context_parts = array(
			$this->current_referrer ? $this->current_referrer : 'no_referrer',
			$this->current_payment_method ? $this->current_payment_method : 'no_payment_method',
			// Add timestamp component to avoid stale caches during development/testing
			get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined'),
			$context_type
		);

		$context = implode('_', $context_parts);
		
		$this->log_debug('generate_cache_context', array(
			'context' => $context,
			'parts' => $context_parts
		));
		
		return $context;
	}

	/**
	 * Clear compiled rules cache.
	 */
	public function clear_rules_cache() {
		$this->compiled_rules = null;
		$this->cache_manager->delete('pricing_rules_compiled');
		
		// Also clear all product pricing caches when rules change
		$this->cache_manager->clear_pricing_cache();
	}

	/**
	 * Get current referrer.
	 *
	 * @return string Current referrer domain.
	 */
	public function get_current_referrer() {
		return $this->current_referrer;
	}

	/**
	 * Get current payment method.
	 *
	 * @return string Current payment method.
	 */
	public function get_current_payment_method() {
		return $this->current_payment_method;
	}

	/**
	 * Set current payment method (for testing or manual override).
	 *
	 * @param string $payment_method Payment method.
	 */
	public function set_current_payment_method($payment_method) {
		// Clear caches when payment method changes
		if ($this->current_payment_method !== $payment_method) {
			$this->log_debug('set_current_payment_method: Payment method changed, clearing caches', array(
				'old_method' => $this->current_payment_method,
				'new_method' => $payment_method
			));
			
			// Clear all product pricing caches since context has changed
			$this->cache_manager->clear_pricing_cache();
		}
		
		$this->current_payment_method = $payment_method;
		$this->setup_evaluation_context();
	}

	/**
	 * Get evaluation context.
	 *
	 * @return array Evaluation context.
	 */
	public function get_evaluation_context() {
		return $this->evaluation_context;
	}

	/**
	 * Get cache manager.
	 *
	 * @return WC_Vibe_Cache_Manager Cache manager instance.
	 */
	public function get_cache_manager() {
		return $this->cache_manager;
	}

	/**
	 * Log debug information if debugging is enabled.
	 *
	 * @param string $message Debug message.
	 * @param array $data Additional data to log.
	 */
	private function log_debug($message, $data = array()) {
		if (!$this->is_debug_enabled()) {
			return;
		}

		$log_entry = array(
			'timestamp' => current_time('mysql'),
			'class' => 'WC_Vibe_Pricing_Engine',
			'message' => $message,
			'data' => $data,
			'backtrace' => wp_debug_backtrace_summary()
		);

		error_log('VIBE_DEBUG: ' . json_encode($log_entry));

		// Also store in option for admin review
		$debug_logs = get_option('wc_vibe_debug_logs', array());
		$debug_logs[] = $log_entry;
		
		// Keep only last 100 entries
		if (count($debug_logs) > 100) {
			$debug_logs = array_slice($debug_logs, -100);
		}
		
		update_option('wc_vibe_debug_logs', $debug_logs);
	}

	/**
	 * Check if debugging is enabled.
	 *
	 * @return bool True if debugging is enabled.
	 */
	private function is_debug_enabled() {
		return defined('WP_DEBUG') && WP_DEBUG && get_option('wc_vibe_enable_debug_logging', false);
	}

	/**
	 * Output debug information in footer for admin users.
	 */
	public function output_debug_info() {
		if (!current_user_can('manage_options') || !$this->is_debug_enabled()) {
			return;
		}

		$debug_info = array(
			'pricing_engine' => array(
				'current_payment_method' => $this->current_payment_method,
				'current_referrer' => $this->current_referrer,
				'evaluation_context' => $this->evaluation_context,
				'apply_mode' => get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined'),
				'compiled_rules_count' => $this->compiled_rules ? count($this->compiled_rules) : 0
			),
			'wc_functions_available' => array(
				'WC' => function_exists('WC'),
				'wc_get_price_decimals' => function_exists('wc_get_price_decimals'),
				'session_available' => function_exists('WC') && WC()->session ? true : false
			),
			'server_data' => array(
				'POST_payment_method' => isset($_POST['payment_method']) ? $_POST['payment_method'] : null,
				'HTTP_REFERER' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null,
				'session_id' => session_id()
			)
		);

		echo "<!-- VIBE PRICING ENGINE DEBUG INFO -->\n";
		echo "<script>console.log('Vibe Pricing Engine Debug:', " . json_encode($debug_info) . ");</script>\n";
		echo "<!-- END VIBE PRICING ENGINE DEBUG INFO -->\n";
	}

	/**
	 * Check if a product has applicable pricing rules for a specific payment method.
	 * This is used for gateway availability checks.
	 *
	 * @param WC_Product $product Product object.
	 * @param string $payment_method Payment method to check for.
	 * @return bool True if product has applicable rules for the payment method.
	 */
	public function has_applicable_rules_for_product($product, $payment_method) {
		if (!$product || !is_a($product, 'WC_Product')) {
			return false;
		}

		// Save current context
		$original_payment_method = $this->current_payment_method;
		
		// Temporarily set payment method for the check
		$this->current_payment_method = $payment_method;
		
		try {
			// Get applicable rules for this product
			$rules = $this->get_applicable_rules($product, 'application');
			
			// Check if any rules would apply
			$has_rules = !empty($rules);
			
			$this->log_debug('has_applicable_rules_for_product', array(
				'product_id' => $product->get_id(),
				'payment_method' => $payment_method,
				'rules_count' => count($rules),
				'has_rules' => $has_rules
			));
			
			return $has_rules;
		} finally {
			// Always restore original context
			$this->current_payment_method = $original_payment_method;
		}
	}
} 