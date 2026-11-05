<?php
/**
 * Default store settings applied after a successful integration.
 *
 * @package WooCommerce_Correios
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Fills the integration settings the seller has not set yet.
 * Values that already exist are kept, except FORCED keys, which every
 * integration (new or update) sets again.
 */
class WC_BlueX_Integration_Defaults
{
	const SETTINGS_OPTION = 'woocommerce_correios-integration_settings';

	const DEFAULTS = array(
		'noBlueStatus'      => 'wc-shipping-progress',
		'districtCode'      => 'SCL',
		'pudoEnable'        => 'yes',
		'pudoWidgetVersion' => 'v2',
		'districtsEnable'   => 'yes',
	);

	const FORCED = array('pudoEnable', 'pudoWidgetVersion', 'districtsEnable');

	/**
	 * @param WC_Correios_Settings|null $settings Settings handler.
	 * @param array                     $options  origin_district: district code resolved from the Blue Express address.
	 *                                            force_origin_district: let that district replace a saved one (new integrations).
	 * @return array{applied: string[], skipped: array<string,string>}
	 */
	public static function apply($settings = null, array $options = array())
	{
		$settings        = $settings ?: WC_Correios_Settings::get_instance();
		$stored          = get_option(self::SETTINGS_OPTION, array());
		$stored          = is_array($stored) ? $stored : array();
		$origin_district = self::valid_district(isset($options['origin_district']) ? $options['origin_district'] : null);
		$force_origin    = !empty($options['force_origin_district']);

		$to_apply = array();
		$skipped  = array();

		foreach (self::DEFAULTS as $key => $value) {
			if (in_array($key, self::FORCED, true)) {
				if (!isset($stored[$key]) || $value !== $stored[$key]) {
					$to_apply[$key] = $value;
				}
				continue;
			}
			if ('districtCode' === $key && null !== $origin_district) {
				if (($force_origin && ($stored[$key] ?? null) !== $origin_district) || (!$force_origin && self::is_unset($stored, $key))) {
					$to_apply[$key] = $origin_district;
				}
				continue;
			}
			if (!self::is_unset($stored, $key)) {
				continue;
			}
			if ('noBlueStatus' === $key && !self::order_status_exists($value)) {
				$skipped[$key] = 'order_status_not_registered';
				continue;
			}
			$to_apply[$key] = $value;
		}

		if (!empty($to_apply)) {
			$settings->update_settings($to_apply);
		}

		return array(
			'applied' => array_keys($to_apply),
			'skipped' => $skipped,
		);
	}

	private static function valid_district($code)
	{
		$code = is_string($code) ? strtoupper(trim($code)) : '';
		return 1 === preg_match('/^[A-Z0-9]{2,5}$/', $code) ? $code : null;
	}

	private static function is_unset(array $stored, $key)
	{
		return !isset($stored[$key]) || '' === $stored[$key];
	}

	private static function order_status_exists($status)
	{
		if (!function_exists('wc_get_order_statuses')) {
			return true;
		}
		return array_key_exists($status, wc_get_order_statuses());
	}
}
