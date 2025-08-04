<?php

/**
 * Vibe Rule Compiler Class
 *
 * Enterprise-grade rule compilation system that pre-processes and indexes
 * pricing rules for O(1) lookup performance.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.3.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rule Compiler for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Rule_Compiler
 * @version  1.3.0
 */
class WC_Vibe_Rule_Compiler {

    /**
     * Compiled rule index.
     *
     * @var array
     */
    private $compiled_index = null;

    /**
     * Cache manager instance.
     *
     * @var WC_Vibe_Cache_Manager
     */
    private $cache_manager;

    /**
     * Performance metrics.
     *
     * @var array
     */
    private $metrics = array(
        'compile_time' => 0,
        'rules_processed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0
    );

    /**
     * Constructor.
     *
     * @param WC_Vibe_Cache_Manager $cache_manager Cache manager instance.
     */
    public function __construct($cache_manager) {
        $this->cache_manager = $cache_manager;
    }

    /**
     * Get compiled rule index with O(1) product lookup.
     *
     * @return array Compiled rule index.
     */
    public function get_compiled_index() {
        if (null !== $this->compiled_index) {
            return $this->compiled_index;
        }

        $cache_key = 'compiled_rule_index_v2';
        $cached_index = $this->cache_manager->get($cache_key);
        
        if (false !== $cached_index) {
            $this->compiled_index = $cached_index;
            $this->metrics['cache_hits']++;
            return $this->compiled_index;
        }

        $this->metrics['cache_misses']++;
        $start_time = microtime(true);
        
        $this->compiled_index = $this->compile_rule_index();
        
        $this->metrics['compile_time'] = microtime(true) - $start_time;
        
        // Cache for 1 hour with auto-refresh on rule changes
        $this->cache_manager->set($cache_key, $this->compiled_index, 3600);
        
        return $this->compiled_index;
    }

    /**
     * Compile rule index for fast lookups.
     *
     * Creates indexed data structures:
     * - product_rules: product_id => [rule_ids]
     * - category_rules: category_id => [rule_ids] 
     * - global_rules: [rule_ids] (apply to all products)
     * - rule_data: rule_id => compiled_rule_data
     *
     * @return array Compiled index.
     */
    private function compile_rule_index() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'vibe_pricing_rules';
        
        // Single optimized query with proper indexing
        $rules_data = $wpdb->get_results("
            SELECT 
                id, name, priority, status,
                referrer_conditions, product_conditions, price_adjustment,
                discount_integration, display_options,
                created_at, updated_at
            FROM {$table_name} 
            WHERE status = 'active' 
            ORDER BY priority DESC, id ASC
        ", ARRAY_A);

        if (!$rules_data) {
            return $this->get_empty_index();
        }

        $compiled_index = array(
            'product_rules' => array(),
            'category_rules' => array(),
            'tag_rules' => array(),
            'global_rules' => array(),
            'rule_data' => array(),
            'compiled_at' => time(),
            'version' => '2.0'
        );

        foreach ($rules_data as $rule_row) {
            $this->metrics['rules_processed']++;
            
            $compiled_rule = $this->compile_single_rule($rule_row);
            $rule_id = (int) $rule_row['id'];
            
            $compiled_index['rule_data'][$rule_id] = $compiled_rule;
            
            // Index by product conditions for O(1) lookup
            $this->index_rule_by_conditions($compiled_rule, $rule_id, $compiled_index);
        }

        // Optimize index structure for memory efficiency
        $this->optimize_index_structure($compiled_index);

        return $compiled_index;
    }

    /**
     * Compile a single rule with optimized data structure.
     *
     * @param array $rule_row Raw rule data from database.
     * @return array Compiled rule.
     */
    private function compile_single_rule($rule_row) {
        $compiled_rule = array(
            'id' => (int) $rule_row['id'],
            'name' => $rule_row['name'],
            'priority' => (int) $rule_row['priority'],
            'referrer_conditions' => $this->parse_json_field($rule_row['referrer_conditions']),
            'product_conditions' => $this->parse_json_field($rule_row['product_conditions']),
            'price_adjustment' => $this->parse_json_field($rule_row['price_adjustment']),
            'discount_integration' => $rule_row['discount_integration'],
            'display_options' => $this->parse_json_field($rule_row['display_options'])
        );

        // Pre-compile condition matchers for performance
        $compiled_rule['condition_matchers'] = $this->compile_condition_matchers($compiled_rule);
        
        // Pre-calculate rule hash for cache invalidation
        $compiled_rule['hash'] = $this->calculate_rule_hash($compiled_rule);

        return $compiled_rule;
    }

    /**
     * Index rule by its conditions for fast lookup.
     *
     * @param array $compiled_rule Compiled rule data.
     * @param int $rule_id Rule ID.
     * @param array &$compiled_index Reference to index being built.
     */
    private function index_rule_by_conditions($compiled_rule, $rule_id, &$compiled_index) {
        $conditions = $compiled_rule['product_conditions'];
        $target_type = isset($conditions['target_type']) ? $conditions['target_type'] : 'all';

        switch ($target_type) {
            case 'all':
                $compiled_index['global_rules'][] = $rule_id;
                break;

            case 'specific':
                if (!empty($conditions['product_ids'])) {
                    foreach ($conditions['product_ids'] as $product_id) {
                        $compiled_index['product_rules'][(int) $product_id][] = $rule_id;
                    }
                }
                break;

            case 'categories':
                if (!empty($conditions['categories'])) {
                    foreach ($conditions['categories'] as $category_id) {
                        $compiled_index['category_rules'][(int) $category_id][] = $rule_id;
                    }
                }
                break;

            case 'tags':
                if (!empty($conditions['tags'])) {
                    foreach ($conditions['tags'] as $tag_id) {
                        $compiled_index['tag_rules'][(int) $tag_id][] = $rule_id;
                    }
                }
                break;

            case 'complex':
            case 'price_range':
                // These require evaluation but still indexed globally for now
                // TODO: Advanced indexing for complex conditions
                $compiled_index['global_rules'][] = $rule_id;
                break;
        }
    }

    /**
     * Get applicable rule IDs for a product with O(1) lookup.
     *
     * @param WC_Product $product Product object.
     * @param array $compiled_index Compiled rule index.
     * @return array Array of rule IDs sorted by priority.
     */
    public function get_applicable_rule_ids($product, $compiled_index = null) {
        if (null === $compiled_index) {
            $compiled_index = $this->get_compiled_index();
        }

        $applicable_rule_ids = array();
        $product_id = $product->get_id();

        // 1. Direct product rules (O(1) lookup)
        if (isset($compiled_index['product_rules'][$product_id])) {
            $applicable_rule_ids = array_merge(
                $applicable_rule_ids, 
                $compiled_index['product_rules'][$product_id]
            );
        }

        // 2. Category rules (O(1) lookup per category)
        $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        foreach ($product_categories as $category_id) {
            if (isset($compiled_index['category_rules'][$category_id])) {
                $applicable_rule_ids = array_merge(
                    $applicable_rule_ids, 
                    $compiled_index['category_rules'][$category_id]
                );
            }
        }

        // 3. Tag rules (O(1) lookup per tag)
        $product_tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'ids'));
        foreach ($product_tags as $tag_id) {
            if (isset($compiled_index['tag_rules'][$tag_id])) {
                $applicable_rule_ids = array_merge(
                    $applicable_rule_ids, 
                    $compiled_index['tag_rules'][$tag_id]
                );
            }
        }

        // 4. Global rules (apply to all products)
        if (!empty($compiled_index['global_rules'])) {
            $applicable_rule_ids = array_merge($applicable_rule_ids, $compiled_index['global_rules']);
        }

        // Remove duplicates and sort by priority (already in priority order)
        $applicable_rule_ids = array_unique($applicable_rule_ids);

        // Return rule data sorted by priority
        return $this->sort_rules_by_priority($applicable_rule_ids, $compiled_index);
    }

    /**
     * Get rule data for specific rule IDs.
     *
     * @param array $rule_ids Rule IDs.
     * @param array $compiled_index Compiled rule index.
     * @return array Array of rule data sorted by priority.
     */
    public function get_rules_data($rule_ids, $compiled_index = null) {
        if (null === $compiled_index) {
            $compiled_index = $this->get_compiled_index();
        }

        $rules_data = array();
        foreach ($rule_ids as $rule_id) {
            if (isset($compiled_index['rule_data'][$rule_id])) {
                $rules_data[] = $compiled_index['rule_data'][$rule_id];
            }
        }

        return $rules_data;
    }

    /**
     * Sort rules by priority.
     *
     * @param array $rule_ids Rule IDs.
     * @param array $compiled_index Compiled rule index.
     * @return array Sorted rule IDs.
     */
    private function sort_rules_by_priority($rule_ids, $compiled_index) {
        usort($rule_ids, function($a, $b) use ($compiled_index) {
            $priority_a = $compiled_index['rule_data'][$a]['priority'] ?? 0;
            $priority_b = $compiled_index['rule_data'][$b]['priority'] ?? 0;
            
            if ($priority_a == $priority_b) {
                return $a - $b; // Secondary sort by ID
            }
            
            return $priority_b - $priority_a; // Higher priority first
        });

        return $rule_ids;
    }

    /**
     * Compile condition matchers for faster evaluation.
     *
     * @param array $compiled_rule Compiled rule.
     * @return array Condition matchers.
     */
    private function compile_condition_matchers($compiled_rule) {
        $matchers = array();
        
        // Pre-compile referrer conditions
        if (!empty($compiled_rule['referrer_conditions']['domains'])) {
            $matchers['referrer_domains'] = array_map('strtolower', $compiled_rule['referrer_conditions']['domains']);
            $matchers['referrer_match_type'] = $compiled_rule['referrer_conditions']['match_type'] ?? 'contains';
        }

        // Pre-compile price conditions
        $product_conditions = $compiled_rule['product_conditions'];
        if (isset($product_conditions['price_operator'], $product_conditions['price_value'])) {
            $matchers['price_condition'] = array(
                'operator' => $product_conditions['price_operator'],
                'value' => (float) $product_conditions['price_value']
            );
        }

        return $matchers;
    }

    /**
     * Calculate rule hash for cache invalidation.
     *
     * @param array $compiled_rule Compiled rule.
     * @return string Rule hash.
     */
    private function calculate_rule_hash($compiled_rule) {
        $hash_data = array(
            $compiled_rule['id'],
            $compiled_rule['priority'],
            $compiled_rule['referrer_conditions'],
            $compiled_rule['product_conditions'],
            $compiled_rule['price_adjustment']
        );
        
        return hash('xxh64', serialize($hash_data));
    }

    /**
     * Parse JSON field with error handling.
     *
     * @param string $json_string JSON string.
     * @return array Parsed data or empty array on error.
     */
    private function parse_json_field($json_string) {
        if (empty($json_string)) {
            return array();
        }

        $decoded = json_decode($json_string, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Vibe Rule Compiler] JSON decode error: ' . json_last_error_msg() . ' for data: ' . substr($json_string, 0, 100));
            return array();
        }

        return $decoded ?: array();
    }

    /**
     * Optimize index structure for memory efficiency.
     *
     * @param array &$compiled_index Reference to compiled index.
     */
    private function optimize_index_structure(&$compiled_index) {
        // Remove empty arrays to save memory
        foreach ($compiled_index as $key => $value) {
            if (is_array($value) && empty($value) && $key !== 'rule_data') {
                unset($compiled_index[$key]);
            }
        }

        // Compress rule data if many rules exist
        if (count($compiled_index['rule_data']) > 50) {
            $compiled_index['compressed'] = true;
            // Could implement additional compression here if needed
        }
    }

    /**
     * Get empty index structure.
     *
     * @return array Empty index.
     */
    private function get_empty_index() {
        return array(
            'product_rules' => array(),
            'category_rules' => array(),
            'tag_rules' => array(),
            'global_rules' => array(),
            'rule_data' => array(),
            'compiled_at' => time(),
            'version' => '2.0'
        );
    }

    /**
     * Invalidate compiled index cache.
     *
     * Call this when rules are modified.
     */
    public function invalidate_index() {
        $this->compiled_index = null;
        $this->cache_manager->delete('compiled_rule_index_v2');
        
        // Also clear dependent caches
        $this->cache_manager->clear_pricing_cache();
        
        // Log cache invalidation for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Vibe Rule Compiler] Index cache invalidated');
        }
    }

    /**
     * Get performance metrics.
     *
     * @return array Performance metrics.
     */
    public function get_metrics() {
        return $this->metrics;
    }

    /**
     * Warm up the compiled index.
     *
     * Pre-loads and caches the rule index for better performance.
     */
    public function warm_up_index() {
        $start_time = microtime(true);
        $this->get_compiled_index();
        $warm_up_time = microtime(true) - $start_time;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Vibe Rule Compiler] Index warmed up in %.3f seconds. Rules: %d, Cache hits: %d',
                $warm_up_time,
                $this->metrics['rules_processed'],
                $this->metrics['cache_hits']
            ));
        }
    }

    /**
     * Get index statistics for monitoring.
     *
     * @return array Index statistics.
     */
    public function get_index_stats() {
        $index = $this->get_compiled_index();
        
        return array(
            'total_rules' => count($index['rule_data']),
            'product_specific_rules' => count($index['product_rules']),
            'category_rules' => count($index['category_rules']),
            'tag_rules' => count($index['tag_rules']),
            'global_rules' => count($index['global_rules']),
            'compiled_at' => $index['compiled_at'],
            'version' => $index['version'],
            'memory_usage' => strlen(serialize($index)),
            'compile_time' => $this->metrics['compile_time'],
            'cache_hit_ratio' => $this->metrics['cache_hits'] / max(1, $this->metrics['cache_hits'] + $this->metrics['cache_misses'])
        );
    }
}