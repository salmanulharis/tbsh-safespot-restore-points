<?php
/**
 * Backup storage location.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the protected wp-content/uploads/safespot-backups/{random-hash}/ folder.
 */
class SafeSpot_Storage {

	const KEY_OPTION = 'safespot_storage_key';

	/**
	 * Resolved storage directory (trailing slash).
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * Direct filesystem instance used for files we own inside uploads.
	 *
	 * @var WP_Filesystem_Direct|null
	 */
	private static $fs = null;

	/**
	 * Returns a direct filesystem handler.
	 *
	 * Backups live inside uploads, which PHP must already be able to write for media,
	 * so the direct method is used here regardless of FS_METHOD.
	 *
	 * @return WP_Filesystem_Direct
	 */
	public static function fs() {
		if ( null === self::$fs ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			// Same defaults WP_Filesystem() defines; needed when the direct class is used on its own.
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			if ( ! defined( 'FS_CHMOD_DIR' ) ) {
				define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
			}
			if ( ! defined( 'FS_CHMOD_FILE' ) ) {
				define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
			}
			// phpcs:enable
			self::$fs = new WP_Filesystem_Direct( null );
		}
		return self::$fs;
	}

	/**
	 * Base folder: {main site uploads}/safespot-backups.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$switched = false;
		if ( is_multisite() && ! is_main_site() ) {
			switch_to_blog( get_main_site_id() );
			$switched = true;
		}
		$uploads = wp_upload_dir( null, false );
		if ( $switched ) {
			restore_current_blog();
		}
		return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'safespot-backups';
	}

	/**
	 * Random, unguessable folder name.
	 *
	 * @return string
	 */
	private static function key() {
		$key = get_site_option( self::KEY_OPTION );
		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9]{20,64}$/', $key ) ) {
			$key = strtolower( wp_generate_password( 32, false, false ) );
			update_site_option( self::KEY_OPTION, $key );
		}
		return $key;
	}

	/**
	 * Returns the storage directory, creating and protecting it when needed.
	 *
	 * @return string|WP_Error Absolute path with trailing slash.
	 */
	public static function get_dir() {
		if ( null !== self::$dir ) {
			return self::$dir;
		}

		$default = self::base_dir() . '/' . self::key();

		/**
		 * Filters the absolute path of the folder where SafeSpot archives are stored.
		 *
		 * Point this outside the web root for maximum protection on servers that ignore
		 * .htaccess / web.config (for example nginx).
		 *
		 * @param string $path Absolute path without trailing slash.
		 */
		$path = untrailingslashit( wp_normalize_path( (string) apply_filters( 'safespot_storage_path', $default ) ) );

		if ( '' === $path || ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'safespot_storage_unavailable', __( 'SafeSpot could not create its storage folder. Check that the uploads folder is writable.', 'tbsh-safespot-restore-points' ) );
		}

		self::protect( $path );
		if ( $path === $default ) {
			self::protect( self::base_dir() );
		}

		if ( ! wp_is_writable( $path ) ) {
			return new WP_Error( 'safespot_storage_unwritable', __( 'The SafeSpot storage folder is not writable.', 'tbsh-safespot-restore-points' ) );
		}

		self::$dir = trailingslashit( $path );
		return self::$dir;
	}

	/**
	 * Writes deny-all files for Apache, IIS and directory listing.
	 *
	 * @param string $dir Directory.
	 */
	public static function protect( $dir ) {
		$fs  = self::fs();
		$dir = trailingslashit( $dir );

		$files = array(
			'.htaccess'  => "# Deny all direct access to SafeSpot archives.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<hiddenSegments>\n\t\t\t\t\t<add segment=\"\" />\n\t\t\t\t</hiddenSegments>\n\t\t\t</requestFiltering>\n\t\t</security>\n\t\t<directoryBrowse enabled=\"false\" />\n\t</system.webServer>\n</configuration>\n",
			'index.html' => '',
		);

		foreach ( $files as $name => $contents ) {
			if ( ! $fs->exists( $dir . $name ) ) {
				$fs->put_contents( $dir . $name, $contents, FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * Whether every protection file exists in the storage folder.
	 *
	 * @return bool
	 */
	public static function is_protected() {
		$dir = self::get_dir();
		if ( is_wp_error( $dir ) ) {
			return false;
		}
		foreach ( array( '.htaccess', 'index.php', 'web.config' ) as $file ) {
			if ( ! file_exists( $dir . $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Absolute path of a stored archive. Rejects anything that is not a plain file name.
	 *
	 * @param string $file_name Stored file name.
	 * @return string|WP_Error
	 */
	public static function path( $file_name ) {
		$file_name = (string) $file_name;
		if ( '' === $file_name || basename( $file_name ) !== $file_name || false !== strpos( $file_name, '..' ) || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $file_name ) ) {
			return new WP_Error( 'safespot_bad_file_name', __( 'Invalid SafeSpot file name.', 'tbsh-safespot-restore-points' ) );
		}
		$dir = self::get_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		return $dir . $file_name;
	}

	/**
	 * Builds a unique archive file name.
	 *
	 * @param string $type    Item type.
	 * @param string $slug    Item folder slug.
	 * @param string $version Item version.
	 * @param string $ext     Extension without dot.
	 * @return string
	 */
	public static function unique_name( $type, $slug, $version, $ext = 'zip' ) {
		$clean = static function ( $value, $fallback ) {
			$value = preg_replace( '/[^A-Za-z0-9._\-]+/', '-', (string) $value );
			$value = trim( (string) $value, '.-' );
			return '' === $value ? $fallback : substr( $value, 0, 80 );
		};

		return sprintf(
			'%s-%s-%s-%s-%s.%s',
			$type,
			$clean( $slug, 'item' ),
			$clean( $version, '0' ),
			gmdate( 'Ymd-His' ),
			strtolower( wp_generate_password( 8, false, false ) ),
			$ext
		);
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $file_name File name.
	 * @return bool
	 */
	public static function delete( $file_name ) {
		if ( '' === (string) $file_name ) {
			return true;
		}
		$path = self::path( $file_name );
		if ( is_wp_error( $path ) ) {
			return false;
		}
		$fs = self::fs();
		return ! $fs->exists( $path ) || $fs->delete( $path );
	}

	/**
	 * Scratch folder for partial archives.
	 *
	 * @return string|WP_Error
	 */
	public static function temp_dir() {
		$dir = self::get_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$tmp = $dir . 'tmp';
		if ( ! wp_mkdir_p( $tmp ) ) {
			return new WP_Error( 'safespot_tmp_unavailable', __( 'SafeSpot could not create its temporary folder.', 'tbsh-safespot-restore-points' ) );
		}
		self::protect( $tmp );
		return trailingslashit( $tmp );
	}

	/**
	 * Removes temporary files older than a day.
	 */
	public static function cleanup_temp() {
		$tmp = self::temp_dir();
		if ( is_wp_error( $tmp ) ) {
			return;
		}
		$fs   = self::fs();
		$list = $fs->dirlist( $tmp, false );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( $list as $name => $info ) {
			if ( in_array( $name, array( '.htaccess', 'index.php', 'web.config', 'index.html' ), true ) ) {
				continue;
			}
			if ( isset( $info['lastmodunix'] ) && (int) $info['lastmodunix'] < time() - DAY_IN_SECONDS ) {
				$fs->delete( $tmp . $name, true );
			}
		}
	}

	/**
	 * Free disk space in bytes, or null when the host does not expose it.
	 *
	 * @param string $path Path to check (defaults to the storage folder).
	 * @return float|null
	 */
	public static function free_space( $path = '' ) {
		if ( '' === $path ) {
			$dir  = self::get_dir();
			$path = is_wp_error( $dir ) ? WP_CONTENT_DIR : $dir;
		}
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled or restricted on some hosts.
		return false === $free ? null : (float) $free;
	}

	/**
	 * Storage path relative to ABSPATH, for display.
	 *
	 * @return string
	 */
	public static function display_path() {
		$dir = self::get_dir();
		if ( is_wp_error( $dir ) ) {
			return '';
		}
		$abs = trailingslashit( wp_normalize_path( ABSPATH ) );
		return 0 === strpos( $dir, $abs ) ? substr( $dir, strlen( $abs ) ) : $dir;
	}

	/**
	 * Recursively deletes the whole storage tree (used on uninstall).
	 */
	public static function destroy() {
		$fs  = self::fs();
		$dir = self::get_dir();
		if ( ! is_wp_error( $dir ) ) {
			$fs->delete( $dir, true );
		}
		$base = self::base_dir();
		if ( $fs->is_dir( $base ) ) {
			$fs->delete( $base, true );
		}
		self::$dir = null;
	}
}
