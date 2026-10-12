<?php
/**
 * Running jobs: what is running and how to stop it.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the REST API and WP-CLI.
 */
class SafeSpot_Jobs {

	/**
	 * Background queue.
	 *
	 * @var SafeSpot_Queue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param SafeSpot_Queue $queue Queue.
	 */
	public function __construct( SafeSpot_Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * What is running right now.
	 *
	 * @return array { running: bool, lock: array|null (see SafeSpot_Lock::current()), queue: array }.
	 */
	public function status() {
		$lock  = SafeSpot_Lock::current();
		$queue = $this->queue->status();
		return array(
			'running' => null !== $lock || $queue['running'],
			'lock'    => $lock,
			'queue'   => $queue,
		);
	}

	/**
	 * Stops the running job.
	 *
	 * Backups (and restores that have not started replacing files yet) are asked to
	 * stop and clean up; this waits up to $wait seconds for that. A restore that is
	 * replacing files cannot be stopped safely. With $force, a lock whose process stopped
	 * responding is removed.
	 *
	 * @param bool $force      Remove the lock of a process that stopped responding.
	 * @param int  $wait       Seconds to wait for the job to stop.
	 * @param bool $check_caps Check the current user's capabilities (off for WP-CLI).
	 * @return array|WP_Error { status: cancelled|released|requested, message, lock }.
	 */
	public function cancel( $force = false, $wait = 10, $check_caps = true ) {
		$lock  = SafeSpot_Lock::current();
		$queue = $this->queue->status();

		if ( ! $lock && ! $queue['running'] ) {
			return new WP_Error( 'safespot_nothing_running', __( 'Nothing is running.', 'tbsh-safespot-restore-points' ), array( 'status' => 404 ) );
		}

		if ( $lock && $check_caps && ! $this->can_cancel( $lock ) ) {
			return new WP_Error( 'safespot_cannot_cancel', __( 'You are not allowed to cancel this operation.', 'tbsh-safespot-restore-points' ), array( 'status' => 403 ) );
		}

		// Stop the bulk queue first, so it does not simply move on to the next item.
		if ( $queue['running'] && ( ! $lock || 'queue' === $lock['root'] ) ) {
			$this->queue->cancel();
			if ( ! $lock ) {
				$this->log( __( 'Bulk SafeSpot saving was cancelled.', 'tbsh-safespot-restore-points' ) );
				return array(
					'status'  => 'cancelled',
					'message' => __( 'Bulk SafeSpot saving was cancelled.', 'tbsh-safespot-restore-points' ),
					'lock'    => null,
				);
			}
		}

		if ( $force && ! $lock['responsive'] ) {
			SafeSpot_Lock::release_token( $lock['token'] );
			/* translators: %s: description of the operation. */
			$message = sprintf( __( 'Released the lock of an operation that stopped responding: %s.', 'tbsh-safespot-restore-points' ), $lock['summary'] );
			$this->log( $message, 'warning' );
			return array(
				'status'  => 'released',
				'message' => $message,
				'lock'    => null,
			);
		}

		if ( ! $lock['cancellable'] ) {
			return new WP_Error(
				'safespot_not_cancellable',
				/* translators: %s: description of the operation. */
				sprintf( __( 'This cannot be stopped safely right now: %s. Files are being replaced, and stopping half-way would leave a broken plugin or theme. It normally finishes within a minute.', 'tbsh-safespot-restore-points' ), $lock['summary'] ),
				array(
					'status' => 409,
					'lock'   => $lock,
				)
			);
		}

		if ( ! SafeSpot_Lock::request_cancel( $lock['token'] ) ) {
			return array(
				'status'  => 'cancelled',
				'message' => __( 'The operation had already finished.', 'tbsh-safespot-restore-points' ),
				'lock'    => null,
			);
		}
		/* translators: %s: description of the operation. */
		$this->log( sprintf( __( 'Cancel requested: %s.', 'tbsh-safespot-restore-points' ), $lock['summary'] ) );

		$deadline = microtime( true ) + max( 0, (int) $wait );
		while ( microtime( true ) < $deadline ) {
			if ( ! SafeSpot_Lock::is_running( $lock['token'] ) ) {
				return array(
					'status'  => 'cancelled',
					/* translators: %s: what was running, e.g. "Saving a SafeSpot of Akismet". */
					'message' => sprintf( __( 'Cancelled: %s. Nothing was saved or changed.', 'tbsh-safespot-restore-points' ), $lock['label'] ),
					'lock'    => null,
				);
			}
			usleep( 250000 );
		}

		$message = __( 'The operation was asked to stop and will stop at its next checkpoint, usually within a few seconds.', 'tbsh-safespot-restore-points' );
		if ( ! $lock['responsive'] ) {
			$message .= ' ' . __( 'It has not responded for a while. If it does not stop, its process has probably ended: use Force release.', 'tbsh-safespot-restore-points' );
		}

		return array(
			'status'  => 'requested',
			'message' => $message,
			'lock'    => SafeSpot_Lock::current(),
		);
	}

	/**
	 * Whether the current user may cancel an operation.
	 *
	 * @param array $lock Lock info.
	 * @return bool
	 */
	private function can_cancel( array $lock ) {
		$parts = explode( ':', $lock['context'], 3 );
		$type  = isset( $parts[1] ) && in_array( $parts[1], array( 'plugin', 'theme' ), true ) ? $parts[1] : '';
		$cap   = 'restore' === $parts[0] ? 'restore' : 'save';
		return SafeSpot_Permissions::can( $cap, $type );
	}

	/**
	 * Logs a cancel action.
	 *
	 * @param string $message Message.
	 * @param string $status  Log status.
	 */
	private function log( $message, $status = 'info' ) {
		SafeSpot_Logger::log( 'cancel', $status, $message );
	}
}
