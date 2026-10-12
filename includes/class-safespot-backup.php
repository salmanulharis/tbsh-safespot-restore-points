<?php
/**
 * Creating and managing SafeSpots.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves plugin/theme archives and applies retention rules.
 */
class SafeSpot_Backup {

	/**
	 * Creates a SafeSpot.
	 *
	 * @param string $type Item type (plugin|theme).
	 * @param string $slug Plugin file or theme stylesheet.
	 * @param array  $args {
	 *     Optional arguments.
	 *
	 *     @type string    $note             Note.
	 *     @type string    $source           manual|cli|bulk|auto_update|pre_restore|pre_delete.
	 *     @type bool|null $include_settings Include a wp_options snapshot (null = use setting).
	 *     @type int       $lock_wait        Seconds to wait for a busy lock.
	 *     @type bool      $prune            Apply retention afterwards.
	 *     @type int[]     $protect_ids      IDs retention must keep.
	 * }
	 * @return array|WP_Error Formatted SafeSpot. `reused` is true when nothing changed since the newest SafeSpot, which is returned instead of a duplicate.
	 */
	public function save( $type, $slug, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'note'             => '',
				'source'           => 'manual',
				'include_settings' => null,
				'lock_wait'        => 0,
				'prune'            => true,
				'protect_ids'      => array(),
			)
		);

		$item = SafeSpot_Items::get( $type, $slug );
		if ( ! $item ) {
			return new WP_Error( 'safespot_not_installed', __( 'That plugin or theme is not installed.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$include_settings = null === $args['include_settings'] ? (bool) SafeSpot_Settings::get( 'settings_snapshot' ) : (bool) $args['include_settings'];
		$args['note']     = sanitize_textarea_field( (string) $args['note'] );

		if ( ! SafeSpot_Lock::acquire( 'save:' . $type . ':' . $slug, (int) $args['lock_wait'] ) ) {
			return SafeSpot_Lock::busy_error();
		}

		try {
			$result = $this->do_save( $item, $args, $include_settings );
		} finally {
			SafeSpot_Zip::forget_scan();
			SafeSpot_Lock::release();
		}

		$log_action = in_array( $args['source'], array( 'auto_update', 'pre_restore', 'pre_delete' ), true ) ? 'auto' : 'save';

		if ( is_wp_error( $result ) && 'safespot_cancelled' === $result->get_error_code() ) {
			SafeSpot_Logger::log(
				$log_action,
				'warning',
				/* translators: %s: item name. */
				sprintf( __( 'Saving a SafeSpot of %s was cancelled. Nothing was saved.', 'tbsh-safespot-restore-points' ), $item['name'] ),
				array(
					'type' => $type,
					'slug' => $slug,
					'name' => $item['name'],
				)
			);
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			SafeSpot_Logger::log(
				$log_action,
				'error',
				/* translators: 1: item name, 2: error message. */
				sprintf( __( 'Could not save a SafeSpot of %1$s: %2$s', 'tbsh-safespot-restore-points' ), $item['name'], $result->get_error_message() ),
				array(
					'type' => $type,
					'slug' => $slug,
					'name' => $item['name'],
				)
			);
			return $result;
		}

		if ( $result['reused'] ) {
			return $this->reuse( $result['point'], $item, $args, $log_action );
		}

		$point = $result['point'];

		SafeSpot_Logger::log(
			$log_action,
			'success',
			sprintf(
				/* translators: 1: item name, 2: version, 3: size, 4: source label. */
				__( 'Saved %1$s %2$s (%3$s) – %4$s', 'tbsh-safespot-restore-points' ),
				$item['name'],
				$item['version'],
				size_format( $point['file_size'], 1 ),
				SafeSpot_Points::source_label( $point['source'] )
			),
			array(
				'type'     => $type,
				'slug'     => $slug,
				'name'     => $item['name'],
				'point_id' => $point['id'],
			)
		);

		if ( $args['prune'] ) {
			$protect   = array_map( 'intval', (array) $args['protect_ids'] );
			$protect[] = (int) $point['id'];
			$this->prune_item( $type, $slug, $protect );
		}

		$formatted           = SafeSpot_Points::format( $point );
		$formatted['reused'] = false;
		return $formatted;
	}

	/**
	 * Returns an existing identical SafeSpot instead of saving a duplicate.
	 *
	 * @param array  $point      Existing SafeSpot row.
	 * @param array  $item       Item being saved.
	 * @param array  $args       Save arguments.
	 * @param string $log_action Log action.
	 * @return array Formatted SafeSpot with reused = true.
	 */
	private function reuse( array $point, array $item, array $args, $log_action ) {
		// The kept SafeSpot now stands in for a new one: retention must treat it as recent.
		$update = array( 'reused_at' => current_time( 'mysql', true ) );

		// Keep the note the user typed when the existing SafeSpot has none.
		if ( '' !== $args['note'] && '' === $point['note'] ) {
			$update['note'] = substr( $args['note'], 0, 1000 );
		}
		SafeSpot_Points::update( $point['id'], $update );
		$point = SafeSpot_Points::get( $point['id'] );

		SafeSpot_Logger::log(
			$log_action,
			'info',
			sprintf(
				/* translators: 1: item name, 2: version, 3: date. */
				__( 'No new SafeSpot needed for %1$s %2$s: nothing has changed since the SafeSpot saved %3$s, so that one is kept.', 'tbsh-safespot-restore-points' ),
				$item['name'],
				$item['version'],
				SafeSpot_Points::human_date( $point['created_ts'] )
			),
			array(
				'type'     => $item['type'],
				'slug'     => $item['slug'],
				'name'     => $item['name'],
				'point_id' => $point['id'],
			)
		);

		/**
		 * Fires when a save request is answered with an existing, identical SafeSpot.
		 *
		 * @param array $point SafeSpot record that was kept.
		 * @param array $item  Item that was being saved.
		 * @param array $args  Save arguments.
		 */
		do_action( 'safespot_duplicate_skipped', $point, $item, $args );

		$formatted           = SafeSpot_Points::format( $point );
		$formatted['reused'] = true;
		return $formatted;
	}

	/**
	 * Performs the archive work (lock already held).
	 *
	 * The duplicate check runs here, inside the lock, so concurrent requests for the
	 * same item (double clicks, a retried update, cron and the admin screen working
	 * the queue at once) cannot both save the same content.
	 *
	 * @param array $item             Item.
	 * @param array $args             Arguments.
	 * @param bool  $include_settings Whether to snapshot options.
	 * @return array|WP_Error { point: raw SafeSpot row, reused: bool }.
	 */
	private function do_save( array $item, array $args, $include_settings ) {
		SafeSpot_Plugin::raise_limits();

		/**
		 * Filters relative paths (from the item folder root) excluded from archives.
		 *
		 * Empty by default so SafeSpots are exact copies.
		 *
		 * @param string[] $exclude Relative paths or fnmatch() patterns, e.g. ".git".
		 * @param array    $item    Item being saved.
		 */
		$exclude = (array) apply_filters( 'safespot_backup_exclude', array(), $item );

		$fingerprint = SafeSpot_Zip::fingerprint( $item['path'], $exclude );
		if ( is_wp_error( $fingerprint ) ) {
			return $fingerprint;
		}

		$snapshot             = null;
		$settings_fingerprint = '';
		if ( $include_settings ) {
			SafeSpot_Lock::phase( 'settings' );
			$snapshot             = SafeSpot_Settings_Snapshot::export( $item['type'], $item['slug'] );
			$settings_fingerprint = SafeSpot_Settings_Snapshot::fingerprint( $snapshot );
		}

		$identical = $this->find_identical( $item, $fingerprint, $settings_fingerprint, $exclude, $args );
		if ( $identical ) {
			return array(
				'point'  => $identical,
				'reused' => true,
			);
		}

		/**
		 * Fires before a SafeSpot archive is created.
		 *
		 * @param array $item Item being saved (type, slug, name, version, path...).
		 * @param array $args Save arguments (note, source...).
		 */
		do_action( 'safespot_before_save', $item, $args );

		$dir = SafeSpot_Storage::get_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$free = SafeSpot_Storage::free_space( $dir );
		if ( null !== $free && $free < 5 * MB_IN_BYTES ) {
			return new WP_Error( 'safespot_disk_full', __( 'There is not enough free disk space to save a SafeSpot.', 'tbsh-safespot-restore-points' ) );
		}

		$tmp = SafeSpot_Storage::temp_dir();
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_name = SafeSpot_Storage::unique_name( $item['type'], $item['folder'], $item['version'] );
		$partial   = $tmp . $file_name . '.part';
		$final     = $dir . $file_name;

		$zip = SafeSpot_Zip::create( $item['path'], $partial, $exclude );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$fs = SafeSpot_Storage::fs();
		if ( ! $fs->move( $partial, $final, true ) ) {
			$fs->delete( $partial );
			return new WP_Error( 'safespot_move_failed', __( 'Could not move the archive into the storage folder.', 'tbsh-safespot-restore-points' ) );
		}

		if ( SafeSpot_Lock::phase( 'verifying' ) ) {
			$fs->delete( $final );
			return SafeSpot_Lock::cancelled_error();
		}

		$required = $this->required_entry( $item );
		$valid    = SafeSpot_Zip::validate( $final, $item['single_file'] ? basename( $item['slug'] ) : $item['folder'], $item['single_file'], $required );
		if ( is_wp_error( $valid ) ) {
			$fs->delete( $final );
			return $valid;
		}

		$settings = array(
			'file_name' => '',
			'checksum'  => '',
			'count'     => 0,
		);
		if ( null !== $snapshot ) {
			$written = SafeSpot_Settings_Snapshot::write( $snapshot, preg_replace( '/\.zip$/', '', $file_name ) . '.settings.json' );
			if ( is_wp_error( $written ) ) {
				$fs->delete( $final );
				return $written;
			}
			$settings = $written;
		}

		// Last chance to cancel: after this the SafeSpot exists.
		if ( SafeSpot_Lock::checkpoint() ) {
			$fs->delete( $final );
			SafeSpot_Storage::delete( $settings['file_name'] );
			return SafeSpot_Lock::cancelled_error();
		}

		$row = array(
			'item_type'            => $item['type'],
			'slug'                 => $item['slug'],
			'name'                 => $item['name'],
			'version'              => $item['version'],
			'file_name'            => $file_name,
			'file_size'            => (int) filesize( $final ),
			'uncompressed_size'    => $zip['uncompressed_size'],
			'file_count'           => $zip['file_count'],
			'checksum'             => hash_file( 'sha256', $final ),
			'settings_file'        => $settings['file_name'],
			'settings_checksum'    => $settings['checksum'],
			'settings_count'       => $settings['count'],
			'fingerprint'          => $fingerprint,
			'settings_fingerprint' => $settings_fingerprint,
			'note'                 => $args['note'],
			'source'               => $args['source'],
			'requires_wp'          => $item['requires_wp'],
			'requires_php'         => $item['requires_php'],
			'parent_theme'         => $item['parent'],
			'created_by'           => get_current_user_id(),
		);

		$id = SafeSpot_Points::insert( $row );
		if ( is_wp_error( $id ) ) {
			$fs->delete( $final );
			SafeSpot_Storage::delete( $settings['file_name'] );
			return $id;
		}

		$point = SafeSpot_Points::get( $id );

		/**
		 * Fires after a SafeSpot has been saved.
		 *
		 * @param array $point SafeSpot record.
		 * @param array $item  Item that was saved.
		 * @param array $args  Save arguments.
		 */
		do_action( 'safespot_after_save', $point, $item, $args );

		return array(
			'point'  => $point,
			'reused' => false,
		);
	}

	/**
	 * Existing SafeSpot of the item that holds exactly the content about to be saved.
	 *
	 * Every SafeSpot of the same version is a candidate (newest first), not only the
	 * newest one: restoring back and forth between versions means the current version
	 * usually matches an older SafeSpot. Candidates whose file count or total size
	 * differ are skipped without reading anything. SafeSpots saved before fingerprints
	 * existed get theirs computed from the archive once and stored. The match's archive
	 * (and settings file, when settings are requested) must still pass their checksums.
	 *
	 * Retention keeps a reused SafeSpot as if it had just been saved (see reuse()).
	 *
	 * @param array  $item                 Item being saved.
	 * @param string $fingerprint          Files fingerprint.
	 * @param string $settings_fingerprint Settings fingerprint ('' = settings not requested).
	 * @param array  $exclude              Excluded paths the fingerprint was computed with.
	 * @param array  $args                 Save arguments.
	 * @return array|null Raw SafeSpot row.
	 */
	private function find_identical( array $item, $fingerprint, $settings_fingerprint, array $exclude, array $args ) {
		$stats = SafeSpot_Zip::scan_stats( $item['path'], $exclude );
		if ( is_wp_error( $stats ) ) {
			return null;
		}

		$candidates = SafeSpot_Points::query(
			array(
				'type'    => $item['type'],
				'slug'    => $item['slug'],
				'version' => $item['version'],
			)
		);

		foreach ( $candidates as $candidate ) {
			if ( SafeSpot_Lock::is_cancelled() ) {
				return null;
			}
			// Saved by the same code from the same file list, so these must match exactly.
			if ( $candidate['file_count'] !== $stats['count'] || $candidate['uncompressed_size'] !== $stats['size'] ) {
				continue;
			}
			if ( '' !== $settings_fingerprint && '' === $candidate['settings_file'] ) {
				continue;
			}

			$archive_verified = false;
			if ( '' === $candidate['fingerprint'] ) {
				if ( ! $this->verify_archive( $candidate ) ) {
					continue;
				}
				$archive_verified = true;
				$candidate        = $this->backfill_fingerprint( $candidate );
			}
			if ( '' === $candidate['fingerprint'] || ! hash_equals( $candidate['fingerprint'], $fingerprint ) ) {
				continue;
			}

			if ( '' !== $settings_fingerprint && ! $this->settings_match( $candidate, $settings_fingerprint ) ) {
				continue;
			}

			if ( ! $archive_verified && ! $this->verify_archive( $candidate ) ) {
				continue;
			}

			/**
			 * Filters whether an identical existing SafeSpot is kept instead of saving a new one.
			 *
			 * @param bool  $reuse     Whether to reuse the existing SafeSpot (default true).
			 * @param array $candidate Existing SafeSpot record with the same content.
			 * @param array $item      Item being saved.
			 * @param array $args      Save arguments (note, source...).
			 */
			return apply_filters( 'safespot_reuse_identical', true, $candidate, $item, $args ) ? $candidate : null;
		}

		return null;
	}

	/**
	 * Whether a SafeSpot's archive is present and passes its checksum.
	 *
	 * @param array $point SafeSpot row.
	 * @return bool
	 */
	private function verify_archive( array $point ) {
		$path = SafeSpot_Storage::path( $point['file_name'] );
		return ! is_wp_error( $path ) && is_readable( $path ) && '' !== $point['checksum'] && hash_equals( $point['checksum'], (string) hash_file( 'sha256', $path ) );
	}

	/**
	 * Computes and stores the fingerprint of a SafeSpot saved before fingerprints existed.
	 *
	 * Call only after verify_archive() passed, so the stored value describes the real
	 * archive. On failure the row is returned unchanged (and is simply not reused).
	 *
	 * @param array $point SafeSpot row with an empty fingerprint.
	 * @return array Row with its fingerprint filled in when it could be computed.
	 */
	private function backfill_fingerprint( array $point ) {
		$fingerprint = SafeSpot_Zip::archive_fingerprint( SafeSpot_Storage::path( $point['file_name'] ) );
		if ( is_wp_error( $fingerprint ) ) {
			return $point;
		}
		SafeSpot_Points::update( $point['id'], array( 'fingerprint' => $fingerprint ) );
		$point['fingerprint'] = $fingerprint;
		return $point;
	}

	/**
	 * Whether a SafeSpot's settings snapshot holds exactly the given settings.
	 *
	 * Fills in the settings fingerprint of SafeSpots saved before fingerprints existed.
	 * SafeSpot_Settings_Snapshot::read() verifies the file's checksum.
	 *
	 * @param array  $point                SafeSpot row with a settings file.
	 * @param string $settings_fingerprint Settings fingerprint to match.
	 * @return bool
	 */
	private function settings_match( array $point, $settings_fingerprint ) {
		$snapshot = SafeSpot_Settings_Snapshot::read( $point );
		if ( is_wp_error( $snapshot ) ) {
			return false;
		}
		if ( '' === $point['settings_fingerprint'] ) {
			$point['settings_fingerprint'] = SafeSpot_Settings_Snapshot::fingerprint( $snapshot );
			SafeSpot_Points::update( $point['id'], array( 'settings_fingerprint' => $point['settings_fingerprint'] ) );
		}
		return hash_equals( $point['settings_fingerprint'], $settings_fingerprint );
	}

	/**
	 * Archive entry that proves the archive contains the item.
	 *
	 * @param array $item Item (or SafeSpot-derived item).
	 * @return string
	 */
	public function required_entry( array $item ) {
		if ( 'theme' === $item['type'] ) {
			return $item['folder'] . '/style.css';
		}
		return $item['single_file'] ? basename( $item['slug'] ) : $item['slug'];
	}

	/**
	 * Deletes a SafeSpot and its files.
	 *
	 * @param int  $id  ID.
	 * @param bool $log Whether to log the deletion.
	 * @return true|WP_Error
	 */
	public function delete( $id, $log = true ) {
		$point = SafeSpot_Points::get( $id );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		SafeSpot_Storage::delete( $point['file_name'] );
		SafeSpot_Storage::delete( $point['settings_file'] );
		SafeSpot_Points::delete( $id );

		if ( $log ) {
			SafeSpot_Logger::log(
				'delete',
				'success',
				/* translators: 1: item name, 2: version, 3: date. */
				sprintf( __( 'Deleted SafeSpot of %1$s %2$s from %3$s', 'tbsh-safespot-restore-points' ), $point['name'], $point['version'], SafeSpot_Points::human_date( $point['created_ts'] ) ),
				array(
					'type'     => $point['item_type'],
					'slug'     => $point['slug'],
					'name'     => $point['name'],
					'point_id' => $point['id'],
				)
			);
		}

		/**
		 * Fires after a SafeSpot is deleted.
		 *
		 * @param array $point Deleted SafeSpot record.
		 */
		do_action( 'safespot_after_delete', $point );

		return true;
	}

	/**
	 * Applies retention rules to one item.
	 *
	 * @param string $type    Type.
	 * @param string $slug    Slug.
	 * @param int[]  $protect IDs to keep.
	 * @return int Number of SafeSpots removed.
	 */
	public function prune_item( $type, $slug, array $protect = array() ) {
		$keep    = (int) SafeSpot_Settings::get( 'retention_count' );
		$max_age = (int) SafeSpot_Settings::get( 'max_age_days' );
		$removed = 0;

		$last = get_site_transient( 'safespot_last_restore' );
		if ( is_array( $last ) && ! empty( $last['pre_point_id'] ) ) {
			$protect[] = (int) $last['pre_point_id'];
		}

		foreach ( SafeSpot_Points::prunable( $type, $slug, $keep, $max_age, array_map( 'intval', $protect ) ) as $point ) {
			$this->delete( $point['id'], false );
			++$removed;
		}

		if ( $removed ) {
			SafeSpot_Logger::log(
				'cleanup',
				'info',
				/* translators: %d: number of SafeSpots. */
				sprintf( _n( 'Retention removed %d old SafeSpot.', 'Retention removed %d old SafeSpots.', $removed, 'tbsh-safespot-restore-points' ), $removed ),
				array(
					'type'    => $type,
					'slug'    => $slug,
					'user_id' => 0,
				)
			);
		}

		return $removed;
	}

	/**
	 * Applies retention to every item (daily cron).
	 *
	 * @return int Number removed.
	 */
	public function prune_all() {
		$removed = 0;
		foreach ( SafeSpot_Points::items() as $row ) {
			$removed += $this->prune_item( $row['item_type'], $row['slug'] );
		}
		return $removed;
	}

	/**
	 * Emails the site admin about a failure when enabled.
	 *
	 * @param string $subject Subject (without site name).
	 * @param string $message Body.
	 */
	public static function notify_failure( $subject, $message ) {
		if ( ! SafeSpot_Settings::get( 'email_on_failure' ) ) {
			return;
		}
		$site = wp_specialchars_decode( is_multisite() ? get_network()->site_name : get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			SafeSpot_Settings::notify_email(),
			sprintf( '[%s] %s', $site, $subject ),
			$message . "\n\n" . ( is_multisite() ? network_admin_url( 'admin.php?page=safespot&tab=log' ) : admin_url( 'admin.php?page=safespot&tab=log' ) )
		);
	}
}
