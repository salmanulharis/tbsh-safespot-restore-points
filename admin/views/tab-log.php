<?php
/**
 * Activity log tab.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
$safespot_filters = array(
	'action' => isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '',
	'status' => isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( $_GET['log_status'] ) ) : '',
	'type'   => isset( $_GET['log_type'] ) ? sanitize_key( wp_unslash( $_GET['log_type'] ) ) : '',
	'search' => isset( $_GET['log_search'] ) ? sanitize_text_field( wp_unslash( $_GET['log_search'] ) ) : '',
	'page'   => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
);
// phpcs:enable

$safespot_result = SafeSpot_Logger::query( array_merge( $safespot_filters, array( 'per_page' => 30 ) ) );
$safespot_labels = SafeSpot_Logger::statuses();
?>
<form method="get" class="safespot-toolbar safespot-log-filters" action="<?php echo esc_url( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="safespot" />
	<input type="hidden" name="tab" value="log" />

	<div class="safespot-toolbar__search">
		<label for="safespot-log-search" class="screen-reader-text"><?php esc_html_e( 'Search the activity log', 'tbsh-safespot-restore-points' ); ?></label>
		<span class="dashicons dashicons-search" aria-hidden="true"></span>
		<input type="search" id="safespot-log-search" name="log_search" value="<?php echo esc_attr( $safespot_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'tbsh-safespot-restore-points' ); ?>" />
	</div>

	<label for="safespot-log-action" class="screen-reader-text"><?php esc_html_e( 'Action', 'tbsh-safespot-restore-points' ); ?></label>
	<select id="safespot-log-action" name="log_action">
		<option value=""><?php esc_html_e( 'All actions', 'tbsh-safespot-restore-points' ); ?></option>
		<?php foreach ( SafeSpot_Logger::actions() as $safespot_key => $safespot_label ) : ?>
			<option value="<?php echo esc_attr( $safespot_key ); ?>" <?php selected( $safespot_filters['action'], $safespot_key ); ?>><?php echo esc_html( $safespot_label ); ?></option>
		<?php endforeach; ?>
	</select>

	<label for="safespot-log-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'tbsh-safespot-restore-points' ); ?></label>
	<select id="safespot-log-status" name="log_status">
		<option value=""><?php esc_html_e( 'All statuses', 'tbsh-safespot-restore-points' ); ?></option>
		<?php foreach ( $safespot_labels as $safespot_key => $safespot_label ) : ?>
			<option value="<?php echo esc_attr( $safespot_key ); ?>" <?php selected( $safespot_filters['status'], $safespot_key ); ?>><?php echo esc_html( $safespot_label ); ?></option>
		<?php endforeach; ?>
	</select>

	<label for="safespot-log-type" class="screen-reader-text"><?php esc_html_e( 'Type', 'tbsh-safespot-restore-points' ); ?></label>
	<select id="safespot-log-type" name="log_type">
		<option value=""><?php esc_html_e( 'Plugins and themes', 'tbsh-safespot-restore-points' ); ?></option>
		<option value="plugin" <?php selected( $safespot_filters['type'], 'plugin' ); ?>><?php esc_html_e( 'Plugins', 'tbsh-safespot-restore-points' ); ?></option>
		<option value="theme" <?php selected( $safespot_filters['type'], 'theme' ); ?>><?php esc_html_e( 'Themes', 'tbsh-safespot-restore-points' ); ?></option>
	</select>

	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'tbsh-safespot-restore-points' ); ?></button>

	<?php if ( SafeSpot_Permissions::can( 'settings' ) && $safespot_result['total'] > 0 ) : ?>
		<button type="button" class="button button-link-delete safespot-toolbar__end" data-safespot-action="clear-log">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			<?php esc_html_e( 'Clear log', 'tbsh-safespot-restore-points' ); ?>
		</button>
	<?php endif; ?>
</form>

<?php if ( empty( $safespot_result['items'] ) ) : ?>
	<div class="safespot-empty">
		<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
		<p><?php esc_html_e( 'No activity recorded yet.', 'tbsh-safespot-restore-points' ); ?></p>
	</div>
<?php else : ?>
	<table class="wp-list-table widefat striped safespot-log">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Item', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'tbsh-safespot-restore-points' ); ?></th>
				<th scope="col"><?php esc_html_e( 'User', 'tbsh-safespot-restore-points' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $safespot_result['items'] as $safespot_entry ) : ?>
				<tr>
					<td data-colname="<?php esc_attr_e( 'When', 'tbsh-safespot-restore-points' ); ?>"><time datetime="<?php echo esc_attr( $safespot_entry['created_at'] ); ?>"><?php echo esc_html( $safespot_entry['created_human'] ); ?></time></td>
					<td data-colname="<?php esc_attr_e( 'Action', 'tbsh-safespot-restore-points' ); ?>"><?php echo esc_html( $safespot_entry['action_label'] ); ?></td>
					<td data-colname="<?php esc_attr_e( 'Status', 'tbsh-safespot-restore-points' ); ?>"><?php echo SafeSpot_Admin_Views::badge( $safespot_labels[ $safespot_entry['status'] ] ?? $safespot_entry['status'], $safespot_entry['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></td>
					<td data-colname="<?php esc_attr_e( 'Item', 'tbsh-safespot-restore-points' ); ?>">
						<?php if ( '' !== $safespot_entry['name'] ) : ?>
							<span class="dashicons <?php echo 'theme' === $safespot_entry['type'] ? 'dashicons-admin-appearance' : 'dashicons-admin-plugins'; ?>" aria-hidden="true"></span>
							<?php echo esc_html( $safespot_entry['name'] ); ?>
						<?php else : ?>
							<span class="safespot-muted">—</span>
						<?php endif; ?>
					</td>
					<td data-colname="<?php esc_attr_e( 'Details', 'tbsh-safespot-restore-points' ); ?>"><?php echo esc_html( $safespot_entry['message'] ); ?></td>
					<td data-colname="<?php esc_attr_e( 'User', 'tbsh-safespot-restore-points' ); ?>"><?php echo esc_html( $safespot_entry['user'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $safespot_result['pages'] > 1 ) : ?>
		<nav class="tablenav bottom" aria-label="<?php esc_attr_e( 'Log pages', 'tbsh-safespot-restore-points' ); ?>">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					/* translators: %s: number of entries. */
					echo esc_html( sprintf( _n( '%s entry', '%s entries', $safespot_result['total'], 'tbsh-safespot-restore-points' ), number_format_i18n( $safespot_result['total'] ) ) );
					?>
				</span>
				<span class="pagination-links">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $safespot_filters['page'],
								'total'     => $safespot_result['pages'],
								'prev_text' => '&lsaquo;',
								'next_text' => '&rsaquo;',
							)
						)
					);
					?>
				</span>
			</div>
		</nav>
	<?php endif; ?>
<?php endif; ?>
