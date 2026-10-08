<?php
/**
 * Silent upgrader skin.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/**
 * Collects upgrader messages and errors without printing anything.
 */
class SafeSpot_Upgrader_Skin extends WP_Ajax_Upgrader_Skin {

	/**
	 * Converts an upgrader run into true or a WP_Error with the collected messages.
	 *
	 * @param WP_Upgrader $upgrader Upgrader after run().
	 * @return true|WP_Error
	 */
	public static function result( WP_Upgrader $upgrader ) {
		$result = $upgrader->result;
		$skin   = $upgrader->skin;

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $skin instanceof WP_Ajax_Upgrader_Skin ) {
			$errors = $skin->get_errors();
			if ( $errors->has_errors() ) {
				return $errors;
			}
		}

		if ( empty( $result ) ) {
			$messages = $skin instanceof Automatic_Upgrader_Skin ? array_filter( (array) $skin->get_upgrade_messages() ) : array();
			$detail   = $messages ? ' ' . implode( ' ', array_slice( $messages, -3 ) ) : '';
			return new WP_Error(
				'safespot_upgrader_failed',
				__( 'WordPress could not install the SafeSpot files. Check file permissions or your filesystem credentials (FS_METHOD).', 'tbsh-safespot-restore-points' ) . $detail
			);
		}

		return true;
	}
}
