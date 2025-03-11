<?php

/**
 * WC_Gateway_Vibe class
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Vibe Payment Gateway.
 *
 * @class    WC_Gateway_Vibe
 * @version  1.0.0
 */
class WC_Gateway_Vibe extends WC_Payment_Gateway
{

	/**
	 * API endpoint for creating orders.
	 * @var string
	 */
	protected $api_endpoint = 'https://credit.vibe.ir/merchants/api/v1/orders/';

	/**
	 * API endpoint for verifying payments.
	 * @var string
	 */
	protected $verify_endpoint = 'https://credit.vibe.ir/merchants/api/v1/orders/verify';

	/**
	 * API key for authentication.
	 * @var string
	 */
	protected $api_key;

	/**
	 * Unique id for the gateway.
	 * @var string
	 */
	public $id = 'vibe';

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{
		// Load plugin textdomain
		add_action('init', array($this, 'load_plugin_textdomain'));

		// Set gateway position to first
		add_filter('woocommerce_payment_gateways_order', array($this, 'set_gateway_order'), 1);
		
		// Check gateway position and handle accordingly
		add_action('admin_init', array($this, 'check_gateway_position'));
		add_action('admin_notices', array($this, 'display_position_notice'));

		$this->icon               = apply_filters('woocommerce_vibe_gateway_icon', plugins_url('assets/images/vibe-logo.svg', dirname(__FILE__)));
		$this->has_fields         = false;
		$this->supports           = array(
			'products'
		);

		$this->method_title       = _x('Vibe Payment', 'Vibe payment method', 'woocommerce-gateway-vibe');
		$this->method_description = __('Allows payments through Vibe Payment Gateway.', 'woocommerce-gateway-vibe');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->api_key     = $this->get_option('api_key');

		// Add custom styling for the logo
		add_action('wp_head', array($this, 'add_logo_styles'));
		add_action('admin_head', array($this, 'add_logo_styles'));

		// Actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		// Payment listener/API hook
		add_action('woocommerce_api_wc_gateway_vibe', array($this, 'check_payment_response'));
	}

	/**
	 * Load translations.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'woocommerce-gateway-vibe',
			false,
			dirname(dirname(plugin_basename(__FILE__))) . '/i18n/languages/'
		);
	}

	/**
	 * Add custom styles for the logo.
	 */
	public function add_logo_styles()
	{
?>
		<style type="text/css">
			.payment_method_vibe img {
				max-height: 40px;
				width: auto;
			}

			#payment .payment_methods li img.vibe-logo {
				float: right;
				border: 0;
				padding: 0;
				max-height: 40px;
			}
		</style>
<?php
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __('Enable/Disable', 'woocommerce-gateway-vibe'),
				'type'    => 'checkbox',
				'label'   => __('Enable Vibe Payments', 'woocommerce-gateway-vibe'),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __('Title', 'woocommerce-gateway-vibe'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-gateway-vibe'),
				'default'     => _x('Vibe Payment', 'Vibe payment method', 'woocommerce-gateway-vibe'),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __('Description', 'woocommerce-gateway-vibe'),
				'type'        => 'textarea',
				'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce-gateway-vibe'),
				'default'     => __('Pay with Vibe Payment Gateway.', 'woocommerce-gateway-vibe'),
				'desc_tip'    => true,
			),
			'api_key' => array(
				'title'       => __('Merchant API Key', 'woocommerce-gateway-vibe'),
				'type'        => 'password',
				'description' => __('Enter your Vibe Payment Gateway Merchant API Key.', 'woocommerce-gateway-vibe'),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int  $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);

		// Create order in Vibe Payment Gateway
		$response = $this->create_vibe_order($order);

		if (is_wp_error($response)) {
			wc_add_notice($response->get_error_message(), 'error');
			return array(
				'result' => 'failure',
			);
		}

		// Store order ID in session for later retrieval
		WC()->session->set('vibe_order_id', $order_id);

		// Redirect to Vibe Payment Gateway
		return array(
			'result'   => 'success',
			'redirect' => $response['payment_url'],
		);
	}

	/**
	 * Create an order in Vibe Payment Gateway.
	 *
	 * @param  WC_Order $order
	 * @return array|WP_Error
	 */
	protected function create_vibe_order($order)
	{
		// Prepare cart data
		$cart_data = $this->prepare_cart_data($order);

		// Make API request
		$response = wp_remote_post(
			$this->api_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $this->api_key,
				),
				'body'    => wp_json_encode($cart_data),
				'timeout' => 60,
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (wp_remote_retrieve_response_code($response) !== 200) {
			$error_message = isset($data['detail']) ? $this->format_error_message($data['detail']) : __('Unknown error occurred while processing the payment.', 'woocommerce-gateway-vibe');
			return new WP_Error('vibe_api_error', $error_message);
		}

		if (! isset($data['payment_url'])) {
			return new WP_Error('vibe_api_error', __('Invalid response from Vibe Payment Gateway.', 'woocommerce-gateway-vibe'));
		}

		return $data;
	}

	/**
	 * Format error message from API response.
	 *
	 * @param  array $details
	 * @return string
	 */
	protected function format_error_message($details)
	{
		$message = __('Vibe Payment Gateway error: ', 'woocommerce-gateway-vibe');

		if (is_array($details)) {
			foreach ($details as $detail) {
				if (isset($detail['msg'])) {
					$message .= $detail['msg'] . ' ';
				}
			}
		} else {
			$message .= $details;
		}

		return $message;
	}

	/**
	 * Prepare cart data for Vibe Payment Gateway API.
	 *
	 * @param  WC_Order $order
	 * @return array
	 */
	protected function prepare_cart_data($order)
	{
		$items = array();
		$goods_amount = 0;

		// Add line items
		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$price = $order->get_line_subtotal($item, false, false);
			$discount = $order->get_line_subtotal($item, false, false) - $order->get_line_total($item, false, false);

			$items[] = array(
				'id'       => $product ? (string) $product->get_id() : (string) $item_id,
				'name'     => $item->get_name(),
				'price'    => (int) wc_format_decimal($price, 0),
				'discount' => (int) wc_format_decimal($discount, 0),
				'quantity' => (int) $item->get_quantity(),
			);

			$goods_amount += $price;
		}

		// Calculate tax
		$tax = (int) wc_format_decimal($order->get_total_tax(), 0);

		// Generate callback URL
		$callback_url = add_query_arg('wc-api', 'wc_gateway_vibe', home_url('/'));

		// Prepare data
		$data = array(
			'callback_url' => $callback_url,
			'cart_amount'  => (int) wc_format_decimal($order->get_total(), 0),
			'data'         => array(
				'goods_amount' => (int) wc_format_decimal($goods_amount, 0),
				'items'        => $items,
				'tax'          => $tax,
			),
			'order_id'     => (string) $order->get_id(),
		);

		return $data;
	}

	/**
	 * Check for Vibe Payment Gateway response.
	 */
	public function check_payment_response()
	{
		if (! isset($_GET['result']) || ! isset($_GET['ref_id'])) {
			wp_die(__('Invalid response from Vibe Payment Gateway.', 'woocommerce-gateway-vibe'), __('Payment Error', 'woocommerce-gateway-vibe'), array('response' => 500));
		}

		$result = sanitize_text_field(wp_unslash($_GET['result']));
		$ref_id = sanitize_text_field(wp_unslash($_GET['ref_id']));

		// Verify payment
		$verified = $this->verify_payment($ref_id);

		if ($verified) {
			// Get order from session
			$order_id = WC()->session->get('vibe_order_id');
			$order = wc_get_order($order_id);

			if (! $order) {
				wp_die(__('Order not found.', 'woocommerce-gateway-vibe'), __('Payment Error', 'woocommerce-gateway-vibe'), array('response' => 500));
			}

			// Mark order as complete
			$order->payment_complete();
			/* translators: %s: Reference ID from Vibe Payment Gateway */
			$order->add_order_note(sprintf(__('Vibe payment completed. Reference ID: %s', 'woocommerce-gateway-vibe'), $ref_id));

			// Empty cart
			WC()->cart->empty_cart();

			// Redirect to thank you page
			wp_redirect($this->get_return_url($order));
			exit;
		} else {
			// Get order from session
			$order_id = WC()->session->get('vibe_order_id');
			$order = wc_get_order($order_id);

			if ($order) {
				$order->update_status('failed', __('Payment failed or was declined.', 'woocommerce-gateway-vibe'));
			}

			wc_add_notice(__('Payment failed or was declined.', 'woocommerce-gateway-vibe'), 'error');
			wp_redirect(wc_get_checkout_url());
			exit;
		}
	}

	/**
	 * Verify payment with Vibe Payment Gateway.
	 *
	 * @param  string $ref_id
	 * @return bool
	 */
	protected function verify_payment($ref_id)
	{
		$response = wp_remote_post(
			$this->verify_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $this->api_key,
				),
				'body'    => wp_json_encode(array('ref_id' => $ref_id)),
				'timeout' => 60,
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (wp_remote_retrieve_response_code($response) !== 200) {
			return false;
		}

		return isset($data['status']) && $data['status'] === true;
	}

	/**
	 * Check if Vibe gateway is the first payment option.
	 */
	public function check_gateway_position() {
		if (!is_admin()) {
			return;
		}

		// Skip check if we've already shown the notice recently
		if (get_transient('wc_gateway_vibe_position_check_cooldown')) {
			return;
		}

		// Get all available gateways
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		
		if (empty($available_gateways)) {
			return;
		}

		// Get the first gateway
		reset($available_gateways);
		$first_gateway = key($available_gateways);

		// If Vibe is not the first gateway, disable it
		if ($first_gateway !== $this->id) {
			$this->update_option('enabled', 'no');
			
			// Set a flag to show the notice, but only if we haven't shown it before
			if (!get_option('wc_gateway_vibe_position_warning_shown')) {
				update_option('wc_gateway_vibe_position_warning', true);
				update_option('wc_gateway_vibe_position_warning_shown', true);
			}
			
			// Set a cooldown to prevent checking too frequently
			set_transient('wc_gateway_vibe_position_check_cooldown', true, DAY_IN_SECONDS);
		} else {
			// If it's now the first gateway, remove all warnings and flags
			delete_option('wc_gateway_vibe_position_warning');
			delete_option('wc_gateway_vibe_position_warning_shown');
			delete_transient('wc_gateway_vibe_position_check_cooldown');
		}
	}

	/**
	 * Display admin notice if Vibe is not the first payment option.
	 */
	public function display_position_notice() {
		if (!is_admin() || !get_option('wc_gateway_vibe_position_warning')) {
			return;
		}

		$message = sprintf(
			/* translators: %1$s: Gateway name, %2$s: WooCommerce payment settings URL */
			__('%1$s has been disabled because it must be the first payment option. Please reorder your payment gateways <a href="%2$s">here</a> to make %1$s the first option.', 'woocommerce-gateway-vibe'),
			'Vibe Payment',
			admin_url('admin.php?page=wc-settings&tab=checkout')
		);

		echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($message) . '</p></div>';
		
		// Add a dismiss button handler
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$(document).on('click', '.notice-error.is-dismissible .notice-dismiss', function() {
				$.ajax({
					url: ajaxurl,
					data: {
						action: 'dismiss_vibe_position_notice',
						nonce: '<?php echo wp_create_nonce('dismiss_vibe_position_notice'); ?>'
					}
				});
			});
		});
		</script>
		<?php
		
		// Add AJAX handler for dismiss button
		add_action('wp_ajax_dismiss_vibe_position_notice', array($this, 'dismiss_position_notice'));
	}
	
	/**
	 * AJAX handler to dismiss the position notice.
	 */
	public function dismiss_position_notice() {
		// Verify nonce
		if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'dismiss_vibe_position_notice')) {
			wp_die(__('Security check failed.', 'woocommerce-gateway-vibe'));
		}
		
		// Remove the warning flag
		delete_option('wc_gateway_vibe_position_warning');
		
		wp_die();
	}

	/**
	 * Set the gateway order to first position.
	 *
	 * @param array $ordered_gateways Ordered payment gateways.
	 * @return array
	 */
	public function set_gateway_order($ordered_gateways) {
		// Remove vibe from current position if it exists
		if (($key = array_search($this->id, $ordered_gateways)) !== false) {
			unset($ordered_gateways[$key]);
		}
		
		// Add vibe to the beginning of the array
		array_unshift($ordered_gateways, $this->id);
		
		return $ordered_gateways;
	}
}
