<?php

/**
 * Vibe Performance Monitor Class
 *
 * Enterprise-grade performance monitoring and alerting system for
 * detecting memory issues, slow queries, and performance bottlenecks.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.3.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Performance Monitor for Vibe Dynamic Pricing
 *
 * @class    WC_Vibe_Performance_Monitor
 * @version  1.3.0
 */
class WC_Vibe_Performance_Monitor {

    /**
     * Performance metrics storage.
     *
     * @var array
     */
    private static $metrics = array();

    /**
     * Memory usage checkpoints.
     *
     * @var array
     */
    private static $memory_checkpoints = array();

    /**
     * Query performance tracking.
     *
     * @var array
     */
    private static $query_metrics = array();

    /**
     * Performance thresholds.
     *
     * @var array
     */
    private static $thresholds = array(
        'memory_warning' => 100 * 1024 * 1024,  // 100MB  
        'memory_critical' => 120 * 1024 * 1024, // 120MB
        'execution_warning' => 2.0,             // 2 seconds
        'execution_critical' => 5.0,            // 5 seconds
        'query_warning' => 0.1,                 // 100ms
        'query_critical' => 0.5                 // 500ms
    );

    /**
     * Alert flags to prevent spam.
     *
     * @var array
     */
    private static $alert_sent = array();

    /**
     * Initialize performance monitoring.
     */
    public static function init() {
        // Hook into WordPress to monitor performance
        add_action('init', array(__CLASS__, 'start_monitoring'), 1);
        add_action('wp_footer', array(__CLASS__, 'output_performance_summary'));
        add_action('admin_footer', array(__CLASS__, 'output_performance_summary'));
        
        // Monitor database queries
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_filter('query', array(__CLASS__, 'monitor_query'), 10, 1);
        }

        // Schedule performance cleanup
        if (!wp_next_scheduled('vibe_performance_cleanup')) {
            wp_schedule_event(time(), 'daily', 'vibe_performance_cleanup');
        }
        add_action('vibe_performance_cleanup', array(__CLASS__, 'cleanup_old_metrics'));
    }

    /**
     * Start monitoring session.
     */
    public static function start_monitoring() {
        self::checkpoint('session_start');
        
        // Register shutdown function for final metrics
        register_shutdown_function(array(__CLASS__, 'final_checkpoint'));
    }

    /**
     * Create a performance checkpoint.
     *
     * @param string $checkpoint_name Name of the checkpoint.
     * @param array $additional_data Additional data to store.
     */
    public static function checkpoint($checkpoint_name, $additional_data = array()) {
        $timestamp = microtime(true);
        $memory_usage = memory_get_usage(true);
        $peak_memory = memory_get_peak_usage(true);

        self::$memory_checkpoints[$checkpoint_name] = array(
            'timestamp' => $timestamp,
            'memory_usage' => $memory_usage,
            'peak_memory' => $peak_memory,
            'additional_data' => $additional_data
        );

        // Check for memory warnings
        self::check_memory_usage($checkpoint_name, $memory_usage, $peak_memory);
    }

    /**
     * Time a function or code block execution.
     *
     * @param string $operation_name Name of the operation.
     * @param callable $callback Function to execute and time.
     * @param array $context Additional context data.
     * @return mixed Result from callback execution.
     */
    public static function time_operation($operation_name, $callback, $context = array()) {
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        try {
            $result = call_user_func($callback);
            $success = true;
            $error = null;
        } catch (Exception $e) {
            $result = null;
            $success = false;
            $error = $e->getMessage();
        }
        
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        
        $execution_time = $end_time - $start_time;
        $memory_delta = $end_memory - $start_memory;
        
        // Store metrics
        self::$metrics[$operation_name] = array(
            'execution_time' => $execution_time,
            'memory_delta' => $memory_delta,
            'start_memory' => $start_memory,
            'end_memory' => $end_memory,
            'success' => $success,
            'error' => $error,
            'context' => $context,
            'timestamp' => $start_time
        );

        // Check performance thresholds
        self::check_execution_performance($operation_name, $execution_time, $memory_delta);

        return $result;
    }

    /**
     * Monitor database queries.
     *
     * @param string $query SQL query.
     * @return string SQL query (unchanged).
     */
    public static function monitor_query($query) {
        // Only monitor Vibe-related queries
        if (strpos($query, 'vibe_pricing') !== false || strpos($query, 'vibe_') !== false) {
            $start_time = microtime(true);
            
            // Register query completion hook
            add_action('shutdown', function() use ($query, $start_time) {
                $execution_time = microtime(true) - $start_time;
                
                self::$query_metrics[] = array(
                    'query' => substr($query, 0, 200), // First 200 chars
                    'execution_time' => $execution_time,
                    'timestamp' => $start_time
                );

                // Check query performance
                self::check_query_performance($query, $execution_time);
            });
        }

        return $query;
    }

    /**
     * Check memory usage against thresholds.
     *
     * @param string $checkpoint Checkpoint name.
     * @param int $current_memory Current memory usage.
     * @param int $peak_memory Peak memory usage.
     */
    private static function check_memory_usage($checkpoint, $current_memory, $peak_memory) {
        $memory_to_check = max($current_memory, $peak_memory);
        
        if ($memory_to_check > self::$thresholds['memory_critical']) {
            self::send_alert('memory_critical', array(
                'checkpoint' => $checkpoint,
                'memory_usage' => $memory_to_check,
                'formatted_memory' => size_format($memory_to_check),
                'threshold' => self::$thresholds['memory_critical']
            ));
        } elseif ($memory_to_check > self::$thresholds['memory_warning']) {
            self::send_alert('memory_warning', array(
                'checkpoint' => $checkpoint,
                'memory_usage' => $memory_to_check,
                'formatted_memory' => size_format($memory_to_check),
                'threshold' => self::$thresholds['memory_warning']
            ));
        }
    }

    /**
     * Check execution performance against thresholds.
     *
     * @param string $operation Operation name.
     * @param float $execution_time Execution time in seconds.
     * @param int $memory_delta Memory usage change.
     */
    private static function check_execution_performance($operation, $execution_time, $memory_delta) {
        if ($execution_time > self::$thresholds['execution_critical']) {
            self::send_alert('execution_critical', array(
                'operation' => $operation,
                'execution_time' => $execution_time,
                'memory_delta' => $memory_delta,
                'threshold' => self::$thresholds['execution_critical']
            ));
        } elseif ($execution_time > self::$thresholds['execution_warning']) {
            self::send_alert('execution_warning', array(
                'operation' => $operation,
                'execution_time' => $execution_time,
                'memory_delta' => $memory_delta,
                'threshold' => self::$thresholds['execution_warning']
            ));
        }
    }

    /**
     * Check query performance against thresholds.
     *
     * @param string $query SQL query.
     * @param float $execution_time Query execution time.
     */
    private static function check_query_performance($query, $execution_time) {
        if ($execution_time > self::$thresholds['query_critical']) {
            self::send_alert('query_critical', array(
                'query' => substr($query, 0, 100),
                'execution_time' => $execution_time,
                'threshold' => self::$thresholds['query_critical']
            ));
        } elseif ($execution_time > self::$thresholds['query_warning']) {
            self::send_alert('query_warning', array(
                'query' => substr($query, 0, 100),
                'execution_time' => $execution_time,
                'threshold' => self::$thresholds['query_warning']
            ));
        }
    }

    /**
     * Send performance alert.
     *
     * @param string $alert_type Type of alert.
     * @param array $data Alert data.
     */
    private static function send_alert($alert_type, $data) {
        // Prevent alert spam
        $alert_key = $alert_type . '_' . ($data['operation'] ?? $data['checkpoint'] ?? 'global');
        if (isset(self::$alert_sent[$alert_key]) && 
            (time() - self::$alert_sent[$alert_key]) < 300) { // 5 minutes
            return;
        }

        self::$alert_sent[$alert_key] = time();

        // Log the alert
        error_log(sprintf(
            '[Vibe Performance Alert] %s: %s',
            strtoupper($alert_type),
            json_encode($data)
        ));

        // Store alert in database for admin review
        self::store_performance_alert($alert_type, $data);

        // Send admin notice if in admin area
        if (is_admin() && current_user_can('manage_options')) {
            add_action('admin_notices', function() use ($alert_type, $data) {
                $class = strpos($alert_type, 'critical') !== false ? 'error' : 'warning';
                echo '<div class="notice notice-' . $class . '"><p>';
                echo '<strong>Vibe Plugin Performance Alert:</strong> ';
                echo self::format_alert_message($alert_type, $data);
                echo '</p></div>';
            });
        }
    }

    /**
     * Format alert message for display.
     *
     * @param string $alert_type Alert type.
     * @param array $data Alert data.
     * @return string Formatted message.
     */
    private static function format_alert_message($alert_type, $data) {
        switch ($alert_type) {
            case 'memory_critical':
            case 'memory_warning':
                return sprintf(
                    'High memory usage detected at checkpoint "%s": %s (threshold: %s)',
                    $data['checkpoint'],
                    $data['formatted_memory'],
                    size_format($data['threshold'])
                );

            case 'execution_critical':
            case 'execution_warning':
                return sprintf(
                    'Slow operation detected "%s": %.3fs (threshold: %.1fs)',
                    $data['operation'],
                    $data['execution_time'],
                    $data['threshold']
                );

            case 'query_critical':
            case 'query_warning':
                return sprintf(
                    'Slow query detected: %.3fs (threshold: %.1fs) - %s...',
                    $data['execution_time'],
                    $data['threshold'],
                    substr($data['query'], 0, 50)
                );

            default:
                return 'Unknown performance issue detected';
        }
    }

    /**
     * Store performance alert in database.
     *
     * @param string $alert_type Alert type.
     * @param array $data Alert data.
     */
    private static function store_performance_alert($alert_type, $data) {
        $alerts = get_option('wc_vibe_performance_alerts', array());
        
        $alerts[] = array(
            'type' => $alert_type,
            'data' => $data,
            'timestamp' => time(),
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        );

        // Keep only last 100 alerts
        if (count($alerts) > 100) {
            $alerts = array_slice($alerts, -100);
        }

        update_option('wc_vibe_performance_alerts', $alerts);
    }

    /**
     * Get performance summary.
     *
     * @return array Performance summary data.
     */
    public static function get_performance_summary() {
        $summary = array(
            'checkpoints' => self::$memory_checkpoints,
            'operations' => self::$metrics,
            'queries' => self::$query_metrics,
            'thresholds' => self::$thresholds,
            'alerts_sent' => count(self::$alert_sent)
        );

        // Calculate session totals
        if (isset(self::$memory_checkpoints['session_start'])) {
            $start = self::$memory_checkpoints['session_start'];
            $current_memory = memory_get_usage(true);
            $peak_memory = memory_get_peak_usage(true);
            
            $summary['session'] = array(
                'duration' => microtime(true) - $start['timestamp'],
                'memory_growth' => $current_memory - $start['memory_usage'],
                'peak_memory' => $peak_memory,
                'current_memory' => $current_memory
            );
        }

        return $summary;
    }

    /**
     * Output performance summary for debugging.
     */
    public static function output_performance_summary() {
        if (!current_user_can('manage_options') || 
            !get_option('wc_vibe_enable_performance_monitoring', false)) {
            return;
        }

        $summary = self::get_performance_summary();
        
        echo "<!-- VIBE PERFORMANCE MONITOR -->\n";
        echo "<script>console.group('Vibe Performance Monitor');\n";
        echo "console.log('Performance Summary:', " . json_encode($summary) . ");\n";
        
        if (!empty(self::$metrics)) {
            echo "console.table(" . json_encode(self::$metrics) . ");\n";
        }
        
        echo "console.groupEnd();</script>\n";
        echo "<!-- END VIBE PERFORMANCE MONITOR -->\n";
    }

    /**
     * Final checkpoint before script termination.
     */
    public static function final_checkpoint() {
        self::checkpoint('session_end');
    }

    /**
     * Clean up old performance metrics.
     */
    public static function cleanup_old_metrics() {
        // Clean up old alerts (keep only last 7 days)
        $alerts = get_option('wc_vibe_performance_alerts', array());
        $week_ago = time() - (7 * 24 * 60 * 60);
        
        $alerts = array_filter($alerts, function($alert) use ($week_ago) {
            return $alert['timestamp'] > $week_ago;
        });
        
        update_option('wc_vibe_performance_alerts', array_values($alerts));
    }

    /**
     * Get performance alerts for admin review.
     *
     * @param int $limit Number of alerts to retrieve.
     * @return array Recent performance alerts.
     */
    public static function get_recent_alerts($limit = 20) {
        $alerts = get_option('wc_vibe_performance_alerts', array());
        return array_slice($alerts, -$limit);
    }

    /**
     * Set performance thresholds.
     *
     * @param array $thresholds New threshold values.
     */
    public static function set_thresholds($thresholds) {
        self::$thresholds = array_merge(self::$thresholds, $thresholds);
    }

    /**
     * Reset performance metrics.
     */
    public static function reset_metrics() {
        self::$metrics = array();
        self::$memory_checkpoints = array();
        self::$query_metrics = array();
        self::$alert_sent = array();
    }
}