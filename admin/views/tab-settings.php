<?php
/**
 * Settings tab.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

if ( ! SafeSpot_Permissions::can( 'settings' ) ) {
	echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'You do not have permission to change SafeSpot settings.', 'tbsh-safespot-restore-points' ) . '</p></div>';
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';

$safespot_s       = SafeSpot_Settings::all();
$safespot_stats   = SafeSpot_Points::stats();
$safespot_free    = SafeSpot_Storage::free_space();
$safespot_method  = get_filesystem_method( array(), WP_CONTENT_DIR );
$safespot_cron    = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$safespot_holds   = (array) $safespot_s['holds'];
$safespot_exclude = (array) $safespot_s['excluded'];

/**
 * Resolves a "type:slug" key to a display name.
 *
 * @param string $key Key.
 * @return array { type, slug, name }
 */
$safespot_resolve = static function ( $key ) {
	list( $type, $slug ) = array_pad( explode( ':', $key, 2 ), 2, '' );
	$item                = SafeSpot_Items::get( $type, $slug );
	return array(
		'type' => $type,
		'slug' => $slug,
		'name' => $item ? $item['name'] : $slug,
	);
};
?>
<form id="safespot-settings" class="safespot-settings" novalidate>
	<section class="safespot-panel">
		<h2><?php esc_html_e( 'Automatic SafeSpots', 'tbsh-safespot-restore-points' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Before updates', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Before updates', 'tbsh-safespot-restore-points' ); ?></legend>
						<label><input type="checkbox" name="auto_backup" value="1" <?php checked( $safespot_s['auto_backup'] ); ?> /> <?php esc_html_e( 'Save a SafeSpot automatically before any plugin or theme update', 'tbsh-safespot-restore-points' ); ?></label>
						<p class="description"><?php esc_html_e( 'Covers manual, bulk and automatic background updates, and uploaded zips that replace an installed version.', 'tbsh-safespot-restore-points' ); ?></p>
						<label><input type="checkbox" name="auto_backup_plugins" value="1" <?php checked( $safespot_s['auto_backup_plugins'] ); ?> /> <?php esc_html_e( 'Plugins', 'tbsh-safespot-restore-points' ); ?></label><br />
						<label><input type="checkbox" name="auto_backup_themes" value="1" <?php checked( $safespot_s['auto_backup_themes'] ); ?> /> <?php esc_html_e( 'Themes', 'tbsh-safespot-restore-points' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Before deleting', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<label><input type="checkbox" name="backup_before_delete" value="1" <?php checked( $safespot_s['backup_before_delete'] ); ?> /> <?php esc_html_e( 'Save a SafeSpot before a plugin or theme is deleted, so it can be reinstalled later', 'tbsh-safespot-restore-points' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'If the SafeSpot fails', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<label><input type="checkbox" name="abort_update_on_failure" value="1" <?php checked( $safespot_s['abort_update_on_failure'] ); ?> /> <?php esc_html_e( 'Cancel the update when an automatic SafeSpot cannot be saved', 'tbsh-safespot-restore-points' ); ?></label>
					<p class="description"><?php esc_html_e( 'Safer, but a full disk could then block security updates. When off, the update continues and the failure is logged.', 'tbsh-safespot-restore-points' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Excluded items', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<?php if ( empty( $safespot_exclude ) ) : ?>
						<p class="description"><?php esc_html_e( 'None. Use the "Auto" switch on the Plugins and Themes tabs to exclude specific items.', 'tbsh-safespot-restore-points' ); ?></p>
					<?php else : ?>
						<ul class="safespot-chip-list">
							<?php foreach ( $safespot_exclude as $safespot_key ) : ?>
								<?php $safespot_r = $safespot_resolve( $safespot_key ); ?>
								<li class="safespot-chip">
									<?php echo esc_html( $safespot_r['name'] ); ?>
									<button type="button" class="safespot-chip__remove" data-safespot-action="include" data-type="<?php echo esc_attr( $safespot_r['type'] ); ?>" data-slug="<?php echo esc_attr( $safespot_r['slug'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item name. */ __( 'Include %s in automatic SafeSpots again', 'tbsh-safespot-restore-points' ), $safespot_r['name'] ) ); ?>">
										<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	</section>

	<section class="safespot-panel">
		<h2><?php esc_html_e( 'Retention', 'tbsh-safespot-restore-points' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="safespot-retention"><?php esc_html_e( 'Keep per item', 'tbsh-safespot-restore-points' ); ?></label></th>
				<td>
					<input type="number" id="safespot-retention" name="retention_count" min="0" max="100" step="1" class="small-text" value="<?php echo esc_attr( (string) $safespot_s['retention_count'] ); ?>" aria-describedby="safespot-retention-desc" />
					<p class="description" id="safespot-retention-desc"><?php esc_html_e( 'Newest SafeSpots to keep for each plugin or theme. Locked SafeSpots are never deleted automatically. 0 keeps everything.', 'tbsh-safespot-restore-points' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="safespot-max-age"><?php esc_html_e( 'Delete older than', 'tbsh-safespot-restore-points' ); ?></label></th>
				<td>
					<input type="number" id="safespot-max-age" name="max_age_days" min="0" max="3650" step="1" class="small-text" value="<?php echo esc_attr( (string) $safespot_s['max_age_days'] ); ?>" aria-describedby="safespot-max-age-desc" /> <?php esc_html_e( 'days', 'tbsh-safespot-restore-points' ); ?>
					<p class="description" id="safespot-max-age-desc"><?php esc_html_e( 'Optional. 0 disables age-based cleanup.', 'tbsh-safespot-restore-points' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="safespot-log-days"><?php esc_html_e( 'Activity log', 'tbsh-safespot-restore-points' ); ?></label></th>
				<td>
					<input type="number" id="safespot-log-days" name="log_retention_days" min="0" max="3650" step="1" class="small-text" value="<?php echo esc_attr( (string) $safespot_s['log_retention_days'] ); ?>" /> <?php esc_html_e( 'days (0 keeps everything)', 'tbsh-safespot-restore-points' ); ?>
				</td>
			</tr>
		</table>
	</section>

	<section class="safespot-panel">
		<h2><?php esc_html_e( 'Settings snapshot', 'tbsh-safespot-restore-points' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Include settings', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings_snapshot" value="1" <?php checked( $safespot_s['settings_snapshot'] ); ?> /> <?php esc_html_e( 'Also export the item\'s wp_options rows (and theme_mods for themes) with every SafeSpot by default', 'tbsh-safespot-restore-points' ); ?></label>
					<p class="description"><?php esc_html_e( 'Options are matched by name prefix. You can edit the prefixes per item in the "Save SafeSpot" dialog. When restoring you choose between files only or files + settings.', 'tbsh-safespot-restore-points' ); ?></p>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'Custom database tables are not included in settings snapshots.', 'tbsh-safespot-restore-points' ); ?></p></div>
				</td>
			</tr>
		</table>
	</section>

	<section class="safespot-panel">
		<h2><?php esc_html_e( 'Safety', 'tbsh-safespot-restore-points' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'After restoring', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<label><input type="checkbox" name="health_check" value="1" <?php checked( $safespot_s['health_check'] ); ?> /> <?php esc_html_e( 'Load the site in the background and roll back automatically if the restored version causes a fatal error', 'tbsh-safespot-restore-points' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Failure emails', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<label><input type="checkbox" name="email_on_failure" value="1" <?php checked( $safespot_s['email_on_failure'] ); ?> /> <?php esc_html_e( 'Email me when an automatic SafeSpot or a restore fails', 'tbsh-safespot-restore-points' ); ?></label>
					<p>
						<label for="safespot-email" class="screen-reader-text"><?php esc_html_e( 'Notification email', 'tbsh-safespot-restore-points' ); ?></label>
						<input type="email" id="safespot-email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $safespot_s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( SafeSpot_Settings::notify_email() ); ?>" />
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Paused updates', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<?php if ( empty( $safespot_holds ) ) : ?>
						<p class="description"><?php esc_html_e( 'None. When you restore an older version you can pause its updates so it is not updated straight back.', 'tbsh-safespot-restore-points' ); ?></p>
					<?php else : ?>
						<ul class="safespot-chip-list">
							<?php foreach ( $safespot_holds as $safespot_key => $safespot_hold ) : ?>
								<?php $safespot_r = $safespot_resolve( $safespot_key ); ?>
								<li class="safespot-chip">
									<?php echo esc_html( $safespot_r['name'] . ( empty( $safespot_hold['version'] ) ? '' : ' ' . $safespot_hold['version'] ) ); ?>
									<button type="button" class="safespot-chip__remove" data-safespot-action="resume" data-type="<?php echo esc_attr( $safespot_r['type'] ); ?>" data-slug="<?php echo esc_attr( $safespot_r['slug'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item name. */ __( 'Resume updates for %s', 'tbsh-safespot-restore-points' ), $safespot_r['name'] ) ); ?>">
										<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $safespot_s['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Delete all SafeSpots, settings and the activity log when SafeSpot is deleted', 'tbsh-safespot-restore-points' ); ?></label>
				</td>
			</tr>
		</table>
	</section>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'tbsh-safespot-restore-points' ); ?></button>
	</p>
</form>

<section class="safespot-panel">
	<h2><?php esc_html_e( 'Storage & environment', 'tbsh-safespot-restore-points' ); ?></h2>
	<table class="widefat striped safespot-env">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Storage folder', 'tbsh-safespot-restore-points' ); ?></th>
				<td><code><?php echo esc_html( SafeSpot_Storage::display_path() ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Protection files', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<?php if ( SafeSpot_Storage::is_protected() ) : ?>
						<?php echo SafeSpot_Admin_Views::badge( __( '.htaccess, web.config and index.php present', 'tbsh-safespot-restore-points' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
					<?php else : ?>
						<?php echo SafeSpot_Admin_Views::badge( __( 'Missing', 'tbsh-safespot-restore-points' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Downloads are only served through an authenticated handler. On nginx, deny access to this folder in your server config or move it with the safespot_storage_path filter.', 'tbsh-safespot-restore-points' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Storage used', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<?php
					/* translators: 1: size, 2: number of SafeSpots. */
					echo esc_html( sprintf( _n( '%1$s in %2$s SafeSpot', '%1$s in %2$s SafeSpots', $safespot_stats['total'], 'tbsh-safespot-restore-points' ), size_format( $safespot_stats['size'], 1 ), number_format_i18n( $safespot_stats['total'] ) ) );
					if ( null !== $safespot_free ) {
						/* translators: %s: free space. */
						echo ' · ' . esc_html( sprintf( __( '%s free', 'tbsh-safespot-restore-points' ), size_format( $safespot_free, 1 ) ) );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Zip engine', 'tbsh-safespot-restore-points' ); ?></th>
				<td><?php echo 'ziparchive' === SafeSpot_Zip::engine() ? 'ZipArchive' : esc_html__( 'PclZip (ZipArchive is not available)', 'tbsh-safespot-restore-points' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Filesystem method', 'tbsh-safespot-restore-points' ); ?></th>
				<td><code><?php echo esc_html( $safespot_method ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Background processing', 'tbsh-safespot-restore-points' ); ?></th>
				<td>
					<?php
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						esc_html_e( 'Action Scheduler', 'tbsh-safespot-restore-points' );
					} elseif ( $safespot_cron ) {
						esc_html_e( 'WP-Cron (DISABLE_WP_CRON is set – make sure a real cron job runs wp-cron.php)', 'tbsh-safespot-restore-points' );
					} else {
						esc_html_e( 'WP-Cron', 'tbsh-safespot-restore-points' );
					}
					?>
				</td>
			</tr>
		</tbody>
	</table>
</section>
