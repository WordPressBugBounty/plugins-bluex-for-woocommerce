<?php

declare(strict_types=1);

/**
 * Builds the payload of GET /wc-bluex/v1/integration-status from the collected facts.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Report
{
	/**
	 * @param array $facts Output of WC_BlueX_Status_Facts::collect().
	 * @return array
	 */
	public static function build(array $facts)
	{
		$sections   = WC_BlueX_Status_Checks::sections($facts);
		$test_order = isset($facts['test_order']) && is_array($facts['test_order']) ? $facts['test_order'] : array();
		$summary    = WC_BlueX_Status_Progress::summarize($sections, !empty($test_order['validation_date']), $test_order);
		$now        = isset($facts['now']) ? (int) $facts['now'] : time();

		return array(
			'integrationProgress' => $summary['progress'],
			'summary'             => array(
				'configured' => $summary['configured'],
				'warnings'   => $summary['warnings'],
				'errors'     => $summary['errors'],
			),
			'sections'            => array_map(array(__CLASS__, 'serialize_items'), $sections),
			'testOrder'           => array(
				'state'          => WC_BlueX_Status_Progress::test_order_state($test_order, $summary['blocked']),
				'validationDate' => self::text_or_null($test_order, 'validation_date'),
				'orderId'        => self::text_or_null($test_order, 'order_id'),
				'orderIdShort'   => self::first_text($test_order, array('order_id_short', 'local_order_number')),
				'localSentAt'    => !empty($test_order['local_sent_at']) ? gmdate('c', (int) $test_order['local_sent_at']) : null,
				'polling'        => false,
				'ordersUrl'      => isset($facts['urls']['orders']) ? (string) $facts['urls']['orders'] : '',
			),
			'generatedAt'         => gmdate('c', $now),
		);
	}

	/**
	 * An empty extraData must be a JSON object, not [].
	 */
	public static function serialize_items(array $items)
	{
		$out = array();
		foreach ($items as $item) {
			if (empty($item['extraData'])) {
				$item['extraData'] = new stdClass();
			}
			$out[] = $item;
		}
		return $out;
	}

	private static function text_or_null(array $source, $key)
	{
		return isset($source[$key]) && '' !== (string) $source[$key] ? (string) $source[$key] : null;
	}

	/**
	 * First non-empty value of the given keys, or null. Blue's short id wins over the local order number.
	 */
	private static function first_text(array $source, array $keys)
	{
		foreach ($keys as $key) {
			$value = self::text_or_null($source, $key);
			if (null !== $value) {
				return $value;
			}
		}
		return null;
	}
}
