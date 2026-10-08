<?php
/**
 * Plugin upgrader that installs a local SafeSpot archive.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/**
 * Installs a SafeSpot zip over an existing plugin folder using core's upgrader pipeline
 * (download_package() accepts local files and does not delete them).
 */
class SafeSpot_Plugin_Upgrader extends Plugin_Upgrader {

	/**
	 * Restores a folder-based plugin from a local archive.
	 *
	 * @param string $package     Absolute path of the zip.
	 * @param string $plugin_file Plugin basename, e.g. akismet/akismet.php.
	 * @return true|WP_Error
	 */
	public function restore( $package, $plugin_file ) {
		$this->init();
		$this->upgrade_strings();

		$folder = dirname( $plugin_file );
		if ( '.' === $folder || '' === $folder ) {
			return new WP_Error( 'safespot_single_file', __( 'Single-file plugins are restored without the upgrader.', 'tbsh-safespot-restore-points' ) );
		}

		add_filter( 'upgrader_source_selection', array( $this, 'check_package' ) );

		$this->run(
			array(
				'package'                     => $package,
				'destination'                 => trailingslashit( WP_PLUGIN_DIR ) . $folder,
				'clear_destination'           => true,
				'abort_if_destination_exists' => false,
				'clear_working'               => true,
				'hook_extra'                  => array(
					'plugin'           => $plugin_file,
					'type'             => 'plugin',
					'action'           => 'update',
					'safespot_restore' => true,
				),
			)
		);

		remove_filter( 'upgrader_source_selection', array( $this, 'check_package' ) );

		return SafeSpot_Upgrader_Skin::result( $this );
	}
}
