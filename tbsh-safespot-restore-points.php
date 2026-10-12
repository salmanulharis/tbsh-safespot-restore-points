<?php
/**
 * Plugin Name:       SafeSpot – Plugin Restore Points
 * Plugin URI:        https://wordpress.org/plugins/tbsh-safespot-restore-points/
 * Description:       Save restore points ("SafeSpots") of any installed plugin or theme and restore that exact version later with one click. Automatic SafeSpots before updates, one-click undo, and fatal-error protection.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SafeSpot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tbsh-safespot-restore-points
 * Domain Path:       /languages
 * Network:           true
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

define( 'SAFESPOT_VERSION', '1.0.3' );
define( 'SAFESPOT_DB_VERSION', '1.0.3' );
define( 'SAFESPOT_FILE', __FILE__ );
define( 'SAFESPOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAFESPOT_URL', plugin_dir_url( __FILE__ ) );
define( 'SAFESPOT_BASENAME', plugin_basename( __FILE__ ) );

require_once SAFESPOT_DIR . 'includes/class-safespot-autoloader.php';
SafeSpot_Autoloader::register();

register_activation_hook( __FILE__, array( 'SafeSpot_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SafeSpot_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SafeSpot_Plugin', 'instance' ) );

/**
 * Returns the main plugin instance.
 *
 * @return SafeSpot_Plugin
 */
function safespot() {
	return SafeSpot_Plugin::instance();
}
