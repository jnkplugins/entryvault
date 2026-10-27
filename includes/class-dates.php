<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Human-friendly timestamp formatting shared across the admin UI.
 */
class Dates {

    /**
     * Format a timestamp as a friendly US date/time, optionally with a relative
     * suffix, e.g. "Jul 23, 2026 7:59 PM (13 days ago)".
     *
     * @param int|string|null $value     Unix timestamp, MySQL datetime, or ISO-8601.
     * @param bool            $relative  Append "(… ago)" / "(in …)".
     * @param string          $empty     Returned when the value is empty/unparseable.
     */
    public static function human( $value, bool $relative = true, string $empty = '—' ): string {
        $ts = self::timestamp( $value );
        if ( ! $ts ) {
            return $empty;
        }
        $abs = function_exists( 'wp_date' )
            ? wp_date( 'M j, Y g:i A', $ts )
            : date_i18n( 'M j, Y g:i A', $ts );
        return $relative ? $abs . ' (' . self::relative_from_ts( $ts ) . ')' : $abs;
    }

    /**
     * Compact cell for list-table columns: shows only the relative phrase
     * ("13 days ago") with the full absolute timestamp on hover. Keeps narrow
     * date columns from overflowing/overlapping. Returns escaped HTML.
     */
    public static function relative_cell( $value, string $empty = '—' ): string {
        $ts = self::timestamp( $value );
        if ( ! $ts ) {
            return $empty;
        }
        $abs = function_exists( 'wp_date' )
            ? wp_date( 'M j, Y g:i A', $ts )
            : date_i18n( 'M j, Y g:i A', $ts );
        return '<abbr title="' . esc_attr( $abs ) . '" style="white-space:nowrap">'
            . esc_html( self::relative_from_ts( $ts ) ) . '</abbr>';
    }

    /** Just the relative phrase, e.g. "13 days ago" / "in 3 months". */
    public static function relative( $value ): string {
        $ts = self::timestamp( $value );
        return $ts ? self::relative_from_ts( $ts ) : '';
    }

    private static function relative_from_ts( int $ts ): string {
        $now = time();
        // Timestamps written a moment ago can land a second or two ahead of
        // this clock, and "in 1 second" for something that just happened reads
        // as a bug.
        if ( $ts > $now && $ts - $now <= MINUTE_IN_SECONDS ) {
            return __( 'just now', 'entryvault' );
        }
        return $ts >= $now
            /* translators: %s: human-readable time difference, e.g. "3 hours". */
            ? sprintf( __( 'in %s', 'entryvault' ), human_time_diff( $now, $ts ) )
            /* translators: %s: human-readable time difference, e.g. "3 hours". */
            : sprintf( __( '%s ago', 'entryvault' ), human_time_diff( $ts, $now ) );
    }

    /**
     * Unix timestamp for a stored value. Every datetime EntryVault stores is UTC
     * (since 1.17.0), so a bare "Y-m-d H:i:s" is read as UTC, never in the
     * server's or the site's zone.
     */
    public static function timestamp( $value ): int {
        if ( $value === null || $value === '' ) {
            return 0;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }
        $value = (string) $value;
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value ) ) {
            $dt = date_create_immutable( $value, new \DateTimeZone( 'UTC' ) );
            if ( $dt ) {
                return $dt->getTimestamp();
            }
        }
        $ts = strtotime( $value );
        if ( ! $ts ) {
            $ts = strtotime( preg_replace( '/\.\d+/', '', (string) $value ) );
        }
        return $ts ?: 0;
    }

    /** Now, as stored: UTC "Y-m-d H:i:s". */
    public static function now(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * A stored (UTC) value in site time, "Y-m-d H:i:s", for the values we hand
     * out: REST fields, CSV cells, webhook payloads and merge tags kept the
     * site's clock before 1.17.0 and keep it, so nobody's Zap or sheet moves.
     */
    public static function to_site( $utc ): string {
        $ts = self::timestamp( $utc );
        if ( ! $ts ) {
            return '';
        }
        return wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() );
    }

    /** A site-time "Y-m-d H:i:s" as UTC, for sources that store the site's clock. */
    public static function to_utc( $local ): string {
        $local = trim( (string) $local );
        if ( '' === $local ) {
            return '';
        }
        return (string) get_gmt_from_date( $local, 'Y-m-d H:i:s' );
    }

    /**
     * UTC bounds of a site day typed by the owner ("Y-m-d"): the first and
     * last second of that day on the site's clock, as stored values.
     *
     * @return array{0:string,1:string}
     */
    public static function site_day_bounds_utc( string $ymd ): array {
        return [ self::to_utc( $ymd . ' 00:00:00' ), self::to_utc( $ymd . ' 23:59:59' ) ];
    }

    /** Today on the site's clock, "Y-m-d". */
    public static function site_today(): string {
        return wp_date( 'Y-m-d' );
    }

    /**
     * Outward copy of a row: each named time in site time (as before 1.17.0)
     * plus "<name>_gmt" with the stored UTC value.
     *
     * @param array<string,mixed> $row
     * @param string[]            $keys
     * @return array<string,mixed>
     */
    public static function outward( array $row, array $keys ): array {
        foreach ( $keys as $k ) {
            if ( ! array_key_exists( $k, $row ) ) {
                continue;
            }
            $utc = $row[ $k ];
            if ( null === $utc || '' === $utc ) {
                $row[ $k . '_gmt' ] = $utc;
                continue;
            }
            $row[ $k ]          = self::to_site( (string) $utc );
            $row[ $k . '_gmt' ] = (string) $utc;
        }
        return $row;
    }

    /**
     * The site zone's constant-offset stretches between two Unix times:
     * [ [ from_ts, offset_seconds ], ... ] in order, the first one starting at
     * $from. A fixed-offset site ("UTC+5:30") or a zone without daylight saving
     * is one stretch.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function offset_ranges( int $from, int $to ): array {
        $tz    = wp_timezone();
        $trans = $tz->getTransitions( $from, max( $from, $to ) );
        if ( ! is_array( $trans ) || ! $trans ) {
            return [ [ $from, (int) $tz->getOffset( new \DateTimeImmutable( '@' . $from ) ) ] ];
        }
        $out = [];
        foreach ( $trans as $t ) {
            $off = (int) $t['offset'];
            if ( $out && end( $out )[1] === $off ) {
                continue;
            }
            $out[] = [ max( $from, (int) $t['ts'] ), $off ];
        }
        return $out;
    }

    /**
     * SQL for "the UTC offset in seconds that applied at this stored time", for
     * stored values between $from and $to (Unix times). One branch per
     * constant-offset stretch, so daylight saving is right on both sides.
     *
     * $col must be a trusted column expression. With $col_is_local the column
     * holds site wall-clock times (the pre-1.17.0 data) and the branches are
     * compared on the site's clock; otherwise it holds UTC.
     */
    public static function offset_case_sql( string $col, int $from, int $to, bool $col_is_local = false ): string {
        $ranges = self::offset_ranges( $from, $to );
        if ( count( $ranges ) === 1 ) {
            return (string) (int) $ranges[0][1];
        }
        $sql = 'CASE';
        $n   = count( $ranges );
        for ( $i = 0; $i < $n - 1; $i++ ) {
            $end_ts = $ranges[ $i + 1 ][0];
            // The stretch ends at the next transition. On the site's clock that
            // moment reads end + this stretch's offset; the repeated hour after
            // a fall-back goes to the earlier offset, as PHP's own reading does.
            $edge = gmdate( 'Y-m-d H:i:s', $end_ts + ( $col_is_local ? $ranges[ $i ][1] : 0 ) );
            $sql .= " WHEN {$col} < '{$edge}' THEN " . (int) $ranges[ $i ][1];
        }
        return $sql . ' ELSE ' . (int) $ranges[ $n - 1 ][1] . ' END';
    }
}
