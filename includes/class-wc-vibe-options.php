<?php
/**
 * WooCommerce Vibe Gateway Options Management
 *
 * Centralized options management following WordPress enterprise standards.
 *
 * @package WooCommerce_Vibe_Gateway
 * @since   1.2.3
 */

defined('ABSPATH') || exit;

/**
 * WC_Vibe_Options class.
 *
 * Provides centralized management of plugin options with consistent naming,
 * proper sanitization, and enterprise-grade error handling.
 */
class WC_Vibe_Options {

	/**
	 * Option key prefix for consistent naming convention.
	 */
	const PREFIX = 'wc_vibe_';

	/**
	 * Default option values.
	 */
	private static $defaults = array(
		'version' => '1.2.3',
		'activation_pending' => false,
		'activation_error' => '',
		'last_heartbeat' => 0,
		'tracking_cache' => array(),
		'dynamic_pricing_enabled' => 'yes',
		'dynamic_pricing_emergency_disable' => 'no',
		'dynamic_pricing_apply_mode' => 'combined',
		'price_display_settings' => array(
			'display_layout' => 'two_line',
			'price_order' => 'original_first',
			'new_price_font_size' => '100%',
			'original_price_font_size' => '85%',
			'new_price_prefix' => 'قیمت اقساطی ',
			'original_price_prefix' => 'قیمت نقدی ',
		),
		'api_key' => '',
		'api_enable_auth' => 'no',
		'debug_logs' => array(),
		'enable_debug_logging' => false,
	);

	/**
	 * Get an option value.
	 *
	 * @param string $key     Option key (without prefix).
	 * @param mixed  $default Default value if option doesn't exist.
	 * @return mixed Option value.
	 */
	public static function get($key, $default = null) {
		// Use default from our defaults array if no custom default provided
		if ($default === null && isset(self::$defaults[$key])) {
			$default = self::$defaults[$key];
		}

		$value = get_option(self::PREFIX . $key, $default);
		
		// Apply sanitization when retrieving
		return self::sanitize_option($key, $value);
	}

	/**
	 * Update an option value.
	 *
	 * @param string $key   Option key (without prefix).
	 * @param mixed  $value Option value.
	 * @return bool True if option was updated, false otherwise.
	 */
	public static function update($key, $value) {
		// Sanitize before saving
		$sanitized_value = self::sanitize_option($key, $value);
		
		return update_option(self::PREFIX . $key, $sanitized_value);
	}

	/**
	 * Delete an option.
	 *
	 * @param string $key Option key (without prefix).
	 * @return bool True if option was deleted, false otherwise.
	 */
	public static function delete($key) {
		return delete_option(self::PREFIX . $key);
	}

	/**
	 * Add an option (only if it doesn't exist).
	 *
	 * @param string $key   Option key (without prefix).
	 * @param mixed  $value Option value.
	 * @return bool True if option was added, false if it already exists.
	 */
	public static function add($key, $value) {
		// Sanitize before saving
		$sanitized_value = self::sanitize_option($key, $value);
		
		return add_option(self::PREFIX . $key, $sanitized_value);
	}

	/**
	 * Get all plugin options.
	 *
	 * @return array All plugin options with their values.
	 */
	public static function get_all() {
		$options = array();
		
		foreach (array_keys(self::$defaults) as $key) {
			$options[$key] = self::get($key);
		}
		
		return $options;
	}

	/**
	 * Sanitize option value based on option type.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Option value to sanitize.
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_option($key, $value) {
		switch ($key) {
			case 'version':
			case 'activation_error':
			case 'dynamic_pricing_apply_mode':
			case 'api_key':
				return sanitize_text_field($value);

			case 'activation_pending':
			case 'enable_debug_logging':
				return (bool) $value;

			case 'dynamic_pricing_enabled':
			case 'dynamic_pricing_emergency_disable':
			case 'api_enable_auth':
				return in_array($value, array('yes', 'no'), true) ? $value : 'no';

			case 'last_heartbeat':
				return absint($value);

			case 'tracking_cache':
			case 'debug_logs':
				return is_array($value) ? $value : array();

			case 'price_display_settings':
				if (!is_array($value)) {
					return self::$defaults[$key];
				}
				
				// Sanitize each setting within the array
				$sanitized = array();
				$default_settings = self::$defaults[$key];
				
				foreach ($default_settings as $setting_key => $default_value) {
					if (isset($value[$setting_key])) {
						switch ($setting_key) {
							case 'display_layout':
								$sanitized[$setting_key] = in_array($value[$setting_key], array('two_line', 'inline'), true) 
									? $value[$setting_key] : 'two_line';
								break;
							case 'price_order':
								$sanitized[$setting_key] = in_array($value[$setting_key], array('original_first', 'dynamic_first'), true) 
									? $value[$setting_key] : 'original_first';
								break;
							case 'new_price_font_size':
							case 'original_price_font_size':
							case 'new_price_prefix':
							case 'original_price_prefix':
								$sanitized[$setting_key] = sanitize_text_field($value[$setting_key]);
								break;
							default:
								$sanitized[$setting_key] = $value[$setting_key];
						}
					} else {
						$sanitized[$setting_key] = $default_value;
					}
				}
				
				return $sanitized;

			default:
				// Default sanitization for unknown keys
				if (is_string($value)) {
					return sanitize_text_field($value);
				}
				return $value;
		}
	}

	/**
	 * Reset all options to their default values.
	 *
	 * @return bool True if all options were reset successfully.
	 */
	public static function reset_all() {
		$success = true;
		
		foreach (self::$defaults as $key => $default_value) {
			if (!self::update($key, $default_value)) {
				$success = false;
			}
		}
		
		return $success;
	}

	/**
	 * Get the full option name with prefix.
	 *
	 * @param string $key Option key (without prefix).
	 * @return string Full option name with prefix.
	 */
	public static function get_option_name($key) {
		return self::PREFIX . $key;
	}

	/**
	 * Check if an option exists.
	 *
	 * @param string $key Option key (without prefix).
	 * @return bool True if option exists, false otherwise.
	 */
	public static function exists($key) {
		return get_option(self::PREFIX . $key, '__not_found__') !== '__not_found__';
	}

	/**
	 * Get option statistics for monitoring/debugging.
	 *
	 * @return array Statistics about plugin options.
	 */
	public static function get_stats() {
		$stats = array(
			'total_options' => 0,
			'existing_options' => 0,
			'options_with_defaults' => 0,
			'custom_values' => 0,
		);

		foreach (self::$defaults as $key => $default_value) {
			$stats['total_options']++;
			
			if (self::exists($key)) {
				$stats['existing_options']++;
				
				$current_value = get_option(self::PREFIX . $key);
				if ($current_value === $default_value) {
					$stats['options_with_defaults']++;
				} else {
					$stats['custom_values']++;
				}
			}
		}

		return $stats;
	}
}