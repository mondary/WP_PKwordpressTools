<?php
/**
 * Lab : preset de snippet utilitaire.
 *
 * @package PK_WordPress_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Lab preset catalogue and one-click install.
 */
class PKWT_Lab {

	/** @var PKWT_Lab|null */
	private static ?PKWT_Lab $instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	const ACTIVE_KEY = 'pkwt_active_presets';

	/**
	 * Wire up hooks - execute active preset code globally.
	 */
	public function init(): void {
		add_action( 'after_setup_theme', [ $this, 'run_active_presets' ], 0 );
	}

	/**
	 * Execute every active preset's code.
	 */
	public function run_active_presets(): void {
		$active  = self::get_active();
		$presets = $this->get_presets();

		foreach ( $active as $slug ) {
			if ( ! isset( $presets[ $slug ] ) ) {
				continue;
			}

			$code = $presets[ $slug ]['code'];
			if ( '' === trim( $code ) ) {
				continue;
			}

			try {
				eval( $code );
			} catch ( \Throwable $e ) {
				error_log( sprintf( '[PKWT Lab preset %s] %s', $slug, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Get the list of active preset slugs.
	 */
	public static function get_active(): array {
		$val = get_option( self::ACTIVE_KEY, [] );
		return is_array( $val ) ? $val : [];
	}

	/**
	 * Check if a preset is active.
	 */
	public static function is_active( string $slug ): bool {
		return in_array( $slug, self::get_active(), true );
	}

	/**
	 * Toggle a preset on/off.
	 */
	public static function set_active( string $slug, bool $active ): void {
		$current = self::get_active();
		if ( $active ) {
			if ( ! in_array( $slug, $current, true ) ) {
				$current[] = $slug;
			}
		} else {
			$current = array_values( array_diff( $current, [ $slug ] ) );
		}
		update_option( self::ACTIVE_KEY, $current );
	}

	/**
	 * All available presets.
	 */
	public function get_presets(): array {
		return [
			'publish-missed-scheduled-posts' => [
				'slug'        => 'publish-missed-scheduled-posts',
				'name'        => __( 'Missed Scheduled Posts Publisher', 'pk-wordpress-tools' ),
				'description' => __( 'Toutes les 15 minutes, publie jusqu’à 20 contenus planifiés dont la date est dépassée.', 'pk-wordpress-tools' ),
				'category'    => __( 'Automatisation', 'pk-wordpress-tools' ),
				'code'        => <<<'PHP'
add_action( 'init', function () {
    if ( get_transient( 'pkwt_missed_scheduled_posts_lock' ) ) {
        return;
    }

    set_transient( 'pkwt_missed_scheduled_posts_lock', 1, 15 * MINUTE_IN_SECONDS );

    global $wpdb;
    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_date <= %s ORDER BY post_date ASC LIMIT %d",
            'future',
            current_time( 'mysql' ),
            20
        )
    );

    foreach ( $post_ids as $post_id ) {
        wp_publish_post( (int) $post_id );
    }
} );
PHP,
			],
		];
	}

	/**
	 * Get one preset by slug.
	 */
	public function get_preset( string $slug ): ?array {
		$presets = $this->get_presets();
		return $presets[ $slug ] ?? null;
	}

	/**
	 * Install a preset as a new snippet (inactive by default).
	 *
	 * @return int The new snippet id or 0 on failure.
	 */
	public function install_preset( string $slug ): int {
		$preset = $this->get_preset( $slug );
		if ( ! $preset ) {
			return 0;
		}

		return PKWT_Snippets::instance()->save_snippet( [
			'name'        => sprintf( '[Lab] %s', $preset['name'] ),
			'description' => $preset['description'],
			'code'        => $preset['code'],
			'active'      => 0,
		] );
	}
}
