<?php
/**
 * SafeSpot metadata repository.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name comes from $wpdb->base_prefix; all values go through $wpdb->prepare().

/**
 * CRUD for the {base_prefix}safespot_points table.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * -- Custom table; the table name comes from $wpdb->base_prefix and every value is prepared.
 */
class SafeSpot_Points {

	const SOURCES = array( 'manual', 'cli', 'bulk', 'auto_update', 'pre_restore', 'pre_delete' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->base_prefix . 'safespot_points';
	}

	/**
	 * Inserts a SafeSpot.
	 *
	 * @param array $data Column values.
	 * @return int|WP_Error New ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$row = array(
			'item_type'            => 'theme' === ( $data['item_type'] ?? '' ) ? 'theme' : 'plugin',
			'slug'                 => (string) ( $data['slug'] ?? '' ),
			'name'                 => (string) ( $data['name'] ?? '' ),
			'version'              => (string) ( $data['version'] ?? '' ),
			'file_name'            => (string) ( $data['file_name'] ?? '' ),
			'file_size'            => (int) ( $data['file_size'] ?? 0 ),
			'uncompressed_size'    => (int) ( $data['uncompressed_size'] ?? 0 ),
			'file_count'           => (int) ( $data['file_count'] ?? 0 ),
			'checksum'             => (string) ( $data['checksum'] ?? '' ),
			'settings_file'        => (string) ( $data['settings_file'] ?? '' ),
			'settings_checksum'    => (string) ( $data['settings_checksum'] ?? '' ),
			'settings_count'       => (int) ( $data['settings_count'] ?? 0 ),
			'fingerprint'          => (string) ( $data['fingerprint'] ?? '' ),
			'settings_fingerprint' => (string) ( $data['settings_fingerprint'] ?? '' ),
			'note'                 => (string) ( $data['note'] ?? '' ),
			'source'               => in_array( $data['source'] ?? '', self::SOURCES, true ) ? $data['source'] : 'manual',
			'is_locked'            => empty( $data['is_locked'] ) ? 0 : 1,
			'requires_wp'          => (string) ( $data['requires_wp'] ?? '' ),
			'requires_php'         => (string) ( $data['requires_php'] ?? '' ),
			'parent_theme'         => (string) ( $data['parent_theme'] ?? '' ),
			'created_by'           => (int) ( $data['created_by'] ?? 0 ),
			'created_at'           => (string) ( $data['created_at'] ?? current_time( 'mysql', true ) ),
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( false === $wpdb->insert( self::table(), $row, $formats ) ) {
			return new WP_Error( 'safespot_db_insert', __( 'Could not save the SafeSpot record to the database.', 'tbsh-safespot-restore-points' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetches a SafeSpot row.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Newest SafeSpot of an item.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return array|null
	 */
	public static function latest( $type, $slug ) {
		$rows = self::query(
			array(
				'type'  => $type,
				'slug'  => $slug,
				'limit' => 1,
			)
		);
		return $rows ? $rows[0] : null;
	}

	/**
	 * Fetches several SafeSpot rows.
	 *
	 * @param int[] $ids IDs.
	 * @return array[] Keyed by ID; missing IDs are left out.
	 */
	public static function get_many( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the ID count.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$row               = self::cast( $row );
			$out[ $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Updates columns.
	 *
	 * @param int   $id   ID.
	 * @param array $data Columns (note, is_locked).
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$allowed = array(
			'note'      => '%s',
			'is_locked' => '%d',
		);
		$row     = array_intersect_key( $data, $allowed );
		if ( empty( $row ) ) {
			return false;
		}
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = $allowed[ $key ];
		}
		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ), $formats, array( '%d' ) );
	}

	/**
	 * Deletes a row (files are removed separately).
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Lists SafeSpots.
	 *
	 * @param array $args { type, slug, source, locked, limit, offset, order }.
	 * @return array[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'type'   => '',
				'slug'   => '',
				'source' => '',
				'limit'  => 0,
				'offset' => 0,
				'order'  => 'DESC',
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $args['type'], array( 'plugin', 'theme' ), true ) ) {
			$where[]  = 'item_type = %s';
			$params[] = $args['type'];
		}
		if ( '' !== $args['slug'] ) {
			$where[]  = 'slug = %s';
			$params[] = $args['slug'];
		}
		if ( in_array( $args['source'], self::SOURCES, true ) ) {
			$where[]  = 'source = %s';
			$params[] = $args['source'];
		}

		$order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at {$order}, id {$order}";

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = (int) $args['offset'];
		}

		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from fixed fragments above.

		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/**
	 * Per-item summary (count, size, latest date) for a type.
	 *
	 * @param string $type Item type.
	 * @return array Keyed by slug.
	 */
	public static function summary( $type ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT slug, MAX(name) AS name, COUNT(*) AS total, SUM(file_size) AS size, MAX(created_at) AS latest FROM {$table} WHERE item_type = %s GROUP BY slug",
				$type
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['slug'] ] = array(
				'name'   => $row['name'],
				'count'  => (int) $row['total'],
				'size'   => (int) $row['size'],
				'latest' => $row['latest'],
			);
		}
		return $out;
	}

	/**
	 * Global statistics.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(file_size),0) AS size, MAX(created_at) AS latest FROM {$table}", ARRAY_A );
		$last  = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 1", ARRAY_A );

		return array(
			'total'  => (int) ( $row['total'] ?? 0 ),
			'size'   => (int) ( $row['size'] ?? 0 ),
			'latest' => $last ? self::cast( $last ) : null,
		);
	}

	/**
	 * SafeSpots that retention rules allow deleting for one item.
	 *
	 * @param string $type        Item type.
	 * @param string $slug        Item slug.
	 * @param int    $keep        Number of newest SafeSpots to keep (0 = unlimited).
	 * @param int    $max_age     Maximum age in days (0 = disabled).
	 * @param int[]  $protect_ids IDs that must never be removed.
	 * @return array[]
	 */
	public static function prunable( $type, $slug, $keep, $max_age, array $protect_ids = array() ) {
		$points = self::query(
			array(
				'type' => $type,
				'slug' => $slug,
			)
		);

		$cutoff   = $max_age > 0 ? time() - ( $max_age * DAY_IN_SECONDS ) : 0;
		$unlocked = 0;
		$remove   = array();

		foreach ( $points as $point ) {
			if ( $point['is_locked'] || in_array( $point['id'], $protect_ids, true ) ) {
				continue;
			}
			++$unlocked;
			$too_many = $keep > 0 && $unlocked > $keep;
			$too_old  = $cutoff > 0 && $point['created_ts'] < $cutoff;
			if ( $too_many || $too_old ) {
				$remove[] = $point;
			}
		}
		return $remove;
	}

	/**
	 * Distinct items that have SafeSpots.
	 *
	 * @return array[] Each with item_type and slug.
	 */
	public static function items() {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( "SELECT DISTINCT item_type, slug FROM {$table}", ARRAY_A );
	}

	/**
	 * Normalizes column types and adds derived fields.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function cast( array $row ) {
		foreach ( array( 'id', 'file_size', 'uncompressed_size', 'file_count', 'settings_count', 'created_by' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}
		$row['is_locked']  = ! empty( $row['is_locked'] );
		$row['created_ts'] = (int) strtotime( $row['created_at'] . ' UTC' );
		$row['note']       = (string) $row['note'];
		return $row;
	}

	/**
	 * Public representation used by REST, JS and CLI.
	 *
	 * @param array $point Casted row.
	 * @return array
	 */
	public static function format( array $point ) {
		$user = $point['created_by'] ? get_userdata( $point['created_by'] ) : false;

		return array(
			'id'                => $point['id'],
			'type'              => $point['item_type'],
			'slug'              => $point['slug'],
			'name'              => $point['name'],
			'version'           => $point['version'],
			'size'              => $point['file_size'],
			'size_human'        => size_format( $point['file_size'], 1 ),
			'uncompressed_size' => $point['uncompressed_size'],
			'file_count'        => $point['file_count'],
			'checksum'          => $point['checksum'],
			'has_settings'      => '' !== $point['settings_file'],
			'settings_count'    => $point['settings_count'],
			'note'              => $point['note'],
			'source'            => $point['source'],
			'source_label'      => self::source_label( $point['source'] ),
			'is_auto'           => in_array( $point['source'], array( 'auto_update', 'pre_restore', 'pre_delete' ), true ),
			'locked'            => $point['is_locked'],
			'requires_wp'       => $point['requires_wp'],
			'requires_php'      => $point['requires_php'],
			'parent_theme'      => $point['parent_theme'],
			'created_by'        => $point['created_by'],
			'created_by_name'   => $user ? $user->display_name : __( 'System', 'tbsh-safespot-restore-points' ),
			'created_at'        => gmdate( 'c', $point['created_ts'] ),
			'created_human'     => self::human_date( $point['created_ts'] ),
			'download_url'      => self::download_url( $point['id'] ),
		);
	}

	/**
	 * Authenticated download URL.
	 *
	 * @param int $id ID.
	 * @return string
	 */
	public static function download_url( $id ) {
		$base = is_multisite() ? network_admin_url( 'admin-post.php' ) : admin_url( 'admin-post.php' );
		return add_query_arg(
			array(
				'action'   => 'safespot_download',
				'id'       => (int) $id,
				'_wpnonce' => wp_create_nonce( 'safespot_download_' . (int) $id ),
			),
			$base
		);
	}

	/**
	 * Label for the source badge.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'manual'      => __( 'Manual', 'tbsh-safespot-restore-points' ),
			'cli'         => __( 'WP-CLI', 'tbsh-safespot-restore-points' ),
			'bulk'        => __( 'Bulk', 'tbsh-safespot-restore-points' ),
			'auto_update' => __( 'Auto – before update', 'tbsh-safespot-restore-points' ),
			'pre_restore' => __( 'Auto – before restore', 'tbsh-safespot-restore-points' ),
			'pre_delete'  => __( 'Auto – before delete', 'tbsh-safespot-restore-points' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * Localized date/time string.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function human_date( $timestamp ) {
		if ( ! $timestamp ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
