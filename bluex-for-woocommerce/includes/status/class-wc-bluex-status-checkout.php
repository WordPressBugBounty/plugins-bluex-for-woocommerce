<?php

declare(strict_types=1);

/**
 * Whether the checkout of the store requires the data Blue Express needs to issue a label (phone, email and address).
 * Reads the checkout the store really uses: classic fields (already filtered by themes and plugins) or Checkout block.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Checkout
{
	const BLOCK_CHECKOUT = 'woocommerce/checkout';
	const ADDRESS_BLOCKS = array('woocommerce/checkout-shipping-address-block', 'woocommerce/checkout-billing-address-block');
	const BLOCKS_UTILS   = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';

	/**
	 * @return array{mode: string, required: array{phone: bool, email: bool, address: bool}}|null Null when it cannot be determined.
	 */
	public static function inspect()
	{
		$page_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;
		$page    = $page_id > 0 ? get_post($page_id) : null;
		if (!$page) {
			return null;
		}

		if (has_block(self::BLOCK_CHECKOUT, $page)) {
			return array('mode' => 'blocks', 'required' => self::blocks_required($page));
		}

		return array('mode' => 'classic', 'required' => self::required_from_fields(WC()->checkout()->get_checkout_fields('billing')));
	}

	/**
	 * @param array $billing Billing fieldset of WC_Checkout::get_checkout_fields(). A field that is not there is not required.
	 * @return array{phone: bool, email: bool, address: bool}
	 */
	public static function required_from_fields(array $billing)
	{
		return array(
			'phone'   => !empty($billing['billing_phone']['required']),
			'email'   => !empty($billing['billing_email']['required']),
			'address' => !empty($billing['billing_address_1']['required']),
		);
	}

	/**
	 * Phone setting of the Checkout block in WooCommerce versions that keep it in the address blocks of the page.
	 * The shipping address block wins because Blue Express reads the shipping phone first.
	 *
	 * @param array $blocks Output of parse_blocks().
	 */
	public static function phone_required_in_blocks(array $blocks)
	{
		$found = array();
		self::collect_phone_setting($blocks, $found);

		foreach (self::ADDRESS_BLOCKS as $name) {
			if (isset($found[$name])) {
				return $found[$name];
			}
		}
		return false;
	}

	private static function collect_phone_setting(array $blocks, array &$found)
	{
		foreach ($blocks as $block) {
			$name = isset($block['blockName']) ? (string) $block['blockName'] : '';
			if (in_array($name, self::ADDRESS_BLOCKS, true) && !isset($found[$name])) {
				$found[$name] = !empty($block['attrs']['requirePhoneField']);
			}
			if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				self::collect_phone_setting($block['innerBlocks'], $found);
			}
		}
	}

	private static function blocks_required($page)
	{
		$countries = WC()->countries;
		$address   = $countries->get_address_fields($countries->get_base_country(), 'billing_');

		return array(
			'phone'   => self::blocks_phone_required($page),
			'email'   => true,
			'address' => !empty($address['billing_address_1']['required']),
		);
	}

	private static function blocks_phone_required($page)
	{
		if (is_callable(array(self::BLOCKS_UTILS, 'get_phone_field_visibility'))) {
			return 'required' === call_user_func(array(self::BLOCKS_UTILS, 'get_phone_field_visibility'));
		}

		return self::phone_required_in_blocks(parse_blocks((string) $page->post_content));
	}
}
