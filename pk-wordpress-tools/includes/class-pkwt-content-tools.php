<?php
/**
 * Content-oriented administration tools.
 *
 * @package WP_PK_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class PKWT_Content_Tools {

	private static ?PKWT_Content_Tools $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/** Wire the content administration hooks. */
	public function init(): void {
		add_filter( 'manage_edit-post_columns', [ $this, 'add_featured_image_column' ] );
		add_action( 'manage_post_posts_custom_column', [ $this, 'render_featured_image_column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'render_featured_image_filter' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_posts_by_featured_image' ] );
		add_filter( 'views_edit-post', [ $this, 'add_markdown_export_view' ] );
		add_action( 'admin_menu', [ $this, 'register_missing_images_page' ] );
		add_action( 'admin_post_pkwt_delete_featured_image', [ $this, 'delete_featured_image' ] );
		add_action( 'admin_post_pkwt_export_markdown', [ $this, 'export_markdown' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_search' ], 100 );
		add_action( 'pre_current_active_plugins', [ $this, 'sort_active_plugins_first' ] );
	}

	public function add_featured_image_column( array $columns ): array {
		$updated = [];
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$updated['pkwt_featured_image'] = __( 'Featured image', 'pk-wordpress-tools' );
			}
			$updated[ $key ] = $label;
		}
		return $updated;
	}

	public function render_featured_image_column( string $column, int $post_id ): void {
		if ( 'pkwt_featured_image' !== $column ) {
			return;
		}

		$attachment_id = get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id ) {
			echo '<span class="pkwt-featured-image__empty">' . esc_html__( 'No image', 'pk-wordpress-tools' ) . '</span>';
			return;
		}

		$thumbnail = wp_get_attachment_image( $attachment_id, [ 80, 60 ], false, [ 'class' => 'pkwt-featured-image__thumbnail' ] );
		echo wp_kses_post( $thumbnail );

		if ( current_user_can( 'delete_post', $attachment_id ) ) {
			$action_url = admin_url( 'admin-post.php' );
			?>
			<form class="pkwt-featured-image__delete-form" method="post" action="<?php echo esc_url( $action_url ); ?>" data-pkwt-confirm="<?php echo esc_attr__( 'Permanently delete this featured image? This cannot be undone.', 'pk-wordpress-tools' ); ?>">
				<input type="hidden" name="action" value="pkwt_delete_featured_image">
				<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( wp_get_referer() ?: admin_url( 'edit.php' ) ); ?>">
				<?php wp_nonce_field( 'pkwt_delete_featured_image_' . $attachment_id ); ?>
				<button type="submit" class="button-link-delete pkwt-featured-image__delete"><?php esc_html_e( 'Delete image', 'pk-wordpress-tools' ); ?></button>
			</form>
			<?php
		}
	}

	public function render_featured_image_filter( string $post_type ): void {
		if ( ! $this->is_posts_list_screen() || 'post' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET['pkwt_featured_image'] ) ? sanitize_key( wp_unslash( $_GET['pkwt_featured_image'] ) ) : '';
		?>
		<select name="pkwt_featured_image" id="pkwt-featured-image-filter">
			<option value=""><?php esc_html_e( 'All featured images', 'pk-wordpress-tools' ); ?></option>
			<option value="has" <?php selected( 'has', $selected ); ?>><?php esc_html_e( 'Has featured image', 'pk-wordpress-tools' ); ?></option>
			<option value="missing" <?php selected( 'missing', $selected ); ?>><?php esc_html_e( 'Missing featured image', 'pk-wordpress-tools' ); ?></option>
		</select>
		<?php
	}

	public function add_markdown_export_view( array $views ): array {
		if ( ! $this->is_posts_list_screen() || ! current_user_can( 'edit_posts' ) ) {
			return $views;
		}
		ob_start();
		$this->render_markdown_export_button();
		$views['pkwt-markdown-export'] = ob_get_clean();
		return $views;
	}

	public function filter_posts_by_featured_image( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ! $this->is_posts_list_screen() ) {
			return;
		}

		$filter = isset( $_GET['pkwt_featured_image'] ) ? sanitize_key( wp_unslash( $_GET['pkwt_featured_image'] ) ) : '';
		if ( ! in_array( $filter, [ 'has', 'missing' ], true ) ) {
			return;
		}

		$query->set( 'meta_query', [
			[
				'key'     => '_thumbnail_id',
				'compare' => 'has' === $filter ? 'EXISTS' : 'NOT EXISTS',
			],
		] );
	}

	public function register_missing_images_page(): void {
		$count = $this->get_missing_featured_image_count();
		$label = sprintf(
			/* translators: %d: published posts without a featured image. */
			__( 'Sans image <span class="awaiting-mod count-%d"><span class="pending-count">%d</span></span>', 'pk-wordpress-tools' ),
			$count,
			$count
		);
		add_submenu_page( 'edit.php', __( 'Posts without featured images', 'pk-wordpress-tools' ), $label, 'edit_posts', 'pkwt-missing-featured-images', [ $this, 'render_missing_images_page' ] );
	}

	public function render_missing_images_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'pk-wordpress-tools' ) );
		}

		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$query = new WP_Query([
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'paged'          => $page,
			'meta_query'     => [ [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ] ],
		]);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Posts without featured images', 'pk-wordpress-tools' ); ?></h1>
			<?php if ( $query->have_posts() ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th><?php esc_html_e( 'Title', 'pk-wordpress-tools' ); ?></th><th><?php esc_html_e( 'Published', 'pk-wordpress-tools' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $query->posts as $post ) : ?>
							<tr><td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( '(no title)', 'pk-wordpress-tools' ) ); ?></a></td><td><?php echo esc_html( get_the_date( get_option( 'date_format' ), $post ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				echo wp_kses_post( paginate_links([
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $page,
					'total'     => max( 1, (int) $query->max_num_pages ),
					'type'      => 'list',
				] ) );
				?>
			<?php else : ?>
				<p><?php esc_html_e( 'All published posts have a featured image.', 'pk-wordpress-tools' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		wp_reset_postdata();
	}

	public function delete_featured_image(): void {
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$post_id       = absint( $_POST['post_id'] ?? 0 );
		$nonce         = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! $attachment_id || ! $post_id || ! wp_verify_nonce( $nonce, 'pkwt_delete_featured_image_' . $attachment_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'delete_post', $attachment_id ) || $attachment_id !== (int) get_post_thumbnail_id( $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to delete this image.', 'pk-wordpress-tools' ), 403 );
		}

		$deleted = wp_delete_attachment( $attachment_id, true );
		$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
		$redirect = wp_validate_redirect( $redirect, admin_url( 'edit.php' ) );
		$redirect = add_query_arg( 'pkwt_featured_image_deleted', $deleted ? '1' : '0', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_admin_notice(): void {
		if ( ! isset( $_GET['pkwt_featured_image_deleted'] ) ) {
			return;
		}
		$deleted = '1' === sanitize_text_field( wp_unslash( $_GET['pkwt_featured_image_deleted'] ) );
		$message = $deleted ? __( 'The featured image was permanently deleted.', 'pk-wordpress-tools' ) : __( 'The featured image could not be deleted.', 'pk-wordpress-tools' );
		$class   = $deleted ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function export_markdown(): void {
		check_admin_referer( 'pkwt_export_markdown' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to export posts.', 'pk-wordpress-tools' ), 403 );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The ZIP PHP extension is required for this export.', 'pk-wordpress-tools' ) );
		}

		$temp_file = wp_tempnam( 'pkwt-markdown-export.zip' );
		if ( ! $temp_file ) {
			wp_die( esc_html__( 'Unable to create the export file.', 'pk-wordpress-tools' ) );
		}
		register_shutdown_function( [ $this, 'cleanup_temp_file' ], $temp_file );
		$zip = new ZipArchive();
		try {
			if ( true !== $zip->open( $temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Unable to open ZIP file.' );
			}
			$index  = "# " . __( 'Post export', 'pk-wordpress-tools' ) . "\n\n";
			$paged  = 1;
			$status = array_values( array_diff( get_post_stati( [ 'internal' => false ], 'names' ), [ 'auto-draft', 'inherit', 'trash' ] ) );
			do {
				$query = new WP_Query([
					'post_type'              => 'post',
					'post_status'            => $status,
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]);
				foreach ( $query->posts as $post_id ) {
					$post     = get_post( $post_id );
					$filename = sprintf( '%06d-%s.md', $post_id, sanitize_file_name( $post->post_name ?: 'post' ) );
					if ( ! $zip->addFromString( $filename, $this->post_to_markdown( $post ) ) ) {
						throw new RuntimeException( 'Unable to add post to ZIP file.' );
					}
					$index .= '- ' . $filename . ' - ' . wp_strip_all_tags( get_the_title( $post ) ) . "\n";
				}
				$has_more = $paged < (int) $query->max_num_pages;
				++$paged;
			} while ( $has_more );
			if ( ! $zip->addFromString( 'INDEX.md', $index ) || ! $zip->close() ) {
				throw new RuntimeException( 'Unable to finalize ZIP file.' );
			}

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="pkwt-posts-markdown.zip"' );
			header( 'Content-Length: ' . (string) filesize( $temp_file ) );
			readfile( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			$this->cleanup_temp_file( $temp_file );
			exit;
		} catch ( Throwable $exception ) {
			$zip->close();
			wp_die( esc_html__( 'The Markdown export could not be created.', 'pk-wordpress-tools' ) );
		} finally {
			$this->cleanup_temp_file( $temp_file );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( $this->can_use_admin_bar_search() || $this->is_posts_list_screen() ) {
			wp_enqueue_style( 'pkwt-content-tools', PKWT_URL . 'assets/css/content-tools.css', [], PKWT_VERSION );
		}
		if ( $this->is_posts_list_screen() ) {
			wp_enqueue_script( 'pkwt-content-tools', PKWT_URL . 'assets/js/content-tools.js', [], PKWT_VERSION, true );
		}
	}

	public function add_admin_bar_search( WP_Admin_Bar $admin_bar ): void {
		if ( ! is_admin() || ! $this->can_use_admin_bar_search() ) {
			return;
		}
		$form = '<form class="pkwt-admin-bar-search" method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '">'
			. '<label class="screen-reader-text" for="pkwt-admin-bar-search-input">' . esc_html__( 'Search posts', 'pk-wordpress-tools' ) . '</label>'
			. '<input id="pkwt-admin-bar-search-input" name="s" type="search" placeholder="' . esc_attr__( 'Search posts', 'pk-wordpress-tools' ) . '">'
			. '<input name="post_type" type="hidden" value="post">'
			. '<button type="submit">' . esc_html__( 'Search', 'pk-wordpress-tools' ) . '</button></form>';
		$admin_bar->add_node([
			'id'    => 'pkwt-post-search',
			'title' => $form,
			'meta'  => [ 'class' => 'pkwt-admin-bar-search-node' ],
		]);
	}

	public function sort_active_plugins_first(): void {
		if ( is_network_admin() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		global $wp_list_table;
		if ( ! $wp_list_table instanceof WP_Plugins_List_Table || empty( $wp_list_table->items ) ) {
			return;
		}
		$active = array_fill_keys( (array) get_option( 'active_plugins', [] ), true );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_fill_keys( array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ), true ) );
		}
		uksort( $wp_list_table->items, static function ( string $first, string $second ) use ( $active ): int {
			$first_active  = isset( $active[ $first ] );
			$second_active = isset( $active[ $second ] );
			if ( $first_active !== $second_active ) {
				return $first_active ? -1 : 1;
			}
			return strnatcasecmp( $first, $second );
		} );
	}

	public function cleanup_temp_file( string $temp_file ): void {
		if ( is_file( $temp_file ) ) {
			@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	private function render_markdown_export_button(): void {
		?>
		<form class="pkwt-markdown-export" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pkwt_export_markdown">
			<?php wp_nonce_field( 'pkwt_export_markdown' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Export Markdown ZIP', 'pk-wordpress-tools' ); ?></button>
		</form>
		<?php
	}

	private function get_missing_featured_image_count(): int {
		$query = new WP_Query([
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'meta_query'             => [ [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ] ],
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);
		return (int) $query->found_posts;
	}

	private function post_to_markdown( WP_Post $post ): string {
		$categories = wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'names' ] );
		$tags       = wp_get_post_terms( $post->ID, 'post_tag', [ 'fields' => 'names' ] );
		$thumbnail  = get_the_post_thumbnail_url( $post, 'full' );
		$yaml       = [
			'title'          => get_the_title( $post ),
			'date'           => get_post_time( 'c', true, $post ),
			'modified'       => get_post_modified_time( 'c', true, $post ),
			'status'         => $post->post_status,
			'slug'           => $post->post_name,
			'author'         => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'url'            => get_permalink( $post ),
			'featured_image' => $thumbnail ?: '',
		];
		$output = "---\n";
		foreach ( $yaml as $key => $value ) {
			$output .= $key . ': ' . $this->yaml_scalar( (string) $value ) . "\n";
		}
		$output .= "categories:\n";
		foreach ( $categories as $category ) {
			$output .= '  - ' . $this->yaml_scalar( $category ) . "\n";
		}
		$output .= "tags:\n";
		foreach ( $tags as $tag ) {
			$output .= '  - ' . $this->yaml_scalar( $tag ) . "\n";
		}
		$content = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $post->post_content ), true ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = preg_replace( "/[ \t]+\n/", "\n", $content );
		return $output . "---\n\n# " . $this->yaml_scalar( get_the_title( $post ) ) . "\n\n" . trim( (string) $content ) . "\n";
	}

	private function yaml_scalar( string $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return is_string( $encoded ) ? $encoded : '""';
	}

	private function is_posts_list_screen(): bool {
		global $pagenow;
		return is_admin() && 'edit.php' === $pagenow && ( ! isset( $_GET['post_type'] ) || 'post' === sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) && ! isset( $_GET['page'] );
	}

	private function can_use_admin_bar_search(): bool {
		$user = wp_get_current_user();
		return $user->exists() && ( is_super_admin( $user->ID ) || (bool) array_intersect( [ 'administrator', 'editor' ], (array) $user->roles ) );
	}
}
