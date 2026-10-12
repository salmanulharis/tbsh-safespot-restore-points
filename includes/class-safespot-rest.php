<?php
/**
 * REST API.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/safespot/v1. Every route has a permission_callback; cookie
 * authentication requires the standard wp_rest nonce (X-WP-Nonce), which
 * wp.apiFetch sends automatically.
 */
class SafeSpot_REST {

	const NS = 'safespot/v1';

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
	 * Queue.
	 *
	 * @var SafeSpot_Queue
	 */
	private $queue;

	/**
	 * Running jobs.
	 *
	 * @var SafeSpot_Jobs
	 */
	private $jobs;

	/**
	 * Constructor.
	 *
	 * @param SafeSpot_Backup  $backup  Backup service.
	 * @param SafeSpot_Restore $restore Restore service.
	 * @param SafeSpot_Queue   $queue   Queue.
	 * @param SafeSpot_Jobs    $jobs    Running jobs.
	 */
	public function __construct( SafeSpot_Backup $backup, SafeSpot_Restore $restore, SafeSpot_Queue $queue, SafeSpot_Jobs $jobs ) {
		$this->backup  = $backup;
		$this->restore = $restore;
		$this->queue   = $queue;
		$this->jobs    = $jobs;
	}

	/**
	 * Hooks route registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Common argument schemas.
	 *
	 * @return array
	 */
	private function item_args() {
		return array(
			'type' => array(
				'type'     => 'string',
				'enum'     => array( 'plugin', 'theme' ),
				'required' => true,
			),
			'slug' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_slug' ),
			),
		);
	}

	/**
	 * Registers routes.
	 */
	public function routes() {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		register_rest_route(
			self::NS,
			'/item',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item_row' ),
				'permission_callback' => $this->type_permission( 'view' ),
				'args'                => $this->item_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => $this->type_permission( 'view' ),
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'enum'     => array( 'plugin', 'theme' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/points',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_points' ),
					'permission_callback' => $this->type_permission( 'view', false ),
					'args'                => array(
						'type' => array(
							'type' => 'string',
							'enum' => array( 'plugin', 'theme', '' ),
						),
						'slug' => array(
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_slug' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_point' ),
					'permission_callback' => $this->type_permission( 'save' ),
					'args'                => array_merge(
						$this->item_args(),
						array(
							'note'             => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'include_settings' => array(
								'type' => 'boolean',
							),
							'prefixes'         => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/points/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_point' ),
					'permission_callback' => $this->point_permission( 'view' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_point' ),
					'permission_callback' => $this->point_permission( 'save' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'note'   => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'locked' => array(
								'type' => 'boolean',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_point' ),
					'permission_callback' => $this->point_permission( 'delete' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/points/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_points' ),
				'permission_callback' => array( $this, 'can_view_any' ),
				'args'                => array(
					'ids'            => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 500,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'action'         => array(
						'type'     => 'string',
						'enum'     => array( 'lock', 'unlock', 'delete' ),
						'required' => true,
					),
					'include_locked' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/points/(?P<id>\d+)/preflight',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preflight' ),
				'permission_callback' => $this->point_permission( 'restore' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::NS,
			'/points/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_point' ),
				'permission_callback' => $this->point_permission( 'restore' ),
				'args'                => array_merge(
					$id_arg,
					array(
						'mode'          => array(
							'type'    => 'string',
							'enum'    => array( 'files', 'files_settings' ),
							'default' => 'files',
						),
						'pause_updates' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => array( $this, 'can_rollback' ),
			)
		);

		register_rest_route(
			self::NS,
			'/exclusions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_exclusion' ),
				'permission_callback' => $this->type_permission( 'save' ),
				'args'                => array_merge(
					$this->item_args(),
					array(
						'excluded' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/holds',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_hold' ),
				'permission_callback' => $this->type_permission( 'restore' ),
				'args'                => array_merge(
					$this->item_args(),
					array(
						'hold' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_log' ),
					'permission_callback' => array( $this, 'can_view_any' ),
					'args'                => array(
						'action'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'status'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'type'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 25,
							'minimum' => 1,
							'maximum' => 200,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_log' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_view_any' ),
			)
		);

		register_rest_route(
			self::NS,
			'/queue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'queue_status' ),
					'permission_callback' => array( $this, 'can_save_any' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'queue_add' ),
					'permission_callback' => array( $this, 'can_save_any' ),
					'args'                => array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'type' => array(
										'type' => 'string',
										'enum' => array( 'plugin', 'theme' ),
									),
									'slug' => array( 'type' => 'string' ),
								),
							),
						),
						'note'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'queue_cancel' ),
					'permission_callback' => array( $this, 'can_save_any' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'jobs_status' ),
				'permission_callback' => array( $this, 'can_view_any' ),
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'jobs_cancel' ),
				'permission_callback' => array( $this, 'can_save_any' ),
				'args'                => array(
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/queue/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'queue_run' ),
				'permission_callback' => array( $this, 'can_save_any' ),
			)
		);
	}

	/**
	 * Sanitizes a plugin file / theme stylesheet.
	 *
	 * @param string $slug Raw value.
	 * @return string
	 */
	public function sanitize_slug( $slug ) {
		$slug = wp_normalize_path( trim( (string) $slug ) );
		$slug = preg_replace( '#[^A-Za-z0-9._\-/]#', '', $slug );
		return 0 === validate_file( $slug ) ? $slug : '';
	}

	/**
	 * Permission callback based on the request's "type" parameter.
	 *
	 * @param string $action   Action.
	 * @param bool   $required Whether type is required (false = any type when missing).
	 * @return callable
	 */
	private function type_permission( $action, $required = true ) {
		return static function ( WP_REST_Request $request ) use ( $action, $required ) {
			$type = (string) $request->get_param( 'type' );
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
				return $required ? false : SafeSpot_Permissions::can( $action );
			}
			return SafeSpot_Permissions::can( $action, $type );
		};
	}

	/**
	 * Permission callback based on the SafeSpot's type.
	 *
	 * @param string $action Action.
	 * @return callable
	 */
	private function point_permission( $action ) {
		return static function ( WP_REST_Request $request ) use ( $action ) {
			$point = SafeSpot_Points::get( (int) $request['id'] );
			if ( ! $point ) {
				return SafeSpot_Permissions::can( 'view' );
			}
			return SafeSpot_Permissions::can( $action, $point['item_type'] );
		};
	}

	/**
	 * Any-type view permission.
	 *
	 * @return bool
	 */
	public function can_view_any() {
		return SafeSpot_Permissions::can( 'view' );
	}

	/**
	 * Any-type save permission.
	 *
	 * @return bool
	 */
	public function can_save_any() {
		return SafeSpot_Permissions::can( 'save' );
	}

	/**
	 * Settings permission.
	 *
	 * @return bool
	 */
	public function can_manage_settings() {
		return SafeSpot_Permissions::can( 'settings' );
	}

	/**
	 * Rollback permission (type of the last restore).
	 *
	 * @return bool
	 */
	public function can_rollback() {
		$last = SafeSpot_Restore::last_restore();
		return $last ? SafeSpot_Permissions::can( 'restore', $last['type'] ) : SafeSpot_Permissions::can( 'restore' );
	}

	/**
	 * Adds refreshed row HTML for an item to a response payload.
	 *
	 * @param array  $data     Payload.
	 * @param string $type     Type.
	 * @param string $slug     Slug.
	 * @param bool   $expanded Keep history open.
	 * @return array
	 */
	private function with_row( array $data, $type, $slug, $expanded = true ) {
		$data['html']    = SafeSpot_Admin_Views::item_html( $type, $slug, $expanded );
		$data['item_id'] = SafeSpot_Admin_Views::item_id( $type, $slug );
		return $data;
	}

	/**
	 * GET /item – rendered row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item_row( WP_REST_Request $request ) {
		return rest_ensure_response( $this->with_row( array(), $request['type'], $request['slug'], false ) );
	}

	/**
	 * GET /items – installed items with SafeSpot counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ) {
		$out = array();
		foreach ( SafeSpot_Admin_Views::collect_items( $request['type'] ) as $row ) {
			$item  = $row['item'];
			$out[] = array(
				'type'          => $item['type'],
				'slug'          => $item['slug'],
				'name'          => $item['name'],
				'version'       => $item['version'],
				'status'        => $item['status'],
				'update'        => $item['update'],
				'count'         => $row['summary'] ? $row['summary']['count'] : 0,
				'size'          => $row['summary'] ? $row['summary']['size'] : 0,
				'latest'        => $row['summary'] ? $row['summary']['latest'] : null,
				'auto_excluded' => SafeSpot_Settings::is_excluded( $item['type'], $item['slug'] ),
				'auto_active'   => SafeSpot_Settings::auto_backup_enabled( $item['type'] ) && ! SafeSpot_Settings::is_excluded( $item['type'], $item['slug'] ),
				'updates_held'  => (bool) SafeSpot_Settings::get_hold( $item['type'], $item['slug'] ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /points.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_points( WP_REST_Request $request ) {
		$points = SafeSpot_Points::query(
			array(
				'type' => (string) $request['type'],
				'slug' => (string) $request['slug'],
			)
		);
		$out    = array();
		foreach ( $points as $point ) {
			if ( SafeSpot_Permissions::can( 'view', $point['item_type'] ) ) {
				$out[] = SafeSpot_Points::format( $point );
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /points – save a SafeSpot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_point( WP_REST_Request $request ) {
		$type = $request['type'];
		$slug = $request['slug'];

		if ( null !== $request['prefixes'] && '' !== $request['prefixes'] ) {
			SafeSpot_Settings::set_prefixes( $type, $slug, $request['prefixes'] );
		}

		$point = $this->backup->save(
			$type,
			$slug,
			array(
				'note'             => $request['note'],
				'source'           => 'manual',
				'include_settings' => null === $request['include_settings'] ? null : (bool) $request['include_settings'],
			)
		);

		if ( is_wp_error( $point ) ) {
			return $point;
		}

		if ( $point['reused'] ) {
			$message = sprintf(
				/* translators: 1: item name, 2: version, 3: date. */
				__( '%1$s %2$s has not changed since its SafeSpot from %3$s, so that SafeSpot was kept instead of saving a duplicate.', 'tbsh-safespot-restore-points' ),
				$point['name'],
				$point['version'],
				$point['created_human']
			);
		} else {
			$message = sprintf(
				/* translators: 1: item name, 2: version, 3: size. */
				__( 'SafeSpot saved: %1$s %2$s (%3$s).', 'tbsh-safespot-restore-points' ),
				$point['name'],
				$point['version'],
				$point['size_human']
			);
		}

		return rest_ensure_response(
			$this->with_row(
				array(
					'point'   => $point,
					'reused'  => $point['reused'],
					'message' => $message,
				),
				$type,
				$slug
			)
		);
	}

	/**
	 * GET /points/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_point( WP_REST_Request $request ) {
		$point = SafeSpot_Points::get( (int) $request['id'] );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( SafeSpot_Points::format( $point ) );
	}

	/**
	 * PATCH /points/{id} – note / lock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_point( WP_REST_Request $request ) {
		$point = SafeSpot_Points::get( (int) $request['id'] );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$data    = array();
		$message = __( 'SafeSpot updated.', 'tbsh-safespot-restore-points' );
		if ( null !== $request['note'] ) {
			$data['note'] = substr( (string) $request['note'], 0, 1000 );
			$message      = __( 'Note saved.', 'tbsh-safespot-restore-points' );
		}
		if ( null !== $request['locked'] ) {
			$data['is_locked'] = $request['locked'] ? 1 : 0;
			$message           = $request['locked'] ? __( 'SafeSpot locked. It will never be deleted automatically.', 'tbsh-safespot-restore-points' ) : __( 'SafeSpot unlocked.', 'tbsh-safespot-restore-points' );
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'safespot_nothing_to_update', __( 'Nothing to update.', 'tbsh-safespot-restore-points' ), array( 'status' => 400 ) );
		}

		SafeSpot_Points::update( $point['id'], $data );
		SafeSpot_Logger::log(
			'edit',
			'info',
			/* translators: 1: item name, 2: version, 3: change. */
			sprintf( __( 'Edited SafeSpot of %1$s %2$s: %3$s', 'tbsh-safespot-restore-points' ), $point['name'], $point['version'], $message ),
			array(
				'type'     => $point['item_type'],
				'slug'     => $point['slug'],
				'name'     => $point['name'],
				'point_id' => $point['id'],
			)
		);

		return rest_ensure_response(
			$this->with_row(
				array(
					'point'   => SafeSpot_Points::format( SafeSpot_Points::get( $point['id'] ) ),
					'message' => $message,
				),
				$point['item_type'],
				$point['slug']
			)
		);
	}

	/**
	 * DELETE /points/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_point( WP_REST_Request $request ) {
		$point = SafeSpot_Points::get( (int) $request['id'] );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$result = $this->backup->delete( $point['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			$this->with_row(
				array(
					'deleted' => true,
					/* translators: %s: version. */
					'message' => sprintf( __( 'SafeSpot %s deleted.', 'tbsh-safespot-restore-points' ), $point['version'] ),
				),
				$point['item_type'],
				$point['slug']
			)
		);
	}

	/**
	 * POST /points/bulk – lock, unlock or delete several SafeSpots.
	 *
	 * Permissions are checked per SafeSpot (save for lock/unlock, delete for delete).
	 * Locked SafeSpots are only deleted when include_locked is true.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function bulk_points( WP_REST_Request $request ) {
		$action = $request['action'];
		$ids    = array_values( array_unique( array_map( 'absint', (array) $request['ids'] ) ) );
		$points = SafeSpot_Points::get_many( $ids );
		$done   = array();
		$failed = array();
		$items  = array();
		$skip   = array(
			'missing'    => array_values( array_diff( $ids, array_keys( $points ) ) ),
			'locked'     => array(),
			'permission' => array(),
		);

		foreach ( $ids as $id ) {
			if ( ! isset( $points[ $id ] ) ) {
				continue;
			}
			$point = $points[ $id ];

			if ( ! SafeSpot_Permissions::can( 'delete' === $action ? 'delete' : 'save', $point['item_type'] ) ) {
				$skip['permission'][] = $id;
				continue;
			}

			$items[ $point['item_type'] . ':' . $point['slug'] ] = array( $point['item_type'], $point['slug'] );

			if ( 'delete' === $action ) {
				if ( $point['is_locked'] && ! $request['include_locked'] ) {
					$skip['locked'][] = $id;
					continue;
				}
				$result = $this->backup->delete( $id );
				if ( is_wp_error( $result ) ) {
					$failed[] = array(
						'id'      => $id,
						'message' => $result->get_error_message(),
					);
					continue;
				}
				$done[] = $id;
				continue;
			}

			$lock = 'lock' === $action;
			if ( $point['is_locked'] !== $lock ) {
				SafeSpot_Points::update( $id, array( 'is_locked' => $lock ? 1 : 0 ) );
				SafeSpot_Logger::log(
					'edit',
					'info',
					sprintf(
						/* translators: 1: item name, 2: version. */
						$lock ? __( 'Locked SafeSpot of %1$s %2$s (bulk action).', 'tbsh-safespot-restore-points' ) : __( 'Unlocked SafeSpot of %1$s %2$s (bulk action).', 'tbsh-safespot-restore-points' ),
						$point['name'],
						$point['version']
					),
					array(
						'type'     => $point['item_type'],
						'slug'     => $point['slug'],
						'name'     => $point['name'],
						'point_id' => $id,
					)
				);
			}
			$done[] = $id;
		}

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = $this->with_row( array(), $item[0], $item[1] );
		}

		return rest_ensure_response(
			array(
				'action'  => $action,
				'done'    => $done,
				'skipped' => $skip,
				'failed'  => $failed,
				'rows'    => $rows,
				'message' => $this->bulk_message( $action, count( $done ), $skip, count( $failed ) ),
			)
		);
	}

	/**
	 * Summary sentence for a bulk action.
	 *
	 * @param string $action  lock|unlock|delete.
	 * @param int    $done    Number processed.
	 * @param array  $skip    Skipped IDs by reason.
	 * @param int    $failed  Number failed.
	 * @return string
	 */
	private function bulk_message( $action, $done, array $skip, $failed ) {
		if ( 'delete' === $action ) {
			/* translators: %d: number of SafeSpots. */
			$parts = array( sprintf( _n( '%d SafeSpot deleted.', '%d SafeSpots deleted.', $done, 'tbsh-safespot-restore-points' ), $done ) );
		} elseif ( 'lock' === $action ) {
			/* translators: %d: number of SafeSpots. */
			$parts = array( sprintf( _n( '%d SafeSpot locked. Locked SafeSpots are never deleted automatically.', '%d SafeSpots locked. Locked SafeSpots are never deleted automatically.', $done, 'tbsh-safespot-restore-points' ), $done ) );
		} else {
			/* translators: %d: number of SafeSpots. */
			$parts = array( sprintf( _n( '%d SafeSpot unlocked.', '%d SafeSpots unlocked.', $done, 'tbsh-safespot-restore-points' ), $done ) );
		}

		if ( $skip['locked'] ) {
			$count = count( $skip['locked'] );
			/* translators: %d: number of SafeSpots. */
			$parts[] = sprintf( _n( '%d locked SafeSpot was kept.', '%d locked SafeSpots were kept.', $count, 'tbsh-safespot-restore-points' ), $count );
		}
		if ( $skip['permission'] ) {
			$count = count( $skip['permission'] );
			/* translators: %d: number of SafeSpots. */
			$parts[] = sprintf( _n( '%d SafeSpot was skipped because you are not allowed to change it.', '%d SafeSpots were skipped because you are not allowed to change them.', $count, 'tbsh-safespot-restore-points' ), $count );
		}
		if ( $skip['missing'] ) {
			$count = count( $skip['missing'] );
			/* translators: %d: number of SafeSpots. */
			$parts[] = sprintf( _n( '%d SafeSpot no longer exists.', '%d SafeSpots no longer exist.', $count, 'tbsh-safespot-restore-points' ), $count );
		}
		if ( $failed ) {
			/* translators: %d: number of SafeSpots. */
			$parts[] = sprintf( _n( '%d SafeSpot could not be deleted. See the Activity Log.', '%d SafeSpots could not be deleted. See the Activity Log.', $failed, 'tbsh-safespot-restore-points' ), $failed );
		}

		return implode( ' ', $parts );
	}

	/**
	 * GET /points/{id}/preflight.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preflight( WP_REST_Request $request ) {
		$result = $this->restore->preflight( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /points/{id}/restore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_point( WP_REST_Request $request ) {
		$point = SafeSpot_Points::get( (int) $request['id'] );
		if ( ! $point ) {
			return new WP_Error( 'safespot_not_found', __( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		$result = $this->restore->restore(
			$point['id'],
			array(
				'mode'          => $request['mode'],
				'pause_updates' => (bool) $request['pause_updates'],
			)
		);

		if ( is_wp_error( $result ) ) {
			$data            = (array) $result->get_error_data();
			$data['html']    = SafeSpot_Admin_Views::item_html( $point['item_type'], $point['slug'], true );
			$data['item_id'] = SafeSpot_Admin_Views::item_id( $point['item_type'], $point['slug'] );
			$result->add_data( $data );
			return $result;
		}

		return rest_ensure_response( $this->with_row( $result, $point['item_type'], $point['slug'] ) );
	}

	/**
	 * POST /rollback – undo the last restore.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rollback() {
		$last   = SafeSpot_Restore::last_restore();
		$result = $this->restore->rollback_last();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $last ? $this->with_row( $result, $last['type'], $last['slug'] ) : $result );
	}

	/**
	 * POST /exclusions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function set_exclusion( WP_REST_Request $request ) {
		SafeSpot_Settings::set_excluded( $request['type'], $request['slug'], (bool) $request['excluded'] );
		$item    = SafeSpot_Items::get( $request['type'], $request['slug'] );
		$name    = $item ? $item['name'] : $request['slug'];
		$enabled = SafeSpot_Settings::auto_backup_enabled( $request['type'] );

		if ( $request['excluded'] ) {
			/* translators: %s: item name. */
			$message = sprintf( __( 'Automatic SafeSpots turned off for %s.', 'tbsh-safespot-restore-points' ), $name );
		} elseif ( $enabled ) {
			/* translators: %s: item name. */
			$message = sprintf( __( 'Automatic SafeSpots turned on for %s.', 'tbsh-safespot-restore-points' ), $name );
		} else {
			/* translators: %s: item name. */
			$message = sprintf( __( '%s is included again, but automatic SafeSpots are turned off in Settings, so nothing is saved automatically until you turn them on there.', 'tbsh-safespot-restore-points' ), $name );
		}

		return rest_ensure_response(
			array(
				'excluded'     => (bool) $request['excluded'],
				'auto_enabled' => $enabled,
				'message'      => $message,
			)
		);
	}

	/**
	 * POST /holds – pause or resume updates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function set_hold( WP_REST_Request $request ) {
		$type = $request['type'];
		$slug = $request['slug'];
		$item = SafeSpot_Items::get( $type, $slug );
		SafeSpot_Settings::set_hold( $type, $slug, (bool) $request['hold'], $item ? $item['version'] : '' );

		$name    = $item ? $item['name'] : $slug;
		$message = $request['hold']
			/* translators: %s: item name. */
			? sprintf( __( 'Updates paused for %s.', 'tbsh-safespot-restore-points' ), $name )
			/* translators: %s: item name. */
			: sprintf( __( 'Updates resumed for %s.', 'tbsh-safespot-restore-points' ), $name );

		SafeSpot_Logger::log(
			'hold',
			'info',
			$message,
			array(
				'type' => $type,
				'slug' => $slug,
				'name' => $name,
			)
		);

		return rest_ensure_response( $this->with_row( array( 'message' => $message ), $type, $slug, false ) );
	}

	/**
	 * GET /log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_log( WP_REST_Request $request ) {
		return rest_ensure_response(
			SafeSpot_Logger::query(
				array(
					'action'   => (string) $request['action'],
					'status'   => (string) $request['status'],
					'type'     => (string) $request['type'],
					'search'   => (string) $request['search'],
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
				)
			)
		);
	}

	/**
	 * DELETE /log.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_log() {
		SafeSpot_Logger::clear();
		SafeSpot_Logger::log( 'settings', 'info', __( 'Activity log cleared.', 'tbsh-safespot-restore-points' ) );
		return rest_ensure_response( array( 'message' => __( 'Activity log cleared.', 'tbsh-safespot-restore-points' ) ) );
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( SafeSpot_Settings::all() );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		$saved = SafeSpot_Settings::update( (array) $params );
		SafeSpot_Logger::log( 'settings', 'info', __( 'Settings updated.', 'tbsh-safespot-restore-points' ) );

		return rest_ensure_response(
			array(
				'settings' => $saved,
				'message'  => __( 'Settings saved.', 'tbsh-safespot-restore-points' ),
			)
		);
	}

	/**
	 * GET /stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats() {
		$stats = SafeSpot_Points::stats();
		return rest_ensure_response(
			array(
				'total'        => $stats['total'],
				'size'         => $stats['size'],
				'size_human'   => size_format( $stats['size'], 1 ),
				'latest'       => $stats['latest'] ? SafeSpot_Points::format( $stats['latest'] ) : null,
				'last_restore' => SafeSpot_Logger::latest( array( 'restore', 'rollback' ) ),
				'free_space'   => SafeSpot_Storage::free_space(),
				'lock'         => SafeSpot_Lock::current(),
			)
		);
	}

	/**
	 * GET /queue.
	 *
	 * @return WP_REST_Response
	 */
	public function queue_status() {
		return rest_ensure_response( $this->queue->status() );
	}

	/**
	 * POST /queue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function queue_add( WP_REST_Request $request ) {
		$items = array();
		foreach ( (array) $request['items'] as $item ) {
			$items[] = array(
				'type' => isset( $item['type'] ) ? (string) $item['type'] : 'plugin',
				'slug' => isset( $item['slug'] ) ? $this->sanitize_slug( $item['slug'] ) : '',
			);
		}
		return rest_ensure_response( $this->queue->enqueue( $items, (string) $request['note'] ) );
	}

	/**
	 * POST /queue/run – process a time-boxed batch.
	 *
	 * @return WP_REST_Response
	 */
	public function queue_run() {
		return rest_ensure_response( $this->queue->process( 15 ) );
	}

	/**
	 * GET /jobs – what is running.
	 *
	 * @return WP_REST_Response
	 */
	public function jobs_status() {
		return rest_ensure_response( $this->jobs->status() );
	}

	/**
	 * POST /jobs/cancel – stop the running job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function jobs_cancel( WP_REST_Request $request ) {
		$result = $this->jobs->cancel( (bool) $request['force'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['jobs'] = $this->jobs->status();
		return rest_ensure_response( $result );
	}

	/**
	 * DELETE /queue.
	 *
	 * @return WP_REST_Response
	 */
	public function queue_cancel() {
		$this->queue->cancel();
		return rest_ensure_response( $this->queue->status() );
	}
}
