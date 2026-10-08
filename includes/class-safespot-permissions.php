<?php
/**
 * Capability checks.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central place for every permission decision.
 *
 * - View / save SafeSpots: activate_plugins (plugins) or switch_themes (themes).
 * - Restore / delete:       additionally install_plugins or install_themes.
 * - On multisite:           additionally manage_network_plugins / manage_network_themes
 *                           (network administrators only).
 */
class SafeSpot_Permissions {

	/**
	 * Whether the current user may perform an action.
	 *
	 * @param string $action view|save|restore|delete|settings.
	 * @param string $type   plugin|theme ('' = any type).
	 * @return bool
	 */
	public static function can( $action, $type = '' ) {
		if ( '' === $type ) {
			$allowed = self::can( $action, 'plugin' ) || self::can( $action, 'theme' );
		} elseif ( 'settings' === $action ) {
			$allowed = current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
		} else {
			$allowed = self::check_caps( $action, $type );
		}

		/**
		 * Filters whether the current user may perform a SafeSpot action.
		 *
		 * @param bool   $allowed Whether the action is allowed.
		 * @param string $action  view|save|restore|delete|settings.
		 * @param string $type    plugin|theme or empty.
		 */
		return (bool) apply_filters( 'safespot_user_can', $allowed, $action, $type );
	}

	/**
	 * Core capability mapping.
	 *
	 * @param string $action Action.
	 * @param string $type   Type.
	 * @return bool
	 */
	private static function check_caps( $action, $type ) {
		$is_plugin = 'plugin' === $type;

		$caps = array( $is_plugin ? 'activate_plugins' : 'switch_themes' );
		if ( in_array( $action, array( 'restore', 'delete' ), true ) ) {
			$caps[] = $is_plugin ? 'install_plugins' : 'install_themes';
		}
		if ( is_multisite() ) {
			$caps[] = $is_plugin ? 'manage_network_plugins' : 'manage_network_themes';
		}

		foreach ( $caps as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Capability used to register the admin menu.
	 *
	 * @return string
	 */
	public static function menu_capability() {
		if ( is_multisite() ) {
			return current_user_can( 'manage_network_plugins' ) ? 'manage_network_plugins' : 'manage_network_themes';
		}
		return current_user_can( 'activate_plugins' ) ? 'activate_plugins' : 'switch_themes';
	}
}
