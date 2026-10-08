<?php
/**
 * Uninstall: removes data only when the user opted in.
 *
 * @package SafeSpot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'SAFESPOT_DIR' ) ) {
	define( 'SAFESPOT_DIR', plugin_dir_path( __FILE__ ) );
}

require_once SAFESPOT_DIR . 'includes/class-safespot-autoloader.php';
SafeSpot_Autoloader::register();

$safespot_settings = get_site_option( 'safespot_settings', array() );

wp_clear_scheduled_hook( 'safespot_daily_cleanup' );
wp_clear_scheduled_hook( 'safespot_process_queue' );

if ( empty( $safespot_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

SafeSpot_Storage::destroy();

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}safespot_points" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}safespot_log" );
// phpcs:enable

foreach ( array( 'safespot_settings', 'safespot_db_version', 'safespot_storage_key', 'safespot_queue' ) as $safespot_option ) {
	delete_site_option( $safespot_option );
}
delete_site_transient( 'safespot_last_restore' );
SafeSpot_Lock::force_release();
