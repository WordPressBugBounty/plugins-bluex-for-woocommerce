<?php
/**
 * BlueX PUDO Shipping Method.
 *
 * Registers `bluex-pudo` as a native WooCommerce shipping method. The method
 * itself is intentionally lightweight: fixed cost per instance, no webservice
 * call (PUDO is a pickup, not a courier service — the real SLA/address comes
 * from the widget + Store API extension once the customer picks a point).
 *
 * Dynamic label: if the session has `bluex_agency_name` set (persisted by the
 * blocks integration's Store API update_callback when the customer picks a
 * point in the iframe), the rate label renders as "Retiro en <agency_name>"
 * with the agency address as the delivery forecast line. Otherwise it renders
 * the configured title/forecast. This is read on every calculate_shipping so
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

	public function __construct($instance_id = 0)
	{
		$this->id                 = 'bluex-pudo';
		$this->instance_id        = absint($instance_id);
		$this->method_title       = __('Retiro en Punto Blue Express', 'woocommerce-correios');
		$this->method_description = __('Permite al cliente elegir un punto PUDO de Blue Express como destino de retiro.', 'woocommerce-correios');
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled    = $this->get_option('enabled');
		$this->title      = $this->get_option('title');
		$this->tax_status = $this->get_option('tax_status');
		$this->cost       = $this->get_option('cost');

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
			'tax_status' => [
				'title'   => __('Estado de impuestos', 'woocommerce-correios'),
				'type'    => 'select',
				'default' => 'none',
				'options' => [
					'taxable' => __('Imponible', 'woocommerce-correios'),
					'none'    => __('Ninguno', 'woocommerce-correios'),
				],
			],
			'cost' => [
				'title'       => __('Costo base (se sobreescribe con el costo de Express)', 'woocommerce-correios'),
				'type'        => 'price',
				'description' => __('Este valor se ignora en tiempo de cálculo: el costo final de PUDO siempre copia el del rate Blue Express Express. Queda aquí solo por compatibilidad.', 'woocommerce-correios'),
				'default'     => '0',
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * Calculate the rate for this package.
	 *
	 * IMPORTANT: the cost emitted here is a placeholder. The final cost is
	 * resolved by the `woocommerce_package_rates` filter in class-wc-correios.php,
	 * which copies the cost from the `bluex-ex` rate in the same package so the
	 * PUDO rate's price mirrors the Express service exactly. This is the UX
	 * expectation (no price jump at place-order) AND the backend expectation
	 * (the order is rewritten to method_id=bluex-ex on create — see blocks
	 * integration — so the cost must match upstream).
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

		// Label: include the agency name as a suffix when available so the
		// rate's own label carries the selected pickup point everywhere it's
		// rendered (list, order summary sidebar, emails, admin). A Store API
		// response filter would only cover blocks render; setting it here on
		// the rate object makes every consumer see the same label.
		//
		// Cost is the admin-configured base (typically 0). The package-rates
		// filter in class-wc-correios.php will overwrite it with the bluex-ex
		// cost before rendering.
		$label = $agency_name !== ''
			? $this->title . ' - ' . $agency_name
			: $this->title;

		$this->add_rate([
			'id'        => $this->get_rate_id(),
			'label'     => $label,
			'cost'      => $this->cost,
			'meta_data' => $meta_data,
		]);
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
}
