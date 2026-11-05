<?php

declare(strict_types=1);

/**
 * Remembers the outcome of the last order sent to Blue Express, so the status panel can tell
 * whether order capture is really working.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Order_Sync_Log
{
	const OPTION            = 'bluex_last_order_sync';
	const MAX_ERROR_LENGTH  = 200;

	/**
	 * @param mixed  $order_id Order the webhook was sent for (0 when unknown).
	 * @param bool   $ok       Whether Blue Express accepted it.
	 * @param string $error    Failure reason, shown to the seller.
	 */
	public static function record($order_id, $ok, $error = '')
	{
		$error = trim(wp_strip_all_tags((string) $error));
		if (strlen($error) > self::MAX_ERROR_LENGTH) {
			$error = substr($error, 0, self::MAX_ERROR_LENGTH);
		}

		update_option(self::OPTION, array(
			'time'     => time(),
			'order_id' => is_numeric($order_id) ? (int) $order_id : 0,
			'ok'       => (bool) $ok,
			'error'    => $ok ? '' : $error,
		), false);
	}

	/**
	 * @return array{time: int, order_id: int, ok: bool, error: string}|null
	 */
	public static function last()
	{
		$value = get_option(self::OPTION, null);
		return is_array($value) ? $value : null;
	}
}
