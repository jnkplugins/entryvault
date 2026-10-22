<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * Reporting aggregations for the dashboard.
 *
 * All methods read from `wp_entryvault_submissions` and `wp_entryvault_timeline` and return
 * plain associative arrays (one row per result). All inputs are sanitized and
 * all SQL is built via $wpdb->prepare().
 */
class Reporting {

    /**
     * Counts of submissions per stage in a pipeline, optionally bounded by
     * a created_at date range (inclusive). Returns one row per stage in
     * the pipeline, including stages with zero submissions.
     *
     * @return array<int,array{stage_id:int,stage_name:string,color:string,count:int}>
     */
    public static function counts_by_stage( $pipeline_id, $date_from = null, $date_to = null ): array {
        global $wpdb;

        $pipeline_id = (int) $pipeline_id;
        if ( $pipeline_id <= 0 ) {
            return [];
        }

        $stages_t = $wpdb->prefix . 'entryvault_stages';
        $subs_t   = $wpdb->prefix . 'entryvault_submissions';

        // Date constraints attach to the LEFT JOIN's ON clause so stages
        // with zero matching submissions still appear.
        $join_extra = '';
        $params     = [];
        if ( $date_from ) {
            $join_extra .= ' AND sub.created_at >= %s ';
            $params[]    = self::sanitize_date( $date_from );
        }
        if ( $date_to ) {
            $join_extra .= ' AND sub.created_at <= %s ';
            $params[]    = self::sanitize_date( $date_to, true );
        }
        $params[] = $pipeline_id;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $join_extra is a static SQL fragment holding only %s placeholders; %i identifiers and all values are bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id AS stage_id, s.name AS stage_name, s.color AS color,
                    COUNT(sub.id) AS cnt
             FROM %i s
             LEFT JOIN %i sub ON sub.stage_id = s.id {$join_extra}
             WHERE s.pipeline_id = %d
             GROUP BY s.id, s.name, s.color, s.position
             ORDER BY s.position ASC, s.id ASC",
            array_merge( [ $stages_t, $subs_t ], $params )
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'stage_id'   => (int) ( $r['stage_id'] ?? 0 ),
                'stage_name' => (string) ( $r['stage_name'] ?? '' ),
                'color'      => (string) ( $r['color'] ?? '' ),
                'count'      => (int) ( $r['cnt'] ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * Counts of submissions per source plugin, optionally for one pipeline.
     *
     * The Reports screen asked for all pipelines while every other figure on it
     * followed the pipeline picker, so "This week 21" sat next to "Total 2".
     *
     * @return array<int,array{source:string,count:int}>
     */
    public static function counts_by_source( $date_from = null, $date_to = null, $pipeline_id = null ): array {
        global $wpdb;
        $subs_t = $wpdb->prefix . 'entryvault_submissions';

        $where  = ' WHERE 1=1 ';
        $params = [];
        if ( $pipeline_id !== null && (int) $pipeline_id > 0 ) {
            $where   .= ' AND pipeline_id = %d ';
            $params[] = (int) $pipeline_id;
        }
        if ( $date_from ) {
            $where   .= ' AND created_at >= %s ';
            $params[] = self::sanitize_date( $date_from );
        }
        if ( $date_to ) {
            $where   .= ' AND created_at <= %s ';
            $params[] = self::sanitize_date( $date_to, true );
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is a static SQL fragment holding only %s/%d placeholders; %i identifier and all values are bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT source_plugin AS source, COUNT(*) AS cnt
             FROM %i
             {$where}
             GROUP BY source_plugin
             ORDER BY cnt DESC, source_plugin ASC",
            array_merge( [ $subs_t ], $params )
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'source' => (string) ( $r['source'] ?? '' ),
                // Display name; 'source' stays the stored value for filtering.
                'label'  => Sources::label( (string) ( $r['source'] ?? '' ) ),
                'count'  => (int) ( $r['cnt'] ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * Daily volume of submissions for the last N days, optionally
     * scoped to a pipeline. Includes zero-count days (filled in PHP).
     *
     * @return array<int,array{date:string,count:int}>
     */
    public static function daily_volume( $pipeline_id = null, $days = 30 ): array {
        global $wpdb;
        $subs_t = $wpdb->prefix . 'entryvault_submissions';

        $days = max( 1, min( 365, (int) $days ) );
        $end  = self::current_date();
        $start_ts = strtotime( $end ) - ( ( $days - 1 ) * 86400 );
        $start    = gmdate( 'Y-m-d', $start_ts );

        $where  = ' WHERE created_at >= %s AND created_at <= %s ';
        $params = [ $start . ' 00:00:00', $end . ' 23:59:59' ];
        if ( $pipeline_id ) {
            $where   .= ' AND pipeline_id = %d ';
            $params[] = (int) $pipeline_id;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is a static SQL fragment holding only %s/%d placeholders; %i identifier and all values are bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS cnt
             FROM %i
             {$where}
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            array_merge( [ $subs_t ], $params )
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $by_day = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $by_day[ (string) ( $r['d'] ?? '' ) ] = (int) ( $r['cnt'] ?? 0 );
            }
        }
        $out = [];
        for ( $i = 0; $i < $days; $i++ ) {
            $d     = gmdate( 'Y-m-d', $start_ts + ( $i * 86400 ) );
            $out[] = [
                'date'  => $d,
                'count' => (int) ( $by_day[ $d ] ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * Funnel for a pipeline: ordered stages with counts and conversion %
     * relative to the previous stage. The first stage's `conversion` is null.
     *
     * @return array<int,array{stage_id:int,stage_name:string,color:string,count:int,conversion:?float}>
     */
    public static function funnel( $pipeline_id ): array {
        $stages = self::counts_by_stage( (int) $pipeline_id );
        $out    = [];
        $prev   = null;
        foreach ( $stages as $s ) {
            $conv = null;
            if ( $prev !== null && $prev > 0 ) {
                $conv = round( ( $s['count'] / $prev ) * 100, 2 );
            }
            // When the preceding stage is empty there is nothing to convert FROM,
            // so the rate is undefined, not zero. Reporting 0.0 made a stage that
            // genuinely held records read as "0%" — and because the board lets you
            // move a card straight from New to Qualified, an empty predecessor is
            // routine rather than a corner case. null renders as "—".
            $out[] = array_merge( $s, [ 'conversion' => $conv ] );
            $prev  = $s['count'];
        }
        return $out;
    }

    /**
     * Average hours each submission has spent in each stage, derived from
     * consecutive `stage_changed` timeline events. The "current" stage (open
     * interval) is approximated using NOW() as the end timestamp.
     *
     * @return array<int,array{stage_id:int,stage_name:string,avg_hours:float,sample_size:int}>
     */
    public static function time_in_stage( $pipeline_id ): array {
        global $wpdb;
        $pipeline_id = (int) $pipeline_id;
        if ( $pipeline_id <= 0 ) {
            return [];
        }
        $subs_t  = $wpdb->prefix . 'entryvault_submissions';
        $tl_t    = $wpdb->prefix . 'entryvault_timeline';
        $stage_t = $wpdb->prefix . 'entryvault_stages';

        // Pull all stage_changed events for submissions in this pipeline,
        // plus each submission's created_at + initial stage_id so we can
        // anchor the first interval.
        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.submission_id, t.data, t.created_at, s.created_at AS sub_created_at
                 FROM %i t
                 INNER JOIN %i s ON s.id = t.submission_id
                 WHERE s.pipeline_id = %d AND t.type = %s
                 ORDER BY t.submission_id ASC, t.created_at ASC",
                $tl_t,
                $subs_t,
                $pipeline_id,
                'stage_changed'
            ),
            ARRAY_A
        ) ?: [];

        // Bucket: stage_id => [total_seconds, sample_count]
        $buckets = [];
        $now_ts  = self::current_timestamp();

        // Group events per submission.
        $by_sub = [];
        foreach ( $events as $e ) {
            $by_sub[ (int) $e['submission_id'] ][] = $e;
        }

        foreach ( $by_sub as $sub_id => $list ) {
            // Walk pairs: each event records the new stage at created_at; the
            // duration *in* a stage is from (its entry time) to (next event).
            // We rely on the `data` JSON containing 'from'/'to' if present,
            // else fall back to using the next event's stage as the boundary.
            $count = count( $list );

            // The time before the first move counts too: from the moment the
            // entry arrived until it left its first stage.
            $first      = $list[0];
            $first_data = self::decode_data( $first['data'] ?? '' );
            $first_from = (int) ( $first_data['from_stage_id'] ?? ( $first_data['from'] ?? 0 ) );
            $arrived_ts = strtotime( (string) ( $first['sub_created_at'] ?? '' ) );
            $left_ts    = strtotime( (string) $first['created_at'] );
            if ( $first_from > 0 && $arrived_ts && $left_ts && $left_ts >= $arrived_ts ) {
                if ( ! isset( $buckets[ $first_from ] ) ) {
                    $buckets[ $first_from ] = [ 0, 0 ];
                }
                $buckets[ $first_from ][0] += $left_ts - $arrived_ts;
                $buckets[ $first_from ][1] += 1;
            }

            for ( $i = 0; $i < $count; $i++ ) {
                $cur = $list[ $i ];
                $data = self::decode_data( $cur['data'] ?? '' );
                // Stage moves are stored as to_stage_id. Reading only 'to' meant
                // every row was skipped and the report showed 0 hours / sample 0
                // for every stage, however long entries had waited.
                $stage_id = (int) ( $data['to_stage_id'] ?? ( $data['to'] ?? 0 ) );
                if ( $stage_id <= 0 ) {
                    continue;
                }
                $start_ts = strtotime( (string) $cur['created_at'] );
                if ( ! $start_ts ) {
                    continue;
                }
                $end_ts = ( $i + 1 < $count )
                    ? strtotime( (string) $list[ $i + 1 ]['created_at'] )
                    : $now_ts;
                if ( ! $end_ts || $end_ts < $start_ts ) {
                    continue;
                }
                $secs = $end_ts - $start_ts;
                if ( ! isset( $buckets[ $stage_id ] ) ) {
                    $buckets[ $stage_id ] = [ 0, 0 ];
                }
                $buckets[ $stage_id ][0] += $secs;
                $buckets[ $stage_id ][1] += 1;
            }
        }

        // Resolve stage names for the pipeline.
        $stage_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, position FROM %i WHERE pipeline_id = %d ORDER BY position ASC, id ASC",
                $stage_t,
                $pipeline_id
            ),
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ( $stage_rows as $sr ) {
            $sid    = (int) $sr['id'];
            $bucket = $buckets[ $sid ] ?? [ 0, 0 ];
            $avg_h  = $bucket[1] > 0 ? round( ( $bucket[0] / $bucket[1] ) / 3600, 2 ) : 0.0;
            $out[]  = [
                'stage_id'    => $sid,
                'stage_name'  => (string) ( $sr['name'] ?? '' ),
                'avg_hours'   => $avg_h,
                'sample_size' => (int) $bucket[1],
            ];
        }
        return $out;
    }

    /**
     * Per-owner breakdown (F-28).
     *
     * Aggregates submissions by `owner_user_id`, returning one row per
     * distinct owner (NULL owners collapse into a synthetic "Unassigned"
     * row with owner_user_id = 0). Each row reports total submissions,
     * a per-stage count map, won/lost counts (looked up by case-insensitive
     * stage name), win rate and average hours-in-stage.
     *
     * @param int|null    $pipeline_id Restrict to a single pipeline (optional).
     * @param string|null $date_from   Inclusive start (YYYY-MM-DD).
     * @param string|null $date_to     Inclusive end (YYYY-MM-DD).
     * @return array<int,array{
     *   owner_user_id:int, owner_name:string, owner_email:string,
     *   total:int, by_stage:array<int,int>, won:int, lost:int,
     *   win_rate:?float, avg_time_in_stage_h:float
     * }>
     */
    public static function per_owner( $pipeline_id = null, $date_from = null, $date_to = null ): array {
        global $wpdb;

        $subs_t  = $wpdb->prefix . 'entryvault_submissions';
        $stage_t = $wpdb->prefix . 'entryvault_stages';

        $where  = [ '1=1' ];
        $params = [];
        if ( $pipeline_id ) {
            $where[]  = 'pipeline_id = %d';
            $params[] = (int) $pipeline_id;
        }
        if ( $date_from ) {
            $where[]  = 'created_at >= %s';
            $params[] = self::sanitize_date( $date_from );
        }
        if ( $date_to ) {
            $where[]  = 'created_at <= %s';
            $params[] = self::sanitize_date( $date_to, true );
        }
        $where_sql = implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is a static SQL fragment holding only %s placeholders; %i identifier and all values are bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, owner_user_id, stage_id, pipeline_id
             FROM %i
             WHERE {$where_sql}",
            array_merge( [ $subs_t ], $params )
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        // Map stage_id => [name, pipeline_id] so we can resolve won/lost stages
        // per pipeline using their (case-insensitive) names.
        $stage_rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, pipeline_id FROM %i', $stage_t ), ARRAY_A );
        $stage_info = [];
        $won_stages_by_pl  = [];
        $lost_stages_by_pl = [];
        foreach ( $stage_rows as $sr ) {
            $sid = (int) ( $sr['id'] ?? 0 );
            $pid = (int) ( $sr['pipeline_id'] ?? 0 );
            $nm  = strtolower( trim( (string) ( $sr['name'] ?? '' ) ) );
            $stage_info[ $sid ] = [ 'name' => (string) ( $sr['name'] ?? '' ), 'pipeline_id' => $pid ];
            if ( $nm === 'won' ) {
                $won_stages_by_pl[ $pid ][] = $sid;
            } elseif ( $nm === 'lost' ) {
                $lost_stages_by_pl[ $pid ][] = $sid;
            }
        }

        // Per-submission avg hours in stage from timeline.
        $sub_avg_h = self::avg_time_in_stage_per_submission( $rows );

        // Bucket per owner.
        $buckets = [];
        foreach ( $rows as $r ) {
            $oid_raw = $r['owner_user_id'] ?? null;
            $oid     = ( $oid_raw === null || $oid_raw === '' ) ? 0 : (int) $oid_raw;
            $sid     = (int) ( $r['stage_id'] ?? 0 );
            $pid     = (int) ( $r['pipeline_id'] ?? 0 );

            if ( ! isset( $buckets[ $oid ] ) ) {
                $buckets[ $oid ] = [
                    'total'     => 0,
                    'by_stage'  => [],
                    'won'       => 0,
                    'lost'      => 0,
                    'hours_sum' => 0.0,
                    'hours_n'   => 0,
                ];
            }
            $buckets[ $oid ]['total']++;
            if ( $sid > 0 ) {
                $buckets[ $oid ]['by_stage'][ $sid ] = ( $buckets[ $oid ]['by_stage'][ $sid ] ?? 0 ) + 1;
            }
            if ( in_array( $sid, $won_stages_by_pl[ $pid ] ?? [], true ) ) {
                $buckets[ $oid ]['won']++;
            }
            if ( in_array( $sid, $lost_stages_by_pl[ $pid ] ?? [], true ) ) {
                $buckets[ $oid ]['lost']++;
            }
            $sub_id = (int) ( $r['id'] ?? 0 );
            if ( isset( $sub_avg_h[ $sub_id ] ) ) {
                $buckets[ $oid ]['hours_sum'] += $sub_avg_h[ $sub_id ];
                $buckets[ $oid ]['hours_n']++;
            }
        }

        // Resolve user names/emails.
        $out = [];
        foreach ( $buckets as $oid => $b ) {
            $name  = '';
            $email = '';
            if ( $oid > 0 && function_exists( 'get_user_by' ) ) {
                $user = get_user_by( 'id', $oid );
                if ( $user ) {
                    $name  = (string) ( $user->display_name ?? $user->user_login ?? '' );
                    $email = (string) ( $user->user_email ?? '' );
                }
            }
            if ( $oid === 0 ) {
                $name = 'Unassigned';
            }
            $denom    = $b['won'] + $b['lost'];
            $win_rate = $denom > 0 ? round( $b['won'] / $denom, 4 ) : null;
            $avg_h    = $b['hours_n'] > 0 ? round( $b['hours_sum'] / $b['hours_n'], 2 ) : 0.0;

            $out[] = [
                'owner_user_id'       => (int) $oid,
                'owner_name'          => $name,
                'owner_email'         => $email,
                'total'               => (int) $b['total'],
                'by_stage'            => $b['by_stage'],
                'won'                 => (int) $b['won'],
                'lost'                => (int) $b['lost'],
                'win_rate'            => $win_rate,
                'avg_time_in_stage_h' => $avg_h,
            ];
        }

        // Stable sort: highest total first, then by name.
        usort( $out, static function ( $a, $b ) {
            $cmp = $b['total'] <=> $a['total'];
            if ( $cmp !== 0 ) {
                return $cmp;
            }
            return strcmp( (string) $a['owner_name'], (string) $b['owner_name'] );
        } );

        return $out;
    }

    /**
     * Average hours-in-stage *per submission*, derived from timeline events.
     * For submissions with no `stage_changed` events the value is 0.0.
     *
     * @param array<int,array<string,mixed>> $sub_rows Rows containing 'id'.
     * @return array<int,float> submission_id => avg_hours
     */
    private static function avg_time_in_stage_per_submission( array $sub_rows ): array {
        global $wpdb;
        if ( ! $sub_rows ) {
            return [];
        }
        $ids = [];
        foreach ( $sub_rows as $r ) {
            $sid = (int) ( $r['id'] ?? 0 );
            if ( $sid > 0 ) {
                $ids[ $sid ] = true;
            }
        }
        if ( ! $ids ) {
            return [];
        }
        $tl_t = $wpdb->prefix . 'entryvault_timeline';

        // Pull all stage_changed events for these submissions. We don't IN()
        // because the FakeWpdb doesn't model that — fetch all and filter.
        $events = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT submission_id, created_at FROM %i WHERE type = %s ORDER BY submission_id ASC, created_at ASC",
                $tl_t,
                'stage_changed'
            ),
            ARRAY_A
        );

        $by_sub = [];
        foreach ( $events as $e ) {
            $sid = (int) ( $e['submission_id'] ?? 0 );
            if ( ! isset( $ids[ $sid ] ) ) {
                continue;
            }
            $by_sub[ $sid ][] = (string) ( $e['created_at'] ?? '' );
        }

        $now_ts = self::current_timestamp();
        $out    = [];
        foreach ( $by_sub as $sid => $stamps ) {
            $count = count( $stamps );
            $total = 0;
            $n     = 0;
            for ( $i = 0; $i < $count; $i++ ) {
                $start = strtotime( $stamps[ $i ] );
                if ( ! $start ) {
                    continue;
                }
                $end = ( $i + 1 < $count ) ? strtotime( $stamps[ $i + 1 ] ) : $now_ts;
                if ( ! $end || $end < $start ) {
                    continue;
                }
                $total += ( $end - $start );
                $n++;
            }
            if ( $n > 0 ) {
                $out[ $sid ] = ( $total / $n ) / 3600;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */

    private static function decode_data( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function sanitize_date( $val, bool $end_of_day = false ): string {
        $val = trim( (string) $val );
        // Accept Y-m-d or Y-m-d H:i:s
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
            return $val . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val ) ) {
            return $val;
        }
        // Fallback: today.
        return gmdate( 'Y-m-d' ) . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
    }

    private static function current_date(): string {
        if ( function_exists( 'current_time' ) ) {
            $now = current_time( 'mysql' );
            if ( is_string( $now ) && strlen( $now ) >= 10 ) {
                return substr( $now, 0, 10 );
            }
        }
        return gmdate( 'Y-m-d' );
    }

    private static function current_timestamp(): int {
        if ( function_exists( 'current_time' ) ) {
            $ts = current_time( 'timestamp' );
            if ( is_int( $ts ) ) {
                return $ts;
            }
            $mysql = current_time( 'mysql' );
            if ( is_string( $mysql ) ) {
                $parsed = strtotime( $mysql );
                if ( $parsed ) {
                    return $parsed;
                }
            }
        }
        return time();
    }
}

