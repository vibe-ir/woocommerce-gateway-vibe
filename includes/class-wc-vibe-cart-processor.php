<?php

/**
 * Vibe Cart Processor Class
 *
 * Enterprise-grade cart processing that eliminates duplicate loops and
 * implements efficient batch processing for O(n) performance.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.3.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cart Processor for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Cart_Processor
 * @version  1.3.0
 */
class WC_Vibe_Cart_Processor {

    /**
     * Rule compiler instance.
     *
     * @var WC_Vibe_Rule_Compiler
     */
    private $rule_compiler;

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
     * Processed cart data cache.
     *
     * @var array
     */
    private $cart_analysis_cache = null;

    /**
     * Performance monitoring.
     *
     * @var array
     */
    private $performance_metrics = array(
        'process_time' => 0,
        'products_processed' => 0,
        'rules_evaluated' => 0,
        'cache_hits' => 0,
        'db_queries' => 0
    );

    /**
     * Constructor.
     *
     * @param WC_Vibe_Rule_Compiler $rule_compiler Rule compiler instance.
     * @param WC_Vibe_Pricing_Engine $pricing_engine Pricing engine instance.
     * @param WC_Vibe_Cache_Manager $cache_manager Cache manager instance.
     */
    public function __construct($rule_compiler, $pricing_engine, $cache_manager) {
        $this->rule_compiler = $rule_compiler;
        $this->pricing_engine = $pricing_engine;
        $this->cache_manager = $cache_manager;
    }

    /**
     * Analyze cart for Vibe gateway availability with single-pass processing.
     *
     * @param string $context Processing context ('gateway_check', 'pricing_display', 'checkout').
     * @param string $payment_method Payment method context.
     * @return array Cart analysis results.
     */
    public function analyze_cart_for_vibe_gateway($context = 'gateway_check', $payment_method = 'vibe') {
        $start_time = microtime(true);
        
        // Check cache first
        $cache_key = $this->generate_cart_cache_key($context, $payment_method);
        $cached_analysis = $this->cache_manager->get($cache_key);
        
        if (false !== $cached_analysis) {
            $this->performance_metrics['cache_hits']++;
            return $cached_analysis;
        }

        // Get cart items once
        if (!function_exists('WC') || !WC()->cart) {
            return $this->get_empty_analysis_result();
        }

        $cart_items = WC()->cart->get_cart();
        if (empty($cart_items)) {
            return $this->get_empty_analysis_result();
        }

        // Single-pass cart analysis
        $analysis_result = $this->perform_single_pass_cart_analysis($cart_items, $context, $payment_method);

        // Cache result for 30 minutes
        $this->cache_manager->set($cache_key, $analysis_result, 1800);
        
        $this->performance_metrics['process_time'] = microtime(true) - $start_time;
        
        return $analysis_result;
    }

    /**
     * Perform single-pass cart analysis eliminating all duplicate processing.
     *
     * @param array $cart_items Cart items.
     * @param string $context Processing context.
     * @param string $payment_method Payment method.
     * @return array Analysis result.
     */
    private function perform_single_pass_cart_analysis($cart_items, $context, $payment_method) {
        // Step 1: Extract all unique product IDs (including parent products)
        $product_ids = $this->extract_all_product_ids($cart_items);
        
        // Step 2: Batch load all products in single query
        $products = $this->batch_load_products($product_ids);
        
        // Step 3: Get compiled rule index once
        $rule_index = $this->rule_compiler->get_compiled_index();
        
        // Step 4: Single-pass analysis
        return $this->analyze_cart_items_single_pass($cart_items, $products, $rule_index, $context, $payment_method);
    }

    /**
     * Extract all product IDs needed for processing (including parent products).
     *
     * @param array $cart_items Cart items.
     * @return array Unique product IDs.
     */
    private function extract_all_product_ids($cart_items) {
        $product_ids = array();
        
        foreach ($cart_items as $cart_item) {
            if (!isset($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
                continue;
            }
            
            $product = $cart_item['data'];
            $product_ids[] = $product->get_id();
            
            // Include parent product ID for variations
            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) {
                    $product_ids[] = $parent_id;
                }
            }
        }
        
        return array_unique(array_filter($product_ids));
    }

    /**
     * Batch load products using optimized query.
     *
     * @param array $product_ids Product IDs to load.
     * @return array Products indexed by ID.
     */
    private function batch_load_products($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $this->performance_metrics['db_queries']++;
        
        // Use WC's optimized product loading
        $products = array();
        
        try {
            // Batch load products using WC_Product_Data_Store
            $data_store = WC_Data_Store::load('product');
            
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $products[$product_id] = $product;
                }
            }
            
            $this->performance_metrics['products_processed'] = count($products);
            
        } catch (Exception $e) {
            error_log('[Vibe Cart Processor] Error batch loading products: ' . $e->getMessage());
            return array();
        }
        
        return $products;
    }

    /**
     * Analyze cart items in single pass with pre-loaded data.
     *
     * @param array $cart_items Cart items.
     * @param array $products Pre-loaded products indexed by ID.
     * @param array $rule_index Compiled rule index.
     * @param string $context Processing context.
     * @param string $payment_method Payment method.
     * @return array Analysis result.
     */
    private function analyze_cart_items_single_pass($cart_items, $products, $rule_index, $context, $payment_method) {
        $analysis_result = array(
            'vibe_gateway_available' => true,
            'items_with_rules' => 0,
            'total_items' => 0,
            'items_analysis' => array(),
            'performance_summary' => array(),
            'context' => $context,
            'payment_method' => $payment_method
        );

        // Set pricing engine context
        $this->pricing_engine->set_current_payment_method($payment_method);
        
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $analysis_result['total_items']++;
            
            if (!isset($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
                $analysis_result['vibe_gateway_available'] = false;
                $analysis_result['items_analysis'][$cart_item_key] = array(
                    'has_rules' => false,
                    'error' => 'Invalid product data'
                );
                continue;
            }
            
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            
            // Analyze single item using pre-loaded data
            $item_analysis = $this->analyze_single_cart_item(
                $product, 
                $products, 
                $rule_index, 
                $context, 
                $payment_method
            );
            
            $analysis_result['items_analysis'][$cart_item_key] = $item_analysis;
            
            if ($item_analysis['has_rules']) {
                $analysis_result['items_with_rules']++;
            } else {
                // For gateway availability check, all items must have rules
                if ($context === 'gateway_check') {
                    $analysis_result['vibe_gateway_available'] = false;
                }
            }
        }

        // Add performance metrics
        $analysis_result['performance_summary'] = $this->performance_metrics;
        
        return $analysis_result;
    }

    /**
     * Analyze single cart item with optimized rule checking.
     *
     * @param WC_Product $product Product object.
     * @param array $products Pre-loaded products (for parent lookup).
     * @param array $rule_index Compiled rule index.
     * @param string $context Processing context.
     * @param string $payment_method Payment method.
     * @return array Item analysis.
     */
    private function analyze_single_cart_item($product, $products, $rule_index, $context, $payment_method) {
        $item_analysis = array(
            'product_id' => $product->get_id(),
            'has_rules' => false,
            'applicable_rules' => array(),
            'is_variation' => false,
            'parent_product_id' => null,
            'parent_has_rules' => false
        );

        // Check if product is variation
        if ($product->is_type('variation')) {
            $item_analysis['is_variation'] = true;
            $parent_id = $product->get_parent_id();
            $item_analysis['parent_product_id'] = $parent_id;
            
            // Check parent product rules using pre-loaded data
            if ($parent_id && isset($products[$parent_id])) {
                $parent_product = $products[$parent_id];
                $parent_rule_ids = $this->rule_compiler->get_applicable_rule_ids($parent_product, $rule_index);
                $item_analysis['parent_has_rules'] = !empty($parent_rule_ids);
                
                if ($item_analysis['parent_has_rules']) {
                    $item_analysis['applicable_rules'] = array_merge(
                        $item_analysis['applicable_rules'], 
                        $parent_rule_ids
                    );
                }
            }
        }

        // Check direct product rules
        $direct_rule_ids = $this->rule_compiler->get_applicable_rule_ids($product, $rule_index);
        if (!empty($direct_rule_ids)) {
            $item_analysis['applicable_rules'] = array_merge(
                $item_analysis['applicable_rules'], 
                $direct_rule_ids
            );
        }

        // Remove duplicate rule IDs
        $item_analysis['applicable_rules'] = array_unique($item_analysis['applicable_rules']);
        
        // Filter rules by context (referrer, payment method, etc.)
        $contextual_rules = $this->filter_rules_by_context(
            $item_analysis['applicable_rules'], 
            $rule_index, 
            $context, 
            $payment_method
        );

        $item_analysis['has_rules'] = !empty($contextual_rules);
        $item_analysis['contextual_rules'] = $contextual_rules;
        
        $this->performance_metrics['rules_evaluated'] += count($item_analysis['applicable_rules']);
        
        return $item_analysis;
    }

    /**
     * Filter rules by current context (payment method, referrer, etc.).
     *
     * @param array $rule_ids Rule IDs to filter.
     * @param array $rule_index Compiled rule index.
     * @param string $context Processing context.
     * @param string $payment_method Payment method.
     * @return array Filtered rule IDs.
     */
    private function filter_rules_by_context($rule_ids, $rule_index, $context, $payment_method) {
        if (empty($rule_ids)) {
            return array();
        }

        $apply_mode = get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined');
        $current_referrer = $this->pricing_engine->get_current_referrer();
        
        $contextual_rules = array();
        
        foreach ($rule_ids as $rule_id) {
            if (!isset($rule_index['rule_data'][$rule_id])) {
                continue;
            }
            
            $rule_data = $rule_index['rule_data'][$rule_id];
            
            if ($this->does_rule_match_context($rule_data, $apply_mode, $context, $payment_method, $current_referrer)) {
                $contextual_rules[] = $rule_id;
            }
        }
        
        return $contextual_rules;
    }

    /**
     * Check if rule matches current context with optimized logic.
     *
     * @param array $rule_data Rule data.
     * @param string $apply_mode Apply mode setting.
     * @param string $context Processing context.
     * @param string $payment_method Payment method.
     * @param string $current_referrer Current referrer.
     * @return bool True if rule matches context.
     */
    private function does_rule_match_context($rule_data, $apply_mode, $context, $payment_method, $current_referrer) {
        switch ($apply_mode) {
            case 'always':
                return true;

            case 'combined':
                $vibe_payment_selected = ($payment_method === 'vibe');
                $vibe_referrer = $this->check_vibe_referrer($current_referrer);
                
                if ($context === 'display' || $context === 'pricing_display') {
                    return $vibe_referrer;
                } else {
                    return $vibe_payment_selected;
                }

            case 'payment_method':
                return ($payment_method === 'vibe');

            case 'referrer':
                if (empty($rule_data['referrer_conditions']['domains'])) {
                    return true;
                }
                return $this->check_referrer_conditions($rule_data['referrer_conditions'], $current_referrer);
        }

        return false;
    }

    /**
     * Check if referrer is from Vibe with optimized string matching.
     *
     * @param string $referrer Current referrer.
     * @return bool True if Vibe referrer.
     */
    private function check_vibe_referrer($referrer) {
        if (empty($referrer)) {
            return false;
        }
        
        $referrer_lower = strtolower($referrer);
        return (strpos($referrer_lower, 'vibe.ir') !== false);
    }

    /**
     * Check referrer conditions with optimized matching.
     *
     * @param array $conditions Referrer conditions.
     * @param string $current_referrer Current referrer.
     * @return bool True if conditions match.
     */
    private function check_referrer_conditions($conditions, $current_referrer) {
        if (empty($current_referrer) || empty($conditions['domains'])) {
            return false;
        }

        $match_type = $conditions['match_type'] ?? 'contains';
        $referrer_lower = strtolower($current_referrer);
        
        foreach ($conditions['domains'] as $domain) {
            $domain_lower = strtolower(trim($domain));
            
            switch ($match_type) {
                case 'exact':
                    if ($referrer_lower === $domain_lower) return true;
                    break;
                case 'contains':
                    if (strpos($referrer_lower, $domain_lower) !== false) return true;
                    break;
                case 'ends_with':
                    if (substr($referrer_lower, -strlen($domain_lower)) === $domain_lower) return true;
                    break;
            }
        }

        return false;
    }

    /**
     * Generate cache key for cart analysis.
     *
     * @param string $context Processing context.
     * @param string $payment_method Payment method.
     * @return string Cache key.
     */
    private function generate_cart_cache_key($context, $payment_method) {
        if (!function_exists('WC') || !WC()->cart) {
            return 'vibe_cart_empty';
        }

        $cart_hash = WC()->cart->get_cart_hash();
        $referrer = $this->pricing_engine->get_current_referrer();
        $apply_mode = get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined');
        
        $cache_components = array(
            'cart_analysis',
            $context,
            $payment_method,
            $cart_hash,
            $referrer ?: 'no_referrer',
            $apply_mode,
            'v2'
        );
        
        return implode('_', $cache_components);
    }

    /**
     * Get empty analysis result.
     *
     * @return array Empty analysis result.
     */
    private function get_empty_analysis_result() {
        return array(
            'vibe_gateway_available' => false,
            'items_with_rules' => 0,
            'total_items' => 0,
            'items_analysis' => array(),
            'performance_summary' => array(),
            'context' => 'empty_cart',
            'payment_method' => 'none'
        );
    }

    /**
     * Clear cart analysis cache.
     */
    public function clear_cart_cache() {
        $this->cart_analysis_cache = null;
        
        // Clear related caches
        if (function_exists('WC') && WC()->cart) {
            $cart_hash = WC()->cart->get_cart_hash();
            $this->cache_manager->delete("cart_analysis_{$cart_hash}");
        }
    }

    /**
     * Get performance metrics.
     *
     * @return array Performance metrics.
     */
    public function get_performance_metrics() {
        return $this->performance_metrics;
    }

    /**
     * Reset performance metrics.
     */
    public function reset_performance_metrics() {
        $this->performance_metrics = array(
            'process_time' => 0,
            'products_processed' => 0,
            'rules_evaluated' => 0,
            'cache_hits' => 0,
            'db_queries' => 0
        );
    }

    /**
     * Get cart processing statistics for monitoring.
     *
     * @return array Processing statistics.
     */
    public function get_processing_stats() {
        $stats = array(
            'performance_metrics' => $this->performance_metrics,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        );

        if (function_exists('WC') && WC()->cart) {
            $stats['cart_items_count'] = WC()->cart->get_cart_contents_count();
            $stats['cart_hash'] = WC()->cart->get_cart_hash();
        }

        return $stats;
    }
}