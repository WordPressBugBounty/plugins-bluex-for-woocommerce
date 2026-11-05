<?php
/**
 * BlueX PUDO Shipping Method.
 *
 * Registers `bluex-pudo` as a native WooCommerce shipping method. It quotes
 * either the server-validated shopper selection or the nearest preview agency
 * through the same BlueX PUDO webservice using the real cart package.
 *
 * Dynamic label: if the session has `bluex_agency_name` set (persisted by the
 * blocks integration's Store API update_callback when the customer picks a
 * point in the iframe), the rate label renders as "Retiro en <agency_name>"
 * and the quoted delivery promise is exposed as rate metadata. Otherwise it
 * renders the configured title. This is read on every calculate_shipping so
 * label flips naturally as the cart refreshes after point selection.
 *
 * @package WooCommerce_Correios/Classes/Shipping
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once dirname(__DIR__) . '/class-bluex-pudo-selection-state.php';

class WC_BlueX_Pudo extends WC_Shipping_Method
{
	/** @var bool Prevent a later package success from hiding an earlier package failure in the same request. */
	private static $selected_quote_failed_this_request = false;

	// Fee/IVA por defecto aplicado al costo cotizado de PUDO.
	const DEFAULT_FEE = '19%';

	/** @var string */
	public $fee = self::DEFAULT_FEE;

	public function __construct($instance_id = 0)
	{
		$this->id                 = 'bluex-pudo';
		$this->instance_id        = absint($instance_id);
		$this->method_title       = __('Retiro en Punto Blue Express', 'woocommerce-correios');
		$this->method_description = __('Permite al cliente elegir un Punto Blue Express de Blue Express como destino de retiro.', 'woocommerce-correios');
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled    = $this->get_option('enabled');
		$this->title      = $this->get_option('title');
		// Fijo en 'none': el fee/IVA se aplica manualmente sobre el costo, así que
		// no delegamos el impuesto a WooCommerce. Además evita que una instancia
		// vieja con 'taxable' guardado lo siga heredando.
		$this->tax_status = 'none';
		$this->fee        = $this->get_option('fee');

		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			[$this, 'process_admin_options']
		);
	}

	public function init_form_fields()
	{
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => __('Habilitar/Deshabilitar', 'woocommerce-correios'),
				'type'    => 'checkbox',
				'label'   => __('Habilitar este método de envío', 'woocommerce-correios'),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __('Título', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('Nombre del método mostrado al cliente cuando no hay un punto elegido.', 'woocommerce-correios'),
				'default'     => __('Retiro en Punto Blue Express', 'woocommerce-correios'),
				'desc_tip'    => true,
			],
			'fee' => [
				// type 'text' (no 'price'): WooCommerce valida los campos 'price'
				// con wc_format_decimal(), que ELIMINA el '%' al guardar y dejaría
				// '19' (monto fijo) en vez de '19%'. 'text' preserva el porcentaje.
				'title'       => __('IVA / Recargo (Handling Fee)', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('Porcentaje (ej. 19%) o monto fijo aplicado al costo cotizado. Por defecto 19% (IVA).', 'woocommerce-correios'),
				'desc_tip'    => true,
				// default vacío a propósito: el 19% se persiste solo en instancias
				// NUEVAS vía maybe_set_default_fee(). Un default '19%' aquí haría que
				// WooCommerce lo aplique por fallback a instancias viejas sin fee
				// guardado (backfill no deseado).
				'default'     => '',
				'placeholder' => self::DEFAULT_FEE,
			],
		];
	}

	/**
	 * Calculate the rate for this package.
	 *
	 * A confirmed, server-validated agency wins. Otherwise the nearest agency is
	 * quoted as preview-only rate metadata. Preview failures omit PUDO; a transient
	 * selected-quote failure keeps a stale display rate but blocks order creation.
	 *
	 * Respects the plugin-wide `pudoEnable` master switch.
	 *
	 * @param array $package
	 */
	public function calculate_shipping($package = [])
	{
		if (isset($package['destination']['country']) && $package['destination']['country'] !== 'CL') {
			return;
		}

		$config = get_option('woocommerce_correios-integration_settings', []);
		if (!isset($config['pudoEnable']) || $config['pudoEnable'] !== 'yes') {
			return;
		}

		$selected = $this->get_selected_agency($package);
		$is_selected = $selected['agency_id'] !== '';
		$agency_id = $selected['agency_id'];
		$agency_name = $selected['agency_name'];
		$agency_address = $selected['agency_address'];

		// Pricing v2 already quotes every pickup point, so the nearest one comes from the same quote.
		$nearest = false;
		if (!$is_selected && WC_BlueX_Pudo_Widget_V2::is_active()) {
			$nearest = true;
		} elseif (!$is_selected) {
			$from_district = 'SCL';
			$settings = WC_Correios_Settings::get_instance();
			$settings_data = $settings->get_settings();
			if (!empty($settings_data['districtCode'])) {
				$from_district = $settings_data['districtCode'];
			}

			$destination = $package['destination'] ?? [];
			$city = sanitize_text_field((string) ($destination['city'] ?? ''));
			$state = sanitize_text_field((string) ($destination['state'] ?? ''));
			$region = strpos($state, 'CL-') === 0 ? substr($state, 3) : $state;
			if ($city === '' || $region === '') {
				return;
			}

			$geo = (new BlueX_API_Client())->get_geolocation($city, $region, null, true);
			if (is_wp_error($geo) || !is_array($geo)) {
				return;
			}

			$nearby = WC_Bluex_Pudo_Nearby_Service::fetch_nearby_pudos(
				$from_district,
				$package,
				$settings->get_account_name(),
				5,
				true,
				array(
					'regionCode'   => $geo['regionCode'] ?? null,
					'districtCode' => $geo['districtCode'] ?? null,
				)
			);
			$closest = WC_Bluex_Pudo_Nearby_Service::get_closest_agency_price($nearby);
			if (empty($closest['agency']['agencyId'])) {
				return;
			}

			$agency = $closest['agency'];
			$agency_id = sanitize_text_field((string) $agency['agencyId']);
			$agency_name = sanitize_text_field((string) ($agency['service_name'] ?? $agency['agencyName'] ?? ''));
			$agency_address = $this->get_nearby_agency_address($agency);
		}

		$quoted_shipping = $this->get_quoted_pudo_shipping($package, $agency_id, $nearest);
		if ($nearest && $quoted_shipping && !empty($quoted_shipping->agencyId)) {
			$agency_id = sanitize_text_field((string) $quoted_shipping->agencyId);
			$agency_name = sanitize_text_field((string) $quoted_shipping->agencyName);
			$agency_address = sanitize_text_field((string) $quoted_shipping->agencyAddress);
		} elseif ($nearest) {
			$quoted_shipping = null;
		}
		$quote_signature = WC_Bluex_Pudo_Selection_State::quote_signature($package, $agency_id);
		$quote_is_current = true;
		if (!$quoted_shipping) {
			// Keep the chosen method stable during a transient pricing failure. The
			// last successful amount is display-only: order validation blocks stale
			// selected quotes until a retry succeeds for the confirmed agency.
			if (!$is_selected) {
				return;
			}
			$quoted_shipping = $this->get_last_successful_quote($quote_signature);
			if (!$quoted_shipping) {
				return;
			}
			$quote_is_current = false;
			self::$selected_quote_failed_this_request = true;
		}

		if ($quote_is_current) {
			$this->remember_successful_quote($quoted_shipping, $quote_signature);
		}
		if (WC()->session) {
			WC()->session->set(
				'bluex_pudo_selected_quote_valid',
				$is_selected && $quote_is_current && !self::$selected_quote_failed_this_request &&
				WC_Bluex_Pudo_Selection_State::is_complete_destination($package['destination'] ?? array())
			);
		}

		if ($agency_name === '' && !empty($quoted_shipping->nameService)) {
			$agency_name = sanitize_text_field((string) $quoted_shipping->nameService);
		}

		$meta_data = array(
			'_is_pudo'             => 'yes',
			'_bluex_pudo_quoted'   => 'yes',
			'_delivery_forecast'   => (string) $quoted_shipping->PrazoEntrega,
			'_bluex_agency_id'     => $is_selected ? $agency_id : '',
			'_bluex_agency_name'   => $is_selected ? $agency_name : '',
			'_bluex_agency_address'=> $is_selected ? $agency_address : '',
			'_bluex_pudo_quote_current' => $quote_is_current ? 'yes' : 'no',
		);

		if ($is_selected) {
			$meta_data['_bluex_pudo_selected_agency_id'] = $agency_id;
		} else {
			$meta_data['_bluex_pudo_preview_agency_id'] = $agency_id;
			$meta_data['_bluex_pudo_preview_agency_name'] = $agency_name;
			$meta_data['_bluex_pudo_preview_agency_address'] = $agency_address;
		}

		$cost = $this->normalize_quoted_cost($quoted_shipping->Valor);
		// Whole pesos, as the widget shows them (2403 net + 19% = 2859.57 -> 2860).
		$cost = round($cost + (float) $this->get_fee($this->fee, $cost));
		$label = $agency_name !== '' ? $this->title . ' - ' . $agency_name : $this->title;

		$this->add_rate(array(
			'id'        => $this->get_rate_id(),
			'label'     => $label,
			'cost'      => $cost,
			'meta_data' => $meta_data,
		));
	}

	/** Persist the small successful quote needed for a safe display fallback. */
	private function remember_successful_quote($shipping, $signature)
	{
		if (!WC()->session || !is_object($shipping)) {
			return;
		}
		WC()->session->set('bluex_pudo_last_successful_quote', array(
			'Valor'          => (string) $shipping->Valor,
			'PrazoEntrega'   => (string) $shipping->PrazoEntrega,
			'nameService'    => (string) ($shipping->nameService ?? ''),
			'isShipmentFree' => !empty($shipping->isShipmentFree),
			'signature'      => $signature,
		));
	}

	/**
	 * Rehydrate a display-only fallback. Order creation remains blocked while
	 * bluex_pudo_selected_quote_valid is false. A quote saved for a different cart
	 * or point is not reused; one saved before signatures existed still is.
	 *
	 * @return object|null
	 */
	private function get_last_successful_quote($signature)
	{
		if (!WC()->session) {
			return null;
		}
		$quote = WC()->session->get('bluex_pudo_last_successful_quote', array());
		if (!is_array($quote) || !isset($quote['Valor'], $quote['PrazoEntrega'])) {
			return null;
		}
		if (isset($quote['signature']) && !hash_equals((string) $quote['signature'], (string) $signature)) {
			return null;
		}
		return (object) array(
			'Codigo'          => 'EX',
			'Valor'           => (string) $quote['Valor'],
			'PrazoEntrega'    => (string) $quote['PrazoEntrega'],
			'Erro'            => '0',
			'MsgErro'         => '',
			'nameService'     => (string) ($quote['nameService'] ?? ''),
			'isShipmentFree'  => !empty($quote['isShipmentFree']),
		);
	}

	/**
	 * Return only a server-validated shopper selection made for this package's destination.
	 *
	 * @param array $package Shipping package.
	 * @return array
	 */
	private function get_selected_agency(array $package = array())
	{
		$empty = array('agency_id' => '', 'agency_name' => '', 'agency_address' => '');
		if (!WC()->session) {
			return $empty;
		}

		WC_Bluex_Pudo_Selection_State::invalidate_if_destination_changed(
			WC()->session,
			$package['destination'] ?? array()
		);

		$agency_id = sanitize_text_field((string) WC()->session->get('bluex_agency_id', ''));
		$validated_id = sanitize_text_field((string) WC()->session->get('bluex_agency_validated_id', ''));
		if ($agency_id === '' || $validated_id === '' || !hash_equals($validated_id, $agency_id)) {
			return $empty;
		}

		return array(
			'agency_id'      => $agency_id,
			'agency_name'    => sanitize_text_field((string) WC()->session->get('bluex_agency_name', '')),
			'agency_address' => sanitize_text_field((string) WC()->session->get('bluex_agency_address', '')),
		);
	}

	/**
	 * Normalize the address variants returned by Nearby.
	 *
	 * @param array $agency Nearby agency.
	 * @return string
	 */
	private function get_nearby_agency_address(array $agency)
	{
		if (!empty($agency['agencyAddress']) && is_array($agency['agencyAddress'])) {
			$address = $agency['agencyAddress'];
			return sanitize_text_field(implode(' ', array_filter(array(
				$address['streetName'] ?? $address['street_name'] ?? '',
				$address['streetNumber'] ?? $address['street_number'] ?? '',
			))));
		}

		return sanitize_text_field((string) ($agency['address'] ?? ''));
	}

	/**
	 * Quote the selected pickup point through the same webservice used by BlueX.
	 *
	 * @param array $package Shipping package.
	 * @return object|null
	 */
	private function get_quoted_pudo_shipping($package, $agency_id, $nearest = false)
	{
		return self::quote_point($package, $agency_id, $nearest, $this->id, $this->instance_id);
	}

	/**
	 * Whether the point can be quoted for the cart as it stands (widget v2 only).
	 * A point Blue does not quote would drop the PUDO rate once committed, so it is checked first.
	 *
	 * @param string $agency_id   Candidate point, already validated.
	 * @param array  $destination Destination the shopper just submitted, when WooCommerce has not stored it yet.
	 * @return bool
	 */
	public static function selected_point_is_quotable($agency_id, array $destination = array())
	{
		if (!class_exists('WC_BlueX_Pudo_Widget_V2') || !WC_BlueX_Pudo_Widget_V2::is_active()) {
			return true;
		}
		if (!function_exists('WC') || !WC() || !WC()->cart) {
			return true;
		}
		$packages = WC()->cart->get_shipping_packages();
		$package  = is_array($packages) ? reset($packages) : false;
		if (!is_array($package) || empty($package['contents'])) {
			return true;
		}
		$package['destination'] = array_merge(
			(array) ($package['destination'] ?? array()),
			array_filter($destination, function ($value) {
				return is_scalar($value) && trim((string) $value) !== '';
			})
		);
		if (null !== self::quote_point($package, $agency_id)) {
			return true;
		}

		if (function_exists('bluex_log')) {
			bluex_log('warning', 'PUDO point has no quote: ' . wp_json_encode(array(
				'agency_id' => (string) $agency_id,
				'state'     => (string) ($package['destination']['state'] ?? ''),
				'city'      => (string) ($package['destination']['city'] ?? ''),
			)));
		}
		return false;
	}

	/**
	 * @param array  $package     Shipping package.
	 * @param string $agency_id   Point to quote.
	 * @param bool   $nearest     Quote the closest point instead.
	 * @param string $method_id   Shipping method id.
	 * @param int    $instance_id Shipping zone instance.
	 * @return object|null
	 */
	public static function quote_point($package, $agency_id, $nearest = false, $method_id = 'bluex-pudo', $instance_id = 0)
	{
		if (!class_exists('WC_Correios_Webservice')) {
			return null;
		}

		$api = new WC_Correios_Webservice($method_id, $instance_id);
		$api->set_service('EX');
		$api->set_agency_id($agency_id);
		if ($nearest) {
			$api->set_nearest_pudo(true);
		}
		$api->set_package($package);

		if (isset($package['destination']['postcode'])) {
			$api->set_destination_postcode($package['destination']['postcode']);
		}

		if (isset($package['contents_cost'])) {
			$api->set_declared_value(18 >= (float) $package['contents_cost'] ? 0 : $package['contents_cost']);
		}

		$shipping = $api->get_shipping();
		if (!is_object($shipping) || !isset($shipping->Erro, $shipping->Valor, $shipping->PrazoEntrega)) {
			return null;
		}

		$error_number = (string) $shipping->Erro;
		if ($error_number !== '0' && $error_number !== '') {
			return null;
		}

		$cost = self::normalize_quoted_cost($shipping->Valor);
		if (empty($shipping->isShipmentFree) && 0 === intval($cost)) {
			return null;
		}

		return $shipping;
	}

	/**
	 * Normalize BlueX pricing response value into a WooCommerce rate cost.
	 *
	 * @param mixed $value Raw pricing response value.
	 * @return float
	 */
	private static function normalize_quoted_cost($value)
	{
		if (function_exists('wc_correios_normalizePrice')) {
			return (float) wc_correios_normalizePrice(esc_attr((string) $value));
		}

		return (float) str_replace(',', '.', (string) $value);
	}

	/**
	 * Rewrite a single shipping order item from bluex-pudo to bluex-ex.
	 *
	 * The BlueX backend contract keys pickup orders by the presence of the
	 * `agencyId` meta + `shipping_method=bluex-ex`, NOT by a bluex-pudo
	 * method id. This normalizes the saved item so both checkout flows
	 * produce the same shape. Idempotent — no-op on non-pudo items.
	 *
	 * Does NOT call $item->save(). Callers that mutate an item ALREADY
	 * attached to an order should call save() themselves; the hook
	 * `woocommerce_checkout_create_order_shipping_item` fires BEFORE the
	 * item is attached (verified against WC source class-wc-checkout.php
	 * create_order_shipping_lines), so saving there is redundant —
	 * WC's own persist cycle picks it up.
	 *
	 * @param WC_Order_Item_Shipping $item
	 */
	public static function rewrite_shipping_item_to_bluex_ex($item)
	{
		if (!$item instanceof WC_Order_Item_Shipping) {
			return;
		}
		if ($item->get_method_id() !== 'bluex-pudo') {
			return;
		}

		// Preserve the current label (PUDO agency-dynamic or generic title).
		// Instance id kept blank so the order carries a neutral bluex-ex
		// attribution; the agencyId meta is what the backend actually keys on.
		$item->set_method_id('bluex-ex');
		$item->add_meta_data('_bluex_original_method', 'bluex-pudo', true);
	}

	/**
	 * Rewrite every bluex-pudo shipping item on an order to bluex-ex.
	 *
	 * Calls save() on each modified item because this path operates on an
	 * order that is already in the DB lifecycle (Blocks update_order_from_
	 * store_api_request, or any after-create context). Idempotent — skips
	 * items that are already bluex-ex.
	 *
	 * @param WC_Order $order
	 */
	public static function rewrite_shipping_method_to_bluex_ex($order)
	{
		foreach ($order->get_items('shipping') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Shipping) {
				continue;
			}
			if ($item->get_method_id() !== 'bluex-pudo') {
				continue;
			}
			self::rewrite_shipping_item_to_bluex_ex($item);
			$item->save();
		}
	}

	/**
	 * Persist the default 19% fee (IVA) on a newly created bluex-pudo instance.
	 *
	 * Hooked on `woocommerce_shipping_zone_method_added`, which fires from
	 * WC_Shipping_Zone::add_shipping_method() — the single creation point used
	 * by every path (manual admin add, REST API, plugin automation, migrator).
	 *
	 * We set the default HERE (at creation) instead of via the form-field
	 * `default` so that ONLY new instances get 19%: pre-existing PUDO instances
	 * (created before this feature, with no saved `fee`) keep charging no IVA
	 * until edited — honoring the "solo nuevas creaciones, sin backfill" decision.
	 *
	 * Idempotent: skips if a non-empty `fee` is already stored (e.g. one the
	 * zone automation wrote), so it never overrides an explicit value.
	 *
	 * @param int    $instance_id New shipping method instance id.
	 * @param string $type        Shipping method id (e.g. 'bluex-pudo').
	 * @param int    $zone_id     Shipping zone id.
	 */
	public static function maybe_set_default_fee($instance_id, $type, $zone_id)
	{
		if ($type !== 'bluex-pudo') {
			return;
		}

		$option_key = 'woocommerce_bluex-pudo_' . absint($instance_id) . '_settings';
		$settings   = get_option($option_key, []);
		if (!is_array($settings)) {
			$settings = [];
		}

		// Do not override an explicit fee already persisted for this instance.
		if (isset($settings['fee']) && $settings['fee'] !== '') {
			return;
		}

		$settings['fee'] = self::DEFAULT_FEE;
		update_option($option_key, $settings);
	}
}
