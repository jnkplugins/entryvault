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

    private static function timestamp( $value ): int {
        if ( $value === null || $value === '' ) {
            return 0;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }
        $value = (string) $value;
        // Stored datetimes (current_time( 'mysql' )) are site-local and carry no
        // zone; read them in the site timezone, not the server's.
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value ) && function_exists( 'wp_timezone' ) ) {
            $dt = date_create_immutable( $value, wp_timezone() );
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
}
