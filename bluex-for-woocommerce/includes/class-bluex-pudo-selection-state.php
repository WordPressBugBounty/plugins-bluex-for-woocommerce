<?php
/**
 * Ties a confirmed PUDO selection to the shipping destination it was made for.
 *
 * The confirmed point lives in the WC session; without this, changing the checkout
 * address kept quoting and rehydrating the point chosen for the previous address.
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_Bluex_Pudo_Selection_State
{
	const SESSION_KEY = 'bluex_agency_destination_sig';

	private static $session_keys = array(
		'bluex_agency_id',
		'bluex_agency_validated_id',
		'bluex_pudo_selected',
		'bluex_agency_name',
		'bluex_agency_address',
		'bluex_agency_city',
		'bluex_agency_state',
		'bluex_pudo_selected_quote_valid',
		'bluex_pudo_last_successful_quote',
		self::SESSION_KEY,
	);

	/**
	 * Normalized state|city|street. address_2 is ignored on purpose: it does not move the point search.
	 *
	 * @param array $destination Keys: state, city, address_1 (or address).
	 * @return string
	 */
	public static function signature(array $destination)
	{
		$street = $destination['address_1'] ?? ($destination['address'] ?? '');
		$state = strtoupper(trim((string) ($destination['state'] ?? '')));
		if (strpos($state, 'CL-') === 0) {
			$state = substr($state, 3);
		}
		return $state . '|' . self::normalize_text($destination['city'] ?? '') . '|' . self::normalize_text($street);
	}

	/**
	 * Clear the confirmed point when a complete destination differs from the one it was
	 * confirmed for. A selection without a stored signature (made before this
	 * existed) adopts the current destination instead of being dropped.
	 * Incomplete snapshots preserve the selection and make its quote non-authoritative.
	 *
	 * @param object $session     WC session.
	 * @param array  $destination Current shipping destination.
	 * @return string|null Previous agency id when the selection was cleared, null otherwise.
	 */
	public static function invalidate_if_destination_changed($session, array $destination)
	{
		if (!$session || $destination === array()) {
			return null;
		}

		$agency_id = (string) $session->get('bluex_agency_id', '');
		if ($agency_id === '') {
			return null;
		}
		if (!self::is_complete_destination($destination)) {
			$session->set('bluex_pudo_selected_quote_valid', false);
			return null;
		}

		$current = self::signature($destination);
		$stored = (string) $session->get(self::SESSION_KEY, '');
		if ($stored === '') {
			$session->set(self::SESSION_KEY, $current);
			return null;
		}
		if (hash_equals($stored, $current)) {
			return null;
		}

		self::clear($session);
		return $agency_id;
	}

	public static function remember_destination($session, array $destination)
	{
		if ($session && self::is_complete_destination($destination)) {
			$session->set(self::SESSION_KEY, self::signature($destination));
		}
	}

	public static function is_complete_destination(array $destination)
	{
		foreach (array(
			$destination['state'] ?? '',
			$destination['city'] ?? '',
			$destination['address_1'] ?? ($destination['address'] ?? ''),
		) as $value) {
			if (!is_scalar($value) || trim((string) $value) === '') {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fingerprint of what a PUDO quote was made for: the point and the shippable cart lines.
	 * A saved quote is only a valid display fallback for the same fingerprint.
	 *
	 * @param array  $package   Shipping package.
	 * @param string $agency_id Quoted point.
	 * @return string
	 */
	public static function quote_signature(array $package, $agency_id)
	{
		$lines = array();
		foreach ((array) ($package['contents'] ?? array()) as $line) {
			$id = (int) ($line['variation_id'] ?? 0);
			if ($id === 0) {
				$id = (int) ($line['product_id'] ?? 0);
			}
			$lines[] = array($id, (int) ($line['quantity'] ?? 0));
		}
		sort($lines);

		return md5(json_encode(array((string) $agency_id, $lines)));
	}

	/**
	 * Cart contents changed: forget the saved fallback quote and make WooCommerce
	 * rebuild its cached shipping rates. The confirmed point is kept.
	 */
	public static function forget_quote()
	{
		if (function_exists('WC') && WC() && WC()->session) {
			WC()->session->__unset('bluex_pudo_last_successful_quote');
		}
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::get_transient_version('shipping', true);
		}
	}

	public static function init()
	{
		foreach (array(
			'woocommerce_add_to_cart',
			'woocommerce_after_cart_item_quantity_update',
			'woocommerce_cart_item_removed',
			'woocommerce_cart_item_restored',
		) as $hook) {
			add_action($hook, array(__CLASS__, 'forget_quote'));
		}
	}

	/** Clear every PUDO-only session value without touching the customer. */
	public static function clear($session)
	{
		if (!$session) {
			return;
		}
		foreach (self::$session_keys as $key) {
			$session->__unset($key);
		}
	}

	/** Shipping destination of the logged-in/session customer as stored server-side. */
	public static function customer_destination()
	{
		$customer = function_exists('WC') && WC() ? WC()->customer : null;
		if (!$customer) {
			return array();
		}
		return array(
			'state'     => $customer->get_shipping_state(),
			'city'      => $customer->get_shipping_city(),
			'address_1' => $customer->get_shipping_address_1(),
		);
	}

	private static function normalize_text($value)
	{
		$value = strtr(
			mb_strtolower(trim((string) $value), 'UTF-8'),
			array(
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
			)
		);
		return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
	}
}
