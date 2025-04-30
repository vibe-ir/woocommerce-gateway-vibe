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
            // Track the activation
            $this->track_activation();

            // Clear the activation flag
            update_option('wc_vibe_activation_pending', 'completed');

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
            // Schedule weekly heartbeat
            wp_schedule_event(time(), 'weekly', self::HEARTBEAT_EVENT);
        }
    }

    /**
     * Track plugin activation.
     * This is also called from the main plugin file directly.
     */
    public function track_activation()
    {
        $this->log('Track activation method called');
        $this->send_tracking_data('activation');
    }

    /**
     * Track plugin deactivation.
     */
    public function track_deactivation()
    {
        $this->log('Track deactivation method called');
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
    public function send_heartbeat()
    {
        $this->log('Sending heartbeat ping');
        $this->send_tracking_data('heartbeat');
        update_option(self::LAST_HEARTBEAT_OPTION, time());
    }

    /**
     * Get basic site information.
     *
     * @return array Site information.
     */
    private function get_tracking_data()
    {
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
        $data['plugin_version'] = defined('WC_VIBE_VERSION') ? WC_VIBE_VERSION : '1.0.1';

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
    private function get_api_key()
    {
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
    private function send_tracking_data($event)
    {
        // Don't track if WordPress is installing
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            $this->log("Skipping tracking during WordPress installation");
            return;
        }

        // Don't track if no API key is configured
        $api_key = $this->get_api_key();
        if (!$api_key) {
            $this->log("No API key found, skipping tracking");
            return;
        }

        $data = $this->get_tracking_data();
        $data['event'] = $event;

        // Set active status based on event
        if ($event === 'deactivation' || $event === 'uninstall') {
            $data['is_active'] = false;
        }

        $this->log("Sending $event tracking data: " . wp_json_encode($data));

        // Use a more reliable request method
        $this->send_request($data, $api_key);
    }

    /**
     * Send an HTTP request to the tracking API.
     * 
     * @param array $data The data to send.
     * @param string $api_key The API key to use.
     */
    private function send_request($data, $api_key)
    {
        // In debug mode, use blocking requests with longer timeout
        $is_debug = defined('WP_DEBUG') && WP_DEBUG;

        // Prepare the request
        $args = array(
            'body' => wp_json_encode($data),
            'timeout' => $is_debug ? 10 : 5,
            'blocking' => $is_debug, // Only wait for response in debug mode
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
                'Accept' => 'application/json'
            ),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_site_url(),
        );

        $this->log("Sending request to: " . $this->api_url . $data['event']);

        // Send the request
        $response = wp_remote_post($this->api_url . $data['event'], $args);

        // Log the result
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("API request error: $error_message");
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $this->log("API response ($response_code): $body");
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

        // Send final uninstall event if possible
        $instance = new self();
        $instance->log('Cleanup called - sending uninstall event');
        $instance->send_tracking_data('uninstall');
    }
}
