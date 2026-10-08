<?php
/**
 * Global operation lock.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name comes from $wpdb->base_prefix; all values go through $wpdb->prepare().

/**
 * Prevents two backups/restores from running at the same time.
 *
 * Uses an atomic INSERT IGNORE on the main site's options table (unique option_name),
 * the same technique as WP_Upgrader::create_lock(), but shared across the network.
 * The lock is re-entrant within one request and released on shutdown, even after a
 * fatal error.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * -- Atomic lock row must bypass the options cache.
 */
class SafeSpot_Lock {

	const NAME = 'safespot_operation_lock';
	const TTL  = 1800;

	/**
	 * Re-entrancy depth for the current request.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Raw value written by this request.
	 *
	 * @var string
	 */
	private static $owned = '';

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
	 * @param string $context Description of the operation (shown to other users).
	 * @param int    $wait    Seconds to wait for a busy lock.
	 * @return bool
	 */
	public static function acquire( $context = '', $wait = 0 ) {
		global $wpdb;

		if ( self::$depth > 0 ) {
			++self::$depth;
			return true;
		}

		$table    = self::table();
		$deadline = time() + max( 0, (int) $wait );
		$attempts = 0;

		while ( $attempts < 600 ) {
			++$attempts;
			$value = wp_json_encode(
				array(
					'time'    => time(),
					'context' => (string) $context,
					'user'    => get_current_user_id(),
					'token'   => wp_generate_password( 12, false, false ),
				)
			);

			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::NAME,
					$value
				)
			);

			if ( $inserted ) {
				self::$depth = 1;
				self::$owned = $value;
				if ( ! self::$shutdown_registered ) {
					register_shutdown_function( array( __CLASS__, 'shutdown' ) );
					self::$shutdown_registered = true;
				}
				return true;
			}

			$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", self::NAME ) );
			$info = $raw ? json_decode( $raw, true ) : null;

			if ( null === $raw ) {
				continue;
			}

			if ( ! is_array( $info ) || ( time() - (int) ( $info['time'] ?? 0 ) ) > self::TTL ) {
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
			return;
		}
		if ( 1 === self::$depth ) {
			$table = self::table();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::NAME, self::$owned ) );
		}
		self::$depth = 0;
		self::$owned = '';
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
	 * Information about the current lock holder, if any.
	 *
	 * @return array|null
	 */
	public static function current() {
		global $wpdb;
		$table = self::table();
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", self::NAME ) );
		$info  = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $info ) || ( time() - (int) ( $info['time'] ?? 0 ) ) > self::TTL ) {
			return null;
		}
		return $info;
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
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s", self::NAME ) );
		self::$depth = 0;
		self::$owned = '';
	}
}
