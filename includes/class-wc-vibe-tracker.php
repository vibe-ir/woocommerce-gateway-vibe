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
class WC_Vibe_Tracker {

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
     * Initialize the tracker.
     */
    public static function init() {
        $instance = new self();
        
        // Register activation hook - using the constant defined in main plugin file
        register_activation_hook(WC_VIBE_PLUGIN_FILE, array($instance, 'track_activation'));
        
        // Register deactivation hook
        register_deactivation_hook(WC_VIBE_PLUGIN_FILE, array($instance, 'track_deactivation'));
        
        // Register the heartbeat event
        add_action('init', array($instance, 'register_heartbeat'));
        
        // Handle the heartbeat event
        add_action(self::HEARTBEAT_EVENT, array($instance, 'send_heartbeat'));
    }

    /**
     * Register the heartbeat event with WP Cron.
     */
    public function register_heartbeat() {
        if (!wp_next_scheduled(self::HEARTBEAT_EVENT)) {
            // Schedule weekly heartbeat
            wp_schedule_event(time(), 'weekly', self::HEARTBEAT_EVENT);
        }
    }

    /**
     * Track plugin activation.
     */
    public function track_activation() {
        $this->send_tracking_data('activation');
    }

    /**
     * Track plugin deactivation.
     */
    public function track_deactivation() {
        $this->send_tracking_data('deactivation');
        
        // Update status to inactive (in case the deactivation request fails)
        $cached_data = get_option(self::TRACKING_CACHE_OPTION);
        if ($cached_data && isset($cached_data['data'])) {
            $cached_data['data']['is_active'] = false;
            update_option(self::TRACKING_CACHE_OPTION, $cached_data);
        }
    }

    /**
     * Send heartbeat ping.
     */
    public function send_heartbeat() {
        $this->send_tracking_data('heartbeat');
        update_option(self::LAST_HEARTBEAT_OPTION, time());
    }

    /**
     * Get basic site information.
     *
     * @return array Site information.
     */
    private function get_tracking_data() {
        // Use cached data if available (less than 6 hours old)
        $cached_data = get_option(self::TRACKING_CACHE_OPTION);
        if ($cached_data && isset($cached_data['timestamp']) && (time() - $cached_data['timestamp']) < 6 * HOUR_IN_SECONDS) {
            return $cached_data['data'];
        }

        // Basic site data that doesn't require expensive operations
        $data = array(
            'url' => get_site_url(),
            'name' => get_bloginfo('name'),
            'email' => get_option('admin_email'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'is_active' => true,
        );
        
        // Get WooCommerce version if available
        global $woocommerce;
        if ($woocommerce && isset($woocommerce->version)) {
            $data['wc_version'] = $woocommerce->version;
        } else {
            $data['wc_version'] = 'unknown';
        }
        
        // Plugin version from constant
        $data['plugin_version'] = defined('WC_VIBE_VERSION') ? WC_VIBE_VERSION : '1.0.0';

        // Save to cache with timestamp
        update_option(self::TRACKING_CACHE_OPTION, array(
            'timestamp' => time(),
            'data' => $data
        ));

        return $data;
    }

    /**
     * Get API key from options.
     *
     * @return string|bool The API key or false if not set.
     */
    private function get_api_key() {
        // Try to get it from options first
        $api_key = get_option('vibe_api_key', false);
        
        // If not in options, try to get from WooCommerce settings
        if (!$api_key) {
            // Get Vibe gateway settings
            $settings = get_option('woocommerce_vibe_settings');
            if ($settings && isset($settings['api_key'])) {
                $api_key = $settings['api_key'];
            }
        }
        
        return $api_key;
    }

    /**
     * Send tracking data to the API.
     *
     * @param string $event The event type (activation, deactivation, or heartbeat).
     */
    private function send_tracking_data($event) {
        // Don't track if WordPress is installing
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return;
        }
        
        // Don't track if no API key is configured
        $api_key = $this->get_api_key();
        if (!$api_key) {
            return;
        }

        $data = $this->get_tracking_data();
        $data['event'] = $event;
        
        // Set active status based on event
        if ($event === 'deactivation' || $event === 'uninstall') {
            $data['is_active'] = false;
        }

        // Use non-blocking request for better performance
        $this->send_non_blocking_request($data, $api_key);
    }

    /**
     * Send a non-blocking HTTP request to the tracking API.
     * 
     * @param array $data The data to send.
     * @param string $api_key The API key to use.
     */
    private function send_non_blocking_request($data, $api_key) {
        // Prepare the request with minimal timeout for performance
        $args = array(
            'body' => json_encode($data),
            'timeout' => 0.01,
            'blocking' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
                'Accept' => 'application/json'
            ),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_site_url(),
        );

        // Send the request
        wp_remote_post($this->api_url . $data['event'], $args);
    }

    /**
     * Clean up when the plugin is uninstalled.
     */
    public static function cleanup() {
        // Clear the scheduled event
        wp_clear_scheduled_hook(self::HEARTBEAT_EVENT);
        
        // Delete options
        delete_option(self::LAST_HEARTBEAT_OPTION);
        delete_option(self::TRACKING_CACHE_OPTION);
        
        // Send final uninstall event if possible
        $instance = new self();
        $instance->send_tracking_data('uninstall');
    }
} 