<?php
/**
 * Background queue for bulk SafeSpots.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Processes "Save SafeSpots for selected/all items" in small time-boxed batches, using
 * Action Scheduler when available and WP-Cron otherwise. The admin screen also drives
 * the queue while it is open, so progress does not depend on site traffic.
 */
class SafeSpot_Queue {

	const OPTION = 'safespot_queue';
	const HOOK   = 'safespot_process_queue';

	/**
	 * Backup service.
	 *
	 * @var SafeSpot_Backup
	 */
	private $backup;

	/**
	 * Constructor.
	 *
	 * @param SafeSpot_Backup $backup Backup service.
	 */
	public function __construct( SafeSpot_Backup $backup ) {
		$this->backup = $backup;
	}

	/**
	 * Registers the background hook.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run_background' ) );
	}

	/**
	 * Current queue state.
	 *
	 * @return array
	 */
	public function get() {
		$state = get_site_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'pending' => array(),
				'total'   => 0,
				'done'    => 0,
				'failed'  => array(),
				'note'    => '',
				'started' => 0,
				'user'    => 0,
			)
		);
	}

	/**
	 * Saves queue state.
	 *
	 * @param array $state State.
	 */
	private function put( array $state ) {
		update_site_option( self::OPTION, $state );
	}

	/**
	 * Adds items to the queue.
	 *
	 * @param array  $items List of { type, slug }.
	 * @param string $note  Note for each SafeSpot.
	 * @return array Status.
	 */
	public function enqueue( array $items, $note = '' ) {
		$state = $this->get();
		if ( empty( $state['pending'] ) ) {
			$state = array(
				'pending' => array(),
				'total'   => 0,
				'done'    => 0,
				'failed'  => array(),
				'note'    => sanitize_textarea_field( $note ),
				'started' => time(),
				'user'    => get_current_user_id(),
			);
		}

		$existing = array();
		foreach ( $state['pending'] as $pending ) {
			$existing[ $pending['type'] . ':' . $pending['slug'] ] = true;
		}

		foreach ( $items as $item ) {
			$type = isset( $item['type'] ) && 'theme' === $item['type'] ? 'theme' : 'plugin';
			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			$key  = $type . ':' . $slug;
			if ( '' === $slug || isset( $existing[ $key ] ) || ! SafeSpot_Permissions::can( 'save', $type ) || ! SafeSpot_Items::get( $type, $slug ) ) {
				continue;
			}
			$state['pending'][] = array(
				'type' => $type,
				'slug' => $slug,
			);
			$existing[ $key ]   = true;
			++$state['total'];
		}

		$this->put( $state );
		$this->schedule();

		return $this->status();
	}

	/**
	 * Schedules background processing.
	 */
	private function schedule() {
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), 'safespot' ) ) {
				as_enqueue_async_action( self::HOOK, array(), 'safespot' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Background callback.
	 */
	public function run_background() {
		$this->process( 20 );
		if ( ! empty( $this->get()['pending'] ) ) {
			$this->schedule();
		}
	}

	/**
	 * Processes items until the time budget is used.
	 *
	 * @param int $budget Seconds.
	 * @return array Status.
	 */
	public function process( $budget = 15 ) {
		$start = microtime( true );

		do {
			$state = $this->get();
			if ( empty( $state['pending'] ) ) {
				break;
			}

			$next = $state['pending'][0];
			$this->current_user_fallback( $state );

			$result = $this->backup->save(
				$next['type'],
				$next['slug'],
				array(
					'note'      => $state['note'],
					'source'    => 'bulk',
					'lock_wait' => 5,
				)
			);

			if ( is_wp_error( $result ) && 'safespot_locked' === $result->get_error_code() ) {
				break;
			}

			$state = $this->get();
			array_shift( $state['pending'] );
			++$state['done'];
			if ( is_wp_error( $result ) ) {
				$state['failed'][] = array(
					'type'    => $next['type'],
					'slug'    => $next['slug'],
					'message' => $result->get_error_message(),
				);
			}
			$this->put( $state );
		} while ( ( microtime( true ) - $start ) < $budget );

		return $this->status();
	}

	/**
	 * Uses the queue owner's identity when processing from cron.
	 *
	 * @param array $state State.
	 */
	private function current_user_fallback( array $state ) {
		if ( ! get_current_user_id() && $state['user'] ) {
			wp_set_current_user( (int) $state['user'] );
		}
	}

	/**
	 * Public status.
	 *
	 * @return array
	 */
	public function status() {
		$state = $this->get();
		return array(
			'total'    => (int) $state['total'],
			'done'     => (int) $state['done'],
			'pending'  => count( $state['pending'] ),
			'failed'   => $state['failed'],
			'running'  => ! empty( $state['pending'] ),
			'progress' => $state['total'] > 0 ? (int) floor( $state['done'] / $state['total'] * 100 ) : 100,
		);
	}

	/**
	 * Clears the queue.
	 */
	public function cancel() {
		delete_site_option( self::OPTION );
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), 'safespot' );
		}
	}
}
