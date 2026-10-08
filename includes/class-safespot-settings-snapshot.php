<?php
/**
 * Optional wp_options snapshot.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports and imports option rows that belong to a plugin or theme.
 *
 * Values are stored and restored as raw serialized strings and written back with
 * $wpdb, so snapshot data is never unserialized by SafeSpot. Custom database tables
 * are intentionally not included.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 * -- Raw option rows are needed to round-trip values byte-for-byte; caches are flushed after writes.
 */
class SafeSpot_Settings_Snapshot {

	const FORMAT = 1;

	/**
	 * Builds the snapshot for an item.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return array
	 */
	public static function export( $type, $slug ) {
		global $wpdb;

		$prefixes = SafeSpot_Settings::get_prefixes( $type, $slug );
		$options  = array();
		$network  = array();

		foreach ( $prefixes as $prefix ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				if ( self::is_protected_name( $row['option_name'] ) ) {
					continue;
				}
				$options[ $row['option_name'] ] = array(
					'value'    => $row['option_value'],
					'autoload' => $row['autoload'],
				);
			}

			if ( is_multisite() ) {
				$meta = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s",
						get_current_network_id(),
						$wpdb->esc_like( $prefix ) . '%'
					),
					ARRAY_A
				);
				foreach ( (array) $meta as $row ) {
					if ( self::is_protected_name( $row['meta_key'] ) ) {
						continue;
					}
					$network[ $row['meta_key'] ] = $row['meta_value'];
				}
			}
		}

		if ( 'theme' === $type ) {
			$mods_name = 'theme_mods_' . $slug;
			$row       = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $mods_name ), ARRAY_A );
			if ( $row ) {
				$options[ $mods_name ] = array(
					'value'    => $row['option_value'],
					'autoload' => $row['autoload'],
				);
			}
		}

		return array(
			'format'          => self::FORMAT,
			'type'            => $type,
			'slug'            => $slug,
			'prefixes'        => $prefixes,
			'site_id'         => get_current_blog_id(),
			'created_at'      => gmdate( 'c' ),
			'options'         => $options,
			'network_options' => $network,
		);
	}

	/**
	 * Number of rows in a snapshot.
	 *
	 * @param array $snapshot Snapshot.
	 * @return int
	 */
	public static function count( array $snapshot ) {
		return count( (array) ( $snapshot['options'] ?? array() ) ) + count( (array) ( $snapshot['network_options'] ?? array() ) );
	}

	/**
	 * Writes snapshot rows back to the database.
	 *
	 * Only option names that match the snapshot's own prefixes (or the theme's
	 * theme_mods row) are accepted, so a tampered file cannot overwrite core options.
	 *
	 * @param array  $snapshot Snapshot.
	 * @param string $type     Expected item type.
	 * @param string $slug     Expected slug.
	 * @return int|WP_Error Number of rows written.
	 */
	public static function import( array $snapshot, $type, $slug ) {
		global $wpdb;

		if ( (int) ( $snapshot['format'] ?? 0 ) !== self::FORMAT || ( $snapshot['type'] ?? '' ) !== $type || ( $snapshot['slug'] ?? '' ) !== $slug ) {
			return new WP_Error( 'safespot_settings_mismatch', __( 'The settings snapshot does not belong to this item.', 'tbsh-safespot-restore-points' ) );
		}

		$prefixes = array_filter( (array) ( $snapshot['prefixes'] ?? array() ), array( 'SafeSpot_Settings', 'is_valid_prefix' ) );
		$allowed  = static function ( $name ) use ( $prefixes, $type, $slug ) {
			if ( 'theme' === $type && 'theme_mods_' . $slug === $name ) {
				return true;
			}
			if ( self::is_protected_name( $name ) ) {
				return false;
			}
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
			return false;
		};

		$written = 0;

		foreach ( (array) ( $snapshot['options'] ?? array() ) as $name => $row ) {
			$name = (string) $name;
			if ( ! $allowed( $name ) || ! is_array( $row ) || ! isset( $row['value'] ) ) {
				continue;
			}
			$autoload = isset( $row['autoload'] ) ? substr( sanitize_key( $row['autoload'] ), 0, 20 ) : 'yes';
			$ok       = $wpdb->replace(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => (string) $row['value'],
					'autoload'     => '' !== $autoload ? $autoload : 'yes',
				),
				array( '%s', '%s', '%s' )
			);
			if ( false !== $ok ) {
				++$written;
				wp_cache_delete( $name, 'options' );
			}
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( is_multisite() ) {
			$network_id = get_current_network_id();
			foreach ( (array) ( $snapshot['network_options'] ?? array() ) as $name => $value ) {
				$name = (string) $name;
				if ( ! $allowed( $name ) ) {
					continue;
				}
				$wpdb->delete(
					$wpdb->sitemeta,
					array(
						'site_id'  => $network_id,
						'meta_key' => $name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact key lookup.
					),
					array( '%d', '%s' )
				);
				$ok = $wpdb->insert(
					$wpdb->sitemeta,
					array(
						'site_id'    => $network_id,
						'meta_key'   => $name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Insert.
						'meta_value' => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Insert.
					),
					array( '%d', '%s', '%s' )
				);
				if ( false !== $ok ) {
					++$written;
					wp_cache_delete( $network_id . ':' . $name, 'site-options' );
				}
			}
		}

		return $written;
	}

	/**
	 * Options SafeSpot never exports or overwrites.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function is_protected_name( $name ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' ) ) {
			return true;
		}
		if ( in_array( $name, array( 'safespot_settings', 'safespot_db_version', 'safespot_storage_key', 'safespot_operation_lock', 'safespot_queue' ), true ) ) {
			return true;
		}
		return in_array( $name, array( 'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'cron', 'admin_email', 'users_can_register', 'default_role', 'db_version', 'initial_db_version', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt' ), true ) || false !== strpos( $name, 'user_roles' );
	}

	/**
	 * Saves a snapshot to the storage folder.
	 *
	 * @param array  $snapshot  Snapshot.
	 * @param string $file_name File name.
	 * @return array|WP_Error { file_name, checksum, count }.
	 */
	public static function write( array $snapshot, $file_name ) {
		$path = SafeSpot_Storage::path( $file_name );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$json = wp_json_encode( $snapshot );
		if ( false === $json || ! SafeSpot_Storage::fs()->put_contents( $path, $json, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'safespot_settings_write', __( 'Could not write the settings snapshot.', 'tbsh-safespot-restore-points' ) );
		}
		return array(
			'file_name' => $file_name,
			'checksum'  => hash_file( 'sha256', $path ),
			'count'     => self::count( $snapshot ),
		);
	}

	/**
	 * Loads and verifies a snapshot file.
	 *
	 * @param array $point SafeSpot row.
	 * @return array|WP_Error
	 */
	public static function read( array $point ) {
		if ( '' === $point['settings_file'] ) {
			return new WP_Error( 'safespot_no_settings', __( 'This SafeSpot has no settings snapshot.', 'tbsh-safespot-restore-points' ) );
		}
		$path = SafeSpot_Storage::path( $point['settings_file'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'safespot_settings_missing', __( 'The settings snapshot file is missing.', 'tbsh-safespot-restore-points' ) );
		}
		if ( ! hash_equals( (string) $point['settings_checksum'], (string) hash_file( 'sha256', $path ) ) ) {
			return new WP_Error( 'safespot_settings_corrupt', __( 'The settings snapshot failed its checksum and will not be used.', 'tbsh-safespot-restore-points' ) );
		}
		$data = json_decode( (string) SafeSpot_Storage::fs()->get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'safespot_settings_corrupt', __( 'The settings snapshot could not be read.', 'tbsh-safespot-restore-points' ) );
		}
		return $data;
	}
}
