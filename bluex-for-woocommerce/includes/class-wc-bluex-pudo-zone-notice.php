<?php
/**
 * Admin-side companion to WC_BlueX_Pudo_Zone_Migrator.
 *
 * Two responsibilities:
 *
 *   1. Toggle watcher — when pudoEnable flips from "no" to "yes" (i.e. the
 *      customer reactivates PUDO), detect zones that were created during
 *      the off period and now have other bluex-* methods but no bluex-pudo.
 *      Surface those as a dismissible admin notice so the operator knows
 *      to use the integration panel to add PUDO. We do NOT auto-insert in
 *      this case — respecting deliberate removals the customer may have
 *      done while PUDO was off.
 *
 *   2. Admin notice — renders dismissible notices for two states:
 *        a) "missing zones" warning (yellow) after a reactivation.
 *        b) "migration failed" error (red) with a Retry button if the
 *           Action Scheduler-driven migration ran out of backoff retries.
 *
 * The dismissal uses a hash of the currently missing zone IDs stored in
 * user_meta. If new zones appear after dismissal, the hash changes and
 * the notice re-surfaces — so dismissing won't permanently silence a
 * regression.
 *
 * Both AJAX handlers verify capabilities and a nonce, and are scoped to
 * admin-only requests.
 *
 * @package WooCommerce_Correios/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_BlueX_Pudo_Zone_Notice {

	const TRANSIENT_WARNING = 'bluex_pudo_zones_warning';
	const WARNING_TTL       = 30 * DAY_IN_SECONDS;

	const USER_META_DISMISS_HASH = 'bluex_pudo_zones_warning_dismissed_hash';

	const NONCE_ACTION = 'bluex_pudo_zones_notice';

	const AJAX_DISMISS = 'bluex_pudo_zones_dismiss_notice';
	const AJAX_RETRY   = 'bluex_pudo_zones_retry_migration';

	const SETTINGS_OPTION = 'woocommerce_correios-integration_settings';

	public static function init() {
		// Toggle watcher — fires on every save of the integration settings.
		add_action(
			'update_option_' . self::SETTINGS_OPTION,
			array( __CLASS__, 'on_settings_updated' ),
			10,
			2
		);

		// Admin notice rendering + AJAX handlers.
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'wp_ajax_' . self::AJAX_DISMISS, array( __CLASS__, 'ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_RETRY, array( __CLASS__, 'ajax_retry_migration' ) );
	}

	/**
	 * Hook: update_option_woocommerce_correios-integration_settings.
	 *
	 * Detects pudoEnable transition no→yes and writes the missing-zones
	 * snapshot to a transient consumed by render_admin_notices(). Wrapped
	 * in try/catch — a failure here must NEVER prevent the customer's
	 * settings save from succeeding.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value Newly saved option value.
	 */
	public static function on_settings_updated( $old_value, $new_value ) {
		try {
			$was_enabled = is_array( $old_value ) && ! empty( $old_value['pudoEnable'] ) && $old_value['pudoEnable'] === 'yes';
			$is_enabled  = is_array( $new_value ) && ! empty( $new_value['pudoEnable'] ) && $new_value['pudoEnable'] === 'yes';

			if ( $was_enabled || ! $is_enabled ) {
				return; // Only act on the no→yes transition.
			}

			if ( ! class_exists( 'WC_BlueX_Pudo_Zone_Migrator' ) ) {
				return;
			}

			$missing = WC_BlueX_Pudo_Zone_Migrator::detect_zones_missing_pudo();
			if ( empty( $missing ) ) {
				delete_transient( self::TRANSIENT_WARNING );
				return;
			}

			set_transient( self::TRANSIENT_WARNING, $missing, self::WARNING_TTL );
		} catch ( \Throwable $e ) {
			// Swallow — settings save must complete.
		}
	}

	/**
	 * Hook: admin_notices.
	 */
	public static function render_admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// 1) Migration-failure notice (highest priority; red).
		$failed = get_transient( WC_BlueX_Pudo_Zone_Migrator::TRANSIENT_FAILED );
		if ( is_array( $failed ) ) {
			self::render_failure_notice( $failed );
			// Don't return — also show the warning if both apply.
		}

		// 2) Missing-zones warning notice.
		$missing = get_transient( self::TRANSIENT_WARNING );
		if ( ! is_array( $missing ) || empty( $missing ) ) {
			return;
		}

		// Has the operator already dismissed THIS exact set of zones?
		$current_hash   = self::hash_zones( $missing );
		$dismissed_hash = get_user_meta( get_current_user_id(), self::USER_META_DISMISS_HASH, true );
		if ( $dismissed_hash === $current_hash ) {
			return;
		}

		self::render_missing_zones_notice( $missing, $current_hash );
	}

	private static function render_missing_zones_notice( $missing, $hash ) {
		$count       = count( $missing );
		$zones_admin = admin_url( 'admin.php?page=wc-settings&tab=integration&section=correios-integration' );

		// Show first 8 names; summarize the rest.
		$names_visible = array_slice( $missing, 0, 8 );
		$names_extra   = max( 0, $count - count( $names_visible ) );

		$nonce = wp_create_nonce( self::NONCE_ACTION );

		?>
		<div class="notice notice-warning is-dismissible bluex-pudo-zones-notice"
		     data-bluex-notice="missing-zones"
		     data-hash="<?php echo esc_attr( $hash ); ?>"
		     data-nonce="<?php echo esc_attr( $nonce ); ?>"
		     data-action="<?php echo esc_attr( self::AJAX_DISMISS ); ?>">
			<p>
				<strong><?php esc_html_e( 'Blue Express — PUDO no está configurado en algunas zonas', 'woocommerce-correios' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: number of zones missing PUDO */
					esc_html( _n(
						'Detectamos %d zona de envío que tiene métodos de Blue Express pero no incluye "Retiro en Punto Blue Express". Si querés ofrecer PUDO en esa zona, agregalo desde el panel de integración.',
						'Detectamos %d zonas de envío que tienen métodos de Blue Express pero no incluyen "Retiro en Punto Blue Express". Si querés ofrecer PUDO en esas zonas, agregalo desde el panel de integración.',
						$count,
						'woocommerce-correios'
					) ),
					$count
				);
				?>
			</p>
			<ul style="margin-left: 1.5em; list-style: disc;">
				<?php foreach ( $names_visible as $z ) : ?>
					<li><?php echo esc_html( $z['zone_name'] ); ?> <small style="color: #6b7280;">(ID <?php echo (int) $z['zone_id']; ?>)</small></li>
				<?php endforeach; ?>
				<?php if ( $names_extra > 0 ) : ?>
					<li><em>
						<?php
						printf(
							/* translators: %d: number of additional zones */
							esc_html__( 'y %d más…', 'woocommerce-correios' ),
							$names_extra
						);
						?>
					</em></li>
				<?php endif; ?>
			</ul>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $zones_admin ); ?>">
					<?php esc_html_e( 'Configurar zonas de envío', 'woocommerce-correios' ); ?>
				</a>
			</p>
			<?php self::print_dismiss_script(); ?>
		</div>
		<?php
	}

	private static function render_failure_notice( $failed ) {
		$nonce   = wp_create_nonce( self::NONCE_ACTION );
		$message = isset( $failed['message'] ) ? (string) $failed['message'] : '';
		?>
		<div class="notice notice-error bluex-pudo-zones-notice"
		     data-bluex-notice="migration-failed"
		     data-nonce="<?php echo esc_attr( $nonce ); ?>"
		     data-action="<?php echo esc_attr( self::AJAX_RETRY ); ?>">
			<p>
				<strong><?php esc_html_e( 'Blue Express — La migración automática de PUDO no se completó', 'woocommerce-correios' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'No pudimos agregar el método "Retiro en Punto Blue Express" a tus zonas de envío en segundo plano. Esto no afecta el funcionamiento de tu tienda — los demás métodos siguen operando normalmente. Podés reintentarlo o agregar PUDO manualmente desde el panel de integración.', 'woocommerce-correios' ); ?>
			</p>
			<?php if ( $message !== '' ) : ?>
				<p><small style="color: #6b7280;"><?php echo esc_html( $message ); ?></small></p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary bluex-pudo-zones-retry">
					<?php esc_html_e( 'Reintentar migración', 'woocommerce-correios' ); ?>
				</button>
			</p>
			<?php self::print_retry_script(); ?>
		</div>
		<?php
	}

	/**
	 * Inline JS for dismissing the warning notice. Uses jQuery (always
	 * present in WP admin) and the standard wp.ajax pattern.
	 */
	private static function print_dismiss_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		(function ($) {
			$(document).on('click', '.bluex-pudo-zones-notice[data-bluex-notice="missing-zones"] .notice-dismiss', function () {
				var $notice = $(this).closest('.bluex-pudo-zones-notice');
				$.post(ajaxurl, {
					action: $notice.data('action'),
					nonce:  $notice.data('nonce'),
					hash:   $notice.data('hash')
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	private static function print_retry_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		(function ($) {
			$(document).on('click', '.bluex-pudo-zones-retry', function (e) {
				e.preventDefault();
				var $btn    = $(this);
				var $notice = $btn.closest('.bluex-pudo-zones-notice');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Reintentando…', 'woocommerce-correios' ) ); ?>');
				$.post(ajaxurl, {
					action: $notice.data('action'),
					nonce:  $notice.data('nonce')
				}).done(function () {
					$notice.fadeOut(300);
				}).fail(function () {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reintentar migración', 'woocommerce-correios' ) ); ?>');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX: dismiss the missing-zones warning for the current user.
	 * Stores the hash of the dismissed zone set so the notice re-surfaces
	 * if a new zone is later detected (different hash).
	 */
	public static function ajax_dismiss_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( $hash === '' ) {
			wp_send_json_error( array( 'reason' => 'no_hash' ), 400 );
		}

		update_user_meta( get_current_user_id(), self::USER_META_DISMISS_HASH, $hash );
		wp_send_json_success();
	}

	/**
	 * AJAX: retry a failed migration. Caps + nonce verified.
	 */
	public static function ajax_retry_migration() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'WC_BlueX_Pudo_Zone_Migrator' ) ) {
			wp_send_json_error( array( 'reason' => 'migrator_missing' ), 500 );
		}

		try {
			WC_BlueX_Pudo_Zone_Migrator::retry();
			wp_send_json_success();
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'reason' => 'retry_failed', 'message' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * Stable hash over zone IDs so dismissal persists across page loads
	 * but invalidates when new zones appear.
	 */
	private static function hash_zones( $zones ) {
		$ids = array();
		foreach ( $zones as $z ) {
			$ids[] = (int) $z['zone_id'];
		}
		sort( $ids );
		return md5( implode( ',', $ids ) );
	}
}
