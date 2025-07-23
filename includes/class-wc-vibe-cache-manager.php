<?php

/**
 * Vibe Cache Manager Class
 *
 * Multi-tier caching system for dynamic pricing.
 * Implements Object Cache → Transients → Database fallback strategy.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.1.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Cache Manager for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Cache_Manager
 * @version  1.1.0
 */
class WC_Vibe_Cache_Manager {

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	private $cache_group = 'vibe_dynamic_pricing';

	/**
	 * Default cache expiration time (in seconds).
	 *
	 * @var int
	 */
	private $default_expiration = 3600; // 1 hour

	/**
	 * Database cache table name.
	 *
	 * @var string
	 */
	private $db_cache_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->db_cache_table = $wpdb->prefix . 'vibe_pricing_cache';
		
		// Schedule cleanup for expired cache entries
		if (!wp_next_scheduled('vibe_cache_cleanup')) {
			wp_schedule_event(time(), 'daily', 'vibe_cache_cleanup');
		}
		
		add_action('vibe_cache_cleanup', array($this, 'cleanup_expired_cache'));
	}

	/**
	 * Get cached data using multi-tier strategy.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group (optional).
	 * @return mixed|false Cached data or false if not found.
	 */
	public function get($key, $group = null) {
		if (null === $group) {
			$group = $this->cache_group;
		}

		$cache_key = $this->generate_cache_key($key, $group);

		// Tier 1: Object Cache (if available)
		if (wp_using_ext_object_cache()) {
			$data = wp_cache_get($cache_key, $group);
			if (false !== $data) {
				return $data;
			}
		}

		// Tier 2: WordPress Transients
		$transient_key = $this->get_transient_key($cache_key);
		$data = get_transient($transient_key);
		if (false !== $data) {
			// Store in object cache for faster access next time
			if (wp_using_ext_object_cache()) {
				wp_cache_set($cache_key, $data, $group, $this->default_expiration);
			}
			return $data;
		}

		// Tier 3: Database Cache
		$data = $this->get_from_database($cache_key);
		if (false !== $data) {
			// Store in higher tiers for faster access
			set_transient($transient_key, $data, $this->default_expiration);
			if (wp_using_ext_object_cache()) {
				wp_cache_set($cache_key, $data, $group, $this->default_expiration);
			}
			return $data;
		}

		return false;
	}

	/**
	 * Set cached data using multi-tier strategy.
	 *
	 * @param string $key Cache key.
	 * @param mixed $data Data to cache.
	 * @param int $expiration Cache expiration time in seconds.
	 * @param string $group Cache group (optional).
	 * @return bool True on success, false on failure.
	 */
	public function set($key, $data, $expiration = null, $group = null) {
		if (null === $expiration) {
			$expiration = $this->default_expiration;
		}
		
		if (null === $group) {
			$group = $this->cache_group;
		}

		$cache_key = $this->generate_cache_key($key, $group);
		$success = true;

		// Store in all tiers
		// Tier 1: Object Cache
		if (wp_using_ext_object_cache()) {
			$success = wp_cache_set($cache_key, $data, $group, $expiration) && $success;
		}

		// Tier 2: WordPress Transients
		$transient_key = $this->get_transient_key($cache_key);
		$success = set_transient($transient_key, $data, $expiration) && $success;

		// Tier 3: Database Cache
		$success = $this->set_in_database($cache_key, $data, $expiration) && $success;

		return $success;
	}

	/**
	 * Delete cached data from all tiers.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group (optional).
	 * @return bool True on success, false on failure.
	 */
	public function delete($key, $group = null) {
		if (null === $group) {
			$group = $this->cache_group;
		}

		$cache_key = $this->generate_cache_key($key, $group);
		$success = true;

		// Delete from all tiers
		// Tier 1: Object Cache
		if (wp_using_ext_object_cache()) {
			$success = wp_cache_delete($cache_key, $group) && $success;
		}

		// Tier 2: WordPress Transients
		$transient_key = $this->get_transient_key($cache_key);
		$success = delete_transient($transient_key) && $success;

		// Tier 3: Database Cache
		$success = $this->delete_from_database($cache_key) && $success;

		return $success;
	}

	/**
	 * Clear all pricing cache.
	 */
	public function clear_pricing_cache() {
		// Clear object cache group
		if (wp_using_ext_object_cache()) {
			wp_cache_flush_group($this->cache_group);
		}

		// Clear transients
		$this->clear_pricing_transients();

		// Clear database cache
		$this->clear_database_cache();
	}

	/**
	 * Get pricing rules from cache.
	 *
	 * @return array|false Pricing rules or false if not cached.
	 */
	public function get_pricing_rules() {
		return $this->get('pricing_rules_compiled');
	}

	/**
	 * Set pricing rules in cache.
	 *
	 * @param array $rules Compiled pricing rules.
	 * @param int $expiration Cache expiration time.
	 * @return bool True on success, false on failure.
	 */
	public function set_pricing_rules($rules, $expiration = null) {
		return $this->set('pricing_rules_compiled', $rules, $expiration);
	}

	/**
	 * Get product rule mapping from cache.
	 *
	 * @param int $product_id Product ID.
	 * @return array|false Product rules or false if not cached.
	 */
	public function get_product_rules($product_id) {
		return $this->get("product_rules_{$product_id}");
	}

	/**
	 * Set product rule mapping in cache.
	 *
	 * @param int $product_id Product ID.
	 * @param array $rules Product rules.
	 * @param int $expiration Cache expiration time.
	 * @return bool True on success, false on failure.
	 */
	public function set_product_rules($product_id, $rules, $expiration = null) {
		return $this->set("product_rules_{$product_id}", $rules, $expiration);
	}

	/**
	 * Get dynamic price from cache.
	 *
	 * @param int $product_id Product ID.
	 * @param string $context Pricing context (e.g., referrer, payment method).
	 * @return float|false Dynamic price or false if not cached.
	 */
	public function get_dynamic_price($product_id, $context) {
		$cache_key = "dynamic_price_{$product_id}_{$context}";
		return $this->get($cache_key);
	}

	/**
	 * Set dynamic price in cache.
	 *
	 * @param int $product_id Product ID.
	 * @param string $context Pricing context.
	 * @param float $price Dynamic price.
	 * @param int $expiration Cache expiration time.
	 * @return bool True on success, false on failure.
	 */
	public function set_dynamic_price($product_id, $context, $price, $expiration = null) {
		$cache_key = "dynamic_price_{$product_id}_{$context}";
		return $this->set($cache_key, $price, $expiration);
	}

	/**
	 * Generate cache key.
	 *
	 * @param string $key Base key.
	 * @param string $group Cache group.
	 * @return string Generated cache key.
	 */
	private function generate_cache_key($key, $group) {
		return $group . '_' . $key;
	}

	/**
	 * Get transient key (WordPress has a 172 character limit).
	 *
	 * @param string $cache_key Cache key.
	 * @return string Transient key.
	 */
	private function get_transient_key($cache_key) {
		// Ensure transient key is under 172 characters
		if (strlen($cache_key) > 165) {
			$cache_key = 'vdp_' . md5($cache_key);
		}
		return $cache_key;
	}

	/**
	 * Get data from database cache.
	 *
	 * @param string $cache_key Cache key.
	 * @return mixed|false Cached data or false if not found.
	 */
	private function get_from_database($cache_key) {
		global $wpdb;

		$result = $wpdb->get_row($wpdb->prepare(
			"SELECT cache_value, expiry_time FROM {$this->db_cache_table} 
			WHERE cache_key = %s AND expiry_time > NOW()",
			$cache_key
		));

		if ($result) {
			return maybe_unserialize($result->cache_value);
		}

		return false;
	}

	/**
	 * Set data in database cache.
	 *
	 * @param string $cache_key Cache key.
	 * @param mixed $data Data to cache.
	 * @param int $expiration Cache expiration time in seconds.
	 * @return bool True on success, false on failure.
	 */
	private function set_in_database($cache_key, $data, $expiration) {
		global $wpdb;

		$expiry_time = date('Y-m-d H:i:s', time() + $expiration);
		$serialized_data = maybe_serialize($data);

		$result = $wpdb->replace(
			$this->db_cache_table,
			array(
				'cache_key' => $cache_key,
				'cache_value' => $serialized_data,
				'expiry_time' => $expiry_time,
			),
			array('%s', '%s', '%s')
		);

		return false !== $result;
	}

	/**
	 * Delete data from database cache.
	 *
	 * @param string $cache_key Cache key.
	 * @return bool True on success, false on failure.
	 */
	private function delete_from_database($cache_key) {
		global $wpdb;

		$result = $wpdb->delete(
			$this->db_cache_table,
			array('cache_key' => $cache_key),
			array('%s')
		);

		return false !== $result;
	}

	/**
	 * Clear all pricing-related transients.
	 */
	private function clear_pricing_transients() {
		global $wpdb;

		// Get all transients related to pricing
		$transients = $wpdb->get_col($wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} 
			WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $this->cache_group . '%',
			'_transient_vdp_%'
		));

		foreach ($transients as $transient) {
			$transient_name = str_replace('_transient_', '', $transient);
			delete_transient($transient_name);
		}
	}

	/**
	 * Clear all database cache.
	 */
	private function clear_database_cache() {
		global $wpdb;
		$wpdb->query("TRUNCATE TABLE {$this->db_cache_table}");
	}

	/**
	 * Clean up expired cache entries.
	 */
	public function cleanup_expired_cache() {
		global $wpdb;

		// Clean up expired database cache entries
		$wpdb->query("DELETE FROM {$this->db_cache_table} WHERE expiry_time < NOW()");

		// Clean up expired transients (WordPress should handle this, but let's be sure)
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE %s 
			AND option_value < UNIX_TIMESTAMP()",
			'_transient_timeout_' . $this->cache_group . '%'
		));
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Cache statistics.
	 */
	public function get_cache_stats() {
		global $wpdb;

		$stats = array(
			'object_cache_enabled' => wp_using_ext_object_cache(),
			'database_cache_entries' => 0,
			'database_cache_size' => 0,
			'transient_entries' => 0,
		);

		// Database cache stats
		$db_stats = $wpdb->get_row("
			SELECT COUNT(*) as entries, 
			       SUM(LENGTH(cache_value)) as size 
			FROM {$this->db_cache_table} 
			WHERE expiry_time > NOW()
		");

		if ($db_stats) {
			$stats['database_cache_entries'] = (int) $db_stats->entries;
			$stats['database_cache_size'] = (int) $db_stats->size;
		}

		// Transient stats
		$transient_count = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} 
			WHERE option_name LIKE %s",
			'_transient_' . $this->cache_group . '%'
		));

		$stats['transient_entries'] = (int) $transient_count;

		return $stats;
	}

	/**
	 * Invalidate cache for specific product.
	 *
	 * @param int $product_id Product ID.
	 */
	public function invalidate_product_cache($product_id) {
		// Delete product-specific caches
		$this->delete("product_rules_{$product_id}");
		
		// Delete all dynamic price caches for this product
		global $wpdb;
		$cache_keys = $wpdb->get_col($wpdb->prepare(
			"SELECT cache_key FROM {$this->db_cache_table} 
			WHERE cache_key LIKE %s",
			"%dynamic_price_{$product_id}_%"
		));

		foreach ($cache_keys as $cache_key) {
			$this->delete_from_database($cache_key);
		}
	}

	/**
	 * Warm up cache with frequently accessed data.
	 *
	 * @param array $product_ids Product IDs to warm up.
	 */
	public function warm_up_cache($product_ids = array()) {
		// This method can be called during off-peak hours to pre-populate cache
		// with frequently accessed pricing data
		
		if (empty($product_ids)) {
			// Get most popular products if none specified
			$product_ids = $this->get_popular_products();
		}

		foreach ($product_ids as $product_id) {
			// Pre-calculate and cache common pricing scenarios
			$product = wc_get_product($product_id);
			if ($product) {
				// Cache product rules
				$this->get_product_rules($product_id);
				
				// Cache common pricing contexts
				$common_contexts = array('vibe.ir', 'default', 'vibe_payment');
				foreach ($common_contexts as $context) {
					$this->get_dynamic_price($product_id, $context);
				}
			}
		}
	}

	/**
	 * Get popular products for cache warm-up.
	 *
	 * @param int $limit Number of products to return.
	 * @return array Product IDs.
	 */
	private function get_popular_products($limit = 100) {
		global $wpdb;

		$product_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pm.meta_key = 'total_sales'
			ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC
			LIMIT %d",
			$limit
		));

		return array_map('intval', $product_ids);
	}
} 