<?php
/**
 * REST sync endpoint: self-contained plugin update over HTTP.
 *
 * Exposes pk-wordpress-tools/v1/sync/manifest and pk-wordpress-tools/v1/sync
 * to allow a remote agent to push the plugin files (diff-based) using
 * Application Passwords.
 *
 * @package WP_PK_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * PKWT REST Sync.
 */
class PKWT_Sync {

	const NS  = 'pk-wordpress-tools/v1';
	const CAP = 'manage_options';

	/** @var PKWT_Sync|null */
	private static ?PKWT_Sync $instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Wire REST routes.
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route( self::NS, '/sync/manifest', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_manifest' ],
			'permission_callback' => [ $this, 'check_auth' ],
		] );

		register_rest_route( self::NS, '/sync', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'apply_sync' ],
			'permission_callback' => [ $this, 'check_auth' ],
			'args'                => [
				'files'         => [ 'type' => 'array', 'required' => false, 'default' => [] ],
				'delete_paths'  => [ 'type' => 'array', 'required' => false, 'default' => [] ],
				'dry_run'       => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
				'activate'      => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
			],
		] );
	}

	/**
	 * Permission check: requires Application Password authentication + capability.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function check_auth( \WP_REST_Request $req ): bool|\WP_Error {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error(
				'pkwt_rest_forbidden',
				__( 'Vous n\'avez pas la permission de synchroniser ce plugin.', 'pk-wordpress-tools' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * GET manifest: return all plugin files with sha1.
	 */
	public function get_manifest( \WP_REST_Request $req ): \WP_REST_Response {
		$files = [];
		$root  = PKWT_DIR;
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$path = str_replace( $root, '', $f->getPathname() );
			$path = ltrim( str_replace( '\\', '/', $path ), '/' );
			// Skip dotfiles & secrets.
			if ( preg_match( '#(^|/)\.#', $path ) ) {
				continue;
			}
			$files[] = [
				'path' => $path,
				'size' => (int) $f->getSize(),
				'sha1' => sha1_file( $f->getPathname() ),
			];
		}

		$plugin_data = get_plugin_data( PKWT_FILE, false, false );

		return new \WP_REST_Response( [
			'slug'    => plugin_basename( PKWT_FILE ),
			'version' => $plugin_data['Version'] ?? PKWT_VERSION,
			'files'   => $files,
		] );
	}

	/**
	 * POST sync: apply diff (added/modified/deleted files).
	 */
	public function apply_sync( \WP_REST_Request $req ): \WP_REST_Response {
		$files        = (array) $req->get_param( 'files' );
		$delete_paths = (array) $req->get_param( 'delete_paths' );
		$dry_run      = (bool) $req->get_param( 'dry_run' );
		$activate     = (bool) $req->get_param( 'activate' );
		$root          = trailingslashit( PKWT_DIR );

		$written = [];
		$deleted = [];
		$errors  = [];

		// Apply writes.
		foreach ( $files as $item ) {
			$path    = isset( $item['path'] ) ? (string) $item['path'] : '';
			$content = isset( $item['content_b64'] ) ? (string) $item['content_b64'] : '';
			if ( '' === $path ) {
				continue;
			}

			// Reject path traversal / dotfiles / absolute / outside root.
			if ( preg_match( '#(^|/)\.#', $path ) || false !== strpos( $path, '..' ) || 0 === strpos( $path, '/' ) ) {
				$errors[] = [ 'path' => $path, 'reason' => 'forbidden' ];
				continue;
			}
			$full = $root . $path;

			if ( ! $dry_run ) {
				$dir = dirname( $full );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					$errors[] = [ 'path' => $path, 'reason' => 'mkdir_failed' ];
					continue;
				}

				$bytes = base64_decode( $content, true );
				if ( false === $bytes ) {
					$errors[] = [ 'path' => $path, 'reason' => 'base64_invalid' ];
					continue;
				}

				$ok = file_put_contents( $full, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $ok ) {
					$errors[] = [ 'path' => $path, 'reason' => 'write_failed' ];
					continue;
				}
			}
			$written[] = $path;
		}

		// Apply deletes.
		foreach ( $delete_paths as $path ) {
			$path = (string) $path;
			if ( '' === $path || preg_match( '#(^|/)\.#', $path ) || false !== strpos( $path, '..' ) || 0 === strpos( $path, '/' ) ) {
				$errors[] = [ 'path' => $path, 'reason' => 'delete_forbidden' ];
				continue;
			}
			$full = $root . $path;
			if ( ! $dry_run && is_file( $full ) ) {
				@unlink( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$deleted[] = $path;
		}

		// Activate if requested (and not dry-run).
		$activated = false;
		if ( $activate && ! $dry_run ) {
			$basename = plugin_basename( PKWT_FILE );
			if ( ! is_plugin_active( $basename ) ) {
				$result = activate_plugin( $basename, '', false );
				$activated = ! is_wp_error( $result );
			} else {
				$activated = true;
			}
		}

		return new \WP_REST_Response( [
			'dry_run'    => $dry_run,
			'written'    => $written,
			'deleted'    => $deleted,
			'errors'     => $errors,
			'activated'  => $activated,
			'file_count' => count( $written ),
		] );
	}
}
