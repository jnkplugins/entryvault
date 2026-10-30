<?php
namespace EntryVault;

use EntryVault\Historical\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Past entries: copies the entries a form plugin already holds onto the board,
 * with their original dates. Free for everyone since 1.20.0 (owner decision D1,
 * 29 Oct 2026); it was the Pro "Historical backfill" before.
 *
 * Public surface:
 *   - start( form_id, source_plugin )         schedules the first batch.
 *   - run( args )                              cron handler; processes batch and re-schedules.
 *   - state( form_id, source )                 returns option payload.
 *   - cancel( form_id, source )                clears the cron event.
 *
 * Progress is mirrored into the option
 *   entryvault_backfill_state_{source}_{form_id}
 *
 * Idempotency: each entry is dispatched through Sync_Engine::ingest(), which
 * deduplicates on (source_plugin, source_form_id, source_entry_id).
 */
class Backfill {

    public const HOOK         = 'entryvault_backfill_run';
    public const BATCH_SIZE   = 50;
    public const OPTION_PREFIX = 'entryvault_backfill_state_';
    /** A batch WP-Cron has not run this long after it was due is run by the page instead. */
    public const STALL_SECONDS = 15;

    public static function option_key( int $form_id, string $source_plugin ): string {
        return self::OPTION_PREFIX . sanitize_key( $source_plugin ) . '_' . (int) $form_id;
    }

    /**
     * Kick off a backfill. Resets state and schedules the first cron tick.
     *
     * @return array<string,mixed> The initial state.
     */
    public static function start( int $form_id, string $source_plugin ): array {
        $form_id       = (int) $form_id;
        $source_plugin = (string) $source_plugin;
        if ( $form_id <= 0 || $source_plugin === '' ) {
            return [];
        }

        $state = [
            'form_id'       => $form_id,
            'source_plugin' => $source_plugin,
            'offset'        => 0,
            'done'          => 0,
            'skipped'       => 0,
            'present'       => 0,
            'total'         => null,
            'started_at'    => current_time( 'mysql', true ),
            'completed_at'  => null,
            'errors'        => [],
            'status'        => 'running',
            'last_batch_at' => time(),
        ];
        update_option( self::option_key( $form_id, $source_plugin ), $state );
        Past_Entries::flush();

        self::schedule_next( [
            'form_id'       => $form_id,
            'source_plugin' => $source_plugin,
            'offset'        => 0,
        ] );

        return $state;
    }

    /**
     * Cron handler. Processes one batch of historical entries.
     *
     * @param array{form_id:int,source_plugin:string,offset:int} $args
     */
    public static function run( $args ): void {
        if ( ! is_array( $args ) ) {
            return;
        }
        $form_id       = (int) ( $args['form_id'] ?? 0 );
        $source_plugin = (string) ( $args['source_plugin'] ?? '' );
        $offset        = max( 0, (int) ( $args['offset'] ?? 0 ) );
        if ( $form_id <= 0 || $source_plugin === '' ) {
            return;
        }

        $key   = self::option_key( $form_id, $source_plugin );
        $state = get_option( $key );
        $state = is_array( $state ) ? $state : [
            'form_id'       => $form_id,
            'source_plugin' => $source_plugin,
            'offset'        => 0,
            'done'          => 0,
            'skipped'       => 0,
            'present'       => 0,
            'total'         => null,
            'started_at'    => current_time( 'mysql', true ),
            'completed_at'  => null,
            'errors'        => [],
            'status'        => 'running',
        ];

        $provider = Providers::get( $source_plugin );
        if ( ! $provider || ! $provider->available() ) {
            $state['status']       = 'failed';
            $state['errors'][]     = 'adapter_unavailable';
            $state['completed_at'] = current_time( 'mysql', true );
            update_option( $key, $state );
            return;
        }

        // A reader that goes straight to the form plugin's tables checks first
        // that they still look the way it expects, and stops with a clear
        // message instead of reading the wrong thing.
        if ( $provider->is_experimental() ) {
            $check = $provider->schema_check();
            if ( empty( $check['ok'] ) ) {
                $state['status']       = 'failed';
                $state['errors'][]     = 'schema_mismatch: ' . implode( ', ', (array) ( $check['missing'] ?? [] ) );
                $state['completed_at'] = current_time( 'mysql', true );
                update_option( $key, $state );
                return;
            }
        }

        $batch = (int) apply_filters( 'entryvault_backfill_batch_size', self::BATCH_SIZE, $form_id, $source_plugin );
        $batch = max( 1, $batch );

        $entries = [];
        try {
            $entries = $provider->entries( $form_id, $offset, $batch );
        } catch ( \Throwable $e ) {
            $state['errors'][] = 'list_error: ' . $e->getMessage();
        }

        if ( ! is_array( $entries ) ) {
            $entries = [];
        }

        $engine    = new Sync_Engine();
        $processed = 0;
        $skipped   = 0;
        // Of the skipped: how many were on the board already (the rest are spam,
        // drafts or unreadable). A re-run said "30 left out (spam, unreadable or
        // already here)" when all 30 were simply here.
        $present = 0;
        /**
         * Filter whether entries brought in by Backfill run "Submission created"
         * automations. Off: those are old leads, and a thank-you rule emailed
         * every one of them (the CSV import already worked this way).
         *
         * @param bool $run Default false.
         */
        $run_rules = (bool) apply_filters( 'entryvault_backfill_runs_automations', false );
        // The same pair of hooks the CSV import fires: Pro's automation engine
        // listens and stays quiet unless run_rules is on. Free has no automations.
        do_action( 'entryvault_import_begin', [ 'run_rules' => $run_rules ] );
        try {
            foreach ( $entries as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $payload = isset( $entry['payload'] ) && is_array( $entry['payload'] ) ? $entry['payload'] : null;
                if ( ! $payload ) {
                    // A row the provider decided not to import (spam, a draft).
                    $skipped++;
                    continue;
                }
                // Make sure source_plugin propagates even when adapter omits it.
                $payload['source_plugin'] = $payload['source_plugin'] ?? $source_plugin;
                // Sources without a shared entry id (Contact Form 7 via Flamingo or
                // CFDB7) may already be on the board from live capture.
                // The same entry may already be here from a CSV export of this
                // form, or as its Trinity Forms import / the entry that import
                // copied (different ids, so Sync_Engine cannot tell).
                if ( $provider->is_already_captured( $payload ) || Import_Dedupe::copy_elsewhere( $payload ) ) {
                    $skipped++;
                    $present++;
                    continue;
                }
                // Count only rows that became new entries. Running the import a
                // second time used to report "2 / 2" brought in while the engine
                // had matched both to entries already on the board.
                try {
                    $result = $engine->ingest_result( $payload );
                    if ( ! empty( $result['created'] ) ) {
                        $processed++;
                    } else {
                        $skipped++;
                        if ( ! empty( $result['id'] ) ) {
                            $present++;
                        }
                    }
                } catch ( \Throwable $e ) {
                    $state['errors'][] = 'ingest_error: ' . $e->getMessage();
                }
            }
        } finally {
            do_action( 'entryvault_import_end' );
        }

        $state['last_batch_at'] = time();
        $state['done']    = (int) ( $state['done'] ?? 0 ) + $processed;
        $state['skipped'] = (int) ( $state['skipped'] ?? 0 ) + $skipped;
        $state['present'] = (int) ( $state['present'] ?? 0 ) + $present;
        // Advance past every row read, imported or not, so a skipped row is not
        // read again on the next batch.
        $state['offset'] = $offset + count( $entries );

        // If the adapter returned a full batch, there may be more to do.
        if ( count( $entries ) >= $batch && ( $processed + $skipped ) > 0 ) {
            $state['status'] = 'running';
            update_option( $key, $state );
            self::schedule_next( [
                'form_id'       => $form_id,
                'source_plugin' => $source_plugin,
                'offset'        => $state['offset'],
            ] );
            return;
        }

        // Done.
        $state['status']       = 'completed';
        $state['total']        = (int) $state['done'];
        $state['completed_at'] = current_time( 'mysql', true );
        update_option( $key, $state );
        Past_Entries::flush();
    }

    /**
     * Run the next batch here when WP-Cron has not.
     *
     * Every batch waited for WP-Cron, so on a host with DISABLE_WP_CRON and no
     * system cron the page said "Running · 0 brought in" for ever. The Backfill
     * page polls the state every 2 seconds while a run is going; that poll now
     * runs a batch itself when the queued one is overdue (at once when WP-Cron
     * is off). The queued event is removed first, so cron and the page never
     * run the same batch, and a short lock stops two open tabs doing so.
     *
     * @return bool True when a batch ran.
     */
    public static function run_if_stalled( int $form_id, string $source_plugin ): bool {
        $state = self::state( $form_id, $source_plugin );
        if ( ( $state['status'] ?? '' ) !== 'running' ) {
            return false;
        }
        /**
         * Filter whether WP-Cron is off on this site (default: DISABLE_WP_CRON).
         *
         * @param bool $off
         */
        $cron_off = (bool) apply_filters( 'entryvault_wp_cron_disabled', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        $last     = (int) ( $state['last_batch_at'] ?? 0 );
        $idle     = $last > 0 ? time() - $last : PHP_INT_MAX;
        if ( ! $cron_off && $idle < self::STALL_SECONDS ) {
            return false;
        }

        $args = [
            'form_id'       => $form_id,
            'source_plugin' => $source_plugin,
            'offset'        => (int) ( $state['offset'] ?? 0 ),
        ];
        $when = wp_next_scheduled( self::HOOK, [ $args ] );
        if ( $when ) {
            if ( ! $cron_off && $when > time() - self::STALL_SECONDS ) {
                return false; // queued and not overdue yet
            }
            wp_unschedule_event( $when, self::HOOK, [ $args ] );
        } elseif ( $idle < 60 ) {
            return false; // WP-Cron took it and may still be running it
        }

        $lock = 'entryvault_backfill_lock_' . md5( self::option_key( $form_id, $source_plugin ) );
        if ( get_transient( $lock ) ) {
            return false;
        }
        set_transient( $lock, 1, MINUTE_IN_SECONDS );
        try {
            self::run( $args );
        } finally {
            delete_transient( $lock );
        }
        return true;
    }

    public static function state( int $form_id, string $source_plugin ): array {
        $val = get_option( self::option_key( $form_id, $source_plugin ) );
        return is_array( $val ) ? $val : [];
    }

    public static function cancel( int $form_id, string $source_plugin ): bool {
        $args = [ [
            'form_id'       => (int) $form_id,
            'source_plugin' => (string) $source_plugin,
            'offset'        => 0,
        ] ];
        // wp_clear_scheduled_hook clears all events for the hook regardless of args.
        wp_clear_scheduled_hook( self::HOOK, $args );
        wp_clear_scheduled_hook( self::HOOK );

        $key   = self::option_key( $form_id, $source_plugin );
        $state = get_option( $key );
        if ( is_array( $state ) ) {
            $state['status']       = 'cancelled';
            $state['completed_at'] = current_time( 'mysql', true );
            update_option( $key, $state );
        }
        return true;
    }

    private static function schedule_next( array $args ): void {
        wp_schedule_single_event( time() + 5, self::HOOK, [ $args ] );
    }
}
