<?php
/**
 * Plugin notes & icons : colonne icône + colonne note sur l'écran Extensions.
 * Feature toggleable depuis le Lab.
 *
 * @package WP_PK_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class PKWT_Notes {

	const DB_VERSION_KEY = 'pkwt_notes_db_version';
	const FEATURE_KEY    = 'pkwt_plugin_notes_enabled';

	/** @var PKWT_Notes|null */
	private static ?PKWT_Notes $instance = null;

	/** @var array|null Cached icon lookup from update_plugins transient. */
	private static ?array $icon_cache = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/* ---------------------------------------------------------------------
	 * ACTIVATION
	 * ------------------------------------------------------------------- */

	public static function activate(): void {
		self::create_table();
		if ( get_option( self::FEATURE_KEY ) === false ) {
			update_option( self::FEATURE_KEY, '1' );
		}
	}

	private static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'pkwt_plugin_notes';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			plugin_slug varchar(255) NOT NULL,
			note longtext NOT NULL DEFAULT '',
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (plugin_slug)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, PKWT_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * FEATURE TOGGLE
	 * ------------------------------------------------------------------- */

	public static function is_enabled(): bool {
		return get_option( self::FEATURE_KEY, '1' ) === '1';
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::FEATURE_KEY, $enabled ? '1' : '0' );
	}

	/* ---------------------------------------------------------------------
	 * INIT — ne wire les hooks que si la feature est activée
	 * ------------------------------------------------------------------- */

	public function init(): void {
		// Ensure the DB table exists even if activation hook never ran
		// (e.g. plugin was pushed via REST/FTP without deactivate/reactivate).
		if ( get_option( self::DB_VERSION_KEY ) !== PKWT_VERSION ) {
			self::create_table();
		}

		// AJAX endpoint is ALWAYS registered — notes might exist even if
		// the column display is toggled off.
		add_action( 'wp_ajax_pkwt_save_note', [ $this, 'ajax_save_note' ] );
		add_action( 'admin_init', [ $this, 'save_note_form' ] );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'manage_plugins_columns', [ $this, 'add_columns' ] );
		add_action( 'manage_plugins_custom_column', [ $this, 'render_columns' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * COLUMNS
	 * ------------------------------------------------------------------- */

	public function add_columns( array $cols ): array {
		// Insérer l'icône en toute première position.
		$new = [];
		$inserted = false;
		foreach ( $cols as $key => $label ) {
			if ( ! $inserted && 'cb' !== $key ) {
				$new['pkwt_icon'] = '';
				$inserted = true;
			}
			$new[ $key ] = $label;
		}
		if ( ! $inserted ) {
			$new['pkwt_icon'] = '';
		}
		$new['pkwt_note'] = __( 'Note', 'pk-wordpress-tools' );
		return $new;
	}

	public function render_columns( string $col, string $file ): void {
		if ( 'pkwt_icon' === $col ) {
			$this->render_icon_cell( $file );
		}
		if ( 'pkwt_note' === $col ) {
			$this->render_note_cell( $file );
		}
	}

	private function render_icon_cell( string $file ): void {
		$url = $this->get_plugin_icon_url( $file );
		$all = get_plugins();
		$name = (string) ( $all[ $file ]['Name'] ?? '' );
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		?>
		<div class="pkwt-plugin-icon-cell">
			<?php if ( $url ) : ?>
				<img src="<?php echo esc_url( $url ); ?>" alt="" class="pkwt-plugin-thumb" />
			<?php else : ?>
				<span class="pkwt-plugin-thumb-fallback" aria-hidden="true"><?php echo esc_html( $initial ?: '?' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_note_cell( string $file ): void {
		$note     = $this->get_note( $file );
		?>
		<form method="post" class="pkwt-plugin-note">
			<?php wp_nonce_field( 'pkwt-save-plugin-note' ); ?>
			<input type="hidden" name="pkwt_note_action" value="save" />
			<input type="hidden" name="pkwt_note_plugin" value="<?php echo esc_attr( $file ); ?>" />
			<textarea class="pkwt-plugin-note__text" name="pkwt_note" rows="2" placeholder="<?php esc_attr_e( 'Ajouter une note...', 'pk-wordpress-tools' ); ?>"><?php echo esc_textarea( $note ); ?></textarea>
			<button type="submit" class="button button-small"><?php esc_html_e( 'Enregistrer', 'pk-wordpress-tools' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Save one note through a normal admin form POST. This is deliberately not
	 * AJAX: hosts/security layers often block admin-ajax requests.
	 */
	public function save_note_form(): void {
		if ( ! isset( $_POST['pkwt_note_action'] ) || 'save' !== $_POST['pkwt_note_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'pk-wordpress-tools' ) );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pkwt-save-plugin-note' ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}

		$plugin = isset( $_POST['pkwt_note_plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['pkwt_note_plugin'] ) ) : '';
		$note = isset( $_POST['pkwt_note'] ) ? wp_unslash( $_POST['pkwt_note'] ) : '';
		if ( '' !== $plugin ) {
			$this->save_note( $plugin, (string) $note );
		}

		wp_safe_redirect( admin_url( 'plugins.php?pkwt_note_saved=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * ICON RESOLUTION
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the icon URL for a plugin file slug.
	 * Checks: plugin data icons → WP.org transient → empty (dashicon fallback).
	 */
	private function get_plugin_icon_url( string $file ): string {
		// 1) Plugin data icons (our own plugin injects these via all_plugins filter).
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all = get_plugins();
		$data = $all[ $file ] ?? [];

		if ( ! empty( $data['icons']['svg'] ) ) return $data['icons']['svg'];
		if ( ! empty( $data['icons']['2x'] ) ) return $data['icons']['2x'];
		if ( ! empty( $data['icons']['1x'] ) ) return $data['icons']['1x'];
		if ( ! empty( $data['icons']['default'] ) ) return $data['icons']['default'];

		// 2) WP.org update_plugins transient.
		$this->load_icon_cache();
		if ( isset( self::$icon_cache[ $file ] ) ) {
			return self::$icon_cache[ $file ];
		}

		return '';
	}

	/**
	 * Populate the icon cache from the update_plugins site transient.
	 */
	private function load_icon_cache(): void {
		if ( self::$icon_cache !== null ) {
			return;
		}
		self::$icon_cache = [];

		$updates = get_site_transient( 'update_plugins' );
		if ( ! $updates ) {
			return;
		}

		foreach ( [ 'response', 'no_update' ] as $bucket ) {
			if ( ! isset( $updates->$bucket ) ) {
				continue;
			}
			foreach ( (array) $updates->$bucket as $plugin_file => $info ) {
				if ( empty( $info->icons ) ) {
					continue;
				}
				$icons = (array) $info->icons;
				$url = $icons['svg'] ?? $icons['2x'] ?? $icons['1x'] ?? $icons['default'] ?? '';
				if ( $url ) {
					self::$icon_cache[ $plugin_file ] = $url;
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * STORE
	 * ------------------------------------------------------------------- */

	public function get_note( string $plugin_file ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_plugin_notes';
		$row   = $wpdb->get_var( $wpdb->prepare(
			"SELECT note FROM {$table} WHERE plugin_slug = %s",
			$plugin_file
		) );
		return (string) ( $row ?? '' );
	}

	public function save_note( string $plugin_file, string $note ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_plugin_notes';
		$note  = trim( strip_tags( wp_unslash( $note ), '<a><strong><em><code><br>' ) );

		if ( '' === $note ) {
			return false !== $wpdb->delete( $table, [ 'plugin_slug' => $plugin_file ], [ '%s' ] );
		}

		return false !== $wpdb->replace(
			$table,
			[ 'plugin_slug' => $plugin_file, 'note' => $note ],
			[ '%s', '%s' ]
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	public function ajax_save_note(): void {
		check_ajax_referer( 'pkwt_note_save', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'pk-wordpress-tools' ) ], 403 );
		}

		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$note   = isset( $_POST['note'] )   ? wp_unslash( $_POST['note'] )  : '';

		if ( '' === $plugin ) {
			wp_send_json_error( [ 'message' => __( 'Slug manquant.', 'pk-wordpress-tools' ) ] );
		}

		$ok = $this->save_note( $plugin, $note );
		wp_send_json_success( [ 'saved' => (bool) $ok ] );
	}
}
