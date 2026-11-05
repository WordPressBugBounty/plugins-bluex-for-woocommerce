<?php
/**
 * Integration setup endpoints (post-integration defaults and zone provisioning).
 *
 * @package WooCommerce_Correios/API/Endpoints
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Each step is its own route so the admin UI can show real progress and retry a single step.
 */
class WC_BlueX_Integration_Setup_Endpoint
{
	const NAMESPACE_ROUTE = 'wc-bluex/v1';

	public function register_routes()
	{
		register_rest_route(self::NAMESPACE_ROUTE, '/integration-setup/defaults', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'apply_defaults'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route(self::NAMESPACE_ROUTE, '/integration-setup/zones', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'provision_zones'),
			'permission_callback' => array($this, 'check_permission'),
		));
	}

	public function check_permission()
	{
		return current_user_can('manage_woocommerce');
	}

	public function apply_defaults($request = null)
	{
		try {
			$is_new = $request && rest_sanitize_boolean($request->get_param('isNew'));
			$result = WC_BlueX_Integration_Defaults::apply(null, array(
				'origin_district'       => $request ? $request->get_param('districtCode') : null,
				'force_origin_district' => $is_new,
			));

			return new WP_REST_Response(array(
				'success' => true,
				'status'  => empty($result['applied']) ? 'unchanged' : 'applied',
				'applied' => $result['applied'],
				'skipped' => $result['skipped'],
				'message' => __('Configuración predeterminada aplicada.', 'woocommerce-correios'),
			), 200);
		} catch (Throwable $e) {
			bluex_log('error', 'Integration defaults failed: ' . $e->getMessage());
			return new WP_REST_Response(array(
				'success' => false,
				'code'    => 'defaults_failed',
				'message' => __('No pudimos aplicar la configuración predeterminada. Puedes ajustarla desde la pestaña "Configuración".', 'woocommerce-correios'),
			), 500);
		}
	}

	public function provision_zones()
	{
		$result = WC_BlueX_Integration_Zones::provision();

		if ('error' === $result['status']) {
			return new WP_REST_Response(array_merge(array('success' => false), $result), 500);
		}

		return new WP_REST_Response(array_merge(array('success' => true), $result), 200);
	}
}
