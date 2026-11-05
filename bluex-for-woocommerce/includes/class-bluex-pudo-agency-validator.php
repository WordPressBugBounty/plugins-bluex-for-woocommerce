<?php

defined('ABSPATH') || exit;

/**
 * Shared server-side trust boundary for PUDO agency selections.
 */
class WC_Bluex_Pudo_Agency_Validator
{
	/**
	 * Validate and canonicalize an agency against BlueX geolocation.
	 *
	 * @param mixed                   $agency_id  Attacker-controlled agency ID.
	 * @param array                   $destination WooCommerce destination.
	 * @param BlueX_API_Client|null   $client Optional client for focused tests.
	 * @return array|WP_Error
	 */
	public static function validate($agency_id, array $destination, $client = null)
	{
		if (!is_scalar($agency_id)) {
			return new WP_Error('bluex_pudo_invalid_agency', __('El punto Blue Express no es válido.', 'woocommerce-correios'));
		}
		$agency_id = sanitize_text_field((string) $agency_id);
		if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $agency_id)) {
			return new WP_Error('bluex_pudo_invalid_agency', __('El punto Blue Express no es válido.', 'woocommerce-correios'));
		}

		$city_value = $destination['city'] ?? '';
		$state_value = $destination['state'] ?? '';
		if (!is_scalar($city_value) || !is_scalar($state_value)) {
			return new WP_Error('bluex_pudo_missing_destination', __('Completa la comuna y región antes de seleccionar un punto.', 'woocommerce-correios'));
		}

		$city = sanitize_text_field((string) $city_value);
		$state = sanitize_text_field((string) $state_value);
		if ($city !== '' && $state === '') {
			$state = self::resolve_state_from_city($city);
		}
		if ($city === '' || $state === '') {
			return new WP_Error('bluex_pudo_missing_destination', __('Completa la comuna y región antes de seleccionar un punto.', 'woocommerce-correios'));
		}

		$region = strpos($state, 'CL-') === 0 ? substr($state, 3) : $state;
		$client = $client ?: new BlueX_API_Client();
		$response = $client->get_geolocation($city, $region, $agency_id, true);
		if (is_wp_error($response) || !is_array($response) || empty($response['pickupInfo']) || !is_array($response['pickupInfo'])) {
			return new WP_Error('bluex_pudo_unresolved_agency', __('No pudimos validar el punto Blue Express seleccionado.', 'woocommerce-correios'));
		}

		$pickup = $response['pickupInfo'];
		$resolved_id = sanitize_text_field((string) ($pickup['agency_id'] ?? $pickup['agencyId'] ?? $pickup['id'] ?? ''));
		if ($resolved_id !== '' && !hash_equals($agency_id, $resolved_id)) {
			return new WP_Error('bluex_pudo_agency_mismatch', __('El punto Blue Express seleccionado no coincide con la respuesta del servicio.', 'woocommerce-correios'));
		}

		$address = $pickup['agency_address'] ?? $pickup['agencyAddress'] ?? $pickup['address'] ?? '';
		if (is_array($address)) {
			$address = implode(', ', array_filter(array_map('sanitize_text_field', $address)));
		}

		// Keep pickup geography separate from the WooCommerce customer address.
		// Prefer canonical fields returned by the same trusted BlueX lookup that
		// resolved the agency ID, and fall back to the already-validated request
		// geography only when the response omits them.
		$resolved_city = self::first_non_empty_scalar(array(
			$response['cidadeName'] ?? null,
			$pickup['city_name'] ?? null,
			$pickup['cityName'] ?? null,
			$city,
		));

		$resolved_state = self::first_non_empty_scalar(array(
			$response['regionCode'] ?? null,
			$pickup['regionCode'] ?? null,
			$state,
		));
		if ($resolved_state !== '' && strpos($resolved_state, 'CL-') !== 0) {
			$resolved_state = 'CL-' . $resolved_state;
		}

		return array(
			'agency_id'      => $agency_id,
			'agency_name'    => sanitize_text_field((string) ($pickup['agency_name'] ?? $pickup['agencyName'] ?? '')),
			'agency_address' => sanitize_text_field((string) $address),
			'agency_city'    => $resolved_city,
			'agency_state'   => $resolved_state,
		);
	}

	/**
	 * Return the first scalar value that remains non-empty after sanitization.
	 *
	 * BlueX may include canonical keys with an empty string. Null coalescing does
	 * not skip those values, so an explicit scan is required before falling back
	 * to the already-validated widget geography.
	 *
	 * @param array $values Candidate values ordered by trust/preference.
	 * @return string
	 */
	private static function first_non_empty_scalar(array $values)
	{
		foreach ($values as $value) {
			if (!is_scalar($value)) {
				continue;
			}
			$value = sanitize_text_field((string) $value);
			if ($value !== '') {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Resolve a missing widget region from the canonical Chile commune list.
	 *
	 * @param string $city Commune name from the trusted widget message.
	 * @return string WooCommerce state code or an empty string.
	 */
	private static function resolve_state_from_city($city)
	{
		if (!function_exists('bluex_get_communes_by_region')) {
			$communes_file = __DIR__ . '/data/chile-communes.php';
			if (is_readable($communes_file)) {
				require_once $communes_file;
			}
		}
		if (!function_exists('bluex_get_communes_by_region')) {
			return '';
		}

		$wanted = self::normalize_location_name($city);
		foreach (bluex_get_communes_by_region() as $region_code => $communes) {
			foreach ((array) $communes as $commune) {
				$name = is_array($commune) ? ($commune['name'] ?? '') : '';
				if ($wanted !== '' && hash_equals($wanted, self::normalize_location_name($name))) {
					return sanitize_text_field((string) $region_code);
				}
			}
		}
		return '';
	}

	/**
	 * Normalize location labels for accent/case-insensitive comparison.
	 */
	private static function normalize_location_name($value)
	{
		$value = sanitize_text_field((string) $value);
		if (function_exists('remove_accents')) {
			$value = remove_accents($value);
		} elseif (function_exists('iconv')) {
			$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
			if ($ascii !== false) {
				$value = $ascii;
			}
		}
		$value = strtolower($value);
		return preg_replace('/[^a-z0-9]+/', '', $value);
	}
}
