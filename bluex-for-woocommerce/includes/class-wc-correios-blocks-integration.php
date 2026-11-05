<?php
/**
 * WooCommerce Blocks Integration
 *
 * Adds support for displaying delivery forecast in WooCommerce Block-based checkout.
 *
 * @package WooCommerce_Correios/Classes/Blocks
 * @since   4.0.0
 */

defined('ABSPATH') || exit;

/**
 * WooCommerce Blocks Integration Class
 */
class WC_Correios_Blocks_Integration
{
	/**
	 * Version of the script. Bump when JS/CSS assets change so the query string
	 * changes and CDN caches (Cloudflare etc.) serve the new file instead of the
	 * cached old one. Using a file-mtime-based suffix would be more robust but
	 * requires a stable plugin path; a manual bump is fine for this release.
	 *
	 * @var string
	 */
	private $script_version = '1.4.5';

	/**
	 * Constructor
	 */
	public function __construct()
	{
		// Only initialize if WooCommerce Blocks is active
		if (!$this->is_blocks_active()) {
			return;
		}

		// Expose delivery forecast data to Store API
		add_filter('woocommerce_store_api_shipping_rate_data', array($this, 'add_delivery_forecast_data'), 10, 2);

		// Enqueue frontend scripts for blocks checkout
		add_action('wp_enqueue_scripts', array($this, 'enqueue_blocks_scripts'));

		// Register Store API extension for PUDO agency data (read + write).
		add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_extension'));
		add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_update_callback'));

		// Persist agency data from the request / session into order meta.
		add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'update_order_from_store_api_request'), 10, 2);

		// Safety net for non-blocks order-creation paths.
		add_action('woocommerce_checkout_create_order', array($this, 'persist_pudo_meta_on_order_create'), 10, 2);
	}

	/**
	 * Check if WooCommerce Blocks is active
	 *
	 * @return bool
	 */
	private function is_blocks_active()
	{
		return class_exists('Automattic\WooCommerce\Blocks\Package');
	}

	/**
	 * Add delivery forecast data to Store API shipping rate response
	 *
	 * This makes the delivery forecast metadata available to the frontend blocks.
	 *
	 * @param array            $rate_data Shipping rate data.
	 * @param WC_Shipping_Rate $rate      Shipping rate object.
	 * @return array Modified shipping rate data.
	 */
	public function add_delivery_forecast_data($rate_data, $rate)
	{
		// Skip if not a Blue Express method
		if (!$this->is_bluex_method($rate->get_method_id())) {
			return $rate_data;
		}

		// Get metadata
		$meta_data = $rate->get_meta_data();

		// Add delivery forecast if available
		if (!empty($meta_data['_delivery_forecast'])) {
			$rate_data['delivery_forecast'] = sanitize_text_field($meta_data['_delivery_forecast']);
		}
		if ($rate->get_method_id() === 'bluex-pudo' && !empty($meta_data['_bluex_pudo_preview_agency_id'])) {
			$rate_data['pudo_preview_agency_id'] = sanitize_text_field($meta_data['_bluex_pudo_preview_agency_id']);
			$rate_data['pudo_preview_agency_name'] = sanitize_text_field($meta_data['_bluex_pudo_preview_agency_name'] ?? '');
			$rate_data['pudo_preview_agency_address'] = sanitize_text_field($meta_data['_bluex_pudo_preview_agency_address'] ?? '');
		}

		return $rate_data;
	}


	/**
	 * Check if shipping method is a Blue Express method
	 *
	 * @param string $method_id Method ID.
	 * @return bool
	 */
	private function is_bluex_method($method_id)
	{
		return is_string($method_id) && strpos($method_id, 'bluex-') === 0;
	}

	/**
	 * Register the Store API read-side extension for bluex_pudo.
	 *
	 * Exposes agency_id / agency_name / agency_address under the namespace
	 * `bluex_pudo` on CartSchema (NOT CheckoutSchema) so the data lands in
	 * `cart.extensions.bluex_pudo` which is what the `wc/store/cart` data
	 * store serves to the React client. Checkout schema would populate a
	 * different endpoint that our `select('wc/store/cart').getCartData()`
	 * consumer does NOT read, resulting in a permanently-empty extension
	 * on the client.
	 *
	 * Lets the client read the persisted selection on page load (survives
	 * refresh) and rebuild its UI state.
	 */
	public function register_store_api_extension()
	{
		$schema_callback = function () {
			return array(
				'agency_id' => array(
					'description' => __('ID de la agencia PUDO seleccionada.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => false,
				),
				'agency_name' => array(
					'description' => __('Nombre de la agencia PUDO seleccionada.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'agency_address' => array(
					'description' => __('Dirección de la agencia PUDO seleccionada.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'agency_city' => array(
					'description' => __('Comuna interna de la agencia PUDO seleccionada.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'agency_state' => array(
					'description' => __('Región interna de la agencia PUDO seleccionada.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'preview_rate_id' => array(
					'description' => __('ID de la tarifa PUDO que contiene el preview.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'preview_agency_id' => array(
					'description' => __('ID de la agencia PUDO usada como preview.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'preview_agency_name' => array(
					'description' => __('Nombre de la agencia PUDO usada como preview.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
				'preview_agency_address' => array(
					'description' => __('Dirección de la agencia PUDO usada como preview.', 'woocommerce-correios'),
					'type'        => array('string', 'null'),
					'context'     => array('view', 'edit'),
					'readonly'    => true,
				),
			);
		};
		$data_callback = function () {
			$preview = $this->get_selected_pudo_preview();
			if (!WC()->session) {
				return array(
					'agency_id'             => null,
					'agency_name'           => null,
					'agency_address'        => null,
					'agency_city'           => null,
					'agency_state'          => null,
					'preview_rate_id'       => null,
					'preview_agency_id'     => null,
					'preview_agency_name'   => null,
					'preview_agency_address' => null,
				);
			}
			$agency_id      = WC()->session->get('bluex_agency_id');
			$agency_name    = WC()->session->get('bluex_agency_name');
			$agency_address = WC()->session->get('bluex_agency_address');
			$agency_city    = WC()->session->get('bluex_agency_city');
			$agency_state   = WC()->session->get('bluex_agency_state');
			return array(
				'agency_id'      => $agency_id ? sanitize_text_field((string) $agency_id) : null,
				'agency_name'    => $agency_name ? sanitize_text_field((string) $agency_name) : null,
				'agency_address' => $agency_address ? sanitize_text_field((string) $agency_address) : null,
				'agency_city'    => $agency_city ? sanitize_text_field((string) $agency_city) : null,
				'agency_state'   => $agency_state ? sanitize_text_field((string) $agency_state) : null,
				'preview_rate_id'        => $preview['rate_id'] ?: null,
				'preview_agency_id'      => $preview['agency_id'] ?: null,
				'preview_agency_name'    => $preview['agency_name'] ?: null,
				'preview_agency_address' => $preview['agency_address'] ?: null,
			);
		};

		if (function_exists('woocommerce_store_api_register_endpoint_data')) {
			woocommerce_store_api_register_endpoint_data(array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'bluex_pudo',
				'schema_callback' => $schema_callback,
				'schema_type'     => ARRAY_A,
				'data_callback'   => $data_callback,
			));
		} elseif (
			class_exists('\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema') &&
			class_exists('\Automattic\WooCommerce\StoreApi\StoreApi')
		) {
			$extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(
				\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class
			);
			if ($extend instanceof \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema) {
				$extend->register_endpoint_data(array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => 'bluex_pudo',
					'schema_callback' => $schema_callback,
					'data_callback'   => $data_callback,
				));
			}
		}
	}

	/**
	 * Read preview metadata from the currently selected PUDO rate.
	 *
	 * Store API omits underscore-prefixed shipping-rate metadata, so the preview
	 * is exposed through the supported cart extension without making internal
	 * rate metadata public or persisting it as a confirmed selection.
	 *
	 * @return array
	 */
	private function get_selected_pudo_preview()
	{
		$empty = array(
			'rate_id'        => '',
			'agency_id'      => '',
			'agency_name'    => '',
			'agency_address' => '',
		);

		if (!WC()->session || !WC()->shipping()) {
			return $empty;
		}

		$packages = WC()->shipping()->get_packages();
		$chosen = (array) WC()->session->get('chosen_shipping_methods', array());
		if (!is_array($packages) || empty($packages)) {
			return $empty;
		}

		foreach ($packages as $package_id => $package) {
			$chosen_rate_id = isset($chosen[$package_id]) ? (string) $chosen[$package_id] : '';
			$rates = isset($package['rates']) && is_array($package['rates']) ? $package['rates'] : array();
			if ($chosen_rate_id === '' || empty($rates)) {
				continue;
			}

			$rate = isset($rates[$chosen_rate_id]) ? $rates[$chosen_rate_id] : null;
			if (!$rate || !is_callable(array($rate, 'get_method_id')) || $rate->get_method_id() !== 'bluex-pudo') {
				continue;
			}

			$meta = $rate->get_meta_data();
			if (empty($meta['_bluex_pudo_preview_agency_id'])) {
				continue;
			}

			return array(
				'rate_id'        => sanitize_text_field($chosen_rate_id),
				'agency_id'      => sanitize_text_field((string) $meta['_bluex_pudo_preview_agency_id']),
				'agency_name'    => sanitize_text_field((string) ($meta['_bluex_pudo_preview_agency_name'] ?? '')),
				'agency_address' => sanitize_text_field((string) ($meta['_bluex_pudo_preview_agency_address'] ?? '')),
			);
		}

		return $empty;
	}

	/**
	 * Register the Store API write-side callback for bluex_pudo.
	 *
	 * The React client calls `wc.blocksCheckout.extensionCartUpdate({ namespace:
	 * 'bluex_pudo', data: {...} })` when the user picks a pickup point. The endpoint
	 * `/wc/store/v1/cart/extensions` looks up the callback registered here, runs it,
	 * and the cart auto-refreshes with the new data. Without this registration the
	 * endpoint rejects with `woocommerce_rest_cart_extensions_error: There is no
	 * such namespace registered: bluex_pudo`.
	 *
	 * We persist the agency selection into the WC session; the checkout order
	 * creation step reads it back.
	 */
	public function register_store_api_update_callback()
	{
		if (!function_exists('woocommerce_store_api_register_update_callback')) {
			return;
		}

		woocommerce_store_api_register_update_callback(array(
			'namespace' => 'bluex_pudo',
			'callback'  => function ($data) {
				$data = is_array($data) ? $data : array();
				if (!WC()->session) {
					return;
				}

				$agency_id = isset($data['agency_id']) && is_scalar($data['agency_id'])
					? sanitize_text_field((string) $data['agency_id'])
					: '';

				// A request without an agency (e.g. an old client cleaning up after a
				// method switch) changes nothing: the confirmed point stays so it is
				// still selected when the shopper comes back to PUDO.
				if ($agency_id === '') {
					return;
				}

				$customer_destination = WC_Bluex_Pudo_Selection_State::customer_destination();
				WC_Bluex_Pudo_Selection_State::invalidate_if_destination_changed(WC()->session, $customer_destination);
				$destination = array(
					'city'  => isset($data['agency_city']) && is_scalar($data['agency_city'])
						? sanitize_text_field((string) $data['agency_city'])
						: '',
					'state' => isset($data['agency_state']) && is_scalar($data['agency_state'])
						? sanitize_text_field((string) $data['agency_state'])
						: '',
				);
				$validated = WC_Bluex_Pudo_Agency_Validator::validate($agency_id, $destination);
				if (is_wp_error($validated)) {
					if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
						throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
							$validated->get_error_code(),
							$validated->get_error_message(),
							400
						);
					}
					throw new Exception($validated->get_error_message());
				}
				if (class_exists('WC_BlueX_Pudo') && !WC_BlueX_Pudo::selected_point_is_quotable($validated['agency_id'])) {
					$message = __('No pudimos obtener la tarifa de este punto Blue Express. Elige otro punto o intenta nuevamente.', 'woocommerce-correios');
					if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
						throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('bluex_pudo_quote_unavailable', $message, 400);
					}
					throw new Exception($message);
				}

				WC()->session->set('bluex_agency_id', $validated['agency_id']);
				WC()->session->set('bluex_agency_validated_id', $validated['agency_id']);
				WC()->session->set('bluex_pudo_selected', true);
				WC()->session->set('bluex_agency_name', $validated['agency_name']);
				WC()->session->set('bluex_agency_address', $validated['agency_address']);
				WC()->session->set('bluex_agency_city', $validated['agency_city']);
				WC()->session->set('bluex_agency_state', $validated['agency_state']);
				WC()->session->set('bluex_pudo_selected_quote_valid', false);
				WC_Bluex_Pudo_Selection_State::remember_destination(
					WC()->session,
					$customer_destination
				);
				// Invalidate WC's shipping rate cache so the automatic recalc
				// that the /cart/extensions endpoint runs after this callback
				// picks up fresh session values. The dynamic LABEL is applied
				// at Store API response-time (apply_pudo_dynamic_label filter),
				// so that doesn't require invalidation — but the rate COST
				// (quoted for the selected or preview agency) still needs a fresh
				// calculation. Per WooCommerce Blocks docs, the
				// endpoint itself calls calculate_totals() after our callback,
				// so we do NOT call calculate_shipping() explicitly — that was
				// redundant and not idiomatic.
				$this->invalidate_shipping_rate_cache();
			},
		));
	}

	/**
	 * Drop cached package rates before WooCommerce's automatic recalculation.
	 */
	private function invalidate_shipping_rate_cache()
	{
		if (!WC()->session || !WC()->cart || !is_callable(array(WC()->cart, 'get_shipping_packages'))) {
			return;
		}

		foreach (array_keys((array) WC()->cart->get_shipping_packages()) as $package_key) {
			WC()->session->__unset('shipping_for_package_' . $package_key);
		}
	}

	/**
	 * Persist agency data into order meta during blocks-based checkout creation.
	 *
	 * Reads from (1) the Store API request extensions payload and (2) the WC session
	 * as a fallback — the client typically sends the data via `extensionCartUpdate`
	 * at point-selection time, not during the place-order request itself.
	 *
	 * Writes `agencyId`, `agencyName`, `agencyAddress`, `isPudoSelected` as order
	 * meta — the same keys the classic checkout persists, so downstream consumers
	 * (emails, ops integrations, Blue Express backend) see identical data regardless
	 * of which checkout flow was used.
	 *
	 * @param WC_Order        $order   The order being created.
	 * @param WP_REST_Request $request The full Store API request.
	 */
	public function update_order_from_store_api_request($order, $request)
	{
		// Only treat this as a PUDO order if the shipping method currently on
		// the order is bluex-pudo. This prevents stale session agency data from
		// leaking onto orders where the customer ultimately picked bluex-ex or
		// another method.
		$has_pudo_item = false;
		foreach ($order->get_items('shipping') as $item) {
			if ($item instanceof WC_Order_Item_Shipping && $item->get_method_id() === 'bluex-pudo') {
				$has_pudo_item = true;
				break;
			}
		}
		if (!$has_pudo_item) {
			return;
		}

		$extensions     = $request->get_param('extensions');
		$extensions     = is_array($extensions) ? $extensions : array();
		$pudo_extension = isset($extensions['bluex_pudo']) && is_array($extensions['bluex_pudo'])
			? $extensions['bluex_pudo']
			: array();
		$agency_id      = isset($pudo_extension['agency_id']) && is_scalar($pudo_extension['agency_id'])
			? (string) $pudo_extension['agency_id']
			: '';
		$agency_name    = '';
		$agency_address = '';

		if (WC()->session) {
			if ($agency_id === '') { $agency_id = (string) WC()->session->get('bluex_agency_id', ''); }
			$agency_name = (string) WC()->session->get('bluex_agency_name', '');
			$agency_address = (string) WC()->session->get('bluex_agency_address', '');
		}
		$validated_id = WC()->session ? (string) WC()->session->get('bluex_agency_validated_id', '') : '';

		// HARD STOP: the customer selected PUDO but didn't pick a point. Throw a
		// RouteException so the Store API returns a 400 with the error message,
		// which WooCommerce Blocks surfaces inline and re-enables the cart for
		// the customer to fix. Never allow a PUDO order to be created without
		// an agency_id — the Blue Express backend has no way to route it.
		if ($agency_id === '' || $validated_id === '' || !hash_equals($validated_id, $agency_id)) {
			if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'bluex_pudo_missing_agency',
					__('Selecciona un punto de retiro Blue Express para continuar.', 'woocommerce-correios'),
					400
				);
			}
			// Fallback for older WC where RouteException isn't available: block
			// with a generic WP_Error via throwing a standard Exception so the
			// order is not created with invalid state.
			throw new Exception(
				__('Selecciona un punto de retiro Blue Express para continuar.', 'woocommerce-correios')
			);
		}
		if (!WC()->session || !WC()->session->get('bluex_pudo_selected_quote_valid', false)) {
			if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'bluex_pudo_quote_unavailable',
					__('No pudimos actualizar la tarifa del punto Blue Express. Intenta nuevamente.', 'woocommerce-correios'),
					400
				);
			}
			throw new Exception(
				__('No pudimos actualizar la tarifa del punto Blue Express. Intenta nuevamente.', 'woocommerce-correios')
			);
		}

		$order->update_meta_data('agencyId', sanitize_text_field($agency_id));
		$order->update_meta_data('isPudoSelected', 'pudoShipping');
		if ($agency_name !== '') {
			$order->update_meta_data('agencyName', sanitize_text_field($agency_name));
		}
		if ($agency_address !== '') {
			$order->update_meta_data('agencyAddress', sanitize_text_field($agency_address));
		}

		// Now rewrite bluex-pudo → bluex-ex so the saved order carries the
		// shipping_method the BlueX backend expects. Helper lives on
		// WC_BlueX_Pudo (the shipping method class owns its rewrite).
		WC_BlueX_Pudo::rewrite_shipping_method_to_bluex_ex($order);

		$order->save();
	}

	/**
	 * Safety net for non-blocks order creation paths (REST admin, programmatic).
	 *
	 * Classic checkout already persists agency data via save_custom_input_to_order_meta
	 * in class-wc-correios-pudos-map.php. This hook only fills in when that path
	 * hasn't run and the session holds an agency selection.
	 */
	public function persist_pudo_meta_on_order_create($order, $data)
	{
		if (!WC()->session) {
			return;
		}

		// Only act on orders whose shipping method was bluex-pudo. Any stale
		// session data from an earlier PUDO selection must NOT leak onto an
		// order where the customer ultimately picked bluex-ex or another method.
		$has_pudo_item = false;
		foreach ($order->get_items('shipping') as $item) {
			if ($item instanceof WC_Order_Item_Shipping && $item->get_method_id() === 'bluex-pudo') {
				$has_pudo_item = true;
				break;
			}
		}
		if (!$has_pudo_item) {
			return;
		}

		// Rewrite bluex-pudo → bluex-ex so the saved order carries the
		// shipping_method the BlueX backend expects. Helper lives on
		// WC_BlueX_Pudo (the shipping method class owns its rewrite).
		WC_BlueX_Pudo::rewrite_shipping_method_to_bluex_ex($order);

		if (!empty($order->get_meta('agencyId'))) {
			return;
		}

		$agency_id = (string) WC()->session->get('bluex_agency_id', '');
		$validated_id = (string) WC()->session->get('bluex_agency_validated_id', '');
		if ($agency_id === '' || $validated_id === '' || !hash_equals($validated_id, $agency_id)) {
			return;
		}

		$order->update_meta_data('agencyId', sanitize_text_field($agency_id));
		$order->update_meta_data('isPudoSelected', 'pudoShipping');

		$agency_name    = (string) WC()->session->get('bluex_agency_name', '');
		$agency_address = (string) WC()->session->get('bluex_agency_address', '');
		if ($agency_name !== '')    { $order->update_meta_data('agencyName', sanitize_text_field($agency_name)); }
		if ($agency_address !== '') { $order->update_meta_data('agencyAddress', sanitize_text_field($agency_address)); }
	}

	/**
	 * Enqueue scripts for blocks-based checkout
	 */
	public function enqueue_blocks_scripts()
	{
		// Only load on checkout page with blocks
		if (!is_checkout() || !has_block('woocommerce/checkout')) {
			return;
		}

		$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
		$script_path = 'assets/js/frontend/blocks-delivery-forecast' . $suffix . '.js';
		$script_url = plugins_url($script_path, WC_Correios::get_main_file());
		$script_file = WC_Correios::get_plugin_path() . $script_path;

		// Check if file exists
		if (!file_exists($script_file)) {
			$script_path = 'assets/js/frontend/blocks-delivery-forecast.js';
			$script_url = plugins_url($script_path, WC_Correios::get_main_file());
		}

		// Enqueue script
		wp_enqueue_script(
			'wc-correios-blocks-delivery',
			$script_url,
			array('wp-data', 'wp-element', 'wp-hooks'),
			$this->script_version,
			true
		);

		// Enqueue PUDO integration script
		$this->enqueue_pudo_script();

		// Enqueue City Select integration script
		$this->enqueue_city_select_script();

		// Add inline script for debugging (only if WP_DEBUG is enabled)
		if (defined('WP_DEBUG') && WP_DEBUG) {
			wp_add_inline_script(
				'wc-correios-blocks-delivery',
				'console.log("BlueX Blocks Integration loaded");',
				'after'
			);
		}
	}

	/**
		* Enqueue PUDO integration script
		*/
	private function enqueue_pudo_script()
	{
		$configData = get_option('woocommerce_correios-integration_settings');

		// Only if PUDO is enabled
		if (!isset($configData['pudoEnable']) || $configData['pudoEnable'] !== "yes") {
			return;
		}

		$suffix      = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
		$script_path = 'assets/js/frontend/blocks-pudo-integration' . $suffix . '.js';
		$script_file = WC_Correios::get_plugin_path() . $script_path;
		if (!file_exists($script_file)) {
			$script_path = 'assets/js/frontend/blocks-pudo-integration.js';
		}
		$script_url = plugins_url($script_path, WC_Correios::get_main_file());

		wp_enqueue_script(
			'bluex-pudo-widget-security',
			plugins_url('assets/js/frontend/pudo-widget-security' . $suffix . '.js', WC_Correios::get_main_file()),
			array(),
			$this->script_version,
			true
		);

		// Shared core of the widget v2 (no-op while v1 is selected).
		WC_BlueX_Pudo_Widget_V2::enqueue_core($this->script_version);

		wp_enqueue_script(
			'bluex-blocks-pudo-integration',
			$script_url,
			array_merge(array('wp-data', 'wp-element', 'wp-hooks', 'wc-blocks-checkout', 'bluex-pudo-widget-security'), WC_BlueX_Pudo_Widget_V2::script_dependencies()),
			$this->script_version,
			true
		);

		// Styles for the injected option, widget iframe, and order-summary hint.
		wp_enqueue_style(
			'bluex-blocks-pudo-css',
			plugins_url('assets/css/frontend/bluex-pudo-blocks.css', WC_Correios::get_main_file()),
			array(),
			$this->script_version
		);

		// Prepare params
		$devMode = isset($configData['devOptions']) && $configData['devOptions'] === "yes";
		$basePathUrl = ($devMode && !empty($configData['alternativeBasePath'])) ? $configData['alternativeBasePath'] : 'https://eplin.api.blue.cl';

		$params = array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('bluex_checkout_nonce'),
			'widget_base_urls' => array(
				'prod' => 'https://widget-pudo.blue.cl',
				'qa' => 'https://widget-pudo.qa.blue.cl',
				'dev' => 'https://widget-pudo.dev.blue.cl'
			),
			'base_path_url' => $basePathUrl,
		);

		wp_localize_script('bluex-blocks-pudo-integration', 'bluex_checkout_params', WC_BlueX_Pudo_Widget_V2::filter_params($params));
	}

	/**
		* Enqueue City Select integration script
		*/
	private function enqueue_city_select_script()
	{
		$configData = get_option('woocommerce_correios-integration_settings');

		// Only if Districts are enabled
		if (!isset($configData['districtsEnable']) || $configData['districtsEnable'] !== "yes") {
			return;
		}

		$script_path = 'assets/js/frontend/blocks-city-select.js';
		$script_url = plugins_url($script_path, WC_Correios::get_main_file());

		wp_enqueue_script(
			'bluex-blocks-city-select',
			$script_url,
			array('wp-data', 'wp-element', 'wp-hooks', 'wc-blocks-checkout'),
			$this->script_version,
			true
		);

		// Get places
		// We need to instantiate WC_States_Places_Bx to get places easily, or replicate logic.
		// Since the class is available, let's try to use it if possible, or just replicate the loading logic.
		// Replicating is safer to avoid side effects of __construct.

		$places = array();

		// Use the clean list of communes for Chile
		if (!function_exists('bluex_get_communes_by_region')) {
			$communes_file = WC_Correios::get_plugin_path() . '/includes/data/chile-communes.php';
			if (file_exists($communes_file)) {
				require_once $communes_file;
			}
		}

		if (function_exists('bluex_get_communes_by_region')) {
			$communes_by_region = bluex_get_communes_by_region();
			$cl_places = array();

			foreach ($communes_by_region as $region_code => $communes) {
				$cl_places[$region_code] = array_map(function($commune) {
					return $commune['name'];
				}, $communes);
			}
		} else {
			// Fallback to old method if new file not found
			$places_file = WC_Correios::get_plugin_path() . '/includes/districts/places/CL.php';

			if (file_exists($places_file)) {
				global $places; // Ensure we capture it
				include $places_file;
				// $places should now be populated for CL
				$cl_places = isset($places['CL']) ? $places['CL'] : $places;
			} else {
				$cl_places = array();
			}
		}

		$params = array(
			'cities' => json_encode(array('CL' => $cl_places)),
			'i18n_select_city_text' => esc_attr__('Select an option&hellip;', 'woocommerce')
		);

		wp_localize_script('bluex-blocks-city-select', 'wc_city_select_params', $params);
	}

	/**
	 * Get script version
	 *
	 * @return string
	 */
	public function get_script_version()
	{
		return $this->script_version;
	}
}

// Initialize the integration
new WC_Correios_Blocks_Integration();
