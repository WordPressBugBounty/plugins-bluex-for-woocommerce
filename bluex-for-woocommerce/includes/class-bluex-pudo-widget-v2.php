<?php
/**
 * Pickup-point widget v2: gating, localized params and cart endpoint.
 *
 * v2 reuses the whole v1 server flow (session, agency validation, order meta);
 * this class only decides which widget the checkout loads and feeds it the cart.
 *
 * @package WooCommerce_Correios/Classes
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Pudo_Widget_V2
{
	const CORE_HANDLE   = 'bluex-pudo-widget-v2-core';
	const CART_ENDPOINT = 'bluex_pudo_widget_cart';

	/** Widget hosts per environment (picked client-side from base_path_url, like v1). */
	const WIDGET_BASE_URLS = array(
		'prod' => 'https://ecommerce-maps.blue.cl',
		'qa'   => 'https://ecommerce-maps.stg.blue.cl',
		'dev'  => 'https://ecommerce-maps.dev.blue.cl',
	);

	/**
	 * v2 is active only with the PUDO master switch on AND v2 selected.
	 */
	public static function is_active()
	{
		$config = get_option('woocommerce_correios-integration_settings', array());
		if (!is_array($config) || !isset($config['pudoEnable']) || $config['pudoEnable'] !== 'yes') {
			return false;
		}
		$version = WC_Correios_Settings::sanitize_pudo_widget_version(isset($config['pudoWidgetVersion']) ? $config['pudoWidgetVersion'] : 'v1');
		return $version === 'v2';
	}

	public static function init()
	{
		if (!self::is_active()) {
			return;
		}
		add_action('wc_ajax_' . self::CART_ENDPOINT, array(__CLASS__, 'ajax_cart'));
	}

	/**
	 * Cart as the widget expects it, for the current session. Read-only.
	 */
	public static function ajax_cart()
	{
		nocache_headers();
		if (!WC()->cart && function_exists('wc_load_cart')) {
			wc_load_cart();
		}
		wp_send_json(WC_BlueX_Pudo_Widget_Payload::from_cart(WC()->cart));
	}

	/**
	 * Params for the checkout scripts. v1 gets them back unchanged.
	 *
	 * @param array $params Params already built for bluex_checkout_params.
	 * @return array
	 */
	public static function filter_params(array $params)
	{
		if (!self::is_active()) {
			return $params;
		}

		return array_merge($params, array(
			'widget_version'   => 'v2',
			'widget_base_urls' => self::WIDGET_BASE_URLS,
			'shop'             => WC_Correios_Settings::get_instance()->get_account_name(),
			'ecommerce'        => 'woocommerce',
			'lang'             => self::get_language(),
			'cart_endpoint'    => WC_AJAX::get_endpoint(self::CART_ENDPOINT),
		));
	}

	/**
	 * Script handles the checkout scripts must depend on (none on v1).
	 */
	public static function script_dependencies()
	{
		return self::is_active() ? array(self::CORE_HANDLE) : array();
	}

	/**
	 * Enqueue the shared v2 core. No-op on v1. Requires the widget-security script.
	 *
	 * @param string $version Asset version for cache busting.
	 */
	public static function enqueue_core($version)
	{
		if (!self::is_active()) {
			return;
		}
		$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			self::CORE_HANDLE,
			plugins_url('assets/js/frontend/pudo-widget-v2-core' . $suffix . '.js', WC_Correios::get_main_file()),
			array('bluex-pudo-widget-security'),
			$version,
			true
		);
	}

	private static function get_language()
	{
		return strtolower(substr((string) determine_locale(), 0, 2)) === 'en' ? 'en' : 'es';
	}
}
