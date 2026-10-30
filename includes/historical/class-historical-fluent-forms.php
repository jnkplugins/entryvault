<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Fluent_Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Past Fluent Forms entries, read through Fluent's public PHP API
 * (fluentFormApi( 'forms' )), not its tables.
 *
 * The old reader went through wpFluent()'s query builder and cast the result to
 * an array. Fluent 6.2 returns a Collection object there, and (array) on an
 * object gives its properties, so the Backfill screen listed "#0 Form ID 0" and
 * every run failed. The public API pages entries itself and survives Fluent
 * changing its storage.
 */
class Historical_Fluent_Forms extends Provider_Base {

    /** Fluent statuses that are not real leads. */
    private const SKIP_STATUSES = [ 'trashed', 'spam' ];

    public function source(): string {
        return 'fluent-forms';
    }

    public function label(): string {
        return 'Fluent Forms';
    }

    public function access(): string {
        return self::ACCESS_API;
    }

    public function source_version(): string {
        return defined( 'FLUENTFORM_VERSION' ) ? (string) FLUENTFORM_VERSION : '';
    }

    protected function adapter_class(): string {
        return Adapter_Fluent_Forms::class;
    }

    /**
     * Fluent's forms API object, or null when Fluent Forms is not loaded.
     * Filterable so tests can hand in a stand-in.
     *
     * @return object|null
     */
    protected function api() {
        $api = null;
        if ( function_exists( 'fluentFormApi' ) ) {
            try {
                $api = fluentFormApi( 'forms' );
            } catch ( \Throwable $e ) {
                $api = null;
            }
        }
        /**
         * Filter the Fluent Forms API object EntryVault reads past entries with.
         *
         * @param object|null $api fluentFormApi( 'forms' ), or null.
         */
        $api = apply_filters( 'entryvault_fluent_forms_api', $api );
        return is_object( $api ) ? $api : null;
    }

    public function available(): bool {
        $api = $this->api();
        return $api && method_exists( $api, 'forms' ) && method_exists( $api, 'entryInstance' );
    }

    public function forms(): array {
        $api = $this->api();
        if ( ! $api || ! $this->available() ) {
            return [];
        }
        $out  = [];
        $page = 1;
        do {
            $res  = $api->forms( [ 'per_page' => 100, 'page' => $page, 'sort_by' => 'ASC', 'status' => 'all' ] );
            $rows = self::rows( is_array( $res ) ? ( $res['data'] ?? [] ) : [] );
            foreach ( $rows as $form ) {
                $id = (int) self::prop( $form, 'id' );
                if ( $id > 0 ) {
                    $out[] = [ 'id' => $id, 'title' => (string) self::prop( $form, 'title' ) ];
                }
            }
            $last = is_array( $res ) ? (int) ( $res['last_page'] ?? 1 ) : 1;
            $page++;
        } while ( $rows && $page <= $last && $page <= 50 );
        return $out;
    }

    /** How many entries the form holds, trash and spam left out (Fluent's own totals). */
    public function count( int $form_id ): ?int {
        $api = $this->api();
        if ( ! $api || ! $this->available() ) {
            return null;
        }
        try {
            $entries = $api->entryInstance( (int) $form_id );
            $all     = $entries->entries( [ 'per_page' => 1, 'page' => 1 ] );
            if ( ! is_array( $all ) || ! isset( $all['total'] ) ) {
                return null;
            }
            $n = (int) $all['total'];
            foreach ( self::SKIP_STATUSES as $status ) {
                $res = $entries->entries( [ 'per_page' => 1, 'page' => 1, 'entry_type' => $status ] );
                $n  -= is_array( $res ) ? (int) ( $res['total'] ?? 0 ) : 0;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        return max( 0, $n );
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $adapter = $this->adapter();
        $api     = $this->api();
        if ( ! $adapter || ! $api || ! $this->available() ) {
            return [];
        }
        $limit  = max( 1, $limit );
        $offset = max( 0, $offset );
        // Fluent pages by page number. Backfill always asks in whole batches, but a
        // page that does not line up is read from the start and cut.
        if ( 0 === $offset % $limit ) {
            $per_page = $limit;
            $page     = (int) ( $offset / $limit ) + 1;
            $cut      = 0;
        } else {
            $per_page = $offset + $limit;
            $page     = 1;
            $cut      = $offset;
        }
        $res  = $api->entryInstance( (int) $form_id )->entries( [
            'per_page'   => $per_page,
            'page'       => $page,
            'sort_type'  => 'ASC',
            'entry_type' => 'all',
        ] );
        $rows = self::rows( is_array( $res ) ? ( $res['data'] ?? [] ) : [] );
        if ( $cut ) {
            $rows = array_slice( $rows, $cut, $limit );
        }

        $out = [];
        foreach ( $rows as $row ) {
            $entry_id = (int) self::prop( $row, 'id' );
            if ( ! $entry_id ) {
                continue;
            }
            if ( in_array( (string) self::prop( $row, 'status' ), self::SKIP_STATUSES, true ) ) {
                // Read but not brought in: keeps the offset moving.
                $out[] = [ 'source_entry_id' => (string) $entry_id, 'payload' => null ];
                continue;
            }
            $response = self::prop( $row, 'response' );
            if ( is_object( $response ) ) {
                $response = json_decode( (string) wp_json_encode( $response ), true );
            }
            $response = $this->decode_json_map( $response );
            $out[]    = [
                'source_entry_id' => (string) $entry_id,
                'payload'         => $adapter->build_normalized(
                    (int) $form_id,
                    $entry_id,
                    $response,
                    (int) ( self::prop( $row, 'form_id' ) ?: $form_id ),
                    // Fluent Forms writes created_at with current_time( 'mysql' ):
                    // the site's clock, so convert it to UTC.
                    \EntryVault\Dates::to_utc( (string) self::prop( $row, 'created_at' ) ) ?: null
                ),
            ];
        }
        return $out;
    }

    /**
     * A list of rows from whatever Fluent returned: a plain array, or one of its
     * Collection objects (iterable, or with all()).
     *
     * @param mixed $data
     * @return array<int,mixed>
     */
    private static function rows( $data ): array {
        if ( is_array( $data ) ) {
            return array_values( $data );
        }
        if ( $data instanceof \Traversable ) {
            return array_values( iterator_to_array( $data, false ) );
        }
        if ( is_object( $data ) && method_exists( $data, 'all' ) ) {
            $all = $data->all();
            return is_array( $all ) ? array_values( $all ) : [];
        }
        return [];
    }

    /**
     * @param mixed $row
     * @return mixed
     */
    private static function prop( $row, string $key ) {
        if ( is_array( $row ) ) {
            return $row[ $key ] ?? null;
        }
        if ( is_object( $row ) ) {
            return $row->{$key} ?? null;
        }
        return null;
    }
}
