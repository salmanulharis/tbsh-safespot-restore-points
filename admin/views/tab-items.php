<?php
/**
 * Plugins / Themes tab.
 *
 * @package SafeSpot
 *
 * @var string $tab plugins|themes.
 */

defined( 'ABSPATH' ) || exit;

$safespot_type = 'themes' === $tab ? 'theme' : 'plugin';

if ( ! SafeSpot_Permissions::can( 'view', $safespot_type ) ) {
	echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'You do not have permission to manage these SafeSpots.', 'tbsh-safespot-restore-points' ) . '</p></div>';
	return;
}

$safespot_rows   = SafeSpot_Admin_Views::collect_items( $safespot_type );
$safespot_points = array();
foreach ( SafeSpot_Points::query( array( 'type' => $safespot_type ) ) as $safespot_point ) {
	$safespot_points[ $safespot_point['slug'] ][] = $safespot_point;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state.
$safespot_open = isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : '';
$safespot_noun = 'plugin' === $safespot_type ? __( 'plugins', 'tbsh-safespot-restore-points' ) : __( 'themes', 'tbsh-safespot-restore-points' );

if ( ! SafeSpot_Settings::auto_backup_enabled( $safespot_type ) ) {
	if ( ! SafeSpot_Settings::get( 'auto_backup' ) ) {
		$safespot_auto_message = __( 'Automatic SafeSpots before updates are turned off, so nothing is saved automatically and the Auto switches below have no effect.', 'tbsh-safespot-restore-points' );
	} elseif ( 'plugin' === $safespot_type ) {
		$safespot_auto_message = __( 'Automatic SafeSpots are turned off for plugins, so nothing is saved automatically before plugin updates and the Auto switches below have no effect.', 'tbsh-safespot-restore-points' );
	} else {
		$safespot_auto_message = __( 'Automatic SafeSpots are turned off for themes, so nothing is saved automatically before theme updates and the Auto switches below have no effect.', 'tbsh-safespot-restore-points' );
	}
	?>
	<div class="notice notice-warning inline safespot-auto-notice">
		<p>
			<?php echo esc_html( $safespot_auto_message ); ?>
			<?php if ( SafeSpot_Permissions::can( 'settings' ) ) : ?>
				<a href="<?php echo esc_url( SafeSpot_Admin_Views::page_url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Change this in Settings', 'tbsh-safespot-restore-points' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
?>
<div class="safespot-toolbar">
	<div class="safespot-toolbar__search">
		<label for="safespot-search" class="screen-reader-text">
			<?php
			/* translators: %s: "plugins" or "themes". */
			echo esc_html( sprintf( __( 'Search %s', 'tbsh-safespot-restore-points' ), $safespot_noun ) );
			?>
		</label>
		<span class="dashicons dashicons-search" aria-hidden="true"></span>
		<input type="search" id="safespot-search" class="safespot-search" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: "plugins" or "themes". */ __( 'Search %s…', 'tbsh-safespot-restore-points' ), $safespot_noun ) ); ?>" autocomplete="off" />
	</div>
	<div class="safespot-toolbar__filter">
		<label for="safespot-filter" class="screen-reader-text"><?php esc_html_e( 'Filter', 'tbsh-safespot-restore-points' ); ?></label>
		<select id="safespot-filter" class="safespot-filter">
			<option value="all"><?php esc_html_e( 'All', 'tbsh-safespot-restore-points' ); ?></option>
			<option value="with"><?php esc_html_e( 'With SafeSpots', 'tbsh-safespot-restore-points' ); ?></option>
			<option value="without"><?php esc_html_e( 'Without SafeSpots', 'tbsh-safespot-restore-points' ); ?></option>
			<option value="updates"><?php esc_html_e( 'Update available', 'tbsh-safespot-restore-points' ); ?></option>
			<option value="active"><?php esc_html_e( 'Active', 'tbsh-safespot-restore-points' ); ?></option>
			<option value="not_installed"><?php esc_html_e( 'Not installed', 'tbsh-safespot-restore-points' ); ?></option>
		</select>
	</div>
	<div class="safespot-toolbar__bulk">
		<button type="button" class="button" data-safespot-action="bulk-save" disabled>
			<span class="dashicons dashicons-shield" aria-hidden="true"></span>
			<?php esc_html_e( 'Save SafeSpots for selected', 'tbsh-safespot-restore-points' ); ?>
		</button>
	</div>
	<p class="safespot-toolbar__count" aria-live="polite"></p>
</div>
<div class="safespot-queue-status" hidden></div>

<?php if ( empty( $safespot_rows ) ) : ?>
	<div class="safespot-empty">
		<span class="dashicons dashicons-shield" aria-hidden="true"></span>
		<p><?php esc_html_e( 'Nothing installed yet.', 'tbsh-safespot-restore-points' ); ?></p>
	</div>
<?php else : ?>
	<table class="wp-list-table widefat striped safespot-items" data-type="<?php echo esc_attr( $safespot_type ); ?>">
		<thead>
			<tr>
				<td class="manage-column check-column">
					<label class="screen-reader-text" for="safespot-select-all"><?php esc_html_e( 'Select all', 'tbsh-safespot-restore-points' ); ?></label>
					<input type="checkbox" id="safespot-select-all" class="safespot-select-all" />
				</td>
				<th scope="col" class="column-primary"><?php echo 'plugin' === $safespot_type ? esc_html__( 'Plugin', 'tbsh-safespot-restore-points' ) : esc_html__( 'Theme', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Version', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'SafeSpots', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Latest SafeSpot', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Storage', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><abbr title="<?php esc_attr_e( 'Automatic SafeSpot before updates', 'tbsh-safespot-restore-points' ); ?>"><?php esc_html_e( 'Auto', 'tbsh-safespot-restore-points' ); ?></abbr></th>
				<th scope="col" class="safespot-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tbsh-safespot-restore-points' ); ?></span></th>
			</tr>
		</thead>
		<?php
		foreach ( $safespot_rows as $safespot_slug => $safespot_row ) {
			SafeSpot_Admin_Views::template(
				'item',
				array(
					'item'     => $safespot_row['item'],
					'points'   => $safespot_points[ $safespot_slug ] ?? array(),
					'expanded' => '' !== $safespot_open && ( $safespot_open === $safespot_slug || $safespot_open === $safespot_row['item']['folder'] ),
				)
			);
		}
		?>
	</table>
	<?php
	$safespot_can_lock   = SafeSpot_Permissions::can( 'save', $safespot_type );
	$safespot_can_delete = SafeSpot_Permissions::can( 'delete', $safespot_type );
	?>
	<?php if ( $safespot_can_lock || $safespot_can_delete ) : ?>
		<div class="safespot-selection-bar" role="region" aria-label="<?php esc_attr_e( 'Selected SafeSpots', 'tbsh-safespot-restore-points' ); ?>" hidden>
			<p class="safespot-selection-bar__count" aria-live="polite"></p>
			<div class="safespot-selection-bar__actions">
				<?php if ( $safespot_can_lock ) : ?>
					<button type="button" class="button" data-safespot-action="bulk-lock">
						<span class="dashicons dashicons-lock" aria-hidden="true"></span>
						<?php esc_html_e( 'Lock', 'tbsh-safespot-restore-points' ); ?>
					</button>
					<button type="button" class="button" data-safespot-action="bulk-unlock">
						<span class="dashicons dashicons-unlock" aria-hidden="true"></span>
						<?php esc_html_e( 'Unlock', 'tbsh-safespot-restore-points' ); ?>
					</button>
				<?php endif; ?>
				<?php if ( $safespot_can_delete ) : ?>
					<button type="button" class="button safespot-bulk-delete" data-safespot-action="bulk-delete">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Delete…', 'tbsh-safespot-restore-points' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="button-link" data-safespot-action="clear-selection">
					<?php esc_html_e( 'Clear selection', 'tbsh-safespot-restore-points' ); ?>
				</button>
			</div>
		</div>
	<?php endif; ?>
	<div class="safespot-empty safespot-no-results" hidden>
		<span class="dashicons dashicons-search" aria-hidden="true"></span>
		<p><?php esc_html_e( 'Nothing matches your search.', 'tbsh-safespot-restore-points' ); ?></p>
	</div>
<?php endif; ?>
