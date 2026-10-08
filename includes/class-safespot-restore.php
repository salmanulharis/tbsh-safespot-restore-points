<?php
/**
 * Restoring SafeSpots.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pre-restore checks, file replacement through core upgraders, automatic rollback
 * and post-restore fatal error detection.
 */
class SafeSpot_Restore {

	const LAST_RESTORE = 'safespot_last_restore';

	/**
	 * Backup service.
	 *
	 * @var SafeSpot_Backup
	 */
	private $backup;

	/**
	 * Whether this request turned maintenance mode on.
	 *
	 * @var bool
	 */
	private $maintenance_on = false;

	/**
	 * Plugin deactivated by an in-progress restore that must be reactivated if the request dies.
	 *
	 * @var array{slug: string, network: bool}|null
	 */
	private $pending_activation = null;

	/**
	 * Constructor.
	 *
	 * @param SafeSpot_Backup $backup Backup service.
	 */
	public function __construct( SafeSpot_Backup $backup ) {
		$this->backup = $backup;
	}

	/**
	 * Archive-related properties derived from a SafeSpot row.
	 *
	 * @param array $point SafeSpot row.
	 * @return array { type, slug, folder, single_file, root, required, target }
	 */
	public function point_item( array $point ) {
		$type   = $point['item_type'];
		$slug   = $point['slug'];
		$single = 'plugin' === $type && false === strpos( $slug, '/' );

		if ( 'plugin' === $type ) {
			$folder = $single ? basename( $slug, '.php' ) : dirname( $slug );
			$root   = $single ? $slug : $folder;
			$target = wp_normalize_path( WP_PLUGIN_DIR . '/' . ( $single ? $slug : $folder ) );
		} else {
			$folder = $slug;
			$root   = $slug;
			$target = wp_normalize_path( get_theme_root( $slug ) . '/' . $slug );
		}

		$item             = array(
			'type'        => $type,
			'slug'        => $slug,
			'folder'      => $folder,
			'single_file' => $single,
			'root'        => $root,
			'target'      => $target,
			'name'        => $point['name'],
		);
		$item['required'] = $this->backup->required_entry( $item );

		return $item;
	}

	/**
	 * Verifies the checksum and structure of a SafeSpot archive.
	 *
	 * @param array $point SafeSpot row.
	 * @return array|WP_Error { path, entries }
	 */
	public function verify_archive( array $point ) {
		$path = SafeSpot_Storage::path( $point['file_name'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'safespot_zip_missing', __( 'The SafeSpot archive file is missing from the storage folder.', 'tbsh-safespot-restore-points' ) );
		}
		if ( '' === $point['checksum'] || ! hash_equals( $point['checksum'], (string) hash_file( 'sha256', $path ) ) ) {
			return new WP_Error( 'safespot_checksum_mismatch', __( 'The SafeSpot archive failed its SHA-256 checksum. It may be corrupt or modified, so nothing was changed.', 'tbsh-safespot-restore-points' ) );
		}

		$pi      = $this->point_item( $point );
		$entries = SafeSpot_Zip::validate( $path, $pi['root'], $pi['single_file'], $pi['required'] );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		return array(
			'path'    => $path,
			'entries' => $entries,
		);
	}

	/**
	 * Runs pre-restore checks and builds the version comparison.
	 *
	 * @param int $id SafeSpot ID.
	 * @return array|WP_Error
	 */
	public function preflight( $id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$point = SafeSpot_Points::get( $id );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$pi     = $this->point_item( $point );
		$item   = SafeSpot_Items::get( $pi['type'], $pi['slug'] );
		$checks = array();
		$add    = static function ( $key, $status, $label, $message ) use ( &$checks ) {
			$checks[] = array(
				'id'      => $key,
				'status'  => $status,
				'label'   => $label,
				'message' => $message,
			);
		};

		// Archive integrity.
		$archive = $this->verify_archive( $point );
		if ( is_wp_error( $archive ) ) {
			$add( 'archive', 'fail', __( 'Archive integrity', 'tbsh-safespot-restore-points' ), $archive->get_error_message() );
		} else {
			$add(
				'archive',
				'pass',
				__( 'Archive integrity', 'tbsh-safespot-restore-points' ),
				/* translators: %s: number of files. */
				sprintf( _n( 'SHA-256 checksum verified, %s file, no unsafe paths.', 'SHA-256 checksum verified, %s files, no unsafe paths.', $point['file_count'], 'tbsh-safespot-restore-points' ), number_format_i18n( $point['file_count'] ) )
			);
		}

		if ( 'plugin' === $pi['type'] && SAFESPOT_BASENAME === $pi['slug'] ) {
			$add( 'self', 'fail', __( 'SafeSpot itself', 'tbsh-safespot-restore-points' ), __( 'SafeSpot cannot restore itself while running. Download this SafeSpot and install it from Plugins → Add New instead.', 'tbsh-safespot-restore-points' ) );
		}

		if ( ! wp_is_file_mod_allowed( 'safespot_restore' ) ) {
			$add( 'file_mods', 'fail', __( 'File changes allowed', 'tbsh-safespot-restore-points' ), __( 'File modifications are disabled on this site (DISALLOW_FILE_MODS).', 'tbsh-safespot-restore-points' ) );
		}

		// PHP / WordPress requirements from the backed-up headers.
		if ( '' !== $point['requires_php'] ) {
			$ok = is_php_version_compatible( $point['requires_php'] );
			$add(
				'php',
				$ok ? 'pass' : 'fail',
				__( 'PHP version', 'tbsh-safespot-restore-points' ),
				/* translators: 1: required PHP version, 2: current PHP version. */
				sprintf( __( 'Requires PHP %1$s, this server runs %2$s.', 'tbsh-safespot-restore-points' ), $point['requires_php'], PHP_VERSION )
			);
		}
		if ( '' !== $point['requires_wp'] ) {
			$ok = is_wp_version_compatible( $point['requires_wp'] );
			$add(
				'wp',
				$ok ? 'pass' : 'fail',
				__( 'WordPress version', 'tbsh-safespot-restore-points' ),
				/* translators: 1: required WP version, 2: current WP version. */
				sprintf( __( 'Requires WordPress %1$s, this site runs %2$s.', 'tbsh-safespot-restore-points' ), $point['requires_wp'], get_bloginfo( 'version' ) )
			);
		}

		// Disk space: room for the safety SafeSpot plus the extracted files.
		$needed = (int) ( $point['uncompressed_size'] * 2 + $point['file_size'] + 5 * MB_IN_BYTES );
		$free   = SafeSpot_Storage::free_space( WP_CONTENT_DIR );
		if ( null === $free ) {
			$add( 'disk', 'warn', __( 'Free disk space', 'tbsh-safespot-restore-points' ), __( 'The server does not report free disk space, so it could not be checked.', 'tbsh-safespot-restore-points' ) );
		} else {
			$add(
				'disk',
				$free >= $needed ? 'pass' : 'fail',
				__( 'Free disk space', 'tbsh-safespot-restore-points' ),
				/* translators: 1: free space, 2: needed space. */
				sprintf( __( '%1$s free, about %2$s needed.', 'tbsh-safespot-restore-points' ), size_format( $free, 1 ), size_format( $needed, 1 ) )
			);
		}

		$this->check_permissions( $pi, $add );

		if ( ! SafeSpot_Lock::is_owner() ) {
			$lock = SafeSpot_Lock::current();
			if ( $lock ) {
				$add( 'lock', 'fail', __( 'No other operation running', 'tbsh-safespot-restore-points' ), __( 'Another SafeSpot backup or restore is in progress. Try again when it finishes.', 'tbsh-safespot-restore-points' ) );
			}
		}

		$this->check_item_specifics( $point, $pi, $item, $add );

		if ( '' !== $point['settings_file'] ) {
			$add(
				'settings',
				'info',
				__( 'Settings snapshot', 'tbsh-safespot-restore-points' ),
				/* translators: %s: number of option rows. */
				sprintf( _n( 'A snapshot of %s settings row is available. Custom database tables are not included.', 'A snapshot of %s settings rows is available. Custom database tables are not included.', $point['settings_count'], 'tbsh-safespot-restore-points' ), number_format_i18n( $point['settings_count'] ) )
			);
		}

		$can_restore = true;
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				$can_restore = false;
				break;
			}
		}

		$diff = null;
		if ( ! is_wp_error( $archive ) && $point['file_count'] <= 20000 ) {
			$diff = SafeSpot_Zip::diff( $archive['entries'], $pi['root'], $item ? $item['path'] : '' );
		}

		$current = $item ? $item['version'] : '';
		if ( ! $item ) {
			$direction = 'install';
		} else {
			$cmp       = version_compare( $point['version'], $current );
			$direction = $cmp < 0 ? 'downgrade' : ( 0 === $cmp ? 'reinstall' : 'upgrade' );
		}

		return array(
			'point'           => SafeSpot_Points::format( $point ),
			'installed'       => (bool) $item,
			'status'          => $item ? $item['status'] : 'not_installed',
			'status_label'    => $item ? SafeSpot_Items::status_label( $item ) : __( 'Not installed', 'tbsh-safespot-restore-points' ),
			'current_version' => $current,
			'target_version'  => $point['version'],
			'direction'       => $direction,
			'checks'          => $checks,
			'can_restore'     => $can_restore,
			'diff'            => $diff,
			'has_settings'    => '' !== $point['settings_file'],
			'will_backup'     => (bool) $item,
			'hold'            => (bool) SafeSpot_Settings::get_hold( $pi['type'], $pi['slug'] ),
		);
	}

	/**
	 * Filesystem permission / credential checks.
	 *
	 * @param array    $pi  Point item.
	 * @param callable $add Check collector.
	 */
	private function check_permissions( array $pi, callable $add ) {
		$method = get_filesystem_method( array(), WP_CONTENT_DIR );
		$label  = __( 'Write permissions', 'tbsh-safespot-restore-points' );

		if ( 'direct' !== $method ) {
			ob_start();
			$creds = request_filesystem_credentials( '', $method, false, WP_CONTENT_DIR );
			ob_end_clean();
			if ( false === $creds ) {
				$add( 'permissions', 'fail', $label, __( 'WordPress needs FTP/SSH credentials to change files. Define FS_METHOD and the FTP_* constants in wp-config.php to allow one-click restores.', 'tbsh-safespot-restore-points' ) );
			} else {
				/* translators: %s: filesystem method, e.g. ftpext. */
				$add( 'permissions', 'pass', $label, sprintf( __( 'Files will be written using the "%s" filesystem method.', 'tbsh-safespot-restore-points' ), $method ) );
			}
			return;
		}

		$paths = array( dirname( $pi['target'] ), WP_CONTENT_DIR );
		if ( file_exists( $pi['target'] ) ) {
			$paths[] = $pi['target'];
		}
		$upgrade = WP_CONTENT_DIR . '/upgrade';
		if ( file_exists( $upgrade ) ) {
			$paths[] = $upgrade;
		}

		$bad = array();
		foreach ( $paths as $path ) {
			if ( ! wp_is_writable( $path ) ) {
				$bad[] = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $path ) );
			}
		}

		if ( $bad ) {
			/* translators: %s: list of folders. */
			$add( 'permissions', 'fail', $label, sprintf( __( 'These folders are not writable: %s', 'tbsh-safespot-restore-points' ), implode( ', ', $bad ) ) );
		} else {
			$add( 'permissions', 'pass', $label, __( 'The target folders are writable.', 'tbsh-safespot-restore-points' ) );
		}
	}

	/**
	 * Plugin/theme specific checks.
	 *
	 * @param array      $point SafeSpot row.
	 * @param array      $pi    Point item.
	 * @param array|null $item  Installed item.
	 * @param callable   $add   Check collector.
	 */
	private function check_item_specifics( array $point, array $pi, $item, callable $add ) {
		if ( 'plugin' === $pi['type'] ) {
			if ( $item && $item['network'] ) {
				$add( 'state', 'info', __( 'Activation state', 'tbsh-safespot-restore-points' ), __( 'This plugin is network activated. It will be deactivated during the restore and network activated again afterwards.', 'tbsh-safespot-restore-points' ) );
			} elseif ( $item && $item['active'] ) {
				$add( 'state', 'info', __( 'Activation state', 'tbsh-safespot-restore-points' ), __( 'This plugin is active. The site enters maintenance mode briefly; the plugin is deactivated, replaced and reactivated.', 'tbsh-safespot-restore-points' ) );
			} elseif ( ! $item ) {
				$add( 'state', 'info', __( 'Activation state', 'tbsh-safespot-restore-points' ), __( 'This plugin is not installed. It will be reinstalled but left inactive.', 'tbsh-safespot-restore-points' ) );
			}
			return;
		}

		if ( '' !== $point['parent_theme'] ) {
			$parent = wp_get_theme( $point['parent_theme'] );
			if ( ! $parent->exists() ) {
				$add(
					'parent',
					$item && $item['active'] ? 'fail' : 'warn',
					__( 'Parent theme', 'tbsh-safespot-restore-points' ),
					/* translators: %s: parent theme folder. */
					sprintf( __( 'This child theme needs the parent theme "%s", which is not installed. Restore or install the parent theme first.', 'tbsh-safespot-restore-points' ), $point['parent_theme'] )
				);
			}
		}

		if ( $item && 'parent' === $item['status'] ) {
			$child = wp_get_theme();
			$add(
				'child',
				'warn',
				__( 'Active child theme', 'tbsh-safespot-restore-points' ),
				/* translators: %s: child theme name. */
				sprintf( __( 'This is the parent of the active child theme "%s". Restoring it changes how the live site looks and works.', 'tbsh-safespot-restore-points' ), $child->get( 'Name' ) )
			);
		} elseif ( $item && 'active' === $item['status'] ) {
			$add( 'state', 'info', __( 'Active theme', 'tbsh-safespot-restore-points' ), __( 'This is the active theme. The site enters maintenance mode for a few seconds while files are replaced.', 'tbsh-safespot-restore-points' ) );
		}

		if ( is_multisite() ) {
			$add( 'network', 'info', __( 'Multisite', 'tbsh-safespot-restore-points' ), __( 'Theme files are shared by every site in the network that uses this theme.', 'tbsh-safespot-restore-points' ) );
		}
	}

	/**
	 * Restores a SafeSpot.
	 *
	 * @param int   $id   SafeSpot ID.
	 * @param array $args {
	 *     Optional. Restore options.
	 *
	 *     @type string    $mode          files|files_settings.
	 *     @type bool      $pause_updates Pause updates for the item afterwards.
	 *     @type bool      $is_rollback   Internal: undoing a previous restore (no safety SafeSpot).
	 *     @type bool|null $health_check  Override the health-check setting.
	 * }
	 * @return array|WP_Error
	 */
	public function restore( $id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'mode'          => 'files',
				'pause_updates' => false,
				'is_rollback'   => false,
				'health_check'  => null,
			)
		);

		$point = SafeSpot_Points::get( $id );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		SafeSpot_Plugin::raise_limits();

		if ( ! SafeSpot_Lock::acquire( 'restore:' . $point['item_type'] . ':' . $point['slug'], 0 ) ) {
			return new WP_Error( 'safespot_locked', __( 'Another SafeSpot backup or restore is running. Please try again in a moment.', 'tbsh-safespot-restore-points' ), array( 'status' => 409 ) );
		}

		register_shutdown_function( array( $this, 'shutdown' ) );

		try {
			$result                   = $this->do_restore( $point, $args );
			$this->pending_activation = null;
		} finally {
			$this->maintenance( false );
			SafeSpot_Lock::release();
		}

		$action = $args['is_rollback'] ? 'rollback' : 'restore';
		$log    = array(
			'type'     => $point['item_type'],
			'slug'     => $point['slug'],
			'name'     => $point['name'],
			'point_id' => $point['id'],
		);

		if ( is_wp_error( $result ) ) {
			SafeSpot_Logger::log(
				$action,
				'error',
				/* translators: 1: item name, 2: version, 3: error. */
				sprintf( __( 'Restore of %1$s %2$s failed: %3$s', 'tbsh-safespot-restore-points' ), $point['name'], $point['version'], $result->get_error_message() ),
				$log
			);
			SafeSpot_Backup::notify_failure(
				/* translators: %s: item name. */
				sprintf( __( 'SafeSpot restore failed: %s', 'tbsh-safespot-restore-points' ), $point['name'] ),
				$result->get_error_message()
			);
			return $result;
		}

		SafeSpot_Logger::log( $action, empty( $result['warnings'] ) ? 'success' : 'warning', $result['message'] . ( $result['warnings'] ? ' ' . implode( ' ', $result['warnings'] ) : '' ), $log );

		return $result;
	}

	/**
	 * Restore implementation (lock held).
	 *
	 * @param array $point SafeSpot row.
	 * @param array $args  Arguments.
	 * @return array|WP_Error
	 */
	private function do_restore( array $point, array $args ) {
		$preflight = $this->preflight( $point['id'] );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( ! $preflight['can_restore'] ) {
			$messages = array();
			foreach ( $preflight['checks'] as $check ) {
				if ( 'fail' === $check['status'] ) {
					$messages[] = $check['message'];
				}
			}
			return new WP_Error( 'safespot_preflight_failed', implode( ' ', $messages ), array( 'status' => 400 ) );
		}

		$pi    = $this->point_item( $point );
		$item  = SafeSpot_Items::get( $pi['type'], $pi['slug'] );
		$state = $this->capture_state( $pi, $item );

		$restore_settings = 'files_settings' === $args['mode'] && '' !== $point['settings_file'];
		$snapshot         = null;
		if ( $restore_settings ) {
			$snapshot = SafeSpot_Settings_Snapshot::read( $point );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
		}

		/**
		 * Fires before a SafeSpot is restored (after checks pass, before any change).
		 *
		 * @param array      $point SafeSpot record being restored.
		 * @param array|null $item  Currently installed item, or null if not installed.
		 * @param array      $args  Restore arguments.
		 */
		do_action( 'safespot_before_restore', $point, $item, $args );

		// 1. Safety SafeSpot of the current version, so every restore can be undone.
		$pre_point = null;
		if ( $item && ! $args['is_rollback'] ) {
			$saved = $this->backup->save(
				$pi['type'],
				$pi['slug'],
				array(
					/* translators: %s: version being restored. */
					'note'             => sprintf( __( 'Auto – before restore to %s', 'tbsh-safespot-restore-points' ), $point['version'] ),
					'source'           => 'pre_restore',
					'include_settings' => $restore_settings,
					'prune'            => false,
				)
			);
			if ( is_wp_error( $saved ) ) {
				/* translators: %s: error message. */
				return new WP_Error( 'safespot_pre_backup_failed', sprintf( __( 'Could not save a safety SafeSpot of the current version, so the restore was cancelled: %s', 'tbsh-safespot-restore-points' ), $saved->get_error_message() ) );
			}
			$pre_point = SafeSpot_Points::get( $saved['id'] );
		}

		$last = array(
			'type'              => $pi['type'],
			'slug'              => $pi['slug'],
			'name'              => $point['name'],
			'restored_point_id' => $point['id'],
			'pre_point_id'      => $pre_point ? $pre_point['id'] : 0,
			'version_from'      => $item ? $item['version'] : '',
			'version_to'        => $point['version'],
			'settings'          => $restore_settings,
			'hold_added'        => false,
			'time'              => time(),
			'user'              => get_current_user_id(),
			'status'            => 'in_progress',
		);
		if ( ! $args['is_rollback'] && $pre_point ) {
			set_site_transient( self::LAST_RESTORE, $last, DAY_IN_SECONDS );
		}

		// 2. Replace the files.
		$replaced = $this->replace_files( $point, $state );
		if ( is_wp_error( $replaced ) ) {
			$message = $replaced->get_error_message();
			if ( $pre_point ) {
				$rollback = $this->replace_files( $pre_point, $state );
				if ( is_wp_error( $rollback ) ) {
					/* translators: %s: error message. */
					$message .= ' ' . sprintf( __( 'Automatic rollback also failed: %s. Restore the safety SafeSpot manually.', 'tbsh-safespot-restore-points' ), $rollback->get_error_message() );
				} else {
					$message .= ' ' . __( 'The previous version was put back automatically.', 'tbsh-safespot-restore-points' );
					delete_site_transient( self::LAST_RESTORE );
				}
			}
			return new WP_Error( 'safespot_restore_failed', $message, array( 'status' => 500 ) );
		}

		$warnings = array();

		// 3. Settings.
		$settings_written = null;
		if ( $restore_settings ) {
			$written = SafeSpot_Settings_Snapshot::import( $snapshot, $pi['type'], $pi['slug'] );
			if ( is_wp_error( $written ) ) {
				$warnings[] = $written->get_error_message();
			} else {
				$settings_written = $written;
			}
		}

		// 4. Make sure the site still loads.
		$health    = null;
		$do_health = null === $args['health_check'] ? (bool) SafeSpot_Settings::get( 'health_check' ) : (bool) $args['health_check'];
		if ( $do_health && $state['active'] ) {
			$health = SafeSpot_Health::check( 'plugin' === $pi['type'] ? 'admin' : 'front' );

			if ( 'fatal' === $health['status'] ) {
				return $this->handle_fatal( $point, $pi, $pre_point, $state, $restore_settings, $health );
			}
			if ( 'unknown' === $health['status'] ) {
				$warnings[] = $health['message'];
			}
		}

		// 5. Optionally keep this version until the user resumes updates.
		if ( $args['pause_updates'] ) {
			SafeSpot_Settings::set_hold( $pi['type'], $pi['slug'], true, $point['version'] );
			$last['hold_added'] = true;
		}

		if ( ! $args['is_rollback'] && $pre_point ) {
			$last['status'] = 'done';
			set_site_transient( self::LAST_RESTORE, $last, DAY_IN_SECONDS );
		}

		$protect = array( (int) $point['id'] );
		if ( $pre_point ) {
			$protect[] = (int) $pre_point['id'];
		}
		$this->backup->prune_item( $pi['type'], $pi['slug'], $protect );

		$from = $item ? $item['version'] : '';
		if ( '' === $from ) {
			/* translators: 1: item name, 2: version. */
			$message = sprintf( __( 'Reinstalled %1$s %2$s from its SafeSpot.', 'tbsh-safespot-restore-points' ), $point['name'], $point['version'] );
		} else {
			/* translators: 1: item name, 2: old version, 3: restored version. */
			$message = sprintf( __( 'Restored %1$s from %2$s to %3$s.', 'tbsh-safespot-restore-points' ), $point['name'], $from, $point['version'] );
		}

		$result = array(
			'message'           => $message,
			'point'             => SafeSpot_Points::format( $point ),
			'pre_point'         => $pre_point ? SafeSpot_Points::format( $pre_point ) : null,
			'from'              => $from,
			'to'                => $point['version'],
			'health'            => $health,
			'settings_restored' => $settings_written,
			'warnings'          => $warnings,
			'can_undo'          => (bool) $pre_point && ! $args['is_rollback'],
		);

		/**
		 * Fires after a SafeSpot has been restored successfully.
		 *
		 * @param array      $point  SafeSpot record that was restored.
		 * @param array|null $item   Item as it was before the restore (null if it was not installed).
		 * @param array      $result Restore result.
		 */
		do_action( 'safespot_after_restore', $point, $item, $result );

		return $result;
	}

	/**
	 * Rolls back after the health check detected a fatal error.
	 *
	 * @param array      $point            SafeSpot that was restored.
	 * @param array      $pi               Point item.
	 * @param array|null $pre_point        Safety SafeSpot.
	 * @param array      $state            Activation state.
	 * @param bool       $restore_settings Whether settings were restored.
	 * @param array      $health           Health result.
	 * @return WP_Error
	 */
	private function handle_fatal( array $point, array $pi, $pre_point, array $state, $restore_settings, array $health ) {
		/* translators: %s: PHP error. */
		$message = sprintf( __( 'The restored version caused a fatal error: %s.', 'tbsh-safespot-restore-points' ), $health['message'] );

		if ( ! $pre_point ) {
			if ( 'plugin' === $pi['type'] ) {
				deactivate_plugins( $pi['slug'], true );
				$message .= ' ' . __( 'The plugin was deactivated to keep the site online.', 'tbsh-safespot-restore-points' );
			}
			return new WP_Error( 'safespot_restore_fatal', $message, array( 'status' => 500 ) );
		}

		$rollback = $this->replace_files( $pre_point, $state );
		if ( is_wp_error( $rollback ) ) {
			/* translators: %s: error message. */
			$message .= ' ' . sprintf( __( 'Automatic rollback failed: %s. Use the recovery mode link WordPress emails to the administrator, then restore the safety SafeSpot.', 'tbsh-safespot-restore-points' ), $rollback->get_error_message() );
			return new WP_Error( 'safespot_restore_fatal', $message, array( 'status' => 500 ) );
		}

		if ( $restore_settings && '' !== $pre_point['settings_file'] ) {
			$snapshot = SafeSpot_Settings_Snapshot::read( $pre_point );
			if ( ! is_wp_error( $snapshot ) ) {
				SafeSpot_Settings_Snapshot::import( $snapshot, $pi['type'], $pi['slug'] );
			}
		}

		delete_site_transient( self::LAST_RESTORE );
		$message .= ' ' . __( 'SafeSpot rolled back to the previous version automatically. Nothing else was changed.', 'tbsh-safespot-restore-points' );

		return new WP_Error(
			'safespot_restore_fatal',
			$message,
			array(
				'status'      => 500,
				'rolled_back' => true,
			)
		);
	}

	/**
	 * Undoes the most recent restore using its safety SafeSpot.
	 *
	 * @return array|WP_Error
	 */
	public function rollback_last() {
		$last = self::last_restore();
		if ( ! $last || empty( $last['pre_point_id'] ) ) {
			return new WP_Error( 'safespot_no_rollback', __( 'There is no recent restore to roll back.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$pre = SafeSpot_Points::get( (int) $last['pre_point_id'] );
		if ( ! $pre ) {
			delete_site_transient( self::LAST_RESTORE );
			return new WP_Error( 'safespot_no_rollback', __( 'The safety SafeSpot for the last restore no longer exists.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$mode   = ! empty( $last['settings'] ) && '' !== $pre['settings_file'] ? 'files_settings' : 'files';
		$result = $this->restore(
			$pre['id'],
			array(
				'mode'        => $mode,
				'is_rollback' => true,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			if ( ! empty( $last['hold_added'] ) ) {
				SafeSpot_Settings::set_hold( $last['type'], $last['slug'], false );
			}
			delete_site_transient( self::LAST_RESTORE );
			/* translators: 1: item name, 2: version. */
			$result['message'] = sprintf( __( 'Rolled back the last restore. %1$s is back on version %2$s.', 'tbsh-safespot-restore-points' ), $pre['name'], $pre['version'] );
		}

		return $result;
	}

	/**
	 * Information about the last restore (kept for 24 hours).
	 *
	 * @return array|null
	 */
	public static function last_restore() {
		$last = get_site_transient( self::LAST_RESTORE );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Captures whether the item is active before the restore.
	 *
	 * @param array      $pi   Point item.
	 * @param array|null $item Installed item.
	 * @return array { active, network }
	 */
	private function capture_state( array $pi, $item ) {
		if ( ! $item ) {
			return array(
				'active'  => false,
				'network' => false,
			);
		}
		if ( 'plugin' === $pi['type'] ) {
			return array(
				'active'  => (bool) $item['active'],
				'network' => (bool) $item['network'],
			);
		}
		return array(
			'active'  => get_stylesheet() === $pi['slug'] || get_template() === $pi['slug'],
			'network' => false,
		);
	}

	/**
	 * Replaces the item's files with an archive and restores activation state.
	 *
	 * @param array $point SafeSpot row to install.
	 * @param array $state Activation state captured before the restore.
	 * @return true|WP_Error
	 */
	private function replace_files( array $point, array $state ) {
		$archive = $this->verify_archive( $point );
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}

		$connected = $this->fs_connect();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$pi        = $this->point_item( $point );
		$is_plugin = 'plugin' === $pi['type'];

		if ( $is_plugin && ( $state['active'] || is_plugin_active( $pi['slug'] ) ) ) {
			deactivate_plugins( $pi['slug'], true, $state['network'] ? true : null );
			if ( $state['active'] ) {
				$this->pending_activation = array(
					'slug'    => $pi['slug'],
					'network' => (bool) $state['network'],
				);
			}
		}

		if ( $state['active'] ) {
			$this->maintenance( true );
		}

		try {
			if ( $is_plugin && $pi['single_file'] ) {
				$result = $this->restore_single_file( $archive['path'], $pi['slug'] );
			} elseif ( $is_plugin ) {
				$upgrader = new SafeSpot_Plugin_Upgrader( new SafeSpot_Upgrader_Skin() );
				$result   = $upgrader->restore( $archive['path'], $pi['slug'] );
			} else {
				$upgrader = new SafeSpot_Theme_Upgrader( new SafeSpot_Upgrader_Skin() );
				$result   = $upgrader->restore( $archive['path'], $pi['slug'] );
			}
		} finally {
			$this->maintenance( false );
		}

		$this->invalidate_opcache( $pi['target'] );

		if ( $is_plugin ) {
			wp_clean_plugins_cache( true );
		} else {
			wp_clean_themes_cache();
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $is_plugin && $state['active'] ) {
			$activated                = $this->reactivate( $pi['slug'], $state['network'] );
			$this->pending_activation = null;
			if ( is_wp_error( $activated ) ) {
				/* translators: %s: error message. */
				return new WP_Error( 'safespot_reactivate_failed', sprintf( __( 'The files were restored, but the plugin could not be reactivated: %s', 'tbsh-safespot-restore-points' ), $activated->get_error_message() ) );
			}
		}

		return true;
	}

	/**
	 * Restores a single-file plugin (e.g. hello.php) with the Filesystem API.
	 *
	 * Core upgraders always install into a folder, which would change the plugin's
	 * basename, so the file is copied directly instead.
	 *
	 * @param string $zip  Archive path.
	 * @param string $file Plugin file name.
	 * @return true|WP_Error
	 */
	private function restore_single_file( $zip, $file ) {
		global $wp_filesystem;

		$work     = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'upgrade/safespot-' . strtolower( wp_generate_password( 8, false, false ) );
		$unzipped = unzip_file( $zip, $work );
		if ( is_wp_error( $unzipped ) ) {
			$wp_filesystem->delete( $work, true );
			return $unzipped;
		}

		$source = trailingslashit( $work ) . basename( $file );
		if ( ! $wp_filesystem->exists( $source ) ) {
			$wp_filesystem->delete( $work, true );
			return new WP_Error( 'safespot_single_missing', __( 'The plugin file was not found in the archive.', 'tbsh-safespot-restore-points' ) );
		}

		$copied = $wp_filesystem->copy( $source, trailingslashit( $wp_filesystem->wp_plugins_dir() ) . basename( $file ), true, FS_CHMOD_FILE );
		$wp_filesystem->delete( $work, true );

		if ( ! $copied ) {
			return new WP_Error( 'safespot_single_copy', __( 'Could not copy the plugin file into place.', 'tbsh-safespot-restore-points' ) );
		}
		return true;
	}

	/**
	 * Connects the global WP_Filesystem without prompting.
	 *
	 * @return true|WP_Error
	 */
	private function fs_connect() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		ob_start();
		$creds = request_filesystem_credentials( '', '', false, WP_CONTENT_DIR );
		ob_end_clean();

		if ( false === $creds || ! WP_Filesystem( $creds, WP_CONTENT_DIR ) ) {
			return new WP_Error( 'safespot_fs_credentials', __( 'WordPress could not access the filesystem. Define FS_METHOD (and FTP credentials if needed) in wp-config.php.', 'tbsh-safespot-restore-points' ) );
		}
		if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
			return $wp_filesystem->errors;
		}
		return true;
	}

	/**
	 * Toggles core maintenance mode (.maintenance file).
	 *
	 * @param bool $enable Enable or disable.
	 */
	private function maintenance( $enable ) {
		global $wp_filesystem;

		if ( ! $enable && ! $this->maintenance_on ) {
			return;
		}
		if ( ! $wp_filesystem && is_wp_error( $this->fs_connect() ) ) {
			return;
		}

		$upgrader = new WP_Upgrader( new SafeSpot_Upgrader_Skin() );
		$upgrader->init();
		$upgrader->maintenance_mode( (bool) $enable );
		$this->maintenance_on = (bool) $enable;
	}

	/**
	 * Shutdown handler: never leave the site in maintenance mode after a fatal error,
	 * and put back the activation of a plugin the dying restore had deactivated.
	 */
	public function shutdown() {
		if ( $this->maintenance_on ) {
			$this->maintenance( false );
		}

		$pending = $this->pending_activation;
		if ( $pending ) {
			$this->pending_activation = null;
			$this->reactivate( $pending['slug'], $pending['network'] );
		}
	}

	/**
	 * Marks a restored plugin active again without loading its code in this request.
	 *
	 * Equivalent to a silent activate_plugin(), but a fatal error in the restored code
	 * cannot kill the restore request; the loopback health check detects it instead.
	 *
	 * @param string $slug    Plugin basename.
	 * @param bool   $network Network-wide activation.
	 * @return true|WP_Error
	 */
	private function reactivate( $slug, $network ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$valid = validate_plugin( $slug );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( $network && is_multisite() ) {
			$active          = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active[ $slug ] = time();
			update_site_option( 'active_sitewide_plugins', $active );
		} else {
			$active   = (array) get_option( 'active_plugins', array() );
			$active[] = $slug;
			$active   = array_values( array_unique( $active ) );
			sort( $active );
			update_option( 'active_plugins', $active );
		}
		return true;
	}

	/**
	 * Clears OPcache for restored files so the next request runs the restored code.
	 *
	 * @param string $path File or folder.
	 */
	private function invalidate_opcache( $path ) {
		if ( ! function_exists( 'wp_opcache_invalidate' ) || ! file_exists( $path ) ) {
			return;
		}
		if ( is_file( $path ) ) {
			wp_opcache_invalidate( $path, true );
			return;
		}
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( $path );
			return;
		}
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					wp_opcache_invalidate( $file->getPathname(), true );
				}
			}
		} catch ( Exception $e ) {
			return;
		}
	}
}
