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
	const DB_VERSION     = '2';
	const BACKUPS_DB_VERSION_KEY = 'pkwt_snippet_backups_db_version';
	const BACKUPS_DB_VERSION     = '2';
	const PERSONAL_LIBRARY_OPTION = 'pkwt_personal_snippet_library';
	const BACKUP_RETENTION_LIMIT  = 20;

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
		self::create_revisions_table();
		self::create_backups_table();
	}

	/**
	 * Create the backups table once for installations upgraded from older releases.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_KEY ) ) {
			self::create_table();
			self::create_revisions_table();
		}

		if ( self::BACKUPS_DB_VERSION !== get_option( self::BACKUPS_DB_VERSION_KEY ) ) {
			self::create_backups_table();
		}
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
			deleted_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			modified_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY active (active),
			KEY deleted_at (deleted_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Create immutable saved versions of snippets.
	 */
	private static function create_revisions_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'pkwt_snippet_revisions';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			snippet_id bigint(20) unsigned NOT NULL,
			version_number int(10) unsigned NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			code longtext NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY snippet_version (snippet_id,version_number),
			KEY snippet_id (snippet_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the table used for server-side snippet snapshots.
	 */
	private static function create_backups_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'pkwt_snippet_backups';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(191) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			snippet_count int(10) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::BACKUPS_DB_VERSION_KEY, self::BACKUPS_DB_VERSION );
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
	public function get_all_snippets( bool $include_trashed = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		$where = $include_trashed ? '' : 'WHERE deleted_at IS NULL';
		return (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC" );
	}

	/**
	 * Get snippets for one lifecycle view.
	 *
	 * @param string $status all, active, inactive, trash, or revisions.
	 */
	public function get_snippets_by_status( string $status ): array {
		global $wpdb;
		$table     = $wpdb->prefix . 'pkwt_snippets';
		$revisions = $wpdb->prefix . 'pkwt_snippet_revisions';
		$where     = 's.deleted_at IS NULL';

		if ( 'active' === $status ) {
			$where .= ' AND s.active = 1';
		} elseif ( 'inactive' === $status ) {
			$where .= ' AND s.active = 0';
		} elseif ( 'trash' === $status ) {
			$where = 's.deleted_at IS NOT NULL';
		} elseif ( 'revisions' === $status ) {
			$where .= " AND EXISTS ( SELECT 1 FROM {$revisions} r WHERE r.snippet_id = s.id )";
		}

		return (array) $wpdb->get_results( "SELECT s.* FROM {$table} s WHERE {$where} ORDER BY s.modified_at DESC, s.id DESC" );
	}

	/**
	 * Count snippets for lifecycle tabs.
	 *
	 * @return array<string, int>
	 */
	public function get_snippet_counts(): array {
		global $wpdb;
		$table     = $wpdb->prefix . 'pkwt_snippets';
		$revisions = $wpdb->prefix . 'pkwt_snippet_revisions';
		return [
			'all'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL" ),
			'active'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND active = 1" ),
			'inactive'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND active = 0" ),
			'trash'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NOT NULL" ),
			'revisions' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT s.id) FROM {$table} s WHERE s.deleted_at IS NULL AND EXISTS ( SELECT 1 FROM {$revisions} r WHERE r.snippet_id = s.id )" ),
		];
	}

	/**
	 * Get only active snippets.
	 */
	public function get_active_snippets(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE active = %d AND deleted_at IS NULL ORDER BY id ASC", 1 ) );
	}

	/**
	 * Get one snippet by id.
	 */
	public function get_snippet( int $id, bool $include_trashed = false ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		$where = $include_trashed ? '' : 'AND deleted_at IS NULL';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d {$where}", $id ) );
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
			$current = $this->get_snippet( $id );
			if ( ! $current ) {
				return 0;
			}
			$wpdb->query( 'START TRANSACTION' );
			if ( ! $this->create_revision( $current, get_current_user_id() ) ) {
				$wpdb->query( 'ROLLBACK' );
				return 0;
			}
			$ok = (bool) $wpdb->update( $table, $fields, [ 'id' => $id ], [ '%s', '%s', '%s', '%d' ], [ '%d' ] );
			$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
			return $ok ? $id : 0;
		}

		$ok = (bool) $wpdb->insert( $table, $fields, [ '%s', '%s', '%s', '%d' ] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Move a snippet to the recycle bin and deactivate it.
	 */
	public function trash_snippet( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		return false !== $wpdb->update( $table, [ 'active' => 0, 'deleted_at' => current_time( 'mysql', true ) ], [ 'id' => $id, 'deleted_at' => null ], [ '%d', '%s' ], [ '%d', '%s' ] );
	}

	/** Restore a snippet from the recycle bin. */
	public function restore_snippet( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update( $wpdb->prefix . 'pkwt_snippets', [ 'deleted_at' => null ], [ 'id' => $id, 'deleted_at' => null ], [ '%s' ], [ '%d', '%s' ] );
	}

	/** Permanently delete a trashed snippet and all its revisions. */
	public function permanently_delete_snippet( int $id ): bool {
		global $wpdb;
		$snippets  = $wpdb->prefix . 'pkwt_snippets';
		$revisions = $wpdb->prefix . 'pkwt_snippet_revisions';
		$wpdb->query( 'START TRANSACTION' );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$snippets} WHERE id = %d AND deleted_at IS NOT NULL", $id ) );
		if ( ! $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( false === $wpdb->delete( $revisions, [ 'snippet_id' => $id ], [ '%d' ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		$library = get_option( self::PERSONAL_LIBRARY_OPTION, [] );
		if ( is_array( $library ) ) {
			update_option( self::PERSONAL_LIBRARY_OPTION, array_values( array_diff( array_map( 'absint', $library ), [ $id ] ) ) );
		}
		return true;
	}

	/**
	 * Activate / deactivate a snippet.
	 */
	public function toggle_snippet( int $id, bool $active ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippets';
		$current = $this->get_snippet( $id );
		if ( ! $current ) {
			return false;
		}
		$wpdb->query( 'START TRANSACTION' );
		if ( ! $this->create_revision( $current, get_current_user_id() ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$updated = false !== $wpdb->update( $table, [ 'active' => $active ? 1 : 0 ], [ 'id' => $id, 'deleted_at' => null ], [ '%d' ], [ '%d', '%s' ] );
		$wpdb->query( $updated ? 'COMMIT' : 'ROLLBACK' );
		return $updated;
	}

	/** @return object[] Immutable versions, newest first. */
	public function get_revisions( int $snippet_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippet_revisions';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE snippet_id = %d ORDER BY version_number DESC", $snippet_id ) );
	}

	/** Restore a historical version as a new saved version. */
	public function restore_revision( int $snippet_id, int $revision_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippet_revisions';
		$revision = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND snippet_id = %d", $revision_id, $snippet_id ) );
		if ( ! $revision || ! $this->get_snippet( $snippet_id ) ) {
			return false;
		}
		return $this->save_snippet( [
			'id' => $snippet_id, 'name' => $revision->name, 'description' => $revision->description,
			'code' => $revision->code, 'active' => $revision->active, 'created_by' => $user_id,
		] ) === $snippet_id;
	}

	/** Create an immutable copy of the current state before an update. */
	private function create_revision( object $snippet, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippet_revisions';
		$version = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version_number) FROM {$table} WHERE snippet_id = %d", $snippet->id ) ) + 1;
		return (bool) $wpdb->insert( $table, [
			'snippet_id' => $snippet->id, 'version_number' => $version, 'name' => $snippet->name,
			'description' => $snippet->description, 'code' => $snippet->code, 'active' => $snippet->active,
			'created_at' => current_time( 'mysql', true ), 'created_by' => absint( $user_id ),
		], [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d' ] );
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
	 * Store a complete snippet snapshot in the dedicated backups table.
	 *
	 * @param string $label                Optional human-readable label.
	 * @param int    $created_by           User creating the snapshot.
	 * @param int[]  $personal_library_ids Personal-library snippet IDs.
	 * @return int Backup ID, or zero on failure.
	 */
	public function create_backup( string $label, int $created_by, array $personal_library_ids ): int {
		global $wpdb;

		$snippets = $this->get_all_snippets( true );
		$valid_ids = array_map( static fn( object $snippet ): int => (int) $snippet->id, array_filter( $snippets, static fn( object $snippet ): bool => empty( $snippet->deleted_at ) ) );
		$library_ids = array_values( array_intersect( array_values( array_unique( array_filter( array_map( 'absint', $personal_library_ids ) ) ) ), $valid_ids ) );
		$payload = wp_json_encode( [
			'version'          => 2,
			'snippets'         => array_map( static fn( object $snippet ): array => [
				'id'          => (int) $snippet->id,
				'name'        => $snippet->name,
				'description' => $snippet->description,
				'code'        => $snippet->code,
				'active'      => (int) $snippet->active,
				'deleted_at'  => $snippet->deleted_at,
			], $snippets ),
			'revisions'        => array_map( static fn( object $revision ): array => [
				'id' => (int) $revision->id, 'snippet_id' => (int) $revision->snippet_id,
				'version_number' => (int) $revision->version_number, 'name' => $revision->name,
				'description' => $revision->description, 'code' => $revision->code,
				'active' => (int) $revision->active, 'created_at' => $revision->created_at,
				'created_by' => (int) $revision->created_by,
			], $this->get_all_revisions() ),
			'personal_library' => $library_ids,
		] );

		if ( false === $payload ) {
			return 0;
		}

		$table = $wpdb->prefix . 'pkwt_snippet_backups';
		$ok = $wpdb->insert(
			$table,
			[
				'label'         => substr( sanitize_text_field( $label ), 0, 191 ),
				'payload'       => $payload,
				'snippet_count' => count( $snippets ),
				'created_by'    => absint( $created_by ),
				'created_at'    => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%d', '%s' ]
		);

		if ( ! $ok ) {
			return 0;
		}

		$this->enforce_backup_retention();
		return (int) $wpdb->insert_id;
	}

	/** @return object[] All revision rows, for a complete server backup. */
	private function get_all_revisions(): array {
		global $wpdb;
		return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}pkwt_snippet_revisions ORDER BY id ASC" );
	}

	/**
	 * Return recent server-side snapshots, newest first.
	 */
	public function get_recent_backups( int $limit = self::BACKUP_RETENTION_LIMIT ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippet_backups';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, label, snippet_count, created_by, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", max( 1, $limit ) ) );
	}

	/**
	 * Restore a server snapshot, replacing all current snippets and library membership.
	 *
	 * @return int Number of restored snippets, or -1 on failure.
	 */
	public function restore_backup( int $backup_id ): int {
		global $wpdb;
		$backups_table = $wpdb->prefix . 'pkwt_snippet_backups';
		$snippets_table = $wpdb->prefix . 'pkwt_snippets';
		$revisions_table = $wpdb->prefix . 'pkwt_snippet_revisions';
		$backup = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM {$backups_table} WHERE id = %d", $backup_id ) );
		$data = $backup ? json_decode( $backup->payload, true ) : null;

		if ( ! is_array( $data ) || ! isset( $data['snippets'] ) || ! is_array( $data['snippets'] ) ) {
			return -1;
		}

		$items = [];
		foreach ( $data['snippets'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['code'] ) || ! is_string( $item['code'] ) ) {
				return -1;
			}
			$source_id = absint( $item['id'] ?? 0 );
			if ( 0 === $source_id || isset( $items[ $source_id ] ) ) {
				return -1;
			}
			$items[ $source_id ] = [
				'name'        => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
				'code'        => $item['code'],
				'active'      => empty( $item['active'] ) ? 0 : 1,
				'deleted_at'  => empty( $item['deleted_at'] ) ? null : sanitize_text_field( (string) $item['deleted_at'] ),
			];
		}
		$revision_items = [];
		foreach ( $data['revisions'] ?? [] as $revision ) {
			if ( ! is_array( $revision ) || ! isset( $revision['snippet_id'], $revision['version_number'], $revision['code'] ) || ! is_string( $revision['code'] ) ) {
				return -1;
			}
			$source_id = absint( $revision['snippet_id'] );
			$version   = absint( $revision['version_number'] );
			if ( ! isset( $items[ $source_id ] ) || $version < 1 || isset( $revision_items[ $source_id ][ $version ] ) ) {
				return -1;
			}
			$revision_items[ $source_id ][ $version ] = $revision;
		}

		$wpdb->query( 'START TRANSACTION' );
		$success = false !== $wpdb->query( "DELETE FROM {$revisions_table}" ) && false !== $wpdb->query( "DELETE FROM {$snippets_table}" );
		$id_map = [];
		if ( $success ) {
			foreach ( $items as $source_id => $item ) {
				$success = false !== $wpdb->insert( $snippets_table, $item, [ '%s', '%s', '%s', '%d', '%s' ] );
				if ( ! $success ) {
					break;
				}
				$id_map[ $source_id ] = (int) $wpdb->insert_id;
			}
		}
		if ( $success ) {
			foreach ( $revision_items as $source_id => $versions ) {
				foreach ( $versions as $version => $revision ) {
					$success = false !== $wpdb->insert( $revisions_table, [
						'snippet_id' => $id_map[ $source_id ], 'version_number' => $version,
						'name' => sanitize_text_field( (string) ( $revision['name'] ?? '' ) ),
						'description' => sanitize_textarea_field( (string) ( $revision['description'] ?? '' ) ),
						'code' => $revision['code'], 'active' => empty( $revision['active'] ) ? 0 : 1,
						'created_at' => sanitize_text_field( (string) ( $revision['created_at'] ?? current_time( 'mysql', true ) ) ),
						'created_by' => absint( $revision['created_by'] ?? 0 ),
					], [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d' ] );
					if ( ! $success ) {
						break 2;
					}
				}
			}
		}

		if ( ! $success ) {
			$wpdb->query( 'ROLLBACK' );
			return -1;
		}

		$wpdb->query( 'COMMIT' );
		$library = isset( $data['personal_library'] ) && is_array( $data['personal_library'] ) ? $data['personal_library'] : [];
		$restored_library = [];
		foreach ( $library as $source_id ) {
			$source_id = absint( $source_id );
			if ( isset( $id_map[ $source_id ] ) ) {
				$restored_library[] = $id_map[ $source_id ];
			}
		}
		update_option( self::PERSONAL_LIBRARY_OPTION, array_values( array_unique( $restored_library ) ) );

		return count( $items );
	}

	/**
	 * Delete a stored snapshot.
	 */
	public function delete_backup( int $backup_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . 'pkwt_snippet_backups', [ 'id' => $backup_id ], [ '%d' ] );
	}

	/**
	 * Keep only the configured number of newest snapshots.
	 */
	private function enforce_backup_retention(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pkwt_snippet_backups';
		$ids = (array) $wpdb->get_col( "SELECT id FROM {$table} ORDER BY created_at DESC, id DESC" );
		foreach ( array_slice( $ids, self::BACKUP_RETENTION_LIMIT ) as $id ) {
			$wpdb->delete( $table, [ 'id' => absint( $id ) ], [ '%d' ] );
		}
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
