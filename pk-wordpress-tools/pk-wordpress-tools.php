<?php
/**
 * Plugin Name:       PK WordPress Tools
 * Description:       Boîte à outils WP : calendrier éditorial, outils de contenus, lecteur d'articles, Lab, extensions et export.
 * Version:           1.2026.16
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            PK
 * License:           GPL-2.0-or-later
 * Text Domain:       pk-wordpress-tools
 */

defined( 'ABSPATH' ) || exit;

define( 'PKWT_VERSION', '1.2026.16' );
define( 'PKWT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PKWT_URL', plugin_dir_url( __FILE__ ) );
define( 'PKWT_FILE', __FILE__ );

// REST/FTP deployments may run with timestamp validation disabled in OPcache.
// Invalidate this plugin's PHP files once per released version before loading them.
if ( function_exists( 'opcache_invalidate' ) && get_option( 'pkwt_opcache_version' ) !== PKWT_VERSION ) {
	foreach ( glob( PKWT_DIR . 'includes/*.php' ) ?: [] as $pkwt_file ) {
		@opcache_invalidate( $pkwt_file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@opcache_invalidate( __FILE__, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	update_option( 'pkwt_opcache_version', PKWT_VERSION );
}

require_once PKWT_DIR . 'includes/class-pkwt-snippets.php';
require_once PKWT_DIR . 'includes/class-pkwt-native-features.php';
require_once PKWT_DIR . 'includes/class-pkwt-lab.php';
require_once PKWT_DIR . 'includes/class-pkwt-notes.php';
require_once PKWT_DIR . 'includes/class-pkwt-calendar.php';
require_once PKWT_DIR . 'includes/class-pkwt-content-tools.php';
require_once PKWT_DIR . 'includes/class-pkwt-reader.php';
require_once PKWT_DIR . 'includes/class-pkwt-admin.php';
require_once PKWT_DIR . 'includes/class-pkwt-sync.php';

register_activation_hook( __FILE__, [ 'PKWT_Snippets', 'activate' ] );
register_activation_hook( __FILE__, [ 'PKWT_Notes', 'activate' ] );

add_action( 'plugins_loaded', function (): void {
	PKWT_Snippets::maybe_upgrade_schema();
	// Disable the broken scheduled-posts snippet once, wherever it was saved.
	if ( ! get_option( 'pkwt_disabled_missed_scheduled_posts_snippet' ) ) {
		global $wpdb;
		$marker = '%pkwt_missed_scheduled_posts_lock%';
		foreach ( [ $wpdb->prefix . 'pkwt_snippets', $wpdb->prefix . 'snippets' ] as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET active = 0 WHERE code LIKE %s", $marker ) );
			}
		}
		update_option( 'pkwt_disabled_missed_scheduled_posts_snippet', '1' );
	}

	PKWT_Snippets::instance()->init();
	PKWT_Lab::instance()->init();

	// Inject our icon into the plugins list & replace the row icon (newsletter pattern).
	PKWT_Admin::register_plugin_icon();

	// REST sync endpoint — always available.
	PKWT_Sync::instance()->init();

	// Notes: table creation + AJAX action must run everywhere (admin + AJAX).
	PKWT_Notes::instance()->init();
	PKWT_Calendar::instance()->init();
	PKWT_Content_Tools::instance()->init();

	if ( is_admin() ) {
		PKWT_Admin::instance()->init();
	}
} );
