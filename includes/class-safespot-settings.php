<?php
/**
 * Settings storage.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Network-wide settings stored in a single site option.
 */
class SafeSpot_Settings {

	const OPTION = 'safespot_settings';

	/**
	 * Option-name prefixes that are too generic to be exported as "plugin settings".
	 *
	 * @var string[]
	 */
	const RESERVED_PREFIXES = array( 'wp', 'wp_', 'wp-', '_tr', '_transient', '_site_transient', 'site', 'home', 'theme', 'widget', 'user', 'cron', 'active', 'auto', 'admin', 'blog', 'default', 'upload', 'perma', 'page', 'post', 'mail', 'rewrite', 'stylesheet', 'template', 'sidebars', 'can', 'db_', 'initial', 'fresh', 'recently', 'uninstall', 'safespot' );

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'auto_backup'             => true,
			'auto_backup_plugins'     => true,
			'auto_backup_themes'      => true,
			'backup_before_delete'    => false,
			'abort_update_on_failure' => false,
			'retention_count'         => 3,
			'max_age_days'            => 0,
			'settings_snapshot'       => false,
			'health_check'            => true,
			'email_on_failure'        => true,
			'notify_email'            => '',
			'log_retention_days'      => 90,
			'delete_on_uninstall'     => false,
			'excluded'                => array(),
			'option_prefixes'         => array(),
			'holds'                   => array(),
		);
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_site_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Saves raw settings (internal use; values must already be sanitized).
	 *
	 * @param array $settings Full settings array.
	 */
	private static function save( array $settings ) {
		self::$cache = wp_parse_args( $settings, self::defaults() );
		update_site_option( self::OPTION, self::$cache );
	}

	/**
	 * Sanitizes and saves the user-editable settings.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$settings = self::all();

		$booleans = array( 'auto_backup', 'auto_backup_plugins', 'auto_backup_themes', 'backup_before_delete', 'abort_update_on_failure', 'settings_snapshot', 'health_check', 'email_on_failure', 'delete_on_uninstall' );
		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$settings[ $key ] = rest_sanitize_boolean( $input[ $key ] );
			}
		}

		if ( isset( $input['retention_count'] ) ) {
			$settings['retention_count'] = min( 100, max( 0, absint( $input['retention_count'] ) ) );
		}
		if ( isset( $input['max_age_days'] ) ) {
			$settings['max_age_days'] = min( 3650, absint( $input['max_age_days'] ) );
		}
		if ( isset( $input['log_retention_days'] ) ) {
			$settings['log_retention_days'] = min( 3650, absint( $input['log_retention_days'] ) );
		}
		if ( isset( $input['notify_email'] ) ) {
			$email                    = sanitize_email( $input['notify_email'] );
			$settings['notify_email'] = is_email( $email ) ? $email : '';
		}

		self::save( $settings );
		return self::all();
	}

	/**
	 * Builds the settings key for an item.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return string
	 */
	public static function item_key( $type, $slug ) {
		return $type . ':' . $slug;
	}

	/**
	 * Whether an item is excluded from automatic SafeSpots.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return bool
	 */
	public static function is_excluded( $type, $slug ) {
		return in_array( self::item_key( $type, $slug ), (array) self::get( 'excluded' ), true );
	}

	/**
	 * Whether automatic SafeSpots are turned on for a type (main setting and the type setting).
	 *
	 * Per-item exclusions only apply on top of this.
	 *
	 * @param string $type Item type.
	 * @return bool
	 */
	public static function auto_backup_enabled( $type ) {
		return (bool) self::get( 'auto_backup' ) && (bool) self::get( 'plugin' === $type ? 'auto_backup_plugins' : 'auto_backup_themes' );
	}

	/**
	 * Includes or excludes an item from automatic SafeSpots.
	 *
	 * @param string $type     Item type.
	 * @param string $slug     Item slug.
	 * @param bool   $excluded Whether to exclude.
	 */
	public static function set_excluded( $type, $slug, $excluded ) {
		$settings = self::all();
		$key      = self::item_key( $type, $slug );
		$list     = array_values( array_diff( (array) $settings['excluded'], array( $key ) ) );
		if ( $excluded ) {
			$list[] = $key;
		}
		$settings['excluded'] = $list;
		self::save( $settings );
	}

	/**
	 * Default option prefix for an item, derived from its folder name.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return string[]
	 */
	public static function default_prefixes( $type, $slug ) {
		$base = 'plugin' === $type ? dirname( $slug ) : $slug;
		if ( '.' === $base ) {
			$base = basename( $slug, '.php' );
		}
		$underscored = str_replace( '-', '_', strtolower( $base ) );
		$prefixes    = array_unique( array( $underscored . '_', $underscored ) );
		return array_values( array_filter( $prefixes, array( __CLASS__, 'is_valid_prefix' ) ) );
	}

	/**
	 * Option prefixes for an item (user-defined or default).
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return string[]
	 */
	public static function get_prefixes( $type, $slug ) {
		$all = (array) self::get( 'option_prefixes' );
		$key = self::item_key( $type, $slug );
		if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		return self::default_prefixes( $type, $slug );
	}

	/**
	 * Saves custom option prefixes for an item.
	 *
	 * @param string       $type     Item type.
	 * @param string       $slug     Item slug.
	 * @param string|array $prefixes Comma-separated string or array.
	 * @return string[] Sanitized prefixes.
	 */
	public static function set_prefixes( $type, $slug, $prefixes ) {
		if ( ! is_array( $prefixes ) ) {
			$prefixes = explode( ',', (string) $prefixes );
		}
		$clean = array();
		foreach ( $prefixes as $prefix ) {
			$prefix = trim( (string) $prefix );
			if ( self::is_valid_prefix( $prefix ) ) {
				$clean[] = $prefix;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		$settings = self::all();
		$all      = (array) $settings['option_prefixes'];
		$key      = self::item_key( $type, $slug );
		if ( self::default_prefixes( $type, $slug ) === $clean ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = $clean;
		}
		$settings['option_prefixes'] = $all;
		self::save( $settings );

		return $clean;
	}

	/**
	 * Validates an option-name prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	public static function is_valid_prefix( $prefix ) {
		if ( strlen( $prefix ) < 3 || strlen( $prefix ) > 100 ) {
			return false;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-\.]+$/', $prefix ) ) {
			return false;
		}
		$lower = strtolower( $prefix );
		foreach ( self::RESERVED_PREFIXES as $reserved ) {
			if ( $lower === $reserved || rtrim( $lower, '_-' ) === rtrim( $reserved, '_-' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the update hold for an item, if any.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return array|null
	 */
	public static function get_hold( $type, $slug ) {
		$holds = (array) self::get( 'holds' );
		$key   = self::item_key( $type, $slug );
		return isset( $holds[ $key ] ) ? $holds[ $key ] : null;
	}

	/**
	 * Pauses or resumes updates for an item.
	 *
	 * @param string $type    Item type.
	 * @param string $slug    Item slug.
	 * @param bool   $hold    Whether updates should be paused.
	 * @param string $version Version that is being held.
	 */
	public static function set_hold( $type, $slug, $hold, $version = '' ) {
		$settings = self::all();
		$holds    = (array) $settings['holds'];
		$key      = self::item_key( $type, $slug );
		if ( $hold ) {
			$holds[ $key ] = array(
				'version' => sanitize_text_field( $version ),
				'since'   => time(),
				'user'    => get_current_user_id(),
			);
		} else {
			unset( $holds[ $key ] );
		}
		$settings['holds'] = $holds;
		self::save( $settings );
	}

	/**
	 * Address that receives failure notifications.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = (string) self::get( 'notify_email' );
		if ( is_email( $email ) ) {
			return $email;
		}
		return is_multisite() ? (string) get_site_option( 'admin_email' ) : (string) get_option( 'admin_email' );
	}

	/**
	 * Clears the in-memory cache (used after external option changes).
	 */
	public static function flush() {
		self::$cache = null;
	}
}
