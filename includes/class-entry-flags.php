<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer.

/**
 * Star and read/unread on an entry.
 *
 * Both are per site, not per user: one inbox shared by whoever works the leads,
 * like a shared mailbox. "Read" means somebody opened the entry. (The board's
 * "New" badge stays per user: it is "arrived since you last looked".) Stored
 * on the submissions row (is_starred, read_at in UTC), so filtering and counting
 * stay single-table queries.
 */
class Entry_Flags {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'entryvault_submissions';
	}

	public static function set_starred( int $id, bool $starred ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), [ 'is_starred' => $starred ? 1 : 0 ], [ 'id' => $id ] );
	}

	/**
	 * Mark read (now) or unread. Leaves updated_at alone: opening an entry is
	 * not a change to it.
	 */
	public static function set_read( int $id, bool $read ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), [ 'read_at' => $read ? current_time( 'mysql', true ) : null ], [ 'id' => $id ] );
	}

	/**
	 * @param int[]  $ids
	 * @param string $flag 'read', 'unread', 'star' or 'unstar'.
	 * @return int Rows changed.
	 */
	public static function bulk( array $ids, string $flag ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( $v ) => $v > 0 ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		switch ( $flag ) {
			case 'read':
				$set    = 'read_at = %s';
				$guard  = 'read_at IS NULL';
				$params = [ current_time( 'mysql', true ) ];
				break;
			case 'unread':
				$set    = 'read_at = NULL';
				$guard  = 'read_at IS NOT NULL';
				$params = [];
				break;
			case 'star':
				$set    = 'is_starred = 1';
				$guard  = 'is_starred = 0';
				$params = [];
				break;
			case 'unstar':
				$set    = 'is_starred = 0';
				$guard  = 'is_starred = 1';
				$params = [];
				break;
			default:
				return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $set/$guard are static fragments; $in holds %d placeholders.
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET {$set} WHERE {$guard} AND id IN ({$in})",
			array_merge( [ self::table() ], $params, $ids )
		) );
	}

	/**
	 * Unread open entries per pipeline, limited to what the current user may see.
	 *
	 * @return array<int,int> pipeline_id => count.
	 */
	public static function unread_by_pipeline(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pipeline_id, COUNT(*) AS n FROM %i WHERE read_at IS NULL AND status = 'open' GROUP BY pipeline_id",
			self::table()
		), ARRAY_A );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['pipeline_id'] ] = (int) $r['n'];
		}
		if ( ! Capabilities::current_user_can_manage() ) {
			$viewable = Capabilities::viewable_pipeline_ids_for_current_user();
			$out      = array_intersect_key( $out, array_flip( $viewable ) );
		}
		return $out;
	}

	/** Total unread for the current user's pipelines (the admin menu badge). */
	public static function unread_total(): int {
		return (int) array_sum( self::unread_by_pipeline() );
	}

	/**
	 * Shape a row's flags for the browser.
	 *
	 * @param array<string,mixed> $row
	 * @return array{is_starred:bool,is_read:bool}
	 */
	public static function of( array $row ): array {
		return [
			'is_starred' => ! empty( $row['is_starred'] ),
			'is_read'    => ! empty( $row['read_at'] ),
		];
	}
}
