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
	 * Logger instance.
	 * @var WC_Logger
	 */
	private $logger = null;

	/**
	 * Debug mode.
	 * @var bool
	 */
	private $debug_mode = false;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{
		$this->icon               = apply_filters('woocommerce_vibe_gateway_icon', plugins_url('assets/images/vibe-logo.svg', dirname(__FILE__)));
		$this->has_fields         = false;
		$this->supports           = array(
			'products'
		);

		$this->method_title       = __('Vibe Payment', 'woocommerce-gateway-vibe');
		$this->method_description = __('Allows payments through Vibe Payment Gateway.', 'woocommerce-gateway-vibe');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->api_key     = $this->get_option('api_key');
		$this->debug_mode  = 'yes' === $this->get_option('debug_mode', 'no');

		// Add custom styling for the logo
		add_action('wp_head', array($this, 'add_logo_styles'));
		add_action('admin_head', array($this, 'add_logo_styles'));

		// Actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
		add_action('woocommerce_api_wc_gateway_vibe', array($this, 'check_payment_response'));
	}

	/**
	 * Add custom styles for the logo.
	 */
	public function add_logo_styles()
	{
?>
		<style type="text/css">
			.payment_method_vibe img {
				max-height: 30px;
				width: auto;
			}

			#payment .payment_methods li img.vibe-logo {
				float: right;
				border: 0;
				padding: 0;
				max-height: 30px;
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
				'default'     => __('Vibe Payment', 'woocommerce-gateway-vibe'),
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
			'debug_mode' => array(
				'title'       => __('Debug Mode', 'woocommerce-gateway-vibe'),
				'type'        => 'checkbox',
				'label'       => __('Enable logging for debugging', 'woocommerce-gateway-vibe'),
				'default'     => 'no',
				'description' => __('Log payment gateway events to help troubleshoot issues.', 'woocommerce-gateway-vibe'),
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

		$this->log('Processing payment for order ' . $order_id);

		// Create order in Vibe Payment Gateway
		$response = $this->create_vibe_order($order);

		if (is_wp_error($response)) {
			$this->log('Error creating Vibe order: ' . $response->get_error_message());
			wc_add_notice($response->get_error_message(), 'error');
			return array(
				'result' => 'failure',
			);
		}

		// Store order ID in session for later retrieval
		WC()->session->set('vibe_order_id', $order_id);

		// Store the payment URL in order meta
		$order->update_meta_data('_vibe_payment_url', $response['payment_url']);
		$order->save();

		$this->log('Customer redirected to Vibe payment page for order ' . $order_id);

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

		// Get the UUID we'll use for the API
		$uuid = $order->get_meta('_vibe_uuid_order_id');

		$this->log('Creating Vibe order with UUID: ' . $uuid . ' for WC order: ' . $order->get_id());
		$this->log('API request data: ' . wp_json_encode($cart_data));

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
			$this->log('API Error: ' . $response->get_error_message());
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		$this->log('Vibe API response for order ' . $order->get_id() . ' (UUID: ' . $uuid . '): ' . wp_json_encode($data));

		if (wp_remote_retrieve_response_code($response) !== 200) {
			$error_message = isset($data['detail']) ? $this->format_error_message($data['detail']) : __('Unknown error occurred while processing the payment.', 'woocommerce-gateway-vibe');
			$this->log('Error response: ' . $error_message);
			return new WP_Error('vibe_api_error', $error_message);
		}

		if (! isset($data['payment_url'])) {
			$this->log('Invalid response: Missing payment_url');
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
	 * Generate UUID v4.
	 *
	 * @return string
	 */
	protected function generate_uuid_v4()
	{
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100 (UUID v4)
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

		// Get the store currency
		$currency = get_woocommerce_currency();
		// Flag to track if we need to convert IRT to IRR
		$currency_needs_conversion = ($currency === 'IRT');

		// Add a debug log for currency
		$this->log('Store currency: ' . $currency . '. Conversion to IRR needed: ' . ($currency_needs_conversion ? 'Yes' : 'No'));

		// Add line items
		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$price = $order->get_line_subtotal($item, false, false);
			$discount = $order->get_line_subtotal($item, false, false) - $order->get_line_total($item, false, false);

			// Convert IRT to IRR if needed (1 Toman = 10 Rials)
			if ($currency_needs_conversion) {
				$price = $price * 10;
				$discount = $discount * 10;
			}

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
		if ($currency_needs_conversion) {
			$tax = $tax * 10;
		}

		// Generate callback URL with order ID
		$callback_url = add_query_arg(
			array(
				'wc-api' => 'wc_gateway_vibe',
				'order_id' => $order->get_id()
			),
			home_url('/')
		);

		// Generate a UUID v4 for the API order_id
		$uuid = $this->generate_uuid_v4();

		// Store the UUID in the order meta data for reference
		$order->update_meta_data('_vibe_uuid_order_id', $uuid);
		$order->save();

		$this->log('Generated UUID v4 for order ' . $order->get_id() . ': ' . $uuid);

		// Get the total and convert if needed
		$total = $order->get_total();
		if ($currency_needs_conversion) {
			$total = $total * 10;
			$this->log('Converted order total from IRT to IRR: ' . $total);
		}

		// Prepare data
		$data = array(
			'callback_url' => $callback_url,
			'cart_amount'  => (int) wc_format_decimal($total, 0),
			'data'         => array(
				'goods_amount' => (int) wc_format_decimal($goods_amount, 0),
				'items'        => $items,
				'tax'          => $tax,
			),
			'order_id'     => $uuid, // Use UUID v4 instead of WC order ID
		);

		return $data;
	}

	/**
	 * Check for Vibe Payment Gateway response.
	 */
	public function check_payment_response()
	{
		// Get order ID from query string if available
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : null;

		// Fallback to session if needed
		if (!$order_id) {
			$order_id = WC()->session->get('vibe_order_id');
		}

		// Get reference ID
		$ref_id = isset($_GET['ref_id']) ? sanitize_text_field(wp_unslash($_GET['ref_id'])) : '';
		$result = isset($_GET['result']) ? sanitize_text_field(wp_unslash($_GET['result'])) : '';

		$this->log('Payment callback received. Order ID: ' . $order_id . ', Ref ID: ' . $ref_id . ', Result: ' . $result);

		if (!$order_id || !$ref_id) {
			$this->log('Missing order ID or reference ID in callback');
			wp_die(
				__('Invalid payment response. Missing required parameters.', 'woocommerce-gateway-vibe'),
				__('Payment Error', 'woocommerce-gateway-vibe'),
				array('response' => 400)
			);
		}

		// Get order by WooCommerce order ID
		$order = wc_get_order($order_id);

		if (!$order) {
			$this->log('Order not found by standard ID: ' . $order_id . '. Attempting to find by UUID.');

			// Try to find the order by UUID meta
			$orders = wc_get_orders(array(
				'meta_key'   => '_vibe_uuid_order_id',
				'meta_value' => $order_id,
				'limit'      => 1,
			));

			if (!empty($orders)) {
				$order = $orders[0];
				$this->log('Found order by UUID: ' . $order->get_id());
			}
		}

		if (!$order) {
			$this->log('Order not found: ' . $order_id);
			wp_die(
				__('Order not found.', 'woocommerce-gateway-vibe'),
				__('Payment Error', 'woocommerce-gateway-vibe'),
				array('response' => 404)
			);
		}

		// Prevent duplicate processing
		if ($order->is_paid()) {
			$this->log('Order already paid: ' . $order->get_id());
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// Verify payment
		$verification_result = $this->verify_payment($ref_id);

		if ($verification_result) {
			// Store reference ID
			$order->update_meta_data('_vibe_reference_id', $ref_id);
			$order->save();

			// Complete payment
			$order->payment_complete($ref_id);

			// Add note with both reference ID and UUID
			$uuid = $order->get_meta('_vibe_uuid_order_id');
			/* translators: %1$s: Reference ID from Vibe Payment Gateway, %2$s: UUID v4 used for the order */
			$order->add_order_note(sprintf(__('Payment completed via Vibe. Reference ID: %1$s, UUID: %2$s', 'woocommerce-gateway-vibe'), $ref_id, $uuid));

			// Empty cart
			WC()->cart->empty_cart();

			// Show success message with processing page
			echo '<div style="text-align: center; padding: 50px 0;">';
			echo '<h1>' . esc_html__('Payment Successful', 'woocommerce-gateway-vibe') . '</h1>';
			echo '<p>' . esc_html__('Your payment has been processed successfully. Redirecting to order confirmation...', 'woocommerce-gateway-vibe') . '</p>';
			echo '</div>';
			echo '<script>setTimeout(function() { window.location = "' . esc_url($this->get_return_url($order)) . '"; }, 2000);</script>';

			$this->log('Payment successful for order: ' . $order->get_id() . ' with reference: ' . $ref_id);
			exit;
		} else {
			// Log failure
			$this->log('Payment verification failed for order: ' . $order->get_id());

			// Update order status
			$order->update_status(
				'failed',
				__('Payment failed or was declined.', 'woocommerce-gateway-vibe')
			);

			// Show error message with processing page
			echo '<div style="text-align: center; padding: 50px 0;">';
			echo '<h1>' . esc_html__('Payment Failed', 'woocommerce-gateway-vibe') . '</h1>';
			echo '<p>' . esc_html__('Your payment could not be processed. Redirecting to checkout...', 'woocommerce-gateway-vibe') . '</p>';
			echo '</div>';
			echo '<script>setTimeout(function() { window.location = "' . esc_url(wc_get_checkout_url()) . '"; }, 2000);</script>';

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
		$this->log('Verifying payment with ref_id: ' . $ref_id);

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
			$this->log('Verification error: ' . $response->get_error_message());
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		$this->log('Verification response: ' . wp_json_encode($data));

		if (wp_remote_retrieve_response_code($response) !== 200) {
			$this->log('Verification failed with status code: ' . wp_remote_retrieve_response_code($response));
			return false;
		}

		return isset($data['status']) && $data['status'] === true;
	}

	/**
	 * Log debug messages.
	 *
	 * @param string $message
	 */
	private function log($message)
	{
		if ($this->debug_mode) {
			if (empty($this->logger)) {
				$this->logger = wc_get_logger();
			}
			$this->logger->info($message, array('source' => 'vibe-payment'));
		}
	}

	/**
	 * Output for the order received page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page($order_id)
	{
		$order = wc_get_order($order_id);

		if (!$order) {
			return;
		}

		// Only show for orders paid with this gateway
		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		// Get the reference ID from order meta
		$ref_id = $order->get_meta('_vibe_reference_id');

		if ($ref_id) {
			echo '<div class="vibe-payment-details">';
			echo '<p><strong>' . esc_html__('شناسه پرداخت:', 'woocommerce-gateway-vibe') . '</strong> ' . esc_html($ref_id) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Override parent get_option to apply translations to certain fields.
	 *
	 * @param string $key Option key.
	 * @param mixed  $empty_value Value when empty.
	 * @return string The translated option value.
	 */
	public function get_option($key, $empty_value = null)
	{
		$value = parent::get_option($key, $empty_value);

		// Apply translations to specific fields
		if ('title' === $key || 'description' === $key) {
			$value = __($value, 'woocommerce-gateway-vibe');
		}

		return $value;
	}
}
