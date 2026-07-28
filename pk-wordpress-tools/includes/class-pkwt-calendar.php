<?php
/**
 * Editorial calendar and Gutenberg scheduling helpers.
 *
 * @package PK_WordPress_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class PKWT_Calendar {

	private const SLUG = 'pkwt-calendar';
	private const NONCE_ACTION = 'pkwt_calendar';
	private const SLOTS = [ 10, 14, 11, 12, 13 ];

	private static ?PKWT_Calendar $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_calendar_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'wp_ajax_pkwt_calendar_move_post', [ $this, 'ajax_move_post' ] );
		add_action( 'wp_ajax_pkwt_calendar_next_slot', [ $this, 'ajax_next_slot' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php',
			__( 'Calendrier éditorial', 'pk-wordpress-tools' ),
			__( 'Calendrier', 'pk-wordpress-tools' ),
			'edit_posts',
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_calendar_assets( string $hook ): void {
		if ( 'posts_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pkwt-calendar', PKWT_URL . 'assets/css/calendar.css', [], PKWT_VERSION );
		wp_enqueue_script( 'pkwt-calendar', PKWT_URL . 'assets/js/calendar.js', [], PKWT_VERSION, true );
		wp_localize_script( 'pkwt-calendar', 'PKWTCalendar', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'    => [
				'moveError' => __( 'Impossible de déplacer cet article.', 'pk-wordpress-tools' ),
				'confirmReallocate' => __( 'Cette action modifiera les dates des articles sélectionnés. Continuer ?', 'pk-wordpress-tools' ),
			],
		] );
	}

	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_enqueue_script(
			'pkwt-calendar-editor',
			PKWT_URL . 'assets/js/calendar-editor.js',
			[ 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins' ],
			PKWT_VERSION,
			true
		);
		wp_localize_script( 'pkwt-calendar-editor', 'PKWTCalendarEditor', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'label'   => __( 'Créneau suggéré par le calendrier', 'pk-wordpress-tools' ),
			'empty'   => __( 'Aucun créneau libre trouvé.', 'pk-wordpress-tools' ),
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'pk-wordpress-tools' ) );
		}
		$this->handle_reallocation();
		$month = isset( $_GET['month'] ) ? absint( $_GET['month'] ) : (int) current_time( 'n' );
		$year  = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) current_time( 'Y' );
		$month = min( 12, max( 1, $month ) );
		$year  = min( 9999, max( 1970, $year ) );
		$category = isset( $_GET['category'] ) ? absint( $_GET['category'] ) : 0;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$posts = $this->get_month_posts( $year, $month, $category, $search );
		$by_day = [];
		foreach ( $posts as $post ) {
			$by_day[ (int) mysql2date( 'j', $post->post_date ) ][] = $post;
		}
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), wp_timezone() );
		$previous = $first->modify( '-1 month' );
		$next = $first->modify( '+1 month' );
		$days = (int) $first->format( 't' );
		$offset = (int) $first->format( 'N' ) - 1;
		?>
		<div class="wrap pkwt-calendar-wrap">
			<h1><?php esc_html_e( 'Calendrier éditorial', 'pk-wordpress-tools' ); ?></h1>
			<?php if ( isset( $_GET['reallocated'] ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( _n( '%d article réalloué.', '%d articles réalloués.', absint( $_GET['reallocated'] ), 'pk-wordpress-tools' ), absint( $_GET['reallocated'] ) ) ); ?></p></div>
			<?php endif; ?>
			<div class="pkwt-calendar-toolbar">
				<a class="button" href="<?php echo esc_url( $this->page_url( $previous, $category, $search ) ); ?>">&larr; <?php esc_html_e( 'Précédent', 'pk-wordpress-tools' ); ?></a>
				<h2><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?></h2>
				<a class="button" href="<?php echo esc_url( $this->page_url( $next, $category, $search ) ); ?>"><?php esc_html_e( 'Suivant', 'pk-wordpress-tools' ); ?> &rarr;</a>
			</div>
			<form class="pkwt-calendar-filters" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="month" value="<?php echo esc_attr( (string) $month ); ?>">
				<input type="hidden" name="year" value="<?php echo esc_attr( (string) $year ); ?>">
				<label><span class="screen-reader-text"><?php esc_html_e( 'Catégorie', 'pk-wordpress-tools' ); ?></span><?php wp_dropdown_categories( [ 'show_option_all' => __( 'Toutes les catégories', 'pk-wordpress-tools' ), 'hide_empty' => false, 'name' => 'category', 'selected' => $category ] ); ?></label>
				<label><span class="screen-reader-text"><?php esc_html_e( 'Rechercher', 'pk-wordpress-tools' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un article', 'pk-wordpress-tools' ); ?>"></label>
				<button class="button"><?php esc_html_e( 'Filtrer', 'pk-wordpress-tools' ); ?></button>
			</form>
			<form class="pkwt-calendar-reallocate" method="post">
				<?php wp_nonce_field( 'pkwt_calendar_reallocate' ); ?>
				<input type="hidden" name="pkwt_calendar_reallocate" value="1">
				<label><input type="checkbox" name="statuses[]" value="draft" checked> <?php esc_html_e( 'Brouillons', 'pk-wordpress-tools' ); ?></label>
				<label><input type="checkbox" name="statuses[]" value="future"> <?php esc_html_e( 'Articles planifiés', 'pk-wordpress-tools' ); ?></label>
				<label><input type="checkbox" name="confirmed" value="1" required> <?php esc_html_e( 'Je confirme la modification des dates.', 'pk-wordpress-tools' ); ?></label>
				<button class="button button-primary"><?php esc_html_e( 'Réallouer aux prochains créneaux', 'pk-wordpress-tools' ); ?></button>
			</form>
			<p class="pkwt-calendar-legend"><span class="is-publish"><?php esc_html_e( 'Publié', 'pk-wordpress-tools' ); ?></span><span class="is-future"><?php esc_html_e( 'Planifié', 'pk-wordpress-tools' ); ?></span><span class="is-draft"><?php esc_html_e( 'Brouillon', 'pk-wordpress-tools' ); ?></span><span>▣ <?php esc_html_e( 'Image mise en avant', 'pk-wordpress-tools' ); ?></span></p>
			<div class="pkwt-calendar-grid" role="grid" aria-label="<?php echo esc_attr( wp_date( 'F Y', $first->getTimestamp() ) ); ?>">
				<?php foreach ( [ __( 'Lun', 'pk-wordpress-tools' ), __( 'Mar', 'pk-wordpress-tools' ), __( 'Mer', 'pk-wordpress-tools' ), __( 'Jeu', 'pk-wordpress-tools' ), __( 'Ven', 'pk-wordpress-tools' ), __( 'Sam', 'pk-wordpress-tools' ), __( 'Dim', 'pk-wordpress-tools' ) ] as $weekday ) : ?><div class="pkwt-calendar-weekday" role="columnheader"><?php echo esc_html( $weekday ); ?></div><?php endforeach; ?>
				<?php for ( $cell = 0; $cell < $offset + $days; $cell++ ) : $day = $cell - $offset + 1; ?>
					<div class="pkwt-calendar-day <?php echo $day < 1 ? 'is-empty' : ''; ?>" role="gridcell"<?php echo $day > 0 ? ' data-date="' . esc_attr( sprintf( '%04d-%02d-%02d', $year, $month, $day ) ) . '"' : ''; ?>>
						<?php if ( $day > 0 ) : ?><strong><?php echo esc_html( (string) $day ); ?></strong><?php foreach ( $by_day[ $day ] ?? [] as $post ) : $this->render_post_card( $post ); endforeach; endif; ?>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}

	private function render_post_card( WP_Post $post ): void {
		$status = in_array( $post->post_status, [ 'publish', 'future', 'draft' ], true ) ? $post->post_status : 'draft';
		$time = mysql2date( get_option( 'time_format' ), $post->post_date );
		?>
		<div class="pkwt-calendar-post is-<?php echo esc_attr( $status ); ?>" draggable="true" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%1$s, %2$s', 'pk-wordpress-tools' ), get_the_title( $post ), $time ) ); ?>">
			<span class="pkwt-calendar-post__time"><?php echo esc_html( $time ); ?></span>
			<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( '(sans titre)', 'pk-wordpress-tools' ) ); ?></a>
			<?php if ( has_post_thumbnail( $post ) ) : ?><span aria-label="<?php esc_attr_e( 'Image mise en avant', 'pk-wordpress-tools' ); ?>">▣</span><?php endif; ?>
			<a class="pkwt-calendar-post__preview" href="<?php echo esc_url( get_preview_post_link( $post ) ); ?>"><?php esc_html_e( 'Aperçu', 'pk-wordpress-tools' ); ?></a>
		</div>
		<?php
	}

	private function get_month_posts( int $year, int $month, int $category, string $search ): array {
		$args = [ 'post_type' => 'post', 'post_status' => [ 'publish', 'future', 'draft' ], 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC', 'date_query' => [ [ 'year' => $year, 'month' => $month, 'inclusive' => true ] ], 's' => $search ];
		if ( $category ) {
			$args['cat'] = $category;
		}
		return get_posts( $args );
	}

	private function page_url( DateTimeImmutable $date, int $category, string $search ): string {
		return add_query_arg( array_filter( [ 'page' => self::SLUG, 'month' => $date->format( 'n' ), 'year' => $date->format( 'Y' ), 'category' => $category ?: null, 's' => $search ?: null ] ), admin_url( 'edit.php' ) );
	}

	public function ajax_move_post(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$post_id = isset( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( [ 'message' => __( 'Requête non autorisée.', 'pk-wordpress-tools' ) ], 403 );
		}
		$original = new DateTimeImmutable( $post->post_date, wp_timezone() );
		$target = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $original->format( 'H:i:s' ), wp_timezone() );
		if ( ! $target || $target->format( 'Y-m-d' ) !== $date ) {
			wp_send_json_error( [ 'message' => __( 'Date invalide.', 'pk-wordpress-tools' ) ], 400 );
		}
		$result = wp_update_post( [ 'ID' => $post_id, 'post_date' => $target->format( 'Y-m-d H:i:s' ), 'post_date_gmt' => get_gmt_from_date( $target->format( 'Y-m-d H:i:s' ) ) ], true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success();
	}

	public function ajax_next_slot(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'pk-wordpress-tools' ) ], 403 );
		}
		$slot = $this->next_free_slot();
		wp_send_json_success( [ 'date' => $slot ? $slot->format( 'Y-m-d\TH:i:s' ) : null ] );
	}

	private function next_free_slot( ?array &$occupied = null ): ?DateTimeImmutable {
		if ( null === $occupied ) {
			$occupied = [];
			$items = get_posts( [ 'post_type' => 'post', 'post_status' => [ 'publish', 'future', 'draft' ], 'posts_per_page' => -1, 'fields' => 'ids', 'date_query' => [ [ 'after' => current_time( 'Y-m-d 00:00:00' ), 'inclusive' => true ] ] ] );
			foreach ( $items as $id ) {
				$post = get_post( $id );
				if ( $post ) {
					$occupied[ mysql2date( 'Y-m-d H', $post->post_date ) ] = true;
				}
			}
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$day = $now->setTime( 0, 0 );
		for ( $i = 0; $i < 366; $i++, $day = $day->modify( '+1 day' ) ) {
			foreach ( self::SLOTS as $hour ) {
				$candidate = $day->setTime( $hour, 0 );
				$key = $candidate->format( 'Y-m-d H' );
				if ( $candidate > $now && empty( $occupied[ $key ] ) ) {
					$occupied[ $key ] = true;
					return $candidate;
				}
			}
		}
		return null;
	}

	private function handle_reallocation(): void {
		if ( empty( $_POST['pkwt_calendar_reallocate'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'pkwt_calendar_reallocate' ) || empty( $_POST['confirmed'] ) ) {
			wp_die( esc_html__( 'Confirmation ou vérification de sécurité manquante.', 'pk-wordpress-tools' ) );
		}
		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? array_intersect( [ 'draft', 'future' ], array_map( 'sanitize_key', wp_unslash( $_POST['statuses'] ) ) ) : [];
		if ( ! $statuses ) {
			return;
		}
		$posts = get_posts( [ 'post_type' => 'post', 'post_status' => $statuses, 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC' ] );
		$occupied = null;
		$count = 0;
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) || ! ( $slot = $this->next_free_slot( $occupied ) ) ) {
				continue;
			}
			if ( ! is_wp_error( wp_update_post( [ 'ID' => $post->ID, 'post_date' => $slot->format( 'Y-m-d H:i:s' ), 'post_date_gmt' => get_gmt_from_date( $slot->format( 'Y-m-d H:i:s' ) ), 'post_status' => 'future' ], true ) ) ) {
				$count++;
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'reallocated' => $count ], admin_url( 'edit.php' ) ) );
		exit;
	}
}
