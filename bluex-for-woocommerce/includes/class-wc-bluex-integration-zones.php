<?php
/**
 * Automatic Blue Express shipping zone provisioning after integration.
 *
 * @package WooCommerce_Correios
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Creates the Blue Express zones only when the store has none, and never
 * replaces or edits zones the seller already manages.
 */
class WC_BlueX_Integration_Zones
{
	const METHODS = array('bluex-ex', 'bluex-py', 'bluex-pudo');

	/**
	 * @param object|null $automation Zone automation service (get_zones_status, create_zones).
	 * @return array{status: string, code?: string, message: string, summary?: array}
	 */
	public static function provision($automation = null)
	{
		try {
			$automation = $automation ?: WC_BlueX_Shipping_Zone_Automation();

			$status = $automation->get_zones_status();
			if (!empty($status['zones_with_bluex'])) {
				return array(
					'status'  => 'skipped',
					'message' => __('Ya existen zonas de envío con Blue Express; se mantuvieron sin cambios.', 'woocommerce-correios'),
				);
			}

			$result = $automation->create_zones(array(
				'strategy'        => 'by_region',
				'methods'         => self::METHODS,
				'mode'            => 'create_new',
				'include_pudos'   => true,
				'dry_run'         => false,
				'backup_existing' => false,
				'exclude_islands' => true,
				'fee'             => '19%',
			));

			if (!empty($result['success'])) {
				return array(
					'status'  => 'created',
					'message' => __('Zonas de envío creadas correctamente.', 'woocommerce-correios'),
					'summary' => isset($result['summary']) ? $result['summary'] : array(),
				);
			}

			return self::error_from(isset($result['error']) ? (string) $result['error'] : '');
		} catch (Throwable $e) {
			return self::error_from($e->getMessage());
		}
	}

	private static function error_from($raw)
	{
		if (function_exists('bluex_log')) {
			bluex_log('error', 'Zone provisioning failed: ' . $raw);
		}

		if (false !== strpos($raw, 'no está disponible')) {
			return self::error('methods_unavailable', __('Los métodos de envío de Blue Express no están disponibles en tu tienda. Verifica que el plugin esté activo y vuelve a intentarlo.', 'woocommerce-correios'));
		}
		if (false !== strpos($raw, 'Permisos insuficientes')) {
			return self::error('permission_denied', __('Tu usuario no tiene permisos para crear zonas de envío. Ingresa con un administrador de la tienda.', 'woocommerce-correios'));
		}
		if (false !== strpos($raw, 'WooCommerce no está activo')) {
			return self::error('woocommerce_inactive', __('WooCommerce no está activo. Actívalo para crear las zonas de envío.', 'woocommerce-correios'));
		}

		return self::error('zones_failed', __('No pudimos crear las zonas de envío automáticamente. Puedes crearlas desde la pestaña "Zonas de Envío".', 'woocommerce-correios'));
	}

	private static function error($code, $message)
	{
		return array('status' => 'error', 'code' => $code, 'message' => $message);
	}
}
