<?php
namespace EntryVault\Historical;

use EntryVault\Adapter_Registry;
use EntryVault\Adapters\Adapter_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the past-entries readers (EntryVault > Past entries).
 *
 * One provider per supported source form plugin. Providers read past entries
 * from the source plugin's storage and normalize them through the matching
 * live-capture adapter's build_normalized(), so historical and live entries
 * share one normalization path.
 */
abstract class Provider_Base {

    /** Source slug — must match the live adapter's slug(). */
    abstract public function source(): string;

    /**
     * Registry key. Usually the source slug; differs when several providers
     * read one source's entries from different stores (Flamingo and CFDB7 both
     * hold Contact Form 7 messages).
     */
    public function key(): string {
        return $this->source();
    }

    /**
     * Whether this payload is already on the board under a different entry id.
     * Only sources whose live capture cannot know the stored row's id (CF7)
     * need this; everyone else is deduped by Sync_Engine on the entry id.
     */
    public function is_already_captured( array $payload ): bool {
        return false;
    }

    /**
     * Content match for is_already_captured(): an entry from the same source and
     * form, received within $window seconds, with the same email, whose field
     * values agree on every field key the two share (at least one).
     */
    protected function matches_existing_entry( array $payload, int $window = 600 ): bool {
        global $wpdb;
        $source  = (string) ( $payload['source_plugin'] ?? '' );
        $form_id = (string) ( $payload['source_form_id'] ?? '' );
        $created = \EntryVault\Dates::timestamp( (string) ( $payload['created_at'] ?? '' ) );
        if ( '' === $source || '' === $form_id || ! $created ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, email FROM {$wpdb->prefix}entryvault_submissions
              WHERE source_plugin = %s AND source_form_id = %s AND source_entry_id <> %s
                AND created_at BETWEEN %s AND %s",
            $source,
            $form_id,
            (string) ( $payload['source_entry_id'] ?? '' ),
            gmdate( 'Y-m-d H:i:s', $created - $window ),
            gmdate( 'Y-m-d H:i:s', $created + $window )
        ), ARRAY_A );
        if ( ! $candidates ) {
            return false;
        }
        $mine = [];
        foreach ( (array) ( $payload['fields'] ?? [] ) as $f ) {
            $v = $f['value'] ?? '';
            $mine[ (string) ( $f['key'] ?? '' ) ] = trim( is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ) );
        }
        $email = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
        foreach ( $candidates as $c ) {
            if ( strtolower( trim( (string) $c['email'] ) ) !== $email ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
            $rows   = $wpdb->get_results( $wpdb->prepare(
                "SELECT field_key, value FROM {$wpdb->prefix}entryvault_submission_data WHERE submission_id = %d",
                (int) $c['id']
            ), ARRAY_A );
            $shared = 0;
            $same   = true;
            foreach ( (array) $rows as $r ) {
                $k = (string) $r['field_key'];
                if ( ! array_key_exists( $k, $mine ) ) {
                    continue;
                }
                $shared++;
                if ( trim( (string) $r['value'] ) !== $mine[ $k ] ) {
                    $same = false;
                    break;
                }
            }
            if ( $same && $shared > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a table exists, including temporary tables (SHOW TABLES does not
     * list those). Errors are suppressed: a missing table is the normal case.
     */
    protected static function table_exists( string $table ): bool {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- probe of a fixed, prefixed table name.
        $ok = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ) );
        $wpdb->suppress_errors( $suppress );
        return false !== $ok;
    }

    /** Read through the form plugin's own public PHP API. */
    public const ACCESS_API = 'api';
    /** Our own plugin (Trinity Forms): we keep both sides in step. */
    public const ACCESS_OWN = 'own';
    /**
     * Read from the form plugin's database tables: it offers no public way to
     * read its entries. Experimental: a form plugin update can change them.
     */
    public const ACCESS_DIRECT = 'direct';

    /**
     * How this reader gets at the entries (owner decision D2, 29 Oct 2026).
     * Defaults to the honest worst case; readers that use a public API say so.
     */
    public function access(): string {
        return self::ACCESS_DIRECT;
    }

    public function is_experimental(): bool {
        return self::ACCESS_DIRECT === $this->access();
    }

    /** The form plugin's version, for problem reports. '' when unknown. */
    public function source_version(): string {
        return '';
    }

    /**
     * Whether the form plugin is installed at all, even when it keeps no
     * entries (WPForms Lite). Defaults to available().
     */
    public function installed(): bool {
        return $this->available();
    }

    /**
     * Whether the form plugin keeps entries we could bring in. False for
     * WPForms Lite, which only emails them.
     */
    public function keeps_entries(): bool {
        return true;
    }

    /**
     * How many entries a form holds, or null when the reader cannot count
     * cheaply (the screen then says "entries found" without a number).
     */
    public function count( int $form_id ): ?int {
        return null;
    }

    /**
     * Tables (without the prefix) and the columns a direct reader relies on.
     *
     * @return array<string,string[]>
     */
    protected function expected_schema(): array {
        return [];
    }

    /**
     * Check, before reading, that the tables and columns this reader relies on
     * are there. Readers through a public API have nothing to check.
     *
     * @return array{ok:bool,missing:string[]}
     */
    public function schema_check(): array {
        global $wpdb;
        $missing = [];
        foreach ( $this->expected_schema() as $table => $columns ) {
            $full = $wpdb->prefix . $table;
            if ( ! self::table_exists( $full ) ) {
                $missing[] = $table;
                continue;
            }
            $suppress = $wpdb->suppress_errors( true );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema probe of a fixed, prefixed table.
            $have = array_map( 'strtolower', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $full ) ) );
            $wpdb->suppress_errors( $suppress );
            foreach ( $columns as $col ) {
                if ( ! in_array( strtolower( $col ), $have, true ) ) {
                    $missing[] = $table . '.' . $col;
                }
            }
        }
        return [ 'ok' => ! $missing, 'missing' => $missing ];
    }

    /** Human-readable source plugin name for the Backfill screen. */
    abstract public function label(): string;

    /** Fully-qualified class name of the matching live-capture adapter. */
    abstract protected function adapter_class(): string;

    /** Whether the source plugin's entry storage is reachable right now. */
    abstract public function available(): bool;

    /**
     * List the source plugin's forms: [ [ 'id' => int, 'title' => string ], ... ]
     */
    abstract public function forms(): array;

    /**
     * Return a batch of historical entries already in normalized form.
     *
     * Each row: [ 'source_entry_id' => string, 'payload' => array ]
     * where payload is the same shape Sync_Engine::ingest() expects.
     *
     * @return array<int,array{source_entry_id:string,payload:array<string,mixed>}>
     */
    abstract public function entries( int $form_id, int $offset, int $limit ): array;

    /**
     * Resolve the live adapter (for build_normalized). Registry first; direct
     * instantiation as fallback for cron/boot-ordering edge cases.
     */
    protected function adapter(): ?Adapter_Base {
        $adapter = Adapter_Registry::instance()->get( $this->source() );
        if ( $adapter instanceof Adapter_Base ) {
            return $adapter;
        }
        $class = $this->adapter_class();
        if ( $class && class_exists( $class ) ) {
            $adapter = new $class();
            return $adapter instanceof Adapter_Base ? $adapter : null;
        }
        return null;
    }

    /**
     * Decode a stored JSON field map (or pass an already-decoded array through).
     */
    protected function decode_json_map( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
