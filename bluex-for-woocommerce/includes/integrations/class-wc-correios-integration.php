<?php

/**
 * Correios integration.
 *
 * @package WooCommerce_Correios/Classes/Integration
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Correios integration class.
 */
class WC_Correios_Integration extends WC_Integration
{

	/**
	 * Initialize integration actions.
	 */
	public function __construct()
	{
		$this->id           = 'correios-integration';
		$this->method_title = __('Blue Express', 'woocommerce-correios');
		$this->method_description = __('Página de configuración para integrar tu tienda WooCommerce con <a href="https://ecommerce.blue.cl/" target="_blank">Blue Express</a>.', 'woocommerce-correios');
		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		/** Integration settings */
		$this->tracking_bxkey          = $this->get_tracking_bxkey();
		$this->noBlueStatus          	= $this->get_option('noBlueStatus');
		$this->noBlueOnCreate          	= $this->get_option('noBlueOnCreate');
		$this->districtCode          	= $this->get_option('districtCode');
		$this->googleKey        = $this->get_option('googleKey');
		$this->pudoEnable          = $this->get_option('pudoEnable');
		$this->devOptions          = $this->get_option('devOptions');
		$this->alternativeBasePath        = $this->get_option('alternativeBasePath');
		$this->districtsEnable          = $this->get_option('districtsEnable');
		$this->account_name          = $this->get_account_name();
		/** End Integration settings */

		$this->tracking_enable         = $this->get_option('tracking_enable');
		$this->tracking_debug          = $this->get_option('tracking_debug');
		$this->autofill_enable         = $this->get_option('autofill_enable');
		$this->autofill_validity       = $this->get_option('autofill_validity');
		$this->autofill_force          = $this->get_option('autofill_force');
		$this->autofill_empty_database = $this->get_option('autofill_empty_database');
		$this->autofill_debug          = $this->get_option('autofill_debug');


		// Actions.
		add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
		add_action('wp_ajax_test_correios_integration', array($this, 'test_correios_integration'));

		// Tracking history actions.
		add_filter('woocommerce_correios_enable_tracking_history', array($this, 'setup_tracking_history'), 10);
		add_filter('woocommerce_correios_enable_tracking_debug', array($this, 'setup_tracking_debug'), 10);

		// Autofill address actions.
		add_filter('woocommerce_correios_enable_autofill_addresses', array($this, 'setup_autofill_addresses'), 10);
		add_filter('woocommerce_correios_enable_autofill_addresses_debug', array($this, 'setup_autofill_addressesDebug'), 10);
		add_filter('woocommerce_correios_autofill_addresses_validity_time', array($this, 'setup_autofill_addressesValidityTime'), 10);
		add_filter('woocommerce_correios_autofill_addresses_force_autofill', array($this, 'setup_autofill_addressesForceAutofill'), 10);
		add_action('wp_ajax_correios_autofill_addresses_empty_database', array($this, 'ajax_empty_database'));

		// Register AJAX actions
		add_action('wp_ajax_validate_integration_is_active', array($this, 'ajax_validate_integration_is_active'));
		add_action('wp_ajax_nopriv_validate_integration_is_active', array($this, 'ajax_validate_integration_is_active'));

		add_action('wp_ajax_update_integration_credentials', array($this, 'ajax_update_integration_credentials'));
		add_action('wp_ajax_nopriv_update_integration_credentials', array($this, 'ajax_update_integration_credentials'));

		add_action('wp_ajax_save_integration_settings', array($this, 'ajax_save_integration_settings'));

		add_action('wp_ajax_get_integration_settings', array($this, 'ajax_get_integration_settings'));

		add_action('wp_ajax_save_developer_settings', array($this, 'ajax_save_developer_settings'));
	}

	protected function get_tracking_log_link()
	{
		return ' <a href="' . esc_url(admin_url('admin.php?page=wc-status&tab=logs&log_file=correios-tracking-history-' . sanitize_file_name(wp_hash('correios-tracking-history')) . '.log')) . '">' . __('View logs.', 'woocommerce-correios') . '</a>';
	}

	public function init_form_fields()
	{
		$this->form_fields = array(
			'check_integration' => array(
				'title'       => __('Probar integración', 'woocommerce-correios'),
				'type'        => 'button',
				'description' => __('Haz clic para probar la integración con Blue Express. Ten en cuenta que es necesario guardar los datos requeridos en la integración antes de proceder con la prueba.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'label'       => __('Probar integración', 'woocommerce-correios'),
			),
			'account_name' => array(
				'title'       => __('Nombre de la cuenta (Account name)', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('El nombre de tu cuenta (Account name), que debe coincidir con el configurado en el portal de Blue Express.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'default'     => home_url() . "/",
				'custom_attributes' => array(
					'readonly' => 'readonly',
				),
			),
			'tracking_bxkey' => array(
				'title'       => __('Clave API de Blue Express', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('Tu clave API proporcionada por Blue Express.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'default'     => '',
			),
			'districtCode' => array(
				'title'       => __('Código del distrito de la tienda', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('El código del distrito de tu tienda. Ejemplo: ARI.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'default'     => '',
			),
			'noBlueStatus' => array(
				'title'       => __('Estado de emisión de OS', 'woocommerce-correios'),
				'type'        => 'select',
				'description' => __('Selecciona el estado en el que el pedido será enviado a Blue Express. Ejemplo: Pendiente.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'default'     => 'wc-shipping-progress',
				'options'     => wc_get_order_statuses(),
			),
			'noBlueOnCreate' => array(
				'title'       => __('Enviar pedido al crearlo', 'woocommerce-correios'),
				'type'        => 'checkbox',
				'description' => __('Enviar el pedido a Blue Express al crearlo. Marca la casilla para habilitar.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'label'       => __('Marcar para habilitar', 'woocommerce-correios'),
				'default'     => 'no',
			),
			'pudoEnable' => array(
				'title'       => __('Habilitar funcionalidad de puntos de recogida', 'woocommerce-correios'),
				'type'        => 'checkbox',
				'description' => __('Habilita la funcionalidad de puntos de recogida. Marca la casilla para habilitar.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'label'       => __('Marcar para habilitar', 'woocommerce-correios'),
				'default'     => 'no',
			),
			'googleKey' => array(
				'title'       => __('Clave API de Google', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('Tu clave API personalizada de Google, utilizada en el mapa de puntos de recogida.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'default'     => '',
			),
			'districtsEnable' => array(
				'title'       => __('Habilitar funcionalidad de distritos', 'woocommerce-correios'),
				'type'        => 'checkbox',
				'description' => __('Habilita la funcionalidad de distritos en el checkout para convertir la región y las ciudades en listas desplegables. Marca la casilla para habilitar.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'label'       => __('Marcar para habilitar', 'woocommerce-correios'),
				'default'     => 'no',
			),
		);


		if (defined('DEV_OPTIONS') && DEV_OPTIONS) {
			$this->form_fields['devOptions'] = array(
				'title'       => __('Habilitar opciones de desarrollo', 'woocommerce-correios'),
				'type'        => 'checkbox',
				'description' => __('Habilita las funcionalidades de opciones de desarrollo. Ej: Marcar = Sí', 'woocommerce-correios'),
				'desc_tip'    => true,
				'label'       => 'Marcar para habilitar',
				'default'     => 'no',
			);

			$this->form_fields['alternativeBasePath'] = array(
				'title'       => __('Ruta base alternativa', 'woocommerce-correios'),
				'type'        => 'text',
				'description' => __('Tu ruta base alternativa.', 'woocommerce-correios'),
				'desc_tip'    => true,
				'default'     => 'https://apigw.bluex.cl',
			);
		}
	}

	/**
	 * Correios options page.
	 */
	public function admin_options()
	{
		echo '<div id="integration-react-form"></div>';


		include WC_Correios::get_plugin_path() . 'includes/admin/views/html-admin-help-message.php';

		/* if (class_exists('SoapClient')) {
			echo '<div><input type="hidden" name="section" value="' . esc_attr($this->id) . '" /></div>';
			echo '<table class="form-table">' . $this->generate_settings_html($this->get_form_fields(), false) . '</table>'; // WPCS: XSS ok.
		} else {
			$GLOBALS['hide_save_button'] = true; // Hide save button.
			/* translators: %s: SOAP documentation link */
		/*			echo '<div class="notice notice-error inline"><p>' . sprintf(esc_html__('It\'s required have installed the %s on your server in order to integrate with the services of the Correios!', 'woocommerce-correios'), '<a href="https://secure.php.net/manual/book.soap.php" target="_blank" rel="nofollow noopener noreferrer">' . esc_html__('SOAP module', 'woocommerce-correios') . '</a>') . '</p></div>';
		} */

		$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script($this->id . '-admin', plugins_url('assets/js/admin/integration' . $suffix . '.js', WC_Correios::get_main_file()), array('jquery', 'jquery-blockui'), time(), true);
		wp_localize_script(
			$this->id . '-admin',
			'WCCorreiosIntegrationAdminParams',
			array(
				'i18n_confirm_message' => __('Are you sure you want to delete all postcodes from the database?', 'woocommerce-correios'),
				'empty_database_nonce' => wp_create_nonce('woocommerce_correios_autofill_addresses_nonce'),
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('correios_integration_nonce')
			)
		);
	}

	/**
	 * Generate Button Input HTML.
	 *
	 * @param string $key  Input key.
	 * @param array  $data Input data.
	 * @return string
	 */
	public function generate_button_html($key, $data)
	{
		$field    = $this->plugin_id . $this->id . '_' . $key;
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);

		$data = wp_parse_args($data, $defaults);

		ob_start();
?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($field); ?>"><?php echo wp_kses_post($data['title']); ?></label>
				<?php echo $this->get_tooltip_html($data); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
					<button class="<?php echo esc_attr($data['class']); ?>" type="button" name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>" style="<?php echo esc_attr($data['css']); ?>" <?php echo $this->get_custom_attribute_html($data); ?>><?php echo wp_kses_post($data['title']); ?></button>
					<?php echo $this->get_description_html($data); ?>
					<div id="integration-message" style="display:none; margin-top:10px;"></div>
				</fieldset>
			</td>
		</tr>
<?php
		return ob_get_clean();
	}

	/**
	 * Enable tracking history.
	 *
	 * @return bool
	 */
	public function setup_tracking_history()
	{
		return 'yes' === $this->tracking_enable && class_exists('SoapClient');
	}



	/**
	 * Set up tracking debug.
	 *
	 * @return bool
	 */
	public function setup_tracking_debug()
	{
		return 'yes' === $this->tracking_debug;
	}

	/**
	 * Enable autofill addresses.
	 *
	 * @return bool
	 */
	public function setup_autofill_addresses()
	{
		return 'yes' === $this->autofill_enable && class_exists('SoapClient');
	}

	/**
	 * Set up autofill addresses debug.
	 *
	 * @return bool
	 */
	public function setup_autofill_addressesDebug()
	{
		return 'yes' === $this->autofill_debug;
	}

	/**
	 * Set up autofill addresses validity time.
	 *
	 * @return string
	 */
	public function setup_autofill_addressesValidityTime()
	{
		return $this->autofill_validity;
	}

	/**
	 * Set up autofill addresses force autofill.
	 *
	 * @return string
	 */
	public function setup_autofill_addressesForceAutofill()
	{
		return $this->autofill_force;
	}

	/**
	 * Ajax empty database.
	 */
	public function ajax_empty_database()
	{
		global $wpdb;

		if (!isset($_POST['nonce'])) { // WPCS: input var okay, CSRF ok.
			wp_send_json_error(array('message' => __('Missing parameters!', 'woocommerce-correios')));
			exit;
		}

		if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'woocommerce_correios_autofill_addresses_nonce')) { // WPCS: input var okay, CSRF ok.
			wp_send_json_error(array('message' => __('Invalid nonce!', 'woocommerce-correios')));
			exit;
		}

		$table_name = $wpdb->prefix . WC_Correios_AutofillAddresses::$table;
		$wpdb->query("DROP TABLE IF EXISTS $table_name;"); // @codingStandardsIgnoreLine

		WC_Correios_AutofillAddresses::create_database();

		wp_send_json_success(array('message' => __('Postcode database emptied successfully!', 'woocommerce-correios')));
	}


	function getBasePath()
	{
		$settings = $this->get_integration_settings();
		$devMode = defined('DEV_OPTIONS') && DEV_OPTIONS && $settings['devOptions'] === 'yes';
		$alternativeBasePath = $settings['alternativeBasePath'];
		$basePathUrl = ($devMode && !empty($alternativeBasePath)) ? $alternativeBasePath : 'https://apigw.bluex.cl';
		return $basePathUrl;
	}

	function test_correios_integration()
	{
		check_ajax_referer('correios_integration_nonce', 'nonce');
		// Preparar los datos para la solicitud
		$request_data = array(
			'from' => array(
				'country'  => 'CL',
				'district' => $this->get_option('districtCode'),
			),
			'to' => array(
				'country'  => 'CL',
				'state'    => 13,
				'district' => 'PRO',
			),
			'domain'       => $this->get_account_name(),
			'serviceType'  => 'EX',
			'datosProducto' => array(
				'producto'        => 'P',
				'familiaProducto' => 'PAQU',
				'bultos'          => array(
					array(
						'largo'       => 1,
						'ancho'       => 1,
						'alto'        => 1,
						'sku'         => '',
						'pesoFisico'  => 8,
						'cantidad'    => 2,
					),
					array(
						'largo'       => 1,
						'ancho'       => 1,
						'alto'        => 1,
						'sku'         => '',
						'pesoFisico'  => 1,
						'cantidad'    => 43,
					),
				),
			),
		);

		// Realizar la solicitud HTTP
		$url = $this->getBasePath() . "/api/ecommerce/pricing/v1";
		$response = wp_remote_post($url, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'apikey'       => $this->get_tracking_bxkey(),
			),
			'body'    => wp_json_encode($request_data)
		));

		// Manejar la respuesta
		if (is_wp_error($response)) {
			wp_send_json_error($response->get_error_message());
		} else {
			$response_code = wp_remote_retrieve_response_code($response);
			$response_body = wp_remote_retrieve_body($response);
			if ($response_code === 200) {
				wp_send_json_success('Integración exitosa.');
				$settings = $this->get_integration_settings();
				$settings['test_pricing_query'] = true;
				update_option('woocommerce_correios-integration_settings', $settings);
			} else {
				$settings = $this->get_integration_settings();
				$settings['test_pricing_query'] = false;
				update_option('woocommerce_correios-integration_settings', $settings);
				wp_send_json_error("Error en la integración: $response_body");
			}
		}
	}

	public function get_account_name()
	{
		return home_url() . "/";
	}


	public function get_tracking_bxkey()
	{
		$settings = $this->get_integration_settings();
		$devMode = defined('DEV_OPTIONS') && DEV_OPTIONS && $settings['devOptions'] === 'yes';
		$bxkey = $devMode ? $settings['tracking_bxkey'] : 'W6FGzkovqEQaklVLCgzXKNt5UPJiqWml';
		return $bxkey;
	}

	/**
	 * Validate if the integration is active.
	 *
	 * @return array
	 */
	public function validate_integration_status()
	{
		check_ajax_referer('correios_integration_nonce', 'nonce');

		$request_data = array(
			'ecommerce'  => 'Woocommerce',
			'accountName' => $this->get_account_name(),
		);
		$response = wp_remote_post($this->getBasePath() . "/api/ecommerce/token/v1/ecommerce/integration-status", array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'apikey'       => $this->get_tracking_bxkey(),
			),
			'body'    => wp_json_encode($request_data)
		));

		if (is_wp_error($response)) {
			return array(
				'error' => true,
				'message' => $response->get_error_message(),
			);
		} else {
			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			if (!isset($response_data['storeId'])) {
				// Case 1: storeId is not present
				return array(
					'activeIntegration' => false,
					'errorCode' => '00',
					'message' => $response_data['message'],
				);
			} elseif (!$response_data['activeIntegration']) {
				// Case 2: storeId is present but activeIntegration is false
				return array(
					'activeIntegration' => false,
					'errorCode' => '01',
					'message' => $response_data['message'],
					'storeId' => $response_data['storeId'],
				);
			} else {
				// Case 3: storeId and activeIntegration are present and true
				return $response_data;
			}
		}
	}

	/**
	 * Update integration credentials.
	 *
	 * @param string $storeId
	 * @param object $credentials
	 * @return array
	 */
	public function update_integration_credentials($storeId, $credentials)
	{
		$request_data = array(
			'storeId' => $storeId,
			'credentials' => array(
				'accessToken' => $credentials['clientKey'],
				'secretKey' => $credentials['clientSecret'],
				'accountName' => home_url() . "/",
			),
		);

		$url = $this->getBasePath() . "/api/ecommerce/token/v1/ecommerce/update-tokens";

		$response = wp_remote_post($url, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'apikey'       => $this->get_tracking_bxkey(),
			),
			'body' => wp_json_encode($request_data),
		));

		if (is_wp_error($response)) {
			return array(
				'error' => true,
				'message' => $response->get_error_message(),
			);
		} else {
			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			if (!$response_data['activeIntegration']) {
				return array(
					'activeIntegration' => false,
					'errorCode' => '01',
					'message' => $response_data['message'],
				);
			} else {
				return $response_data;
			}
		}
	}

	/**
	 * AJAX handler for validating integration status.
	 */
	public function ajax_validate_integration_is_active()
	{
		check_ajax_referer('correios_integration_nonce', 'nonce');

		$result = $this->validate_integration_status();
		$settings = $this->get_integration_settings();
		$result['settings'] = $settings;
		$result['optionsEmissionOs'] = wc_get_order_statuses();
		$result['account_name'] = $this->get_account_name();
		$result['getBasePath'] = $this->getBasePath();
		wp_send_json($result);
	}

	/**
	 * AJAX handler for updating integration credentials.
	 */
	public function ajax_update_integration_credentials()
	{
		check_ajax_referer('correios_integration_nonce', 'nonce');

		$storeId = isset($_POST['storeId']) ? sanitize_text_field(wp_unslash($_POST['storeId'])) : '';
		$credentials = isset($_POST['credentials']) ? json_decode(wp_unslash($_POST['credentials']), true) : array();

		if (empty($storeId) || empty($credentials)) {
			wp_send_json_error(array('message' => __('Missing parameters!', 'woocommerce-correios')));
			return;
		}

		$result = $this->update_integration_credentials($storeId, $credentials);
		wp_send_json($result);
	}

	/**
	 * AJAX handler for saving integration settings.
	 */
	public function ajax_save_integration_settings()
	{
		check_ajax_referer('correios_integration_nonce', 'nonce');

		// Verifica los permisos del usuario
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => __('No tienes permiso para realizar esta acción.', 'woocommerce-correios')));
			return;
		}

		$settings = $this->get_integration_settings();
		// Procesa y guarda cada opción individualmente
		$settings['noBlueStatus'] = isset($_POST['noBlueStatus']) ? sanitize_text_field(wp_unslash($_POST['noBlueStatus'])) : '';
		$settings['noBlueOnCreate'] = isset($_POST['noBlueOnCreate']) ? wp_unslash($_POST['noBlueOnCreate']) : 'no';
		$settings['districtCode'] = isset($_POST['districtCode']) ? sanitize_text_field(wp_unslash($_POST['districtCode'])) : '';
		$settings['googleKey'] = isset($_POST['googleKey']) ? sanitize_text_field(wp_unslash($_POST['googleKey'])) : '';
		$settings['pudoEnable'] = isset($_POST['pudoEnable']) ? wp_unslash($_POST['pudoEnable']) : 'no';
		$settings['districtsEnable'] = isset($_POST['districtsEnable']) ? wp_unslash($_POST['districtsEnable']) : 'no';

		update_option('woocommerce_correios-integration_settings', $settings);

		$this->test_correios_integration();

		wp_send_json_success(array('message' => __('Configuración guardada exitosamente.', 'woocommerce-correios')));
	}

	/**
	 * AJAX handler for getting integration settings.
	 */
	public function get_integration_settings()
	{
		return get_option('woocommerce_correios-integration_settings');
	}

	/**
	 * AJAX handler for saving developer settings.
	 */
	public function ajax_save_developer_settings()
	{
		check_ajax_referer('correios_integration_nonce', 'nonce');

		// Verifica los permisos del usuario
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => __('No tienes permiso para realizar esta acción.', 'woocommerce-correios')));
			return;
		}

		// Procesa y guarda las opciones de desarrollo
		$devOptions = isset($_POST['devOptions']) ? wp_unslash($_POST['devOptions']) : 'no';
		$alternativeBasePath = isset($_POST['alternativeBasePath']) ? esc_url_raw(wp_unslash($_POST['alternativeBasePath'])) : '';
		$tracking_bxkey = isset($_POST['tracking_bxkey']) ? $_POST['tracking_bxkey'] : '';

		$settings = $this->get_integration_settings();
		$settings['devOptions'] = $devOptions;
		$settings['alternativeBasePath'] = $alternativeBasePath;
		$settings['tracking_bxkey'] = $tracking_bxkey;

		update_option('woocommerce_correios-integration_settings', $settings);

		wp_send_json_success(array('message' => __('Configuración de desarrollo guardada exitosamente.', 'woocommerce-correios')));
	}
}
