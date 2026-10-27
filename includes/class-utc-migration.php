<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time schema data migration on our own tables.

/**
 * One-time move of stored times from the site's clock to UTC (1.17.0).
 *
 * Before 1.17.0 EntryVault stored current_time( 'mysql' ): the site's wall
 * clock with no zone. A time zone change, a daylight-saving hour or a copy to
 * another site then moved every date, and UTC sources had to be converted on
 * the way in. Now every stored time is UTC and only display converts.
 *
 * How the old rows are converted:
 * - Once, from Activator::maybe_upgrade() (admin_init). Done when the option
 *   entryvault_times_utc is set; a fresh install sets it on activation.
 * - Per table and column, one UPDATE each. The offset comes from a CASE with
 *   one branch per constant-offset stretch of the site zone
 *   (wp_timezone()->getTransitions()), so summer and winter rows on a
 *   daylight-saving site each move by their own offset, and a 100k-row table
 *   converts in one statement instead of a PHP loop.
 * - Progress is saved after each column (entryvault_times_utc_done), so a
 *   timeout resumes at the next column on the next admin page and no column
 *   is shifted twice. One statement per column means a column is either all
 *   converted or not at all.
 * - Rows written by this version before the conversion ran are already UTC.
 *   The first request on the new code records each table's highest id and the
 *   site clock (entryvault_times_utc_marks); only rows at or below the mark are
 *   converted, and times that can change later (updated_at, last_run_at, ...)
 *   only when they are not later than the mark's site time.
 * - Rows that were UTC all along are left alone: Gravity Forms cards (GF stores
 *   UTC and EntryVault kept it as is) and WPForms cards imported by Backfill
 *   (WPForms stores entries.date in UTC), recognised by an exact match with
 *   wpforms_entries.date.
 */
class Utc_Migration {

    public const FLAG     = 'entryvault_times_utc';
    public const MARKS    = 'entryvault_times_utc_marks';
    public const PROGRESS = 'entryvault_times_utc_done';

    /**
     * Columns to convert: table => [ column => mutable ]. Mutable columns can be
     * rewritten on old rows after the upgrade, so they also get the time guard.
     */
    private const COLUMNS = [
        'submissions' => [ 'created_at' => false, 'updated_at' => true ],
        'pipelines'   => [ 'created_at' => false ],
        'timeline'    => [ 'created_at' => false ],
        'emails'      => [ 'sent_at' => false ],
        'inboxes'     => [ 'created_at' => false, 'last_polled_at' => true ],
        'automations' => [ 'created_at' => false, 'updated_at' => true, 'last_run_at' => true ],
        'approvals'   => [ 'created_at' => false, 'reviewed_at' => true ],
    ];

    public static function is_done(): bool {
        return (bool) get_option( self::FLAG );
    }

    /** Fresh install: nothing to convert. */
    public static function mark_fresh(): void {
        update_option( self::FLAG, 1, true );
        delete_option( self::MARKS );
        delete_option( self::PROGRESS );
    }

    /**
     * Note where the old data ends, the first time this version runs on a site
     * that still holds site-time rows. Cheap on every other request: two
     * autoloaded options.
     */
    public static function record_marks(): void {
        if ( self::is_done() || false !== get_option( self::MARKS ) ) {
            return;
        }
        // No version yet: a fresh install, which activation flags as done.
        if ( false === get_option( 'entryvault_version' ) && false === get_option( 'ev_version' ) ) {
            return;
        }
        global $wpdb;
        $ids  = [];
        $prev = $wpdb->suppress_errors( true );
        foreach ( array_keys( self::COLUMNS ) as $t ) {
            $table   = $wpdb->prefix . 'entryvault_' . $t;
            $key     = 'approvals' === $t ? 'submission_id' : 'id';
            $max     = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $key, $table ) );
            $ids[ $t ] = null === $max ? 0 : (int) $max;
        }
        $wpdb->suppress_errors( $prev );
        add_option( self::MARKS, [ 'at' => current_time( 'mysql' ), 'ids' => $ids ], '', true );
    }

    /** admin_init (via Activator::maybe_upgrade): convert what is left, once. */
    public static function maybe_run(): void {
        if ( self::is_done() ) {
            return;
        }
        self::run();
    }

    public static function run(): void {
        global $wpdb;
        self::record_marks();
        $marks = get_option( self::MARKS );
        $marks = is_array( $marks ) ? $marks : [ 'at' => current_time( 'mysql' ), 'ids' => [] ];
        $done  = get_option( self::PROGRESS );
        $done  = is_array( $done ) ? $done : [];

        foreach ( self::COLUMNS as $t => $cols ) {
            foreach ( $cols as $col => $mutable ) {
                $key = $t . '.' . $col;
                if ( in_array( $key, $done, true ) ) {
                    continue;
                }
                self::convert_column( $t, $col, $mutable, $marks );
                $done[] = $key;
                update_option( self::PROGRESS, $done, false );
                /** Fires after each converted column (tests use it to cut a run short). */
                do_action( 'entryvault_times_utc_column_done', $key );
            }
        }

        foreach ( [ 'meta.kanban_seen', 'option.retention', 'option.imap_poll' ] as $key ) {
            if ( in_array( $key, $done, true ) ) {
                continue;
            }
            self::convert_stored_option( $key, (string) $marks['at'] );
            $done[] = $key;
            update_option( self::PROGRESS, $done, false );
        }

        update_option( self::FLAG, 1, true );
        delete_option( self::PROGRESS );
        delete_option( self::MARKS );
        // Replaced by this conversion (an unreleased 1.16.x fix).
        delete_option( 'entryvault_gf_times_fixed' );
    }

    private static function convert_column( string $t, string $col, bool $mutable, array $marks ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'entryvault_' . $t;
        if ( ! self::table_has_column( $table, $col ) ) {
            return;
        }
        $id_col = 'approvals' === $t ? 'submission_id' : 'id';
        // Approval rows are keyed by submission and written when it arrives, so
        // the submissions mark says which ones existed before the upgrade.
        $max_id = (int) ( $marks['ids'][ 'approvals' === $t ? 'submissions' : $t ] ?? 0 );
        if ( $max_id <= 0 ) {
            return;
        }

        // Every value below is either an integer or a fixed identifier; the
        // only user-shaped string (the mark time) goes through prepare().
        $where  = $wpdb->prepare( '%i IS NOT NULL AND %i >= %s AND %i <= %d', $col, $col, '1971-01-01 00:00:00', $id_col, $max_id );
        if ( $mutable ) {
            $where .= $wpdb->prepare( ' AND %i <= %s', $col, (string) $marks['at'] );
        }
        if ( 'submissions' === $t && 'created_at' === $col ) {
            $where .= self::already_utc_exclusion( $table );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built from prepare() above.
        $range = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(%i) AS lo, MAX(%i) AS hi FROM %i WHERE {$where}", $col, $col, $table ), ARRAY_A );
        if ( ! $range || null === $range['lo'] ) {
            return;
        }
        // Site wall-clock values; widen by two days so the range surely covers
        // them whatever the offset.
        $from = (int) strtotime( $range['lo'] . ' UTC' ) - 2 * DAY_IN_SECONDS;
        $to   = (int) strtotime( $range['hi'] . ' UTC' ) + 2 * DAY_IN_SECONDS;

        $col_sql = '`' . str_replace( '`', '', $col ) . '`';
        $offset  = Dates::offset_case_sql( $col_sql, $from, $to, true );
        if ( '0' === $offset ) {
            return; // UTC site: the site clock was UTC already.
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $offset is integers and fixed date literals from Dates::offset_case_sql(); $where built from prepare().
        $wpdb->query( $wpdb->prepare( "UPDATE %i SET {$col_sql} = DATE_SUB({$col_sql}, INTERVAL ({$offset}) SECOND) WHERE {$where}", $table ) );
    }

    /**
     * SQL (" AND ...") that leaves out cards whose created_at was UTC already.
     */
    private static function already_utc_exclusion( string $table ): string {
        global $wpdb;
        $sql = '';
        // Gravity Forms stores UTC and EntryVault kept it unconverted. Only the
        // unreleased 1.16.x fix (flag entryvault_gf_times_fixed) turned them
        // into site time; then they convert like every other card.
        if ( ! get_option( 'entryvault_gf_times_fixed' ) ) {
            $sql .= $wpdb->prepare( ' AND source_plugin <> %s', 'gravity-forms' );
        }
        // Backfill stored WPForms' entries.date (UTC, written with gmdate())
        // unchanged; live captures stored the site clock. An exact match with
        // the WPForms row tells the imported ones apart.
        $wpf = $wpdb->prefix . 'wpforms_entries';
        if ( self::table_has_column( $wpf, 'date' ) ) {
            $sql .= $wpdb->prepare(
                ' AND NOT ( source_plugin = %s AND EXISTS ( SELECT 1 FROM %i w WHERE w.entry_id = source_entry_id AND w.date = created_at ) )',
                'wpforms',
                $wpf
            );
        }
        return $sql;
    }

    /** Times kept in options and user meta, converted the same way. */
    private static function convert_stored_option( string $key, string $mark_at ): void {
        global $wpdb;
        $shift = static function ( $value ) use ( $mark_at ) {
            $value = (string) $value;
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) || $value > $mark_at ) {
                return $value;
            }
            return Dates::to_utc( $value );
        };
        switch ( $key ) {
            case 'meta.kanban_seen':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT umeta_id, user_id, meta_value FROM %i WHERE meta_key LIKE %s',
                    $wpdb->usermeta,
                    $wpdb->esc_like( 'entryvault_kanban_seen_' ) . '%'
                ), ARRAY_A );
                foreach ( (array) $rows as $r ) {
                    $new = $shift( $r['meta_value'] );
                    if ( $new !== $r['meta_value'] ) {
                        $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $new ], [ 'umeta_id' => (int) $r['umeta_id'] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                        wp_cache_delete( (int) $r['user_id'], 'user_meta' );
                    }
                }
                break;
            case 'option.retention':
                $state = get_option( 'entryvault_retention_state' );
                if ( is_array( $state ) && ! empty( $state['last_run'] ) ) {
                    $state['last_run'] = $shift( $state['last_run'] );
                    update_option( 'entryvault_retention_state', $state, false );
                }
                break;
            case 'option.imap_poll':
                $settings = get_option( 'entryvault_settings' );
                if ( is_array( $settings ) && ! empty( $settings['imap_last_poll_at'] ) ) {
                    $settings['imap_last_poll_at'] = $shift( $settings['imap_last_poll_at'] );
                    update_option( 'entryvault_settings', $settings );
                }
                break;
        }
    }

    private static function table_has_column( string $table, string $col ): bool {
        global $wpdb;
        $prev = $wpdb->suppress_errors( true );
        $row  = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $col ) );
        $wpdb->suppress_errors( $prev );
        return (bool) $row;
    }
}
