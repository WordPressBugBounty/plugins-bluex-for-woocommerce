<?php
/**
 * Pricing v2 (widget v2): one quote for pickup points and home delivery.
 *
 * Pure helpers: build the request body, pick the rate a shipping method needs
 * and return it in the same shape as pricing v1, so callers do not change.
 *
 * @package WooCommerce_Correios/Classes
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Pudo_Pricing_V2
{
	const PATH = '/eplin/orchestrator-woocomerce/v1/woo/maps/pudos-pricing';

	/** Gateway host per environment, chosen from the configured base path. */
	const HOSTS = array(
		'prod' => 'https://public.api.blue.cl',
		'stg'  => 'https://public.api.stg.blue.cl',
		'dev'  => 'https://public.api.dev.blue.cl',
	);

	public static function endpoint_for_base_path($base_path)
	{
		$base = strtolower((string) $base_path);
		if (strpos($base, '.dev.') !== false) {
			$env = 'dev';
		} elseif (strpos($base, '.qa.') !== false || strpos($base, '.stg.') !== false) {
			$env = 'stg';
		} else {
			$env = 'prod';
		}

		return self::HOSTS[$env] . self::PATH;
	}

	public static function build_payload(array $from, array $to, $domain, array $bultos, $price, $destination)
	{
		if (isset($to['state']) && is_numeric($to['state'])) {
			$to['state'] = (int) $to['state'];
		}

		return array(
			'from'          => $from,
			'to'            => $to,
			'domain'        => $domain,
			'datosProducto' => array(
				'producto'        => 'P',
				'familiaProducto' => 'PUDO',
				'bultos'          => $bultos,
			),
			'destination'   => $destination,
			'price'         => (int) round((float) $price),
		);
	}

	/**
	 * Pick one rate from the v2 quote and return it as a pricing v1 response.
	 *
	 * @param array  $response  Decoded v2 response ({"rates": [...]}).
	 * @param string $service   Home delivery code (EX, PY, MD).
	 * @param string $agency_id Selected pickup point; quotes `EX-<id>` instead of $service.
	 * @param bool   $nearest   Quote the closest pickup point when no agency is selected.
	 * @return array {code, data, agency?}
	 */
	public static function select_rate(array $response, $service, $agency_id = '', $nearest = false)
	{
		$rates = isset($response['rates']) && is_array($response['rates']) ? $response['rates'] : array();
		$rate  = null;
		$agency = null;

		if ($agency_id !== '') {
			$rate = self::find_by_code($rates, 'EX-' . $agency_id);
		} elseif ($nearest) {
			$rate = self::closest_pickup($rates);
		} else {
			$rate = self::find_by_code($rates, (string) $service);
		}

		if (!$rate || !isset($rate['total_price']) || !is_numeric($rate['total_price'])) {
			return array('code' => '-1', 'message' => 'Rate not available in pricing v2 response.', 'data' => array());
		}

		// Same rule as the pickup-point widget: drop the net decimals, then the fee is applied on top.
		$total = (int) (float) $rate['total_price'];

		if (strpos((string) $rate['service_code'], 'EX-') === 0) {
			$agency = array(
				'id'      => substr((string) $rate['service_code'], 3),
				'name'    => (string) ($rate['service_name'] ?? ''),
				'address' => (string) ($rate['pickupAddress'] ?? ''),
			);
		}

		return array(
			'code'   => '00',
			'data'   => array(
				'total'          => $total,
				'promiseDay'     => (string) ($rate['promiseDay'] ?? $rate['description'] ?? '0'),
				'nameService'    => (string) ($rate['service_name'] ?? ''),
				'isShipmentFree' => $total === 0,
			),
			'agency' => $agency,
		);
	}

	private static function find_by_code(array $rates, $code)
	{
		foreach ($rates as $rate) {
			if (isset($rate['service_code']) && (string) $rate['service_code'] === $code) {
				return $rate;
			}
		}
		return null;
	}

	private static function closest_pickup(array $rates)
	{
		$closest = null;
		foreach ($rates as $rate) {
			if (!isset($rate['service_code'], $rate['userDistance']) || strpos((string) $rate['service_code'], 'EX-') !== 0) {
				continue;
			}
			if ($closest === null || (float) $rate['userDistance'] < (float) $closest['userDistance']) {
				$closest = $rate;
			}
		}
		return $closest;
	}
}
