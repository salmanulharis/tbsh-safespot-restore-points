<?php
/**
 * Installed plugin/theme lookup.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes plugins and themes into one "item" shape.
 */
class SafeSpot_Items {

	/**
	 * Loads admin helpers needed outside wp-admin (cron, REST, CLI).
	 */
	private static function load_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
	}

	/**
	 * All installed plugins.
	 *
	 * @return array[] Keyed by plugin file.
	 */
	public static function plugins() {
		self::load_dependencies();
		$updates = get_site_transient( 'update_plugins' );
		$items   = array();

		foreach ( get_plugins() as $file => $data ) {
			$items[ $file ] = self::plugin_item( $file, $data, $updates );
		}
		return $items;
	}

	/**
	 * All installed themes.
	 *
	 * @return array[] Keyed by stylesheet.
	 */
	public static function themes() {
		self::load_dependencies();
		$updates = get_site_transient( 'update_themes' );
		$items   = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[ $stylesheet ] = self::theme_item( $theme, $updates );
		}
		return $items;
	}

	/**
	 * One installed item, or null.
	 *
	 * @param string $type Item type.
	 * @param string $slug Plugin file or theme stylesheet.
	 * @return array|null
	 */
	public static function get( $type, $slug ) {
		self::load_dependencies();
		$slug = (string) $slug;

		if ( 'plugin' === $type ) {
			if ( '' === $slug || 0 !== validate_file( $slug ) ) {
				return null;
			}
			$plugins = get_plugins();
			if ( ! isset( $plugins[ $slug ] ) ) {
				return null;
			}
			return self::plugin_item( $slug, $plugins[ $slug ], get_site_transient( 'update_plugins' ) );
		}

		if ( 'theme' === $type ) {
			if ( '' === $slug || 0 !== validate_file( $slug ) || false !== strpos( $slug, '/' ) ) {
				return null;
			}
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return null;
			}
			return self::theme_item( $theme, get_site_transient( 'update_themes' ) );
		}

		return null;
	}

	/**
	 * Resolves user input ("akismet", "akismet/akismet.php", "hello") to a plugin file.
	 *
	 * @param string $input Input.
	 * @return string|null
	 */
	public static function resolve_plugin( $input ) {
		self::load_dependencies();
		$input   = trim( (string) $input );
		$plugins = get_plugins();

		if ( isset( $plugins[ $input ] ) ) {
			return $input;
		}
		foreach ( array_keys( $plugins ) as $file ) {
			$dir = dirname( $file );
			if ( ( '.' !== $dir && $dir === $input ) || ( '.' === $dir && basename( $file, '.php' ) === $input ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Builds a plugin item.
	 *
	 * @param string $file    Plugin file.
	 * @param array  $data    Header data.
	 * @param mixed  $updates update_plugins transient.
	 * @return array
	 */
	private static function plugin_item( $file, array $data, $updates ) {
		$single  = false === strpos( $file, '/' );
		$network = is_multisite() && is_plugin_active_for_network( $file );
		$active  = $network || is_plugin_active( $file );
		$update  = '';
		if ( is_object( $updates ) && isset( $updates->response[ $file ]->new_version ) ) {
			$update = (string) $updates->response[ $file ]->new_version;
		}

		if ( $network ) {
			$status = 'network';
		} elseif ( $active ) {
			$status = 'active';
		} else {
			$status = 'inactive';
		}

		return array(
			'type'         => 'plugin',
			'slug'         => $file,
			'folder'       => $single ? basename( $file, '.php' ) : dirname( $file ),
			'name'         => '' !== $data['Name'] ? wp_strip_all_tags( $data['Name'] ) : $file,
			'version'      => (string) $data['Version'],
			'author'       => wp_strip_all_tags( (string) $data['Author'] ),
			'path'         => wp_normalize_path( WP_PLUGIN_DIR . '/' . ( $single ? $file : dirname( $file ) ) ),
			'single_file'  => $single,
			'status'       => $status,
			'active'       => $active,
			'network'      => $network,
			'requires_wp'  => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
			'requires_php' => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
			'parent'       => '',
			'update'       => $update,
		);
	}

	/**
	 * Builds a theme item.
	 *
	 * @param WP_Theme $theme   Theme.
	 * @param mixed    $updates update_themes transient.
	 * @return array
	 */
	private static function theme_item( WP_Theme $theme, $updates ) {
		$stylesheet = $theme->get_stylesheet();
		$template   = $theme->get_template();
		$update     = '';
		if ( is_object( $updates ) && isset( $updates->response[ $stylesheet ]['new_version'] ) ) {
			$update = (string) $updates->response[ $stylesheet ]['new_version'];
		}

		if ( get_stylesheet() === $stylesheet ) {
			$status = 'active';
		} elseif ( get_template() === $stylesheet ) {
			$status = 'parent';
		} else {
			$status = 'inactive';
		}

		return array(
			'type'         => 'theme',
			'slug'         => $stylesheet,
			'folder'       => $stylesheet,
			'name'         => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
			'version'      => (string) $theme->get( 'Version' ),
			'author'       => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'path'         => wp_normalize_path( $theme->get_stylesheet_directory() ),
			'single_file'  => false,
			'status'       => $status,
			'active'       => 'inactive' !== $status,
			'network'      => is_multisite() && $theme->is_allowed( 'network' ),
			'requires_wp'  => (string) $theme->get( 'RequiresWP' ),
			'requires_php' => (string) $theme->get( 'RequiresPHP' ),
			'parent'       => $template !== $stylesheet ? $template : '',
			'update'       => $update,
		);
	}

	/**
	 * Localized status label.
	 *
	 * @param array $item Item.
	 * @return string
	 */
	public static function status_label( array $item ) {
		$labels = array(
			'network'       => __( 'Network active', 'tbsh-safespot-restore-points' ),
			'active'        => 'theme' === $item['type'] ? __( 'Active theme', 'tbsh-safespot-restore-points' ) : __( 'Active', 'tbsh-safespot-restore-points' ),
			'parent'        => __( 'Parent of active theme', 'tbsh-safespot-restore-points' ),
			'inactive'      => __( 'Inactive', 'tbsh-safespot-restore-points' ),
			'not_installed' => __( 'Not installed', 'tbsh-safespot-restore-points' ),
		);
		return isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : '';
	}

	/**
	 * Placeholder item for something that has SafeSpots but is no longer installed.
	 *
	 * @param string $type  Item type.
	 * @param string $slug  Slug.
	 * @param string $name  Last known name.
	 * @return array
	 */
	public static function missing_item( $type, $slug, $name ) {
		return array(
			'type'         => $type,
			'slug'         => $slug,
			'folder'       => 'plugin' === $type ? ( false === strpos( $slug, '/' ) ? basename( $slug, '.php' ) : dirname( $slug ) ) : $slug,
			'name'         => '' !== $name ? $name : $slug,
			'version'      => '',
			'author'       => '',
			'path'         => '',
			'single_file'  => 'plugin' === $type && false === strpos( $slug, '/' ),
			'status'       => 'not_installed',
			'active'       => false,
			'network'      => false,
			'requires_wp'  => '',
			'requires_php' => '',
			'parent'       => '',
			'update'       => '',
		);
	}
}
