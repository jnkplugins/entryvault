<?php
namespace EntryVault;

use EntryVault\Historical\Provider_Base;
use EntryVault\Historical\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * What the board's day-one box and the Past entries screen show: which form
 * plugins hold entries that are not on the board yet, how they are read, and
 * the "Report a problem" path for readers that go to the database directly.
 *
 * Owner decisions, 29 Oct 2026: D1 bringing in past entries is free; D2 read
 * through the form plugin's public API where it has one, else its tables,
 * said plainly, marked Experimental, checked first, with a report path.
 */
class Past_Entries {

    public const DISMISS_OPTION = 'entryvault_past_entries_dismissed';
    private const CACHE         = 'entryvault_past_entries_found';
    private const REPORT_LIMIT  = 'entryvault_past_entries_report_';

    /**
     * Every installed form plugin a reader exists for, with its forms and counts.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function found( bool $fresh = false ): array {
        if ( ! $fresh ) {
            $cached = get_transient( self::CACHE );
            // Keyed to this version and the active plugins, so an update or a form plugin
            // switched on or off is seen at once instead of after the 10 minutes.
            if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === self::cache_key() ) {
                return (array) $cached['rows'];
            }
        }
        $out = [];
        foreach ( Providers::all() as $key => $provider ) {
            try {
                $row = self::describe( (string) $key, $provider );
            } catch ( \Throwable $e ) {
                continue;
            }
            if ( $row ) {
                $out[] = $row;
            }
        }
        set_transient( self::CACHE, [ 'key' => self::cache_key(), 'rows' => $out ], 10 * MINUTE_IN_SECONDS );
        return $out;
    }

    /** What a cached count must match to be used. */
    public static function cache_key(): string {
        return ENTRYVAULT_VERSION . '|' . md5( (string) wp_json_encode( (array) get_option( 'active_plugins', [] ) ) );
    }

    /** Forget the cached counts (a run started or finished). */
    public static function flush(): void {
        delete_transient( self::CACHE );
    }

    /** @return array<string,mixed>|null */
    private static function describe( string $key, Provider_Base $provider ): ?array {
        if ( ! $provider->installed() ) {
            return null;
        }
        $row = [
            'key'           => $key,
            'label'         => $provider->label(),
            'access'        => $provider->access(),
            'experimental'  => $provider->is_experimental(),
            'keeps_entries' => $provider->keeps_entries(),
            'schema'        => [ 'ok' => true, 'missing' => [] ],
            'forms'         => [],
            'total'         => 0,
            'pending'       => 0,
        ];
        if ( ! $provider->keeps_entries() || ! $provider->available() ) {
            return $row;
        }
        if ( $provider->is_experimental() ) {
            $row['schema'] = $provider->schema_check();
            if ( empty( $row['schema']['ok'] ) ) {
                return $row;
            }
        }
        foreach ( (array) $provider->forms() as $form ) {
            $id = (int) ( $form['id'] ?? 0 );
            if ( $id <= 0 ) {
                continue;
            }
            $count = $provider->count( $id );
            $state = Backfill::state( $id, $key );
            $done  = 'completed' === ( $state['status'] ?? '' );
            // Entries not on the board yet, matched the way the import matches
            // them (same entry id, or the same entry from a CSV export). The box
            // counted every entry the form held, so after a CSV import of the
            // same export it still offered all 30 again.
            $new = ( $done || 0 === $count ) ? 0 : self::not_on_board( $provider, $key, $id, $count );
            $row['forms'][] = [
                'id'     => $id,
                'title'  => (string) ( $form['title'] ?? '' ),
                'count'  => $count,
                'new'    => $new,
                'status' => (string) ( $state['status'] ?? '' ),
            ];
            $row['total'] += (int) $count;
            $row['pending'] += null === $new ? 1 : $new;
        }
        return $row;
    }

    /** Entries read per form at most when counting what is not on the board yet. */
    public const SCAN_LIMIT = 2000;

    /**
     * How many of a form's entries are not on the board yet: not brought in
     * or captured under their own id, and not there from a CSV export of the
     * form (Import_Dedupe, the same matching Backfill and the CSV import use).
     * Entries the reader skips (spam, drafts) are not counted.
     *
     * Reads at most SCAN_LIMIT entries; past that the rest are counted as new
     * when the form's total is known, else null ("past entries found").
     */
    public static function not_on_board( Provider_Base $provider, string $key, int $form_id, ?int $count = null ): ?int {
        $new    = 0;
        $offset = 0;
        $batch  = 100;
        while ( $offset < self::SCAN_LIMIT ) {
            $rows = $provider->entries( $form_id, $offset, $batch );
            if ( ! is_array( $rows ) || ! $rows ) {
                break;
            }
            foreach ( $rows as $entry ) {
                $payload = is_array( $entry ) && isset( $entry['payload'] ) && is_array( $entry['payload'] ) ? $entry['payload'] : null;
                if ( ! $payload ) {
                    continue;
                }
                $payload['source_plugin'] = $payload['source_plugin'] ?? $key;
                if ( self::on_board( $provider, $payload ) ) {
                    continue;
                }
                $new++;
            }
            $offset += count( $rows );
            if ( count( $rows ) < $batch ) {
                return $new;
            }
        }
        if ( $offset < self::SCAN_LIMIT ) {
            return $new;
        }
        return null === $count ? null : $new + max( 0, $count - $offset );
    }

    /** Is this entry from a form plugin already on the board, by any route? */
    private static function on_board( Provider_Base $provider, array $payload ): bool {
        if ( Submission_Store::find_by_source(
            (string) ( $payload['source_plugin'] ?? '' ),
            (string) ( $payload['source_form_id'] ?? '' ),
            (string) ( $payload['source_entry_id'] ?? '' )
        ) ) {
            return true;
        }
        return $provider->is_already_captured( $payload ) || Import_Dedupe::copy_elsewhere( $payload ) > 0;
    }

    /**
     * Sources whose entries the box offers to bring in: readable, checked, and
     * holding entries not brought in yet.
     *
     * @param array<int,array<string,mixed>> $found
     * @return array<int,array<string,mixed>>
     */
    public static function offerable( array $found ): array {
        return array_values( array_filter( $found, static function ( $s ) {
            return ! empty( $s['keeps_entries'] ) && ! empty( $s['schema']['ok'] ) && (int) $s['pending'] > 0;
        } ) );
    }

    /** Installed form plugins that keep no entries at all (WPForms Lite). */
    public static function keeps_none( array $found ): array {
        return array_values( array_filter( $found, static fn( $s ) => empty( $s['keeps_entries'] ) ) );
    }

    /**
     * What the board's box shows to this user: sources to offer, and form
     * plugins that keep no entries (said honestly, nothing offered). Empty for
     * non-admins and once "Not now" was clicked.
     *
     * @return array{offer:array,none:array}
     */
    public static function board_box(): array {
        $box = [ 'offer' => [], 'none' => [] ];
        if ( ! current_user_can( 'manage_options' ) || self::dismissed() ) {
            return $box;
        }
        $found = self::found();
        $box   = [ 'offer' => self::offerable( $found ), 'none' => self::keeps_none( $found ) ];
        return $box;
    }

    public static function dismissed(): bool {
        return (bool) get_option( self::DISMISS_OPTION, false );
    }

    public static function dismiss(): void {
        update_option( self::DISMISS_OPTION, time(), false );
    }

    /**
     * What a problem report sends: nothing about the entries or the people in
     * them, and not the site address.
     *
     * @return array<string,string>
     */
    public static function report_data( string $key ): array {
        $provider = Providers::get( $key );
        if ( ! $provider ) {
            return [];
        }
        $check = $provider->is_experimental() ? $provider->schema_check() : [ 'ok' => true, 'missing' => [] ];
        $state = [];
        foreach ( (array) ( $provider->available() ? $provider->forms() : [] ) as $form ) {
            $st = Backfill::state( (int) ( $form['id'] ?? 0 ), $key );
            foreach ( (array) ( $st['errors'] ?? [] ) as $err ) {
                // Error codes only: an exception message could quote a value.
                $state[] = strtok( (string) $err, ':' );
            }
        }
        global $wp_version;
        return [
            'Form plugin'         => $key,
            'Form plugin version' => $provider->source_version() !== '' ? $provider->source_version() : 'unknown',
            'Read'                => $provider->access(),
            'EntryVault'          => defined( 'ENTRYVAULT_VERSION' ) ? ENTRYVAULT_VERSION : 'unknown',
            'WordPress'           => (string) $wp_version,
            'PHP'                 => PHP_VERSION,
            'Database check'      => ! empty( $check['ok'] ) ? 'ok' : 'missing: ' . implode( ', ', (array) $check['missing'] ),
            'Last run errors'     => $state ? implode( ', ', array_unique( $state ) ) : 'none',
        ];
    }

    /** The report as plain text, exactly as sent. */
    public static function report_text( string $key ): string {
        $lines = [ 'EntryVault past entries: direct database reading problem', '' ];
        foreach ( self::report_data( $key ) as $k => $v ) {
            $lines[] = $k . ': ' . $v;
        }
        return implode( "\n", $lines );
    }

    /**
     * Send the report, on the owner's click only. Goes the way the Help and
     * feedback page sends: the site's own wp_mail() to the EntryVault team.
     * No entry content, no personal data, no site address.
     *
     * @return string 'sent' | 'limited' | 'failed' | 'unknown'
     */
    public static function send_report( string $key ): string {
        if ( ! self::report_data( $key ) ) {
            return 'unknown';
        }
        $limit = self::REPORT_LIMIT . md5( $key );
        if ( get_transient( $limit ) ) {
            return 'limited';
        }
        /** Where feedback is delivered (shared with the Help and feedback page). */
        $to   = apply_filters( 'entryvault_feedback_recipient', 'support+entryvault@jnkplugins.com' );
        $sent = wp_mail( $to, '[EntryVault] Past entries: ' . $key, self::report_text( $key ), [ 'Content-Type: text/plain; charset=UTF-8' ] );
        set_transient( $limit, 1, HOUR_IN_SECONDS );
        return $sent ? 'sent' : 'failed';
    }
}
