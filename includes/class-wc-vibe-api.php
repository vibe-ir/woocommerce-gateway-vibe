<?php

/**
 * Vibe API for Product Information Collection
 *
 * @package WooCommerce\Vibe
 * @since 1.0.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * WC_Vibe_API class.
 *
 * Handles the product information collection API endpoints.
 */
class WC_Vibe_API
{

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Register REST API routes.
		add_action('rest_api_init', array($this, 'register_rest_routes'));

		// Check if REST API is disabled and show admin notice if needed.
		add_action('admin_init', array($this, 'check_rest_api_status'));
	}

	/**
	 * Check if WordPress REST API is disabled and show admin notice if it is.
	 */
	public function check_rest_api_status()
	{
		// Only run this check for admin users who can manage options.
		if (! current_user_can('manage_options')) {
			return;
		}

		// Check if REST API is disabled.
		if ($this->is_rest_api_disabled()) {
			add_action('admin_notices', array($this, 'rest_api_disabled_notice'));
		}
	}

	/**
	 * Check if WordPress REST API is disabled.
	 *
	 * @return bool True if REST API is disabled, false otherwise.
	 */
	private function is_rest_api_disabled()
	{
		// Check common ways REST API might be disabled.

		// Check if REST API is disabled via filters.
		if (has_filter('rest_enabled') && ! apply_filters('rest_enabled', true)) {
			return true;
		}

		// Check if REST API is disabled via the REST authentication filter.
		if (has_filter('rest_authentication_errors')) {
			// Temporarily remove all registered filters.
			global $wp_filter;
			if (isset($wp_filter['rest_authentication_errors'])) {
				$rest_auth_filters = $wp_filter['rest_authentication_errors'];
				$wp_filter['rest_authentication_errors'] = new WP_Hook();

				// Add a test filter that will check if other filters are blocking REST API.
				$test_blocked = false;
				add_filter('rest_authentication_errors', function ($errors) use (&$test_blocked) {
					if (is_wp_error($errors)) {
						$test_blocked = true;
					}
					return $errors;
				}, 100);

				// Trigger the filter.
				$result = apply_filters('rest_authentication_errors', null);

				// Restore original filters.
				$wp_filter['rest_authentication_errors'] = $rest_auth_filters;

				if ($test_blocked || is_wp_error($result)) {
					return true;
				}
			}
		}

		// Check if REST API is disabled via permalink settings.
		if (get_option('permalink_structure') === '') {
			// REST API requires pretty permalinks to be enabled.
			return true;
		}

		// Make a test request to the REST API.
		$response = wp_remote_get(rest_url('wp/v2/types'), array(
			'timeout' => 10,
			'sslverify' => false,
		));

		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			return true;
		}

		return false;
	}

	/**
	 * Admin notice for disabled REST API.
	 */
	public function rest_api_disabled_notice()
	{
?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e('Vibe Payment Gateway Warning:', 'woocommerce-gateway-vibe'); ?></strong>
				<?php esc_html_e('The WordPress REST API appears to be disabled on your site. The Vibe Product Information Collection API requires the WordPress REST API to function properly. Please enable the REST API or check with your hosting provider.', 'woocommerce-gateway-vibe'); ?>
			</p>
			<p>
				<a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" class="button button-primary">
					<?php esc_html_e('Check Permalink Settings', 'woocommerce-gateway-vibe'); ?>
				</a>
				<a href="https://developer.wordpress.org/rest-api/" target="_blank" class="button">
					<?php esc_html_e('Learn More About REST API', 'woocommerce-gateway-vibe'); ?>
				</a>
			</p>
		</div>
<?php
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes()
	{
		// Register product list endpoint.
		register_rest_route(
			'vibe/v1',
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_products'),
				'permission_callback' => array($this, 'get_items_permissions_check'),
				'args'                => array(
					'page' => array(
						'description'       => __('Page number', 'woocommerce-gateway-vibe'),
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'size' => array(
						'description'       => __('Number of products per page', 'woocommerce-gateway-vibe'),
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// Register product details endpoint.
		register_rest_route(
			'vibe/v1',
			'/products/(?P<product_id>[\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_product_details'),
				'permission_callback' => array($this, 'get_items_permissions_check'),
				'args'                => array(
					'product_id' => array(
						'description'       => __('Product ID', 'woocommerce-gateway-vibe'),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Check if a given request has access to read items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check($request)
	{
		// Check if WooCommerce is active.
		if (! $this->is_woocommerce_active()) {
			return new WP_Error('woocommerce_not_active', __('WooCommerce is not active', 'woocommerce-gateway-vibe'), array('status' => 404));
		}

		// Check if API authentication is enabled.
		if ('yes' === get_option('wc_vibe_api_enable_auth', 'no')) {
			// Get the API key from the request headers.
			$api_key = $request->get_header('X-Vibe-API-Key');

			// If no API key is provided, return an error.
			if (empty($api_key)) {
				return new WP_Error(
					'vibe_api_missing_key',
					__('API key is required. Please include the X-Vibe-API-Key header in your request.', 'woocommerce-gateway-vibe'),
					array('status' => 401)
				);
			}

			// Get the stored API key.
			$stored_api_key = get_option('wc_vibe_api_key');

			// If the API key doesn't match, return an error.
			if ($api_key !== $stored_api_key) {
				return new WP_Error(
					'vibe_api_invalid_key',
					__('Invalid API key.', 'woocommerce-gateway-vibe'),
					array('status' => 401)
				);
			}
		}

		return true;
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active()
	{
		return class_exists('WooCommerce') && function_exists('WC');
	}

	/**
	 * Get a list of products.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_products($request)
	{
		if (! function_exists('wc_get_products')) {
			return new WP_Error('woocommerce_function_missing', __('Required WooCommerce function is missing', 'woocommerce-gateway-vibe'), array('status' => 500));
		}

		$page     = $request->get_param('page');
		$per_page = $request->get_param('size');

		// Query WooCommerce products.
		$args = array(
			'status'   => 'publish',
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
		);

		$products = wc_get_products($args);
		$response = array();

		foreach ($products as $product) {
			$response[] = array(
				'product-id'       => $product->get_id(),
				'product-name'     => $product->get_name(),
				'product-available' => $product->is_purchasable() && $product->is_in_stock(),
			);
		}

		return rest_ensure_response($response);
	}

	/**
	 * Get product details.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_product_details($request)
	{
		// Check for required WooCommerce functions.
		if (! function_exists('wc_get_product') || ! function_exists('wc_get_product_terms') || ! function_exists('wc_attribute_label')) {
			return new WP_Error('woocommerce_function_missing', __('Required WooCommerce functions are missing', 'woocommerce-gateway-vibe'), array('status' => 500));
		}

		$product_id = $request->get_param('product_id');
		$product    = wc_get_product($product_id);

		if (! $product || ! $product->is_visible()) {
			return new WP_Error('product_not_found', __('Product not found', 'woocommerce-gateway-vibe'), array('status' => 404));
		}

		// Get product images.
		$images = array();
		$attachment_ids = $product->get_gallery_image_ids();

		// Add featured image to the beginning of the array.
		if ($product->get_image_id()) {
			array_unshift($attachment_ids, $product->get_image_id());
		}

		foreach ($attachment_ids as $attachment_id) {
			$images[] = wp_get_attachment_url($attachment_id);
		}

		// Get product attributes/specifications.
		$attributes = array();
		foreach ($product->get_attributes() as $attribute) {
			if ($attribute->is_taxonomy()) {
				$attribute_taxonomy = $attribute->get_taxonomy_object();
				$attribute_values = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
				if (! empty($attribute_values)) {
					foreach ($attribute_values as $value) {
						$attributes[] = array(
							'name'  => $attribute_taxonomy->attribute_label,
							'value' => $value,
						);
					}
				}
			} else {
				$values = $attribute->get_options();
				foreach ($values as $value) {
					$attributes[] = array(
						'name'  => $attribute->get_name(),
						'value' => $value,
					);
				}
			}
		}

		// Get product variants if it's a variable product.
		$variants = array();

		// --- Vibe Pricing Engine integration ---
		if (! class_exists('WC_Vibe_Dynamic_Pricing')) {
			return new WP_Error('vibe_pricing_missing', __('Vibe Dynamic Pricing is not available', 'woocommerce-gateway-vibe'), array('status' => 500));
		}
		$pricing_engine = \WC_Vibe_Dynamic_Pricing::get_instance()->get_pricing_engine();
		if (! $pricing_engine) {
			return new WP_Error('vibe_pricing_engine_missing', __('Vibe Pricing Engine is not available', 'woocommerce-gateway-vibe'), array('status' => 500));
		}
		$pricing_engine->set_current_payment_method('vibe');
		// --- End Vibe Pricing Engine integration ---

		if ($product->is_type('variable')) {
			$variations = $product->get_available_variations();
			foreach ($variations as $variation) {
				$variation_obj = wc_get_product($variation['variation_id']);
				$variation_attributes = array();
				// Get variation attributes.
				foreach ($variation['attributes'] as $attribute_name => $attribute_value) {
					$taxonomy = str_replace('attribute_', '', $attribute_name);
					if (taxonomy_exists($taxonomy)) {
						$term = get_term_by('slug', $attribute_value, $taxonomy);
						if ($term) {
							$attribute_label = wc_attribute_label($taxonomy);
							$variation_attributes[] = array(
								'name'  => $attribute_label,
								'value' => $term->name,
							);
						}
					} else {
						$variation_attributes[] = array(
							'name'  => wc_attribute_label($taxonomy),
							'value' => $attribute_value,
						);
					}
				}
				// Get Vibe price for this variant
				$vibe_price = $pricing_engine->get_dynamic_price($variation_obj, $variation_obj->get_price(), 'application');
				if (false === $vibe_price) {
					$vibe_price = $variation_obj->get_price();
				}
				$variants[] = array(
					'variant-title'      => $variation['variation_id'],
					'price-main'         => $vibe_price,
					// 'price-sale'         => (float) ($variation_obj->get_sale_price() ? $variation_obj->get_sale_price() : $vibe_price),
					'price-sale'          => $vibe_price,
					'installment_price'  => $vibe_price,
					'cash_price'         => (float) ($variation_obj->get_sale_price() ? $variation_obj->get_sale_price() : $variation_obj->get_regular_price()),
					'properties'         => $variation_attributes,
				);
			}
		}

		// Get Vibe price for main product
		$vibe_price = $pricing_engine->get_dynamic_price($product, $product->get_price(), 'application');
		if (false === $vibe_price) {
			$vibe_price = $product->get_price();
		}

		// Build the response.
		$response = array(
			'product-id'          => $product->get_id(),
			'product-name'        => $product->get_name(),
			'product-category'    => $this->get_product_categories($product),
			'product-url'         => get_permalink($product->get_id()),
			'product-images'      => $images,
			'price-main'          => $vibe_price,
			// 'price-sale'          => (float) ($product->get_sale_price() ? $product->get_sale_price() : $vibe_price),
			'price-sale'          => $vibe_price,
			'installment_price'   => $vibe_price,
			'cash_price'          => (float) ($product->get_sale_price() ? $product->get_sale_price() : $product->get_regular_price()),
			'product-description' => $product->get_description(),
			'brand'               => $this->get_product_brand($product),
			'model'               => $this->get_product_model($product),
			'properties'          => $attributes,
		);

		// Add variants if available.
		if (! empty($variants)) {
			$response['product-variant'] = $variants;
		}

		return rest_ensure_response($response);
	}

	/**
	 * Get product categories.
	 *
	 * @param WC_Product $product Product object.
	 * @return string Primary category name.
	 */
	private function get_product_categories($product)
	{
		$terms = get_the_terms($product->get_id(), 'product_cat');

		if (! empty($terms) && ! is_wp_error($terms)) {
			// Return the first category name.
			return $terms[0]->name;
		}

		return '';
	}

	/**
	 * Get product brand.
	 *
	 * @param WC_Product $product Product object.
	 * @return string Brand name if available.
	 */
	private function get_product_brand($product)
	{
		// Check if there's a brand taxonomy or attribute.
		// This is a common implementation, but you might need to adjust based on your setup.
		$brand = '';

		// Check for brand taxonomy (common in many brand plugins).
		$brand_taxonomies = array('product_brand', 'pwb-brand', 'brand');

		foreach ($brand_taxonomies as $taxonomy) {
			if (taxonomy_exists($taxonomy)) {
				$terms = get_the_terms($product->get_id(), $taxonomy);
				if (! empty($terms) && ! is_wp_error($terms)) {
					$brand = $terms[0]->name;
					break;
				}
			}
		}

		// If no brand found in taxonomies, check attributes.
		if (empty($brand) && function_exists('wc_get_product_terms')) {
			$attributes = $product->get_attributes();
			$brand_attribute_names = array('brand', 'pa_brand', 'manufacturer');

			foreach ($brand_attribute_names as $attribute_name) {
				if (isset($attributes[$attribute_name])) {
					$attribute = $attributes[$attribute_name];
					if ($attribute->is_taxonomy()) {
						$terms = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
						if (! empty($terms)) {
							$brand = $terms[0];
							break;
						}
					} else {
						$values = $attribute->get_options();
						if (! empty($values)) {
							$brand = $values[0];
							break;
						}
					}
				}
			}
		}

		return $brand;
	}

	/**
	 * Get product model.
	 *
	 * @param WC_Product $product Product object.
	 * @return string Model name if available.
	 */
	private function get_product_model($product)
	{
		// Similar to brand, check for model in attributes.
		$model = '';

		if (! function_exists('wc_get_product_terms')) {
			return $model;
		}

		$attributes = $product->get_attributes();
		$model_attribute_names = array('model', 'pa_model');

		foreach ($model_attribute_names as $attribute_name) {
			if (isset($attributes[$attribute_name])) {
				$attribute = $attributes[$attribute_name];
				if ($attribute->is_taxonomy()) {
					$terms = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
					if (! empty($terms)) {
						$model = $terms[0];
						break;
					}
				} else {
					$values = $attribute->get_options();
					if (! empty($values)) {
						$model = $values[0];
						break;
					}
				}
			}
		}

		return $model;
	}
}

// Initialize the API only if WooCommerce is active.
if (class_exists('WooCommerce')) {
	new WC_Vibe_API();
}
