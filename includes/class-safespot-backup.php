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
	 * @return array|WP_Error Formatted SafeSpot.
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
			return new WP_Error( 'safespot_locked', __( 'Another SafeSpot backup or restore is running. Please try again in a moment.', 'tbsh-safespot-restore-points' ), array( 'status' => 409 ) );
		}

		try {
			$result = $this->do_save( $item, $args, $include_settings );
		} finally {
			SafeSpot_Lock::release();
		}

		$log_action = in_array( $args['source'], array( 'auto_update', 'pre_restore', 'pre_delete' ), true ) ? 'auto' : 'save';

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

		SafeSpot_Logger::log(
			$log_action,
			'success',
			sprintf(
				/* translators: 1: item name, 2: version, 3: size, 4: source label. */
				__( 'Saved %1$s %2$s (%3$s) – %4$s', 'tbsh-safespot-restore-points' ),
				$item['name'],
				$item['version'],
				size_format( $result['file_size'], 1 ),
				SafeSpot_Points::source_label( $result['source'] )
			),
			array(
				'type'     => $type,
				'slug'     => $slug,
				'name'     => $item['name'],
				'point_id' => $result['id'],
			)
		);

		if ( $args['prune'] ) {
			$protect   = array_map( 'intval', (array) $args['protect_ids'] );
			$protect[] = (int) $result['id'];
			$this->prune_item( $type, $slug, $protect );
		}

		return SafeSpot_Points::format( $result );
	}

	/**
	 * Performs the archive work (lock already held).
	 *
	 * @param array $item             Item.
	 * @param array $args             Arguments.
	 * @param bool  $include_settings Whether to snapshot options.
	 * @return array|WP_Error Raw SafeSpot row.
	 */
	private function do_save( array $item, array $args, $include_settings ) {
		SafeSpot_Plugin::raise_limits();

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

		/**
		 * Filters relative paths (from the item folder root) excluded from archives.
		 *
		 * Empty by default so SafeSpots are exact copies.
		 *
		 * @param string[] $exclude Relative paths or fnmatch() patterns, e.g. ".git".
		 * @param array    $item    Item being saved.
		 */
		$exclude = (array) apply_filters( 'safespot_backup_exclude', array(), $item );

		$zip = SafeSpot_Zip::create( $item['path'], $partial, $exclude );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$fs = SafeSpot_Storage::fs();
		if ( ! $fs->move( $partial, $final, true ) ) {
			$fs->delete( $partial );
			return new WP_Error( 'safespot_move_failed', __( 'Could not move the archive into the storage folder.', 'tbsh-safespot-restore-points' ) );
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
		if ( $include_settings ) {
			$snapshot = SafeSpot_Settings_Snapshot::export( $item['type'], $item['slug'] );
			$written  = SafeSpot_Settings_Snapshot::write( $snapshot, preg_replace( '/\.zip$/', '', $file_name ) . '.settings.json' );
			if ( is_wp_error( $written ) ) {
				$fs->delete( $final );
				return $written;
			}
			$settings = $written;
		}

		$row = array(
			'item_type'         => $item['type'],
			'slug'              => $item['slug'],
			'name'              => $item['name'],
			'version'           => $item['version'],
			'file_name'         => $file_name,
			'file_size'         => (int) filesize( $final ),
			'uncompressed_size' => $zip['uncompressed_size'],
			'file_count'        => $zip['file_count'],
			'checksum'          => hash_file( 'sha256', $final ),
			'settings_file'     => $settings['file_name'],
			'settings_checksum' => $settings['checksum'],
			'settings_count'    => $settings['count'],
			'note'              => $args['note'],
			'source'            => $args['source'],
			'requires_wp'       => $item['requires_wp'],
			'requires_php'      => $item['requires_php'],
			'parent_theme'      => $item['parent'],
			'created_by'        => get_current_user_id(),
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

		return $point;
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
