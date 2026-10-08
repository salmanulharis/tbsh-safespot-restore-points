<?php
/**
 * Theme upgrader that installs a local SafeSpot archive.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/**
 * Installs a SafeSpot zip over an existing theme folder using core's upgrader pipeline.
 */
class SafeSpot_Theme_Upgrader extends Theme_Upgrader {

	/**
	 * Restores a theme from a local archive.
	 *
	 * @param string $package    Absolute path of the zip.
	 * @param string $stylesheet Theme folder name.
	 * @return true|WP_Error
	 */
	public function restore( $package, $stylesheet ) {
		$this->init();
		$this->upgrade_strings();

		add_filter( 'upgrader_source_selection', array( $this, 'check_package' ) );

		$this->run(
			array(
				'package'                     => $package,
				'destination'                 => trailingslashit( get_theme_root( $stylesheet ) ) . $stylesheet,
				'clear_destination'           => true,
				'abort_if_destination_exists' => false,
				'clear_working'               => true,
				'hook_extra'                  => array(
					'theme'            => $stylesheet,
					'type'             => 'theme',
					'action'           => 'update',
					'safespot_restore' => true,
				),
			)
		);

		remove_filter( 'upgrader_source_selection', array( $this, 'check_package' ) );

		wp_clean_themes_cache();

		return SafeSpot_Upgrader_Skin::result( $this );
	}
}
