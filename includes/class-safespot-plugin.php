<?php
/**
 * Main plugin container.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots every component and gives access to shared services.
 */
final class SafeSpot_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var SafeSpot_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Backup service.
	 *
	 * @var SafeSpot_Backup
	 */
	private $backup;

	/**
	 * Restore service.
	 *
	 * @var SafeSpot_Restore
	 */
	private $restore;

	/**
	 * Background queue.
	 *
	 * @var SafeSpot_Queue
	 */
	private $queue;

	/**
	 * Returns the singleton.
	 *
	 * @return SafeSpot_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wires up the plugin.
	 */
	private function boot() {
		SafeSpot_Installer::maybe_upgrade();

		$this->backup  = new SafeSpot_Backup();
		$this->restore = new SafeSpot_Restore( $this->backup );
		$this->queue   = new SafeSpot_Queue( $this->backup );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new SafeSpot_Hooks( $this->backup ) )->register();
		$this->queue->register();
		( new SafeSpot_REST( $this->backup, $this->restore, $this->queue ) )->register();

		if ( is_admin() ) {
			( new SafeSpot_Admin( $this->restore ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			SafeSpot_CLI::register( $this->backup, $this->restore );
		}
	}

	/**
	 * Loads bundled translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'tbsh-safespot-restore-points', false, dirname( SAFESPOT_BASENAME ) . '/languages' );
	}

	/**
	 * Backup service.
	 *
	 * @return SafeSpot_Backup
	 */
	public function backup() {
		return $this->backup;
	}

	/**
	 * Restore service.
	 *
	 * @return SafeSpot_Restore
	 */
	public function restore() {
		return $this->restore;
	}

	/**
	 * Queue service.
	 *
	 * @return SafeSpot_Queue
	 */
	public function queue() {
		return $this->queue;
	}

	/**
	 * Raises PHP limits for long-running file operations where the host allows it.
	 */
	public static function raise_limits() {
		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archives need more than the default 30 seconds.
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
	}
}
