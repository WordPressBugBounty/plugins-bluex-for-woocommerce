<?php

declare(strict_types=1);

/**
 * Pure rules for "can this order be used to print a label?" (phone, email and address).
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Order_Check
{
	const MIN_SEARCH_LENGTH = 3;
	const MIN_PHONE_DIGITS  = 6;

	/**
	 * @param array $fields phone, email, address_1, city (shipping values with billing as fallback).
	 * @return string[] Codes of what is missing: phone | email | address.
	 */
	public static function missing(array $fields)
	{
		$missing = array();

		$phone = isset($fields['phone']) ? (string) $fields['phone'] : '';
		if (preg_match_all('/\d/', $phone) < self::MIN_PHONE_DIGITS) {
			$missing[] = 'phone';
		}

		$email = isset($fields['email']) ? trim((string) $fields['email']) : '';
		if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$missing[] = 'email';
		}

		$address = isset($fields['address_1']) ? trim((string) $fields['address_1']) : '';
		$city    = isset($fields['city']) ? trim((string) $fields['city']) : '';
		if ('' === $address || '' === $city) {
			$missing[] = 'address';
		}

		return $missing;
	}

	/**
	 * @param string[] $codes Output of missing().
	 * @return string[]
	 */
	public static function labels(array $codes)
	{
		$map = array(
			'phone'   => __('teléfono del cliente', 'woocommerce-correios'),
			'email'   => __('email del cliente', 'woocommerce-correios'),
			'address' => __('dirección del cliente', 'woocommerce-correios'),
		);

		$labels = array();
		foreach ($codes as $code) {
			$labels[] = isset($map[$code]) ? $map[$code] : (string) $code;
		}
		return $labels;
	}

	public static function is_search_term_valid($term)
	{
		$term = trim((string) $term);
		return '' === $term || strlen($term) >= self::MIN_SEARCH_LENGTH;
	}
}
