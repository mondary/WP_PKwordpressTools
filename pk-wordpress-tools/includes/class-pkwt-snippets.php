<?php
/**
 * Snippets management: custom DB table, CRUD, global execution, import/export.
 *
 * @package PK_WordPress_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Handles executable PHP snippets stored in a custom table.
 */
class PKWT_Snippets {

	const DB_VERSION_KEY = 'pkwt_snippets_db_version';

	/** @var PKWT_Snippets|null */
	private static ?PKWT_Snippets $instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Activation: create the snippets table.
	 */
	public static function activate(): void {
		self::create_table();
	}

	/**
	 * Create the wp_pkwt_snippets table.
	 */
	private static function create_table(): void {
		global $wpdb;

		$table      = $wpdb->prefix . 'pkwt_snippets';
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			code longtext NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			modified_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY active (active)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_KEY, PKWT_VERSION );
	}

	/**
	 * Wire up hooks.
	 */
	public function init(): void {
		// Run active snippets globally (front + back) on after_setup_theme.
		add_action( 'after_setup_theme', [ $this, 'run_active_snippets' ], -1 );
	}

	/**
	 * Execute every active snippet in global scope.
	 */
	public function run_active_snippets(): void {
		$snippets = $this->get_active_snippets();
		foreach ( $snippets as $snippet ) {
			$this->run_snippet( $snippet->code, $snippet->name, (int) $snippet->id );
		}
	}

	/**
	 * Run a single snippet's code in a sandboxed eval with error capture.
	 *
	 * @param string $code PHP code (no <?php tags).
	 * @param string $name Snippet name (for logging).
	 * @param int    $id   Snippet id (for logging).
	 */
	private function run_snippet( string $code, string $name, int $id ): void {
		if ( '' === trim( $code ) ) {
			return;
		}

		// Wrap to provide access to common WP globals and disable eval crashes.
		try {
			$result = eval( $code );
			if ( false === $result && error_get_last() ) {
				$this->log_error( $id, $name, error_get_last() );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( $id, $name, [
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			] );
		}
	}

	/**
	 * Log snippet errors to error_log prefixed with snippet info.
	 */
	private function log_error( int $id, string $name, array $err ): void {
		$msg = $err['message'] ?? 'unknown';
		$line = $err['line'] ?? '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[PKWT Snippet #%d %s] %s (%s)', $id, $name, $msg, $line ) );
	}

	/**
	 * Get an array of all snippets (raw rows).
	 */
	public function get_all_snippets(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
	}

	/**
	 * Get only active snippets.
	 */
	public function get_active_snippets(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE active = %d ORDER BY id ASC", 1 ) );
	}

	/**
	 * Get one snippet by id.
	 */
	public function get_snippet( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Insert or update a snippet.
	 *
	 * @param array $data name, description, code, active.
	 * @return int The saved snippet id (0 on failure).
	 */
	public function save_snippet( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';

		$fields = [
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'code'        => (string) ( $data['code'] ?? '' ),
			'active'      => ! empty( $data['active'] ) ? 1 : 0,
		];

		$id = ! empty( $data['id'] ) ? absint( $data['id'] ) : 0;
		if ( '' === trim( $fields['name'] ) ) {
			$fields['name'] = __( 'Sans nom', 'pk-wordpress-tools' );
		}

		if ( $id > 0 ) {
			$ok = (bool) $wpdb->update( $table, $fields, [ 'id' => $id ], [ '%s', '%s', '%s', '%d' ], [ '%d' ] );
			return $ok ? $id : 0;
		}

		$ok = (bool) $wpdb->insert( $table, $fields, [ '%s', '%s', '%s', '%d' ] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Delete a snippet.
	 */
	public function delete_snippet( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Activate / deactivate a snippet.
	 */
	public function toggle_snippet( int $id, bool $active ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return false !== $wpdb->update( $table, [ 'active' => $active ? 1 : 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
	}

	/**
	 * Duplicate a snippet.
	 */
	public function duplicate_snippet( int $id ): int {
		$src = $this->get_snippet( $id );
		if ( ! $src ) {
			return 0;
		}
		return $this->save_snippet( [
			'name'        => sprintf( __( '%s (copie)', 'pk-wordpress-tools' ), $src->name ),
			'description' => $src->description,
			'code'        => $src->code,
			'active'      => 0,
		] );
	}

	/**
	 * Export all snippets as JSON string.
	 */
	public function export_json(): string {
		return (string) wp_json_encode( array_map( static fn( $s ) => [
			'name'        => $s->name,
			'description' => $s->description,
			'code'        => $s->code,
			'active'      => (int) $s->active,
		], $this->get_all_snippets() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Import snippets from a JSON array.
	 *
	 * @return int Number of imported snippets.
	 */
	public function import_json( string $json ): int {
		$data = json_decode( wp_unslash( $json ), true );
		if ( ! is_array( $data ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = $this->save_snippet( [
				'name'        => (string) ( $item['name'] ?? '' ),
				'description' => (string) ( $item['description'] ?? '' ),
				'code'        => (string) ( $item['code'] ?? '' ),
				'active'      => 0, // imported inactive for safety.
			] );
			if ( $id > 0 ) {
				$count++;
			}
		}
		return $count;
	}
}
