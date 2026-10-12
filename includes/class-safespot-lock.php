<?php
/**
 * Global operation lock.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name comes from $wpdb->base_prefix; all values go through $wpdb->prepare().

/**
 * Prevents two backups/restores from running at the same time, shows what is running
 * and lets it be cancelled.
 *
 * Uses an atomic INSERT IGNORE on the main site's options table (unique option_name),
 * the same technique as WP_Upgrader::create_lock(), but shared across the network.
 * The lock is re-entrant within one request and released on shutdown, even after a
 * fatal error.
 *
 * The holder reports progress and a heartbeat through checkpoint(). Another request
 * can ask it to stop with request_cancel(): the holder sees the request at its next
 * checkpoint, cleans up and releases the lock. A lock whose holder has shown no sign
 * of life for STALE_AFTER seconds (the PHP process was killed) is taken over
 * automatically.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * -- Atomic lock row must bypass the options cache.
 */
class SafeSpot_Lock {

	const NAME   = 'safespot_operation_lock';
	const CANCEL = 'safespot_operation_cancel';

	/**
	 * Seconds without a heartbeat after which the holder is considered dead and the
	 * lock may be taken over automatically.
	 */
	const STALE_AFTER = 600;

	/**
	 * Seconds without a heartbeat after which the holder is shown as not responding
	 * (and may be force-released by a user).
	 */
	const UNRESPONSIVE_AFTER = 60;

	/**
	 * Minimum seconds between two heartbeat writes.
	 */
	const BEAT_INTERVAL = 2;

	/**
	 * Re-entrancy depth for the current request.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Raw value currently stored in the lock row by this request.
	 *
	 * @var string
	 */
	private static $owned = '';

	/**
	 * Decoded lock state owned by this request.
	 *
	 * @var array
	 */
	private static $state = array();

	/**
	 * Contexts of nested acquisitions (outermost first).
	 *
	 * @var string[]
	 */
	private static $stack = array();

	/**
	 * Time of the last heartbeat write.
	 *
	 * @var float
	 */
	private static $last_beat = 0.0;

	/**
	 * Whether this request was asked to stop (or lost the lock).
	 *
	 * @var bool
	 */
	private static $cancelled = false;

	/**
	 * Whether the shutdown handler is registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Options table of the main site.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->get_blog_prefix( is_multisite() ? get_main_site_id() : 0 ) . 'options';
	}

	/**
	 * Acquires the lock.
	 *
	 * @param string $context Operation, e.g. "save:plugin:akismet/akismet.php", "restore:theme:twentytwentyfive" or "queue".
	 * @param int    $wait    Seconds to wait for a busy lock.
	 * @return bool
	 */
	public static function acquire( $context = '', $wait = 0 ) {
		global $wpdb;

		if ( self::$depth > 0 ) {
			++self::$depth;
			self::$stack[]           = (string) $context;
			self::$state['context']  = (string) $context;
			self::$state['progress'] = null;
			self::write();
			return true;
		}

		$table    = self::table();
		$deadline = time() + max( 0, (int) $wait );
		$attempts = 0;

		while ( $attempts < 600 ) {
			++$attempts;
			$state = array(
				'time'        => time(),
				'beat'        => time(),
				'context'     => (string) $context,
				'root'        => (string) $context,
				'user'        => get_current_user_id(),
				'token'       => wp_generate_password( 12, false, false ),
				'cancellable' => true,
				'progress'    => null,
			);
			$value = wp_json_encode( $state );

			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::NAME,
					$value
				)
			);

			if ( $inserted ) {
				self::$depth     = 1;
				self::$owned     = $value;
				self::$state     = $state;
				self::$stack     = array( (string) $context );
				self::$cancelled = false;
				self::$last_beat = microtime( true );
				// A cancel request can only target the job that was running when it was made.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s", self::CANCEL ) );
				if ( ! self::$shutdown_registered ) {
					register_shutdown_function( array( __CLASS__, 'shutdown' ) );
					self::$shutdown_registered = true;
				}
				return true;
			}

			$raw  = self::raw();
			$info = $raw ? json_decode( $raw, true ) : null;

			if ( null === $raw ) {
				continue;
			}

			if ( ! is_array( $info ) || self::is_stale( $info ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::NAME, $raw ) );
				continue;
			}

			if ( time() >= $deadline ) {
				return false;
			}
			sleep( 1 );
		}

		return false;
	}

	/**
	 * Releases one level of the lock.
	 */
	public static function release() {
		global $wpdb;

		if ( self::$depth > 1 ) {
			--self::$depth;
			array_pop( self::$stack );
			self::$state['context']  = (string) end( self::$stack );
			self::$state['progress'] = null;
			self::write();
			return;
		}
		if ( 1 === self::$depth ) {
			$table = self::table();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::NAME, self::$owned ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::CANCEL, (string) ( self::$state['token'] ?? '' ) ) );
		}
		self::reset();
	}

	/**
	 * Shutdown handler: frees a lock left behind by an aborted request.
	 */
	public static function shutdown() {
		if ( self::$depth > 0 ) {
			self::$depth = 1;
			self::release();
		}
	}

	/**
	 * Reports progress, writes a heartbeat and checks for a cancel request.
	 *
	 * Cheap enough to call for every file: the database is touched at most once per
	 * BEAT_INTERVAL seconds. Returns false when this request does not hold the lock.
	 *
	 * @param array|null $progress Optional { phase, done, total }.
	 * @return bool True when the current operation must stop.
	 */
	public static function checkpoint( $progress = null ) {
		global $wpdb;

		if ( self::$depth < 1 ) {
			return false;
		}
		if ( is_array( $progress ) ) {
			self::$state['progress'] = array(
				'phase' => (string) ( $progress['phase'] ?? '' ),
				'done'  => (int) ( $progress['done'] ?? 0 ),
				'total' => (int) ( $progress['total'] ?? 0 ),
			);
		}
		if ( self::$cancelled ) {
			return true;
		}

		$now = microtime( true );
		if ( $now - self::$last_beat < self::BEAT_INTERVAL ) {
			return false;
		}
		self::$last_beat = $now;

		self::$state['beat'] = time();
		if ( ! self::write() ) {
			// Someone force-released the lock: stop before we collide with another job.
			self::$cancelled = true;
			return true;
		}

		if ( ! empty( self::$state['cancellable'] ) ) {
			$requested = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name = %s', self::CANCEL ) );
			if ( null !== $requested && hash_equals( (string) self::$state['token'], (string) $requested ) ) {
				self::$cancelled = true;
			}
		}

		return self::$cancelled;
	}

	/**
	 * Starts a new phase and publishes it right away.
	 *
	 * @param string $phase Phase key (see phase_label()).
	 * @param int    $done  Units done.
	 * @param int    $total Total units (0 = unknown).
	 * @return bool True when the current operation must stop.
	 */
	public static function phase( $phase, $done = 0, $total = 0 ) {
		self::$last_beat = 0.0;
		return self::checkpoint(
			array(
				'phase' => $phase,
				'done'  => $done,
				'total' => $total,
			)
		);
	}

	/**
	 * Marks whether the running operation may be cancelled.
	 *
	 * A restore turns this off while files are being replaced: stopping half-way would
	 * leave a broken plugin or theme behind.
	 *
	 * @param bool $cancellable Whether a cancel request is honoured.
	 */
	public static function set_cancellable( $cancellable ) {
		if ( self::$depth < 1 ) {
			return;
		}
		self::$state['cancellable'] = (bool) $cancellable;
		self::write();
	}

	/**
	 * Whether this request has been asked to stop.
	 *
	 * @return bool
	 */
	public static function is_cancelled() {
		return self::$cancelled;
	}

	/**
	 * Error returned by an operation that stopped because it was cancelled.
	 *
	 * @return WP_Error
	 */
	public static function cancelled_error() {
		return new WP_Error( 'safespot_cancelled', __( 'The operation was cancelled. Nothing was changed.', 'tbsh-safespot-restore-points' ), array( 'status' => 409 ) );
	}

	/**
	 * Error returned when another operation holds the lock. Says what is running.
	 *
	 * @return WP_Error
	 */
	public static function busy_error() {
		$info = self::current();
		if ( ! $info ) {
			return new WP_Error( 'safespot_locked', __( 'Another SafeSpot backup or restore is running. Please try again in a moment.', 'tbsh-safespot-restore-points' ), array( 'status' => 409 ) );
		}
		return new WP_Error(
			'safespot_locked',
			/* translators: %s: description of the running operation. */
			sprintf( __( 'SafeSpot is busy: %s. Wait for it to finish, or cancel it.', 'tbsh-safespot-restore-points' ), $info['summary'] ),
			array(
				'status' => 409,
				'lock'   => $info,
			)
		);
	}

	/**
	 * Asks the current holder to stop.
	 *
	 * @param string $token Token of the operation to cancel (from current()).
	 * @return bool False when that operation is no longer running.
	 */
	public static function request_cancel( $token ) {
		global $wpdb;

		$info = self::read();
		if ( ! $info || ! hash_equals( (string) $info['token'], (string) $token ) ) {
			return false;
		}

		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s", self::CANCEL ) );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::CANCEL, (string) $token ) );
		return true;
	}

	/**
	 * Whether the operation with this token still holds the lock.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function is_running( $token ) {
		$info = self::read();
		return $info && hash_equals( (string) $info['token'], (string) $token );
	}

	/**
	 * Removes the lock of one specific operation (its process is gone).
	 *
	 * @param string $token Token of the operation.
	 * @return bool Whether a lock was removed.
	 */
	public static function release_token( $token ) {
		global $wpdb;

		$raw  = self::raw();
		$info = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $info ) || ! hash_equals( (string) ( $info['token'] ?? '' ), (string) $token ) ) {
			return false;
		}
		$table   = self::table();
		$removed = (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::NAME, $raw ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s", self::CANCEL ) );
		return $removed;
	}

	/**
	 * Information about the current lock holder, if any.
	 *
	 * @return array|null {
	 *     @type string     $token            Operation token.
	 *     @type string     $context          Current step, e.g. "save:plugin:akismet/akismet.php".
	 *     @type string     $root             Outermost operation, e.g. "queue".
	 *     @type string     $label            What is being done, e.g. "Saving a SafeSpot of Akismet".
	 *     @type string     $job              Outer job label when different (e.g. "Saving SafeSpots in bulk").
	 *     @type string     $phase            Current phase label.
	 *     @type string     $progress_phase   Current phase key.
	 *     @type array|null $progress         { done, total, percent }.
	 *     @type int        $user             User ID.
	 *     @type string     $user_name        Display name.
	 *     @type int        $started          Unix time.
	 *     @type string     $started_human    "3 mins".
	 *     @type int        $last_seen        Seconds since the last heartbeat.
	 *     @type bool       $responsive       Whether the holder showed a sign of life recently.
	 *     @type bool       $cancellable      Whether a cancel request is honoured right now.
	 *     @type bool       $cancel_requested Whether a cancel request is pending.
	 *     @type string     $summary          One-line description.
	 * }
	 */
	public static function current() {
		global $wpdb;

		$info = self::read();
		if ( ! $info ) {
			return null;
		}

		$requested = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name = %s', self::CANCEL ) );
		$user      = ! empty( $info['user'] ) ? get_userdata( (int) $info['user'] ) : false;
		$started   = (int) ( $info['time'] ?? 0 );
		$last_seen = max( 0, time() - self::last_sign_of_life( $info ) );
		$context   = (string) ( $info['context'] ?? '' );
		$root      = (string) ( $info['root'] ?? $context );
		$progress  = is_array( $info['progress'] ?? null ) ? $info['progress'] : null;

		$out = array(
			'token'            => (string) ( $info['token'] ?? '' ),
			'context'          => $context,
			'root'             => $root,
			'label'            => self::describe( $context ),
			'job'              => $root !== $context ? self::describe( $root ) : '',
			'phase'            => $progress ? self::phase_label( $progress['phase'] ?? '' ) : '',
			'progress_phase'   => $progress ? (string) ( $progress['phase'] ?? '' ) : '',
			'progress'         => null,
			'user'             => (int) ( $info['user'] ?? 0 ),
			'user_name'        => $user ? $user->display_name : __( 'System', 'tbsh-safespot-restore-points' ),
			'started'          => $started,
			'started_human'    => human_time_diff( $started, time() ),
			'last_seen'        => $last_seen,
			'responsive'       => $last_seen < self::UNRESPONSIVE_AFTER,
			'cancellable'      => ! empty( $info['cancellable'] ) || ! array_key_exists( 'cancellable', $info ),
			'cancel_requested' => null !== $requested && hash_equals( (string) ( $info['token'] ?? '' ), (string) $requested ),
		);

		if ( $progress && (int) ( $progress['total'] ?? 0 ) > 0 ) {
			$out['progress'] = array(
				'done'    => (int) $progress['done'],
				'total'   => (int) $progress['total'],
				'percent' => (int) min( 100, floor( (int) $progress['done'] / (int) $progress['total'] * 100 ) ),
			);
		}

		$out['summary'] = self::summary( $out );
		return $out;
	}

	/**
	 * Whether this request currently holds the lock.
	 *
	 * @return bool
	 */
	public static function is_owner() {
		return self::$depth > 0;
	}

	/**
	 * Removes the lock unconditionally.
	 */
	public static function force_release() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name IN (%s, %s)", self::NAME, self::CANCEL ) );
		self::reset();
	}

	/**
	 * Raw lock row value.
	 *
	 * @return string|null
	 */
	private static function raw() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name = %s', self::NAME ) );
	}

	/**
	 * Decoded lock row, or null when free (or held by a dead process).
	 *
	 * @return array|null
	 */
	private static function read() {
		$raw  = self::raw();
		$info = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $info ) || self::is_stale( $info ) ) {
			return null;
		}
		return $info;
	}

	/**
	 * Writes this request's state to the lock row.
	 *
	 * @return bool False when the row no longer belongs to this request.
	 */
	private static function write() {
		global $wpdb;

		if ( self::$depth < 1 ) {
			return false;
		}
		$value   = wp_json_encode( self::$state );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$value,
				self::NAME,
				self::$owned
			)
		);
		if ( 1 !== (int) $updated && $value !== self::$owned ) {
			return false;
		}
		self::$owned = $value;
		return true;
	}

	/**
	 * Clears this request's lock state.
	 */
	private static function reset() {
		self::$depth     = 0;
		self::$owned     = '';
		self::$state     = array();
		self::$stack     = array();
		self::$cancelled = false;
		self::$last_beat = 0.0;
	}

	/**
	 * Unix time of the holder's last heartbeat.
	 *
	 * @param array $info Lock row.
	 * @return int
	 */
	private static function last_sign_of_life( array $info ) {
		return max( (int) ( $info['time'] ?? 0 ), (int) ( $info['beat'] ?? 0 ) );
	}

	/**
	 * Whether the holder has shown no sign of life for too long.
	 *
	 * @param array $info Lock row.
	 * @return bool
	 */
	private static function is_stale( array $info ) {
		return ( time() - self::last_sign_of_life( $info ) ) > self::STALE_AFTER;
	}

	/**
	 * Human description of a lock context.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public static function describe( $context ) {
		if ( 'queue' === $context ) {
			return __( 'Saving SafeSpots in bulk', 'tbsh-safespot-restore-points' );
		}

		$parts = explode( ':', (string) $context, 3 );
		if ( 3 !== count( $parts ) ) {
			return __( 'A SafeSpot operation', 'tbsh-safespot-restore-points' );
		}

		list( $action, $type, $slug ) = $parts;
		$item                         = in_array( $type, array( 'plugin', 'theme' ), true ) ? SafeSpot_Items::get( $type, $slug ) : null;
		$name                         = $item ? $item['name'] : $slug;

		if ( 'restore' === $action ) {
			/* translators: %s: plugin or theme name. */
			return sprintf( __( 'Restoring %s', 'tbsh-safespot-restore-points' ), $name );
		}
		/* translators: %s: plugin or theme name. */
		return sprintf( __( 'Saving a SafeSpot of %s', 'tbsh-safespot-restore-points' ), $name );
	}

	/**
	 * Human label of a progress phase.
	 *
	 * @param string $phase Phase key.
	 * @return string
	 */
	public static function phase_label( $phase ) {
		$labels = array(
			'scanning'    => __( 'scanning files', 'tbsh-safespot-restore-points' ),
			'fingerprint' => __( 'checking for changes', 'tbsh-safespot-restore-points' ),
			'settings'    => __( 'saving settings', 'tbsh-safespot-restore-points' ),
			'archiving'   => __( 'adding files to the archive', 'tbsh-safespot-restore-points' ),
			'compressing' => __( 'compressing the archive', 'tbsh-safespot-restore-points' ),
			'verifying'   => __( 'verifying the archive', 'tbsh-safespot-restore-points' ),
			'preparing'   => __( 'running pre-restore checks', 'tbsh-safespot-restore-points' ),
			'safety'      => __( 'saving a safety SafeSpot', 'tbsh-safespot-restore-points' ),
			'replacing'   => __( 'replacing files', 'tbsh-safespot-restore-points' ),
			'health'      => __( 'checking that the site still loads', 'tbsh-safespot-restore-points' ),
		);
		return $labels[ $phase ] ?? '';
	}

	/**
	 * One-line description of a running operation.
	 *
	 * @param array $info Public info (see current()).
	 * @return string
	 */
	private static function summary( array $info ) {
		$text = $info['label'];
		if ( '' !== $info['job'] ) {
			$text .= ' (' . $info['job'] . ')';
		}
		if ( '' !== $info['phase'] ) {
			$text .= ' – ' . $info['phase'];
			if ( $info['progress'] && 'compressing' === $info['progress_phase'] ) {
				$text .= ' ' . $info['progress']['percent'] . '%';
			} elseif ( $info['progress'] ) {
				$text .= ' ' . sprintf(
					/* translators: 1: done, 2: total, 3: percent. */
					__( '(%1$s of %2$s, %3$d%%)', 'tbsh-safespot-restore-points' ),
					number_format_i18n( $info['progress']['done'] ),
					number_format_i18n( $info['progress']['total'] ),
					$info['progress']['percent']
				);
			}
		}
		$text .= ' – ' . sprintf(
			/* translators: 1: time span, e.g. "3 mins", 2: user name. */
			__( 'started %1$s ago by %2$s', 'tbsh-safespot-restore-points' ),
			$info['started_human'],
			$info['user_name']
		);
		if ( ! $info['responsive'] ) {
			/* translators: %s: time span. */
			$text .= ' – ' . sprintf( __( 'not responding for %s', 'tbsh-safespot-restore-points' ), human_time_diff( time() - $info['last_seen'], time() ) );
		}
		return $text;
	}
}
