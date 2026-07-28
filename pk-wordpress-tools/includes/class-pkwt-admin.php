<?php
/**
 * Admin : topbar + 5 pages (Snippets, Lab, Manager, Import, About).
 * Design system « WP PK » (palette verte #00b65e, panels radius 18-28).
 *
 * @package WP_PK_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI controller matching the WP PK family look & feel.
 */
class PKWT_Admin {

	/** @var PKWT_Admin|null */
	private static ?PKWT_Admin $instance = null;

	const SNAP_SLUG  = 'pkwt-snippets';
	const LAB_SLUG   = 'pkwt-lab';
	const MGR_SLUG   = 'pkwt-manager';
	const IMP_SLUG   = 'pkwt-import';
	const ABOUT_SLUG = 'pkwt-about';
	const PERSONAL_LIBRARY_OPTION = 'pkwt_personal_snippet_library';
	const ADMIN_THEME_OPTION = 'pkwt_admin_theme';

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Wire admin hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'save_admin_theme' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_plugins_screen_assets' ], 20 );
		add_filter( 'admin_body_class', [ $this, 'add_admin_theme_body_class' ] );
		add_action( 'wp_ajax_pkwt_toggle_feature', [ $this, 'ajax_toggle_feature' ] );
		add_action( 'wp_ajax_pkwt_toggle_preset', [ $this, 'ajax_toggle_preset' ] );
		add_action( 'wp_ajax_pkwt_toggle_snippet', [ $this, 'ajax_toggle_snippet' ] );
	}

	/**
	 * Return the selected plugin administration theme.
	 */
	private function admin_theme(): string {
		$theme = get_option( self::ADMIN_THEME_OPTION, 'dashboard' );

		return in_array( $theme, [ 'dashboard', 'brutalist' ], true ) ? $theme : 'dashboard';
	}

	/**
	 * Add the theme class only to WP PK Tools screens.
	 */
	public function add_admin_theme_body_class( string $classes ): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, [ self::SNAP_SLUG, 'pkwt-edit', self::LAB_SLUG, self::MGR_SLUG, self::IMP_SLUG, self::ABOUT_SLUG ], true ) ) {
			return $classes;
		}

		return $classes . ' pkwt-admin-theme--' . $this->admin_theme();
	}

	/**
	 * Save the selected administration theme and redirect back to About.
	 */
	public function save_admin_theme(): void {
		$page = isset( $_POST['pkwt_theme_page'] ) ? sanitize_key( wp_unslash( $_POST['pkwt_theme_page'] ) ) : '';
		if ( self::ABOUT_SLUG !== $page ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		$nonce = isset( $_POST['pkwt_admin_theme_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pkwt_admin_theme_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pkwt-save-admin-theme' ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}

		$theme = isset( $_POST['pkwt_admin_theme'] ) ? sanitize_key( wp_unslash( $_POST['pkwt_admin_theme'] ) ) : '';
		$theme = in_array( $theme, [ 'dashboard', 'brutalist' ], true ) ? $theme : 'dashboard';
		update_option( self::ADMIN_THEME_OPTION, $theme );
		$this->add_notice( 'success', __( 'Thème de l’interface enregistré.', 'pk-wordpress-tools' ) );

		wp_safe_redirect( $this->tab_url( self::ABOUT_SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * PLUGIN ICON ON PLUGINS LIST (mirrors newsletter pattern)
	 * ------------------------------------------------------------------- */

	/**
	 * Register our icon into the plugins list — like the newsletter plugin does.
	 */
	public static function register_plugin_icon(): void {
		add_filter( 'all_plugins', static function ( array $plugins ): array {
			$basename = plugin_basename( PKWT_FILE );
			if ( ! isset( $plugins[ $basename ] ) || ! is_readable( PKWT_DIR . 'icon.png' ) ) {
				return $plugins;
			}
			$icon = plugins_url( 'icon.png', PKWT_FILE );
			$plugins[ $basename ]['icons'] = [
				'1x'     => $icon,
				'2x'     => $icon,
				'default'=> $icon,
			];
			return $plugins;
		}, 20 );

		add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
			if ( 'plugins.php' !== $hook ) {
				return;
			}
			$icon_rel   = is_readable( PKWT_DIR . 'icon.png' ) ? 'icon.png' : '';
			$basename   = plugin_basename( PKWT_FILE );
			$icon_url   = plugins_url( $icon_rel, PKWT_FILE );
			$handle     = 'pkwt-plugins-icon';
			wp_register_style( $handle, false, [], PKWT_VERSION );
			wp_enqueue_style( $handle );
			$row_sel = 'tr[data-plugin="' . esc_attr( $basename ) . '"]';
			$css = $row_sel . ' .plugin-icon{'
				. 'background-image:url("' . esc_url( $icon_url ) . '") !important;'
				. 'background-repeat:no-repeat !important;'
				. 'background-position:center !important;'
				. 'background-size:contain !important;'
				. 'color:transparent !important;}'
				. $row_sel . ' .plugin-icon img{opacity:0 !important;}'
				. $row_sel . ' .plugin-icon svg{opacity:0 !important;}';
			wp_add_inline_style( $handle, $css );
		} );
	}

	/* ---------------------------------------------------------------------
	 * MENU
	 * ------------------------------------------------------------------- */

	/**
	 * Register the admin menu with the icon.png as a dashicon fallback.
	 */
	public function register_menu(): void {
		$cap  = 'manage_options';
		$icon = is_readable( PKWT_DIR . 'icon.png' )
			? PKWT_URL . 'icon.png'
			: 'dashicons-screenoptions';

		// Parent = Snippets (so WP shows the icon.png as the menu icon).
		add_menu_page(
			__( 'WP PK Tools', 'pk-wordpress-tools' ),
			__( 'WP PK Tools', 'pk-wordpress-tools' ),
			$cap,
			self::SNAP_SLUG,
			[ $this, 'render_snippets_page' ],
			$icon,
			80
		);

		// Sub-pages.
		$tabs = [
			self::SNAP_SLUG  => [ __( 'Mes snippets', 'pk-wordpress-tools' ),  'render_snippets_page'  ],
			'pkwt-edit'      => [ __( 'Nouveau', 'pk-wordpress-tools' ),   'render_edit_page'      ],
			self::LAB_SLUG   => [ __( 'Bibliothèque', 'pk-wordpress-tools' ), 'render_lab_page'       ],
			self::IMP_SLUG   => [ __( 'Import', 'pk-wordpress-tools' ),     'render_import_page'    ],
			self::ABOUT_SLUG => [ __( 'À propos', 'pk-wordpress-tools' ),  'render_about_page'     ],
		];

		foreach ( $tabs as $slug => [ $label, $method ] ) {
			add_submenu_page(
				self::SNAP_SLUG,
				$label,
				$label,
				$cap,
				$slug,
				[ $this, $method ]
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * ASSETS
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueue admin assets — CodeMirror on edit page only.
	 */
	public function enqueue_assets( string $hook ): void {
		wp_enqueue_style(
			'pkwt-admin',
			PKWT_URL . 'assets/css/admin.css',
			[],
			PKWT_VERSION
		);
		wp_enqueue_script(
			'pkwt-admin',
			PKWT_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			PKWT_VERSION,
			true
		);
		wp_localize_script( 'pkwt-admin', 'PKWT', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pkwt_note_save' ),
			'i18n'    => [
				'confirmDelete' => __( 'Supprimer définitivement ce snippet et ses révisions ?', 'pk-wordpress-tools' ),
				'saved'         => __( 'Enregistré', 'pk-wordpress-tools' ),
				'error'         => __( 'Erreur', 'pk-wordpress-tools' ),
				'copied'        => __( 'Copié !', 'pk-wordpress-tools' ),
				'placeholder'   => __( 'Ajouter une note...', 'pk-wordpress-tools' ),
			],
		] );
		wp_localize_script( 'pkwt-admin', 'PKWT_i18n', [
			'saved'       => __( 'Enregistré', 'pk-wordpress-tools' ),
			'error'       => __( 'Erreur', 'pk-wordpress-tools' ),
			'copied'      => __( 'Copié !', 'pk-wordpress-tools' ),
			'placeholder' => __( 'Ajouter une note...', 'pk-wordpress-tools' ),
			'featureOn'   => __( 'Fonction activée.', 'pk-wordpress-tools' ),
			'featureOff'  => __( 'Fonction désactivée.', 'pk-wordpress-tools' ),
		] );

		// CodeMirror on edit screen only.
		$is_edit = isset( $_GET['page'] ) && 'pkwt-edit' === $_GET['page'];
		if ( $is_edit ) {
			$settings = wp_enqueue_code_editor( [
				'type'       => 'php',
				'codemirror' => [
					'indentUnit'        => 4,
					'tabSize'           => 4,
					'lineNumbers'       => true,
					'autoCloseBrackets' => true,
					'matchBrackets'     => true,
					'styleActiveLine'   => true,
				],
			] );
			wp_enqueue_script( 'wp-code-editor' );
			wp_enqueue_style( 'wp-codemirror' );

			// Localize editor settings.
			add_action( 'admin_print_footer_scripts', function () use ( $settings ) {
				echo '<script>window.pkwtCodeEditorSettings = ' . wp_json_encode( $settings ) . ';</script>';
				?>
				<script>
				(function () {
					if (!window.wp || !wp.codeEditor) { return; }
					var ta = document.getElementById('pkwt-code');
					if (!ta) { return; }
					wp.codeEditor.initialize('pkwt-code', window.pkwtCodeEditorSettings || {});
				})();
				</script>
				<?php
			}, 99 );
		}
	}

	/**
	 * AJAX: toggle a built-in feature on/off.
	 */
	public function ajax_toggle_feature(): void {
		check_ajax_referer( 'pkwt_note_save', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'pk-wordpress-tools' ) ], 403 );
		}

		$feature = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( $_POST['feature'] ) ) : '';
		$enabled_value = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '';
		$enabled       = 'false' !== $enabled_value && '0' !== $enabled_value && '' !== $enabled_value;

		if ( 'plugin_notes' === $feature ) {
			PKWT_Notes::set_enabled( $enabled );
		} elseif ( ! PKWT_Native_Features::set_enabled( $feature, $enabled ) ) {
			wp_send_json_error( [ 'message' => __( 'Feature inconnue.', 'pk-wordpress-tools' ) ] );
		}

		wp_send_json_success( [ 'feature' => $feature, 'enabled' => $enabled ] );
	}

	/**
	 * AJAX: toggle a Lab preset on/off.
	 */
	public function ajax_toggle_preset(): void {
		check_ajax_referer( 'pkwt_note_save', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'pk-wordpress-tools' ) ], 403 );
		}

		$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) ? (bool) $_POST['enabled'] : false;

		$presets = PKWT_Lab::instance()->get_presets();
		if ( ! isset( $presets[ $slug ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Preset inconnu.', 'pk-wordpress-tools' ) ] );
		}

		PKWT_Lab::set_active( $slug, $enabled );
		wp_send_json_success( [ 'slug' => $slug, 'enabled' => $enabled ] );
	}

	/**
	 * AJAX: toggle a custom snippet on/off.
	 */
	public function ajax_toggle_snippet(): void {
		check_ajax_referer( 'pkwt_note_save', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'pk-wordpress-tools' ) ], 403 );
		}

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$enabled = ! empty( $_POST['enabled'] );
		$snippet = PKWT_Snippets::instance()->get_snippet( $id );
		if ( ! $snippet ) {
			wp_send_json_error( [ 'message' => __( 'Snippet inconnu.', 'pk-wordpress-tools' ) ], 404 );
		}

		$source_feature = PKWT_Snippets::instance()->imported_code_snippets_source_feature( $id );
		if ( $enabled && $source_feature ) {
			PKWT_Native_Features::set_enabled( $source_feature, false );
		}

		PKWT_Snippets::instance()->toggle_snippet( $id, $enabled );
		wp_send_json_success( [
			'id'              => $id,
			'enabled'         => $enabled,
			'native_disabled' => $enabled && (bool) $source_feature,
		] );
	}

	/**
	 * On the plugins.php screen, enqueue the notes-inline-edit assets.
	 */
	public function maybe_enqueue_plugins_screen_assets( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pkwt-admin', PKWT_URL . 'assets/css/admin.css', [], PKWT_VERSION );
		wp_enqueue_script( 'pkwt-plugin-notes', PKWT_URL . 'assets/js/plugin-notes.js', [ 'jquery' ], PKWT_VERSION, true );
		wp_localize_script( 'pkwt-plugin-notes', 'PKWT', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pkwt_note_save' ),
		] );
		wp_localize_script( 'pkwt-plugin-notes', 'PKWT_i18n', [
			'saved'       => __( 'Enregistré', 'pk-wordpress-tools' ),
			'error'       => __( 'Erreur', 'pk-wordpress-tools' ),
			'placeholder' => __( 'Ajouter une note...', 'pk-wordpress-tools' ),
		] );
	}

	/* ---------------------------------------------------------------------
	 * SHARED LAYOUT — topbar + page shell
	 * ------------------------------------------------------------------- */

	/**
	 * Build the URL for a tab.
	 */
	private function tab_url( string $slug ): string {
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Render the shared page header.
	 *
	 * @param string $active The active tab slug.
	 * @param string $title  Page title.
	 * @param string $copy   Optional page description.
	 */
	private function render_shell_header( string $active, string $title, string $copy = '' ): void {
		$tabs = [
			self::SNAP_SLUG  => __( 'Mes snippets', 'pk-wordpress-tools' ),
			self::LAB_SLUG   => __( 'Bibliothèque', 'pk-wordpress-tools' ),
			self::IMP_SLUG   => __( 'Import / Export', 'pk-wordpress-tools' ),
			self::ABOUT_SLUG => __( 'À propos', 'pk-wordpress-tools' ),
		];
		$section = [
			self::SNAP_SLUG => __( 'Code personnalisé', 'pk-wordpress-tools' ),
			self::LAB_SLUG  => __( 'Bibliothèque de modèles', 'pk-wordpress-tools' ),
			self::IMP_SLUG  => __( 'Sauvegarde', 'pk-wordpress-tools' ),
			self::ABOUT_SLUG => __( 'WP PK Tools', 'pk-wordpress-tools' ),
			'pkwt-edit'     => __( 'Code personnalisé', 'pk-wordpress-tools' ),
		];
		?>
		<div class="pkwt-topbar">
			<div class="pkwt-topbar__intro" aria-labelledby="pkwt-page-title">
				<p class="pkwt-eyebrow">WP PK Tools / <?php echo esc_html( $section[ $active ] ?? __( 'Outils', 'pk-wordpress-tools' ) ); ?> <span class="pkwt-version-pill">v<?php echo esc_html( PKWT_VERSION ); ?></span></p>
				<h1 id="pkwt-page-title" class="pkwt-admin-title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( $copy ) : ?>
					<p class="pkwt-admin-copy"><?php echo esc_html( $copy ); ?></p>
				<?php endif; ?>
			</div>
			<nav class="pkwt-topbar__nav" aria-label="<?php esc_attr_e( 'Navigation WP PK Tools', 'pk-wordpress-tools' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) :
					$is_active = $active === $slug || ( 'pkwt-edit' === $active && self::SNAP_SLUG === $slug );
					?>
					<a href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>" class="pkwt-topbar__link <?php echo $is_active ? 'is-active' : ''; ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Open the page shell matching the newsletter pattern.
	 */
	private function shell_open( string $active, string $title, string $copy = '' ): void {
		echo '<div class="pkwt-admin-shell pkwt-admin-theme--' . esc_attr( $this->admin_theme() ) . '"><div class="pkwt-page-stack">';
		$this->render_shell_header( $active, $title, $copy );
	}

	/**
	 * Close the page shell.
	 */
	private function shell_close(): void {
		echo '</div></div>';
	}

	/**
	 * Render pending notices.
	 */
	private function notices(): void {
		$transient = 'pkwt_notice_' . get_current_user_id();
		$notices   = get_transient( $transient );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $transient );
		foreach ( $notices as $n ) {
			[ $type, $msg ] = $n;
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), wp_kses_post( $msg ) );
		}
	}

	/**
	 * Register a notice for the next render.
	 */
	private function add_notice( string $type, string $msg ): void {
		$transient = 'pkwt_notice_' . get_current_user_id();
		$existing  = get_transient( $transient );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}
		$existing[] = [ $type, $msg ];
		set_transient( $transient, $existing, 120 );
	}

	/**
	 * Send the existing snippet export as a backup download.
	 */
	private function maybe_download_snippets_export(): void {
		if ( ! isset( $_GET['action'] ) || 'export' !== sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pkwt-export' ) ) {
			wp_die( esc_html__( 'Lien de sauvegarde expiré ou invalide.', 'pk-wordpress-tools' ) );
		}

		$json = PKWT_Snippets::instance()->export_json();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pkwt-snippets-' . gmdate( 'Y-m-d-His' ) . '.json"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Return valid personal-library IDs and remove stale option values.
	 *
	 * @param array|null $snippets Existing snippet rows, when already available.
	 * @return int[]
	 */
	private function personal_library_ids( ?array $snippets = null ): array {
		$stored = get_option( self::PERSONAL_LIBRARY_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $stored ) ) ) );
		$snippets = $snippets ?? PKWT_Snippets::instance()->get_all_snippets( true );
		$valid_ids = array_map( static fn( object $snippet ): int => (int) $snippet->id, $snippets );
		$ids       = array_values( array_intersect( $ids, $valid_ids ) );

		if ( $stored !== $ids ) {
			update_option( self::PERSONAL_LIBRARY_OPTION, $ids );
		}

		return $ids;
	}

	/**
	 * Persist a normalized personal-library ID list.
	 *
	 * @param int[] $ids Snippet IDs.
	 */
	private function update_personal_library_ids( array $ids ): void {
		update_option( self::PERSONAL_LIBRARY_OPTION, array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) );
	}

	/* ---------------------------------------------------------------------
	 * PAGE: SNIPPETS (list)
	 * ------------------------------------------------------------------- */

	/**
	 * Snippets list page.
	 */
	public function render_snippets_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'pk-wordpress-tools' ) );
		}

		$this->maybe_download_snippets_export();
		$this->dispatch_snippet_actions();
		$this->dispatch_backup_actions();

		$status             = isset( $_GET['snippet_status'] ) ? sanitize_key( wp_unslash( $_GET['snippet_status'] ) ) : 'all';
		$status             = in_array( $status, [ 'all', 'active', 'inactive', 'trash', 'revisions' ], true ) ? $status : 'all';
		$snippets           = PKWT_Snippets::instance()->get_snippets_by_status( $status );
		$counts             = PKWT_Snippets::instance()->get_snippet_counts();
		$personal_library   = $this->personal_library_ids();
		$personal_snippets  = 'trash' === $status ? [] : array_values( array_filter( $snippets, static fn( object $snippet ): bool => in_array( (int) $snippet->id, $personal_library, true ) ) );
		$regular_snippets   = 'trash' === $status ? $snippets : array_values( array_filter( $snippets, static fn( object $snippet ): bool => ! in_array( (int) $snippet->id, $personal_library, true ) ) );
		$backup_url         = wp_nonce_url( admin_url( 'admin.php?page=pkwt-snippets&action=export' ), 'pkwt-export' );
		$backups            = PKWT_Snippets::instance()->get_recent_backups();
		$this->shell_open(
			self::SNAP_SLUG,
			__( 'Mes snippets', 'pk-wordpress-tools' ),
			__( 'Vos morceaux de code PHP enregistrés. Un snippet actif est chargé sur le front et dans l’administration.', 'pk-wordpress-tools' )
		);
		$this->notices();
		?>
		<div class="pkwt-toolbar pkwt-toolbar--snippets">
			<div class="pkwt-toolbar__group">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pkwt-edit' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Ajouter un snippet', 'pk-wordpress-tools' ); ?></a>
				<form method="post" class="pkwt-backup-create-form">
					<?php wp_nonce_field( 'pkwt-create-backup' ); ?>
					<input type="hidden" name="pkwt_backup_action" value="create" />
					<label class="screen-reader-text" for="pkwt-backup-label"><?php esc_html_e( 'Libellé de la sauvegarde', 'pk-wordpress-tools' ); ?></label>
					<input type="text" id="pkwt-backup-label" name="pkwt_backup_label" maxlength="191" placeholder="<?php esc_attr_e( 'Libellé facultatif', 'pk-wordpress-tools' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Sauvegarder sur le serveur', 'pk-wordpress-tools' ); ?></button>
				</form>
				<a href="<?php echo esc_url( $backup_url ); ?>" class="button"><?php esc_html_e( 'Télécharger JSON', 'pk-wordpress-tools' ); ?></a>
			</div>
			<p class="pkwt-toolbar__note"><strong><?php echo esc_html( (string) $counts['all'] ); ?></strong> <?php esc_html_e( 'snippet(s) enregistré(s)', 'pk-wordpress-tools' ); ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=pkwt-lab' ) ); ?>"><?php esc_html_e( 'Ajouter depuis la bibliothèque', 'pk-wordpress-tools' ); ?></a></p>
		</div>
		<nav class="pkwt-snippet-tabs" aria-label="<?php esc_attr_e( 'Filtres des snippets', 'pk-wordpress-tools' ); ?>">
			<?php foreach ( [ 'all' => __( 'Tous', 'pk-wordpress-tools' ), 'active' => __( 'Actifs', 'pk-wordpress-tools' ), 'inactive' => __( 'Inactifs', 'pk-wordpress-tools' ), 'trash' => __( 'Corbeille', 'pk-wordpress-tools' ), 'revisions' => __( 'Révisions', 'pk-wordpress-tools' ) ] as $tab => $label ) : ?>
				<a class="<?php echo $status === $tab ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'snippet_status', $tab, admin_url( 'admin.php?page=pkwt-snippets' ) ) ); ?>"><?php echo esc_html( $label ); ?> <span><?php echo esc_html( (string) $counts[ $tab ] ); ?></span></a>
			<?php endforeach; ?>
		</nav>

		<div class="pkwt-panel pkwt-snippet-backups">
			<h2 class="pkwt-panel__title"><?php esc_html_e( 'Sauvegardes serveur', 'pk-wordpress-tools' ); ?></h2>
			<?php if ( empty( $backups ) ) : ?>
				<p class="pkwt-panel__copy"><?php esc_html_e( 'Aucune sauvegarde serveur pour le moment.', 'pk-wordpress-tools' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'pk-wordpress-tools' ); ?></th>
							<th><?php esc_html_e( 'Libellé', 'pk-wordpress-tools' ); ?></th>
							<th><?php esc_html_e( 'Snippets', 'pk-wordpress-tools' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'pk-wordpress-tools' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $backup ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $backup->created_at ) ); ?></td>
								<td><?php echo esc_html( $backup->label ?: __( 'Sans libellé', 'pk-wordpress-tools' ) ); ?></td>
								<td><?php echo esc_html( (string) absint( $backup->snippet_count ) ); ?></td>
								<td>
									<form method="post" class="pkwt-backup-action-form">
										<?php wp_nonce_field( 'pkwt-restore-backup-' . (int) $backup->id ); ?>
										<input type="hidden" name="pkwt_backup_action" value="restore" />
										<input type="hidden" name="pkwt_backup_id" value="<?php echo esc_attr( (string) absint( $backup->id ) ); ?>" />
										<label><input type="checkbox" name="pkwt_backup_confirm" value="1" /> <?php esc_html_e( 'Je confirme le remplacement', 'pk-wordpress-tools' ); ?></label>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Restaurer', 'pk-wordpress-tools' ); ?></button>
									</form>
									<form method="post" class="pkwt-backup-action-form">
										<?php wp_nonce_field( 'pkwt-delete-backup-' . (int) $backup->id ); ?>
										<input type="hidden" name="pkwt_backup_action" value="delete" />
										<input type="hidden" name="pkwt_backup_id" value="<?php echo esc_attr( (string) absint( $backup->id ) ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Supprimer', 'pk-wordpress-tools' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( 'trash' !== $status && ! empty( $personal_snippets ) ) : ?>
		<div class="pkwt-snippets-section">
			<h2 class="pkwt-lab-cat"><?php esc_html_e( 'Bibliothèque personnelle', 'pk-wordpress-tools' ); ?></h2>
			<?php $this->render_snippet_cards( $personal_snippets, true ); ?>
		</div>
		<?php endif; ?>

		<div class="pkwt-snippets-section">
			<h2 class="pkwt-lab-cat"><?php echo esc_html( 'trash' === $status ? __( 'Corbeille', 'pk-wordpress-tools' ) : __( 'Mes snippets', 'pk-wordpress-tools' ) ); ?></h2>
			<?php if ( empty( $regular_snippets ) ) : ?>
				<p class="pkwt-empty"><?php esc_html_e( 'Aucun snippet pour l\'instant. Créez-en un ou installez un preset depuis le Lab.', 'pk-wordpress-tools' ); ?></p>
			<?php else : ?>
				<?php $this->render_snippet_cards( $regular_snippets, false ); ?>
			<?php endif; ?>
		</div>
		<?php
		$this->shell_close();
	}

	/**
	 * Render a compact collection of custom snippet cards.
	 *
	 * @param array $snippets Snippet rows.
	 * @param bool  $personal Whether cards belong to the personal library.
	 */
	private function render_snippet_cards( array $snippets, bool $personal, bool $return_to_library = false ): void {
		?>
		<div class="pkwt-lab-list pkwt-snippet-list">
		<?php foreach ( $snippets as $snippet ) :
			$id          = (int) $snippet->id;
			$edit_url    = wp_nonce_url( admin_url( 'admin.php?page=pkwt-edit&id=' . $id ), 'pkwt-edit_' . $id );
			$is_trashed  = ! empty( $snippet->deleted_at );
			$source_feature = $personal ? PKWT_Snippets::instance()->imported_code_snippets_source_feature( $id ) : '';
			?>
			<div class="pkwt-lab-item pkwt-snippet-item <?php echo $personal ? 'pkwt-personal-snippet-item ' : ''; ?><?php echo $snippet->active ? 'is-on' : ''; ?>" data-id="<?php echo absint( $id ); ?>">
				<div class="pkwt-lab-item__main">
					<div class="pkwt-lab-item__info">
						<?php if ( $is_trashed ) : ?><strong class="pkwt-lab-item__name"><?php echo esc_html( $snippet->name ); ?></strong><?php else : ?><a href="<?php echo esc_url( $edit_url ); ?>" class="pkwt-lab-item__name"><?php echo esc_html( $snippet->name ); ?></a><?php endif; ?>
						<span class="pkwt-lab-item__desc"><?php echo esc_html( $snippet->description ?: __( 'Aucune description.', 'pk-wordpress-tools' ) ); ?></span>
						<?php if ( $source_feature ) : ?>
							<span class="pkwt-lab-item__desc"><?php esc_html_e( 'L’activation de cette source originale désactive automatiquement sa version native.', 'pk-wordpress-tools' ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( ! $is_trashed ) : ?><label class="pkwt-switch">
						<input type="checkbox" class="pkwt-snippet-toggle" data-id="<?php echo absint( $id ); ?>" <?php checked( (bool) $snippet->active ); ?> aria-label="<?php echo esc_attr( $snippet->active ? __( 'Désactiver ce snippet', 'pk-wordpress-tools' ) : __( 'Activer ce snippet', 'pk-wordpress-tools' ) ); ?>" />
						<span class="pkwt-switch__slider"></span>
					</label><?php endif; ?>
				</div>
				<div class="pkwt-lab-item__actions">
					<button type="button" class="pkwt-lab-code-toggle"><?php esc_html_e( 'Voir le code', 'pk-wordpress-tools' ); ?></button>
					<button type="button" class="button button-small pkwt-copy-btn" data-code="<?php echo esc_attr( $snippet->code ); ?>"><?php esc_html_e( 'Copier', 'pk-wordpress-tools' ); ?></button>
					<?php if ( ! $is_trashed ) : ?>
						<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Nouvelle version', 'pk-wordpress-tools' ); ?></a>
						<form method="post" class="pkwt-inline-form">
							<?php wp_nonce_field( 'pkwt-library-' . $id ); ?>
							<input type="hidden" name="pkwt_snippet_action" value="<?php echo esc_attr( $personal ? 'remove-library' : 'add-library' ); ?>" /><input type="hidden" name="pkwt_snippet_id" value="<?php echo absint( $id ); ?>" />
							<?php if ( $return_to_library ) : ?><input type="hidden" name="pkwt_return_page" value="<?php echo esc_attr( self::LAB_SLUG ); ?>" /><?php endif; ?>
							<button type="submit" class="button button-small"><?php echo esc_html( $personal ? __( 'Retirer de la bibliothèque', 'pk-wordpress-tools' ) : __( 'Ajouter à la bibliothèque', 'pk-wordpress-tools' ) ); ?></button>
						</form>
						<form method="post" class="pkwt-inline-form">
							<?php wp_nonce_field( 'pkwt-trash-' . $id ); ?>
							<input type="hidden" name="pkwt_snippet_action" value="trash" /><input type="hidden" name="pkwt_snippet_id" value="<?php echo absint( $id ); ?>" />
							<?php if ( $return_to_library ) : ?><input type="hidden" name="pkwt_return_page" value="<?php echo esc_attr( self::LAB_SLUG ); ?>" /><?php endif; ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Mettre à la corbeille', 'pk-wordpress-tools' ); ?></button>
						</form>
					<?php else : ?>
						<form method="post" class="pkwt-inline-form">
							<?php wp_nonce_field( 'pkwt-restore-' . $id ); ?>
							<input type="hidden" name="pkwt_snippet_action" value="restore" /><input type="hidden" name="pkwt_snippet_id" value="<?php echo absint( $id ); ?>" />
							<?php if ( $return_to_library ) : ?><input type="hidden" name="pkwt_return_page" value="<?php echo esc_attr( self::LAB_SLUG ); ?>" /><?php endif; ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Restaurer', 'pk-wordpress-tools' ); ?></button>
						</form>
						<form method="post" class="pkwt-inline-form pkwt-permanent-delete-form">
							<?php wp_nonce_field( 'pkwt-permanent-delete-' . $id ); ?>
							<input type="hidden" name="pkwt_snippet_action" value="permanent-delete" /><input type="hidden" name="pkwt_snippet_id" value="<?php echo absint( $id ); ?>" />
							<?php if ( $return_to_library ) : ?><input type="hidden" name="pkwt_return_page" value="<?php echo esc_attr( self::LAB_SLUG ); ?>" /><?php endif; ?>
							<label><input type="checkbox" name="pkwt_confirm_permanent_delete" value="1" required /> <?php esc_html_e( 'Confirmer', 'pk-wordpress-tools' ); ?></label>
							<button type="submit" class="button button-small pkwt-delete"><?php esc_html_e( 'Supprimer définitivement', 'pk-wordpress-tools' ); ?></button>
						</form>
					<?php endif; ?>
					<span class="pkwt-snippet-item__date"><?php echo esc_html( mysql2date( 'Y-m-d H:i', $snippet->modified_at ?? '0000-00-00 00:00:00' ) ); ?></span>
				</div>
				<pre class="pkwt-lab-item__code"><code><?php echo esc_html( $snippet->code ); ?></code></pre>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handle list-page actions with PRG redirects.
	 */
	private function dispatch_snippet_actions(): void {
		if ( ! isset( $_POST['pkwt_snippet_action'], $_POST['pkwt_snippet_id'] ) ) {
			return;
		}
		$id     = absint( $_POST['pkwt_snippet_id'] );
		$action = sanitize_key( wp_unslash( $_POST['pkwt_snippet_action'] ) );
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$s      = PKWT_Snippets::instance();
		if ( ! in_array( $action, [ 'trash', 'restore', 'permanent-delete', 'add-library', 'remove-library' ], true ) ) {
			wp_die( esc_html__( 'Action inconnue.', 'pk-wordpress-tools' ) );
		}

		$nonce_action = in_array( $action, [ 'add-library', 'remove-library' ], true ) ? 'pkwt-library-' . $id : 'pkwt-' . $action . '-' . $id;
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}

		switch ( $action ) {
			case 'trash':
				if ( $s->trash_snippet( $id ) ) {
					$this->add_notice( 'success', __( 'Snippet déplacé dans la corbeille.', 'pk-wordpress-tools' ) );
				}
				break;
			case 'restore':
				if ( $s->restore_snippet( $id ) ) {
					$this->add_notice( 'success', __( 'Snippet restauré dans les snippets inactifs.', 'pk-wordpress-tools' ) );
				}
				break;
			case 'permanent-delete':
				if ( empty( $_POST['pkwt_confirm_permanent_delete'] ) ) {
					$this->add_notice( 'error', __( 'Confirmez la suppression définitive.', 'pk-wordpress-tools' ) );
				} elseif ( $s->permanently_delete_snippet( $id ) ) {
					$this->update_personal_library_ids( array_diff( $this->personal_library_ids(), [ $id ] ) );
					$this->add_notice( 'success', __( 'Snippet et révisions supprimés définitivement.', 'pk-wordpress-tools' ) );
				}
				break;
			case 'add-library':
				if ( $s->get_snippet( $id ) ) {
					$this->update_personal_library_ids( [ ...$this->personal_library_ids(), $id ] );
					$this->add_notice( 'success', __( 'Snippet ajouté à la bibliothèque personnelle.', 'pk-wordpress-tools' ) );
				}
				break;
			case 'remove-library':
				$this->update_personal_library_ids( array_diff( $this->personal_library_ids(), [ $id ] ) );
				$this->add_notice( 'success', __( 'Snippet retiré de la bibliothèque personnelle.', 'pk-wordpress-tools' ) );
				break;
		}

		$return_page = isset( $_POST['pkwt_return_page'] ) ? sanitize_key( wp_unslash( $_POST['pkwt_return_page'] ) ) : self::SNAP_SLUG;
		$return_page = self::LAB_SLUG === $return_page ? self::LAB_SLUG : self::SNAP_SLUG;
		wp_safe_redirect( admin_url( 'admin.php?page=' . $return_page ) );
		exit;
	}

	/**
	 * Handle server-backup actions with nonces and PRG redirects.
	 */
	private function dispatch_backup_actions(): void {
		if ( ! isset( $_POST['pkwt_backup_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['pkwt_backup_action'] ) );
		$backup_id = isset( $_POST['pkwt_backup_id'] ) ? absint( $_POST['pkwt_backup_id'] ) : 0;
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$snippets = PKWT_Snippets::instance();

		if ( 'create' === $action ) {
			if ( ! wp_verify_nonce( $nonce, 'pkwt-create-backup' ) ) {
				wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
			}
			$label = isset( $_POST['pkwt_backup_label'] ) ? sanitize_text_field( wp_unslash( $_POST['pkwt_backup_label'] ) ) : '';
			$backup_id = $snippets->create_backup( $label, get_current_user_id(), $this->personal_library_ids() );
			$this->add_notice( $backup_id > 0 ? 'success' : 'error', $backup_id > 0 ? __( 'Sauvegarde créée sur le serveur.', 'pk-wordpress-tools' ) : __( 'La sauvegarde serveur n\'a pas pu être créée.', 'pk-wordpress-tools' ) );
		} elseif ( 'restore' === $action ) {
			if ( $backup_id < 1 || ! wp_verify_nonce( $nonce, 'pkwt-restore-backup-' . $backup_id ) ) {
				wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
			}
			if ( empty( $_POST['pkwt_backup_confirm'] ) ) {
				$this->add_notice( 'error', __( 'Cochez la confirmation avant de restaurer une sauvegarde.', 'pk-wordpress-tools' ) );
			} else {
				$count = $snippets->restore_backup( $backup_id );
				$this->add_notice( $count >= 0 ? 'success' : 'error', $count >= 0 ? sprintf( _n( '%d snippet restauré.', '%d snippets restaurés.', $count, 'pk-wordpress-tools' ), $count ) : __( 'La sauvegarde est invalide ou la restauration a échoué.', 'pk-wordpress-tools' ) );
			}
		} elseif ( 'delete' === $action ) {
			if ( $backup_id < 1 || ! wp_verify_nonce( $nonce, 'pkwt-delete-backup-' . $backup_id ) ) {
				wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
			}
			$deleted = $snippets->delete_backup( $backup_id );
			$this->add_notice( $deleted ? 'success' : 'error', $deleted ? __( 'Sauvegarde serveur supprimée.', 'pk-wordpress-tools' ) : __( 'La sauvegarde serveur n\'a pas pu être supprimée.', 'pk-wordpress-tools' ) );
		} else {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SNAP_SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * PAGE: EDIT (add/edit snippet)
	 * ------------------------------------------------------------------- */

	/**
	 * Edit / create a snippet.
	 */
	public function render_edit_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$this->dispatch_revision_restore( $id );
		$this->dispatch_snippet_save( $id );
		$snippet = null;
		if ( $id > 0 ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'pkwt-edit_' . $id ) ) {
				wp_die( esc_html__( 'Lien expiré ou invalide.', 'pk-wordpress-tools' ) );
			}
			$snippet = PKWT_Snippets::instance()->get_snippet( $id );
		}

		$name        = $snippet->name        ?? '';
		$description = $snippet->description ?? '';
		$code        = $snippet->code        ?? '';
		$active      = $snippet->active      ?? 0;
		$revisions   = $id > 0 ? PKWT_Snippets::instance()->get_revisions( $id ) : [];

		$this->shell_open(
			'pkwt-edit',
			$id > 0 ? __( 'Modifier le snippet', 'pk-wordpress-tools' ) : __( 'Nouveau snippet', 'pk-wordpress-tools' )
		);
		$this->notices();
		?>
		<div class="pkwt-panel pkwt-form">
		<div class="pkwt-edit-layout">
		<form method="post" id="pkwt-snippet-edit-form" class="pkwt-edit-grid">
			<?php wp_nonce_field( 'pkwt-save_' . $id ); ?>
			<input type="hidden" name="pkwt[id]" value="<?php echo esc_attr( $id ); ?>" />

				<div class="pkwt-field pkwt-field--span-2">
					<label for="pkwt-name"><?php esc_html_e( 'Nom', 'pk-wordpress-tools' ); ?></label>
					<input type="text" id="pkwt-name" name="pkwt[name]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required />
				</div>
				<div class="pkwt-field pkwt-field--span-2">
					<label for="pkwt-description"><?php esc_html_e( 'Description', 'pk-wordpress-tools' ); ?></label>
					<textarea id="pkwt-description" name="pkwt[description]" rows="2"><?php echo esc_textarea( $description ); ?></textarea>
				</div>
				<div class="pkwt-field pkwt-field--span-2">
					<label for="pkwt-code"><?php esc_html_e( 'Code PHP', 'pk-wordpress-tools' ); ?></label>
					<textarea id="pkwt-code" name="pkwt[code]" rows="22" class="code"><?php echo esc_textarea( $code ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Code PHP pur, sans balise <?php d\'ouverture. Exécuté globalement (front + admin).', 'pk-wordpress-tools' ); ?></p>
				</div>
		</form>
			<?php if ( $id > 0 ) : ?>
				<aside class="pkwt-revisions-panel" aria-labelledby="pkwt-revisions-title">
					<h2 id="pkwt-revisions-title"><?php esc_html_e( 'Historique des versions', 'pk-wordpress-tools' ); ?></h2>
					<p><?php esc_html_e( 'Chaque enregistrement conserve l’état précédent. La restauration crée une nouvelle version.', 'pk-wordpress-tools' ); ?></p>
					<?php if ( empty( $revisions ) ) : ?>
						<p class="pkwt-muted"><?php esc_html_e( 'Aucune version antérieure.', 'pk-wordpress-tools' ); ?></p>
					<?php else : foreach ( $revisions as $revision ) : ?>
						<div class="pkwt-revision">
							<strong><?php echo esc_html( sprintf( __( 'Version %d', 'pk-wordpress-tools' ), $revision->version_number ) ); ?></strong>
							<span><?php echo esc_html( mysql2date( 'Y-m-d H:i', $revision->created_at ) ); ?></span>
							<form method="post">
								<?php wp_nonce_field( 'pkwt-restore-revision-' . $id . '-' . (int) $revision->id ); ?>
								<input type="hidden" name="pkwt_restore_revision" value="<?php echo absint( $revision->id ); ?>" />
								<label><input type="checkbox" name="pkwt_confirm_restore_revision" value="1" required /> <?php esc_html_e( 'Je confirme la restauration', 'pk-wordpress-tools' ); ?></label>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Restaurer', 'pk-wordpress-tools' ); ?></button>
							</form>
						</div>
					<?php endforeach; endif; ?>
				</aside>
			<?php endif; ?>
			</div>

			<div class="pkwt-edit-action-bar">
				<span><?php echo esc_html( $id > 0 ? __( 'Une version antérieure sera conservée.', 'pk-wordpress-tools' ) : __( 'Le snippet sera créé inactif sauf activation.', 'pk-wordpress-tools' ) ); ?></span>
				<label class="pkwt-edit-action-bar__toggle"><input type="checkbox" form="pkwt-snippet-edit-form" name="pkwt[active]" value="1" <?php checked( $active, 1 ); ?> /> <?php esc_html_e( 'Activer ce snippet', 'pk-wordpress-tools' ); ?></label>
				<button type="submit" form="pkwt-snippet-edit-form" class="button button-primary pkwt-btn-primary"><?php echo esc_html( $id > 0 ? __( 'Enregistrer la nouvelle version', 'pk-wordpress-tools' ) : __( 'Enregistrer', 'pk-wordpress-tools' ) ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pkwt-snippets' ) ); ?>" class="button"><?php esc_html_e( 'Annuler', 'pk-wordpress-tools' ); ?></a>
			</div>
		</div>
		<?php
		$this->shell_close();
	}

	/**
	 * Handle snippet save POST.
	 */
	private function dispatch_snippet_save( int $id ): void {
		if ( ! isset( $_POST['pkwt'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'pkwt-save_' . $id ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}
		$data     = wp_unslash( $_POST['pkwt'] );
		$data['id'] = $id;
		$saved_id = PKWT_Snippets::instance()->save_snippet( $data );
		if ( $saved_id > 0 ) {
			$this->add_notice( 'success', __( 'Snippet enregistré.', 'pk-wordpress-tools' ) );
			$url = wp_nonce_url( admin_url( 'admin.php?page=pkwt-edit&id=' . $saved_id ), 'pkwt-edit_' . $saved_id );
			wp_safe_redirect( $url );
			exit;
		}
		$this->add_notice( 'error', __( 'Erreur lors de l\'enregistrement.', 'pk-wordpress-tools' ) );
	}

	/** Restore a revision only after explicit confirmation. */
	private function dispatch_revision_restore( int $snippet_id ): void {
		if ( $snippet_id < 1 || ! isset( $_POST['pkwt_restore_revision'] ) ) {
			return;
		}
		$revision_id = absint( $_POST['pkwt_restore_revision'] );
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pkwt-restore-revision-' . $snippet_id . '-' . $revision_id ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}
		if ( empty( $_POST['pkwt_confirm_restore_revision'] ) ) {
			$this->add_notice( 'error', __( 'Confirmez la restauration de cette version.', 'pk-wordpress-tools' ) );
			return;
		}
		$restored = PKWT_Snippets::instance()->restore_revision( $snippet_id, $revision_id, get_current_user_id() );
		$this->add_notice( $restored ? 'success' : 'error', $restored ? __( 'Version restaurée.', 'pk-wordpress-tools' ) : __( 'La version n’a pas pu être restaurée.', 'pk-wordpress-tools' ) );
		if ( $restored ) {
			wp_safe_redirect( wp_nonce_url( admin_url( 'admin.php?page=pkwt-edit&id=' . $snippet_id ), 'pkwt-edit_' . $snippet_id ) );
			exit;
		}
	}

	/* ---------------------------------------------------------------------
	 * PAGE: LAB (presets)
	 * ------------------------------------------------------------------- */

	public function render_lab_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		// A normal form submission is intentional here: feature availability must
		// persist even when an admin site's AJAX endpoint is blocked by security rules.
		if ( isset( $_POST['pkwt_feature'] ) && 'plugin_notes' === $_POST['pkwt_feature'] ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'pkwt-toggle-plugin-notes' ) ) {
				wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
			}
			PKWT_Notes::set_enabled( ! empty( $_POST['pkwt_feature_enabled'] ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::LAB_SLUG ) );
			exit;
		}

		// Handle install-as-snippet (copy code into a new snippet for editing).
		if ( isset( $_GET['action'], $_GET['preset'], $_GET['_wpnonce'] )
			&& 'install' === $_GET['action']
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pkwt-install_' . sanitize_text_field( wp_unslash( $_GET['preset'] ) ) )
		) {
			$slug   = sanitize_text_field( wp_unslash( $_GET['preset'] ) );
			$new_id = PKWT_Lab::instance()->install_preset( $slug );
			if ( $new_id > 0 ) {
				$edit = wp_nonce_url( admin_url( 'admin.php?page=pkwt-edit&id=' . $new_id ), 'pkwt-edit_' . $new_id );
				$this->add_notice( 'success', sprintf( __( 'Snippet créé depuis le preset. <a href="%s">Modifier →</a>', 'pk-wordpress-tools' ), esc_url( $edit ) ) );
			}
		}

		$presets = PKWT_Lab::instance()->get_presets();
		$by_cat  = [];
		foreach ( $presets as $p ) {
			$by_cat[ $p['category'] ][] = $p;
		}
		$active_count = count( PKWT_Lab::get_active() );

		$this->shell_open(
			self::LAB_SLUG,
			__( 'Bibliothèque', 'pk-wordpress-tools' ),
			sprintf(
				_n( '%d fonction active.', '%d fonctions actives.', $active_count, 'pk-wordpress-tools' ),
				$active_count
			)
		);
		$this->notices();
		?>

		<div class="pkwt-lab-section">
			<h2 class="pkwt-lab-cat"><?php esc_html_e( 'Extensions', 'pk-wordpress-tools' ); ?></h2>
			<div class="pkwt-lab-list">
				<div class="pkwt-lab-item <?php echo PKWT_Notes::is_enabled() ? 'is-on' : ''; ?>" data-name="notes icones extensions">
					<div class="pkwt-lab-item__main">
						<div class="pkwt-lab-item__info">
							<strong class="pkwt-lab-item__name"><?php esc_html_e( 'Notes & icônes sur Extensions', 'pk-wordpress-tools' ); ?></strong>
							<span class="pkwt-lab-item__desc"><?php esc_html_e( 'Fonction intégrée: ajoute une colonne icône et une colonne note éditable sur l’écran Extensions de WordPress.', 'pk-wordpress-tools' ); ?></span>
						</div>
						<form method="post" class="pkwt-feature-form">
							<?php wp_nonce_field( 'pkwt-toggle-plugin-notes' ); ?>
							<input type="hidden" name="pkwt_feature" value="plugin_notes" />
							<input type="hidden" name="pkwt_feature_enabled" value="0" />
							<label class="pkwt-switch">
								<input type="checkbox" class="pkwt-feature-form-toggle" name="pkwt_feature_enabled" value="1" <?php checked( PKWT_Notes::is_enabled() ); ?> onchange="this.form.submit()" />
								<span class="pkwt-switch__slider"></span>
							</label>
						</form>
					</div>
					<div class="pkwt-lab-item__actions">
						<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-small"><?php esc_html_e( 'Ouvrir Extensions', 'pk-wordpress-tools' ); ?></a>
					</div>
				</div>
			</div>
		</div>

		<?php foreach ( $by_cat as $cat => $items ) : ?>
			<div class="pkwt-lab-section">
				<h2 class="pkwt-lab-cat"><?php echo esc_html( $cat ); ?></h2>
				<div class="pkwt-lab-list">
					<?php foreach ( $items as $p ) :
						$is_on = PKWT_Lab::is_active( $p['slug'] );
						$install_url = wp_nonce_url(
							admin_url( 'admin.php?page=pkwt-lab&action=install&preset=' . $p['slug'] ),
							'pkwt-install_' . $p['slug']
						);
						?>
						<div class="pkwt-lab-item <?php echo $is_on ? 'is-on' : ''; ?>" data-name="<?php echo esc_attr( strtolower( $p['name'] . ' ' . $p['description'] ) ); ?>">
							<div class="pkwt-lab-item__main">
								<div class="pkwt-lab-item__info">
									<strong class="pkwt-lab-item__name"><?php echo esc_html( $p['name'] ); ?></strong>
									<span class="pkwt-lab-item__desc"><?php echo esc_html( $p['description'] ); ?></span>
								</div>
								<label class="pkwt-switch">
									<input type="checkbox" class="pkwt-preset-toggle" data-slug="<?php echo esc_attr( $p['slug'] ); ?>" <?php checked( $is_on ); ?> />
									<span class="pkwt-switch__slider"></span>
								</label>
							</div>
							<div class="pkwt-lab-item__actions">
								<button type="button" class="pkwt-lab-code-toggle"><?php esc_html_e( 'Voir le code', 'pk-wordpress-tools' ); ?></button>
								<button type="button" class="button button-small pkwt-copy-btn" data-code="<?php echo esc_attr( $p['code'] ); ?>"><?php esc_html_e( 'Copier', 'pk-wordpress-tools' ); ?></button>
								<a href="<?php echo esc_url( $install_url ); ?>" class="button button-small"><?php esc_html_e( 'Copier comme snippet', 'pk-wordpress-tools' ); ?></a>
							</div>
							<pre class="pkwt-lab-item__code"><code><?php echo esc_html( $p['code'] ); ?></code></pre>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach;
		$this->shell_close();
	}

	/** Remove or restore a native feature from the Library. */
	private function dispatch_native_feature_library_action(): void {
		if ( ! isset( $_POST['pkwt_native_feature_action'], $_POST['pkwt_native_feature_id'] ) ) {
			return;
		}

		$action     = sanitize_key( wp_unslash( $_POST['pkwt_native_feature_action'] ) );
		$feature_id = sanitize_key( wp_unslash( $_POST['pkwt_native_feature_id'] ) );
		if ( ! in_array( $action, [ 'remove', 'restore' ], true ) || ! isset( PKWT_Native_Features::definitions()[ $feature_id ] ) ) {
			wp_die( esc_html__( 'Action inconnue.', 'pk-wordpress-tools' ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pkwt-' . $action . '-native-feature-' . $feature_id ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}

		PKWT_Native_Features::set_removed( $feature_id, 'remove' === $action );
		$this->add_notice( 'success', 'remove' === $action ? __( 'Fonction désactivée et retirée de la bibliothèque.', 'pk-wordpress-tools' ) : __( 'Fonction restaurée dans la bibliothèque.', 'pk-wordpress-tools' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::LAB_SLUG ) );
		exit;
	}

	/** Import original Code Snippets sources, then redirect to prevent a repeat POST. */
	private function dispatch_code_snippets_source_import(): void {
		if ( ! isset( $_POST['pkwt_library_action'] ) || 'import-code-snippets-sources' !== $_POST['pkwt_library_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pkwt-import-code-snippets-sources' ) ) {
			wp_die( esc_html__( 'Vérification de sécurité échouée.', 'pk-wordpress-tools' ) );
		}

		$report = PKWT_Snippets::instance()->import_code_snippets_sources();
		$parts = [];
		foreach ( [ 'imported' => __( 'Importés', 'pk-wordpress-tools' ), 'present' => __( 'Déjà présents', 'pk-wordpress-tools' ), 'unavailable' => __( 'Indisponibles', 'pk-wordpress-tools' ) ] as $key => $label ) {
			if ( ! empty( $report[ $key ] ) ) {
				$parts[] = '<strong>' . esc_html( $label ) . ' :</strong> ' . esc_html( implode( ', ', $report[ $key ] ) );
			}
		}
		$this->add_notice( $report['error'] ? 'error' : 'success', $parts ? implode( '<br>', $parts ) : esc_html__( 'Aucune source originale ciblée n’a été trouvée.', 'pk-wordpress-tools' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::LAB_SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * PAGE: IMPORT / EXPORT
	 * ------------------------------------------------------------------- */

	public function render_import_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		$this->maybe_download_snippets_export();

		// Import upload.
		if ( isset( $_POST['pkwt_import'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'pkwt-import' ) ) {
			$json   = wp_unslash( $_POST['pkwt_import'] ?? '' );
			$count  = PKWT_Snippets::instance()->import_json( (string) $json );
			if ( $count > 0 ) {
				$this->add_notice( 'success', sprintf( esc_html__( '%d snippets importés (inactifs).', 'pk-wordpress-tools' ), $count ) );
			} else {
				$this->add_notice( 'error', __( 'JSON invalide ou aucun snippet à importer.', 'pk-wordpress-tools' ) );
			}
		}

		$export_url = wp_nonce_url( admin_url( 'admin.php?page=pkwt-import&action=export' ), 'pkwt-export' );

		$this->shell_open(
			self::IMP_SLUG,
			__( 'Import / Export', 'pk-wordpress-tools' ),
			__( 'Sauvegardez ou restaurez vos snippets au format JSON.', 'pk-wordpress-tools' )
		);
		$this->notices();
		?>
		<div class="pkwt-panel">
			<h2 class="pkwt-panel__title"><?php esc_html_e( 'Exporter', 'pk-wordpress-tools' ); ?></h2>
			<p class="pkwt-panel__copy"><?php esc_html_e( 'Télécharge un fichier JSON contenant tous vos snippets (avec leur statut actif).', 'pk-wordpress-tools' ); ?></p>
			<p><a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary pkwt-btn-primary"><?php esc_html_e( 'Télécharger (JSON)', 'pk-wordpress-tools' ); ?></a></p>
		</div>

		<div class="pkwt-panel">
			<h2 class="pkwt-panel__title"><?php esc_html_e( 'Importer', 'pk-wordpress-tools' ); ?></h2>
			<p class="pkwt-panel__copy"><?php esc_html_e( 'Collez un JSON issu d\'un export. Les snippets sont créés désactivés pour vérification.', 'pk-wordpress-tools' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'pkwt-import' ); ?>
				<p>
					<textarea name="pkwt_import" rows="15" class="code pkwt-input pkwt-input--code" placeholder='<?php esc_attr_e( '[{"name":"Mon snippet", ...}]', 'pk-wordpress-tools' ); ?>'></textarea>
				</p>
				<p><button type="submit" class="button button-primary pkwt-btn-primary"><?php esc_html_e( 'Importer', 'pk-wordpress-tools' ); ?></button></p>
			</form>
		</div>
		<?php
		$this->shell_close();
	}

	/* ---------------------------------------------------------------------
	 * PAGE: ABOUT
	 * ------------------------------------------------------------------- */

	public function render_about_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		$this->shell_open(
			self::ABOUT_SLUG,
			__( 'À propos', 'pk-wordpress-tools' ),
			__( 'WP PK Tools — boîte à outils personnelle WordPress.', 'pk-wordpress-tools' )
		);
		$this->notices();
		?>
		<div class="pkwt-panel">
			<div class="pkwt-about-head">
				<span class="pkwt-about-logo"><img src="<?php echo esc_url( plugins_url( 'icon.png', PKWT_FILE ) ); ?>" alt=""/></span>
				<div>
					<h2 class="pkwt-panel__title">WP PK Tools</h2>
					<p class="pkwt-panel__copy">
						<span class="pkwt-version-badge">v<?php echo esc_html( PKWT_VERSION ); ?></span>
						<?php esc_html_e( 'Snippets exécutables, Lab de presets, Manager d\'extensions, notes par extension, import/export.', 'pk-wordpress-tools' ); ?>
					</p>
				</div>
			</div>
			<div class="pkwt-about-grid">
				<div class="pkwt-about-feature">
					<span class="dashicons dashicons-media-code"></span>
					<h3><?php esc_html_e( 'Snippets', 'pk-wordpress-tools' ); ?></h3>
					<p><?php esc_html_e( 'Stokés en DB dédiée, exécutés globalement, éditeur CodeMirror intégré.', 'pk-wordpress-tools' ); ?></p>
				</div>
				<div class="pkwt-about-feature">
					<span class="dashicons dashicons-superhero"></span>
					<h3><?php esc_html_e( 'Lab', 'pk-wordpress-tools' ); ?></h3>
					<p><?php esc_html_e( '13+ presets de snippets utilitaires installables en un clic.', 'pk-wordpress-tools' ); ?></p>
				</div>
				<div class="pkwt-about-feature">
					<span class="dashicons dashicons-grid-view"></span>
					<h3><?php esc_html_e( 'Manager', 'pk-wordpress-tools' ); ?></h3>
					<p><?php esc_html_e( 'Vue grille de toutes vos extensions avec icône et note libre éditable.', 'pk-wordpress-tools' ); ?></p>
				</div>
				<div class="pkwt-about-feature">
					<span class="dashicons dashicons-database-import"></span>
					<h3><?php esc_html_e( 'Import / Export', 'pk-wordpress-tools' ); ?></h3>
					<p><?php esc_html_e( 'Sauvegarde et restauration JSON de tous vos snippets.', 'pk-wordpress-tools' ); ?></p>
				</div>
			</div>
		</div>
		<div class="pkwt-panel pkwt-theme-settings">
			<h2 class="pkwt-panel__title"><?php esc_html_e( 'Thème de l’interface', 'pk-wordpress-tools' ); ?></h2>
			<p class="pkwt-panel__copy"><?php esc_html_e( 'Dashboard propose une interface calme en cartes slate. Brutaliste conserve la mise en page suisse-industrielle actuelle.', 'pk-wordpress-tools' ); ?></p>
			<form method="post" class="pkwt-theme-settings__form">
				<?php wp_nonce_field( 'pkwt-save-admin-theme', 'pkwt_admin_theme_nonce' ); ?>
				<input type="hidden" name="pkwt_theme_page" value="pkwt-about" />
				<label for="pkwt-admin-theme"><?php esc_html_e( 'Apparence', 'pk-wordpress-tools' ); ?></label>
				<select id="pkwt-admin-theme" name="pkwt_admin_theme">
					<option value="dashboard"<?php selected( $this->admin_theme(), 'dashboard' ); ?>><?php esc_html_e( 'Dashboard', 'pk-wordpress-tools' ); ?></option>
					<option value="brutalist"<?php selected( $this->admin_theme(), 'brutalist' ); ?>><?php esc_html_e( 'Brutaliste', 'pk-wordpress-tools' ); ?></option>
				</select>
				<button type="submit" class="button button-primary pkwt-btn-primary"><?php esc_html_e( 'Enregistrer', 'pk-wordpress-tools' ); ?></button>
			</form>
		</div>
		<?php
		$this->shell_close();
	}
}
