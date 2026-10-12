<?php
/**
 * Installation, upgrades and deactivation.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the custom tables and schedules recurring tasks.
 *
 * Metadata lives in network-wide custom tables ({base_prefix}safespot_points and
 * {base_prefix}safespot_log) rather than a custom post type because plugin and theme
 * files are shared by the whole network, the data needs indexed lookups by type/slug
 * and aggregate size queries, and it must never leak into exports, revisions or
 * front-end queries.
 */
class SafeSpot_Installer {

	const DB_OPTION = 'safespot_db_version';

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );
		self::create_tables();
		update_site_option( self::DB_OPTION, SAFESPOT_DB_VERSION );

		if ( false === get_site_option( SafeSpot_Settings::OPTION, false ) ) {
			add_site_option( SafeSpot_Settings::OPTION, SafeSpot_Settings::defaults() );
		}

		$storage = SafeSpot_Storage::get_dir();
		unset( $storage );

		self::schedule_events();
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'safespot_daily_cleanup' );
		wp_clear_scheduled_hook( SafeSpot_Queue::HOOK );
		SafeSpot_Lock::force_release();
	}

	/**
	 * Runs table migrations when the stored schema version is outdated.
	 */
	public static function maybe_upgrade() {
		if ( SAFESPOT_DB_VERSION !== get_site_option( self::DB_OPTION ) ) {
			self::create_tables();
			update_site_option( self::DB_OPTION, SAFESPOT_DB_VERSION );
		}

		if ( is_main_site() && ! wp_installing() ) {
			add_action( 'init', array( __CLASS__, 'schedule_events' ) );
		}
	}

	/**
	 * Schedules the daily cleanup on the main site.
	 */
	public static function schedule_events() {
		if ( is_main_site() && ! wp_next_scheduled( 'safespot_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'safespot_daily_cleanup' );
		}
	}

	/**
	 * Creates or updates the custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$points  = SafeSpot_Points::table();
		$log     = SafeSpot_Logger::table();

		$sql = "CREATE TABLE {$points} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_type varchar(10) NOT NULL DEFAULT '',
  slug varchar(191) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  version varchar(100) NOT NULL DEFAULT '',
  file_name varchar(255) NOT NULL DEFAULT '',
  file_size bigint(20) unsigned NOT NULL DEFAULT 0,
  uncompressed_size bigint(20) unsigned NOT NULL DEFAULT 0,
  file_count int(10) unsigned NOT NULL DEFAULT 0,
  checksum char(64) NOT NULL DEFAULT '',
  settings_file varchar(255) NOT NULL DEFAULT '',
  settings_checksum char(64) NOT NULL DEFAULT '',
  settings_count int(10) unsigned NOT NULL DEFAULT 0,
  fingerprint char(64) NOT NULL DEFAULT '',
  settings_fingerprint char(64) NOT NULL DEFAULT '',
  note text NULL,
  source varchar(20) NOT NULL DEFAULT 'manual',
  is_locked tinyint(1) unsigned NOT NULL DEFAULT 0,
  requires_wp varchar(20) NOT NULL DEFAULT '',
  requires_php varchar(20) NOT NULL DEFAULT '',
  parent_theme varchar(191) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  reused_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY item (item_type,slug(180)),
  KEY created_at (created_at)
) {$collate};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(30) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'success',
  item_type varchar(10) NOT NULL DEFAULT '',
  slug varchar(191) NOT NULL DEFAULT '',
  item_name varchar(255) NOT NULL DEFAULT '',
  point_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY action (action),
  KEY status (status)
) {$collate};";

		dbDelta( $sql );
	}
}
