<?php
/**
 * wc-auth handshake endpoints (start, status, callback).
 *
 * @package WooCommerce_Correios/API/Endpoints
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Integration_Auth_Endpoint
{
	const NAMESPACE_ROUTE = 'wc-bluex/v1';

	public function register_routes()
	{
		register_rest_route(self::NAMESPACE_ROUTE, '/integration-auth/start', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'start'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route(self::NAMESPACE_ROUTE, '/integration-auth/status', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'status'),
			'permission_callback' => array($this, 'check_permission'),
		));

		// Public on purpose: WooCommerce posts here server-to-server; the single-use state authenticates the call.
		register_rest_route(self::NAMESPACE_ROUTE, '/wc-auth-callback', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'callback'),
			'permission_callback' => '__return_true',
		));
	}

	public function check_permission()
	{
		return current_user_can('manage_woocommerce');
	}

	public function start($request)
	{
		$store_id = sanitize_text_field((string) $request->get_param('storeId'));
		$result   = (new WC_BlueX_Integration_Auth())->start($store_id, get_current_user_id());

		return new WP_REST_Response($result, $result['success'] ? 200 : 400);
	}

	public function status($request)
	{
		$state  = sanitize_key((string) $request->get_param('state'));
		$result = (new WC_BlueX_Integration_Auth())->get_status($state, get_current_user_id());

		return new WP_REST_Response($result, 200);
	}

	public function callback($request)
	{
		$payload = $request->get_json_params();
		if (!is_array($payload)) {
			$payload = $request->get_body_params();
		}

		$result = (new WC_BlueX_Integration_Auth())->handle_callback(is_array($payload) ? $payload : array());

		return new WP_REST_Response(array('received' => $result['ok']), $result['ok'] ? 200 : 400);
	}
}
