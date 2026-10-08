<?php
/**
 * WordPress integration hooks.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Automatic SafeSpots before updates, update holds, cleanup and recovery-mode integration.
 */
class SafeSpot_Hooks {

	/**
	 * Backup service.
	 *
	 * @var SafeSpot_Backup
	 */
	private $backup;

	/**
	 * Items already backed up in this request.
	 *
	 * @var array
	 */
	private $done = array();

	/**
	 * Package options captured for the upgrader currently running.
	 *
	 * @var array
	 */
	private $package_options = array();

	/**
	 * ID of the most recent automatic SafeSpot saved in this request.
	 *
	 * @var int
	 */
	private $last_auto_id = 0;

	/**
	 * SafeSpot taken before an install that may still abort because the folder exists.
	 *
	 * @var int
	 */
	private $speculative_id = 0;

	/**
	 * Constructor.
	 *
	 * @param SafeSpot_Backup $backup Backup service.
	 */
	public function __construct( SafeSpot_Backup $backup ) {
		$this->backup = $backup;
	}

	/**
	 * Registers hooks.
	 */
	public function register() {
		// Automatic SafeSpots before files are replaced.
		add_filter( 'upgrader_package_options', array( $this, 'capture_package_options' ), PHP_INT_MAX );
		add_filter( 'upgrader_pre_install', array( $this, 'before_update' ), 5, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'before_overwrite_install' ), 5, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'discard_speculative' ), 10, 2 );
		add_action( 'delete_plugin', array( $this, 'before_delete_plugin' ) );
		add_action( 'delete_theme', array( $this, 'before_delete_theme' ) );

		// Update holds.
		add_filter( 'upgrader_pre_download', array( $this, 'block_held_update' ), 10, 4 );
		add_action( 'load-plugins.php', array( $this, 'hide_held_update_rows' ), 21 );
		add_filter( 'auto_update_plugin', array( $this, 'filter_auto_update_plugin' ), 99, 2 );
		add_filter( 'auto_update_theme', array( $this, 'filter_auto_update_theme' ), 99, 2 );

		// Maintenance.
		add_action( 'safespot_daily_cleanup', array( $this, 'daily_cleanup' ) );

		// Fatal error recovery integration.
		add_filter( 'recovery_mode_email', array( $this, 'recovery_mode_email' ), 10, 2 );
		add_filter( 'wp_php_error_message', array( $this, 'php_error_message' ), 10, 2 );
	}

	/**
	 * Stores package options so uploads that overwrite an existing item can be detected.
	 *
	 * @param array $options Package options.
	 * @return array
	 */
	public function capture_package_options( $options ) {
		$this->package_options = is_array( $options ) ? $options : array();
		return $options;
	}

	/**
	 * Whether automatic SafeSpots are enabled for an item.
	 *
	 * @param string $type Type.
	 * @param string $slug Slug.
	 * @return bool
	 */
	private function should_auto_backup( $type, $slug ) {
		if ( ! SafeSpot_Settings::auto_backup_enabled( $type ) ) {
			return false;
		}
		if ( SafeSpot_Settings::is_excluded( $type, $slug ) ) {
			return false;
		}

		/**
		 * Filters whether an automatic SafeSpot is created for an item.
		 *
		 * @param bool   $should Whether to back up.
		 * @param string $type   plugin|theme.
		 * @param string $slug   Plugin file or theme stylesheet.
		 */
		return (bool) apply_filters( 'safespot_should_auto_backup', true, $type, $slug );
	}

	/**
	 * Creates an automatic SafeSpot and handles failures.
	 *
	 * @param string $type   Type.
	 * @param string $slug   Slug.
	 * @param string $source auto_update|pre_delete.
	 * @param string $note   Note.
	 * @return true|WP_Error
	 */
	private function auto_backup( $type, $slug, $source, $note ) {
		$key = $type . ':' . $slug;
		if ( isset( $this->done[ $key ] ) ) {
			return true;
		}
		$this->done[ $key ] = true;

		$result = $this->backup->save(
			$type,
			$slug,
			array(
				'note'      => $note,
				'source'    => $source,
				'lock_wait' => 120,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$this->last_auto_id = (int) $result['id'];
			return true;
		}

		$item = SafeSpot_Items::get( $type, $slug );
		SafeSpot_Backup::notify_failure(
			/* translators: %s: item name. */
			sprintf( __( 'Automatic SafeSpot failed: %s', 'tbsh-safespot-restore-points' ), $item ? $item['name'] : $slug ),
			$result->get_error_message()
		);

		return $result;
	}

	/**
	 * Hooked to upgrader_pre_install: backs up the current version before an update
	 * (manual, bulk, automatic background updates).
	 *
	 * @param bool|WP_Error $response   Response.
	 * @param array         $hook_extra Upgrader context.
	 * @return bool|WP_Error
	 */
	public function before_update( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! is_array( $hook_extra ) || ! empty( $hook_extra['safespot_restore'] ) ) {
			return $response;
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$type = 'plugin';
			$slug = (string) $hook_extra['plugin'];
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$type = 'theme';
			$slug = (string) $hook_extra['theme'];
		} else {
			return $response;
		}

		if ( ! $this->should_auto_backup( $type, $slug ) || ! SafeSpot_Items::get( $type, $slug ) ) {
			return $response;
		}

		$result = $this->auto_backup( $type, $slug, 'auto_update', $this->update_note( $type, $slug ) );

		if ( is_wp_error( $result ) && SafeSpot_Settings::get( 'abort_update_on_failure' ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'safespot_auto_backup_failed', sprintf( __( 'Update cancelled because SafeSpot could not save a restore point first: %s', 'tbsh-safespot-restore-points' ), $result->get_error_message() ) );
		}

		return $response;
	}

	/**
	 * Hooked to upgrader_source_selection: backs up an existing item that an install is
	 * about to replace ("Replace current with uploaded", `wp plugin install --force`, ...).
	 *
	 * Some tools set clear_destination inside install_package() where it cannot be
	 * observed, so any install over an existing item is backed up. If the install then
	 * aborts because the folder exists, discard_speculative() removes the SafeSpot.
	 *
	 * @param string|WP_Error $source        Extracted source path.
	 * @param string          $remote_source Remote source.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $hook_extra    Context.
	 * @return string|WP_Error
	 */
	public function before_overwrite_install( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		unset( $remote_source, $upgrader );

		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || ! empty( $hook_extra['safespot_restore'] ) ) {
			return $source;
		}
		if ( ( $hook_extra['action'] ?? '' ) !== 'install' ) {
			return $source;
		}
		$certain = ! empty( $this->package_options['clear_destination'] );

		$type   = $hook_extra['type'] ?? '';
		$folder = basename( untrailingslashit( (string) $source ) );
		$slug   = '';

		if ( 'plugin' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( array_keys( get_plugins() ) as $file ) {
				if ( dirname( $file ) === $folder ) {
					$slug = $file;
					break;
				}
			}
		} elseif ( 'theme' === $type && wp_get_theme( $folder )->exists() ) {
			$slug = $folder;
		}

		if ( '' === $slug || ! $this->should_auto_backup( $type, $slug ) ) {
			return $source;
		}

		$this->last_auto_id = 0;
		$result             = $this->auto_backup( $type, $slug, 'auto_update', __( 'Auto – before uploaded zip replaced this version', 'tbsh-safespot-restore-points' ) );

		if ( is_wp_error( $result ) && $certain && SafeSpot_Settings::get( 'abort_update_on_failure' ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'safespot_auto_backup_failed', sprintf( __( 'Installation cancelled because SafeSpot could not save a restore point first: %s', 'tbsh-safespot-restore-points' ), $result->get_error_message() ) );
		}

		$this->speculative_id = $certain ? 0 : $this->last_auto_id;

		return $source;
	}

	/**
	 * Removes the SafeSpot taken before an install that aborted because the folder exists.
	 *
	 * @param array|WP_Error $result     Install result.
	 * @param array          $hook_extra Context.
	 * @return array|WP_Error
	 */
	public function discard_speculative( $result, $hook_extra ) {
		unset( $hook_extra );

		$id                   = $this->speculative_id;
		$this->speculative_id = 0;
		if ( $id && is_wp_error( $result ) && 'folder_exists' === $result->get_error_code() ) {
			$this->backup->delete( $id, false );
		}
		return $result;
	}

	/**
	 * Note for update SafeSpots, including the incoming version when known.
	 *
	 * @param string $type Type.
	 * @param string $slug Slug.
	 * @return string
	 */
	private function update_note( $type, $slug ) {
		$item = SafeSpot_Items::get( $type, $slug );
		if ( $item && '' !== $item['update'] ) {
			/* translators: %s: new version. */
			return sprintf( __( 'Auto – before update to %s', 'tbsh-safespot-restore-points' ), $item['update'] );
		}
		return __( 'Auto – before update', 'tbsh-safespot-restore-points' );
	}

	/**
	 * Backs up a plugin right before it is deleted (optional).
	 *
	 * @param string $plugin_file Plugin file.
	 */
	public function before_delete_plugin( $plugin_file ) {
		if ( SafeSpot_Settings::get( 'backup_before_delete' ) && $this->should_auto_backup( 'plugin', $plugin_file ) ) {
			$this->auto_backup( 'plugin', $plugin_file, 'pre_delete', __( 'Auto – before delete', 'tbsh-safespot-restore-points' ) );
		}
	}

	/**
	 * Backs up a theme right before it is deleted (optional).
	 *
	 * @param string $stylesheet Theme folder.
	 */
	public function before_delete_theme( $stylesheet ) {
		if ( SafeSpot_Settings::get( 'backup_before_delete' ) && $this->should_auto_backup( 'theme', $stylesheet ) ) {
			$this->auto_backup( 'theme', $stylesheet, 'pre_delete', __( 'Auto – before delete', 'tbsh-safespot-restore-points' ) );
		}
	}

	/**
	 * Refuses manual, bulk and WP-CLI updates of items whose updates are paused.
	 *
	 * @param false|string|WP_Error $reply      Short-circuit value.
	 * @param string                $package    Package URL or path.
	 * @param WP_Upgrader           $upgrader   Upgrader.
	 * @param array                 $hook_extra Context.
	 * @return false|string|WP_Error
	 */
	public function block_held_update( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $package, $upgrader );

		if ( false !== $reply || ! is_array( $hook_extra ) || ! empty( $hook_extra['safespot_restore'] ) ) {
			return $reply;
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$type = 'plugin';
			$slug = (string) $hook_extra['plugin'];
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$type = 'theme';
			$slug = (string) $hook_extra['theme'];
		} else {
			return $reply;
		}

		$hold = SafeSpot_Settings::get_hold( $type, $slug );
		if ( ! $hold ) {
			return $reply;
		}

		$item = SafeSpot_Items::get( $type, $slug );
		return new WP_Error(
			'safespot_updates_paused',
			sprintf(
				/* translators: 1: plugin or theme name, 2: version. */
				__( 'Updates for %1$s are paused by SafeSpot to keep version %2$s. Resume updates on the SafeSpot screen first.', 'tbsh-safespot-restore-points' ),
				$item ? $item['name'] : $slug,
				$hold['version'] ?? ''
			)
		);
	}

	/**
	 * Hides the "new version available" row on the Plugins screen for paused plugins.
	 */
	public function hide_held_update_rows() {
		foreach ( array_keys( (array) SafeSpot_Settings::get( 'holds' ) ) as $key ) {
			if ( 0 === strpos( $key, 'plugin:' ) ) {
				remove_action( 'after_plugin_row_' . substr( $key, 7 ), 'wp_plugin_update_row', 10 );
			}
		}
	}

	/**
	 * Blocks automatic updates for held plugins.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function filter_auto_update_plugin( $update, $item ) {
		if ( is_object( $item ) && ! empty( $item->plugin ) && SafeSpot_Settings::get_hold( 'plugin', $item->plugin ) ) {
			return false;
		}
		return $update;
	}

	/**
	 * Blocks automatic updates for held themes.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function filter_auto_update_theme( $update, $item ) {
		if ( is_object( $item ) && ! empty( $item->theme ) && SafeSpot_Settings::get_hold( 'theme', $item->theme ) ) {
			return false;
		}
		return $update;
	}

	/**
	 * Daily retention, log pruning and temp cleanup.
	 */
	public function daily_cleanup() {
		$this->backup->prune_all();
		SafeSpot_Logger::prune( (int) SafeSpot_Settings::get( 'log_retention_days' ) );
		SafeSpot_Storage::cleanup_temp();
	}

	/**
	 * Adds rollback instructions to WordPress's recovery mode email when a restore ran recently.
	 *
	 * @param array  $email Email data.
	 * @param string $url   Recovery mode URL.
	 * @return array
	 */
	public function recovery_mode_email( $email, $url ) {
		unset( $url );
		$last = SafeSpot_Restore::last_restore();
		if ( ! $last || ! is_array( $email ) || time() - (int) $last['time'] > 6 * HOUR_IN_SECONDS ) {
			return $email;
		}

		$page = is_multisite() ? network_admin_url( 'admin.php?page=safespot' ) : admin_url( 'admin.php?page=safespot' );

		$email['message'] .= "\n\n" . sprintf(
			/* translators: 1: item name, 2: restored version, 3: previous version, 4: URL. */
			__( 'SafeSpot note: %1$s was restored to version %2$s shortly before this error (it was on %3$s). After entering recovery mode with the link above, open SafeSpot and click "Roll back this restore": %4$s', 'tbsh-safespot-restore-points' ),
			$last['name'],
			$last['version_to'],
			'' !== $last['version_from'] ? $last['version_from'] : '—',
			$page
		);

		return $email;
	}

	/**
	 * Adds a hint to the "critical error" screen in wp-admin after a recent restore.
	 *
	 * @param string $message HTML message.
	 * @param array  $error   Error data.
	 * @return string
	 */
	public function php_error_message( $message, $error ) {
		unset( $error );
		if ( ! is_admin() ) {
			return $message;
		}
		$last = SafeSpot_Restore::last_restore();
		if ( ! $last || time() - (int) $last['time'] > HOUR_IN_SECONDS ) {
			return $message;
		}

		return $message . '<p>' . esc_html(
			sprintf(
				/* translators: %s: item name. */
				__( 'SafeSpot restored %s a few minutes ago. If this error started after that restore, use the recovery mode link that WordPress emailed to the administrator, then click "Roll back this restore" on the SafeSpot screen.', 'tbsh-safespot-restore-points' ),
				$last['name']
			)
		) . '</p>';
	}
}
