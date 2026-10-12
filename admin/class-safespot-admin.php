<?php
/**
 * Admin screens and integrations.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the SafeSpot menu, assets, native screen integrations and the download handler.
 */
class SafeSpot_Admin {

	/**
	 * Restore service.
	 *
	 * @var SafeSpot_Restore
	 */
	private $restore;

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Constructor.
	 *
	 * @param SafeSpot_Restore $restore Restore service.
	 */
	public function __construct( SafeSpot_Restore $restore ) {
		$this->restore = $restore;
	}

	/**
	 * Registers hooks.
	 */
	public function register() {
		$menu_hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
		add_action( $menu_hook, array( $this, 'menu' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_safespot_download', array( $this, 'download' ) );

		add_filter( is_multisite() ? 'network_admin_plugin_action_links' : 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_filter( 'theme_action_links', array( $this, 'theme_action_links' ), 10, 2 );
		add_filter( is_multisite() ? 'network_admin_plugin_action_links_' . SAFESPOT_BASENAME : 'plugin_action_links_' . SAFESPOT_BASENAME, array( $this, 'own_action_links' ) );

		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( $this, 'recovery_notice' ) );
		add_action( 'admin_notices', array( $this, 'network_activation_notice' ) );
	}

	/**
	 * Adds the top-level menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page(
			__( 'SafeSpot – Plugin Restore Points', 'tbsh-safespot-restore-points' ),
			__( 'SafeSpot', 'tbsh-safespot-restore-points' ),
			SafeSpot_Permissions::menu_capability(),
			'safespot',
			array( $this, 'render' ),
			'dashicons-shield-alt',
			66
		);
	}

	/**
	 * Available tabs for the current user.
	 *
	 * @return array
	 */
	private function tabs() {
		$tabs = array( 'dashboard' => __( 'Dashboard', 'tbsh-safespot-restore-points' ) );
		if ( SafeSpot_Permissions::can( 'view', 'plugin' ) ) {
			$tabs['plugins'] = __( 'Plugins', 'tbsh-safespot-restore-points' );
		}
		if ( SafeSpot_Permissions::can( 'view', 'theme' ) ) {
			$tabs['themes'] = __( 'Themes', 'tbsh-safespot-restore-points' );
		}
		$tabs['log'] = __( 'Activity Log', 'tbsh-safespot-restore-points' );
		if ( SafeSpot_Permissions::can( 'settings' ) ) {
			$tabs['settings'] = __( 'Settings', 'tbsh-safespot-restore-points' );
		}
		return $tabs;
	}

	/**
	 * Renders the admin page.
	 */
	public function render() {
		if ( ! SafeSpot_Permissions::can( 'view' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'tbsh-safespot-restore-points' ), 403 );
		}

		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}

		SafeSpot_Admin_Views::template(
			'page',
			array(
				'tab'  => $tab,
				'tabs' => $tabs,
			)
		);
	}

	/**
	 * Enqueues assets on the SafeSpot page and the native Plugins/Themes screens.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public function enqueue( $hook_suffix ) {
		$version = SAFESPOT_VERSION;
		$is_page = '' !== $this->hook && $hook_suffix === $this->hook;
		$native  = in_array( $hook_suffix, array( 'plugins.php', 'themes.php' ), true );

		if ( ! $is_page && ! $native && ! $this->needs_recovery_notice() ) {
			return;
		}

		wp_enqueue_style( 'safespot-admin', SAFESPOT_URL . 'admin/assets/css/admin.css', array(), $version );

		if ( $is_page ) {
			wp_enqueue_script( 'safespot-admin', SAFESPOT_URL . 'admin/assets/js/admin.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), $version, true );
			wp_set_script_translations( 'safespot-admin', 'tbsh-safespot-restore-points', SAFESPOT_DIR . 'languages' );
			wp_localize_script(
				'safespot-admin',
				'safespotAdmin',
				array(
					'settingsSnapshot' => (bool) SafeSpot_Settings::get( 'settings_snapshot' ),
					'pageUrl'          => SafeSpot_Admin_Views::page_url(),
					'canSettings'      => SafeSpot_Permissions::can( 'settings' ),
					'jobs'             => safespot()->jobs()->status(),
				)
			);
			return;
		}

		wp_enqueue_script( 'safespot-integrations', SAFESPOT_URL . 'admin/assets/js/integrations.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), $version, true );
		wp_set_script_translations( 'safespot-integrations', 'tbsh-safespot-restore-points', SAFESPOT_DIR . 'languages' );

		$themes = array();
		if ( 'themes.php' === $hook_suffix && ! is_network_admin() && SafeSpot_Permissions::can( 'save', 'theme' ) ) {
			$summary = SafeSpot_Points::summary( 'theme' );
			foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
				$themes[ $stylesheet ] = array(
					'count' => isset( $summary[ $stylesheet ] ) ? $summary[ $stylesheet ]['count'] : 0,
					'url'   => SafeSpot_Admin_Views::page_url(
						array(
							'tab'  => 'themes',
							'item' => $stylesheet,
						)
					),
				);
			}
		}

		wp_localize_script(
			'safespot-integrations',
			'safespotIntegrations',
			array(
				'themes'  => $themes,
				'pageUrl' => SafeSpot_Admin_Views::page_url(),
			)
		);
	}

	/**
	 * Adds "Save SafeSpot" and "Restore" row actions on the Plugins screen.
	 *
	 * @param array  $actions     Actions.
	 * @param string $plugin_file Plugin file.
	 * @return array
	 */
	public function plugin_action_links( $actions, $plugin_file ) {
		if ( ! SafeSpot_Permissions::can( 'save', 'plugin' ) || ! is_array( $actions ) ) {
			return $actions;
		}
		static $summary = null;
		if ( null === $summary ) {
			$summary = SafeSpot_Points::summary( 'plugin' );
		}

		$plugins = get_plugins();
		$name    = isset( $plugins[ $plugin_file ]['Name'] ) ? wp_strip_all_tags( $plugins[ $plugin_file ]['Name'] ) : $plugin_file;

		$actions['safespot_save'] = sprintf(
			'<a href="#" class="safespot-quick-save" data-type="plugin" data-slug="%1$s" data-name="%2$s" aria-label="%3$s">%4$s</a>',
			esc_attr( $plugin_file ),
			esc_attr( $name ),
			/* translators: %s: plugin name. */
			esc_attr( sprintf( __( 'Save a SafeSpot of %s', 'tbsh-safespot-restore-points' ), $name ) ),
			esc_html__( 'Save SafeSpot', 'tbsh-safespot-restore-points' )
		);

		if ( ! empty( $summary[ $plugin_file ] ) && SafeSpot_Permissions::can( 'restore', 'plugin' ) ) {
			$actions['safespot_restore'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url(
					SafeSpot_Admin_Views::page_url(
						array(
							'tab'  => 'plugins',
							'item' => $plugin_file,
						)
					)
				),
				/* translators: %s: plugin name. */
				esc_attr( sprintf( __( 'Restore %s from a SafeSpot', 'tbsh-safespot-restore-points' ), $name ) ),
				/* translators: %d: number of SafeSpots. */
				esc_html( sprintf( __( 'Restore (%d)', 'tbsh-safespot-restore-points' ), $summary[ $plugin_file ]['count'] ) )
			);
		}

		return $actions;
	}

	/**
	 * Shows "Updates paused by SafeSpot" under held plugins.
	 *
	 * @param array  $meta        Row meta.
	 * @param string $plugin_file Plugin file.
	 * @return array
	 */
	public function plugin_row_meta( $meta, $plugin_file ) {
		$hold = SafeSpot_Settings::get_hold( 'plugin', $plugin_file );
		if ( $hold && is_array( $meta ) ) {
			$meta[] = '<span class="safespot-held"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> ' . esc_html__( 'Updates paused by SafeSpot', 'tbsh-safespot-restore-points' ) . '</span>';
		}
		return $meta;
	}

	/**
	 * Row actions on the network Themes list table (multisite).
	 *
	 * @param array    $actions Actions.
	 * @param WP_Theme $theme   Theme.
	 * @return array
	 */
	public function theme_action_links( $actions, $theme ) {
		if ( ! is_network_admin() || ! ( $theme instanceof WP_Theme ) || ! SafeSpot_Permissions::can( 'save', 'theme' ) ) {
			return $actions;
		}
		$stylesheet = $theme->get_stylesheet();

		$actions['safespot_save']    = sprintf(
			'<a href="#" class="safespot-quick-save" data-type="theme" data-slug="%1$s" data-name="%2$s">%3$s</a>',
			esc_attr( $stylesheet ),
			esc_attr( $theme->get( 'Name' ) ),
			esc_html__( 'Save SafeSpot', 'tbsh-safespot-restore-points' )
		);
		$actions['safespot_restore'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(
				SafeSpot_Admin_Views::page_url(
					array(
						'tab'  => 'themes',
						'item' => $stylesheet,
					)
				)
			),
			esc_html__( 'SafeSpots', 'tbsh-safespot-restore-points' )
		);
		return $actions;
	}

	/**
	 * Adds a "Dashboard" link to SafeSpot's own row (SafeSpot cannot restore itself).
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function own_action_links( $actions ) {
		unset( $actions['safespot_restore'] );
		array_unshift( $actions, '<a href="' . esc_url( SafeSpot_Admin_Views::page_url() ) . '">' . esc_html__( 'Dashboard', 'tbsh-safespot-restore-points' ) . '</a>' );
		return $actions;
	}

	/**
	 * Whether the recovery/rollback notice should show.
	 *
	 * @return bool
	 */
	private function needs_recovery_notice() {
		$last = SafeSpot_Restore::last_restore();
		if ( ! $last || empty( $last['pre_point_id'] ) || ! SafeSpot_Permissions::can( 'restore', $last['type'] ) ) {
			return false;
		}

		if ( 'in_progress' === $last['status'] && time() - (int) $last['time'] > 5 * MINUTE_IN_SECONDS ) {
			return true;
		}

		if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) {
			return true;
		}

		$extension = 'plugin' === $last['type'] ? strtok( $last['slug'], '/' ) : $last['slug'];
		if ( 'plugin' === $last['type'] && function_exists( 'wp_paused_plugins' ) ) {
			return (bool) wp_paused_plugins()->get( $extension );
		}
		if ( 'theme' === $last['type'] && function_exists( 'wp_paused_themes' ) ) {
			return (bool) wp_paused_themes()->get( $extension );
		}
		return false;
	}

	/**
	 * "Roll back this restore" notice in recovery mode, when the restored item was
	 * paused by WordPress, or when a restore never finished.
	 */
	public function recovery_notice() {
		if ( ! $this->needs_recovery_notice() ) {
			return;
		}
		$last = SafeSpot_Restore::last_restore();
		?>
		<div class="notice notice-error safespot-recovery-notice">
			<p>
				<strong><?php esc_html_e( 'SafeSpot', 'tbsh-safespot-restore-points' ); ?>:</strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: item name, 2: restored version. */
						__( '%1$s was restored to version %2$s and may have caused an error.', 'tbsh-safespot-restore-points' ),
						$last['name'],
						$last['version_to']
					)
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary safespot-rollback-last" data-name="<?php echo esc_attr( $last['name'] ); ?>">
					<?php esc_html_e( 'Roll back this restore', 'tbsh-safespot-restore-points' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( SafeSpot_Admin_Views::page_url() ); ?>"><?php esc_html_e( 'Open SafeSpot', 'tbsh-safespot-restore-points' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Explains that SafeSpot must be network activated on multisite.
	 */
	public function network_activation_notice() {
		if ( ! is_multisite() || is_network_admin() || ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || is_plugin_active_for_network( SAFESPOT_BASENAME ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'SafeSpot manages files shared by the whole network, so it works from the Network Admin only. Please network activate it.', 'tbsh-safespot-restore-points' ) . '</p></div>';
	}

	/**
	 * Streams a SafeSpot archive to an authorized user.
	 */
	public function download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'safespot_download_' . $id );

		$point = SafeSpot_Points::get( $id );
		if ( ! $point ) {
			wp_die( esc_html__( 'SafeSpot not found.', 'tbsh-safespot-restore-points' ), 404 );
		}
		if ( ! SafeSpot_Permissions::can( 'save', $point['item_type'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download this SafeSpot.', 'tbsh-safespot-restore-points' ), 403 );
		}

		$path = SafeSpot_Storage::path( $point['file_name'] );
		$dir  = SafeSpot_Storage::get_dir();
		if ( is_wp_error( $path ) || is_wp_error( $dir ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The SafeSpot archive file is missing.', 'tbsh-safespot-restore-points' ), 404 );
		}

		$real_path = wp_normalize_path( (string) realpath( $path ) );
		$real_dir  = trailingslashit( wp_normalize_path( (string) realpath( $dir ) ) );
		if ( '' === $real_path || 0 !== strpos( $real_path, $real_dir ) ) {
			wp_die( esc_html__( 'Invalid SafeSpot file.', 'tbsh-safespot-restore-points' ), 400 );
		}

		SafeSpot_Logger::log(
			'download',
			'info',
			/* translators: 1: item name, 2: version. */
			sprintf( __( 'Downloaded SafeSpot of %1$s %2$s', 'tbsh-safespot-restore-points' ), $point['name'], $point['version'] ),
			array(
				'type'     => $point['item_type'],
				'slug'     => $point['slug'],
				'name'     => $point['name'],
				'point_id' => $point['id'],
			)
		);

		$pi       = $this->restore->point_item( $point );
		$filename = sanitize_file_name( $pi['folder'] . '-' . $point['version'] . '-safespot-' . gmdate( 'Ymd-His', $point['created_ts'] ) . '.zip' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $real_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a large file in chunks.
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary file download.
				flush();
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with fopen().
		}
		exit;
	}
}
