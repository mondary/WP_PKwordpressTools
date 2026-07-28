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
	private const MONTH_POST_LIMIT = 200;
	private const REALLOCATION_LIMIT = 500;

	private static ?PKWT_Calendar $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function init(): void {
		if ( PKWT_Native_Features::is_enabled( 'editorial-calendar' ) ) {
			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_calendar_assets' ] );
			add_action( 'wp_ajax_pkwt_calendar_data', [ $this, 'ajax_calendar_data' ] );
			add_action( 'wp_ajax_pkwt_calendar_move_post', [ $this, 'ajax_move_post' ] );
			add_action( 'wp_ajax_pkwt_calendar_update_post', [ $this, 'ajax_update_post' ] );
			add_action( 'wp_ajax_pkwt_calendar_reallocate', [ $this, 'ajax_reallocate' ] );
		}
		if ( PKWT_Native_Features::is_enabled( 'editor-next-free-slot' ) ) {
			add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
			add_action( 'wp_ajax_pkwt_calendar_next_slot', [ $this, 'ajax_next_slot' ] );
		}
	}

	public function register_menu(): void {
		add_submenu_page( 'edit.php', __( 'Calendrier éditorial', 'pk-wordpress-tools' ), __( 'Calendrier', 'pk-wordpress-tools' ), 'edit_posts', self::SLUG, [ $this, 'render_page' ] );
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
				'loading'   => __( 'Chargement du calendrier…', 'pk-wordpress-tools' ),
				'error'     => __( 'Une erreur est survenue.', 'pk-wordpress-tools' ),
				'moved'     => __( 'Déplacés', 'pk-wordpress-tools' ),
				'normalized' => __( 'Normalisés ou décalés', 'pk-wordpress-tools' ),
				'skipped'   => __( 'Ignorés ou en échec', 'pk-wordpress-tools' ),
			],
		] );
	}

	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_enqueue_script( 'pkwt-calendar-editor', PKWT_URL . 'assets/js/calendar-editor.js', [ 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins' ], PKWT_VERSION, true );
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
		$month    = min( 12, max( 1, isset( $_GET['month'] ) ? absint( $_GET['month'] ) : (int) current_time( 'n' ) ) );
		$year     = min( 9999, max( 1970, isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) current_time( 'Y' ) ) );
		$category = isset( $_GET['category'] ) ? absint( $_GET['category'] ) : 0;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<div class="wrap pkwt-calendar-wrap" data-month="<?php echo esc_attr( (string) $month ); ?>" data-year="<?php echo esc_attr( (string) $year ); ?>">
			<h1><?php esc_html_e( 'Calendrier éditorial', 'pk-wordpress-tools' ); ?></h1>
			<?php $this->render_daily_quota(); ?>
			<div class="pkwt-calendar-toolbar">
				<button type="button" class="button" data-calendar-nav="previous">&larr; <?php esc_html_e( 'Précédent', 'pk-wordpress-tools' ); ?></button>
				<label><?php esc_html_e( 'Mois', 'pk-wordpress-tools' ); ?><select class="pkwt-calendar-month" aria-label="<?php esc_attr_e( 'Mois affiché', 'pk-wordpress-tools' ); ?>"><?php for ( $i = 1; $i <= 12; $i++ ) : ?><option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( $month, $i ); ?>><?php echo esc_html( wp_date( 'F', mktime( 0, 0, 0, $i, 1 ) ) ); ?></option><?php endfor; ?></select></label>
				<label><?php esc_html_e( 'Année', 'pk-wordpress-tools' ); ?><input class="pkwt-calendar-year" type="number" min="1970" max="9999" value="<?php echo esc_attr( (string) $year ); ?>"></label>
				<button type="button" class="button" data-calendar-nav="next"><?php esc_html_e( 'Suivant', 'pk-wordpress-tools' ); ?> &rarr;</button>
				<button type="button" class="button" data-calendar-view="add"><?php esc_html_e( 'Ajouter le mois suivant', 'pk-wordpress-tools' ); ?></button>
				<button type="button" class="button" data-calendar-view="year"><?php esc_html_e( 'Vue annuelle', 'pk-wordpress-tools' ); ?></button>
			</div>
			<form class="pkwt-calendar-filters">
				<label><?php esc_html_e( 'Catégorie', 'pk-wordpress-tools' ); ?><?php wp_dropdown_categories( [ 'show_option_all' => __( 'Toutes les catégories', 'pk-wordpress-tools' ), 'hide_empty' => false, 'name' => 'category', 'selected' => $category ] ); ?></label>
				<label><?php esc_html_e( 'Rechercher', 'pk-wordpress-tools' ); ?><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un article', 'pk-wordpress-tools' ); ?>"></label>
				<button class="button"><?php esc_html_e( 'Filtrer', 'pk-wordpress-tools' ); ?></button>
			</form>
			<div class="pkwt-calendar-actions"><button type="button" class="button button-primary" data-open-dialog="pkwt-calendar-reallocate-dialog"><?php esc_html_e( 'Réallouer les articles', 'pk-wordpress-tools' ); ?></button></div>
			<p class="pkwt-calendar-legend"><span class="is-publish"><?php esc_html_e( 'Publié', 'pk-wordpress-tools' ); ?></span><span class="is-future"><?php esc_html_e( 'Planifié', 'pk-wordpress-tools' ); ?></span><span class="is-draft"><?php esc_html_e( 'Brouillon', 'pk-wordpress-tools' ); ?></span><span class="has-image"><?php esc_html_e( 'Image mise en avant', 'pk-wordpress-tools' ); ?></span><span class="no-image"><?php esc_html_e( 'Sans image', 'pk-wordpress-tools' ); ?></span></p>
			<p class="pkwt-calendar-status" role="status" aria-live="polite"></p>
			<div class="pkwt-calendar-months" aria-busy="false"><?php echo $this->render_months( $year, $month, 2, $category, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php $this->render_dialogs(); ?>
		</div>
		<?php
	}

	private function render_daily_quota(): void {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$tomorrow = wp_date( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish', 'future') AND post_date >= %s AND post_date < %s", $today . ' 00:00:00', $tomorrow . ' 00:00:00' ) );
		if ( $count < 5 ) {
			printf( '<p class="pkwt-calendar-quota" role="status">%s</p>', esc_html( sprintf( __( 'Objectif du jour : %1$d/5 articles publiés ou planifiés. Il en manque %2$d.', 'pk-wordpress-tools' ), $count, 5 - $count ) ) );
		}
	}

	private function render_months( int $year, int $month, int $months, int $category, string $search ): string {
		$output = '';
		$date = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), wp_timezone() );
		for ( $i = 0; $i < min( 12, max( 1, $months ) ); $i++ ) {
			$output .= $this->render_month( $date, $category, $search );
			$date = $date->modify( '+1 month' );
		}
		return $output;
	}

	private function render_month( DateTimeImmutable $first, int $category, string $search ): string {
		$posts = $this->get_month_posts( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $category, $search );
		$by_day = [];
		$duplicates = [];
		foreach ( $posts as $post ) {
			$by_day[ (int) mysql2date( 'j', $post->post_date ) ][] = $post;
			if ( 'publish' !== $post->post_status && ( $word = $this->first_word( $post->post_title ) ) ) {
				$duplicates[ $word ] = ( $duplicates[ $word ] ?? 0 ) + 1;
			}
		}
		ob_start();
		$days = (int) $first->format( 't' );
		$offset = (int) $first->format( 'N' ) - 1;
		?>
		<section class="pkwt-calendar-month" aria-labelledby="pkwt-calendar-month-<?php echo esc_attr( $first->format( 'Ym' ) ); ?>">
			<h2 id="pkwt-calendar-month-<?php echo esc_attr( $first->format( 'Ym' ) ); ?>"><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?></h2>
			<div class="pkwt-calendar-grid" role="grid" aria-label="<?php echo esc_attr( wp_date( 'F Y', $first->getTimestamp() ) ); ?>">
				<?php foreach ( [ __( 'Lun', 'pk-wordpress-tools' ), __( 'Mar', 'pk-wordpress-tools' ), __( 'Mer', 'pk-wordpress-tools' ), __( 'Jeu', 'pk-wordpress-tools' ), __( 'Ven', 'pk-wordpress-tools' ), __( 'Sam', 'pk-wordpress-tools' ), __( 'Dim', 'pk-wordpress-tools' ) ] as $weekday ) : ?><div class="pkwt-calendar-weekday" role="columnheader"><?php echo esc_html( $weekday ); ?></div><?php endforeach; ?>
				<?php for ( $cell = 0; $cell < $offset + $days; $cell++ ) : $day = $cell - $offset + 1; ?>
					<div class="pkwt-calendar-day <?php echo $day < 1 ? 'is-empty' : ''; ?>" role="gridcell"<?php echo $day > 0 ? ' data-date="' . esc_attr( $first->format( 'Y-m-' ) . sprintf( '%02d', $day ) ) . '"' : ''; ?>>
						<?php if ( $day > 0 ) : ?><strong><?php echo esc_html( (string) $day ); ?></strong><?php foreach ( $by_day[ $day ] ?? [] as $post ) : $this->render_post_card( $post, $duplicates ); endforeach; endif; ?>
					</div>
				<?php endfor; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private function render_post_card( WP_Post $post, array $duplicates ): void {
		$status = in_array( $post->post_status, [ 'publish', 'future', 'draft' ], true ) ? $post->post_status : 'draft';
		$time = mysql2date( get_option( 'time_format' ), $post->post_date );
		$duplicate = 'publish' !== $status && ! empty( $duplicates[ $this->first_word( $post->post_title ) ] ) && $duplicates[ $this->first_word( $post->post_title ) ] > 1;
		$editable = current_user_can( 'edit_post', $post->ID );
		?>
		<article class="pkwt-calendar-post is-<?php echo esc_attr( $status ); ?><?php echo $duplicate ? ' is-duplicate' : ''; ?>"<?php echo $editable ? ' draggable="true"' : ''; ?> data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-title="<?php echo esc_attr( $post->post_title ); ?>" data-date="<?php echo esc_attr( mysql2date( 'Y-m-d', $post->post_date ) ); ?>" data-time="<?php echo esc_attr( mysql2date( 'H:i', $post->post_date ) ); ?>">
			<span class="pkwt-calendar-post__time"><?php echo esc_html( $time ); ?></span>
			<?php if ( has_post_thumbnail( $post ) ) : ?><span class="pkwt-calendar-post__thumbnail"><?php echo get_the_post_thumbnail( $post, [ 40, 40 ], [ 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php else : ?><span class="pkwt-calendar-post__no-image" aria-label="<?php esc_attr_e( 'Sans image mise en avant', 'pk-wordpress-tools' ); ?>">∅</span><?php endif; ?>
			<a class="pkwt-calendar-post__title" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( '(sans titre)', 'pk-wordpress-tools' ) ); ?></a>
			<?php if ( $duplicate ) : ?><span class="pkwt-calendar-post__duplicate"><?php esc_html_e( 'Doublon', 'pk-wordpress-tools' ); ?></span><?php endif; ?>
			<div class="pkwt-calendar-post__controls"><a href="<?php echo esc_url( get_preview_post_link( $post ) ); ?>"><?php esc_html_e( 'Aperçu', 'pk-wordpress-tools' ); ?></a><?php if ( $editable ) : ?><button type="button" class="button-link" data-quick-edit><?php esc_html_e( 'Modification rapide', 'pk-wordpress-tools' ); ?></button><button type="button" class="button-link" data-move-date><?php esc_html_e( 'Déplacer à une date', 'pk-wordpress-tools' ); ?></button><?php endif; ?></div>
		</article>
		<?php
	}

	private function first_word( string $title ): string {
		$title = remove_accents( strtolower( trim( wp_strip_all_tags( $title ) ) ) );
		return preg_match( '/[[:alnum:]]+/u', $title, $matches ) ? $matches[0] : '';
	}

	private function get_month_posts( int $year, int $month, int $category, string $search ): array {
		$first = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$last = ( new DateTimeImmutable( $first, wp_timezone() ) )->modify( '+1 month -1 second' )->format( 'Y-m-d H:i:s' );
		$args = [ 'post_type' => 'post', 'post_status' => [ 'publish', 'future', 'draft' ], 'posts_per_page' => self::MONTH_POST_LIMIT, 'no_found_rows' => true, 'orderby' => 'date', 'order' => 'ASC', 'date_query' => [ [ 'after' => $first, 'before' => $last, 'inclusive' => true ] ], 's' => $search, 'ignore_sticky_posts' => true ];
		if ( $category ) {
			$args['cat'] = $category;
		}
		return get_posts( $args );
	}

	public function ajax_calendar_data(): void {
		$this->verify_calendar_request();
		$month = min( 12, max( 1, absint( $_POST['month'] ?? 0 ) ) );
		$year = min( 9999, max( 1970, absint( $_POST['year'] ?? 0 ) ) );
		$months = min( 12, max( 1, absint( $_POST['months'] ?? 2 ) ) );
		$category = absint( $_POST['category'] ?? 0 );
		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		wp_send_json_success( [ 'html' => $this->render_months( $year, $month, $months, $category, $search ) ] );
	}

	public function ajax_move_post(): void {
		$this->verify_calendar_request();
		$post = $this->editable_post( absint( $_POST['postId'] ?? 0 ) );
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$time = sanitize_text_field( wp_unslash( $_POST['time'] ?? mysql2date( 'H:i', $post->post_date ) ) );
		$this->update_post_date( $post, $date, $time );
		wp_send_json_success( [ 'message' => __( 'Article déplacé.', 'pk-wordpress-tools' ) ] );
	}

	public function ajax_update_post(): void {
		$this->verify_calendar_request();
		$post = $this->editable_post( absint( $_POST['postId'] ?? 0 ) );
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$time = sanitize_text_field( wp_unslash( $_POST['time'] ?? '' ) );
		$result = wp_update_post( [ 'ID' => $post->ID, 'post_title' => $title ], true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		$this->update_post_date( $post, $date, $time );
		wp_send_json_success( [ 'message' => __( 'Article mis à jour.', 'pk-wordpress-tools' ) ] );
	}

	private function verify_calendar_request(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'pk-wordpress-tools' ) ], 403 );
		}
	}

	private function editable_post( int $post_id ): WP_Post {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Requête non autorisée.', 'pk-wordpress-tools' ) ], 403 );
		}
		return $post;
	}

	private function update_post_date( WP_Post $post, string $date, string $time ): void {
		$target = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $target || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $target->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			wp_send_json_error( [ 'message' => __( 'Date ou heure invalide.', 'pk-wordpress-tools' ) ], 400 );
		}
		$result = wp_update_post( [ 'ID' => $post->ID, 'post_date' => $target->format( 'Y-m-d H:i:s' ), 'post_date_gmt' => get_gmt_from_date( $target->format( 'Y-m-d H:i:s' ) ) ], true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
	}

	public function ajax_reallocate(): void {
		$this->verify_calendar_request();
		if ( empty( $_POST['confirmed'] ) ) {
			wp_send_json_error( [ 'message' => __( 'La confirmation explicite est requise.', 'pk-wordpress-tools' ) ], 400 );
		}
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'draft' ) );
		$statuses = 'all' === $mode ? [ 'draft', 'future' ] : [ 'draft' ];
		$per_day = min( 5, max( 1, absint( $_POST['perDay'] ?? 1 ) ) );
		$posts = get_posts( [ 'post_type' => 'post', 'post_status' => $statuses, 'posts_per_page' => self::REALLOCATION_LIMIT, 'no_found_rows' => true, 'orderby' => 'date', 'order' => 'ASC', 'ignore_sticky_posts' => true ] );
		$occupied = $this->occupied_slots( wp_list_pluck( $posts, 'ID' ) );
		$results = [ 'moved' => [], 'skipped' => [], 'normalized' => [] ];
		$daily = [];
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				$results['skipped'][] = sprintf( __( '%s : permission insuffisante.', 'pk-wordpress-tools' ), $post->post_title ?: __( '(sans titre)', 'pk-wordpress-tools' ) );
				continue;
			}
			$slot = $this->next_free_slot( $occupied, $daily, $per_day );
			if ( ! $slot ) {
				$results['skipped'][] = sprintf( __( '%s : aucun créneau disponible.', 'pk-wordpress-tools' ), $post->post_title ?: __( '(sans titre)', 'pk-wordpress-tools' ) );
				continue;
			}
			$old = mysql2date( 'Y-m-d H:i', $post->post_date );
			$result = wp_update_post( [ 'ID' => $post->ID, 'post_date' => $slot->format( 'Y-m-d H:i:s' ), 'post_date_gmt' => get_gmt_from_date( $slot->format( 'Y-m-d H:i:s' ) ), 'post_status' => 'future' ], true );
			if ( is_wp_error( $result ) ) {
				$results['skipped'][] = sprintf( __( '%s : mise à jour impossible.', 'pk-wordpress-tools' ), $post->post_title ?: __( '(sans titre)', 'pk-wordpress-tools' ) );
				continue;
			}
			$label = $post->post_title ?: __( '(sans titre)', 'pk-wordpress-tools' );
			$results['moved'][] = $label . ' → ' . $slot->format( 'd/m H:i' );
			if ( $old !== $slot->format( 'Y-m-d H:i' ) ) {
				$results['normalized'][] = $label;
			}
		}
		wp_send_json_success( [ 'message' => sprintf( _n( '%d article réalloué.', '%d articles réalloués.', count( $results['moved'] ), 'pk-wordpress-tools' ), count( $results['moved'] ) ), 'results' => $results ] );
	}

	/** @return array<string, bool> */
	private function occupied_slots( array $exclude_ids = [] ): array {
		global $wpdb;
		$start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$end = wp_date( 'Y-m-d H:i:s', strtotime( '+366 days', current_time( 'timestamp' ) ) );
		$sql = "SELECT ID, post_date FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish', 'future', 'draft') AND post_date >= %s AND post_date < %s ORDER BY post_date ASC LIMIT 5000";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $start, $end ) );
		$excluded = array_flip( array_map( 'absint', $exclude_ids ) );
		$occupied = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $excluded[ (int) $row->ID ] ) ) {
				$occupied[ mysql2date( 'Y-m-d H', $row->post_date ) ] = true;
			}
		}
		return $occupied;
	}

	private function next_free_slot( ?array &$occupied = null, ?array &$daily = null, int $per_day = 5 ): ?DateTimeImmutable {
		$occupied ??= $this->occupied_slots();
		$daily ??= [];
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$day = $now->setTime( 0, 0 );
		for ( $i = 0; $i < 366; $i++, $day = $day->modify( '+1 day' ) ) {
			$day_key = $day->format( 'Y-m-d' );
			foreach ( self::SLOTS as $hour ) {
				$candidate = $day->setTime( $hour, 0 );
				$key = $candidate->format( 'Y-m-d H' );
				if ( $candidate > $now && empty( $occupied[ $key ] ) && ( $daily[ $day_key ] ?? 0 ) < $per_day ) {
					$occupied[ $key ] = true;
					$daily[ $day_key ] = ( $daily[ $day_key ] ?? 0 ) + 1;
					return $candidate;
				}
			}
		}
		return null;
	}

	public function ajax_next_slot(): void {
		$this->verify_calendar_request();
		$slot = $this->next_free_slot();
		wp_send_json_success( [ 'date' => $slot ? $slot->format( 'Y-m-d\TH:i:s' ) : null ] );
	}

	private function render_dialogs(): void {
		?>
		<dialog id="pkwt-calendar-edit-dialog" class="pkwt-calendar-dialog" aria-labelledby="pkwt-calendar-edit-title"><form method="dialog"><header><h2 id="pkwt-calendar-edit-title"><?php esc_html_e( 'Modification rapide', 'pk-wordpress-tools' ); ?></h2><button class="button-link" value="cancel"><?php esc_html_e( 'Fermer', 'pk-wordpress-tools' ); ?></button></header><input type="hidden" name="postId"><label><?php esc_html_e( 'Titre', 'pk-wordpress-tools' ); ?><input name="title" required></label><label><?php esc_html_e( 'Date', 'pk-wordpress-tools' ); ?><input type="date" name="date" required></label><label><?php esc_html_e( 'Heure', 'pk-wordpress-tools' ); ?><input type="time" name="time" required></label><p class="pkwt-dialog-error" role="alert"></p><button class="button button-primary" value="save"><?php esc_html_e( 'Enregistrer', 'pk-wordpress-tools' ); ?></button></form></dialog>
		<dialog id="pkwt-calendar-move-dialog" class="pkwt-calendar-dialog" aria-labelledby="pkwt-calendar-move-title"><form method="dialog"><header><h2 id="pkwt-calendar-move-title"><?php esc_html_e( 'Déplacer à une date', 'pk-wordpress-tools' ); ?></h2><button class="button-link" value="cancel"><?php esc_html_e( 'Fermer', 'pk-wordpress-tools' ); ?></button></header><input type="hidden" name="postId"><input type="hidden" name="time"><label><?php esc_html_e( 'Date', 'pk-wordpress-tools' ); ?><input type="date" name="date" required></label><p class="pkwt-dialog-error" role="alert"></p><button class="button button-primary" value="save"><?php esc_html_e( 'Déplacer', 'pk-wordpress-tools' ); ?></button></form></dialog>
		<dialog id="pkwt-calendar-reallocate-dialog" class="pkwt-calendar-dialog" aria-labelledby="pkwt-calendar-reallocate-title"><form method="dialog"><header><h2 id="pkwt-calendar-reallocate-title"><?php esc_html_e( 'Réallouer aux prochains créneaux', 'pk-wordpress-tools' ); ?></h2><button class="button-link" value="cancel"><?php esc_html_e( 'Fermer', 'pk-wordpress-tools' ); ?></button></header><fieldset><legend><?php esc_html_e( 'Articles concernés', 'pk-wordpress-tools' ); ?></legend><label><input type="radio" name="mode" value="draft" checked> <?php esc_html_e( 'Brouillons uniquement', 'pk-wordpress-tools' ); ?></label><label><input type="radio" name="mode" value="all"> <?php esc_html_e( 'Planifiés et brouillons (compactage)', 'pk-wordpress-tools' ); ?></label></fieldset><label><?php esc_html_e( 'Articles par jour', 'pk-wordpress-tools' ); ?><select name="perDay"><?php for ( $i = 1; $i <= 5; $i++ ) : ?><option value="<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( (string) $i ); ?></option><?php endfor; ?></select></label><label><input type="checkbox" name="confirmed" value="1" required> <?php esc_html_e( 'Je confirme la modification des dates.', 'pk-wordpress-tools' ); ?></label><p class="pkwt-dialog-error" role="alert"></p><button class="button button-primary" value="save"><?php esc_html_e( 'Confirmer la réallocation', 'pk-wordpress-tools' ); ?></button></form></dialog>
		<dialog id="pkwt-calendar-results-dialog" class="pkwt-calendar-dialog" aria-labelledby="pkwt-calendar-results-title"><form method="dialog"><header><h2 id="pkwt-calendar-results-title"><?php esc_html_e( 'Résultat de la réallocation', 'pk-wordpress-tools' ); ?></h2><button class="button-link" value="cancel"><?php esc_html_e( 'Fermer', 'pk-wordpress-tools' ); ?></button></header><div class="pkwt-calendar-results" role="status"></div></form></dialog>
		<?php
	}
}
