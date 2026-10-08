<?php
/**
 * Admin view rendering.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders admin templates. Item rows are rendered here for both the initial page load
 * and REST responses, so the markup lives in one place.
 */
class SafeSpot_Admin_Views {

	/**
	 * Includes a template with variables.
	 *
	 * @param string $name Template name (without .php).
	 * @param array  $vars Variables.
	 */
	public static function template( $name, array $vars = array() ) {
		$file = SAFESPOT_DIR . 'admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables.
		include $file;
	}

	/**
	 * Returns a rendered template.
	 *
	 * @param string $name Template.
	 * @param array  $vars Variables.
	 * @return string
	 */
	public static function render( $name, array $vars = array() ) {
		ob_start();
		self::template( $name, $vars );
		return (string) ob_get_clean();
	}

	/**
	 * Base URL of the SafeSpot page.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		$base = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return add_query_arg( array_merge( array( 'page' => 'safespot' ), $args ), $base );
	}

	/**
	 * DOM id for an item.
	 *
	 * @param string $type Type.
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function item_id( $type, $slug ) {
		return 'safespot-item-' . substr( md5( $type . ':' . $slug ), 0, 12 );
	}

	/**
	 * Every item (installed or with SafeSpots) for a type, sorted by name.
	 *
	 * @param string $type Type.
	 * @return array[] Each { item, summary }.
	 */
	public static function collect_items( $type ) {
		$installed = 'plugin' === $type ? SafeSpot_Items::plugins() : SafeSpot_Items::themes();
		$summary   = SafeSpot_Points::summary( $type );
		$rows      = array();

		foreach ( $installed as $slug => $item ) {
			$rows[ $slug ] = array(
				'item'    => $item,
				'summary' => $summary[ $slug ] ?? null,
			);
		}
		foreach ( $summary as $slug => $info ) {
			if ( ! isset( $rows[ $slug ] ) ) {
				$rows[ $slug ] = array(
					'item'    => SafeSpot_Items::missing_item( $type, $slug, $info['name'] ),
					'summary' => $info,
				);
			}
		}

		uasort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['item']['name'], $b['item']['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Renders one item (main row + history row) as a <tbody>.
	 *
	 * @param string $type     Type.
	 * @param string $slug     Slug.
	 * @param bool   $expanded Whether the history row starts open.
	 * @return string Empty string when the item neither exists nor has SafeSpots.
	 */
	public static function item_html( $type, $slug, $expanded = false ) {
		$item   = SafeSpot_Items::get( $type, $slug );
		$points = SafeSpot_Points::query(
			array(
				'type' => $type,
				'slug' => $slug,
			)
		);

		if ( ! $item ) {
			if ( empty( $points ) ) {
				return '';
			}
			$item = SafeSpot_Items::missing_item( $type, $slug, $points[0]['name'] );
		}

		return self::render(
			'item',
			array(
				'item'     => $item,
				'points'   => $points,
				'expanded' => $expanded,
			)
		);
	}

	/**
	 * Status badge.
	 *
	 * @param string $label Label.
	 * @param string $modifier Modifier class.
	 * @return string
	 */
	public static function badge( $label, $modifier = '' ) {
		return '<span class="safespot-badge' . ( $modifier ? ' safespot-badge--' . esc_attr( $modifier ) : '' ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * "x ago" string.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	public static function ago( $timestamp ) {
		if ( ! $timestamp ) {
			return '—';
		}
		/* translators: %s: human time difference, e.g. "5 mins". */
		return sprintf( __( '%s ago', 'tbsh-safespot-restore-points' ), human_time_diff( $timestamp, time() ) );
	}
}
