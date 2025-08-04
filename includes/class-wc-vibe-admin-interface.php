<?php

/**
 * Vibe Admin Interface Class
 *
 * Handles the admin interface for dynamic pricing management.
 *
 * @package  WooCommerce Vibe Payment Gateway
 * @since    1.1.0
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Admin Interface for Vibe Dynamic Pricing.
 *
 * @class    WC_Vibe_Admin_Interface
 * @version  1.1.0
 */
class WC_Vibe_Admin_Interface
{

	/**
	 * Pricing engine instance.
	 *
	 * @var WC_Vibe_Pricing_Engine
	 */
	private $pricing_engine;

	/**
	 * Cache manager instance.
	 *
	 * @var WC_Vibe_Cache_Manager
	 */
	private $cache_manager;

	/**
	 * Constructor.
	 *
	 * @param WC_Vibe_Pricing_Engine $pricing_engine Pricing engine instance.
	 * @param WC_Vibe_Cache_Manager $cache_manager Cache manager instance.
	 */
	public function __construct($pricing_engine, $cache_manager)
	{
		$this->pricing_engine = $pricing_engine;
		$this->cache_manager = $cache_manager;
		$this->init();
	}

	/**
	 * Initialize admin interface.
	 */
	private function init()
	{
		// Add admin menu
		add_action('admin_menu', array($this, 'add_admin_menu'));

		// Enqueue admin scripts and styles
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

		// Handle form submissions
		add_action('admin_post_save_vibe_pricing_rule', array($this, 'save_pricing_rule'));
		add_action('admin_post_delete_vibe_pricing_rule', array($this, 'delete_pricing_rule'));
		add_action('admin_post_toggle_vibe_pricing_rule', array($this, 'toggle_pricing_rule'));
		add_action('admin_post_save_vibe_pricing_settings', array($this, 'save_pricing_settings'));
		add_action('admin_post_save_vibe_display_settings', array($this, 'save_display_settings'));

		// Handle form submissions for current page
		add_action('admin_init', array($this, 'handle_form_submissions'));

		// AJAX handlers
		add_action('wp_ajax_vibe_get_pricing_stats', array($this, 'ajax_get_pricing_stats'));
		add_action('wp_ajax_vibe_clear_pricing_cache', array($this, 'ajax_clear_pricing_cache'));

		// Add settings link to plugins page
		add_filter('plugin_action_links_' . plugin_basename(WC_VIBE_PLUGIN_FILE), array($this, 'add_plugin_action_links'));
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu()
	{
		// Main menu page
		add_menu_page(
			__('Vibe Dynamic Pricing', 'woocommerce-gateway-vibe'),
			__('Vibe Pricing', 'woocommerce-gateway-vibe'),
			'manage_woocommerce',
			'vibe-dynamic-pricing',
			array($this, 'display_main_page'),
			'dashicons-tag',
			56
		);

		// Submenu pages
		add_submenu_page(
			'vibe-dynamic-pricing',
			__('Pricing Rules', 'woocommerce-gateway-vibe'),
			__('Rules', 'woocommerce-gateway-vibe'),
			'manage_woocommerce',
			'vibe-dynamic-pricing',
			array($this, 'display_main_page')
		);

		add_submenu_page(
			'vibe-dynamic-pricing',
			__('Display Settings', 'woocommerce-gateway-vibe'),
			__('Display', 'woocommerce-gateway-vibe'),
			'manage_woocommerce',
			'vibe-pricing-display',
			array($this, 'display_settings_page')
		);

		add_submenu_page(
			'vibe-dynamic-pricing',
			__('Performance', 'woocommerce-gateway-vibe'),
			__('Performance', 'woocommerce-gateway-vibe'),
			'manage_woocommerce',
			'vibe-pricing-performance',
			array($this, 'display_performance_page')
		);
	}

	/**
	 * Display main pricing rules page.
	 */
	public function display_main_page()
	{
		$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'rules';

?>
		<div class="wrap">
			<h1><?php _e('Vibe Dynamic Pricing', 'woocommerce-gateway-vibe'); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="?page=vibe-dynamic-pricing&tab=rules" class="nav-tab <?php echo $current_tab === 'rules' ? 'nav-tab-active' : ''; ?>">
					<?php _e('Pricing Rules', 'woocommerce-gateway-vibe'); ?>
				</a>
				<a href="?page=vibe-dynamic-pricing&tab=add-rule" class="nav-tab <?php echo $current_tab === 'add-rule' ? 'nav-tab-active' : ''; ?>">
					<?php _e('Add Rule', 'woocommerce-gateway-vibe'); ?>
				</a>
			</nav>

			<div class="tab-content">
				<?php
				switch ($current_tab) {
					case 'add-rule':
					case 'edit-rule':
						$this->display_add_rule_form();
						break;
					default:
						$this->display_rules_list();
						break;
				}
				?>
			</div>
		</div>
	<?php
	}

	/**
	 * Display pricing rules list.
	 */
	private function display_rules_list()
	{
		$rules = $this->get_pricing_rules();

	?>
		<div class="vibe-pricing-rules">
			<div class="tablenav top">
				<div class="alignleft actions">
					<select name="action" id="bulk-action-selector-top">
						<option value="-1"><?php _e('Bulk Actions', 'woocommerce-gateway-vibe'); ?></option>
						<option value="enable"><?php _e('Enable', 'woocommerce-gateway-vibe'); ?></option>
						<option value="disable"><?php _e('Disable', 'woocommerce-gateway-vibe'); ?></option>
						<option value="delete"><?php _e('Delete', 'woocommerce-gateway-vibe'); ?></option>
					</select>
					<input type="submit" class="button action" value="<?php _e('Apply', 'woocommerce-gateway-vibe'); ?>">
				</div>
				<div class="alignright">
					<a href="?page=vibe-dynamic-pricing&tab=add-rule" class="button button-primary">
						<?php _e('Add New Rule', 'woocommerce-gateway-vibe'); ?>
					</a>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="cb-select-all-1">
						</td>
						<th class="manage-column column-name"><?php _e('Rule Name', 'woocommerce-gateway-vibe'); ?></th>
						<th class="manage-column column-priority"><?php _e('Priority', 'woocommerce-gateway-vibe'); ?></th>
						<th class="manage-column column-targeting"><?php _e('Product Targeting', 'woocommerce-gateway-vibe'); ?></th>
						<th class="manage-column column-adjustment"><?php _e('Price Adjustment', 'woocommerce-gateway-vibe'); ?></th>
						<th class="manage-column column-status"><?php _e('Status', 'woocommerce-gateway-vibe'); ?></th>
						<th class="manage-column column-actions"><?php _e('Actions', 'woocommerce-gateway-vibe'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($rules)): ?>
						<tr>
							<td colspan="7" class="no-items">
								<?php _e('No pricing rules found.', 'woocommerce-gateway-vibe'); ?>
								<a href="?page=vibe-dynamic-pricing&tab=add-rule"><?php _e('Create your first rule', 'woocommerce-gateway-vibe'); ?></a>
							</td>
						</tr>
					<?php else: ?>
						<?php foreach ($rules as $rule): ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="rule_ids[]" value="<?php echo esc_attr($rule['id']); ?>">
								</th>
								<td class="column-name">
									<strong>
										<a href="?page=vibe-dynamic-pricing&tab=edit-rule&rule_id=<?php echo esc_attr($rule['id']); ?>">
											<?php echo esc_html($rule['name']); ?>
										</a>
									</strong>
									<?php if (!empty($rule['description'])): ?>
										<p class="description"><?php echo esc_html($rule['description']); ?></p>
									<?php endif; ?>
								</td>
								<td class="column-priority">
									<?php echo esc_html($rule['priority']); ?>
								</td>
								<td class="column-targeting">
									<?php
									$product_conditions = !empty($rule['product_conditions']) ? json_decode($rule['product_conditions'], true) : array();
									$target_type = isset($product_conditions['target_type']) ? $product_conditions['target_type'] : 'all';

									switch ($target_type) {
										case 'all':
											echo '<strong>' . __('All Products', 'woocommerce-gateway-vibe') . '</strong>';
											break;
										case 'specific':
											$count = isset($product_conditions['product_ids']) ? count($product_conditions['product_ids']) : 0;
											echo '<strong>' . __('Specific Products', 'woocommerce-gateway-vibe') . '</strong><br>';
											/* translators: %d: number of products selected for the pricing rule */
											echo sprintf(_n('%d product', '%d products', $count, 'woocommerce-gateway-vibe'), $count);
											break;
										case 'categories':
											$count = isset($product_conditions['categories']) ? count($product_conditions['categories']) : 0;
											$logic = isset($product_conditions['category_logic']) ? $product_conditions['category_logic'] : 'OR';
											echo '<strong>' . __('Categories', 'woocommerce-gateway-vibe') . '</strong><br>';
											/* translators: %d: number of categories selected for the pricing rule */
											echo sprintf(_n('%d category', '%d categories', $count, 'woocommerce-gateway-vibe'), $count) . ' (' . $logic . ')';
											break;
										case 'tags':
											$count = isset($product_conditions['tags']) ? count($product_conditions['tags']) : 0;
											$logic = isset($product_conditions['tag_logic']) ? $product_conditions['tag_logic'] : 'OR';
											echo '<strong>' . __('Tags', 'woocommerce-gateway-vibe') . '</strong><br>';
											/* translators: %d: number of tags selected for the pricing rule */
											echo sprintf(_n('%d tag', '%d tags', $count, 'woocommerce-gateway-vibe'), $count) . ' (' . $logic . ')';
											break;
										case 'price_range':
											echo '<strong>' . __('Price Range', 'woocommerce-gateway-vibe') . '</strong><br>';
											$min = isset($product_conditions['min_price']) ? $product_conditions['min_price'] : '';
											$max = isset($product_conditions['max_price']) ? $product_conditions['max_price'] : '';
											if ($min && $max) {
												echo '$' . $min . ' - $' . $max;
											} elseif ($min) {
												echo '$' . $min . '+';
											} elseif ($max) {
												echo 'Up to $' . $max;
											}
											break;
										case 'complex':
											echo '<strong>' . __('Complex Logic', 'woocommerce-gateway-vibe') . '</strong>';
											break;
										default:
											echo '<em>' . __('All Products', 'woocommerce-gateway-vibe') . '</em>';
											break;
									}
									?>
								</td>
								<td class="column-adjustment">
									<?php
									$adjustment = !empty($rule['price_adjustment']) ? json_decode($rule['price_adjustment'], true) : array();
									if (!empty($adjustment)) {
										$type = $adjustment['type'];
										$value = $adjustment['value'];

										if ($type === 'percentage') {
											echo $value > 0 ? '+' : '';
											echo esc_html($value) . '%';
										} elseif ($type === 'fixed') {
											echo $value > 0 ? '+' : '';
											echo '$' . esc_html($value);
										} elseif ($type === 'fixed_price') {
											echo '$' . esc_html($value);
										}
									}
									?>
								</td>
								<td class="column-status">
									<span class="status-<?php echo esc_attr($rule['status']); ?>">
										<?php echo $rule['status'] === 'active' ? __('Active', 'woocommerce-gateway-vibe') : __('Inactive', 'woocommerce-gateway-vibe'); ?>
									</span>
								</td>
								<td class="column-actions">
									<a href="?page=vibe-dynamic-pricing&tab=edit-rule&rule_id=<?php echo esc_attr($rule['id']); ?>" class="button button-small">
										<?php _e('Edit', 'woocommerce-gateway-vibe'); ?>
									</a>
									<a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=toggle_vibe_pricing_rule&rule_id=' . $rule['id']), 'toggle_rule_' . $rule['id']); ?>" class="button button-small">
										<?php echo $rule['status'] === 'active' ? __('Disable', 'woocommerce-gateway-vibe') : __('Enable', 'woocommerce-gateway-vibe'); ?>
									</a>
									<a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_vibe_pricing_rule&rule_id=' . $rule['id']), 'delete_rule_' . $rule['id']); ?>"
										class="button button-small button-link-delete"
										onclick="return confirm('<?php _e('Are you sure you want to delete this rule?', 'woocommerce-gateway-vibe'); ?>')">
										<?php _e('Delete', 'woocommerce-gateway-vibe'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php
	}

	/**
	 * Display add/edit rule form.
	 */
	private function display_add_rule_form()
	{
		$rule_id = isset($_GET['rule_id']) ? intval($_GET['rule_id']) : 0;
		$rule = $rule_id ? $this->get_pricing_rule($rule_id) : $this->get_default_rule();

	?>
		<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="vibe-rule-form">
			<?php wp_nonce_field('save_pricing_rule', 'pricing_rule_nonce'); ?>
			<input type="hidden" name="action" value="save_vibe_pricing_rule">
			<?php if ($rule_id): ?>
				<input type="hidden" name="rule_id" value="<?php echo esc_attr($rule_id); ?>">
			<?php endif; ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="rule_name"><?php _e('Rule Name', 'woocommerce-gateway-vibe'); ?></label>
					</th>
					<td>
						<input type="text" id="rule_name" name="rule_name" value="<?php echo esc_attr($rule['name']); ?>" class="regular-text" required>
						<p class="description"><?php _e('A descriptive name for this pricing rule.', 'woocommerce-gateway-vibe'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="rule_description"><?php _e('Description', 'woocommerce-gateway-vibe'); ?></label>
					</th>
					<td>
						<textarea id="rule_description" name="rule_description" rows="3" class="large-text"><?php echo esc_textarea($rule['description']); ?></textarea>
						<p class="description"><?php _e('Optional description of what this rule does.', 'woocommerce-gateway-vibe'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="rule_priority"><?php _e('Priority', 'woocommerce-gateway-vibe'); ?></label>
					</th>
					<td>
						<input type="number" id="rule_priority" name="rule_priority" value="<?php echo esc_attr($rule['priority']); ?>" min="0" max="999" class="small-text">
						<p class="description"><?php _e('Higher priority rules are applied first. Use 0 for lowest priority.', 'woocommerce-gateway-vibe'); ?></p>
					</td>
				</tr>

				<!-- Product Targeting Section -->
				<tr>
					<th scope="row"><?php _e('Product Targeting', 'woocommerce-gateway-vibe'); ?></th>
					<td>
						<?php
						$product_conditions = !empty($rule['product_conditions']) ? json_decode($rule['product_conditions'], true) : array();
						$target_type = isset($product_conditions['target_type']) ? $product_conditions['target_type'] : 'all';
						?>

						<div class="vibe-product-targeting">
							<label>
								<input type="radio" name="target_type" value="all" <?php checked($target_type, 'all'); ?>>
								<?php _e('All Products', 'woocommerce-gateway-vibe'); ?>
							</label>
							<br><br>

							<label>
								<input type="radio" name="target_type" value="specific" <?php checked($target_type, 'specific'); ?>>
								<?php _e('Specific Products', 'woocommerce-gateway-vibe'); ?>
							</label>
							<div class="target-option" data-target="specific" style="margin-left: 25px; margin-top: 10px;">
								<?php
								$selected_products = isset($product_conditions['product_ids']) ? $product_conditions['product_ids'] : array();
								?>
								<select name="target_products[]" id="target_products" multiple style="width: 400px; height: 120px;">
									<?php
									$products = wc_get_products(array('limit' => -1, 'status' => 'publish'));
									foreach ($products as $product) {
										$selected = in_array($product->get_id(), $selected_products) ? 'selected' : '';
										echo '<option value="' . esc_attr($product->get_id()) . '" ' . $selected . '>' . esc_html($product->get_name()) . ' (#' . $product->get_id() . ')</option>';
									}
									?>
								</select>
								<p class="description"><?php _e('Hold Ctrl/Cmd to select multiple products.', 'woocommerce-gateway-vibe'); ?></p>
							</div>
							<br>

							<label>
								<input type="radio" name="target_type" value="categories" <?php checked($target_type, 'categories'); ?>>
								<?php _e('Product Categories', 'woocommerce-gateway-vibe'); ?>
							</label>
							<div class="target-option" data-target="categories" style="margin-left: 25px; margin-top: 10px;">
								<?php
								$selected_categories = isset($product_conditions['categories']) ? $product_conditions['categories'] : array();
								$category_logic = isset($product_conditions['category_logic']) ? $product_conditions['category_logic'] : 'OR';
								?>
								<select name="target_categories[]" id="target_categories" multiple style="width: 400px; height: 120px;">
									<?php
									$categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
									foreach ($categories as $category) {
										$selected = in_array($category->term_id, $selected_categories) ? 'selected' : '';
										echo '<option value="' . esc_attr($category->term_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
									}
									?>
								</select>
								<br><br>
								<label><?php _e('Logic:', 'woocommerce-gateway-vibe'); ?></label>
								<select name="category_logic">
									<option value="OR" <?php selected($category_logic, 'OR'); ?>><?php _e('Any of selected categories (OR)', 'woocommerce-gateway-vibe'); ?></option>
									<option value="AND" <?php selected($category_logic, 'AND'); ?>><?php _e('All selected categories (AND)', 'woocommerce-gateway-vibe'); ?></option>
								</select>
							</div>
							<br>

							<label>
								<input type="radio" name="target_type" value="tags" <?php checked($target_type, 'tags'); ?>>
								<?php _e('Product Tags', 'woocommerce-gateway-vibe'); ?>
							</label>
							<div class="target-option" data-target="tags" style="margin-left: 25px; margin-top: 10px;">
								<?php
								$selected_tags = isset($product_conditions['tags']) ? $product_conditions['tags'] : array();
								$tag_logic = isset($product_conditions['tag_logic']) ? $product_conditions['tag_logic'] : 'OR';
								?>
								<select name="target_tags[]" id="target_tags" multiple style="width: 400px; height: 120px;">
									<?php
									$tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
									foreach ($tags as $tag) {
										$selected = in_array($tag->term_id, $selected_tags) ? 'selected' : '';
										echo '<option value="' . esc_attr($tag->term_id) . '" ' . $selected . '>' . esc_html($tag->name) . '</option>';
									}
									?>
								</select>
								<br><br>
								<label><?php _e('Logic:', 'woocommerce-gateway-vibe'); ?></label>
								<select name="tag_logic">
									<option value="OR" <?php selected($tag_logic, 'OR'); ?>><?php _e('Any of selected tags (OR)', 'woocommerce-gateway-vibe'); ?></option>
									<option value="AND" <?php selected($tag_logic, 'AND'); ?>><?php _e('All selected tags (AND)', 'woocommerce-gateway-vibe'); ?></option>
								</select>
							</div>
							<br>

							<label>
								<input type="radio" name="target_type" value="price_range" <?php checked($target_type, 'price_range'); ?>>
								<?php _e('Price Range', 'woocommerce-gateway-vibe'); ?>
							</label>
							<div class="target-option" data-target="price_range" style="margin-left: 25px; margin-top: 10px;">
								<?php
								$min_price = isset($product_conditions['min_price']) ? $product_conditions['min_price'] : '';
								$max_price = isset($product_conditions['max_price']) ? $product_conditions['max_price'] : '';
								?>
								<label><?php _e('Minimum Price:', 'woocommerce-gateway-vibe'); ?></label>
								<input type="number" name="min_price" value="<?php echo esc_attr($min_price); ?>" step="0.01" min="0" class="small-text">
								<label><?php _e('Maximum Price:', 'woocommerce-gateway-vibe'); ?></label>
								<input type="number" name="max_price" value="<?php echo esc_attr($max_price); ?>" step="0.01" min="0" class="small-text">
								<p class="description"><?php _e('Leave empty for no limit. Applies to products within this price range.', 'woocommerce-gateway-vibe'); ?></p>
							</div>
							<br>

							<label>
								<input type="radio" name="target_type" value="complex" <?php checked($target_type, 'complex'); ?>>
								<?php _e('Complex Logic', 'woocommerce-gateway-vibe'); ?>
							</label>
							<div class="target-option" data-target="complex" style="margin-left: 25px; margin-top: 10px;">
								<div id="complex-logic-builder">
									<p class="description"><?php _e('Build complex conditions like: ((Category A AND Tag X) OR (Category B AND Tag Y)) AND Price > $50', 'woocommerce-gateway-vibe'); ?></p>
									<textarea name="complex_logic" rows="4" class="large-text" placeholder="Example: (category:electronics AND tag:sale) OR (price > 100 AND category:clothing)"><?php
																																																	echo isset($product_conditions['complex_logic']) ? esc_textarea($product_conditions['complex_logic']) : '';
																																																	?></textarea>
									<p class="description">
										<?php _e('Syntax: category:slug, tag:slug, price > amount, price < amount, price = amount. Use AND, OR, parentheses for grouping.', 'woocommerce-gateway-vibe'); ?>
									</p>
								</div>
							</div>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="adjustment_type"><?php _e('Price Adjustment', 'woocommerce-gateway-vibe'); ?></label>
					</th>
					<td>
						<?php
						$adjustment = !empty($rule['price_adjustment']) ? json_decode($rule['price_adjustment'], true) : array();
						$current_type = isset($adjustment['type']) ? $adjustment['type'] : 'percentage';
						$current_value = isset($adjustment['value']) ? $adjustment['value'] : 0;
						?>
						<select id="adjustment_type" name="adjustment_type">
							<option value="percentage" <?php selected($current_type, 'percentage'); ?>><?php _e('Percentage Adjustment', 'woocommerce-gateway-vibe'); ?></option>
							<option value="fixed" <?php selected($current_type, 'fixed'); ?>><?php _e('Fixed Amount Addition/Subtraction', 'woocommerce-gateway-vibe'); ?></option>
							<option value="fixed_price" <?php selected($current_type, 'fixed_price'); ?>><?php _e('Fixed Price Override', 'woocommerce-gateway-vibe'); ?></option>
						</select>
						<input type="number" id="adjustment_value" name="adjustment_value" value="<?php echo esc_attr($current_value); ?>" step="0.01" class="small-text">

						<div class="price-adjustment-help" style="margin-top: 10px;">
							<p><strong><?php _e('Price Adjustment Types:', 'woocommerce-gateway-vibe'); ?></strong></p>
							<ul style="margin-left: 20px;">
								<li><strong><?php _e('Percentage:', 'woocommerce-gateway-vibe'); ?></strong> <?php _e('+10 = 10% increase, -5 = 5% decrease from original price', 'woocommerce-gateway-vibe'); ?></li>
								<li><strong><?php _e('Fixed Amount:', 'woocommerce-gateway-vibe'); ?></strong> <?php _e('+10 = add $10, -5 = subtract $5 from original price', 'woocommerce-gateway-vibe'); ?></li>
								<li><strong><?php _e('Fixed Price:', 'woocommerce-gateway-vibe'); ?></strong> <?php _e('25 = set price to exactly $25 regardless of original price', 'woocommerce-gateway-vibe'); ?></li>
							</ul>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e('Discount Integration', 'woocommerce-gateway-vibe'); ?></th>
					<td>
						<?php $discount_integration = isset($rule['discount_integration']) ? $rule['discount_integration'] : 'apply'; ?>
						<label>
							<input type="radio" name="discount_integration" value="apply" <?php checked($discount_integration, 'apply'); ?>>
							<?php _e('Apply with existing WooCommerce discounts', 'woocommerce-gateway-vibe'); ?>
						</label>
						<br>
						<label>
							<input type="radio" name="discount_integration" value="ignore" <?php checked($discount_integration, 'ignore'); ?>>
							<?php _e('Ignore existing discounts (replace them)', 'woocommerce-gateway-vibe'); ?>
						</label>
						<br>
						<label>
							<input type="radio" name="discount_integration" value="before" <?php checked($discount_integration, 'before'); ?>>
							<?php _e('Apply before other discounts', 'woocommerce-gateway-vibe'); ?>
						</label>
						<p class="description"><?php _e('Choose how this rule interacts with existing WooCommerce coupons and discounts.', 'woocommerce-gateway-vibe'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="rule_status"><?php _e('Status', 'woocommerce-gateway-vibe'); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" id="rule_status" name="rule_status" value="active" <?php checked($rule['status'], 'active'); ?>>
							<?php _e('Enable this rule', 'woocommerce-gateway-vibe'); ?>
						</label>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" class="button-primary" value="<?php echo $rule_id ? __('Update Rule', 'woocommerce-gateway-vibe') : __('Create Rule', 'woocommerce-gateway-vibe'); ?>">
				<a href="?page=vibe-dynamic-pricing" class="button"><?php _e('Cancel', 'woocommerce-gateway-vibe'); ?></a>
			</p>
		</form>

		<script>
			jQuery(document).ready(function($) {
				// Show/hide target options based on selection
				$('input[name="target_type"]').change(function() {
					$('.target-option').hide();
					if ($(this).val() !== 'all') {
						$('.target-option[data-target="' + $(this).val() + '"]').show();
					}
				}).trigger('change');
			});
		</script>
	<?php
	}

	/**
	 * Display settings page.
	 */
	public function display_settings_page()
	{
	?>
		<div class="wrap">
			<h1><?php _e('Display Settings', 'woocommerce-gateway-vibe'); ?></h1>
			<p><?php _e('Configure how dynamic prices are displayed to customers.', 'woocommerce-gateway-vibe'); ?></p>

			<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
				<?php wp_nonce_field('vibe_save_display_settings', 'vibe_display_nonce'); ?>
				<input type="hidden" name="action" value="save_vibe_display_settings">

				<?php
				$settings = get_option('wc_vibe_price_display_settings', array());
				$defaults = array(
					'display_layout' => 'two_line',
					'price_order' => 'original_first',
					'new_price_font_size' => '100%',
					'new_price_color' => '',
					'new_price_font_weight' => 'bold',
					'original_price_font_size' => '85%',
					'original_price_color' => '#999999',
					'original_price_font_weight' => 'normal',
					'new_price_prefix' => 'قیمت اقساطی ',
					'original_price_prefix' => 'قیمت نقدی ',
				);
				$settings = wp_parse_args($settings, $defaults);
				?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php _e('Enable Dynamic Pricing', 'woocommerce-gateway-vibe'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_dynamic_pricing" value="yes" <?php checked(get_option('wc_vibe_dynamic_pricing_enabled', 'yes'), 'yes'); ?>>
								<?php _e('Enable dynamic pricing functionality', 'woocommerce-gateway-vibe'); ?>
							</label>
							<p class="description"><?php _e('Master switch to enable or disable all dynamic pricing features.', 'woocommerce-gateway-vibe'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php _e('Apply Pricing Based On', 'woocommerce-gateway-vibe'); ?></th>
						<td>
							<?php $apply_mode = get_option('wc_vibe_dynamic_pricing_apply_mode', 'combined'); ?>
							<label>
								<input type="radio" name="apply_mode" value="combined" <?php checked($apply_mode, 'combined'); ?>>
								<strong><?php _e('Referrer OR Payment Gateway (Recommended)', 'woocommerce-gateway-vibe'); ?></strong>
							</label>
							<p class="description" style="margin-left: 25px;">
								<?php _e('Apply dynamic pricing when visitors come from vibe.ir OR when they select Vibe payment gateway. This provides the best coverage while preventing double pricing application.', 'woocommerce-gateway-vibe'); ?>
							</p>
							<br>
							<label>
								<input type="radio" name="apply_mode" value="always" <?php checked($apply_mode, 'always'); ?>>
								<?php _e('Always Apply (All Visitors)', 'woocommerce-gateway-vibe'); ?>
							</label>
							<p class="description" style="margin-left: 25px;">
								<?php _e('Apply dynamic pricing to all visitors regardless of referrer or payment method. Use with caution as this affects all customers.', 'woocommerce-gateway-vibe'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php _e('Price Styling', 'woocommerce-gateway-vibe'); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php _e('Dynamic Price Styling', 'woocommerce-gateway-vibe'); ?></legend>
								<h4><?php _e('Dynamic Price Styling:', 'woocommerce-gateway-vibe'); ?></h4>
								<div class="vibe-styling-controls">
									<div class="vibe-style-row">
										<label for="new_price_font_size"><?php _e('Font Size:', 'woocommerce-gateway-vibe'); ?></label>
										<input type="text" id="new_price_font_size" name="new_price_font_size" value="<?php echo esc_attr($settings['new_price_font_size']); ?>" placeholder="100%" class="small-text">
									</div>
									<div class="vibe-style-row">
										<label for="new_price_color"><?php _e('Color:', 'woocommerce-gateway-vibe'); ?></label>
										<input type="text" id="new_price_color" name="new_price_color" value="<?php echo esc_attr($settings['new_price_color']); ?>" placeholder="#000000" class="vibe-color-picker small-text">
									</div>
									<div class="vibe-style-row">
										<label for="new_price_font_weight"><?php _e('Font Weight:', 'woocommerce-gateway-vibe'); ?></label>
										<select id="new_price_font_weight" name="new_price_font_weight">
											<option value="normal" <?php selected($settings['new_price_font_weight'], 'normal'); ?>><?php _e('Normal', 'woocommerce-gateway-vibe'); ?></option>
											<option value="bold" <?php selected($settings['new_price_font_weight'], 'bold'); ?>><?php _e('Bold', 'woocommerce-gateway-vibe'); ?></option>
											<option value="bolder" <?php selected($settings['new_price_font_weight'], 'bolder'); ?>><?php _e('Bolder', 'woocommerce-gateway-vibe'); ?></option>
											<option value="lighter" <?php selected($settings['new_price_font_weight'], 'lighter'); ?>><?php _e('Lighter', 'woocommerce-gateway-vibe'); ?></option>
											<option value="100" <?php selected($settings['new_price_font_weight'], '100'); ?>>100</option>
											<option value="200" <?php selected($settings['new_price_font_weight'], '200'); ?>>200</option>
											<option value="300" <?php selected($settings['new_price_font_weight'], '300'); ?>>300</option>
											<option value="400" <?php selected($settings['new_price_font_weight'], '400'); ?>>400</option>
											<option value="500" <?php selected($settings['new_price_font_weight'], '500'); ?>>500</option>
											<option value="600" <?php selected($settings['new_price_font_weight'], '600'); ?>>600</option>
											<option value="700" <?php selected($settings['new_price_font_weight'], '700'); ?>>700</option>
											<option value="800" <?php selected($settings['new_price_font_weight'], '800'); ?>>800</option>
											<option value="900" <?php selected($settings['new_price_font_weight'], '900'); ?>>900</option>
										</select>
									</div>
								</div>
								
								<h4><?php _e('Original Price Styling:', 'woocommerce-gateway-vibe'); ?></h4>
								<div class="vibe-styling-controls">
									<div class="vibe-style-row">
										<label for="original_price_font_size"><?php _e('Font Size:', 'woocommerce-gateway-vibe'); ?></label>
										<input type="text" id="original_price_font_size" name="original_price_font_size" value="<?php echo esc_attr($settings['original_price_font_size']); ?>" placeholder="85%" class="small-text">
									</div>
									<div class="vibe-style-row">
										<label for="original_price_color"><?php _e('Color:', 'woocommerce-gateway-vibe'); ?></label>
										<input type="text" id="original_price_color" name="original_price_color" value="<?php echo esc_attr($settings['original_price_color']); ?>" placeholder="#999999" class="vibe-color-picker small-text">
									</div>
									<div class="vibe-style-row">
										<label for="original_price_font_weight"><?php _e('Font Weight:', 'woocommerce-gateway-vibe'); ?></label>
										<select id="original_price_font_weight" name="original_price_font_weight">
											<option value="normal" <?php selected($settings['original_price_font_weight'], 'normal'); ?>><?php _e('Normal', 'woocommerce-gateway-vibe'); ?></option>
											<option value="bold" <?php selected($settings['original_price_font_weight'], 'bold'); ?>><?php _e('Bold', 'woocommerce-gateway-vibe'); ?></option>
											<option value="bolder" <?php selected($settings['original_price_font_weight'], 'bolder'); ?>><?php _e('Bolder', 'woocommerce-gateway-vibe'); ?></option>
											<option value="lighter" <?php selected($settings['original_price_font_weight'], 'lighter'); ?>><?php _e('Lighter', 'woocommerce-gateway-vibe'); ?></option>
											<option value="100" <?php selected($settings['original_price_font_weight'], '100'); ?>>100</option>
											<option value="200" <?php selected($settings['original_price_font_weight'], '200'); ?>>200</option>
											<option value="300" <?php selected($settings['original_price_font_weight'], '300'); ?>>300</option>
											<option value="400" <?php selected($settings['original_price_font_weight'], '400'); ?>>400</option>
											<option value="500" <?php selected($settings['original_price_font_weight'], '500'); ?>>500</option>
											<option value="600" <?php selected($settings['original_price_font_weight'], '600'); ?>>600</option>
											<option value="700" <?php selected($settings['original_price_font_weight'], '700'); ?>>700</option>
											<option value="800" <?php selected($settings['original_price_font_weight'], '800'); ?>>800</option>
											<option value="900" <?php selected($settings['original_price_font_weight'], '900'); ?>>900</option>
										</select>
									</div>
								</div>
								
								<p class="description">
									<?php _e('Font Size: Use CSS units (%, px, em, rem). Examples: 100%, 14px, 1.2em, 1rem', 'woocommerce-gateway-vibe'); ?><br>
									<?php _e('Color: Use hex codes (#000000), color names (black), or CSS color values (rgb(0,0,0))', 'woocommerce-gateway-vibe'); ?>
								</p>
							</fieldset>
							
							<style>
								.vibe-styling-controls {
									margin: 10px 0;
									padding: 10px;
									background: #f9f9f9;
									border: 1px solid #ddd;
									border-radius: 4px;
								}
								.vibe-style-row {
									display: flex;
									align-items: center;
									margin-bottom: 8px;
								}
								.vibe-style-row label {
									min-width: 100px;
									margin-right: 10px;
									font-weight: 500;
								}
								.vibe-style-row input,
								.vibe-style-row select {
									margin-right: 10px;
								}
								.vibe-color-picker {
									max-width: 100px;
								}
							</style>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php _e('Price Prefixes & Suffixes', 'woocommerce-gateway-vibe'); ?></th>
						<td>
							<div class="vibe-prefix-suffix-container">
								<!-- Dynamic Price Section -->
								<div class="vibe-price-config-section">
									<div class="vibe-price-config-header">
										<span class="vibe-price-icon vibe-dynamic-icon">💰</span>
										<h4><?php _e('Dynamic Price', 'woocommerce-gateway-vibe'); ?></h4>

									</div>
									<div class="vibe-price-config-fields">
										<div class="vibe-field-group">
											<label class="vibe-field-label"><?php _e('Special pricing for Vibe users', 'woocommerce-gateway-vibe'); ?></label>
											<div class="vibe-input-wrapper">
												<input type="text" name="new_price_prefix" value="<?php echo esc_attr($settings['new_price_prefix']); ?>" placeholder="<?php _e('e.g., Special Price:', 'woocommerce-gateway-vibe'); ?>" class="vibe-prefix-input">
												<span class="vibe-input-preview"><?php echo esc_html($settings['new_price_prefix']); ?>1,100 تومان</span>
											</div>
										</div>
									</div>
								</div>

								<!-- Original Price Section -->
								<div class="vibe-price-config-section">
									<div class="vibe-price-config-header">
										<span class="vibe-price-icon vibe-original-icon">🏷️</span>
										<h4><?php _e('Original Price', 'woocommerce-gateway-vibe'); ?></h4>
									</div>
									<div class="vibe-price-config-fields">
										<div class="vibe-field-group">
											<label class="vibe-field-label"><?php _e('Standard pricing for reference', 'woocommerce-gateway-vibe'); ?></label>
											<div class="vibe-input-wrapper">
												<input type="text" name="original_price_prefix" value="<?php echo esc_attr($settings['original_price_prefix']); ?>" placeholder="<?php _e('e.g., Regular Price:', 'woocommerce-gateway-vibe'); ?>" class="vibe-prefix-input">
												<span class="vibe-input-preview"><?php echo esc_html($settings['original_price_prefix']); ?>1,000 تومان</span>
											</div>
										</div>
									</div>
								</div>

								<!-- Preview Section -->
								<div class="vibe-price-preview-section">
									<div class="vibe-preview-header">
										<span class="vibe-preview-icon">👁️</span>
										<h4><?php _e('Live Preview', 'woocommerce-gateway-vibe'); ?></h4>
									</div>
									<div class="vibe-preview-content">
										<div class="vibe-preview-price dynamic">
											<span class="vibe-preview-prefix" id="dynamic-prefix-preview"><?php echo esc_html($settings['new_price_prefix']); ?></span><span class="vibe-preview-amount">1,100 تومان</span>
										</div>
										<div class="vibe-preview-price original">
											<span class="vibe-preview-prefix" id="original-prefix-preview"><?php echo esc_html($settings['original_price_prefix']); ?></span><span class="vibe-preview-amount">1,000 تومان</span>
										</div>
									</div>
								</div>
							</div>
							<p class="description"><?php _e('Customize the text that appears before and after each price. The preview shows how prices will appear on your product pages.', 'woocommerce-gateway-vibe'); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" class="button-primary" value="<?php _e('Save Settings', 'woocommerce-gateway-vibe'); ?>">
				</p>
			</form>

			<style>
				/* Vibe Prefix/Suffix Modern Design */
				.vibe-prefix-suffix-container {
					background: #f9f9f9;
					border: 1px solid #e1e1e1;
					border-radius: 8px;
					padding: 20px;
					margin-top: 10px;
				}

				.vibe-price-config-section {
					background: white;
					border: 1px solid #ddd;
					border-radius: 6px;
					padding: 20px;
					margin-bottom: 15px;
					box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
				}

				.vibe-price-config-header {
					display: flex;
					align-items: center;
					margin-bottom: 15px;
					padding-bottom: 10px;
					border-bottom: 1px solid #f0f0f0;
				}

				.vibe-price-icon {
					font-size: 24px;
					margin-left: 12px;
				}

				.vibe-price-config-header h4 {
					margin: 0;
					color: #333;
					font-size: 16px;
					font-weight: 600;
				}

				.vibe-price-description {
					margin-right: auto;
					color: #666;
					font-size: 13px;
					font-style: italic;
				}

				.vibe-price-config-fields {
					display: grid;
					grid-template-columns: 1fr;
					gap: 20px;
				}

				.vibe-field-group {
					display: flex;
					flex-direction: column;
				}

				.vibe-field-label {
					font-weight: 500;
					color: #555;
					margin-bottom: 6px;
					font-size: 13px;
				}

				.vibe-input-wrapper {
					position: relative;
				}

				.vibe-prefix-input,
				.vibe-suffix-input {
					width: 100%;
					padding: 10px 12px;
					border: 2px solid #ddd;
					border-radius: 4px;
					font-size: 14px;
					transition: border-color 0.3s ease;
				}

				.vibe-prefix-input:focus,
				.vibe-suffix-input:focus {
					border-color: #0073aa;
					outline: none;
					box-shadow: 0 0 0 1px rgba(0, 115, 170, 0.3);
				}

				.vibe-input-preview {
					display: block;
					margin-top: 6px;
					padding: 8px;
					background: #f8f8f8;
					border: 1px solid #e8e8e8;
					border-radius: 3px;
					font-size: 13px;
					color: #666;
					min-height: 20px;
				}

				.vibe-price-preview-section {
					background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
					color: white;
					border-radius: 6px;
					padding: 20px;
					margin-top: 10px;
				}

				.vibe-preview-header {
					display: flex;
					align-items: center;
					margin-bottom: 15px;
				}

				.vibe-preview-icon {
					font-size: 20px;
					margin-left: 10px;
				}

				.vibe-preview-header h4 {
					margin: 0;
					color: white;
					font-size: 16px;
					font-weight: 600;
				}

				.vibe-preview-content {
					display: flex;
					flex-direction: column;
					gap: 10px;
				}

				.vibe-preview-price {
					padding: 12px 16px;
					border-radius: 4px;
					font-size: 16px;
					font-weight: 500;
				}

				.vibe-preview-price.dynamic {
					background: rgba(255, 255, 255, 0.2);
					border: 1px solid rgba(255, 255, 255, 0.3);
				}

				.vibe-preview-price.original {
					background: rgba(255, 255, 255, 0.1);
					border: 1px solid rgba(255, 255, 255, 0.2);
					opacity: 0.8;
				}

				.vibe-preview-amount {
					font-weight: bold;
					color: #fff;
				}

				.vibe-preview-prefix,
				.vibe-preview-suffix {
					color: rgba(255, 255, 255, 0.9);
				}

				/* Responsive Design */
				@media (max-width: 768px) {
					.vibe-price-config-header {
						flex-direction: column;
						align-items: flex-start;
					}

					.vibe-price-description {
						margin-left: 0;
						margin-top: 5px;
					}
				}
			</style>

			<script>
				jQuery(document).ready(function($) {
					// Live preview updates for prefix/suffix
					function updatePreview() {
						var dynamicPrefix = $('input[name="new_price_prefix"]').val();
						var originalPrefix = $('input[name="original_price_prefix"]').val();

						$('#dynamic-prefix-preview').text(dynamicPrefix);
						$('#original-prefix-preview').text(originalPrefix);

						// Update inline previews
						$('.vibe-prefix-input').each(function() {
							var preview = $(this).closest('.vibe-input-wrapper').find('.vibe-input-preview');
							var prefix = $(this).val();
							var samplePrice = $(this).attr('name').includes('new_') ? '1,100 تومان' : '1,000 تومان';
							preview.text(prefix + samplePrice);
						});
					}

					// Bind events for live preview
					$('input[name="new_price_prefix"], input[name="original_price_prefix"]').on('input keyup', updatePreview);

					// Initial preview update
					updatePreview();
					
					// Initialize WordPress color pickers if available
					if (typeof jQuery.fn.wpColorPicker !== 'undefined') {
						jQuery('.vibe-color-picker').wpColorPicker({
							change: function(event, ui) {
								// Update the input field value with the selected color
								var colorValue = ui.color.toString();
								jQuery(this).val(colorValue).trigger('change');
							},
							clear: function() {
								// Clear the input field value
								jQuery(this).val('').trigger('change');
							}
						});
					} else {
						// Fallback: Add placeholder text for manual entry
						jQuery('.vibe-color-picker').attr('placeholder', '#000000').addClass('regular-text');
					}
				});
			</script>
		</div>
	<?php
	}

	/**
	 * Display performance page.
	 */
	public function display_performance_page()
	{
		$cache_stats = $this->cache_manager->get_cache_stats();

	?>
		<div class="wrap">
			<h1><?php _e('Performance', 'woocommerce-gateway-vibe'); ?></h1>

			<div class="card">
				<h2><?php _e('Cache Statistics', 'woocommerce-gateway-vibe'); ?></h2>
				<table class="widefat">
					<tr>
						<td><?php _e('Object Cache Enabled', 'woocommerce-gateway-vibe'); ?></td>
						<td><?php echo $cache_stats['object_cache_enabled'] ? __('Yes', 'woocommerce-gateway-vibe') : __('No', 'woocommerce-gateway-vibe'); ?></td>
					</tr>
					<tr>
						<td><?php _e('Database Cache Entries', 'woocommerce-gateway-vibe'); ?></td>
						<td><?php echo esc_html($cache_stats['database_cache_entries']); ?></td>
					</tr>
					<tr>
						<td><?php _e('Database Cache Size', 'woocommerce-gateway-vibe'); ?></td>
						<td><?php echo size_format($cache_stats['database_cache_size']); ?></td>
					</tr>
					<tr>
						<td><?php _e('Transient Entries', 'woocommerce-gateway-vibe'); ?></td>
						<td><?php echo esc_html($cache_stats['transient_entries']); ?></td>
					</tr>
				</table>

				<p>
					<a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=vibe_clear_pricing_cache'), 'clear_cache'); ?>"
						class="button" onclick="return confirm('<?php _e('Are you sure you want to clear all pricing caches?', 'woocommerce-gateway-vibe'); ?>')">
						<?php _e('Clear All Caches', 'woocommerce-gateway-vibe'); ?>
					</a>
				</p>
			</div>
		</div>
<?php
	}

	/**
	 * Get pricing rules from database.
	 *
	 * @return array Pricing rules.
	 */
	private function get_pricing_rules()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'vibe_pricing_rules';

		return $wpdb->get_results("
			SELECT * FROM {$table_name} 
			ORDER BY priority DESC, name ASC
		", ARRAY_A);
	}

	/**
	 * Get single pricing rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return array Rule data.
	 */
	private function get_pricing_rule($rule_id)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'vibe_pricing_rules';

		$rule = $wpdb->get_row($wpdb->prepare("
			SELECT * FROM {$table_name} WHERE id = %d
		", $rule_id), ARRAY_A);

		return $rule ? $rule : $this->get_default_rule();
	}

	/**
	 * Get default rule structure.
	 *
	 * @return array Default rule.
	 */
	private function get_default_rule()
	{
		return array(
			'id' => 0,
			'name' => '',
			'description' => '',
			'priority' => 0,
			'status' => 'active',
			'referrer_conditions' => json_encode(array(
				'domains' => array('vibe.ir', '*.vibe.ir'),
				'match_type' => 'contains'
			)),
			'product_conditions' => json_encode(array(
				'target_type' => 'all'
			)),
			'price_adjustment' => json_encode(array(
				'type' => 'percentage',
				'value' => 0
			)),
			'discount_integration' => 'apply',
			'display_options' => json_encode(array()),
		);
	}

	/**
	 * Save pricing rule.
	 */
	public function save_pricing_rule()
	{
		// Verify nonce and permissions
		if (!wp_verify_nonce($_POST['pricing_rule_nonce'], 'save_pricing_rule') || !current_user_can('manage_woocommerce')) {
			wp_die(__('Security check failed', 'woocommerce-gateway-vibe'));
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'vibe_pricing_rules';

		$rule_id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;
		$name = sanitize_text_field($_POST['rule_name']);
		$description = sanitize_textarea_field($_POST['rule_description']);
		$priority = intval($_POST['rule_priority']);
		$status = isset($_POST['rule_status']) ? 'active' : 'inactive';

		// Hardcode referrer conditions to vibe.ir and subdomains
		$referrer_conditions = json_encode(array(
			'domains' => array('vibe.ir', '*.vibe.ir'),
			'match_type' => 'contains'
		));

		// Process product conditions based on target type
		$target_type = sanitize_text_field($_POST['target_type']);
		$product_conditions = array('target_type' => $target_type);

		switch ($target_type) {
			case 'specific':
				$product_conditions['product_ids'] = isset($_POST['target_products']) ? array_map('intval', $_POST['target_products']) : array();
				break;

			case 'categories':
				$product_conditions['categories'] = isset($_POST['target_categories']) ? array_map('intval', $_POST['target_categories']) : array();
				$product_conditions['category_logic'] = sanitize_text_field($_POST['category_logic']);
				break;

			case 'tags':
				$product_conditions['tags'] = isset($_POST['target_tags']) ? array_map('intval', $_POST['target_tags']) : array();
				$product_conditions['tag_logic'] = sanitize_text_field($_POST['tag_logic']);
				break;

			case 'price_range':
				$product_conditions['min_price'] = !empty($_POST['min_price']) ? floatval($_POST['min_price']) : '';
				$product_conditions['max_price'] = !empty($_POST['max_price']) ? floatval($_POST['max_price']) : '';
				break;

			case 'complex':
				$product_conditions['complex_logic'] = sanitize_textarea_field($_POST['complex_logic']);
				break;
		}

		// Process price adjustment
		$adjustment_type = sanitize_text_field($_POST['adjustment_type']);
		$adjustment_value = floatval($_POST['adjustment_value']);
		$price_adjustment = json_encode(array(
			'type' => $adjustment_type,
			'value' => $adjustment_value
		));

		// Process discount integration
		$discount_integration = sanitize_text_field($_POST['discount_integration']);

		$data = array(
			'name' => $name,
			'description' => $description,
			'priority' => $priority,
			'status' => $status,
			'referrer_conditions' => $referrer_conditions,
			'product_conditions' => json_encode($product_conditions),
			'price_adjustment' => $price_adjustment,
			'discount_integration' => $discount_integration,
			'updated_at' => current_time('mysql'),
		);

		if ($rule_id > 0) {
			// Update existing rule
			$wpdb->update($table_name, $data, array('id' => $rule_id));
			$message = __('Rule updated successfully.', 'woocommerce-gateway-vibe');
		} else {
			// Create new rule
			$data['created_at'] = current_time('mysql');
			$wpdb->insert($table_name, $data);
			$message = __('Rule created successfully.', 'woocommerce-gateway-vibe');
		}

		// Clear cache
		$this->cache_manager->clear_pricing_cache();

		// Redirect with success message
		wp_redirect(add_query_arg(array(
			'page' => 'vibe-dynamic-pricing',
			'message' => urlencode($message)
		), admin_url('admin.php')));
		exit;
	}

	/**
	 * Delete pricing rule.
	 */
	public function delete_pricing_rule()
	{
		$rule_id = intval($_GET['rule_id']);

		if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_rule_' . $rule_id) || !current_user_can('manage_woocommerce')) {
			wp_die(__('Security check failed', 'woocommerce-gateway-vibe'));
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'vibe_pricing_rules';

		$wpdb->delete($table_name, array('id' => $rule_id));

		// Clear cache
		$this->cache_manager->clear_pricing_cache();

		wp_redirect(add_query_arg(array(
			'page' => 'vibe-dynamic-pricing',
			'message' => urlencode(__('Rule deleted successfully.', 'woocommerce-gateway-vibe'))
		), admin_url('admin.php')));
		exit;
	}

	/**
	 * Toggle pricing rule status.
	 */
	public function toggle_pricing_rule()
	{
		$rule_id = intval($_GET['rule_id']);

		if (!wp_verify_nonce($_GET['_wpnonce'], 'toggle_rule_' . $rule_id) || !current_user_can('manage_woocommerce')) {
			wp_die(__('Security check failed', 'woocommerce-gateway-vibe'));
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'vibe_pricing_rules';

		// Get current status
		$current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_name} WHERE id = %d", $rule_id));
		$new_status = $current_status === 'active' ? 'inactive' : 'active';

		$wpdb->update($table_name, array('status' => $new_status), array('id' => $rule_id));

		// Clear cache
		$this->cache_manager->clear_pricing_cache();

		wp_redirect(add_query_arg(array(
			'page' => 'vibe-dynamic-pricing',
			'message' => urlencode(__('Rule status updated.', 'woocommerce-gateway-vibe'))
		), admin_url('admin.php')));
		exit;
	}

	/**
	 * Save pricing settings.
	 */
	public function save_pricing_settings()
	{
		if (!wp_verify_nonce($_POST['pricing_settings_nonce'], 'save_pricing_settings') || !current_user_can('manage_woocommerce')) {
			wp_die(__('Security check failed', 'woocommerce-gateway-vibe'));
		}

		// Update enable/disable setting
		update_option('wc_vibe_dynamic_pricing_enabled', isset($_POST['enable_dynamic_pricing']) ? 'yes' : 'no');

		// Update apply mode setting
		$apply_mode = isset($_POST['apply_mode']) ? sanitize_text_field($_POST['apply_mode']) : 'combined';
		update_option('wc_vibe_dynamic_pricing_apply_mode', $apply_mode);

		// Update emergency disable setting
		update_option('wc_vibe_dynamic_pricing_emergency_disable', isset($_POST['emergency_disable']) ? 'yes' : 'no');

		// Update display settings (both prices always shown)
		$display_settings = array(
			'display_layout' => 'two_line',
			'price_order' => isset($_POST['price_order']) ? sanitize_text_field($_POST['price_order']) : 'original_first',
			'new_price_font_size' => isset($_POST['new_price_font_size']) ? sanitize_text_field($_POST['new_price_font_size']) : '100%',
			'new_price_color' => isset($_POST['new_price_color']) ? sanitize_hex_color($_POST['new_price_color']) : '',
			'new_price_font_weight' => isset($_POST['new_price_font_weight']) ? $this->sanitize_font_weight($_POST['new_price_font_weight']) : 'bold',
			'original_price_font_size' => isset($_POST['original_price_font_size']) ? sanitize_text_field($_POST['original_price_font_size']) : '85%',
			'original_price_color' => isset($_POST['original_price_color']) ? sanitize_hex_color($_POST['original_price_color']) : '#999999',
			'original_price_font_weight' => isset($_POST['original_price_font_weight']) ? $this->sanitize_font_weight($_POST['original_price_font_weight']) : 'normal',
			'new_price_prefix' => isset($_POST['new_price_prefix']) ? sanitize_text_field($_POST['new_price_prefix']) : '',
			'original_price_prefix' => isset($_POST['original_price_prefix']) ? sanitize_text_field($_POST['original_price_prefix']) : '',
		);

		update_option('wc_vibe_price_display_settings', $display_settings);

		// Clear pricing cache when settings change
		$this->cache_manager->clear_pricing_cache();

		wp_redirect(add_query_arg(array(
			'page' => 'vibe-pricing-display',
			'message' => urlencode(__('Settings saved successfully.', 'woocommerce-gateway-vibe'))
		), admin_url('admin.php')));
		exit;
	}

	/**
	 * AJAX: Clear pricing cache.
	 */
	public function ajax_clear_pricing_cache()
	{
		if (!wp_verify_nonce($_GET['_wpnonce'], 'clear_cache') || !current_user_can('manage_woocommerce')) {
			wp_die(__('Security check failed', 'woocommerce-gateway-vibe'));
		}

		$this->cache_manager->clear_pricing_cache();

		wp_redirect(add_query_arg(array(
			'page' => 'vibe-pricing-performance',
			'message' => urlencode(__('Cache cleared successfully.', 'woocommerce-gateway-vibe'))
		), admin_url('admin.php')));
		exit;
	}

	/**
	 * AJAX: Get pricing statistics.
	 */
	public function ajax_get_pricing_stats()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(__('Permission denied', 'woocommerce-gateway-vibe'));
		}

		$stats = $this->cache_manager->get_cache_stats();
		wp_send_json_success($stats);
	}

	/**
	 * Enqueue admin assets.
	 */
	public function enqueue_admin_assets($hook)
	{
		if (strpos($hook, 'vibe-') === false) {
			return;
		}

		// Enqueue WordPress color picker
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('wp-color-picker');

		// Check if CSS file exists before enqueuing
		$css_file = WC_VIBE_PLUGIN_PATH . 'assets/css/admin-pricing.css';
		if (file_exists($css_file)) {
			wp_enqueue_style(
				'vibe-admin-pricing',
				WC_VIBE_PLUGIN_URL . 'assets/css/admin-pricing.css',
				array('wp-color-picker'),
				WC_VIBE_VERSION
			);
		}

		// Check if JS file exists before enqueuing
		$js_file = WC_VIBE_PLUGIN_PATH . 'assets/js/admin-pricing.js';
		if (file_exists($js_file)) {
			wp_enqueue_script(
				'vibe-admin-pricing',
				WC_VIBE_PLUGIN_URL . 'assets/js/admin-pricing.js',
				array('jquery', 'wp-color-picker'),
				WC_VIBE_VERSION,
				true
			);
		}
	}

	/**
	 * Add plugin action links.
	 */
	public function add_plugin_action_links($links)
	{
		$action_links = array(
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				admin_url('admin.php?page=vibe-dynamic-pricing'),
				__('Settings', 'woocommerce-gateway-vibe')
			),
		);

		return array_merge($action_links, $links);
	}

	/**
	 * Handle form submissions.
	 */
	public function handle_form_submissions()
	{
		// Handle pricing rules submissions
		if (isset($_POST['vibe_pricing_nonce']) && wp_verify_nonce($_POST['vibe_pricing_nonce'], 'vibe_save_pricing_rules')) {
			$this->save_pricing_rules();
		}

		// Handle display settings submissions
		if (isset($_POST['vibe_display_nonce']) && wp_verify_nonce($_POST['vibe_display_nonce'], 'vibe_save_display_settings')) {
			$this->save_display_settings();
		}

		// Handle debug settings submissions
		if (isset($_POST['vibe_debug_nonce']) && wp_verify_nonce($_POST['vibe_debug_nonce'], 'vibe_save_debug_settings')) {
			$this->save_debug_settings();
		}

		// Handle clear debug logs
		if (isset($_POST['clear_debug_logs'])) {
			delete_option('vibe_debug_logs');
			add_action('admin_notices', function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . __('Debug logs cleared successfully.', 'woocommerce-gateway-vibe') . '</p></div>';
			});
		}
	}

	/**
	 * Save debug settings.
	 */
	private function save_debug_settings()
	{
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		$debug_enabled = isset($_POST['vibe_enable_debug_logging']) ? true : false;
		update_option('wc_vibe_enable_debug_logging', $debug_enabled);

		add_action('admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>' . __('Debug settings saved successfully.', 'woocommerce-gateway-vibe') . '</p></div>';
		});

		// Redirect to prevent form resubmission
		wp_redirect(admin_url('admin.php?page=vibe-dynamic-pricing&tab=debug-settings&saved=1'));
		exit;
	}

	/**
	 * Save display settings.
	 */
	private function save_display_settings()
	{
		if (!wp_verify_nonce($_POST['vibe_display_nonce'], 'vibe_save_display_settings') || !current_user_can('manage_woocommerce')) {
			wp_die(__('Security check failed', 'woocommerce-gateway-vibe'));
		}

		// Update enable/disable setting
		update_option('wc_vibe_dynamic_pricing_enabled', isset($_POST['enable_dynamic_pricing']) ? 'yes' : 'no');

		// Update apply mode setting
		$apply_mode = isset($_POST['apply_mode']) ? sanitize_text_field($_POST['apply_mode']) : 'combined';
		update_option('wc_vibe_dynamic_pricing_apply_mode', $apply_mode);

		// Update emergency disable setting
		update_option('wc_vibe_dynamic_pricing_emergency_disable', isset($_POST['emergency_disable']) ? 'yes' : 'no');

		// Update display settings (both prices always shown)
		$display_settings = array(
			'display_layout' => 'two_line',
			'price_order' => isset($_POST['price_order']) ? sanitize_text_field($_POST['price_order']) : 'original_first',
			'new_price_font_size' => isset($_POST['new_price_font_size']) ? sanitize_text_field($_POST['new_price_font_size']) : '100%',
			'new_price_color' => isset($_POST['new_price_color']) ? sanitize_hex_color($_POST['new_price_color']) : '',
			'new_price_font_weight' => isset($_POST['new_price_font_weight']) ? $this->sanitize_font_weight($_POST['new_price_font_weight']) : 'bold',
			'original_price_font_size' => isset($_POST['original_price_font_size']) ? sanitize_text_field($_POST['original_price_font_size']) : '85%',
			'original_price_color' => isset($_POST['original_price_color']) ? sanitize_hex_color($_POST['original_price_color']) : '#999999',
			'original_price_font_weight' => isset($_POST['original_price_font_weight']) ? $this->sanitize_font_weight($_POST['original_price_font_weight']) : 'normal',
			'new_price_prefix' => isset($_POST['new_price_prefix']) ? sanitize_text_field($_POST['new_price_prefix']) : '',
			'original_price_prefix' => isset($_POST['original_price_prefix']) ? sanitize_text_field($_POST['original_price_prefix']) : '',
		);

		update_option('wc_vibe_price_display_settings', $display_settings);

		// Clear pricing cache when settings change
		$this->cache_manager->clear_pricing_cache();

		// Show success message
		add_action('admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>' . __('Display settings saved successfully.', 'woocommerce-gateway-vibe') . '</p></div>';
		});

		// Redirect to prevent form resubmission
		wp_redirect(admin_url('admin.php?page=vibe-pricing-display&saved=1'));
		exit;
	}

	/**
	 * Save pricing rules (placeholder method).
	 */
	private function save_pricing_rules()
	{
		// This method is called from handle_form_submissions but may not be fully implemented
		// For now, just show a success message
		add_action('admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>' . __('Pricing rules processing - method needs implementation.', 'woocommerce-gateway-vibe') . '</p></div>';
		});
	}

	/**
	 * Get pricing engine instance for debugging.
	 *
	 * @return WC_Vibe_Pricing_Engine|null
	 */
	private function get_pricing_engine_instance()
	{
		// Try to get the pricing engine from the main gateway class
		if (class_exists('WC_Vibe_Payment_Gateway')) {
			$gateways = WC()->payment_gateways->get_available_payment_gateways();
			if (isset($gateways['vibe'])) {
				return $gateways['vibe']->get_pricing_engine();
			}
		}
		return null;
	}

	/**
	 * Sanitize font weight value.
	 *
	 * @param string $font_weight Font weight value to sanitize.
	 * @return string Sanitized font weight.
	 */
	private function sanitize_font_weight($font_weight) {
		$allowed_weights = array(
			'normal', 'bold', 'bolder', 'lighter',
			'100', '200', '300', '400', '500', '600', '700', '800', '900'
		);
		
		$font_weight = sanitize_text_field($font_weight);
		
		if (in_array($font_weight, $allowed_weights, true)) {
			return $font_weight;
		}
		
		// Default fallback
		return 'normal';
	}
}
