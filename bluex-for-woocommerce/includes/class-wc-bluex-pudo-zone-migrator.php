<?php
/**
 * Zone migrator: idempotently inserts bluex-pudo into existing WC shipping
 * zones that already have other bluex-* methods configured.
 *
 * Designed to be safe for production:
 *
 *   - Async via Action Scheduler (with WP-Cron fallback) so it never blocks
 *     a customer-facing request.
 *   - Idempotent: re-running converges to the same state. Each zone check
 *     verifies presence of other bluex-* AND absence of bluex-pudo before
 *     adding. Multiple runs of process_zone() on the same zone are no-ops.
 *   - Batched: BATCH_SIZE zones per scheduled run; the cursor option tracks
 *     progress between batches so a slow-to-finish migration can be paused
 *     and resumed.
 *   - Version-gated: stored MIGRATION_VERSION option prevents re-execution
 *     after the migration has completed for the current schema.
 *   - Locked: a 5-minute transient prevents concurrent batches from racing
 *     (e.g. wp-cron + manual trigger).
 *   - Backoff: exponential delays (1m / 5m / 15m / stop) on whole-batch
 *     failures, so transient errors retry but persistent failures don't
 *     hammer the queue.
 *   - Never bubbles exceptions to admin/cron callers; everything is wrapped
 *     in try/finally and logged via wc_get_logger().
 *
 * Public API:
 *   - init() — wires the Action Scheduler callback.
 *   - maybe_schedule_migration() — entry point from upgrade hook.
 *   - retry() — admin-triggered retry; resets backoff and re-schedules.
 *   - detect_zones_missing_pudo() — public helper used by the notice class.
 *
 * @package WooCommerce_Correios/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_BlueX_Pudo_Zone_Migrator {

	const MIGRATION_VERSION = '1.0';

	const OPTION_VERSION  = 'bluex_pudo_zones_migrated_version';
	const OPTION_CURSOR   = 'bluex_pudo_zones_migration_cursor';
	const OPTION_STARTED  = 'bluex_pudo_zones_migration_started_at';

	const TRANSIENT_LOCK     = 'bluex_pudo_zones_migration_lock';
	const TRANSIENT_ATTEMPTS = 'bluex_pudo_zones_migration_attempts';
	const TRANSIENT_FAILED   = 'bluex_pudo_zones_migration_failed';

	const HOOK  = 'bluex_pudo_migrate_zones_batch';
	const GROUP = 'bluex-pudo';

	const BATCH_SIZE = 20;
	const LOCK_TTL   = 300;          // 5 minutes
	const FAILED_TTL = 7 * DAY_IN_SECONDS;

	const LOG_SOURCE = 'bluex-pudo-zones-migration';

	/**
	 * Bluex method IDs that count as "this zone is configured for Blue
	 * Express" — if any of these is present in a zone, that zone is a
	 * migration candidate.
	 *
	 * @var string[]
	 */
	private static $bluex_courier_methods = array( 'bluex-ex', 'bluex-py', 'bluex-md' );

	/**
	 * Wires the Action Scheduler callback. Must run on every page load so
	 * the queue runner can resolve the hook when actions fire.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ), 10, 1 );
	}

	/**
	 * Public entry point from the install/upgrade flow. Cheap to call: only
	 * touches a couple of options before deciding to do anything.
	 *
	 * Called on every admin page load via the include() chain.
	 */
	public static function maybe_schedule_migration() {
		if ( ! self::is_pudo_globally_enabled() ) {
			return;
		}

		$migrated = get_option( self::OPTION_VERSION );
		if ( $migrated === self::MIGRATION_VERSION ) {
			return; // Already done for this migration schema.
		}

		// Avoid duplicate scheduling: if a batch is already queued, leave it.
		if ( self::has_scheduled_batch() ) {
			return;
		}

		self::schedule_next_batch( 0, 30 );
	}

	/**
	 * Admin-triggered retry. Resets the backoff counter and re-schedules.
	 * Caller MUST verify capabilities / nonce.
	 */
	public static function retry() {
		delete_transient( self::TRANSIENT_ATTEMPTS );
		delete_transient( self::TRANSIENT_FAILED );

		// Resume from where we left off; if no cursor, start over.
		$cursor = (int) get_option( self::OPTION_CURSOR, 0 );
		self::schedule_next_batch( $cursor, 5 );
	}

	/**
	 * Action Scheduler callback. Processes BATCH_SIZE zones starting at the
	 * given cursor and re-schedules itself if more remain. All errors are
	 * caught and logged — never bubbles to the runner.
	 *
	 * @param array|int $args { 'cursor' => int } or a raw cursor.
	 */
	public static function run_batch( $args = array() ) {
		$cursor = is_array( $args ) ? (int) ( $args['cursor'] ?? 0 ) : (int) $args;

		// Concurrency guard: only one batch in flight at a time.
		if ( get_transient( self::TRANSIENT_LOCK ) ) {
			return;
		}
		set_transient( self::TRANSIENT_LOCK, 1, self::LOCK_TTL );

		try {
			// Re-check the master switch on every batch — if the customer
			// disabled PUDO mid-migration, stop quietly.
			if ( ! self::is_pudo_globally_enabled() ) {
				return;
			}

			if ( ! get_option( self::OPTION_STARTED ) ) {
				update_option( self::OPTION_STARTED, time(), false );
			}

			$zones = self::get_zones_slice( $cursor, self::BATCH_SIZE );

			foreach ( $zones as $zone_summary ) {
				try {
					self::process_zone( (int) $zone_summary['zone_id'] );
				} catch ( \Throwable $zone_error ) {
					self::log_error( 'Per-zone migration failed', array(
						'zone_id' => $zone_summary['zone_id'] ?? null,
						'error'   => $zone_error->getMessage(),
					) );
					// Continue with the next zone — one bad zone shouldn't
					// poison the whole batch.
				}
			}

			$new_cursor = $cursor + count( $zones );
			update_option( self::OPTION_CURSOR, $new_cursor, false );

			$total = count( WC_Shipping_Zones::get_zones() );

			if ( $new_cursor < $total ) {
				self::schedule_next_batch( $new_cursor, 30 );
			} else {
				self::mark_complete();
			}
		} catch ( \Throwable $batch_error ) {
			self::handle_batch_failure( $cursor, $batch_error );
		} finally {
			delete_transient( self::TRANSIENT_LOCK );
		}
	}

	/**
	 * Idempotent zone migration: add bluex-pudo iff the zone has another
	 * Blue Express method (ex/py/md) AND doesn't already have bluex-pudo.
	 *
	 * @param int $zone_id WC zone identifier.
	 */
	private static function process_zone( $zone_id ) {
		if ( apply_filters( 'bluex_skip_zone_migration', false, $zone_id ) ) {
			return;
		}

		$zone = WC_Shipping_Zones::get_zone( $zone_id );
		if ( ! $zone instanceof WC_Shipping_Zone ) {
			return; // Zone deleted between batches — no-op.
		}

		$instances = $zone->get_shipping_methods( false );

		$has_courier = false;
		$has_pudo    = false;
		foreach ( $instances as $instance ) {
			$id = $instance->id;
			if ( in_array( $id, self::$bluex_courier_methods, true ) ) {
				$has_courier = true;
			}
			if ( $id === 'bluex-pudo' ) {
				$has_pudo = true;
			}
		}

		if ( $has_courier && ! $has_pudo ) {
			$instance_id = $zone->add_shipping_method( 'bluex-pudo' );
			if ( $instance_id ) {
				self::log_info( 'Added bluex-pudo to zone', array(
					'zone_id'     => $zone_id,
					'zone_name'   => $zone->get_zone_name(),
					'instance_id' => $instance_id,
				) );
			}
		}
	}

	/**
	 * Returns the list of zones (id + name) that have at least one
	 * Blue Express courier method but DO NOT have bluex-pudo. Used by both
	 * the migrator (iteration) and the admin notice (display).
	 *
	 * @return array<int, array{zone_id:int, zone_name:string}>
	 */
	public static function detect_zones_missing_pudo() {
		$missing = array();

		foreach ( WC_Shipping_Zones::get_zones() as $zone_summary ) {
			$zone = WC_Shipping_Zones::get_zone( (int) $zone_summary['zone_id'] );
			if ( ! $zone instanceof WC_Shipping_Zone ) {
				continue;
			}

			$instances   = $zone->get_shipping_methods( false );
			$has_courier = false;
			$has_pudo    = false;

			foreach ( $instances as $instance ) {
				if ( in_array( $instance->id, self::$bluex_courier_methods, true ) ) {
					$has_courier = true;
				}
				if ( $instance->id === 'bluex-pudo' ) {
					$has_pudo = true;
				}
			}

			if ( $has_courier && ! $has_pudo ) {
				$missing[] = array(
					'zone_id'   => (int) $zone_summary['zone_id'],
					'zone_name' => $zone->get_zone_name(),
				);
			}
		}

		return $missing;
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	private static function is_pudo_globally_enabled() {
		$cfg = get_option( 'woocommerce_correios-integration_settings' );
		return ! empty( $cfg['pudoEnable'] ) && $cfg['pudoEnable'] === 'yes';
	}

	/**
	 * WC_Shipping_Zones::get_zones() returns zones excluding the implicit
	 * "Locations not covered" zone (zone_id 0). We slice for batching.
	 */
	private static function get_zones_slice( $offset, $limit ) {
		$all = WC_Shipping_Zones::get_zones();
		return array_slice( $all, $offset, $limit );
	}

	private static function schedule_next_batch( $cursor, $delay_seconds ) {
		$args = array( 'cursor' => (int) $cursor );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action(
				time() + max( 5, (int) $delay_seconds ),
				self::HOOK,
				$args,
				self::GROUP,
				true // unique — don't queue multiple identical batches.
			);
			if ( $action_id ) {
				return;
			}
			// Action Scheduler returned 0 (error logged by AS). Fall through
			// to WP-Cron fallback rather than dropping the migration.
			self::log_error( 'as_schedule_single_action returned 0; falling back to wp_schedule_single_event', $args );
		}

		// Fallback for stores without Action Scheduler available (rare —
		// WC bundles it). WP-Cron isn't as robust but keeps the feature
		// functional in any environment.
		wp_schedule_single_event( time() + max( 5, (int) $delay_seconds ), self::HOOK, array( $args ) );
	}

	private static function has_scheduled_batch() {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::HOOK, null, self::GROUP );
		}
		return (bool) wp_next_scheduled( self::HOOK );
	}

	private static function mark_complete() {
		update_option( self::OPTION_VERSION, self::MIGRATION_VERSION, false );
		delete_option( self::OPTION_CURSOR );
		delete_option( self::OPTION_STARTED );
		delete_transient( self::TRANSIENT_ATTEMPTS );
		delete_transient( self::TRANSIENT_FAILED );

		self::log_info( 'Zone migration complete', array(
			'migration_version' => self::MIGRATION_VERSION,
		) );
	}

	/**
	 * Whole-batch failure handler with exponential backoff.
	 * After the last delay, stops scheduling and surfaces a "failed" flag
	 * to the admin notice so the operator can press "Reintentar".
	 */
	private static function handle_batch_failure( $cursor, $error ) {
		$delays   = array( 60, 300, 900 ); // 1m, 5m, 15m.
		$attempts = (int) get_transient( self::TRANSIENT_ATTEMPTS );

		self::log_error( 'Batch failed', array(
			'cursor'   => $cursor,
			'attempts' => $attempts,
			'error'    => $error->getMessage(),
		) );

		if ( $attempts < count( $delays ) ) {
			set_transient( self::TRANSIENT_ATTEMPTS, $attempts + 1, DAY_IN_SECONDS );
			self::schedule_next_batch( $cursor, $delays[ $attempts ] );
			return;
		}

		// Out of retries — surface to admin and stop.
		set_transient( self::TRANSIENT_FAILED, array(
			'cursor'  => (int) $cursor,
			'message' => $error->getMessage(),
			'time'    => time(),
		), self::FAILED_TTL );
	}

	private static function log_info( $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		try {
			wc_get_logger()->info(
				$message . ' ' . wp_json_encode( $context ),
				array( 'source' => self::LOG_SOURCE )
			);
		} catch ( \Throwable $e ) {
			// Logger should never break us.
		}
	}

	private static function log_error( $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		try {
			wc_get_logger()->error(
				$message . ' ' . wp_json_encode( $context ),
				array( 'source' => self::LOG_SOURCE )
			);
		} catch ( \Throwable $e ) {
			// Swallow — never propagate logging errors.
		}
	}
}
