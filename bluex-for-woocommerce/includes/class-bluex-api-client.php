<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * BlueX API Client
 *
 * Handles communication with BlueX API endpoints.
 */
class BlueX_API_Client
{

    /**
     * Instance of WC_Correios_Settings.
     *
     * @var WC_Correios_Settings
     */
    private $settings_handler;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->settings_handler = WC_Correios_Settings::get_instance();
    }

    /**
     * Get the base URL for the BlueX API.
     *
     * @return string
     */
    private function get_base_url(): string
    {
        return $this->settings_handler->get_base_path();
    }

    /**
     * Get the API key for BlueX.
     *
     * @return string
     */
    private function get_api_key(): string
    {
        return $this->settings_handler->get_tracking_bxkey();
    }

    /**
     * Get the API key for BlueX based on userData.
     *
     * @param array|null $userData User specific data possibly containing the key.
     * @return string
     */
    private function get_tracking_bxkey_from_user(?array $userData): string
    {
        return $this->settings_handler->get_tracking_bxkey($userData);
    }


    /**
     * Makes an HTTP request to the BlueX API.
     *
     * @param string $method HTTP method (POST, GET).
     * @param string $endpoint API endpoint path.
     * @param array $data Request data/body.
     * @param array $extra_headers Additional headers.
     * @param bool $use_user_specific_key Whether to use user-specific key logic.
     * @param array|null $userData User specific data for key retrieval if $use_user_specific_key is true.
     * @return array|WP_Error The response body decoded as an array or WP_Error on failure.
     */
    private function make_request(string $method, string $endpoint, array $data = [], array $extra_headers = [], bool $use_user_specific_key = false, ?array $userData = null)
    {
        $url = $this->get_base_url() . $endpoint;
        $api_key = $use_user_specific_key ? $this->get_tracking_bxkey_from_user($userData) : $this->get_api_key();

        return $this->send_request($url, $api_key, $method, $endpoint, $data, $extra_headers);
    }

    /**
     * Sends the request to an absolute URL. `$endpoint` is only the log label.
     *
     * @return array|WP_Error
     */
    private function send_request(string $url, string $api_key, string $method, string $endpoint, array $data = [], array $extra_headers = [])
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'x-api-key'       => $api_key,
        ], $extra_headers);

        $args = [
            'method'  => strtoupper($method),
            'headers' => $headers,
            'timeout' => 30, // Increased timeout for potentially slow API calls
        ];

        if (!empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        bluex_log('info', "BlueX API Request: Method={$method}, Endpoint={$endpoint}");

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            bluex_log('error', "BlueX API WP_Error ({$endpoint}): " . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        bluex_log('info', "BlueX API Response ({$endpoint}): Code={$response_code}");

        if ($response_code < 200 || $response_code >= 300) {
            bluex_log('error', "BlueX API HTTP Error ({$endpoint}): Code={$response_code}");
            return new WP_Error('api_http_error', "BlueX API request failed with status {$response_code}.", ['status' => $response_code]);
        }

        $decoded_body = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            bluex_log('error', "BlueX API JSON Decode Error ({$endpoint}): " . json_last_error_msg());
            return new WP_Error('json_decode_error', 'Failed to decode API response.');
        }

        return $decoded_body;
    }

    /**
     * Get pricing information.
     *
     * @param array $from Origin details.
     * @param array $to Destination details.
     * @param string $service_type Service type code.
     * @param array $bultos Package details.
     * @param float $declared_value Declared value of the shipment.
     * @param string $familia_producto Product family ('PAQU', 'PUDO').
     * @param array|null $userData User-specific settings for API key retrieval.
     * @return array|WP_Error Pricing data or WP_Error.
     */
    public function get_pricing(array $from, array $to, string $service_type, array $bultos, float $declared_value, string $familia_producto, ?array $userData = null)
    {
        $endpoint = '/eplin/pricing/v1';
        $data = [
            'from'          => $from,
            'to'            => $to,
            'serviceType'   => $service_type,
            'domain'        => $this->settings_handler->get_account_name(),
            'datosProducto' => [
                'producto'        => 'P', // Assuming 'P' is standard
                'familiaProducto' => $familia_producto,
                'bultos'          => $bultos,
            ],
        ];

        $extra_headers = ['price' => (string) $declared_value];

        return $this->make_request('POST', $endpoint, $data, $extra_headers, true, $userData);
    }

    /**
     * Get pricing v2: pickup points and home delivery in a single quote.
     *
     * @param array $payload Body built by WC_BlueX_Pudo_Pricing_V2::build_payload().
     * @return array|WP_Error Decoded response ({"rates": [...]}) or WP_Error.
     */
    public function get_pudo_pricing_v2(array $payload)
    {
        $url = WC_BlueX_Pudo_Pricing_V2::endpoint_for_base_path($this->get_base_url());

        return $this->send_request($url, $this->get_api_key(), 'POST', WC_BlueX_Pudo_Pricing_V2::PATH, $payload);
    }


    /**
     * Get geolocation details for a comuna.
     *
     * @param string $address Normalized city name.
     * @param string $region_code Region code.
     * @param string|null $agency_id Agency ID for PUDO.
     * @param bool $is_pudo Whether PUDO is enabled.
     * @return array|WP_Error Geolocation data or WP_Error.
     */
    public function get_geolocation(string $address, string $region_code, ?string $agency_id, bool $is_pudo)
    {
        $endpoint = '/api/ecommerce/comunas/v1/bxgeo';
        if ($is_pudo) {
            $endpoint .= '/v2';
        }
        $data = [
            'address'    => $address,
            'type'       => 'woocommerce',
            'shop'       => $this->settings_handler->get_account_name(),
            'regionCode' => $region_code,
            'agencyId'   => $agency_id ?? '',
        ];
        // Note: This endpoint seems to expect a JSON string in the body, not a JSON object directly.
        // Let's try sending as an object first with make_request, if it fails, adjust.
        // Re-checking the original code: it uses a JSON string directly in the body, not encoded via wp_json_encode.
        // We need to adapt make_request or handle this specific case.

        // Adaptation: bypass make_request's auto json encoding for this specific endpoint if necessary.
        // For now, let's try with standard make_request which uses wp_json_encode.
        // Update: The original code *incorrectly* wrapped the values in single quotes within the JSON string.
        // Let's assume the API expects a standard JSON body.
        return $this->make_request('POST', $endpoint, $data);
    }


    /**
     * Validate the integration status.
     *
     * @return array|WP_Error Integration status data or WP_Error.
     */
    public function validate_integration_status()
    {
        $endpoint = '/api/ecommerce/token/v1/ecommerce/integration-status';
        $data = [
            'ecommerce'   => 'Woocommerce',
            'accountName' => $this->settings_handler->get_account_name(),
        ];
        return $this->make_request('POST', $endpoint, $data);
    }

    /**
     * Update integration credentials.
     *
     * @param string $store_id Store ID provided by BlueX.
     * @param array $credentials ['clientKey' => ..., 'clientSecret' => ...].
     * @return array|WP_Error Update result or WP_Error.
     */
    public function update_integration_credentials(string $store_id, array $credentials)
    {
        $endpoint = '/api/ecommerce/token/v1/ecommerce/update-tokens';
        $data = [
            'storeId'     => $store_id,
            'ecommerce'   => 'Woocommerce',
            'credentials' => [
                'accessToken' => $credentials['clientKey'] ?? null,
                'secretKey'   => $credentials['clientSecret'] ?? null,
                'accountName' => $this->settings_handler->get_account_name(),
            ],
        ];
        $response = $this->make_request('POST', $endpoint, $data);

        if (class_exists('WC_BlueX_Status_Facts')) {
            WC_BlueX_Status_Facts::forget_blue();
        }

        return $response;
    }

    /**
     * Send order data via webhook.
     *
     * @param array $order_data Mapped order data.
     * @return array|WP_Error Webhook response or WP_Error.
     */
    public function send_order_webhook(array $order_data)
    {
        $endpoint = '/api/integr/woocommerce-wh/v1/order';
        // The webhook expects the raw mapped order data as the body
        return $this->make_request('POST', $endpoint, $order_data);
    }

    /**
     * Send log data via webhook.
     *
     * @param string $error_message Error message.
     * @param array $payload Order payload associated with the error.
     * @return array|WP_Error Webhook response or WP_Error.
     */
    public function send_log_webhook(string $error_message, array $payload)
    {
        $endpoint = '/api/ecommerce/custom/logs/v1';
        $data = [
            'error' => $error_message,
            'order' => $payload,
        ];
        return $this->make_request('POST', $endpoint, $data);
    }

    /**
     * Get nearby PUDOS agencies using the nearby-pudos endpoint.
     * The endpoint path is constructed internally; callers only pass payload,
     * optional extra headers, cookie and optional userData for key selection.
     *
     * @param array $payload Request body or simplified params.
     * @param array $extra_headers Optional extra headers.
     * @param string|null $cookie Optional Cookie header value.
     * @param array|null $userData Optional user data to use a user-specific API key.
     * @return array|WP_Error Decoded response or WP_Error on failure.
     */
    public function get_nearby_pudos(array $payload, array $extra_headers = [], ?string $cookie = null, ?array $userData = null)
    {
        // Internally construct the nearby PUDOS endpoint path so callers
        // do not need to provide or manipulate the endpoint URL.
        $endpoint = '/api/ecommerce/comunas/v1/new-nearby-pudos';
        // If caller passed a full payload (contains 'from' and 'to'), use it as-is.
        if (!(is_array($payload) && isset($payload['from']) && isset($payload['to']))) {
            // Attempt to build the standardized payload from a simpler shape.
            // Expecting either:
            // - ['from_district' => 'SCL', 'package' => [...]] OR
            // - a package-like array with destination/contents_cost keys.

            $from_district = 'SCL';
            if (isset($payload['from_district']) && is_string($payload['from_district'])) {
                $from_district = $payload['from_district'];
            } elseif ($this->settings_handler && method_exists($this->settings_handler, 'get_settings')) {
                $settings = $this->settings_handler->get_settings();
                if (!empty($settings['districtCode'])) {
                    $from_district = $settings['districtCode'];
                }
            }

            // If $payload has a 'package' key, use it; otherwise treat $payload as package.
            $package = [];
            if (isset($payload['package']) && is_array($payload['package'])) {
                $package = $payload['package'];
            } elseif (isset($payload['destination']) || isset($payload['contents_cost'])) {
                $package = $payload;
            }

            $to_state = isset($package['destination']['state']) ? $package['destination']['state'] : null;
            $to_district = isset($package['destination']['city']) ? $package['destination']['city'] : $from_district;
            $bulto_peso = isset($package['contents_cost']) ? (float) $package['contents_cost'] : 1;

            $payload = [
                'from' => ['country' => 'CL', 'district' => $from_district],
                'to'   => [
                    'country'  => 'CL',
                    'state'    => $to_state,
                    'district' => $to_district,
                ],
                'domain' => $this->settings_handler->get_account_name(),
                'datosProducto' => [
                    'producto' => 'P',
                    'familiaProducto' => 'PAQU',
                    'bultos' => [[
                        'largo' => 10,
                        'ancho' => 10,
                        'alto' => 10,
                        'pesoFisico' => $bulto_peso,
                        'cantidad' => 1,
                    ]],
                ],
                'destination' => isset($package['destination']['city']) ? $package['destination']['city'] : '',
                'maxAgenciesShow' => 5,
                'includeGeolocation' => true,
            ];
        }

        // Allow callers to pass a cookie via params
        if (!empty($cookie)) {
            $extra_headers['Cookie'] = $cookie;
        }

        // Delegate to the centralized make_request for consistent headers, logging and error handling
        $use_user_specific_key = !empty($userData);
        return $this->make_request('POST', $endpoint, $payload, $extra_headers, $use_user_specific_key, $userData);
    }
}
