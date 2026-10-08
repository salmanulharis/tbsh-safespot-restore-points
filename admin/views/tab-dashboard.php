<?php
/**
 * Dashboard tab.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

$safespot_stats    = SafeSpot_Points::stats();
$safespot_restored = SafeSpot_Logger::latest( array( 'restore', 'rollback' ) );
$safespot_last     = SafeSpot_Restore::last_restore();
$safespot_recent   = SafeSpot_Logger::recent( 8 );
$safespot_free     = SafeSpot_Storage::free_space();
$safespot_auto_p   = SafeSpot_Settings::auto_backup_enabled( 'plugin' );
$safespot_auto_t   = SafeSpot_Settings::auto_backup_enabled( 'theme' );
if ( $safespot_auto_p && $safespot_auto_t ) {
	$safespot_auto_label = __( 'Auto-SafeSpots before updates: on', 'tbsh-safespot-restore-points' );
} elseif ( $safespot_auto_p ) {
	$safespot_auto_label = __( 'Auto-SafeSpots before updates: plugins only', 'tbsh-safespot-restore-points' );
} elseif ( $safespot_auto_t ) {
	$safespot_auto_label = __( 'Auto-SafeSpots before updates: themes only', 'tbsh-safespot-restore-points' );
} else {
	$safespot_auto_label = __( 'Auto-SafeSpots before updates: off', 'tbsh-safespot-restore-points' );
}

$safespot_pending = array();
if ( SafeSpot_Permissions::can( 'save', 'plugin' ) ) {
	foreach ( SafeSpot_Items::plugins() as $safespot_item ) {
		if ( '' !== $safespot_item['update'] && ! SafeSpot_Settings::get_hold( 'plugin', $safespot_item['slug'] ) ) {
			$safespot_pending[] = $safespot_item;
		}
	}
}
if ( SafeSpot_Permissions::can( 'save', 'theme' ) ) {
	foreach ( SafeSpot_Items::themes() as $safespot_item ) {
		if ( '' !== $safespot_item['update'] && ! SafeSpot_Settings::get_hold( 'theme', $safespot_item['slug'] ) ) {
			$safespot_pending[] = $safespot_item;
		}
	}
}

$safespot_status_icons = array(
	'success' => 'yes-alt',
	'error'   => 'dismiss',
	'warning' => 'warning',
	'info'    => 'info',
);
?>
<section class="safespot-cards" aria-label="<?php esc_attr_e( 'Summary', 'tbsh-safespot-restore-points' ); ?>">
	<div class="safespot-card">
		<span class="safespot-card__icon dashicons dashicons-shield" aria-hidden="true"></span>
		<h2 class="safespot-card__label"><?php esc_html_e( 'Total SafeSpots', 'tbsh-safespot-restore-points' ); ?></h2>
		<p class="safespot-card__value"><?php echo esc_html( number_format_i18n( $safespot_stats['total'] ) ); ?></p>
		<p class="safespot-card__meta">
			<span class="safespot-dot<?php echo $safespot_auto_p || $safespot_auto_t ? ' safespot-dot--on' : ''; ?>" aria-hidden="true"></span><?php echo esc_html( $safespot_auto_label ); ?>
		</p>
	</div>

	<div class="safespot-card">
		<span class="safespot-card__icon dashicons dashicons-database" aria-hidden="true"></span>
		<h2 class="safespot-card__label"><?php esc_html_e( 'Storage used', 'tbsh-safespot-restore-points' ); ?></h2>
		<p class="safespot-card__value"><?php echo esc_html( (string) size_format( $safespot_stats['size'], 1 ) ); ?></p>
		<?php if ( null !== $safespot_free ) : ?>
			<?php $safespot_pct = $safespot_free + $safespot_stats['size'] > 0 ? min( 100, max( 1, (int) round( $safespot_stats['size'] / ( $safespot_free + $safespot_stats['size'] ) * 100 ) ) ) : 0; ?>
			<div class="safespot-meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $safespot_pct ); ?>" aria-label="<?php esc_attr_e( 'Share of free disk space used by SafeSpots', 'tbsh-safespot-restore-points' ); ?>">
				<span style="width: <?php echo esc_attr( (string) $safespot_pct ); ?>%"></span>
			</div>
			<p class="safespot-card__meta">
				<?php
				/* translators: %s: free disk space. */
				echo esc_html( sprintf( __( '%s free on disk', 'tbsh-safespot-restore-points' ), size_format( $safespot_free, 1 ) ) );
				?>
			</p>
		<?php endif; ?>
	</div>

	<div class="safespot-card">
		<span class="safespot-card__icon dashicons dashicons-saved" aria-hidden="true"></span>
		<h2 class="safespot-card__label"><?php esc_html_e( 'Last backup', 'tbsh-safespot-restore-points' ); ?></h2>
		<?php if ( $safespot_stats['latest'] ) : ?>
			<p class="safespot-card__value safespot-card__value--small"><?php echo esc_html( SafeSpot_Admin_Views::ago( $safespot_stats['latest']['created_ts'] ) ); ?></p>
			<p class="safespot-card__meta"><?php echo esc_html( $safespot_stats['latest']['name'] . ' ' . $safespot_stats['latest']['version'] ); ?></p>
		<?php else : ?>
			<p class="safespot-card__value safespot-card__value--small"><?php esc_html_e( 'Never', 'tbsh-safespot-restore-points' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="safespot-card">
		<span class="safespot-card__icon dashicons dashicons-backup" aria-hidden="true"></span>
		<h2 class="safespot-card__label"><?php esc_html_e( 'Last restore', 'tbsh-safespot-restore-points' ); ?></h2>
		<?php if ( $safespot_restored ) : ?>
			<p class="safespot-card__value safespot-card__value--small"><?php echo esc_html( SafeSpot_Admin_Views::ago( $safespot_restored['created_ts'] ) ); ?></p>
			<p class="safespot-card__meta"><?php echo esc_html( $safespot_restored['name'] ); ?></p>
		<?php else : ?>
			<p class="safespot-card__value safespot-card__value--small"><?php esc_html_e( 'Never', 'tbsh-safespot-restore-points' ); ?></p>
		<?php endif; ?>
		<?php if ( $safespot_last && ! empty( $safespot_last['pre_point_id'] ) && SafeSpot_Permissions::can( 'restore', $safespot_last['type'] ) ) : ?>
			<button type="button" class="button safespot-card__action" data-safespot-action="rollback-last" data-name="<?php echo esc_attr( $safespot_last['name'] ); ?>" data-from="<?php echo esc_attr( $safespot_last['version_to'] ); ?>" data-to="<?php echo esc_attr( $safespot_last['version_from'] ); ?>">
				<span class="dashicons dashicons-undo" aria-hidden="true"></span>
				<?php esc_html_e( 'Roll back this restore', 'tbsh-safespot-restore-points' ); ?>
			</button>
		<?php endif; ?>
	</div>
</section>

<div class="safespot-columns">
	<section class="safespot-panel">
		<header class="safespot-panel__header">
			<h2><?php esc_html_e( 'Recent activity', 'tbsh-safespot-restore-points' ); ?></h2>
			<a href="<?php echo esc_url( SafeSpot_Admin_Views::page_url( array( 'tab' => 'log' ) ) ); ?>"><?php esc_html_e( 'View all', 'tbsh-safespot-restore-points' ); ?></a>
		</header>
		<?php if ( empty( $safespot_recent ) ) : ?>
			<div class="safespot-empty">
				<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
				<p><?php esc_html_e( 'No activity yet. Save your first SafeSpot from the Plugins or Themes tab.', 'tbsh-safespot-restore-points' ); ?></p>
			</div>
		<?php else : ?>
			<ul class="safespot-activity">
				<?php foreach ( $safespot_recent as $safespot_entry ) : ?>
					<li class="safespot-activity__item safespot-activity__item--<?php echo esc_attr( $safespot_entry['status'] ); ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $safespot_status_icons[ $safespot_entry['status'] ] ?? 'info' ); ?>" aria-hidden="true"></span>
						<div>
							<p class="safespot-activity__message"><?php echo esc_html( $safespot_entry['message'] ); ?></p>
							<p class="safespot-activity__meta">
								<time datetime="<?php echo esc_attr( $safespot_entry['created_at'] ); ?>"><?php echo esc_html( SafeSpot_Admin_Views::ago( $safespot_entry['created_ts'] ) ); ?></time>
								· <?php echo esc_html( $safespot_entry['user'] ); ?>
							</p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="safespot-panel">
		<header class="safespot-panel__header">
			<h2><?php esc_html_e( 'Updates waiting', 'tbsh-safespot-restore-points' ); ?></h2>
		</header>
		<?php if ( empty( $safespot_pending ) ) : ?>
			<div class="safespot-empty">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<p><?php esc_html_e( 'Everything is up to date.', 'tbsh-safespot-restore-points' ); ?></p>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Save a SafeSpot of these before you update them.', 'tbsh-safespot-restore-points' ); ?></p>
			<ul class="safespot-pending">
				<?php foreach ( $safespot_pending as $safespot_item ) : ?>
					<li data-type="<?php echo esc_attr( $safespot_item['type'] ); ?>" data-slug="<?php echo esc_attr( $safespot_item['slug'] ); ?>">
						<span class="dashicons <?php echo 'plugin' === $safespot_item['type'] ? 'dashicons-admin-plugins' : 'dashicons-admin-appearance'; ?>" aria-hidden="true"></span>
						<strong><?php echo esc_html( $safespot_item['name'] ); ?></strong>
						<span class="safespot-muted"><?php echo esc_html( $safespot_item['version'] . ' → ' . $safespot_item['update'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="button" class="button button-primary" data-safespot-action="save-pending">
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<?php esc_html_e( 'Save SafeSpots for all of these', 'tbsh-safespot-restore-points' ); ?>
				</button>
			</p>
			<div class="safespot-queue-status" hidden></div>
		<?php endif; ?>
	</section>
</div>
