<?php
/**
 * Class autoloader.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps SafeSpot_* class names to files in includes/ and admin/.
 */
class SafeSpot_Autoloader {

	/**
	 * Registers the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file.
	 *
	 * @param string $class_name Class name.
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, 'SafeSpot_' ) ) {
			return;
		}

		$file = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		foreach ( array( 'includes/', 'admin/' ) as $dir ) {
			$path = SAFESPOT_DIR . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
