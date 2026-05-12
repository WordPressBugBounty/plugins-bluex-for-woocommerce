<?php

/**
 * Correios Pudos Map.
 *
 * @package WooCommerce_Correios/Classes/pudos
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Correios pudos map class
 */
class WC_Correios_PudosMap
{
	protected $_basePathUrl;
	protected $_devMode;

	/**
	 * Initialize actions.
	 */
	public function __construct()
	{
		
		$configData = get_option('woocommerce_correios-integration_settings');
		
		if (isset($configData['pudoEnable']) && $configData['pudoEnable'] == "yes") {
			add_action('init', array($this, 'init'));
		} else {
			bluex_log('warning', 'PUDOS: PUDO NOT enabled. pudoEnable = ' . ($configData['pudoEnable'] ?? 'not set'));
		}
		
		// Comprobación con isset para evitar el error:
		$this->_devMode = isset($configData['devOptions']) && $configData['devOptions'] === "yes";

		// Decide the base path URL based on the devMode status
		if ($this->_devMode && !empty($configData['alternativeBasePath'])) {
			$this->_basePathUrl = $configData['alternativeBasePath'];
		} else {
			$this->_basePathUrl = 'https://eplin.api.blue.cl';
		}
		
	}

	/**
	 * Hook into various actions for frontend functionalities.
	 * Updated for modern WooCommerce compatibility.
	 */
	public function init()
	{		
		// Enqueue scripts and styles
		add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));

		// Legacy checkout now uses the native WC shipping-method radios as the
		// single source of truth: `bluex-pudo` is a registered WC_Shipping_Method
		// (see class-wc-bluex-pudo.php). The custom shippingBlue radio group
		// was removed — the widget and hint are driven from JS based on which
		// native rate is selected. Only `agencyId` is still persisted via a
		// hidden input so it travels with the checkout POST.
		add_action('woocommerce_checkout_after_order_review', array($this, 'render_custom_input'), 10);
		
		// Handle form processing
		add_action('woocommerce_checkout_update_order_meta', array($this, 'save_custom_input_to_order_meta'), 10);

		// Safety net: if a user picks the native bluex-pudo rate in legacy checkout
		// (possible since bluex-pudo is now a registered shipping method), rewrite
		// the item to bluex-ex before it's attached to the order so the saved
		// shipping_method matches what the BlueX backend expects. The dominant
		// legacy flow (native bluex-ex + custom pudoShipping radio) is unaffected
		// because this hook is a no-op for non-pudo items.
		//
		// Hook choice verified against WC source (includes/class-wc-checkout.php
		// create_order_shipping_lines): `woocommerce_checkout_create_order_shipping_item`
		// fires per item BEFORE $order->add_item(), which is why the helper here
		// does not save() — WC's own persist cycle handles it.
		add_action('woocommerce_checkout_create_order_shipping_item', array($this, 'rewrite_bluex_pudo_to_ex_on_create'), 10, 4);
		
		// AJAX handlers
		add_action('wp_ajax_clear_shipping_cache', array($this, 'clear_shipping_cache'));
		add_action('wp_ajax_nopriv_clear_shipping_cache', array($this, 'clear_shipping_cache'));
		
		// Add checkout validation
		add_action('woocommerce_checkout_process', array($this, 'validate_checkout_fields'));
		
		// Add custom checkout fields to order review
		add_action('woocommerce_checkout_update_order_review', array($this, 'update_order_review_callback'));
		
	}

	/**
	 * Enqueue scripts on checkout page.
	 * Updated for modern WooCommerce compatibility.
	 */
	public function frontend_scripts()
	{
		// Skip if using Blocks Checkout
		if (has_block('woocommerce/checkout')) {
			return;
		}

		if (is_checkout()) {
			$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

			// Enqueue the main script with proper dependencies
			wp_enqueue_script(
				'bluex-checkout-pudo',
				plugins_url('assets/js/frontend/custom-checkout-map' . $suffix . '.js', WC_Correios::get_main_file()),
				array('jquery', 'wc-checkout'),
				WC_CORREIOS_VERSION ?? '1.0.0',
				true
			);

			// Modal styles for the pickup-point picker dialog and the
			// "Seleccionar / Cambiar punto" link rendered below the rate.
			wp_enqueue_style(
				'bluex-checkout-pudo-modal',
				plugins_url('assets/css/frontend/bluex-pudo-modal.css', WC_Correios::get_main_file()),
				array(),
				WC_CORREIOS_VERSION ?? '1.0.0'
			);
			
			// Localize script with AJAX URL and nonce for security
			wp_localize_script('bluex-checkout-pudo', 'bluex_checkout_params', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('bluex_checkout_nonce'),
				'widget_base_urls' => array(
					'prod' => 'https://widget-pudo.blue.cl',
					'qa' => 'https://widget-pudo.qa.blue.cl',
					'dev' => 'https://widget-pudo.dev.blue.cl'
				),
				'base_path_url' => $this->_basePathUrl
			));
			
		}
	}
	/**
	 * Render the hidden PUDO inputs so the selection travels with the classic
	 * checkout POST and the `woocommerce_checkout_update_order_review` AJAX.
	 *
	 * `agencyId` is the BlueX pickup-point identifier; `agency_name` and
	 * `agency_address` hydrate the WC session so `WC_BlueX_Pudo::calculate_shipping`
	 * can render the dynamic "Retiro en Punto Blue Express - <agency>" label.
	 * Without those two, the label stays as the base title because the session
	 * read returns empty strings.
	 *
	 * The legacy `isPudoSelected` hidden input is gone: the active WC shipping
	 * method radio already conveys that state (bluex-pudo selected ⇔ PUDO).
	 *
	 * On initial render we hydrate the `value` attribute from the WC session
	 * so that (a) page reloads keep the selection visible, and (b) the JS
	 * side can tell "an agency is already persisted" on first paint and
	 * avoid snapshotting the pickup address as if it were the customer's
	 * original address (see syncFromNativeRate).
	 */
	function render_custom_input()
	{
		$agency_id      = WC()->session ? (string) WC()->session->get('bluex_agency_id', '') : '';
		$agency_name    = WC()->session ? (string) WC()->session->get('bluex_agency_name', '') : '';
		$agency_address = WC()->session ? (string) WC()->session->get('bluex_agency_address', '') : '';
?>
		<input type="hidden" name="agencyId" id="agencyId" value="<?php echo esc_attr($agency_id); ?>" />
		<input type="hidden" name="agency_name" id="agency_name" value="<?php echo esc_attr($agency_name); ?>" />
		<input type="hidden" name="agency_address" id="agency_address" value="<?php echo esc_attr($agency_address); ?>" />
	<?php
	}

	/**
	 * Normalize the saved shipping method from bluex-pudo to bluex-ex.
	 *
	 * Hooked on `woocommerce_checkout_create_order_shipping_item` which fires
	 * per shipping item BEFORE it's attached to the order — no-op for non-pudo
	 * items. Delegates to WC_BlueX_Pudo::rewrite_shipping_item_to_bluex_ex
	 * which is the single source of truth for the rewrite rule (shared with
	 * the Blocks flow).
	 *
	 * @param WC_Order_Item_Shipping $item
	 * @param int                    $package_key
	 * @param array                  $package
	 * @param WC_Order               $order
	 */
	public function rewrite_bluex_pudo_to_ex_on_create($item, $package_key, $package, $order)
	{
		if (class_exists('WC_BlueX_Pudo')) {
			WC_BlueX_Pudo::rewrite_shipping_item_to_bluex_ex($item);
		}
	}

	/**
	 * Persist PUDO data onto the order meta at checkout save time.
	 *
	 * Writes:
	 *   - agencyId        — from POST (the hidden input carried by the form).
	 *   - isPudoSelected  — derived: 'pudoShipping' iff the order currently has
	 *     an agencyId present, else 'normalShipping'. Kept for downstream
	 *     consumers that may read it. Not read from POST anymore.
	 *
	 * @param int $order_id The order ID.
	 */
	function save_custom_input_to_order_meta($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}

		$agency_id = isset($_POST['agencyId']) ? sanitize_text_field($_POST['agencyId']) : '';

		if ($agency_id !== '') {
			$order->update_meta_data('agencyId', $agency_id);
			$order->update_meta_data('isPudoSelected', 'pudoShipping');
		} else {
			$order->update_meta_data('isPudoSelected', 'normalShipping');
		}
		$order->save();
	}
	/**
	 * Block place-order when the customer chose bluex-pudo without picking a
	 * point. The POST carries `shipping_method[<index>]=bluex-pudo:<instance>`
	 * directly now (the old `shippingBlue` custom radio is gone).
	 */
	public function validate_checkout_fields()
	{
		if (!$this->is_bluex_pudo_selected_in_post()) {
			return;
		}
		if (empty($_POST['agencyId'])) {
			wc_add_notice(
				__('Por favor selecciona un punto Blue Express.', 'woocommerce-correios'),
				'error'
			);
		}
	}

	/**
	 * Mirror the PUDO state into the WC session on every AJAX checkout refresh.
	 * Session values are what `WC_BlueX_Pudo::calculate_shipping` reads to
	 * build the dynamic "Retiro en Punto Blue Express - <agency>" label and
	 * what the Blocks Store API extension_data_callback also reads for its
	 * client hydration. Keeping this in sync from the classic POST is what
	 * lets the legacy checkout feed the same session the shipping method
	 * relies on.
	 *
	 * @param string $post_data Serialized form data.
	 */
	public function update_order_review_callback($post_data)
	{
		if (!wp_verify_nonce($_POST['security'] ?? '', 'update-order-review')) {
			return;
		}

		parse_str($post_data, $data);

		if (!WC()->session) {
			return;
		}

		$is_pudo_selected = $this->is_bluex_pudo_method_id_in_array(
			isset($data['shipping_method']) && is_array($data['shipping_method']) ? $data['shipping_method'] : array()
		);
		WC()->session->set('bluex_pudo_selected', $is_pudo_selected);

		if ($is_pudo_selected && !empty($data['agencyId'])) {
			// Persist all three agency fields: `agency_id` is what the order
			// key by, `agency_name` drives the dynamic rate label in
			// WC_BlueX_Pudo::calculate_shipping ("Retiro en Punto Blue Express
			// - <agency>"), and `agency_address` is surfaced to downstream
			// consumers that need the display address.
			WC()->session->set('bluex_agency_id', sanitize_text_field($data['agencyId']));
			WC()->session->set(
				'bluex_agency_name',
				isset($data['agency_name']) ? sanitize_text_field($data['agency_name']) : ''
			);
			WC()->session->set(
				'bluex_agency_address',
				isset($data['agency_address']) ? sanitize_text_field($data['agency_address']) : ''
			);
		} else {
			// Switching out of PUDO: clear ALL three keys so a stale agency
			// name from a previous selection does not leak into a new PUDO
			// render, and so `calculate_shipping` falls back to the base title.
			WC()->session->__unset('bluex_agency_id');
			WC()->session->__unset('bluex_agency_name');
			WC()->session->__unset('bluex_agency_address');
		}

		// Invalidate the shipping-rate cache so `calculate_shipping()` — which
		// fires immediately after this hook in WC_AJAX::update_order_review —
		// regenerates rates using the freshly-set session values. Without
		// this, WC keeps returning the cached rate labels because the package
		// signature (destination, cart contents) did not change, only our
		// session did. This is the classic-checkout equivalent of the cache
		// invalidation the Blocks callback does in
		// class-wc-correios-blocks-integration.php::register_store_api_update_callback.
		if (WC()->cart) {
			foreach (array_keys(WC()->cart->get_shipping_packages()) as $package_key) {
				WC()->session->__unset('shipping_for_package_' . $package_key);
			}
		}
	}

	/**
	 * True iff any `shipping_method[<index>]` value in the current POST
	 * corresponds to the bluex-pudo method. WC posts rate IDs as
	 * `bluex-pudo:<instance_id>` so we match by prefix.
	 */
	private function is_bluex_pudo_selected_in_post()
	{
		$post_methods = isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])
			? $_POST['shipping_method']
			: array();
		return $this->is_bluex_pudo_method_id_in_array($post_methods);
	}

	private function is_bluex_pudo_method_id_in_array($methods)
	{
		foreach ($methods as $rate_id) {
			if (is_string($rate_id) && strpos($rate_id, 'bluex-pudo') === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clear the shipping cache - updated for modern WooCommerce.
	 */
	public function clear_shipping_cache()
	{
		// Verify nonce for security
		if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bluex_checkout_nonce')) {
			wp_send_json_error('Invalid nonce');
			return;
		}

		if (WC()->cart && WC()->session) {
		$packages = WC()->cart->get_shipping_packages();

		foreach ($packages as $package_key => $package) {
			WC()->session->__unset('shipping_for_package_' . $package_key);
			}
			
			// Clear WooCommerce transients
			wc_delete_shop_order_transients();
		}

		wp_send_json_success('Shipping cache cleared.');
	}


	/**
	 * Retrieves the widget URL based on the provided domain, appending additional parameters if present.
	 * 
	 * The function analyzes the domain to determine if it belongs to the 'qa' or 'dev' environments.
	 * It also appends the Google key and/or agency ID as query parameters if they are not empty.
	 *
	 * @param string $domain The domain to analyze.
	 * @param string|null $agencyId The agency ID to append to the URL as a parameter.
	 * @return string The URL of the widget corresponding to the environment with additional parameters if applicable.
	 */
	function getWidgetURL($domain, $agencyId = null)
	{
		// Define a regular expression to detect 'qa' or 'dev' in the domain
		// Pattern matches: https://eplin.api.blue.cl (prod), https://eplin.api.qa.blue.cl (qa), https://eplin.api.dev.blue.cl (dev)
		$pattern = '/https:\/\/eplin\.api\.(?:(qa|dev)\.)?blue\.cl/';

		// Use preg_match to extract the environment part if it matches the pattern
		preg_match($pattern, $domain, $matches);

		// Determine the environment; default to production if not 'qa' or 'dev'
		$environment = $matches[1] ?? '';

		// Map environment to respective base URL
		$urls = [
			'qa'  => 'https://widget-pudo.qa.blue.cl',
			'dev' => 'https://widget-pudo.dev.blue.cl',
			''    => 'https://widget-pudo.blue.cl', // Default case (production)
		];

		// Start with the base URL
		$url = $urls[$environment];

		// Initialize query parameters array
		$queryParams = [];


		// Append the agency ID if it is not null or empty
		if (!empty($agencyId)) {
			$queryParams['id'] = $agencyId;
		}

		// Append query parameters to the URL if any exist
		if (!empty($queryParams)) {
			$url .= '?' . http_build_query($queryParams);
		}

		return $url;
	}
}

new WC_Correios_PudosMap();
