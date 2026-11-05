<?php

declare(strict_types=1);

/**
 * Integration status endpoint ("Estado de tienda" panel).
 *
 * @package WooCommerce_Correios/API/Endpoints
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Integration_Status_Endpoint
{
	const NAMESPACE_ROUTE = 'wc-bluex/v1';

	public function register_routes()
	{
		register_rest_route(self::NAMESPACE_ROUTE, '/integration-status', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'get_status'),
			'permission_callback' => array($this, 'check_permission'),
			'args'                => array(
				'refresh' => array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		));

		register_rest_route(self::NAMESPACE_ROUTE, '/status/orders/search', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'search_orders'),
			'permission_callback' => array($this, 'check_permission'),
			'args'                => array(
				'term' => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'purpose' => array(
					'type'    => 'string',
					'default' => 'label',
					'enum'    => array('label', 'test'),
				),
			),
		));

		register_rest_route(self::NAMESPACE_ROUTE, '/status/test-order/emit', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'emit_test_order'),
			'permission_callback' => array($this, 'check_permission'),
			'args'                => array(
				'orderId' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		));
	}

	public function check_permission()
	{
		return current_user_can('manage_woocommerce');
	}

	/**
	 * @param WP_REST_Request $request "term": at least 3 characters, or empty for the most recent orders.
	 * @return WP_REST_Response
	 */
	public function search_orders($request)
	{
		$term = (string) $request->get_param('term');

		if (!WC_BlueX_Status_Order_Check::is_search_term_valid($term)) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => sprintf(__('Escribe al menos %d caracteres.', 'woocommerce-correios'), WC_BlueX_Status_Order_Check::MIN_SEARCH_LENGTH),
			), 400);
		}

		try {
			$purpose = 'test' === (string) $request->get_param('purpose') ? 'test' : 'label';

			return new WP_REST_Response(array(
				'success' => true,
				'data'    => array('orders' => WC_BlueX_Status_Orders::search($term, $purpose)),
			), 200);
		} catch (Throwable $e) {
			bluex_log('error', 'Status order search failed: ' . $e->getMessage());
			return new WP_REST_Response(array(
				'success' => false,
				'message' => __('No pudimos buscar órdenes.', 'woocommerce-correios'),
			), 500);
		}
	}

	/**
	 * @param WP_REST_Request $request "orderId": order to send to Blue Express as a test order.
	 * @return WP_REST_Response
	 */
	public function emit_test_order($request)
	{
		$order_id = (int) $request->get_param('orderId');

		if ($order_id <= 0) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => __('Selecciona una orden.', 'woocommerce-correios'),
			), 400);
		}

		try {
			$result = WC_BlueX_Test_Order::emit($order_id);
		} catch (Throwable $e) {
			bluex_log('error', 'Status test order failed: ' . $e->getMessage());
			return new WP_REST_Response(array(
				'success' => false,
				'message' => __('No pudimos enviar la orden de prueba.', 'woocommerce-correios'),
			), 500);
		}

		if (empty($result['success'])) {
			return new WP_REST_Response(array(
				'success' => false,
				'code'    => $result['code'],
				'message' => $result['message'],
			), $result['status']);
		}

		return new WP_REST_Response(array('success' => true, 'data' => $result['data']), 200);
	}

	/**
	 * @param WP_REST_Request $request Request. "refresh=1" bypasses the Blue and pricing caches.
	 * @return WP_REST_Response
	 */
	public function get_status($request)
	{
		try {
			$facts = WC_BlueX_Status_Facts::collect((bool) $request->get_param('refresh'));

			return new WP_REST_Response(array(
				'success' => true,
				'data'    => WC_BlueX_Status_Report::build($facts),
			), 200);
		} catch (Throwable $e) {
			bluex_log('error', 'Integration status failed: ' . $e->getMessage());
			return new WP_REST_Response(array(
				'success' => false,
				'message' => __('No pudimos cargar el estado de la integración.', 'woocommerce-correios'),
			), 500);
		}
	}
}
