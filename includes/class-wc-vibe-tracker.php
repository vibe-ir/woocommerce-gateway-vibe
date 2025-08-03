<?php

/**
 * WC_Vibe_Tracker class
 *
 * Handles tracking of plugin activation and usage.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin activation and usage tracker.
 *
 * @class    WC_Vibe_Tracker
 * @version  1.0.0
 */
class WC_Vibe_Tracker
{

    /**
     * The tracking API endpoint.
     *
     * @var string
     */
    private $api_url = 'https://credit.vibe.ir/api/v1/tracking/';

    /**
     * The heartbeat event name.
     *
     * @var string
     */
    const HEARTBEAT_EVENT = 'wc_vibe_heartbeat';

    /**
     * The option name for storing the last heartbeat time.
     *
     * @var string
     */
    const LAST_HEARTBEAT_OPTION = 'wc_vibe_last_heartbeat';

    /**
     * The tracking data cache option name.
     *
     * @var string
     */
    const TRACKING_CACHE_OPTION = 'wc_vibe_tracking_cache';

    /**
     * Circuit breaker options.
     *
     * @var string
     */
    const CIRCUIT_BREAKER_OPTION = 'wc_vibe_circuit_breaker';

    /**
     * Activation lock transient key.
     *
     * @var string
     */
    const ACTIVATION_LOCK_KEY = 'wc_vibe_activation_lock';

    /**
     * Max retry attempts for API calls.
     *
     * @var int
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Circuit breaker failure threshold.
     *
     * @var int
     */
    const CIRCUIT_BREAKER_THRESHOLD = 5;

    /**
     * Debug mode flag.
     *
     * @var bool
     */
    private $debug_mode = false;

    /**
     * Initialize the tracker.
     */
    public static function init()
    {
        $instance = new self();

        // Using plugins_loaded is more reliable than register_activation_hook for certain scenarios
        add_action('plugins_loaded', array($instance, 'check_activation'), 20);

        // Register deactivation hook
        register_deactivation_hook(WC_VIBE_PLUGIN_FILE, array($instance, 'track_deactivation'));

        // Register the heartbeat event
        add_action('init', array($instance, 'register_heartbeat'));

        // Handle the heartbeat event
        add_action(self::HEARTBEAT_EVENT, array($instance, 'send_heartbeat'));

        // Clean up expired circuit breaker data
        add_action('init', array($instance, 'cleanup_circuit_breaker'));
    }

    /**
     * Check if this is a new activation and track it.
     */
    public function check_activation()
    {
        // Get the activation flag option
        $activation_flag = get_option('wc_vibe_activation_pending', false);

        // If this is a fresh activation or we haven't sent the activation event yet
        if ($activation_flag === 'pending') {
            // Use transient lock to prevent duplicate activation tracking
            if (get_transient(self::ACTIVATION_LOCK_KEY)) {
                $this->log('Activation already in progress, skipping duplicate');
                return;
            }

            // Set lock for 5 minutes
            set_transient(self::ACTIVATION_LOCK_KEY, true, 5 * MINUTE_IN_SECONDS);

            // Track the activation
            $this->track_activation();

            // Clear the activation flag
            update_option('wc_vibe_activation_pending', 'completed');

            // Remove the lock
            delete_transient(self::ACTIVATION_LOCK_KEY);

            // Log the activation check
            $this->log('Activation event triggered from plugins_loaded hook');
        }
    }

    /**
     * Register the heartbeat event with WP Cron.
     */
    public function register_heartbeat()
    {
        if (!wp_next_scheduled(self::HEARTBEAT_EVENT)) {
            // Schedule daily heartbeat for fresh data
            wp_schedule_event(time(), 'daily', self::HEARTBEAT_EVENT);
        }
    }

    /**
     * Track plugin activation.
     * This is also called from the main plugin file directly.
     */
    public function track_activation()
    {
        $this->log('Track activation method called');
        $this->send_tracking_data('activation', true); // Force fresh data for activation
    }

    /**
     * Track plugin deactivation.
     */
    public function track_deactivation()
    {
        $this->log('Track deactivation method called');
        $this->send_tracking_data('deactivation');

        // Clear tracking cache since plugin is being deactivated
        delete_transient('wc_vibe_tracking_data');
        delete_option(self::TRACKING_CACHE_OPTION);
    }

    /**
     * Send heartbeat ping.
     */
    public function send_heartbeat()
    {
        $this->log('Sending heartbeat ping');
        $this->send_tracking_data('heartbeat', true); // Force fresh data for heartbeat
        update_option(self::LAST_HEARTBEAT_OPTION, time());
    }

    /**
     * Get basic site information.
     *
     * @param bool $force_fresh Whether to force fresh data collection.
     * @return array Site information.
     */
    private function get_tracking_data($force_fresh = false)
    {
        // For daily heartbeats and tracking, always get fresh data
        if (!$force_fresh) {
            // Use cached data if available (less than 1 hour old for non-heartbeat calls)
            $cached_data = get_transient('wc_vibe_tracking_data');
            if ($cached_data !== false) {
                return $cached_data;
            }
        }

        // Batch option reads for efficiency
        $options = $this->get_batch_options(array(
            'admin_email',
            'blogname',
            'woocommerce_vibe_settings'
        ));

        // Basic site data that doesn't require expensive operations
        $data = array(
            'url' => get_site_url(),
            'name' => $options['blogname'] ?: get_bloginfo('name'),
            'email' => $options['admin_email'],
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'is_active' => true,
            'timestamp' => time(),
        );

        // Get WooCommerce version more reliably
        if (defined('WC_VERSION')) {
            $data['wc_version'] = WC_VERSION;
        } elseif (function_exists('WC')) {
            $wc = WC();
            $data['wc_version'] = $wc->version ?? 'unknown';
        } else {
            $data['wc_version'] = 'unknown';
        }

        // Plugin version from constant
        $data['plugin_version'] = defined('WC_VIBE_VERSION') ? WC_VIBE_VERSION : '1.2.2';

        // Add gateway configuration status
        if ($options['woocommerce_vibe_settings']) {
            $settings = $options['woocommerce_vibe_settings'];
            $data['gateway_enabled'] = isset($settings['enabled']) ? ($settings['enabled'] === 'yes') : false;
            $data['has_api_key'] = !empty($settings['api_key']);
        }

        // Cache for 1 hour using transients (more efficient than options)
        set_transient('wc_vibe_tracking_data', $data, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Get API key from options with validation.
     *
     * @return string|bool The API key or false if not set or invalid.
     */
    private function get_api_key()
    {
        // Try to get it from options first
        $api_key = get_option('wc_vibe_api_key', false);

        // If not in options, try to get from WooCommerce settings
        if (!$api_key) {
            // Get Vibe gateway settings
            $settings = get_option('woocommerce_vibe_settings');
            if ($settings && isset($settings['api_key'])) {
                $api_key = $settings['api_key'];
            }
        }

        // Validate API key format (basic validation)
        if ($api_key && !$this->validate_api_key($api_key)) {
            $this->log('Invalid API key format detected');
            return false;
        }

        return $api_key;
    }

    /**
     * Validate API key format.
     *
     * @param string $api_key The API key to validate.
     * @return bool True if valid format.
     */
    private function validate_api_key($api_key)
    {
        // Basic validation - should be non-empty string with reasonable length
        if (!is_string($api_key) || strlen($api_key) < 10 || strlen($api_key) > 100) {
            return false;
        }

        // Check for obvious invalid patterns
        if (preg_match('/^(test|demo|example|placeholder)$/i', $api_key)) {
            return false;
        }

        return true;
    }

    /**
     * Batch option reads for efficiency.
     *
     * @param array $option_names Array of option names to retrieve.
     * @return array Associative array of option values.
     */
    private function get_batch_options($option_names)
    {
        $options = array();
        foreach ($option_names as $name) {
            $options[$name] = get_option($name, null);
        }
        return $options;
    }

    /**
     * Send tracking data to the API.
     *
     * @param string $event The event type (activation, deactivation, or heartbeat).
     * @param bool $force_fresh Whether to force fresh data collection.
     */
    private function send_tracking_data($event, $force_fresh = false)
    {
        // Don't track if WordPress is installing
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            $this->log("Skipping tracking during WordPress installation");
            return;
        }

        // Check circuit breaker
        if ($this->is_circuit_breaker_open()) {
            $this->log("Circuit breaker is open, skipping tracking");
            return;
        }

        // Don't track if no API key is configured
        $api_key = $this->get_api_key();
        if (!$api_key) {
            $this->log("No API key found, skipping tracking");
            return;
        }

        $data = $this->get_tracking_data($force_fresh);
        $data['event'] = $event;

        // Set active status based on event
        if ($event === 'deactivation' || $event === 'uninstall') {
            $data['is_active'] = false;
        }

        $this->log("Sending $event tracking data: " . wp_json_encode($data));

        // Use retry logic with exponential backoff
        $this->send_request_with_retry($data, $api_key);
    }

    /**
     * Send an HTTP request to the tracking API with retry logic.
     * 
     * @param array $data The data to send.
     * @param string $api_key The API key to use.
     */
    private function send_request_with_retry($data, $api_key)
    {
        $attempt = 0;
        $max_attempts = self::MAX_RETRY_ATTEMPTS;
        
        while ($attempt < $max_attempts) {
            $attempt++;
            $success = $this->send_request($data, $api_key, $attempt);
            
            if ($success) {
                // Reset circuit breaker on success
                $this->reset_circuit_breaker();
                return;
            }
            
            // Exponential backoff: 1s, 2s, 4s
            if ($attempt < $max_attempts) {
                $delay = pow(2, $attempt - 1);
                $this->log("Retrying in {$delay} seconds (attempt {$attempt}/{$max_attempts})");
                sleep($delay);
            }
        }
        
        // All attempts failed, record failure
        $this->record_circuit_breaker_failure();
        $this->log("All {$max_attempts} attempts failed, giving up");
    }

    /**
     * Send an HTTP request to the tracking API.
     * 
     * @param array $data The data to send.
     * @param string $api_key The API key to use.
     * @param int $attempt Current attempt number.
     * @return bool Success status.
     */
    private function send_request($data, $api_key, $attempt = 1)
    {
        // Use non-blocking requests by default (except for deactivation/uninstall)
        $is_blocking_event = in_array($data['event'], array('deactivation', 'uninstall'));
        $is_debug = defined('WP_DEBUG') && WP_DEBUG;
        
        // Prepare the request
        $args = array(
            'body' => wp_json_encode($data),
            'timeout' => $is_debug ? 15 : 10,
            'blocking' => $is_blocking_event || $is_debug,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
                'Accept' => 'application/json'
            ),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_site_url(),
        );

        $url = $this->api_url . $data['event'];
        $this->log("Sending request to: {$url} (attempt {$attempt})");

        // Send the request
        $response = wp_remote_post($url, $args);

        // Handle the response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("API request error (attempt {$attempt}): {$error_message}");
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Consider 2xx codes as success
        if ($response_code >= 200 && $response_code < 300) {
            $this->log("API success ({$response_code}): {$body}");
            return true;
        } else {
            $this->log("API error ({$response_code}): {$body}");
            return false;
        }
    }

    /**
     * Log debug messages.
     *
     * @param string $message The message to log.
     */
    private function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Vibe Tracker] ' . $message);
        }
    }

    /**
     * Check if circuit breaker is open.
     *
     * @return bool True if circuit breaker is open.
     */
    private function is_circuit_breaker_open()
    {
        $breaker_data = get_transient(self::CIRCUIT_BREAKER_OPTION);
        if (!$breaker_data) {
            return false;
        }
        
        return $breaker_data['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD;
    }

    /**
     * Record a circuit breaker failure.
     */
    private function record_circuit_breaker_failure()
    {
        $breaker_data = get_transient(self::CIRCUIT_BREAKER_OPTION);
        if (!$breaker_data) {
            $breaker_data = array('failures' => 0, 'last_failure' => time());
        }
        
        $breaker_data['failures']++;
        $breaker_data['last_failure'] = time();
        
        // Keep circuit breaker data for 1 hour
        set_transient(self::CIRCUIT_BREAKER_OPTION, $breaker_data, HOUR_IN_SECONDS);
        
        $this->log("Circuit breaker failure recorded: {$breaker_data['failures']}/{" . self::CIRCUIT_BREAKER_THRESHOLD . "}");
    }

    /**
     * Reset circuit breaker on successful request.
     */
    private function reset_circuit_breaker()
    {
        delete_transient(self::CIRCUIT_BREAKER_OPTION);
    }

    /**
     * Clean up expired circuit breaker data.
     */
    public function cleanup_circuit_breaker()
    {
        $breaker_data = get_transient(self::CIRCUIT_BREAKER_OPTION);
        if ($breaker_data && isset($breaker_data['last_failure'])) {
            // Reset if last failure was more than 1 hour ago
            if (time() - $breaker_data['last_failure'] > HOUR_IN_SECONDS) {
                delete_transient(self::CIRCUIT_BREAKER_OPTION);
                $this->log('Circuit breaker reset due to timeout');
            }
        }
    }

    /**
     * Clean up when the plugin is uninstalled.
     */
    public static function cleanup()
    {
        // Clear the scheduled event
        wp_clear_scheduled_hook(self::HEARTBEAT_EVENT);

        // Delete options
        delete_option(self::LAST_HEARTBEAT_OPTION);
        delete_option(self::TRACKING_CACHE_OPTION);
        delete_option('wc_vibe_activation_pending');
        
        // Delete transients
        delete_transient('wc_vibe_tracking_data');
        delete_transient(self::CIRCUIT_BREAKER_OPTION);
        delete_transient(self::ACTIVATION_LOCK_KEY);

        // Send final uninstall event if possible
        $instance = new self();
        $instance->log('Cleanup called - sending uninstall event');
        $instance->send_tracking_data('uninstall', true);
    }
}
