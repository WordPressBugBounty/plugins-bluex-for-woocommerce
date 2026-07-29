<?php
/**
 * BlueX PUDO Shipping Method.
 *
 * Registers `bluex-pudo` as a native WooCommerce shipping method. Before a
 * pickup point is selected it emits a lightweight wrapper rate so the widget
 * can be shown. Once the session has an agency id, it quotes the selected
 * pickup point through the BlueX webservice using familiaProducto=PUDO.
 *
 * Dynamic label: if the session has `bluex_agency_name` set (persisted by the
 * blocks integration's Store API update_callback when the customer picks a
 * point in the iframe), the rate label renders as "Retiro en <agency_name>"
 * and the quoted delivery promise is exposed as rate metadata. Otherwise it
 * renders the configured title. This is read on every calculate_shipping so
 * label flips naturally as the cart refreshes after point selection.
 *
 * @package WooCommerce_Correios/Classes/Shipping
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_BlueX_Pudo extends WC_Shipping_Method
{
	// Explicit declarations for PHP 8.2+ — $enabled / $title / $tax_status are
	// declared on WC_Shipping_Method core but $cost is not.
	/** @var string */
	protected $cost = '0';

	// Fee/IVA por defecto aplicado al costo cotizado de PUDO.
	const DEFAULT_FEE = '19%';

	/** @var string */
	public $fee = self::DEFAULT_FEE;

	public function __construct($instance_id = 0)
	{
		$this->id                 = 'bluex-pudo';
		$this->instance_id        = absint($instance_id);
		$this->method_title       = __('Retiro en Punto Blue Express', 'woocommerce-correios');
		$this->method_description = __('Permite al cliente elegir un Punto Blue Express de Blue Express como destino de retiro.', 'woocommerce-correios');
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled    = $this->get_option('enabled');
		$this->title      = $this->get_option('title');
		// Fijo en 'none': el fee/IVA se aplica manualmente sobre el costo, así que
		// no delegamos el impuesto a WooCommerce. Además evita que una instancia
		// vieja con 'taxable' guardado lo siga heredando.
		$this->tax_status = 'none';
		$this->cost       = $this->get_option('cost');
		$this->fee        = $this->get_option('fee');

		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			[$this, 'process_admin_options']
		);
	}

	public function init_form_fields()
	{
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => __('Habilitar/Deshabilitar', 'woocommerce-correios'),
				'type'    => 'checkbox',
				'label'   => __('Habilitar este método de envío', 'woocommerce-correios'),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __('Título', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('Nombre del método mostrado al cliente cuando no hay un punto elegido.', 'woocommerce-correios'),
				'default'     => __('Retiro en Punto Blue Express', 'woocommerce-correios'),
				'desc_tip'    => true,
			],
			'cost' => [
				'title'       => __('Costo base (fallback antes de cotizar PUDO)', 'woocommerce-correios'),
				'type'        => 'price',
				'description' => __('Se usa antes de seleccionar un punto o si la cotización PUDO no responde. Cuando hay punto seleccionado, el costo final viene de la tarifa PUDO del backend.', 'woocommerce-correios'),
				'default'     => '0',
				'desc_tip'    => true,
			],
			'fee' => [
				// type 'fee' (custom): render con generate_fee_html (input numérico
				// solo enteros + '%' visible) y saneo con validate_fee_field, que
				// SIEMPRE normaliza a "<int>%". Así, aunque el usuario escriba "19"
				// sin '%', se guarda "19%" y get_fee lo aplica como porcentaje (IVA),
				// nunca como monto fijo. (No usar 'price': wc_format_decimal borra el
				// '%'; no usar 'text': permite entradas ambiguas como "19").
				'title'       => __('IVA / Recargo (%)', 'woocommerce-correios'),
				'type'        => 'fee',
				'description' => __('Ingresa solo el número entero del IVA (ej. 19). El % se aplica automáticamente sobre el costo cotizado.', 'woocommerce-correios'),
				'desc_tip'    => true,
				// default vacío a propósito: el 19% se persiste solo en instancias
				// NUEVAS vía maybe_set_default_fee(). Un default '19%' aquí haría que
				// WooCommerce lo aplique por fallback a instancias viejas sin fee
				// guardado (backfill no deseado).
				'default'     => '',
				'placeholder' => '19',
			],
		];
	}

	/**
	 * Render the `fee` field: integer-only number input with a visible '%' suffix.
	 *
	 * Called by WooCommerce for fields of `type => 'fee'`. The canonical stored
	 * value is "<int>%"; here we strip the '%' so the numeric input shows only the
	 * integer, and render a '%' next to it. On save, validate_fee_field re-appends
	 * the '%', so the user can only enter the number and the percentage is implicit.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_fee_html($key, $data)
	{
		$field_key = $this->get_field_key($key);
		$defaults  = [
			'title'             => '',
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => [],
		];
		$data = wp_parse_args($data, $defaults);

		// Mostrar solo la parte entera del valor guardado ("19%" -> "19"). Valor
		// vacío queda vacío para que se vea el placeholder.
		$stored = (string) $this->get_option($key);
		$value  = $stored === '' ? '' : (string) intval($stored);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
					<input
						class="input-text <?php echo esc_attr($data['class']); ?>"
						type="number"
						min="0"
						max="100"
						step="1"
						inputmode="numeric"
						name="<?php echo esc_attr($field_key); ?>"
						id="<?php echo esc_attr($field_key); ?>"
						style="width:5em;<?php echo esc_attr($data['css']); ?>"
						value="<?php echo esc_attr($value); ?>"
						placeholder="<?php echo esc_attr($data['placeholder']); ?>"
						<?php echo $this->get_custom_attribute_html($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					/> <span class="description">%</span>
					<?php echo $this->get_description_html($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Sanitize the `fee` field on save: always store "<int>%".
	 *
	 * Guarantees the fee is treated as a percentage (IVA). Any non-digit input is
	 * dropped, so "19", "19%" or "19 %" all persist as "19%". Empty stays empty
	 * (no fee). This removes the ambiguity where a bare "19" would be applied by
	 * get_fee() as a fixed amount instead of a percentage.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_fee_field($key, $value)
	{
		$value = is_null($value) ? '' : trim(wp_unslash($value));
		if ($value === '') {
			return '';
		}

		// intval toma el entero inicial: "19" -> 19, "19%" -> 19, "19.5" -> 19,
		// "19,5" -> 19, "abc" -> 0. NO usar preg_replace de no-dígitos: concatenaría
		// "19.5" en "195" (sobrecobro). <= 0 (o no numérico) => sin fee.
		$int = intval($value);
		if ($int <= 0) {
			return '';
		}

		return $int . '%';
	}

	/**
	 * Calculate the rate for this package.
	 *
	 * When an agency is selected, this method quotes the backend as PUDO and emits
	 * the returned tariff and delivery promise. Without an agency, or if the quote
	 * fails, it keeps the historical wrapper-rate fallback; the package-rates
	 * filter may then mirror the bluex-ex cost to preserve the previous UX.
	 *
	 * Respects the plugin-wide `pudoEnable` master switch.
	 *
	 * @param array $package
	 */
	public function calculate_shipping($package = [])
	{
		if (isset($package['destination']['country']) && $package['destination']['country'] !== 'CL') {
			return;
		}

		$config = get_option('woocommerce_correios-integration_settings', []);
		if (!isset($config['pudoEnable']) || $config['pudoEnable'] !== 'yes') {
			return;
		}

		$agency_name    = WC()->session ? (string) WC()->session->get('bluex_agency_name', '') : '';
		$agency_address = WC()->session ? (string) WC()->session->get('bluex_agency_address', '') : '';
		$agency_id      = WC()->session ? (string) WC()->session->get('bluex_agency_id', '') : '';

		$meta_data = [
			'_is_pudo'              => 'yes',
			'_bluex_agency_id'      => $agency_id,
			'_bluex_agency_name'    => $agency_name,
			'_bluex_agency_address' => $agency_address,
		];

		$cost = $this->cost;
		if ($agency_id !== '') {
			$quoted_shipping = $this->get_quoted_pudo_shipping($package);
			if ($quoted_shipping) {
				$cost = $this->normalize_quoted_cost($quoted_shipping->Valor);
				$meta_data['_bluex_pudo_quoted'] = 'yes';
				$meta_data['_delivery_forecast'] = (string) $quoted_shipping->PrazoEntrega;
			}
		}

		// Label: include the agency name as a suffix when available so the
		// rate's own label carries the selected pickup point everywhere it's
		// rendered (list, order summary sidebar, emails, admin). A Store API
		// response filter would only cover blocks render; setting it here on
		// the rate object makes every consumer see the same label.
		//
		// Cost is the quoted PUDO tariff when available. Otherwise it remains the
		// admin-configured fallback and can still be normalized from bluex-ex by the
		// package-rates filter for backward compatibility.
		$label = $agency_name !== ''
			? $this->title . ' - ' . $agency_name
			: $this->title;

		// Aplicar fee/IVA sobre el costo. Cast a float ANTES de get_fee: un costo
		// vacío ('') rompería el cálculo de porcentaje de get_fee (''/100 es fatal
		// en PHP 8). En PUDO cotizado usa su propio costo+fee; en fallback no-cotizado
		// el filtro package-rates espeja EX (que ya trae su fee), así que este fee
		// queda pisado y no hay doble cobro.
		$cost = (float) $cost;
		$fee  = (float) $this->get_fee($this->fee, $cost);
		$cost = $cost + $fee;

		$this->add_rate([
			'id'        => $this->get_rate_id(),
			'label'     => $label,
			'cost'      => $cost,
			'meta_data' => $meta_data,
		]);
	}

	/**
	 * Quote the selected pickup point through the same webservice used by BlueX.
	 *
	 * @param array $package Shipping package.
	 * @return object|null
	 */
	private function get_quoted_pudo_shipping($package)
	{
		if (!class_exists('WC_Correios_Webservice')) {
			return null;
		}

		$api = new WC_Correios_Webservice($this->id, $this->instance_id);
		$api->set_service('EX');
		$api->set_package($package);

		if (isset($package['destination']['postcode'])) {
			$api->set_destination_postcode($package['destination']['postcode']);
		}

		if (isset($package['contents_cost'])) {
			$api->set_declared_value(18 >= (float) $package['contents_cost'] ? 0 : $package['contents_cost']);
		}

		$shipping = $api->get_shipping();
		if (!is_object($shipping) || !isset($shipping->Erro, $shipping->Valor, $shipping->PrazoEntrega)) {
			return null;
		}

		$error_number = (string) $shipping->Erro;
		if ($error_number !== '0' && $error_number !== '') {
			return null;
		}

		$cost = $this->normalize_quoted_cost($shipping->Valor);
		if (empty($shipping->isShipmentFree) && 0 === intval($cost)) {
			return null;
		}

		return $shipping;
	}

	/**
	 * Normalize BlueX pricing response value into a WooCommerce rate cost.
	 *
	 * @param mixed $value Raw pricing response value.
	 * @return float
	 */
	private function normalize_quoted_cost($value)
	{
		if (function_exists('wc_correios_normalizePrice')) {
			return (float) wc_correios_normalizePrice(esc_attr((string) $value));
		}

		return (float) str_replace(',', '.', (string) $value);
	}

	/**
	 * Rewrite a single shipping order item from bluex-pudo to bluex-ex.
	 *
	 * The BlueX backend contract keys pickup orders by the presence of the
	 * `agencyId` meta + `shipping_method=bluex-ex`, NOT by a bluex-pudo
	 * method id. This normalizes the saved item so both checkout flows
	 * produce the same shape. Idempotent — no-op on non-pudo items.
	 *
	 * Does NOT call $item->save(). Callers that mutate an item ALREADY
	 * attached to an order should call save() themselves; the hook
	 * `woocommerce_checkout_create_order_shipping_item` fires BEFORE the
	 * item is attached (verified against WC source class-wc-checkout.php
	 * create_order_shipping_lines), so saving there is redundant —
	 * WC's own persist cycle picks it up.
	 *
	 * @param WC_Order_Item_Shipping $item
	 */
	public static function rewrite_shipping_item_to_bluex_ex($item)
	{
		if (!$item instanceof WC_Order_Item_Shipping) {
			return;
		}
		if ($item->get_method_id() !== 'bluex-pudo') {
			return;
		}

		// Preserve the current label (PUDO agency-dynamic or generic title).
		// Instance id kept blank so the order carries a neutral bluex-ex
		// attribution; the agencyId meta is what the backend actually keys on.
		$item->set_method_id('bluex-ex');
		$item->add_meta_data('_bluex_original_method', 'bluex-pudo', true);
	}

	/**
	 * Rewrite every bluex-pudo shipping item on an order to bluex-ex.
	 *
	 * Calls save() on each modified item because this path operates on an
	 * order that is already in the DB lifecycle (Blocks update_order_from_
	 * store_api_request, or any after-create context). Idempotent — skips
	 * items that are already bluex-ex.
	 *
	 * @param WC_Order $order
	 */
	public static function rewrite_shipping_method_to_bluex_ex($order)
	{
		foreach ($order->get_items('shipping') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Shipping) {
				continue;
			}
			if ($item->get_method_id() !== 'bluex-pudo') {
				continue;
			}
			self::rewrite_shipping_item_to_bluex_ex($item);
			$item->save();
		}
	}

	/**
	 * Persist the default 19% fee (IVA) on a newly created bluex-pudo instance.
	 *
	 * Hooked on `woocommerce_shipping_zone_method_added`, which fires from
	 * WC_Shipping_Zone::add_shipping_method() — the single creation point used
	 * by every path (manual admin add, REST API, plugin automation, migrator).
	 *
	 * We set the default HERE (at creation) instead of via the form-field
	 * `default` so that ONLY new instances get 19%: pre-existing PUDO instances
	 * (created before this feature, with no saved `fee`) keep charging no IVA
	 * until edited — honoring the "solo nuevas creaciones, sin backfill" decision.
	 *
	 * Idempotent: skips if a non-empty `fee` is already stored (e.g. one the
	 * zone automation wrote), so it never overrides an explicit value.
	 *
	 * @param int    $instance_id New shipping method instance id.
	 * @param string $type        Shipping method id (e.g. 'bluex-pudo').
	 * @param int    $zone_id     Shipping zone id.
	 */
	public static function maybe_set_default_fee($instance_id, $type, $zone_id)
	{
		if ($type !== 'bluex-pudo') {
			return;
		}

		$option_key = 'woocommerce_bluex-pudo_' . absint($instance_id) . '_settings';
		$settings   = get_option($option_key, []);
		if (!is_array($settings)) {
			$settings = [];
		}

		// Do not override an explicit fee already persisted for this instance.
		if (isset($settings['fee']) && $settings['fee'] !== '') {
			return;
		}

		$settings['fee'] = self::DEFAULT_FEE;
		update_option($option_key, $settings);
	}
}
