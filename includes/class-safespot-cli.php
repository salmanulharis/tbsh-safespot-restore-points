<?php
/**
 * WP-CLI commands.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Save, list, restore and delete plugin/theme restore points.
 */
class SafeSpot_CLI {

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
	 * Running jobs.
	 *
	 * @var SafeSpot_Jobs
	 */
	private $jobs;

	/**
	 * Registers the "safespot" command.
	 *
	 * @param SafeSpot_Backup  $backup  Backup service.
	 * @param SafeSpot_Restore $restore Restore service.
	 * @param SafeSpot_Jobs    $jobs    Running jobs.
	 */
	public static function register( SafeSpot_Backup $backup, SafeSpot_Restore $restore, SafeSpot_Jobs $jobs ) {
		$instance          = new self();
		$instance->backup  = $backup;
		$instance->restore = $restore;
		$instance->jobs    = $jobs;
		WP_CLI::add_command( 'safespot', $instance );
	}

	/**
	 * Saves a SafeSpot of a plugin or theme.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin folder (e.g. akismet), plugin file (akismet/akismet.php) or theme folder.
	 *
	 * [--type=<type>]
	 * : plugin or theme. Detected automatically when omitted.
	 * ---
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * [--note=<note>]
	 * : Optional note, e.g. "Stable before v3.0 update".
	 *
	 * [--with-settings]
	 * : Also snapshot the item's wp_options rows.
	 *
	 * [--porcelain]
	 * : Output only the new SafeSpot ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp safespot save akismet --note="Before 6.0"
	 *     wp safespot save twentytwentyfive --type=theme
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function save( $args, $assoc_args ) {
		list( $type, $slug ) = $this->resolve( $args[0], $assoc_args['type'] ?? '' );

		$point = $this->backup->save(
			$type,
			$slug,
			array(
				'note'             => (string) ( $assoc_args['note'] ?? '' ),
				'source'           => 'cli',
				'include_settings' => WP_CLI\Utils\get_flag_value( $assoc_args, 'with-settings', null ),
				'lock_wait'        => 30,
			)
		);

		if ( is_wp_error( $point ) ) {
			WP_CLI::error( $point->get_error_message() );
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $point['id'] );
			return;
		}

		if ( $point['reused'] ) {
			WP_CLI::success( sprintf( 'No changes since SafeSpot #%d (%s %s, %s). Kept it instead of saving a duplicate.', $point['id'], $point['name'], $point['version'], $point['created_human'] ) );
			return;
		}

		WP_CLI::success( sprintf( 'Saved SafeSpot #%d: %s %s (%s).', $point['id'], $point['name'], $point['version'], $point['size_human'] ) );
	}

	/**
	 * Lists SafeSpots.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter by plugin or theme.
	 *
	 * [--slug=<slug>]
	 * : Filter by plugin folder/file or theme folder.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show (id, type, slug, name, version, date, size, source, locked, note).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp safespot list
	 *     wp safespot list --type=plugin --slug=akismet --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		unset( $args );
		$type = (string) ( $assoc_args['type'] ?? '' );
		$slug = (string) ( $assoc_args['slug'] ?? '' );

		if ( '' !== $slug ) {
			list( $type, $slug ) = $this->resolve( $slug, $type );
		}

		$rows = array();
		foreach ( SafeSpot_Points::query(
			array(
				'type' => $type,
				'slug' => $slug,
			)
		) as $point ) {
			$f      = SafeSpot_Points::format( $point );
			$rows[] = array(
				'id'      => $f['id'],
				'type'    => $f['type'],
				'slug'    => $f['slug'],
				'name'    => $f['name'],
				'version' => $f['version'],
				'date'    => $f['created_human'],
				'size'    => $f['size_human'],
				'source'  => $f['source_label'],
				'locked'  => $f['locked'] ? 'yes' : 'no',
				'note'    => $f['note'],
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}
		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $rows ) );
			return;
		}

		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : array( 'id', 'type', 'slug', 'name', 'version', 'date', 'size', 'source', 'locked', 'note' );
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Restores a SafeSpot.
	 *
	 * A SafeSpot of the current version is saved first so the restore can be undone
	 * with `wp safespot rollback`.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : SafeSpot ID (see `wp safespot list`).
	 *
	 * [--with-settings]
	 * : Also restore the settings snapshot, if the SafeSpot has one.
	 *
	 * [--pause-updates]
	 * : Pause updates for the item afterwards.
	 *
	 * [--skip-health-check]
	 * : Do not load the site afterwards to detect fatal errors.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp safespot restore 12 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function restore( $args, $assoc_args ) {
		$id        = absint( $args[0] );
		$preflight = $this->restore->preflight( $id );
		if ( is_wp_error( $preflight ) ) {
			WP_CLI::error( $preflight->get_error_message() );
		}

		foreach ( $preflight['checks'] as $check ) {
			$line = sprintf( '[%s] %s: %s', strtoupper( $check['status'] ), $check['label'], $check['message'] );
			if ( 'fail' === $check['status'] ) {
				WP_CLI::warning( $line );
			} else {
				WP_CLI::log( $line );
			}
		}
		if ( ! $preflight['can_restore'] ) {
			WP_CLI::error( 'Pre-restore checks failed. Nothing was changed.' );
		}

		$from = '' !== $preflight['current_version'] ? $preflight['current_version'] : 'not installed';
		WP_CLI::confirm( sprintf( 'Restore %s from %s to %s?', $preflight['point']['name'], $from, $preflight['target_version'] ), $assoc_args );

		$result = $this->restore->restore(
			$id,
			array(
				'mode'          => WP_CLI\Utils\get_flag_value( $assoc_args, 'with-settings', false ) ? 'files_settings' : 'files',
				'pause_updates' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'pause-updates', false ),
				'health_check'  => WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-health-check', false ) ? false : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		WP_CLI::success( $result['message'] );
	}

	/**
	 * Undoes the most recent restore.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rollback( $args, $assoc_args ) {
		unset( $args );
		$last = SafeSpot_Restore::last_restore();
		if ( ! $last ) {
			WP_CLI::error( 'There is no recent restore to roll back.' );
		}
		WP_CLI::confirm( sprintf( 'Roll back %s from %s to %s?', $last['name'], $last['version_to'], $last['version_from'] ), $assoc_args );

		$result = $this->restore->rollback_last();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( $result['message'] );
	}

	/**
	 * Deletes a SafeSpot.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more SafeSpot IDs.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp safespot delete 12 13 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		WP_CLI::confirm( sprintf( 'Delete %d SafeSpot(s)? This cannot be undone.', count( $args ) ), $assoc_args );

		$errors = 0;
		foreach ( $args as $id ) {
			$result = $this->backup->delete( absint( $id ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( '#%d: %s', absint( $id ), $result->get_error_message() ) );
				++$errors;
			} else {
				WP_CLI::log( sprintf( 'Deleted SafeSpot #%d.', absint( $id ) ) );
			}
		}

		if ( $errors ) {
			WP_CLI::error( sprintf( '%d SafeSpot(s) could not be deleted.', $errors ) );
		}
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Shows the SafeSpot operation that is running, if any.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp safespot status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$status = $this->jobs->status();

		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status ) );
			return;
		}
		if ( ! $status['running'] ) {
			WP_CLI::success( 'Nothing is running.' );
			return;
		}
		if ( $status['lock'] ) {
			WP_CLI::line( 'Running: ' . $status['lock']['summary'] );
			WP_CLI::line( 'Cancellable: ' . ( $status['lock']['cancellable'] ? 'yes' : 'no, files are being replaced' ) );
			if ( $status['lock']['cancel_requested'] ) {
				WP_CLI::line( 'A cancel request is pending.' );
			}
		}
		if ( $status['queue']['running'] ) {
			WP_CLI::line( sprintf( 'Bulk queue: %d of %d done, %d pending.', $status['queue']['done'], $status['queue']['total'], $status['queue']['pending'] ) );
		}
	}

	/**
	 * Cancels the running SafeSpot operation (and the bulk queue it belongs to).
	 *
	 * Backups are stopped at their next checkpoint and leave nothing behind. A restore
	 * can only be cancelled before it starts replacing files.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Remove the lock of an operation whose process stopped responding.
	 *
	 * [--wait=<seconds>]
	 * : Seconds to wait for the operation to stop.
	 * ---
	 * default: 15
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp safespot cancel
	 *     wp safespot cancel --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cancel( $args, $assoc_args ) {
		unset( $args );
		$force  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$result = $this->jobs->cancel( $force, absint( $assoc_args['wait'] ?? 15 ), false );

		if ( is_wp_error( $result ) ) {
			if ( 'safespot_nothing_running' === $result->get_error_code() ) {
				WP_CLI::success( $result->get_error_message() );
				return;
			}
			WP_CLI::error( $result->get_error_message() );
		}
		if ( 'requested' === $result['status'] ) {
			WP_CLI::warning( $result['message'] );
			return;
		}
		WP_CLI::success( $result['message'] );
	}

	/**
	 * Resolves a CLI slug to a type and canonical slug.
	 *
	 * @param string $input Input slug.
	 * @param string $type  Requested type.
	 * @return array { type, slug }
	 */
	private function resolve( $input, $type ) {
		$plugin = 'theme' === $type ? null : SafeSpot_Items::resolve_plugin( $input );
		$theme  = ( 'plugin' !== $type && SafeSpot_Items::get( 'theme', $input ) ) ? $input : null;

		if ( ! $plugin && ! $theme ) {
			// Allow SafeSpots of items that are no longer installed.
			foreach ( SafeSpot_Points::items() as $row ) {
				$folder = 'plugin' === $row['item_type'] ? strtok( $row['slug'], '/' ) : $row['slug'];
				if ( ( '' === $type || $row['item_type'] === $type ) && ( $row['slug'] === $input || $folder === $input || basename( $row['slug'], '.php' ) === $input ) ) {
					return array( $row['item_type'], $row['slug'] );
				}
			}
			WP_CLI::error( sprintf( 'No plugin or theme found for "%s".', $input ) );
		}
		if ( $plugin && $theme ) {
			WP_CLI::error( sprintf( '"%s" matches both a plugin and a theme. Add --type=plugin or --type=theme.', $input ) );
		}

		return $plugin ? array( 'plugin', $plugin ) : array( 'theme', $theme );
	}
}
