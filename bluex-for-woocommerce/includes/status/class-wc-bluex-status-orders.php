<?php

declare(strict_types=1);

/**
 * WooCommerce orders as the status panel sees them: search and per-order label data check.
 * Uses the order CRUD API only, so it works with HPOS and legacy post storage.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Orders
{
	const RESULT_LIMIT = 20;
	const RECENT_DAYS  = 30;

	/**
	 * Recent orders when there is no term; otherwise the matches for the term.
	 *
	 * @param string $purpose "label" (any order can be checked) or "test" (adds why an order cannot be used as test order).
	 * @return array[] Each: id, number, customer, location, available, missing[], missingLabels[], reason, reasonLabel.
	 */
	public static function search($term, $purpose = 'label')
	{
		$term = trim((string) $term);

		if ('' === $term) {
			$orders = wc_get_orders(array(
				'limit'        => self::RESULT_LIMIT,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>=' . (time() - self::RECENT_DAYS * 86400),
			));
		} else {
			$ids    = array_slice(array_unique(WC_Data_Store::load('order')->search_orders($term)), 0, self::RESULT_LIMIT);
			$orders = array_map('wc_get_order', $ids);
		}

		$blue_status = 'test' === $purpose ? (string) WC_Correios_Settings::get_instance()->get_setting('noBlueStatus') : '';

		$result = array();
		foreach ($orders as $order) {
			if ($order instanceof WC_Order) {
				$result[] = self::describe($order, 'test' === $purpose ? $blue_status : null);
			}
		}
		return $result;
	}

	/**
	 * @param string|null $test_blue_status When not null the order is also checked as a test order (value: the
	 *                                      status that triggers the real send).
	 */
	public static function describe(WC_Order $order, $test_blue_status = null)
	{
		$missing = WC_BlueX_Status_Order_Check::missing(self::fields($order));
		$reason  = null !== $test_blue_status
			? WC_BlueX_Test_Order::ineligibility(WC_BlueX_Test_Order::facts_for($order, $test_blue_status, time()))
			: null;

		return array(
			'id'            => (int) $order->get_id(),
			'number'        => (string) $order->get_order_number(),
			'customer'      => self::customer($order),
			'location'      => (string) self::first($order->get_shipping_city(), $order->get_billing_city()),
			'available'     => empty($missing) && null === $reason,
			'missing'       => $missing,
			'missingLabels' => WC_BlueX_Status_Order_Check::labels($missing),
			'reason'        => $reason,
			'reasonLabel'   => null !== $reason ? WC_BlueX_Test_Order::reason_label($reason) : '',
		);
	}

	public static function fields(WC_Order $order)
	{
		$shipping_phone = method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '';

		return array(
			'phone'     => self::first($shipping_phone, $order->get_billing_phone()),
			'email'     => (string) $order->get_billing_email(),
			'address_1' => self::first($order->get_shipping_address_1(), $order->get_billing_address_1()),
			'city'      => self::first($order->get_shipping_city(), $order->get_billing_city()),
		);
	}

	private static function customer(WC_Order $order)
	{
		$name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
		if ('' === $name) {
			$name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}
		return $name;
	}

	private static function first($preferred, $fallback)
	{
		$preferred = trim((string) $preferred);
		return '' !== $preferred ? $preferred : trim((string) $fallback);
	}
}
