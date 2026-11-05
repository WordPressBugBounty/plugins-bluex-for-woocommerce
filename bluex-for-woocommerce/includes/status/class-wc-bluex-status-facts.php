<?php

declare(strict_types=1);

/**
 * Collects, from WordPress/WooCommerce/Blue Express, the raw facts the status checks need.
 * Every source is isolated: if one fails its fact is null and the matching item becomes "unevaluated".
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Facts
{
	const BLUE_CACHE_KEY      = 'bluex_status_blue';
	const BLUE_CACHE_TTL      = 60;
	const PRICING_CACHE_KEY   = 'bluex_status_pricing';
	const PRICING_CACHE_TTL   = 600;
	const PRICING_RETRY_TTL   = 60;
	const KEY_ID_OPTION       = 'bluex_wc_auth_key_id';
	const TEST_ORDER_OPTION   = 'bluex_test_order_local';
	const STATUS_HOOK         = 'woocommerce_order_status_changed';

	// Only these keys of Blue's integration-status response are kept (and cached).
	const BLUE_FIELDS = array(
		'storeId',
		'activeIntegration',
		'message',
		'errorCode',
		'storeAddress',
		'region',
		'regionName',
		'provinceCode',
		'commune',
		'communeName',
		'location',
		'address',
		'testOrderValidationDate',
		'testOrderId',
		'testOrderIdShort',
	);

	/**
	 * @param bool $refresh Ignore the caches.
	 * @return array
	 */
	public static function collect($refresh = false)
	{
		$settings = WC_Correios_Settings::get_instance();
		$blue     = self::blue_state($refresh);

		return array(
			'now'              => time(),
			'plugin_version'   => defined('WC_CORREIOS_VERSION') ? (string) WC_CORREIOS_VERSION : '',
			'urls'             => self::guard('urls', array(__CLASS__, 'urls'), array()),
			'blue'             => $blue,
			'wc_address'       => self::guard('address', array(__CLASS__, 'wc_address'), null),
			'credentials'      => self::guard('credentials', array(__CLASS__, 'credentials'), null),
			'zones'            => self::guard('zones', array(__CLASS__, 'zones'), null),
			'checkout_fields'  => self::guard('checkout_fields', array('WC_BlueX_Status_Checkout', 'inspect'), null),
			'capture'          => self::guard('capture', function () use ($settings) {
				return self::capture($settings);
			}, null),
			'pricing'          => empty($blue['active']) ? null : self::guard('pricing', function () use ($settings, $refresh) {
				return self::pricing($settings, $refresh);
			}, null),
			'test_order'       => self::test_order($blue),
		);
	}

	/**
	 * A zone covers Chile when it lists CL (country), a CL state, a continent that contains CL, or postcodes
	 * (the plugin creates its commune zones as postcode locations). "Rest of the world" covers everything.
	 *
	 * @param array $locations           List of array{type: string, code: string}.
	 * @param array $continent_countries Continent code => list of country codes.
	 */
	public static function zone_covers_chile(array $locations, array $continent_countries, $is_rest_of_world = false)
	{
		if ($is_rest_of_world) {
			return true;
		}

		foreach ($locations as $location) {
			$type = isset($location['type']) ? (string) $location['type'] : '';
			$code = isset($location['code']) ? (string) $location['code'] : '';

			if ('country' === $type && 'CL' === $code) {
				return true;
			}
			if ('state' === $type && 0 === strpos($code, 'CL:')) {
				return true;
			}
			if ('continent' === $type && isset($continent_countries[$code]) && in_array('CL', $continent_countries[$code], true)) {
				return true;
			}
			if ('postcode' === $type) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a callback of the given class is registered in a WP_Hook callbacks array.
	 *
	 * @param array  $callbacks Priority => id => array{function: callable}.
	 */
	public static function hook_has_callback_of_class(array $callbacks, $class_name)
	{
		foreach ($callbacks as $priority_callbacks) {
			foreach ($priority_callbacks as $callback) {
				$function = isset($callback['function']) ? $callback['function'] : null;
				if (is_array($function) && isset($function[0]) && is_object($function[0]) && $function[0] instanceof $class_name) {
					return true;
				}
			}
		}
		return false;
	}

	private static function guard($label, $callable, $fallback)
	{
		try {
			return call_user_func($callable);
		} catch (Throwable $e) {
			bluex_log('error', 'Status facts (' . $label . ') failed: ' . $e->getMessage());
			return $fallback;
		}
	}

	/**
	 * Blue Express integration state, uncached when $refresh is true. Never throws: an unreachable Blue gives ok = false.
	 *
	 * @return array{ok: bool, active: bool, message: string, data: array}
	 */
	public static function blue_state($refresh = false)
	{
		return self::guard('blue', function () use ($refresh) {
			return self::blue($refresh);
		}, array('ok' => false, 'active' => false, 'message' => '', 'data' => array()));
	}

	private static function blue($refresh)
	{
		if (!$refresh) {
			$cached = get_transient(self::BLUE_CACHE_KEY);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$response = (new BlueX_API_Client())->validate_integration_status();
		if (is_wp_error($response) || !is_array($response)) {
			return array('ok' => false, 'active' => false, 'message' => '', 'data' => array());
		}

		$result = array(
			'ok'      => true,
			'active'  => !empty($response['storeId']) && !empty($response['activeIntegration']),
			'message' => isset($response['message']) && is_string($response['message']) ? $response['message'] : '',
			'data'    => array_intersect_key($response, array_flip(self::BLUE_FIELDS)),
		);
		// An inactive answer is never cached: a just-created integration would stay red until the TTL expires.
		if ($result['active']) {
			set_transient(self::BLUE_CACHE_KEY, $result, self::BLUE_CACHE_TTL);
		}

		return $result;
	}

	/** Drop the cached Blue state, e.g. after the integration credentials changed. */
	public static function forget_blue()
	{
		delete_transient(self::BLUE_CACHE_KEY);
	}

	public static function urls()
	{
		$checkout_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;
		$checkout    = $checkout_id > 0 ? get_edit_post_link($checkout_id, 'raw') : '';
		$hpos        = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return array(
			'general'   => admin_url('admin.php?page=wc-settings&tab=general'),
			'shipping'  => admin_url('admin.php?page=wc-settings&tab=shipping'),
			'api_keys'  => admin_url('admin.php?page=wc-settings&tab=advanced&section=keys'),
			'checkout'  => (string) $checkout,
			'zone_base' => admin_url('admin.php?page=wc-settings&tab=shipping&zone_id='),
			'orders'    => admin_url($hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order'),
		);
	}

	public static function wc_address()
	{
		$countries = WC()->countries;
		$address   = trim((string) $countries->get_base_address());
		$city      = trim((string) $countries->get_base_city());
		$state     = trim((string) $countries->get_base_state());
		$states    = $countries->get_states($countries->get_base_country());
		$state     = is_array($states) && isset($states[$state]) ? (string) $states[$state] : $state;

		return array(
			'address' => $address,
			'city'    => $city,
			'text'    => implode(', ', array_filter(array($address, trim((string) $countries->get_base_address_2()), $city, $state))),
		);
	}

	public static function credentials()
	{
		global $wpdb;

		$key_id = (int) get_option(self::KEY_ID_OPTION, 0);
		if ($key_id <= 0) {
			return array('key_id' => 0, 'found' => false, 'permissions' => '', 'description' => '');
		}

		$table       = $wpdb->prefix . 'woocommerce_api_keys';
		$row         = $wpdb->get_row($wpdb->prepare("SELECT permissions, description FROM {$table} WHERE key_id = %d", $key_id));

		return array(
			'key_id'      => $key_id,
			'found'       => null !== $row,
			'permissions' => null !== $row ? (string) $row->permissions : '',
			'description' => null !== $row ? trim((string) $row->description) : '',
		);
	}

	public static function zones()
	{
		$continents = array();
		foreach (WC()->countries->get_continents() as $code => $continent) {
			$continents[$code] = isset($continent['countries']) ? $continent['countries'] : array();
		}

		$zones = array();
		foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
			$zone_id = isset($zone_data['id']) ? (int) $zone_data['id'] : (int) $zone_data['zone_id'];
			$zones[] = self::zone_fact(new WC_Shipping_Zone($zone_id), $continents, false);
		}
		$zones[] = self::zone_fact(new WC_Shipping_Zone(0), $continents, true);

		return $zones;
	}

	private static function zone_fact(WC_Shipping_Zone $zone, array $continents, $is_rest_of_world)
	{
		$locations = array();
		foreach ($zone->get_zone_locations() as $location) {
			$locations[] = array('type' => (string) $location->type, 'code' => (string) $location->code);
		}

		$bluex = array();
		foreach ($zone->get_shipping_methods(true) as $method) {
			if (0 === strpos((string) $method->id, 'bluex')) {
				$bluex[] = array('id' => (string) $method->id, 'fee' => (string) $method->get_option('fee'));
			}
		}

		return array(
			'id'           => (int) $zone->get_id(),
			'name'         => $is_rest_of_world ? __('Resto del mundo', 'woocommerce-correios') : (string) $zone->get_zone_name(),
			'covers_chile' => self::zone_covers_chile($locations, $continents, $is_rest_of_world),
			'bluex'        => $bluex,
		);
	}

	private static function capture(WC_Correios_Settings $settings)
	{
		$status   = (string) $settings->get_setting('noBlueStatus');
		$statuses = wc_get_order_statuses();
		$callbacks = isset($GLOBALS['wp_filter'][self::STATUS_HOOK]) ? $GLOBALS['wp_filter'][self::STATUS_HOOK]->callbacks : array();

		$last = WC_BlueX_Order_Sync_Log::last();
		if (null !== $last && !empty($last['ok']) && !empty($last['time'])) {
			$last['ago'] = human_time_diff((int) $last['time'], time());
		}

		return array(
			'status'        => $status,
			'status_exists' => '' !== $status && array_key_exists($status, $statuses),
			'status_label'  => isset($statuses[$status]) ? (string) $statuses[$status] : '',
			'hook_active'   => self::hook_has_callback_of_class($callbacks, 'WC_Correios_Webhook'),
			'last_sync'     => $last,
		);
	}

	/**
	 * One quote per service with the same payload as the existing "test integration" endpoint.
	 *
	 * @return array<string, bool>
	 */
	private static function pricing(WC_Correios_Settings $settings, $refresh)
	{
		if (!$refresh) {
			$cached = get_transient(self::PRICING_CACHE_KEY);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$district = (string) $settings->get_setting('districtCode');
		$from     = array('country' => 'CL', 'district' => '' !== $district ? $district : 'SCL');
		$to       = array('country' => 'CL', 'state' => '13', 'district' => 'PRO');
		$bultos   = array(array('largo' => 10, 'ancho' => 10, 'alto' => 10, 'sku' => 'TEST01', 'pesoFisico' => 1, 'cantidad' => 1));
		$client   = new BlueX_API_Client();

		$result = array();
		foreach (array_keys(WC_BlueX_Status_Checks::PRICING_SERVICES) as $service) {
			$response         = $client->get_pricing($from, $to, $service, $bultos, 10000.0, 'PAQU');
			$result[$service] = !is_wp_error($response) && is_array($response);
		}

		$all_ok = !in_array(false, $result, true);
		set_transient(self::PRICING_CACHE_KEY, $result, $all_ok ? self::PRICING_CACHE_TTL : self::PRICING_RETRY_TTL);

		return $result;
	}

	private static function test_order(array $blue)
	{
		$data  = isset($blue['data']) && is_array($blue['data']) ? $blue['data'] : array();
		$local = self::array_option(self::TEST_ORDER_OPTION);

		return array(
			'validation_date' => isset($data['testOrderValidationDate']) ? (string) $data['testOrderValidationDate'] : '',
			'order_id'        => isset($data['testOrderId']) ? (string) $data['testOrderId'] : '',
			'order_id_short'  => isset($data['testOrderIdShort']) ? (string) $data['testOrderIdShort'] : '',
			'local_order_number' => null !== $local && !empty($local['order_number']) ? (string) $local['order_number'] : '',
			'local_sent_at'   => null !== $local && !empty($local['sent_at']) ? (int) $local['sent_at'] : 0,
		);
	}

	private static function array_option($name)
	{
		$value = get_option($name, null);
		return is_array($value) ? $value : null;
	}
}
