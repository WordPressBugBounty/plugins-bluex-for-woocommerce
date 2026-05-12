<?php

/**
 * WooCommerce cart integration
 *
 * @package WooCommerce_Correios/Classes/Cart
 */

defined('ABSPATH') || exit;

/**
 * Cart integration.
 */
class WC_Correios_Cart
{

	/**
	 * Init cart actions.
	 */
	public function __construct()
	{
		add_action('woocommerce_after_shipping_rate', array($this, 'shipping_delivery_forecast'), 100);
	}

	/**
	 * Adds delivery forecast after method name.
	 *
	 * Skips bluex-pudo: PUDO is a pickup, not a time-bound courier delivery,
	 * so "Hasta N días hábiles" under the rate is misleading. Matches the
	 * equivalent suppression in the Blocks flow (add_delivery_forecast_data
	 * in class-wc-correios-blocks-integration.php) so both checkouts render
	 * the same shape.
	 *
	 * @param WC_Shipping_Rate $shipping_method Shipping method data.
	 */
	public function shipping_delivery_forecast($shipping_method)
	{
		if (is_object($shipping_method) && method_exists($shipping_method, 'get_method_id') && $shipping_method->get_method_id() === 'bluex-pudo') {
			return;
		}

		$meta_data = $shipping_method->get_meta_data();
		$total     = (empty($meta_data['_delivery_forecast'])) ? null : $meta_data['_delivery_forecast'];

		if ($total) {
			/* translators: %d: days to delivery */
			echo '<p><small>' . esc_html($total) . '</small></p>';
		}
	}
}

new WC_Correios_Cart();
