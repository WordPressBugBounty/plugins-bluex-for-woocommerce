<?php
/**
 * Nearby PUDO service helper.
 *
 * Performs an HTTP POST to the internal nearby-pudos endpoint and exposes
 * helpers to extract the closest agency price.
 *
 * @package WooCommerce_Correios/Classes
 */

defined('ABSPATH') || exit;

class WC_Bluex_Pudo_Nearby_Service
{
    /**
     * Default endpoint (can be overridden when calling).
     * @var string
     */
    // public static $default_endpoint = 'https://eplin.api.qa.blue.cl/api/ecommerce/comunas/v1/new-nearby-pudos';

    /**
     * Fetch nearby pudos from the remote service.
     * Logs status/error summaries and returns decoded JSON.
     *
     * @param array|string $from_district_or_payload Payload or origin district.
     * @param array $package Package info when using simplified args.
     * @param string $domain Domain identifier used in payload.
     * @param int $maxAgenciesShow Max agencies to request.
     * @param bool $includeGeolocation Whether to include geolocation in response.
     * @param array|null $datosGeo Optional pre-fetched geolocation (regionCode/districtCode).
     * @param string $cookie Optional Cookie header value.
     * @return array|null Decoded response array or null on error.
     */
    public static function fetch_nearby_pudos($from_district_or_payload, $package = [], $domain = 'copec-qa', $maxAgenciesShow = 5, $includeGeolocation = true, $datosGeo = null, $cookie = '')
    {
        // Allow callers to pass either a fully-built payload (legacy) or
        // individual parameters ($from_district, $package...). Detect shape.
        if (is_array($from_district_or_payload) && isset($from_district_or_payload['from']) && isset($from_district_or_payload['to'])) {
            $payload = $from_district_or_payload;
        } else {
            $from_district = is_string($from_district_or_payload) ? $from_district_or_payload : 'SCL';

            // Prefer explicit geolocation values when provided (regionCode/districtCode)
            $to_state = isset($datosGeo['regionCode']) ? $datosGeo['regionCode'] : (isset($package['destination']['state']) ? $package['destination']['state'] : null);
            $to_district = isset($datosGeo['districtCode']) ? $datosGeo['districtCode'] : (isset($package['destination']['city']) ? $package['destination']['city'] : $from_district);

            $bultos = class_exists('WC_Correios_Package')
                ? (new WC_Correios_Package($package))->get_bultos()
                : array();
            if (empty($bultos)) {
                return null;
            }

            $payload = array(
                'from' => array('country' => 'CL', 'district' => $from_district),
                'to'   => array(
                    'country'  => 'CL',
                    'state'    => $to_state,
                    'district' => $to_district,
                ),
                'domain' => $domain,
                'datosProducto' => array(
                    'producto' => 'P',
                    'familiaProducto' => 'PAQU',
                    'bultos' => $bultos,
                ),
                'destination' => isset($package['destination']['address']) ? $package['destination']['address'] : '',
                'maxAgenciesShow' => $maxAgenciesShow,
                'includeGeolocation' => $includeGeolocation,
            );
        }

        // Prefer the centralized API client if available.
        if (class_exists('BlueX_API_Client')) {
            $client = new BlueX_API_Client();
            // Use client method which now builds the endpoint internally.
            $resp = $client->get_nearby_pudos($payload, [], !empty($cookie) ? $cookie : null, null);
            if (is_wp_error($resp)) {
                bluex_log('error', 'Nearby PUDOS via API client failed: ' . $resp->get_error_message());
                return null;
            }
            return $resp;
        }

        // Fallback to direct wp_remote_post if client not present.
        // Build a sane default endpoint using settings base path when possible.
        $base = 'https://eplin.api.blue.cl';
        if (class_exists('WC_Correios_Settings')) {
            $base = rtrim(WC_Correios_Settings::get_instance()->get_base_path(), '/');
        }
        $endpoint = $base . '/api/ecommerce/comunas/v1/new-nearby-pudos';

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        );

        if (!empty($cookie)) {
            $args['headers']['Cookie'] = $cookie;
        }

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            bluex_log('error', 'Nearby PUDOS request failed: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        bluex_log('info', "Nearby PUDOS HTTP {$code}");

        if ($code < 200 || $code >= 300) {
            bluex_log('error', 'Nearby PUDOS returned non-2xx code: ' . $code);
            return null;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            bluex_log('error', 'Nearby PUDOS JSON decode error: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Return the agency with the smallest `userDistance` and its price.
     *
     * @param array $response Decoded response from fetch_nearby_pudos.
     * @return array|null Array with keys `agency` and `price`, or null if none.
     */
    public static function get_closest_agency_price($response)
    {
        if (empty($response) || !isset($response['agencies']) || !is_array($response['agencies'])) {
            return null;
        }

        $closest = null;
        foreach ($response['agencies'] as $agency) {
            if (!isset($agency['userDistance'])) {
                continue;
            }
            if ($closest === null || (float) $agency['userDistance'] < (float) $closest['userDistance']) {
                $closest = $agency;
            }
        }

        if ($closest === null) {
            return null;
        }

        // Prefer `price` field, fallback to `total_price` if present.
        $price = null;
        if (isset($closest['price'])) {
            $price = $closest['price'];
        } elseif (isset($closest['total_price'])) {
            $price = $closest['total_price'];
        }

        return array(
            'agency' => $closest,
            'price'  => $price,
        );
    }
}
