<?php

declare(strict_types=1);

/**
 * Counters and progress for the status panel. Mirrors app/src/components/StatusPanel/statusModel.js:
 * the test order is part of the denominator and never an error; while it is "pending" it counts as one warning.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Progress
{
	const OK          = 'ok';
	const ERROR       = 'error';
	const WARNING     = 'warning';
	const INFO        = 'info';
	const UNEVALUATED = 'unevaluated';

	public static function variant(array $item)
	{
		$extra = isset($item['extraData']) && is_array($item['extraData']) ? $item['extraData'] : array();

		if (!empty($extra['informational'])) {
			return self::INFO;
		}
		if (!isset($item['isValid'])) {
			return self::UNEVALUATED;
		}
		if (!empty($extra['warning'])) {
			return self::WARNING;
		}
		return $item['isValid'] ? self::OK : self::ERROR;
	}

	/**
	 * @param array      $sections   Output of WC_BlueX_Status_Checks::sections().
	 * @param array|null $test_order Facts "test_order". When given, a pending test order adds one warning.
	 * @return array{configured: int, warnings: int, errors: int, progress: int, blocked: bool}
	 */
	public static function summarize(array $sections, $test_order_validated, $test_order = null)
	{
		$counts = array(
			self::OK          => 0,
			self::WARNING     => 0,
			self::ERROR       => 0,
			self::UNEVALUATED => 0,
		);

		foreach ($sections as $items) {
			foreach ($items as $item) {
				$variant = self::variant($item);
				if (self::INFO !== $variant) {
					$counts[$variant]++;
				}
			}
		}

		$evaluated = $counts[self::OK] + $counts[self::WARNING] + $counts[self::ERROR];
		$valid     = $counts[self::OK] + ($test_order_validated ? 1 : 0);

		return array(
			'configured' => $counts[self::OK],
			'warnings'   => $counts[self::WARNING] + (self::is_test_order_pending($test_order, $counts[self::ERROR] > 0) ? 1 : 0),
			'errors'     => $counts[self::ERROR],
			'progress'   => (int) round($valid / ($evaluated + 1) * 100),
			'blocked'    => $counts[self::ERROR] > 0,
		);
	}

	private static function is_test_order_pending($test_order, $blocked)
	{
		return is_array($test_order) && 'pending' === self::test_order_state($test_order, $blocked);
	}

	/**
	 * @param array $test_order Facts "test_order": validation_date, local_sent_at.
	 * @return string validated | sent | disabled | pending
	 */
	public static function test_order_state(array $test_order, $blocked)
	{
		if (!empty($test_order['validation_date'])) {
			return 'validated';
		}
		if (!empty($test_order['local_sent_at'])) {
			return 'sent';
		}
		return $blocked ? 'disabled' : 'pending';
	}
}
