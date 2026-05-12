<?php

/**
 * Correios
 *
 * @package WooCommerce_Correios/Classes
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Plugins main class.
 */
class WC_Correios
{

	/**
	 * Initialize the plugin public actions.
	 */
	public static function init()
	{
		add_action('init', array(__CLASS__, 'load_plugin_textdomain'), -1);

		// Registramos los hooks de activación/desactivación
		register_activation_hook(WC_CORREIOS_PLUGIN_FILE, array(__CLASS__, 'bluex_plugin_activate'));
		register_deactivation_hook(WC_CORREIOS_PLUGIN_FILE, array(__CLASS__, 'deactivate_logger'));

		// Checks with WooCommerce is installed.
		if (class_exists('WC_Integration')) {
			self::includes();

			if (is_admin()) {
				self::admin_includes();
			}

			// Inicializar la API
			add_action('rest_api_init', array('WC_Correios_API', 'init'));

			// Inicializar Blue Express Quick Checker
			if (class_exists('WC_Bluex_Quick_Checker')) {
				WC_Bluex_Quick_Checker::init();
			}

			// Inicializar Blue Express Zones Validator
			if (class_exists('WC_BlueX_Zones_Validator')) {
				WC_BlueX_Zones_Validator::init();
			}

			// Inicializar Blue Express Granular Zones Configuration
			if (class_exists('WC_BlueX_Granular_Zones_Config')) {
				WC_BlueX_Granular_Zones_Config::init();
			}

			// Inicializar Blue Express City Zone Matcher
			if (class_exists('WC_BlueX_City_Zone_Matcher')) {
				WC_BlueX_City_Zone_Matcher::init();
			}

			// Wire the Action Scheduler callback so queued batches resolve
			// regardless of which page-load triggers the runner. Zero cost
			// at boot — only one add_action call.
			if (class_exists('WC_BlueX_Pudo_Zone_Migrator')) {
				WC_BlueX_Pudo_Zone_Migrator::init();
			}

			// Toggle watcher + admin notice. Only needed in admin contexts;
			// the AJAX handlers also live there.
			if (is_admin() && class_exists('WC_BlueX_Pudo_Zone_Notice')) {
				WC_BlueX_Pudo_Zone_Notice::init();
			}

			add_filter('woocommerce_integrations', array(__CLASS__, 'include_integrations'));
			add_filter('woocommerce_shipping_methods', array(__CLASS__, 'include_methods'));
			add_filter('woocommerce_email_classes', array(__CLASS__, 'include_emails'));

			// Normalize the bluex-pudo rate so its cost mirrors the bluex-ex rate
			// in the same package (see class-wc-bluex-pudo.php docstring for why).
			// Priority 99 runs after other plugins that may add/remove rates.
			add_filter('woocommerce_package_rates', array(__CLASS__, 'normalize_bluex_pudo_rate'), 99, 2);
		} else {
			add_action('admin_notices', array(__CLASS__, 'woocommerce_missing_notice'));
		}
	}

	public static function deactivate_logger()
	{
		require_once(plugin_dir_path(__FILE__) . 'logger/wc-logs-deactive-cron.php');
	}

	public static function bluex_create_logs_table()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'bluex_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			log_timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			log_type varchar(20) NOT NULL,
			log_body text NOT NULL,
			PRIMARY KEY  (id),
			KEY log_timestamp (log_timestamp)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	public static function bluex_plugin_activate()
	{
		// Crear la tabla de logs
		self::bluex_create_logs_table();

		// Programar la limpieza de logs
		if (! wp_next_scheduled('bluex_clean_logs')) {
			wp_schedule_event(time(), 'daily', 'bluex_clean_logs');
		}
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public static function load_plugin_textdomain()
	{
		load_plugin_textdomain('woocommerce-correios', false, dirname(plugin_basename(WC_CORREIOS_PLUGIN_FILE)) . '/languages/');
	}

	/**
	 * Includes.
	 */
	private static function includes()
	{
		// Ensure API Client is loaded first as other classes depend on it.
		include_once dirname(__FILE__) . '/class-bluex-api-client.php';

		include_once dirname(__FILE__) . '/wc-correios-functions.php';
		include_once dirname(__FILE__) . '/class-wc-correios-install.php';
		include_once dirname(__FILE__) . '/class-wc-correios-settings.php';
		include_once dirname(__FILE__) . '/logger/helper.php';
		include_once dirname(__FILE__) . '/class-wc-correios-package.php';
		include_once dirname(__FILE__) . '/class-wc-correios-webservice.php';
		include_once dirname(__FILE__) . '/class-wc-correios-webservice-international.php';
		include_once dirname(__FILE__) . '/class-wc-correios-autofill-addresses.php';
		include_once dirname(__FILE__) . '/class-wc-correios-tracking-history.php';
		include_once dirname(__FILE__) . '/class-wc-correios-rest-api.php';
		include_once dirname(__FILE__) . '/class-wc-correios-orders.php';
		include_once dirname(__FILE__) . '/class-wc-correios-cart.php';
		include_once dirname(__FILE__) . '/class-wc-correios-blocks-integration.php';
		include_once dirname(__FILE__) . '/class-wc-correios-pudos-map.php';
		include_once dirname(__FILE__) . '/class-wc-correios-webhook.php'; // Depends on API Client
		include_once dirname(__FILE__) . '/class-wc-correios-custom-order-status.php';
		include_once dirname(__FILE__) . '/api/class-wc-correios-api.php'; // Loads endpoints which depend on API Client

		// Blue Express Data
		include_once dirname(__FILE__) . '/data/bluex-exclusion-profiles.php';

		// Blue Express Shipping Zone Automation
		include_once dirname(__FILE__) . '/class-wc-bluex-shipping-zone-automation.php';

		// Blue Express PUDO Zone Migrator (Action Scheduler-driven, runs on
		// upgrade or first install when pudoEnable=yes; idempotent).
		include_once dirname(__FILE__) . '/class-wc-bluex-pudo-zone-migrator.php';

		// Blue Express PUDO Zone Notice (admin notice + AJAX dismiss/retry
		// handlers; warns about new zones missing PUDO after a toggle off→on).
		include_once dirname(__FILE__) . '/class-wc-bluex-pudo-zone-notice.php';

		// Blue Express Quick Checker
		include_once dirname(__FILE__) . '/class-bluex-quick-checker.php';

		// Blue Express Zones Validator
        if (is_admin()) {
            include_once dirname(__FILE__) . '/data/chile-communes.php';
        }
		include_once dirname(__FILE__) . '/class-bluex-zones-validator.php';

		// Blue Express Granular Zones Configuration
		include_once dirname(__FILE__) . '/class-bluex-granular-zones-config.php';

		// Blue Express City Zone Matcher
		include_once dirname(__FILE__) . '/class-wc-bluex-city-zone-matcher.php';

		// Districts
		include_once dirname(__FILE__) . '/districts/class-wc-districts.php';

		// Integration.
		include_once dirname(__FILE__) . '/integrations/class-wc-correios-integration.php'; // Depends on API Client



		// Shipping methods.
		if (defined('WC_VERSION') && version_compare(WC_VERSION, '2.6.0', '>=')) {
			include_once dirname(__FILE__) . '/abstracts/class-wc-correios-shipping.php';
			include_once dirname(__FILE__) . '/abstracts/class-wc-correios-shipping-carta.php';
			include_once dirname(__FILE__) . '/abstracts/class-wc-correios-shipping-impresso.php';
			include_once dirname(__FILE__) . '/abstracts/class-wc-correios-shipping-international.php';
			foreach (glob(plugin_dir_path(__FILE__) . '/shipping/*.php') as $filename) {
				include_once $filename;
			}

			// Update settings to 3.0.0 when using WooCommerce 2.6.0.
			WC_Correios_Install::upgrade_300_fromWc260();
		} else {
			include_once dirname(__FILE__) . '/shipping/class-wc-correios-shipping-legacy.php';
		}

		// Update to 3.0.0.
		WC_Correios_Install::upgrade_300();

		// Schedule the bluex-pudo zone migration if needed. Cheap to call
		// on every page load (option compare + early return); the actual
		// work runs async via Action Scheduler.
		WC_Correios_Install::upgrade_zones_migration();
	}

	/**
	 * Admin includes.
	 */
	private static function admin_includes()
	{
		include_once dirname(__FILE__) . '/admin/class-wc-correios-admin-orders.php';
	}

	/**
	 * Include Correios integration to WooCommerce.
	 *
	 * @param  array $integrations Default integrations.
	 *
	 * @return array
	 */
	public static function include_integrations($integrations)
	{
		$integrations[] = 'WC_Correios_Integration';

		return $integrations;
	}

	/**
	 * Include Correios shipping methods to WooCommerce.
	 *
	 * @param  array $methods Default shipping methods.
	 *
	 * @return array
	 */
	public static function include_methods($methods)
	{
		// Legacy method.
		$methods['correios-legacy'] = 'WC_Correios_ShippingLegacy';

		// New methods.
		if (defined('WC_VERSION') && version_compare(WC_VERSION, '2.6.0', '>=')) {
			$methods['bluex-ex']					= 'WC_BlueX_EX';
			$methods['bluex-py']					= 'WC_BlueX_PY';
			$methods['bluex-md']					= 'WC_BlueX_MD';
			$methods['bluex-pudo']					= 'WC_BlueX_Pudo';
			$old_options = get_option('woocommerce_correios_settings');
			if (empty($old_options)) {
				unset($methods['correios-legacy']);
			}
		}

		return $methods;
	}

	/**
	 * Normalize Blue Express shipping rates: mirror pudo's cost/taxes to the
	 * bluex-ex rate, and order the package so all Blue rates come first
	 * (pudo ahead of the rest) with non-Blue carriers after.
	 *
	 * Why:
	 * - bluex-pudo is a UX wrapper around bluex-ex. The customer picks a
	 *   pickup point, but the order itself is processed by the BlueX Express
	 *   service. Showing PUDO with a different price than Express would
	 *   confuse users at place-order (total changes). The order's
	 *   shipping_method is rewritten to `bluex-ex` at creation time (see
	 *   WC_BlueX_Pudo), so the cost MUST already match Express.
	 * - Ordering Blue carriers first is a UX decision: the store owner's
	 *   primary courier should lead the list regardless of whether pudo is
	 *   enabled. When pudo is off (WC_BlueX_Pudo::calculate_shipping early-
	 *   returns and nothing arrives here under that id), we still sort ex/
	 *   py/md ahead of flat_rate / free_shipping / local_pickup.
	 *
	 * @param WC_Shipping_Rate[] $rates   Keyed by rate id.
	 * @param array              $package Shipping package.
	 * @return WC_Shipping_Rate[]
	 */
	public static function normalize_bluex_pudo_rate($rates, $package)
	{
		if (empty($rates) || !is_array($rates)) {
			return $rates;
		}

		// Partition by category in a single pass: pudo, ex, other-blue, other.
		$pudo_key = null;
		$ex_key   = null;
		$bluex_non_pudo = array();
		$other          = array();
		foreach ($rates as $key => $rate) {
			if (!is_object($rate) || !method_exists($rate, 'get_method_id')) {
				$other[$key] = $rate;
				continue;
			}
			$method_id = $rate->get_method_id();
			if ($method_id === 'bluex-pudo' && $pudo_key === null) {
				$pudo_key = $key;
				continue;
			}
			if ($method_id === 'bluex-ex' && $ex_key === null) {
				$ex_key = $key;
				continue;
			}
			if (is_string($method_id) && strpos($method_id, 'bluex-') === 0) {
				$bluex_non_pudo[$key] = $rate;
				continue;
			}
			$other[$key] = $rate;
		}

		// If pudo arrived but no ex is available in this package, pudo can't
		// mirror a parent price — drop it to avoid showing a free/zero cost
		// option that would differ from the eventual order total.
		if ($pudo_key !== null && $ex_key === null) {
			unset($rates[$pudo_key]);
			$pudo_key = null;
		}

		// Mirror cost + taxes from ex onto pudo when both exist.
		if ($pudo_key !== null && $ex_key !== null) {
			$ex_rate   = $rates[$ex_key];
			$pudo_rate = $rates[$pudo_key];
			if (method_exists($ex_rate, 'get_cost') && method_exists($pudo_rate, 'set_cost')) {
				$pudo_rate->set_cost($ex_rate->get_cost());
			}
			if (method_exists($ex_rate, 'get_taxes') && method_exists($pudo_rate, 'set_taxes')) {
				$pudo_rate->set_taxes($ex_rate->get_taxes());
			}
		}

		// If there is no Blue rate at all, leave the caller-provided order
		// untouched — nothing to prioritize.
		if ($pudo_key === null && $ex_key === null && empty($bluex_non_pudo)) {
			return $rates;
		}

		// Final order: pudo (if any), ex (if any), remaining bluex-* (py/md
		// in the order WC received them), then everything else.
		$ordered = array();
		if ($pudo_key !== null) {
			$ordered[$pudo_key] = $rates[$pudo_key];
		}
		if ($ex_key !== null) {
			$ordered[$ex_key] = $rates[$ex_key];
		}
		return $ordered + $bluex_non_pudo + $other;
	}

	/**
	 * Include emails.
	 *
	 * @param  array $emails Default emails.
	 *
	 * @return array
	 */
	public static function include_emails($emails)
	{
		if (!isset($emails['WC_Correios_TrackingEmail'])) {
			$emails['WC_Correios_TrackingEmail'] = include dirname(__FILE__) . '/emails/class-wc-correios-tracking-email.php';
		}

		return $emails;
	}

	/**
	 * WooCommerce fallback notice.
	 */
	public static function woocommerce_missing_notice()
	{
		include_once dirname(__FILE__) . '/admin/views/html-admin-missing-dependencies.php';
	}

	/**
	 * Get main file.
	 *
	 * @return string
	 */
	public static function get_main_file()
	{
		return WC_CORREIOS_PLUGIN_FILE;
	}

	/**
	 * Get plugin path.
	 *
	 * @return string
	 */
	public static function get_plugin_path()
	{
		return plugin_dir_path(WC_CORREIOS_PLUGIN_FILE);
	}

	/**
	 * Get templates path.
	 *
	 * @return string
	 */
	public static function get_templates_path()
	{
		return self::get_plugin_path() . 'templates/';
	}
}
