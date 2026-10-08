<?php
/**
 * Post-restore fatal error detection.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the site in a loopback request using core's "scrape" mechanism (the same one
 * used by the plugin/theme file editor) and reports PHP fatal errors.
 */
class SafeSpot_Health {

	/**
	 * Runs the check.
	 *
	 * @param string $context admin|front.
	 * @return array { status: ok|fatal|unknown, message, error }
	 */
	public static function check( $context = 'admin' ) {
		$scrape_key   = md5( (string) wp_rand() );
		$scrape_nonce = (string) wp_rand();
		$transient    = 'scrape_key_' . $scrape_key;
		set_transient( $transient, $scrape_nonce, 60 );

		$cookies = array();
		foreach ( wp_unslash( $_COOKIE ) as $name => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookies are forwarded verbatim to the loopback request, as core does.
			if ( is_string( $value ) ) {
				$cookies[ $name ] = $value;
			}
		}

		$headers = array( 'Cache-Control' => 'no-cache' );
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Credentials forwarded verbatim, as core does.
		}

		$base = 'admin' === $context && ! empty( $cookies ) ? admin_url() : home_url( '/' );
		$url  = add_query_arg(
			array(
				'wp_scrape_key'   => $scrape_key,
				'wp_scrape_nonce' => $scrape_nonce,
				'safespot_check'  => time(),
			),
			$base
		);

		/** This filter is documented in wp-includes/class-wp-http-streams.php */
		$sslverify = apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied as core's own loopback requests do.

		$response = wp_remote_get(
			$url,
			array(
				'cookies'     => $cookies,
				'headers'     => $headers,
				'timeout'     => 60,
				'sslverify'   => $sslverify,
				'redirection' => 0,
			)
		);

		delete_transient( $transient );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'unknown',
				/* translators: %s: error message. */
				'message' => sprintf( __( 'The site could not be checked after the restore (loopback request failed: %s).', 'tbsh-safespot-restore-points' ), $response->get_error_message() ),
				'error'   => null,
			);
		}

		$body  = wp_remote_retrieve_body( $response );
		$start = "###### wp_scraping_result_start:$scrape_key ######";
		$end   = "###### wp_scraping_result_end:$scrape_key ######";
		$pos   = strpos( $body, $start );

		if ( false === $pos ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 500 ) {
				return array(
					'status'  => 'fatal',
					/* translators: %d: HTTP status code. */
					'message' => sprintf( __( 'The site returned HTTP %d after the restore.', 'tbsh-safespot-restore-points' ), $code ),
					'error'   => null,
				);
			}
			return array(
				'status'  => 'unknown',
				'message' => __( 'The site could not be fully verified after the restore. Please check it manually.', 'tbsh-safespot-restore-points' ),
				'error'   => null,
			);
		}

		$output = substr( $body, $pos + strlen( $start ) );
		$output = substr( $output, 0, (int) strpos( $output, $end ) );
		$result = json_decode( trim( $output ), true );

		if ( true === $result ) {
			return array(
				'status'  => 'ok',
				'message' => __( 'The site loaded without fatal errors.', 'tbsh-safespot-restore-points' ),
				'error'   => null,
			);
		}

		if ( is_array( $result ) && isset( $result['message'] ) ) {
			if ( isset( $result['code'] ) && 'scrape_nonce_failure' === $result['code'] ) {
				return array(
					'status'  => 'unknown',
					'message' => __( 'The site could not be verified after the restore (scrape check failed).', 'tbsh-safespot-restore-points' ),
					'error'   => null,
				);
			}
			$message = wp_strip_all_tags( (string) $result['message'] );
			if ( isset( $result['file'], $result['line'] ) ) {
				/* translators: 1: error message, 2: file, 3: line. */
				$message = sprintf( __( '%1$s in %2$s on line %3$d', 'tbsh-safespot-restore-points' ), $message, (string) $result['file'], (int) $result['line'] );
			}
			return array(
				'status'  => 'fatal',
				'message' => $message,
				'error'   => $result,
			);
		}

		return array(
			'status'  => 'unknown',
			'message' => __( 'The site could not be fully verified after the restore. Please check it manually.', 'tbsh-safespot-restore-points' ),
			'error'   => null,
		);
	}
}
