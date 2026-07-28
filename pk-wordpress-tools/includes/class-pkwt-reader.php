<?php
/**
 * Public news reader, search shortcut, and reading progress indicator.
 *
 * @package PK_WordPress_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class PKWT_Reader {

	private const REST_NAMESPACE = 'pk-wordpress-tools/v1';
	private const REST_ROUTE     = '/reader/posts';

	private static ?PKWT_Reader $instance = null;
	private bool $initialized = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Register public hooks once. This permits the class to be safely initialized
	 * by a future bootstrap entry as well as when this file is loaded directly.
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;
		if ( PKWT_Native_Features::is_enabled( 'news-player' ) ) {
			add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		}
		if ( PKWT_Native_Features::is_enabled( 'news-player' ) || PKWT_Native_Features::is_enabled( 'post-search-auto' ) || PKWT_Native_Features::is_enabled( 'reading-progress' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'wp_footer', [ $this, 'render_public_ui' ] );
		}
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_posts' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$version = defined( 'PKWT_VERSION' ) ? PKWT_VERSION : false;
		$url     = defined( 'PKWT_URL' ) ? PKWT_URL : plugin_dir_url( __DIR__ ) . '../';

		wp_enqueue_style( 'pkwt-reader', $url . 'assets/css/reader.css', [], $version );
		wp_enqueue_script( 'pkwt-reader', $url . 'assets/js/reader.js', [], $version, true );
		wp_localize_script(
			'pkwt-reader',
			'PKWTReader',
			[
				'apiUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
				'features' => [
					'newsPlayer'     => PKWT_Native_Features::is_enabled( 'news-player' ),
					'postSearchAuto' => PKWT_Native_Features::is_enabled( 'post-search-auto' ),
					'readingProgress'=> PKWT_Native_Features::is_enabled( 'reading-progress' ),
				],
				'i18n'   => [
					'loading' => __( 'Loading latest posts...', 'pk-wordpress-tools' ),
					'error'   => __( 'Posts could not be loaded. Please try again.', 'pk-wordpress-tools' ),
					'empty'   => __( 'There are no published posts to read yet.', 'pk-wordpress-tools' ),
					'previous' => __( 'Previous post', 'pk-wordpress-tools' ),
					'next'     => __( 'Next post', 'pk-wordpress-tools' ),
				],
			]
		);
	}

	/**
	 * Return only presentation-safe fields for the public player.
	 */
	public function get_posts( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$posts = get_posts(
			[
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 20,
				'has_password'           => false,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'suppress_filters'       => false,
			]
		);
		$data = [];

		foreach ( $posts as $post ) {
			if ( '' !== $post->post_password ) {
				continue;
			}

			$content = wp_kses_post( apply_filters( 'the_content', $post->post_content ) );
			$excerpt = wp_kses_post( get_the_excerpt( $post ) );
			$image   = get_the_post_thumbnail_url( $post, 'large' );

			$data[] = [
				'id'            => (int) $post->ID,
				'title'         => wp_kses_post( get_the_title( $post ) ),
				'excerpt'       => $excerpt,
				'content'       => $content,
				'permalink'     => $this->safe_url( get_permalink( $post ) ),
				'date'          => get_post_time( DATE_W3C, true, $post ),
				'author'        => sanitize_text_field( get_the_author_meta( 'display_name', (int) $post->post_author ) ),
				'featuredImage' => $this->safe_url( is_string( $image ) ? $image : '' ),
				'contentImages' => $this->get_content_images( $content ),
			];
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Limit URLs exposed by the endpoint to valid absolute web URLs.
	 */
	private function safe_url( string $url ): string {
		$url = esc_url_raw( html_entity_decode( $url, ENT_QUOTES, get_bloginfo( 'charset' ) ), [ 'http', 'https' ] );
		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return in_array( $scheme, [ 'http', 'https' ], true ) ? $url : '';
	}

	/**
	 * Extract image sources after content sanitization and validate every URL.
	 */
	private function get_content_images( string $content ): array {
		$images = [];
		if ( ! preg_match_all( '/<img\\b[^>]*\\bsrc\\s*=\\s*(["\\\'])(.*?)\\1/i', $content, $matches ) ) {
			return $images;
		}

		foreach ( $matches[2] as $url ) {
			$safe_url = $this->safe_url( $url );
			if ( $safe_url && ! in_array( $safe_url, $images, true ) ) {
				$images[] = $safe_url;
			}
		}

		return $images;
	}

	public function render_public_ui(): void {
		if ( is_admin() ) {
			return;
		}
		?>
		<?php if ( PKWT_Native_Features::is_enabled( 'news-player' ) ) : ?>
		<button class="pkwt-reader-launch" type="button" aria-haspopup="dialog" aria-controls="pkwt-reader-dialog">
			<span class="pkwt-reader-launch__icon" aria-hidden="true">&#9654;</span>
			<span><?php esc_html_e( 'News player', 'pk-wordpress-tools' ); ?></span>
		</button>
		<div class="pkwt-reader" id="pkwt-reader-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'News player', 'pk-wordpress-tools' ); ?>" aria-hidden="true" tabindex="-1" hidden>
			<div class="pkwt-reader__frame">
				<header class="pkwt-reader__header">
					<p class="pkwt-reader__eyebrow"><?php esc_html_e( 'Latest stories', 'pk-wordpress-tools' ); ?></p>
					<button class="pkwt-reader__close" type="button" aria-label="<?php esc_attr_e( 'Close news player', 'pk-wordpress-tools' ); ?>"><span aria-hidden="true">&times;</span></button>
				</header>
				<div class="pkwt-reader__status" aria-live="polite"></div>
				<button class="pkwt-reader__retry" type="button" hidden><?php esc_html_e( 'Retry loading posts', 'pk-wordpress-tools' ); ?></button>
				<article class="pkwt-reader__article" aria-busy="false"></article>
				<footer class="pkwt-reader__controls">
					<button class="pkwt-reader__previous" type="button"><?php esc_html_e( 'Previous', 'pk-wordpress-tools' ); ?></button>
					<p class="pkwt-reader__position" aria-live="polite"></p>
					<button class="pkwt-reader__next" type="button"><?php esc_html_e( 'Next', 'pk-wordpress-tools' ); ?></button>
				</footer>
			</div>
		</div>
		<?php endif; ?>
		<?php if ( PKWT_Native_Features::is_enabled( 'reading-progress' ) && is_singular( 'post' ) ) : ?>
			<div class="pkwt-reading-progress" aria-hidden="true"><span class="pkwt-reading-progress__bar"></span></div>
		<?php endif; ?>
		<?php
	}
}

PKWT_Reader::instance()->init();
