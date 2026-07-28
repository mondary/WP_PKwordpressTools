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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_plugins_screen_assets' ], 20 );
		add_action( 'wp_ajax_pkwt_toggle_feature', [ $this, 'ajax_toggle_feature' ] );
		add_action( 'wp_ajax_pkwt_toggle_preset', [ $this, 'ajax_toggle_preset' ] );
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
				'confirmDelete' => __( 'Supprimer ce snippet ?', 'pk-wordpress-tools' ),
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

		$feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) ? (bool) $_POST['enabled'] : false;

		$known = [ 'plugin_notes' ];
		if ( ! in_array( $feature, $known, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Feature inconnue.', 'pk-wordpress-tools' ) ] );
		}

		if ( 'plugin_notes' === $feature ) {
			PKWT_Notes::set_enabled( $enabled );
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
		echo '<div class="pkwt-admin-shell"><div class="pkwt-page-stack">';
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

		$this->dispatch_snippet_actions();

		$snippets = PKWT_Snippets::instance()->get_all_snippets();
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
			</div>
			<p class="pkwt-toolbar__note"><strong><?php echo esc_html( (string) count( $snippets ) ); ?></strong> <?php esc_html_e( 'snippet(s) enregistré(s)', 'pk-wordpress-tools' ); ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=pkwt-lab' ) ); ?>"><?php esc_html_e( 'Ajouter depuis la bibliothèque', 'pk-wordpress-tools' ); ?></a></p>
		</div>

		<div class="pkwt-table-shell">
			<table class="wp-list-table widefat striped pkwt-table">
				<thead>
					<tr>
						<th class="pkwt-col-act"><?php esc_html_e( 'Actif', 'pk-wordpress-tools' ); ?></th>
						<th><?php esc_html_e( 'Nom', 'pk-wordpress-tools' ); ?></th>
						<th><?php esc_html_e( 'Description', 'pk-wordpress-tools' ); ?></th>
						<th class="pkwt-col-date"><?php esc_html_e( 'Modifié', 'pk-wordpress-tools' ); ?></th>
						<th class="pkwt-col-actions"><?php esc_html_e( 'Actions', 'pk-wordpress-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $snippets ) ) : ?>
					<tr><td colspan="5" class="pkwt-empty"><?php esc_html_e( 'Aucun snippet pour l\'instant. Créez-en un ou installez un preset depuis le Lab.', 'pk-wordpress-tools' ); ?></td></tr>
				<?php else : foreach ( $snippets as $s ) :
					$edit_url   = wp_nonce_url( admin_url( 'admin.php?page=pkwt-edit&id=' . $s->id ), 'pkwt-edit_' . $s->id );
					$toggle_url = wp_nonce_url( admin_url( 'admin.php?page=pkwt-snippets&action=toggle&id=' . $s->id ), 'pkwt-toggle_' . $s->id );
					$dup_url    = wp_nonce_url( admin_url( 'admin.php?page=pkwt-snippets&action=duplicate&id=' . $s->id ), 'pkwt-duplicate_' . $s->id );
					$del_url    = wp_nonce_url( admin_url( 'admin.php?page=pkwt-snippets&action=delete&id=' . $s->id ), 'pkwt-delete_' . $s->id );
				?>
					<tr data-id="<?php echo absint( $s->id ); ?>">
						<td class="pkwt-col-act">
							<a href="<?php echo esc_url( $toggle_url ); ?>" class="pkwt-status-badge <?php echo $s->active ? 'is-active' : 'is-off'; ?>">
								<?php if ( $s->active ) : ?>
									<span class="dashicons dashicons-yes-alt"></span>
								<?php else : ?>
									<span class="dashicons dashicons-minus"></span>
								<?php endif; ?>
							</a>
						</td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="pkwt-row-title"><?php echo esc_html( $s->name ); ?></a>
						</td>
						<td class="pkwt-desc"><?php echo esc_html( $s->description ? $s->description : '—' ); ?></td>
						<td class="pkwt-col-date"><?php echo esc_html( mysql2date( 'Y-m-d H:i', $s->modified_at ?? '0000-00-00 00:00:00' ) ); ?></td>
						<td class="pkwt-col-actions">
							<div class="pkwt-row-actions">
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Modifier', 'pk-wordpress-tools' ); ?></a>
								<a href="<?php echo esc_url( $dup_url ); ?>" class="button button-small"><?php esc_html_e( 'Dupliquer', 'pk-wordpress-tools' ); ?></a>
								<a href="<?php echo esc_url( $del_url ); ?>" class="button button-small pkwt-delete"><?php esc_html_e( 'Supprimer', 'pk-wordpress-tools' ); ?></a>
							</div>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		$this->shell_close();
	}

	/**
	 * Handle list-page actions (delete/toggle/duplicate).
	 */
	private function dispatch_snippet_actions(): void {
		if ( ! isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
			return;
		}
		$id     = absint( $_GET['id'] );
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		$s      = PKWT_Snippets::instance();

		switch ( $action ) {
			case 'delete':
				if ( wp_verify_nonce( $nonce, 'pkwt-delete_' . $id ) ) {
					$s->delete_snippet( $id );
					$this->add_notice( 'success', __( 'Snippet supprimé.', 'pk-wordpress-tools' ) );
				}
				break;
			case 'toggle':
				if ( wp_verify_nonce( $nonce, 'pkwt-toggle_' . $id ) ) {
					$cur = $s->get_snippet( $id );
					if ( $cur ) {
						$s->toggle_snippet( $id, ! (bool) $cur->active );
						$this->add_notice( 'success', $cur->active ? __( 'Snippet désactivé.', 'pk-wordpress-tools' ) : __( 'Snippet activé.', 'pk-wordpress-tools' ) );
					}
				}
				break;
			case 'duplicate':
				if ( wp_verify_nonce( $nonce, 'pkwt-duplicate_' . $id ) ) {
					$new = $s->duplicate_snippet( $id );
					if ( $new > 0 ) {
						$this->add_notice( 'success', sprintf( __( 'Snippet dupliqué. <a href="%s">Modifier →</a>', 'pk-wordpress-tools' ), esc_url( wp_nonce_url( admin_url( 'admin.php?page=pkwt-edit&id=' . $new ), 'pkwt-edit_' . $new ) ) ) );
					}
				}
				break;
		}
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
		$snippet = null;
		if ( $id > 0 ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'pkwt-edit_' . $id ) ) {
				wp_die( esc_html__( 'Lien expiré ou invalide.', 'pk-wordpress-tools' ) );
			}
			$snippet = PKWT_Snippets::instance()->get_snippet( $id );
		}

		$this->dispatch_snippet_save( $id );

		$name        = $snippet->name        ?? '';
		$description = $snippet->description ?? '';
		$code        = $snippet->code        ?? '';
		$active      = $snippet->active      ?? 0;

		$this->shell_open(
			'pkwt-edit',
			$id > 0 ? __( 'Modifier le snippet', 'pk-wordpress-tools' ) : __( 'Nouveau snippet', 'pk-wordpress-tools' )
		);
		$this->notices();
		?>
		<form method="post" class="pkwt-panel pkwt-form">
			<?php wp_nonce_field( 'pkwt-save_' . $id ); ?>
			<input type="hidden" name="pkwt[id]" value="<?php echo esc_attr( $id ); ?>" />

			<div class="pkwt-edit-grid">
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
				<div class="pkwt-field pkwt-edit-toggle">
					<label>
						<input type="checkbox" name="pkwt[active]" value="1" <?php checked( $active, 1 ); ?> />
						<span><?php esc_html_e( 'Activer ce snippet', 'pk-wordpress-tools' ); ?></span>
					</label>
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary pkwt-btn-primary"><?php esc_html_e( 'Enregistrer', 'pk-wordpress-tools' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pkwt-snippets' ) ); ?>" class="button"><?php esc_html_e( 'Annuler', 'pk-wordpress-tools' ); ?></a>
			</p>
		</form>
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
			echo '<script>window.location.href="' . esc_url( $url ) . '";</script>';
			exit;
		}
		$this->add_notice( 'error', __( 'Erreur lors de l\'enregistrement.', 'pk-wordpress-tools' ) );
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
			<h2 class="pkwt-lab-cat"><?php esc_html_e( 'Intégré', 'pk-wordpress-tools' ); ?></h2>
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

	/* ---------------------------------------------------------------------
	 * PAGE: IMPORT / EXPORT
	 * ------------------------------------------------------------------- */

	public function render_import_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission.', 'pk-wordpress-tools' ) );
		}

		// Export download.
		if ( isset( $_GET['action'] ) && 'export' === $_GET['action'] ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pkwt-export' ) ) {
				$json = PKWT_Snippets::instance()->export_json();
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="pkwt-snippets-' . gmdate( 'Y-m-d' ) . '.json"' );
				header( 'Content-Length: ' . strlen( $json ) );
				echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
		}

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
		<?php
		$this->shell_close();
	}
}
