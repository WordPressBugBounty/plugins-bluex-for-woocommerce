<?php
/**
 * WooCommerce wc-auth handshake used to obtain REST API keys without manual entry.
 *
 * @package WooCommerce_Correios
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The state token is the only secret tying the public callback to the admin who started
 * the flow, so it is single use and expires. API keys are forwarded straight to Blue
 * Express and are never stored by this plugin or sent back to the browser.
 */
class WC_BlueX_Integration_Auth
{
	const KEY_OPTION       = 'bluex_wc_auth_key_id';
	const TRANSIENT_PREFIX = 'bluex_wc_auth_';
	const TTL              = 1800;
	const APP_NAME         = 'Blue Express';

	private $updater;
	private $key_verifier;
	private $key_revoker;
	private $integration_active;

	/**
	 * @param array $deps Optional callables: updater, key_verifier, key_revoker, integration_active.
	 */
	public function __construct(array $deps = array())
	{
		$this->updater            = isset($deps['updater']) ? $deps['updater'] : array(__CLASS__, 'default_updater');
		$this->key_verifier       = isset($deps['key_verifier']) ? $deps['key_verifier'] : array(__CLASS__, 'default_key_verifier');
		$this->key_revoker        = isset($deps['key_revoker']) ? $deps['key_revoker'] : array(__CLASS__, 'default_key_revoker');
		$this->integration_active = isset($deps['integration_active']) ? $deps['integration_active'] : array(__CLASS__, 'default_integration_active');
	}

	/**
	 * @param string $store_id Blue Express integration ID typed by the seller.
	 * @param int    $user_id  WordPress user starting the flow.
	 * @return array
	 */
	public function start($store_id, $user_id)
	{
		$store_id = trim((string) $store_id);
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $store_id)) {
			return self::failure('invalid_store_id', __('El ID de integración no es válido. Revísalo e inténtalo nuevamente.', 'woocommerce-correios'), false);
		}

		$callback_url = rest_url('wc-bluex/v1/wc-auth-callback');
		if (0 !== strpos($callback_url, 'https://')) {
			return self::failure('https_required', __('Tu tienda necesita HTTPS para conectarse automáticamente con Blue Express. Puedes ingresar las claves manualmente.', 'woocommerce-correios'), true);
		}

		$state = bin2hex(random_bytes(16));
		$this->save($state, array(
			'store_id' => $store_id,
			'user_id'  => (int) $user_id,
			'status'   => 'pending',
			'is_new'   => $this->is_new_integration(),
		));

		$return_url = admin_url('admin.php?page=wc-settings&tab=integration&section=correios-integration') . '&bx_auth_state=' . $state;
		$base       = wc_get_endpoint_url('wc-auth/v1', 'authorize', home_url('/'));
		$query      = http_build_query(array(
			'app_name'     => self::APP_NAME,
			'scope'        => 'read_write',
			'user_id'      => $state,
			'return_url'   => $return_url,
			'callback_url' => $callback_url,
		), '', '&', PHP_QUERY_RFC3986);

		return array(
			'success'  => true,
			'state'    => $state,
			'auth_url' => $base . (false === strpos($base, '?') ? '?' : '&') . $query,
		);
	}

	/**
	 * Handles the server-to-server POST sent by WooCommerce after the admin approves.
	 *
	 * @param array $payload key_id, user_id (our state), consumer_key, consumer_secret.
	 * @return array{ok: bool}
	 */
	public function handle_callback(array $payload)
	{
		$state  = isset($payload['user_id']) ? (string) $payload['user_id'] : '';
		$record = self::is_valid_state($state) ? $this->load($state) : null;
		if (!$record || 'pending' !== $record['status']) {
			return array('ok' => false);
		}

		// Single use: any replay of this state is rejected from here on.
		$record['status'] = 'processing';
		$this->save($state, $record);

		$key_id          = isset($payload['key_id']) ? (int) $payload['key_id'] : 0;
		$consumer_key    = isset($payload['consumer_key']) ? (string) $payload['consumer_key'] : '';
		$consumer_secret = isset($payload['consumer_secret']) ? (string) $payload['consumer_secret'] : '';

		$well_formed = $key_id > 0
			&& 0 === strpos($consumer_key, 'ck_')
			&& 0 === strpos($consumer_secret, 'cs_');

		if (!$well_formed || !call_user_func($this->key_verifier, $key_id, $consumer_key)) {
			$this->fail($state, $record, 'invalid_credentials', __('No pudimos obtener las credenciales de tu tienda automáticamente. Puedes ingresarlas manualmente.', 'woocommerce-correios'), true);
			return array('ok' => true);
		}

		$record['status'] = 'credentials_received';
		$this->save($state, $record);

		try {
			$outcome = call_user_func($this->updater, $record['store_id'], array(
				'clientKey'    => $consumer_key,
				'clientSecret' => $consumer_secret,
			));
		} catch (Throwable $e) {
			bluex_log('error', 'wc-auth credential update threw: ' . $e->getMessage());
			call_user_func($this->key_revoker, $key_id);
			$this->fail($state, $record, 'integration_failed', __('No pudimos comunicarnos con Blue Express. Inténtalo nuevamente en unos minutos.', 'woocommerce-correios'), false);
			return array('ok' => true);
		}

		if (empty($outcome['ok'])) {
			bluex_log('error', 'wc-auth credential update rejected by Blue Express.');
			call_user_func($this->key_revoker, $key_id);
			$this->fail($state, $record, 'credentials_rejected', __('Blue Express no pudo validar la integración. Verifica el ID de integración e inténtalo nuevamente.', 'woocommerce-correios'), false);
			return array('ok' => true);
		}

		$previous = (int) get_option(self::KEY_OPTION, 0);
		update_option(self::KEY_OPTION, $key_id);
		if ($previous > 0 && $previous !== $key_id) {
			call_user_func($this->key_revoker, $previous);
		}

		$record['status'] = 'connected';
		$this->save($state, $record);

		return array('ok' => true);
	}

	/**
	 * @param string $state   State returned by start().
	 * @param int    $user_id Current WordPress user; must own the flow.
	 * @return array
	 */
	public function get_status($state, $user_id)
	{
		$record = self::is_valid_state((string) $state) ? $this->load((string) $state) : null;
		if (!$record || (int) $record['user_id'] !== (int) $user_id) {
			return array(
				'status'       => 'expired',
				'code'         => 'authorization_expired',
				'message'      => __('La autorización expiró o no se completó. Vuelve a intentarlo.', 'woocommerce-correios'),
				'allow_manual' => true,
			);
		}

		if ('failed' === $record['status']) {
			return array(
				'status'       => 'failed',
				'code'         => $record['code'],
				'message'      => $record['message'],
				'allow_manual' => $record['allow_manual'],
			);
		}

		$public = 'processing' === $record['status'] ? 'pending' : $record['status'];
		if ('connected' === $public) {
			return array('status' => 'connected', 'is_new' => !empty($record['is_new']));
		}
		return array('status' => $public);
	}

	// An unknown status counts as an update so a failed check never overrides the seller's settings.
	private function is_new_integration()
	{
		try {
			return false === call_user_func($this->integration_active);
		} catch (Throwable $e) {
			return false;
		}
	}

	private function fail($state, array $record, $code, $message, $allow_manual)
	{
		$record['status']       = 'failed';
		$record['code']         = $code;
		$record['message']      = $message;
		$record['allow_manual'] = $allow_manual;
		$this->save($state, $record);
	}

	private static function failure($code, $message, $allow_manual)
	{
		return array('success' => false, 'code' => $code, 'message' => $message, 'allow_manual' => $allow_manual);
	}

	private static function is_valid_state($state)
	{
		return 1 === preg_match('/^[a-f0-9]{32}$/', $state);
	}

	private static function transient_key($state)
	{
		return self::TRANSIENT_PREFIX . hash('sha256', $state);
	}

	private function save($state, array $record)
	{
		set_transient(self::transient_key($state), $record, self::TTL);
	}

	private function load($state)
	{
		$record = get_transient(self::transient_key($state));
		return is_array($record) ? $record : null;
	}

	public static function default_integration_active()
	{
		$response = (new BlueX_API_Client())->validate_integration_status();
		if (is_wp_error($response) || !is_array($response)) {
			return null;
		}
		return !empty($response['activeIntegration']);
	}

	public static function default_updater($store_id, array $credentials)
	{
		$response = (new BlueX_API_Client())->update_integration_credentials($store_id, $credentials);
		if (is_wp_error($response)) {
			return array('ok' => false, 'message' => $response->get_error_message());
		}
		return array('ok' => !empty($response['activeIntegration']), 'message' => isset($response['message']) ? (string) $response['message'] : '');
	}

	public static function default_key_verifier($key_id, $consumer_key)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';
		$found = $wpdb->get_var($wpdb->prepare("SELECT key_id FROM {$table} WHERE key_id = %d AND consumer_key = %s", $key_id, wc_api_hash($consumer_key)));
		return null !== $found;
	}

	public static function default_key_revoker($key_id)
	{
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'woocommerce_api_keys', array('key_id' => (int) $key_id), array('%d'));
	}
}
