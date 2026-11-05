<?php

declare(strict_types=1);

/**
 * "Probar creación etiqueta": sends one real WooCommerce order to Blue Express through the same webhook
 * as a normal order, tagged with webhookDetail.tags = "isTestOrder" so it can be told apart.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Test_Order
{
	const TAG            = 'isTestOrder';
	const META           = '_bluex_test_order';
	const LOCAL_OPTION   = 'bluex_test_order_local';
	const LOCK_PREFIX    = 'bluex_test_order_lock_';
	const LOCK_TTL       = 30;
	const RECENT_DAYS    = 30;

	// Kill switch: return false from this filter to stop the panel from sending test orders.
	const ENABLED_FILTER = 'bluex_status_test_order_enabled';

	/**
	 * Adds the test marker without dropping anything else the payload carries in webhookDetail.
	 */
	public static function mark_payload(array $payload)
	{
		$detail = isset($payload['webhookDetail']) && is_array($payload['webhookDetail']) ? $payload['webhookDetail'] : array();
		$detail['tags'] = self::TAG;
		$payload['webhookDetail'] = $detail;

		return $payload;
	}

	/**
	 * Why an order cannot be used for the test (it was probably sent before, or is too old).
	 *
	 * @param array $facts has_test_meta, has_tracking, status ("wc-..."), blue_status, created (timestamp|null), now.
	 * @return string|null already_test | has_tracking | already_sent | too_old
	 */
	public static function ineligibility(array $facts)
	{
		if (!empty($facts['has_test_meta'])) {
			return 'already_test';
		}
		if (!empty($facts['has_tracking'])) {
			return 'has_tracking';
		}

		$blue_status = isset($facts['blue_status']) ? (string) $facts['blue_status'] : '';
		if ('' !== $blue_status && isset($facts['status']) && $blue_status === (string) $facts['status']) {
			return 'already_sent';
		}

		$created = isset($facts['created']) ? (int) $facts['created'] : 0;
		$now     = isset($facts['now']) ? (int) $facts['now'] : 0;
		if ($created > 0 && $now > 0 && ($now - $created) > self::RECENT_DAYS * 86400) {
			return 'too_old';
		}

		return null;
	}

	public static function reason_label($code)
	{
		$labels = array(
			'already_test' => __('Ya se usó como orden de prueba', 'woocommerce-correios'),
			'has_tracking' => __('Ya tiene código de seguimiento de Blue Express', 'woocommerce-correios'),
			'already_sent' => __('Ya fue enviada a Blue Express', 'woocommerce-correios'),
			'too_old'      => __('Tiene más de 30 días', 'woocommerce-correios'),
		);
		return isset($labels[$code]) ? $labels[$code] : '';
	}

	/**
	 * Checks the integration before spending a send (the panel's equivalent of Shopify's "validate required data").
	 *
	 * @param array $blue Facts "blue": ok, active, message, data.
	 * @return array{code: string, message: string}|null null when everything is in place.
	 */
	public static function preflight(array $blue)
	{
		if (empty($blue['ok'])) {
			return array('code' => 'blue_unreachable', 'message' => __('No pudimos consultar Blue Express. Intenta de nuevo en unos minutos.', 'woocommerce-correios'));
		}
		if (empty($blue['active'])) {
			$message = !empty($blue['message']) ? (string) $blue['message'] : __('La integración no está activa en Blue Express.', 'woocommerce-correios');
			return array('code' => 'integration_inactive', 'message' => $message);
		}

		$address = WC_BlueX_Status_Checks::store_address(isset($blue['data']) && is_array($blue['data']) ? $blue['data'] : array());
		if ('' === $address['region'] || '' === $address['commune']) {
			return array('code' => 'store_address_missing', 'message' => __('No pudimos identificar la región y comuna de tu tienda en Blue Express.', 'woocommerce-correios'));
		}

		return null;
	}

	/**
	 * @param string|null $blue_status Status that triggers the real send, e.g. "wc-shipping-progress".
	 */
	public static function facts_for(WC_Order $order, $blue_status, $now)
	{
		$created = $order->get_date_created();

		return array(
			'has_test_meta' => '' !== (string) $order->get_meta(self::META),
			'has_tracking'  => function_exists('wc_correios_getTrackingCodes') && !empty(wc_correios_getTrackingCodes($order)),
			'status'        => 'wc-' . $order->get_status(),
			'blue_status'   => (string) $blue_status,
			'created'       => $created ? (int) $created->getTimestamp() : null,
			'now'           => (int) $now,
		);
	}

	/**
	 * Sends the order as a test order. Everything is checked again here: the panel's list is only a hint.
	 *
	 * @return array{success: true, data: array}|array{success: false, code: string, message: string, status: int}
	 */
	public static function emit($order_id)
	{
		if (!apply_filters(self::ENABLED_FILTER, true)) {
			return self::failure('disabled', __('La orden de prueba no está disponible por ahora.', 'woocommerce-correios'), 403);
		}

		$order_id = (int) $order_id;
		$lock_key = self::LOCK_PREFIX . $order_id;
		if (get_transient($lock_key)) {
			return self::failure('locked', __('Esta orden ya se está enviando. Espera unos segundos.', 'woocommerce-correios'), 409);
		}
		set_transient($lock_key, 1, self::LOCK_TTL);

		$result = self::send($order_id);
		if (empty($result['success'])) {
			delete_transient($lock_key);
		}

		return $result;
	}

	private static function send($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return self::failure('not_found', __('No encontramos esa orden.', 'woocommerce-correios'), 404);
		}

		$problem = self::preflight(WC_BlueX_Status_Facts::blue_state(true));
		if (null !== $problem) {
			return self::failure($problem['code'], $problem['message'], 422);
		}

		$settings = WC_Correios_Settings::get_instance();
		$missing  = WC_BlueX_Status_Order_Check::missing(WC_BlueX_Status_Orders::fields($order));
		if (!empty($missing)) {
			return self::failure(
				'missing_data',
				sprintf(__('A la orden le falta: %s.', 'woocommerce-correios'), implode(', ', WC_BlueX_Status_Order_Check::labels($missing))),
				422
			);
		}

		$reason = self::ineligibility(self::facts_for($order, $settings->get_setting('noBlueStatus'), time()));
		if (null !== $reason) {
			return self::failure($reason, self::reason_label($reason) . '.', 422);
		}

		$payload = WC_Correios_Webhook::instance()->build_order_payload($order_id);
		if (null === $payload) {
			return self::failure('payload_failed', __('No pudimos armar la orden para enviarla.', 'woocommerce-correios'), 500);
		}

		$response = (new BlueX_API_Client())->send_order_webhook(array(self::mark_payload($payload)));
		if (is_wp_error($response)) {
			bluex_log('error', 'Test order ' . $order_id . ' was not sent: ' . $response->get_error_message());
			return self::failure('send_failed', __('No pudimos enviar la orden a Blue Express. Intenta de nuevo.', 'woocommerce-correios'), 502);
		}

		$now = time();
		$order->update_meta_data(self::META, (string) $now);
		$order->save();
		update_option(self::LOCAL_OPTION, array(
			'order_id'     => $order_id,
			'order_number' => (string) $order->get_order_number(),
			'sent_at'      => $now,
		), false);

		return array(
			'success' => true,
			'data'    => array(
				'orderId'     => $order_id,
				'orderNumber' => (string) $order->get_order_number(),
				'sentAt'      => gmdate('c', $now),
			),
		);
	}

	private static function failure($code, $message, $status)
	{
		return array('success' => false, 'code' => $code, 'message' => $message, 'status' => $status);
	}
}
