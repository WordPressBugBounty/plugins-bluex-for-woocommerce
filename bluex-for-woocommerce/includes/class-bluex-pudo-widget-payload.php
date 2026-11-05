<?php
/**
 * Cart -> pickup-point widget payload (v2).
 *
 * Builds the cart half of the widget's `SEND_CART_ITEMS` message. The widget keeps
 * Shopify's contract, where prices travel in cents, so price and total are x100;
 * the plugin's own quotes (WC_Correios_Webservice) stay in pesos and are untouched.
 *
 * Weight and dimensions come from WC_Correios_Package::get_product_bulto(), the same
 * calculation used for the real quote: always kg and cm, with the same defaults.
 *
 * @package WooCommerce_Correios/Classes
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Pudo_Widget_Payload
{
	/**
	 * @param object $cart WC_Cart (or any object exposing get_cart()).
	 * @return array total_price, item_count, currency and items[] for the widget.
	 */
	public static function from_cart($cart)
	{
		$items = array();
		$total = 0;
		$count = 0;

		foreach ((array) $cart->get_cart() as $line) {
			$item = self::build_item($line);
			if (null === $item) {
				continue;
			}
			$items[] = $item;
			$total  += $item['price'] * $item['quantity'];
			$count  += $item['quantity'];
		}

		return array(
			'total_price' => $total,
			'item_count'  => $count,
			'currency'    => get_woocommerce_currency(),
			'items'       => $items,
		);
	}

	/**
	 * @param array $line One WC_Cart::get_cart() entry.
	 * @return array|null Null for lines that do not ship.
	 */
	private static function build_item($line)
	{
		$product = isset($line['data']) ? $line['data'] : null;
		if (!is_object($product) || !method_exists($product, 'needs_shipping') || !$product->needs_shipping()) {
			return null;
		}

		$bulto = WC_Correios_Package::get_product_bulto($product, isset($line['quantity']) ? $line['quantity'] : 1);

		if (!empty($line['variation_id'])) {
			$id = (int) $line['variation_id'];
		} elseif (!empty($line['product_id'])) {
			$id = (int) $line['product_id'];
		} else {
			$id = (int) $product->get_id();
		}

		return array(
			'id'                  => $id,
			'quantity'            => $bulto['cantidad'],
			'weightKg'            => round($bulto['pesoFisico'], 3),
			'price'               => (int) round(((float) $product->get_price('edit')) * 100),
			'title'               => sanitize_text_field((string) $product->get_name()),
			'options_with_values' => array(array(
				'name'  => 'Dimensiones',
				'value' => sprintf('height: %d / width: %d / large: %d', $bulto['alto'], $bulto['ancho'], $bulto['largo']),
			)),
		);
	}
}
