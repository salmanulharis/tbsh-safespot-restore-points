<?php
/**
 * Admin page shell.
 *
 * @package SafeSpot
 *
 * @var string $tab  Current tab.
 * @var array  $tabs Tabs (key => label).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap safespot-wrap">
	<header class="safespot-header">
		<h1 class="safespot-title">
			<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'SafeSpot', 'tbsh-safespot-restore-points' ); ?>
			<span class="safespot-title__sub"><?php esc_html_e( 'Plugin & theme restore points', 'tbsh-safespot-restore-points' ); ?></span>
		</h1>
	</header>
	<hr class="wp-header-end" />

	<nav class="nav-tab-wrapper safespot-tabs" aria-label="<?php esc_attr_e( 'SafeSpot sections', 'tbsh-safespot-restore-points' ); ?>">
		<?php foreach ( $tabs as $safespot_key => $safespot_label ) : ?>
			<a href="<?php echo esc_url( SafeSpot_Admin_Views::page_url( array( 'tab' => $safespot_key ) ) ); ?>" class="nav-tab<?php echo $tab === $safespot_key ? ' nav-tab-active' : ''; ?>" <?php echo $tab === $safespot_key ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $safespot_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div id="safespot-notices" class="safespot-notices" aria-live="polite"></div>

	<main class="safespot-main">
		<?php SafeSpot_Admin_Views::template( 'tab-' . ( in_array( $tab, array( 'plugins', 'themes' ), true ) ? 'items' : $tab ), array( 'tab' => $tab ) ); ?>
	</main>

	<dialog id="safespot-modal" class="safespot-modal" aria-labelledby="safespot-modal-title" aria-describedby="safespot-modal-body">
		<form method="dialog" class="safespot-modal__inner">
			<header class="safespot-modal__header">
				<h2 id="safespot-modal-title" class="safespot-modal__title"></h2>
				<button type="button" class="safespot-modal__close" data-safespot-close aria-label="<?php esc_attr_e( 'Close dialog', 'tbsh-safespot-restore-points' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</header>
			<div id="safespot-modal-body" class="safespot-modal__body"></div>
			<div class="safespot-modal__progress" hidden>
				<div class="safespot-progress" role="progressbar" aria-valuetext="" aria-busy="true"><span class="safespot-progress__bar"></span></div>
				<p class="safespot-modal__progress-text" aria-live="polite"></p>
			</div>
			<footer class="safespot-modal__footer">
				<button type="button" class="button" data-safespot-close><?php esc_html_e( 'Cancel', 'tbsh-safespot-restore-points' ); ?></button>
				<button type="submit" class="button button-primary safespot-modal__confirm" value="confirm"></button>
			</footer>
		</form>
	</dialog>
</div>
