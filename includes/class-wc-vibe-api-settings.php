<?php
/**
 * Vibe API Settings
 *
 * @package WooCommerce\Vibe
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Vibe_API_Settings class.
 *
 * Handles the API settings page in WooCommerce.
 */
class WC_Vibe_API_Settings {

	/**
	 * Site URL for API endpoints.
	 *
	 * @var string
	 */
	private $site_url;

	/**
	 * Product list endpoint URL.
	 *
	 * @var string
	 */
	private $product_list_endpoint;

	/**
	 * Product details endpoint URL.
	 *
	 * @var string
	 */
	private $product_details_endpoint;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Set up endpoint URLs.
		$this->site_url = get_site_url();
		$this->product_list_endpoint = $this->site_url . '/wp-json/vibe/v1/products';
		$this->product_details_endpoint = $this->site_url . '/wp-json/vibe/v1/products/{product_id}';
		
		// Add settings tab to WooCommerce settings.
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		
		// Add settings to the tab.
		add_action( 'woocommerce_settings_tabs_vibe_api', array( $this, 'settings_tab' ) );
		
		// Save settings.
		add_action( 'woocommerce_update_options_vibe_api', array( $this, 'update_settings' ) );
		
		// Add link to settings from plugins page.
		add_filter( 'plugin_action_links_woocommerce-gateway-vibe/woocommerce-gateway-vibe.php', array( $this, 'plugin_action_links' ) );
		
		// Add scripts for copy functionality.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Add a new settings tab to the WooCommerce settings tabs array.
	 *
	 * @param array $settings_tabs Array of WooCommerce setting tabs.
	 * @return array $settings_tabs Array of WooCommerce setting tabs.
	 */
	public function add_settings_tab( $settings_tabs ) {
		$settings_tabs['vibe_api'] = __( 'Vibe API', 'woocommerce-gateway-vibe' );
		return $settings_tabs;
	}

	/**
	 * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
	 */
	public function settings_tab() {
		// Check if WooCommerce admin functions are available.
		if ( function_exists( 'woocommerce_admin_fields' ) ) {
			woocommerce_admin_fields( $this->get_settings() );
		}
		
		// Add API endpoint information section.
		$this->add_api_endpoints_section();
	}

	/**
	 * Add API endpoints section with copy functionality.
	 */
	public function add_api_endpoints_section() {
		?>
		<h2><?php esc_html_e( 'API Endpoints', 'woocommerce-gateway-vibe' ); ?></h2>
		<p><?php esc_html_e( 'Use these endpoints to access your product information. Click the copy button to copy the URL to your clipboard.', 'woocommerce-gateway-vibe' ); ?></p>
		
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label><?php esc_html_e( 'Product List Endpoint', 'woocommerce-gateway-vibe' ); ?></label>
					</th>
					<td class="forminp forminp-text">
						<div class="vibe-api-endpoint-container">
							<input type="text" class="regular-text vibe-api-endpoint" value="<?php echo esc_url( $this->product_list_endpoint ); ?>" readonly />
							<button type="button" class="button vibe-copy-endpoint" data-endpoint="<?php echo esc_url( $this->product_list_endpoint ); ?>">
								<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'woocommerce-gateway-vibe' ); ?>
							</button>
						</div>
						<p class="description">
							<?php esc_html_e( 'Endpoint for retrieving a list of products. Supports pagination with page and size parameters.', 'woocommerce-gateway-vibe' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Example:', 'woocommerce-gateway-vibe' ); ?> <code><?php echo esc_url( $this->product_list_endpoint . '?page=1&size=10' ); ?></code>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label><?php esc_html_e( 'Product Details Endpoint', 'woocommerce-gateway-vibe' ); ?></label>
					</th>
					<td class="forminp forminp-text">
						<div class="vibe-api-endpoint-container">
							<input type="text" class="regular-text vibe-api-endpoint" value="<?php echo esc_url( $this->product_details_endpoint ); ?>" readonly />
							<button type="button" class="button vibe-copy-endpoint" data-endpoint="<?php echo esc_url( $this->product_details_endpoint ); ?>">
								<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'woocommerce-gateway-vibe' ); ?>
							</button>
						</div>
						<p class="description">
							<?php esc_html_e( 'Endpoint for retrieving details of a specific product. Replace {product_id} with the actual product ID.', 'woocommerce-gateway-vibe' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Example:', 'woocommerce-gateway-vibe' ); ?> <code><?php echo esc_url( str_replace( '{product_id}', '123', $this->product_details_endpoint ) ); ?></code>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label><?php esc_html_e( 'Share Endpoints', 'woocommerce-gateway-vibe' ); ?></label>
					</th>
					<td class="forminp forminp-text">
						<button type="button" class="button vibe-share-endpoints">
							<span class="dashicons dashicons-share"></span> <?php esc_html_e( 'Share via Email', 'woocommerce-gateway-vibe' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Share these endpoints with your integration partner via email.', 'woocommerce-gateway-vibe' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Enqueue admin scripts for copy functionality.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on WooCommerce settings page.
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		
		// Check if we're on the Vibe API tab.
		if ( ! isset( $_GET['tab'] ) || 'vibe_api' !== $_GET['tab'] ) {
			return;
		}
		
		// Add inline styles.
		wp_add_inline_style( 'wp-admin', '
			.vibe-api-endpoint-container {
				display: flex;
				align-items: center;
				margin-bottom: 5px;
			}
			.vibe-api-endpoint {
				margin-right: 10px !important;
				flex-grow: 1;
			}
			.vibe-copy-endpoint .dashicons,
			.vibe-share-endpoints .dashicons {
				margin-top: 3px;
				margin-right: 3px;
			}
			.vibe-copy-success {
				color: #46b450;
				margin-left: 10px;
				display: none;
			}
		' );
		
		// Add inline script.
		wp_add_inline_script( 'jquery', '
			jQuery(document).ready(function($) {
				// Copy endpoint to clipboard.
				$(".vibe-copy-endpoint").on("click", function() {
					var endpoint = $(this).data("endpoint");
					var tempInput = $("<input>");
					$("body").append(tempInput);
					tempInput.val(endpoint).select();
					document.execCommand("copy");
					tempInput.remove();
					
					// Show success message.
					var $button = $(this);
					var originalText = $button.html();
					$button.html("<span class=\"dashicons dashicons-yes\"></span> ' . esc_js( __( 'Copied!', 'woocommerce-gateway-vibe' ) ) . '");
					setTimeout(function() {
						$button.html(originalText);
					}, 2000);
				});
				
				// Share endpoints via email.
				$(".vibe-share-endpoints").on("click", function() {
					var subject = "' . esc_js( __( 'Vibe API Endpoints', 'woocommerce-gateway-vibe' ) ) . '";
					var body = "' . esc_js( __( 'Here are the API endpoints for accessing our product information:', 'woocommerce-gateway-vibe' ) ) . '\\n\\n";
					body += "' . esc_js( __( 'Product List Endpoint:', 'woocommerce-gateway-vibe' ) ) . '\\n";
					body += "' . esc_js( $this->product_list_endpoint ) . '\\n\\n";
					body += "' . esc_js( __( 'Product Details Endpoint:', 'woocommerce-gateway-vibe' ) ) . '\\n";
					body += "' . esc_js( $this->product_details_endpoint ) . '\\n\\n";
					
					if ($("input#vibe_api_enable_auth").is(":checked")) {
						body += "' . esc_js( __( 'Note: API authentication is enabled. You will need to include the following API key in the X-Vibe-API-Key header of your requests:', 'woocommerce-gateway-vibe' ) ) . '\\n";
						body += "' . esc_js( get_option( 'wc_vibe_api_key', '' ) ) . '";
					}
					
					window.location.href = "mailto:?subject=" + encodeURIComponent(subject) + "&body=" + encodeURIComponent(body);
				});
			});
		' );
	}

	/**
	 * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
	 */
	public function update_settings() {
		// Check if WooCommerce admin functions are available.
		if ( function_exists( 'woocommerce_update_options' ) ) {
			woocommerce_update_options( $this->get_settings() );
		}
		
		// Generate a new API key if requested.
		if ( isset( $_POST['vibe_api_generate_key'] ) && 'yes' === $_POST['vibe_api_generate_key'] ) {
			$this->generate_api_key();
		}
	}

	/**
	 * Get all the settings for this plugin.
	 *
	 * @return array Array of settings for @see woocommerce_admin_fields() function.
	 */
	public function get_settings() {
		$settings = array(
			'section_title' => array(
				'name'     => __( 'Vibe Product Information Collection API', 'woocommerce-gateway-vibe' ),
				'type'     => 'title',
				'desc'     => __( 'Configure the settings for the Vibe Product Information Collection API.', 'woocommerce-gateway-vibe' ),
				'id'       => 'vibe_api_section_title',
			),
			'enable_api_auth' => array(
				'name'     => __( 'Enable API Authentication', 'woocommerce-gateway-vibe' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable API key authentication for the Vibe Product Information Collection API.', 'woocommerce-gateway-vibe' ),
				'id'       => 'vibe_api_enable_auth',
				'default'  => 'no',
			),
			'api_key' => array(
				'name'     => __( 'API Key', 'woocommerce-gateway-vibe' ),
				'type'     => 'text',
				'desc'     => __( 'API key for authenticating requests to the Vibe Product Information Collection API. This key must be included in the X-Vibe-API-Key header of API requests.', 'woocommerce-gateway-vibe' ),
				'id'       => 'vibe_api_key',
				'default'  => $this->get_default_api_key(),
				'custom_attributes' => array(
					'readonly' => 'readonly',
				),
			),
			'generate_key' => array(
				'name'     => __( 'Generate New API Key', 'woocommerce-gateway-vibe' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Check this box and save changes to generate a new API key. Warning: This will invalidate the existing key.', 'woocommerce-gateway-vibe' ),
				'id'       => 'vibe_api_generate_key',
				'default'  => 'no',
			),
			'section_end' => array(
				'type'     => 'sectionend',
				'id'       => 'vibe_api_section_end',
			),
		);

		return apply_filters( 'wc_vibe_api_settings', $settings );
	}

	/**
	 * Get default API key.
	 *
	 * @return string Default API key.
	 */
	private function get_default_api_key() {
		$api_key = get_option( 'vibe_api_key' );
		
		if ( empty( $api_key ) ) {
			$api_key = $this->generate_api_key();
		}
		
		return $api_key;
	}

	/**
	 * Generate a new API key.
	 *
	 * @return string Generated API key.
	 */
	private function generate_api_key() {
		$api_key = wp_generate_password( 32, false );
		update_option( 'wc_vibe_api_key', $api_key );
		
		return $api_key;
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Plugin action links.
	 * @return array Plugin action links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=vibe_api' ) . '">' . __( 'API Settings', 'woocommerce-gateway-vibe' ) . '</a>';
		array_unshift( $links, $settings_link );
		
		return $links;
	}
}

// Initialize the settings.
new WC_Vibe_API_Settings(); 