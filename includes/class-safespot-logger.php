<?php
/**
 * Activity log.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name comes from $wpdb->base_prefix; all values go through $wpdb->prepare().

/**
 * Writes and reads the {base_prefix}safespot_log table.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
 * -- Custom table; the table name comes from $wpdb->base_prefix and every value is prepared.
 */
class SafeSpot_Logger {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->base_prefix . 'safespot_log';
	}

	/**
	 * Human labels for log actions.
	 *
	 * @return array
	 */
	public static function actions() {
		return array(
			'save'     => __( 'Saved', 'tbsh-safespot-restore-points' ),
			'auto'     => __( 'Auto-SafeSpot', 'tbsh-safespot-restore-points' ),
			'restore'  => __( 'Restored', 'tbsh-safespot-restore-points' ),
			'rollback' => __( 'Rolled back', 'tbsh-safespot-restore-points' ),
			'delete'   => __( 'Deleted', 'tbsh-safespot-restore-points' ),
			'cleanup'  => __( 'Cleanup', 'tbsh-safespot-restore-points' ),
			'edit'     => __( 'Edited', 'tbsh-safespot-restore-points' ),
			'hold'     => __( 'Updates paused/resumed', 'tbsh-safespot-restore-points' ),
			'settings' => __( 'Settings', 'tbsh-safespot-restore-points' ),
			'download' => __( 'Downloaded', 'tbsh-safespot-restore-points' ),
			'cancel'   => __( 'Cancelled', 'tbsh-safespot-restore-points' ),
		);
	}

	/**
	 * Human labels for statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'success' => __( 'Success', 'tbsh-safespot-restore-points' ),
			'error'   => __( 'Failed', 'tbsh-safespot-restore-points' ),
			'warning' => __( 'Warning', 'tbsh-safespot-restore-points' ),
			'info'    => __( 'Info', 'tbsh-safespot-restore-points' ),
		);
	}

	/**
	 * Adds a log entry.
	 *
	 * @param string $action  Action key.
	 * @param string $status  success|error|warning|info.
	 * @param string $message Human-readable message.
	 * @param array  $args    { type, slug, name, point_id, context, user_id }.
	 * @return int Entry ID (0 on failure).
	 */
	public static function log( $action, $status, $message, array $args = array() ) {
		global $wpdb;

		$context = isset( $args['context'] ) ? wp_json_encode( $args['context'] ) : '';

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id(),
				'action'     => substr( sanitize_key( $action ), 0, 30 ),
				'status'     => in_array( $status, array( 'success', 'error', 'warning', 'info' ), true ) ? $status : 'info',
				'item_type'  => isset( $args['type'] ) ? substr( (string) $args['type'], 0, 10 ) : '',
				'slug'       => isset( $args['slug'] ) ? substr( (string) $args['slug'], 0, 191 ) : '',
				'item_name'  => isset( $args['name'] ) ? substr( wp_strip_all_tags( (string) $args['name'] ), 0, 255 ) : '',
				'point_id'   => isset( $args['point_id'] ) ? (int) $args['point_id'] : 0,
				'message'    => wp_strip_all_tags( (string) $message ),
				'context'    => (string) $context,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		/**
		 * Fires after SafeSpot writes an activity log entry.
		 *
		 * @param string $action  Action key.
		 * @param string $status  Status.
		 * @param string $message Message.
		 * @param array  $args    Extra arguments.
		 */
		do_action( 'safespot_logged', $action, $status, $message, $args );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Queries the log.
	 *
	 * @param array $args { action, status, type, search, page, per_page }.
	 * @return array { items, total, pages }.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'action'   => '',
				'status'   => '',
				'type'     => '',
				'search'   => '',
				'page'     => 1,
				'per_page' => 25,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['action'] && isset( self::actions()[ $args['action'] ] ) ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}
		if ( '' !== $args['status'] && isset( self::statuses()[ $args['status'] ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( in_array( $args['type'], array( 'plugin', 'theme' ), true ) ) {
			$where[]  = 'item_type = %s';
			$params[] = $args['type'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(item_name LIKE %s OR slug LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 200, (int) $args['per_page'] ) );
		$page      = max( 1, (int) $args['page'] );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed fragments.

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed fragments.

		return array(
			'items' => array_map( array( __CLASS__, 'format' ), (array) $items ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Most recent entries.
	 *
	 * @param int $limit Number of entries.
	 * @return array[]
	 */
	public static function recent( $limit = 10 ) {
		$result = self::query( array( 'per_page' => $limit ) );
		return $result['items'];
	}

	/**
	 * Latest successful entry for an action.
	 *
	 * @param string[] $actions Action keys.
	 * @return array|null
	 */
	public static function latest( array $actions ) {
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
		$row          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'success' AND action IN ({$placeholders}) ORDER BY id DESC LIMIT 1", $actions ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is generated.
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Formats a row for output.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function format( array $row ) {
		$user    = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : false;
		$ts      = (int) strtotime( $row['created_at'] . ' UTC' );
		$actions = self::actions();

		return array(
			'id'            => (int) $row['id'],
			'created_at'    => gmdate( 'c', $ts ),
			'created_ts'    => $ts,
			'created_human' => SafeSpot_Points::human_date( $ts ),
			'user'          => $user ? $user->display_name : __( 'System', 'tbsh-safespot-restore-points' ),
			'action'        => $row['action'],
			'action_label'  => isset( $actions[ $row['action'] ] ) ? $actions[ $row['action'] ] : $row['action'],
			'status'        => $row['status'],
			'type'          => $row['item_type'],
			'slug'          => $row['slug'],
			'name'          => $row['item_name'],
			'point_id'      => (int) $row['point_id'],
			'message'       => (string) $row['message'],
		);
	}

	/**
	 * Deletes every entry.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Removes entries older than N days.
	 *
	 * @param int $days Age in days (0 disables pruning).
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return;
		}
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
