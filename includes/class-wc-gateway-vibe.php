<?php
/**
 * WC_Gateway_Vibe class
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vibe Payment Gateway.
 *
 * @class    WC_Gateway_Vibe
 * @version  1.0.0
 */
class WC_Gateway_Vibe extends WC_Payment_Gateway {

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
	public function __construct() {
		
		$this->icon               = apply_filters( 'woocommerce_vibe_gateway_icon', '' );
		$this->has_fields         = false;
		$this->supports           = array(
			'products'
		);

		$this->method_title       = _x( 'Vibe Payment', 'Vibe payment method', 'woocommerce-gateway-vibe' );
		$this->method_description = __( 'Allows payments through Vibe Payment Gateway.', 'woocommerce-gateway-vibe' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->api_key     = $this->get_option( 'api_key' );

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		
		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_gateway_vibe', array( $this, 'check_payment_response' ) );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-gateway-vibe' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Vibe Payments', 'woocommerce-gateway-vibe' ),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce-gateway-vibe' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-vibe' ),
				'default'     => _x( 'Vibe Payment', 'Vibe payment method', 'woocommerce-gateway-vibe' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce-gateway-vibe' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce-gateway-vibe' ),
				'default'     => __( 'Pay with Vibe Payment Gateway.', 'woocommerce-gateway-vibe' ),
				'desc_tip'    => true,
			),
			'api_key' => array(
				'title'       => __( 'Merchant API Key', 'woocommerce-gateway-vibe' ),
				'type'        => 'password',
				'description' => __( 'Enter your Vibe Payment Gateway Merchant API Key.', 'woocommerce-gateway-vibe' ),
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
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Create order in Vibe Payment Gateway
		$response = $this->create_vibe_order( $order );

		if ( is_wp_error( $response ) ) {
			wc_add_notice( $response->get_error_message(), 'error' );
			return array(
				'result' => 'failure',
			);
		}

		// Store order ID in session for later retrieval
		WC()->session->set( 'vibe_order_id', $order_id );

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
	protected function create_vibe_order( $order ) {
		// Prepare cart data
		$cart_data = $this->prepare_cart_data( $order );

		// Make API request
		$response = wp_remote_post(
			$this->api_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $this->api_key,
				),
				'body'    => wp_json_encode( $cart_data ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$error_message = isset( $data['detail'] ) ? $this->format_error_message( $data['detail'] ) : __( 'Unknown error occurred while processing the payment.', 'woocommerce-gateway-vibe' );
			return new WP_Error( 'vibe_api_error', $error_message );
		}

		if ( ! isset( $data['payment_url'] ) ) {
			return new WP_Error( 'vibe_api_error', __( 'Invalid response from Vibe Payment Gateway.', 'woocommerce-gateway-vibe' ) );
		}

		return $data;
	}

	/**
	 * Format error message from API response.
	 *
	 * @param  array $details
	 * @return string
	 */
	protected function format_error_message( $details ) {
		$message = __( 'Vibe Payment Gateway error: ', 'woocommerce-gateway-vibe' );
		
		if ( is_array( $details ) ) {
			foreach ( $details as $detail ) {
				if ( isset( $detail['msg'] ) ) {
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
	protected function prepare_cart_data( $order ) {
		$items = array();
		$goods_amount = 0;

		// Add line items
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$price = $order->get_line_subtotal( $item, false, false );
			$discount = $order->get_line_subtotal( $item, false, false ) - $order->get_line_total( $item, false, false );
			
			$items[] = array(
				'id'       => $product ? $product->get_id() : $item_id,
				'name'     => $item->get_name(),
				'price'    => wc_format_decimal( $price, 0 ),
				'discount' => wc_format_decimal( $discount, 0 ),
				'quantity' => $item->get_quantity(),
			);
			
			$goods_amount += $price;
		}

		// Calculate tax
		$tax = wc_format_decimal( $order->get_total_tax(), 0 );

		// Generate callback URL
		$callback_url = add_query_arg( 'wc-api', 'wc_gateway_vibe', home_url( '/' ) );

		// Prepare data
		$data = array(
			'order_id'     => $order->get_id(),
			'cart_amount'  => wc_format_decimal( $order->get_total(), 0 ),
			'callback_url' => $callback_url,
			'data'         => array(
				'goods_amount' => wc_format_decimal( $goods_amount, 0 ),
				'items'        => $items,
				'tax'          => $tax,
			),
		);

		return $data;
	}

	/**
	 * Check for Vibe Payment Gateway response.
	 */
	public function check_payment_response() {
		if ( ! isset( $_GET['result'] ) || ! isset( $_GET['ref_id'] ) ) {
			wp_die( __( 'Invalid response from Vibe Payment Gateway.', 'woocommerce-gateway-vibe' ), __( 'Payment Error', 'woocommerce-gateway-vibe' ), array( 'response' => 500 ) );
		}

		$result = sanitize_text_field( wp_unslash( $_GET['result'] ) );
		$ref_id = sanitize_text_field( wp_unslash( $_GET['ref_id'] ) );
		
		// Verify payment
		$verified = $this->verify_payment( $ref_id );
		
		if ( $verified ) {
			// Get order from session
			$order_id = WC()->session->get( 'vibe_order_id' );
			$order = wc_get_order( $order_id );
			
			if ( ! $order ) {
				wp_die( __( 'Order not found.', 'woocommerce-gateway-vibe' ), __( 'Payment Error', 'woocommerce-gateway-vibe' ), array( 'response' => 500 ) );
			}
			
			// Mark order as complete
			$order->payment_complete();
			$order->add_order_note( sprintf( __( 'Vibe payment completed. Reference ID: %s', 'woocommerce-gateway-vibe' ), $ref_id ) );
			
			// Empty cart
			WC()->cart->empty_cart();
			
			// Redirect to thank you page
			wp_redirect( $this->get_return_url( $order ) );
			exit;
		} else {
			// Get order from session
			$order_id = WC()->session->get( 'vibe_order_id' );
			$order = wc_get_order( $order_id );
			
			if ( $order ) {
				$order->update_status( 'failed', __( 'Payment failed or was declined.', 'woocommerce-gateway-vibe' ) );
			}
			
			wc_add_notice( __( 'Payment failed or was declined.', 'woocommerce-gateway-vibe' ), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Verify payment with Vibe Payment Gateway.
	 *
	 * @param  string $ref_id
	 * @return bool
	 */
	protected function verify_payment( $ref_id ) {
		$response = wp_remote_post(
			$this->verify_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $this->api_key,
				),
				'body'    => wp_json_encode( array( 'ref_id' => $ref_id ) ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		return isset( $data['status'] ) && $data['status'] === true;
	}
} 