<?php

declare(strict_types=1);

/**
 * Pure status checks for the "Estado de tienda" panel.
 *
 * Every check receives the facts collected by WC_BlueX_Status_Facts and returns an item:
 * { id, isValid (true|false|null), msg, extraData, actions[] }.
 * isValid = null means "not evaluated". Nothing here touches WordPress state.
 *
 * @package WooCommerce_Correios/Status
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Status_Checks
{
	const BLUE_PORTAL_URL = 'https://ecommerce.blue.cl';

	const PRICING_SERVICES = array(
		'EX' => 'Express',
		'PY' => 'Priority',
		'MD' => 'Same Day',
	);

	/**
	 * @param array $facts Output of WC_BlueX_Status_Facts::collect().
	 * @return array{connection: array, storeConfigurations: array, postIntegration: array}
	 */
	public static function sections(array $facts)
	{
		$sections = array(
			'storeConfigurations' => array(
				self::plugin_version($facts),
				self::store_billing_address($facts),
				self::label_data($facts),
			),
			'connection'          => array(
				self::credentials($facts),
				self::api_connection($facts),
			),
			'postIntegration'     => array(
				self::invoice_address($facts),
				self::shipping_zones($facts),
				self::orders_capture($facts),
				self::pricing_blue($facts),
				self::fee_percentage($facts),
			),
		);

		// Optional items return null when there is nothing to show.
		return array_map(function ($items) {
			return array_values(array_filter($items));
		}, $sections);
	}

	public static function credentials(array $facts)
	{
		if (self::unknown($facts, 'credentials')) {
			return self::not_evaluated('credentials', __('No pudimos revisar las credenciales de WooCommerce.', 'woocommerce-correios'));
		}

		$creds   = $facts['credentials'];
		$key_id  = isset($creds['key_id']) ? (int) $creds['key_id'] : 0;
		$actions = array(self::internal(__('Ir a Conexión', 'woocommerce-correios'), 'conexion'));
		$name    = isset($creds['description']) ? trim((string) $creds['description']) : '';
		$extra   = '' !== $name ? array('chip' => $name) : array();
		$keys    = self::url($facts, 'api_keys');
		$open    = '' !== $keys ? array(self::external(__('Ver credenciales', 'woocommerce-correios'), !empty($creds['found']) && $key_id > 0 ? $keys . '&edit-key=' . $key_id : $keys)) : array();

		if ($key_id <= 0) {
			return self::item('credentials', null, __('Credenciales ingresadas manualmente: no podemos comprobar sus permisos desde WooCommerce.', 'woocommerce-correios'), array('unevaluated' => true), $open);
		}
		if (empty($creds['found'])) {
			return self::item('credentials', false, __('La llave de API de WooCommerce que usa la integración ya no existe. Vuelve a integrar tu tienda.', 'woocommerce-correios'), array(), array_merge($actions, $open));
		}
		if ('read_write' !== (isset($creds['permissions']) ? $creds['permissions'] : '')) {
			return self::item('credentials', false, __('La llave de API no tiene permisos de lectura y escritura. Vuelve a integrar tu tienda.', 'woocommerce-correios'), $extra, array_merge($actions, $open));
		}

		return self::item('credentials', true, __('Llave de API con permisos de lectura y escritura.', 'woocommerce-correios'), $extra, $open);
	}

	public static function api_connection(array $facts)
	{
		$blue = self::blue($facts);

		if (empty($blue['ok'])) {
			return self::item('apiConnection', null, __('No pudimos consultar Blue Express. Intenta actualizar en unos minutos.', 'woocommerce-correios'), array('unevaluated' => true));
		}
		if (!empty($blue['active'])) {
			return self::item('apiConnection', true, __('Integración activa con tu cuenta Blue Express.', 'woocommerce-correios'));
		}

		$message = !empty($blue['message']) ? (string) $blue['message'] : __('La integración no está activa en Blue Express.', 'woocommerce-correios');
		return self::item('apiConnection', false, $message, array(), array(self::internal(__('Ir a Conexión', 'woocommerce-correios'), 'conexion')));
	}

	public static function store_billing_address(array $facts)
	{
		if (self::unknown($facts, 'wc_address')) {
			return self::not_evaluated('storeBillingAddress', __('No pudimos revisar la dirección de tu tienda en WooCommerce.', 'woocommerce-correios'));
		}

		$address = $facts['wc_address'];
		$general = self::url($facts, 'general');
		$open    = '' !== $general ? array(self::external(__('Ir a configuración', 'woocommerce-correios'), $general)) : array();
		if (empty($address['address']) || empty($address['city'])) {
			return self::item(
				'storeBillingAddress',
				false,
				__('Tu tienda no tiene dirección y ciudad configuradas en WooCommerce. Complétalas en Ajustes > General.', 'woocommerce-correios'),
				array(),
				$open
			);
		}

		return self::item('storeBillingAddress', true, __('Dirección de la tienda configurada en WooCommerce.', 'woocommerce-correios'), array('value' => (string) $address['text']), $open);
	}

	public static function plugin_version(array $facts)
	{
		$version = isset($facts['plugin_version']) ? (string) $facts['plugin_version'] : '';
		return self::item(
			'pluginVersion',
			true,
			__('Versión activa instalada en tu tienda.', 'woocommerce-correios'),
			array('informational' => true, 'chip' => '' !== $version ? 'v' . $version : '')
		);
	}

	/**
	 * The address the seller configured in Blue Express, read from the root of its integration-status response.
	 * It is edited in the Blue portal, so the only action is a link there.
	 */
	public static function invoice_address(array $facts)
	{
		$blue = self::blue($facts);
		if (empty($blue['ok'])) {
			return self::not_evaluated('invoiceAddress', __('No pudimos consultar la dirección de tu cuenta en Blue Express. Intenta actualizar en unos minutos.', 'woocommerce-correios'));
		}

		$address = self::store_address(isset($blue['data']) && is_array($blue['data']) ? $blue['data'] : array());
		$extra   = '' !== $address['text'] ? array('value' => $address['text']) : array();
		$open    = array(self::external(__('Ir a Blue Express', 'woocommerce-correios'), self::BLUE_PORTAL_URL));

		if ('' === $address['region'] || '' === $address['commune']) {
			return self::item(
				'invoiceAddress',
				true,
				__('No pudimos identificar la región y comuna de tu dirección en Blue Express. Revísala en tu cuenta de Blue Express.', 'woocommerce-correios'),
				array_merge($extra, array('warning' => true)),
				$open
			);
		}

		return self::item('invoiceAddress', true, __('Dirección configurada en Blue Express.', 'woocommerce-correios'), $extra, $open);
	}

	public static function shipping_zones(array $facts)
	{
		if (self::unknown($facts, 'zones')) {
			return self::not_evaluated('shippingZones', __('No pudimos revisar las zonas de envío de tu tienda.', 'woocommerce-correios'));
		}

		$names = array();
		foreach (self::zones($facts) as $zone) {
			if (!empty($zone['covers_chile']) && !empty($zone['bluex'])) {
				$names[] = (string) $zone['name'];
			}
		}

		if (empty($names)) {
			return self::item(
				'shippingZones',
				false,
				__('No encontramos zonas de envío en Chile con métodos Blue Express. Créalas desde Zonas de Envío.', 'woocommerce-correios'),
				array(),
				array(self::internal(__('Ir a configuración', 'woocommerce-correios'), 'zonas-envio'))
			);
		}

		$count = count($names);
		$note  = 1 === $count ? __('1 zona configurada', 'woocommerce-correios') : sprintf(__('%d zonas configuradas', 'woocommerce-correios'), $count);
		return self::item('shippingZones', true, '', array('value' => implode(', ', $names), 'note' => $note, 'noteTarget' => 'zonas-envio', 'count' => $count));
	}

	public static function label_data(array $facts)
	{
		$intro    = __('Para emitir etiquetas con Blue Express necesitamos el teléfono, el correo electrónico y la dirección del destinatario. Revisamos que tu checkout de WooCommerce exija estos campos como obligatorios.', 'woocommerce-correios');
		$checkout = self::url($facts, 'checkout');
		$fix      = '' !== $checkout ? array(self::external(__('Ir a configuración', 'woocommerce-correios'), $checkout)) : array();

		// Classic or Blocks, when the fields cannot be read at all we say so instead of guessing.
		if (self::unknown($facts, 'checkout_fields') || !isset($facts['checkout_fields']['required']) || !is_array($facts['checkout_fields']['required'])) {
			return self::item(
				'labelData',
				true,
				$intro,
				array(
					'warning' => true,
					'detail'  => __('Verificación limitada en tu checkout, es posible que una personalización, tema o plugin adicional en tu checkout esté limitando la verificación de campos que requerimos como obligatorios.', 'woocommerce-correios'),
				),
				$fix
			);
		}

		$required = $facts['checkout_fields']['required'];
		$labels   = array(
			'phone'   => __('teléfono', 'woocommerce-correios'),
			'email'   => __('correo electrónico', 'woocommerce-correios'),
			'address' => __('dirección', 'woocommerce-correios'),
		);

		$missing = array();
		foreach ($labels as $code => $label) {
			if (empty($required[$code])) {
				$missing[] = '**' . $label . '**';
			}
		}

		if (empty($missing)) {
			return self::item('labelData', true, $intro, array('detail' => __('Tu checkout exige teléfono, correo electrónico y dirección.', 'woocommerce-correios')));
		}

		return self::item(
			'labelData',
			true,
			$intro,
			array(
				'warning' => true,
				'detail'  => sprintf(
					__('Campos faltantes obligatorios: %s.', 'woocommerce-correios'),
					implode(', ', $missing)
				),
			),
			$fix
		);
	}

	public static function orders_capture(array $facts)
	{
		if (self::unknown($facts, 'capture')) {
			return self::not_evaluated('ordersCapture', __('No pudimos revisar la captura de órdenes.', 'woocommerce-correios'));
		}

		$capture = $facts['capture'];
		$actions = array(self::internal(__('Ir a configuración', 'woocommerce-correios'), 'configuracion'));
		$status  = isset($capture['status']) ? (string) $capture['status'] : '';

		if ('' === $status) {
			return self::item('ordersCapture', false, __('No definiste el estado de pedido que dispara el envío a Blue Express.', 'woocommerce-correios'), array(), $actions);
		}
		if (empty($capture['status_exists'])) {
			return self::item('ordersCapture', false, sprintf(__('El estado "%s" ya no existe en WooCommerce. Elige otro en Configuración.', 'woocommerce-correios'), $status), array(), $actions);
		}
		if (empty($capture['hook_active'])) {
			return self::item('ordersCapture', false, __('La captura de órdenes no está activa en tu tienda. Revisa que el plugin esté habilitado y vuelve a cargar la página.', 'woocommerce-correios'), array(), $actions);
		}

		$last = isset($capture['last_sync']) && is_array($capture['last_sync']) ? $capture['last_sync'] : null;
		if (null !== $last && array_key_exists('ok', $last) && false === $last['ok']) {
			$error = !empty($last['error']) ? ': ' . $last['error'] : '.';
			return self::item('ordersCapture', false, sprintf(__('El último envío de una orden a Blue Express falló%s', 'woocommerce-correios'), $error), array(), $actions);
		}

		$label = !empty($capture['status_label']) ? (string) $capture['status_label'] : $status;
		$msg   = sprintf(__('Estado para emisión: %s.', 'woocommerce-correios'), $label);
		if (null !== $last && !empty($last['ago'])) {
			$msg .= ' ' . sprintf(__('Último envío correcto hace %s.', 'woocommerce-correios'), $last['ago']);
		}
		return self::item('ordersCapture', true, $msg, array(), $actions);
	}

	public static function pricing_blue(array $facts)
	{
		$pricing = isset($facts['pricing']) && is_array($facts['pricing']) ? $facts['pricing'] : null;
		if (null === $pricing) {
			return self::item('pricingBlue', null, __('No pudimos cotizar tarifas con Blue Express. Intenta actualizar en unos minutos.', 'woocommerce-correios'), array('unevaluated' => true));
		}

		$ok     = array();
		$failed = array();
		foreach (self::PRICING_SERVICES as $code => $label) {
			if (!empty($pricing[$code])) {
				$ok[] = $label;
			} else {
				$failed[] = $label;
			}
		}

		if (empty($failed)) {
			return self::item('pricingBlue', true, sprintf(__('Tarifas Blue Express disponibles para: %s.', 'woocommerce-correios'), implode(', ', $ok)));
		}

		return self::item(
			'pricingBlue',
			false,
			sprintf(__('No pudimos obtener tarifas Blue Express para: %s. Es una consulta al servicio de tarifas de Blue Express: actualiza en unos minutos y, si el problema continúa, comunícate con el soporte de Blue Express.', 'woocommerce-correios'), implode(', ', $failed)),
			array('failed' => $failed)
		);
	}

	public static function fee_percentage(array $facts)
	{
		if (self::unknown($facts, 'zones')) {
			return self::not_evaluated('feePercentage', __('No pudimos revisar el cargo por servicio de tus zonas de envío.', 'woocommerce-correios'));
		}

		$shipping   = self::url($facts, 'shipping');
		$open       = '' !== $shipping ? array(self::external(__('Ir a configuración', 'woocommerce-correios'), $shipping)) : array();
		$with_bluex = array();
		$bad        = array();
		foreach (self::zones($facts) as $zone) {
			if (empty($zone['bluex'])) {
				continue;
			}
			$with_bluex[] = $zone;
			foreach ($zone['bluex'] as $method) {
				if (!self::fee_is_vat(isset($method['fee']) ? $method['fee'] : '')) {
					$bad[] = $zone;
					break;
				}
			}
		}

		if (empty($with_bluex)) {
			return self::item('feePercentage', null, __('Aún no hay zonas con métodos Blue Express para revisar el cargo por servicio.', 'woocommerce-correios'), array('unevaluated' => true), $open);
		}

		if (empty($bad)) {
			return self::item('feePercentage', true, __('Cargo por servicio al 19% en todos los métodos Blue Express.', 'woocommerce-correios'), array(), $open);
		}

		$names = array();
		foreach ($bad as $zone) {
			$names[] = (string) $zone['name'];
		}

		return self::item(
			'feePercentage',
			true,
			__('¡Atención! Se identificó que uno o más métodos de envío no tienen configurado el cargo por servicio al 19%. Configura ese valor en las zonas de envío para asegurar el cálculo correcto.', 'woocommerce-correios'),
			array('warning' => true, 'zones' => $names),
			array(self::external(__('Ir a configuración', 'woocommerce-correios'), self::zone_url($facts, (int) $bad[0]['id'])))
		);
	}

	/**
	 * "19%" with optional spaces or a decimal comma. A bare "19" is a fixed amount, not VAT.
	 */
	public static function fee_is_vat($fee)
	{
		return 1 === preg_match('/^\s*19([.,]0+)?\s*%\s*$/', (string) $fee);
	}

	/**
	 * Reads the store address Blue returns. Accepts a nested "storeAddress" or the same fields at the top level.
	 *
	 * @return array{region: string, commune: string, text: string}
	 */
	public static function store_address(array $data)
	{
		$src = isset($data['storeAddress']) && is_array($data['storeAddress']) ? $data['storeAddress'] : $data;

		$region  = self::first_text($src, array('regionName', 'region', 'provinceCode'));
		$commune = self::first_text($src, array('communeName', 'commune', 'location'));

		$parts = array();
		$candidates = array(
			self::first_text($src, array('address', 'street', 'addressLine')),
			self::first_text($src, array('communeName')),
			self::first_text($src, array('regionName')),
		);
		foreach ($candidates as $part) {
			if ('' !== $part) {
				$parts[] = $part;
			}
		}

		return array('region' => $region, 'commune' => $commune, 'text' => implode(', ', $parts));
	}

	private static function first_text(array $src, array $keys)
	{
		foreach ($keys as $key) {
			if (isset($src[$key]) && (is_string($src[$key]) || is_int($src[$key])) && '' !== trim((string) $src[$key])) {
				return trim((string) $src[$key]);
			}
		}
		return '';
	}

	private static function unknown(array $facts, $key)
	{
		return !isset($facts[$key]) || !is_array($facts[$key]);
	}

	private static function not_evaluated($id, $msg)
	{
		return self::item($id, null, $msg, array('unevaluated' => true));
	}

	private static function blue(array $facts)
	{
		return isset($facts['blue']) && is_array($facts['blue']) ? $facts['blue'] : array();
	}

	private static function zones(array $facts)
	{
		return isset($facts['zones']) && is_array($facts['zones']) ? $facts['zones'] : array();
	}

	private static function url(array $facts, $key)
	{
		return isset($facts['urls'][$key]) ? (string) $facts['urls'][$key] : '';
	}

	private static function zone_url(array $facts, $zone_id)
	{
		return self::url($facts, 'zone_base') . $zone_id;
	}

	private static function item($id, $is_valid, $msg, array $extra = array(), array $actions = array())
	{
		return array(
			'id'        => $id,
			'isValid'   => $is_valid,
			'msg'       => (string) $msg,
			'extraData' => $extra,
			'actions'   => $actions,
		);
	}

	private static function internal($label, $target)
	{
		return array('label' => $label, 'type' => 'internal', 'target' => $target);
	}

	private static function external($label, $url)
	{
		return array('label' => $label, 'type' => 'external', 'target' => $url);
	}
}
