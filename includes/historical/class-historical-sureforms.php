<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Sureforms;

defined( 'ABSPATH' ) || exit;

class Historical_Sureforms extends Provider_Base {

    public function source(): string {
        return 'sureforms';
    }

    public function label(): string {
        return 'SureForms';
    }

    /**
     * SureForms has no public entry reader; this uses its internal Entries
     * table class. Check it is still there before reading.
     */
    public function schema_check(): array {
        $class = $this->entries_class();
        $ok    = class_exists( $class ) && method_exists( $class, 'get_all' );
        return [ 'ok' => $ok, 'missing' => $ok ? [] : [ 'SRFM\\Inc\\Database\\Tables\\Entries::get_all' ] ];
    }

    /**
     * SureForms' Entries table class. Filterable so tests can hand in a
     * stand-in with the same static methods.
     */
    private function entries_class(): string {
        /**
         * Filter the SureForms class EntryVault reads past entries with.
         *
         * @param string $class Class name.
         */
        return (string) apply_filters( 'entryvault_sureforms_entries_class', '\\SRFM\\Inc\\Database\\Tables\\Entries' );
    }

    /** Real entries of one form: not in the trash. */
    private static function where( int $form_id ): array {
        return [ [
            [ 'key' => 'form_id', 'compare' => '=', 'value' => $form_id ],
            [ 'key' => 'status', 'compare' => '!=', 'value' => 'trash' ],
        ] ];
    }

    public function source_version(): string {
        return defined( 'SRFM_VER' ) ? (string) SRFM_VER : '';
    }

    protected function adapter_class(): string {
        return Adapter_Sureforms::class;
    }

    public function available(): bool {
        return class_exists( $this->entries_class() );
    }

    public function count( int $form_id ): ?int {
        $class = $this->entries_class();
        if ( ! class_exists( $class ) || ! method_exists( $class, 'get_total_entries_by_status' ) ) {
            return null;
        }
        try {
            // 'all' leaves out the trash, as entries() does.
            return (int) $class::get_total_entries_by_status( 'all', (int) $form_id );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    public function forms(): array {
        $posts = get_posts( [
            'post_type'      => 'sureforms_form',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ] );
        return array_map( static fn( $p ) => [ 'id' => (int) $p->ID, 'title' => $p->post_title ], $posts );
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $adapter = $this->adapter();
        if ( ! $adapter || ! $this->available() ) {
            return [];
        }
        // get_all() reads only "where": the top-level form_id this passed
        // before was ignored, so every form's entries (and the trash) came in
        // under whichever form was picked.
        $class   = $this->entries_class();
        $entries = $class::get_all( [
            'where'   => self::where( (int) $form_id ),
            'limit'   => max( 1, (int) $limit ),
            'offset'  => max( 0, (int) $offset ),
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );
        if ( ! is_array( $entries ) ) {
            return [];
        }
        $out = [];
        foreach ( $entries as $entry ) {
            $entry = (array) $entry;
            $entry_id = (int) ( $entry['ID'] ?? $entry['id'] ?? 0 );
            if ( ! $entry_id ) {
                continue;
            }
            [ $form_data, $key_labels ] = self::live_shape( is_array( $entry['form_data'] ?? null ) ? $entry['form_data'] : [] );
            $payload                    = $adapter->build_normalized(
                (int) $form_id,
                $entry_id,
                $form_data,
                // SureForms writes created_at with current_time( 'mysql' ) (form-
                // submit.php): the site's clock, so convert it to UTC.
                \EntryVault\Dates::to_utc( (string) ( $entry['created_at'] ?? '' ) ) ?: null
            );
            $payload['fields'] = self::fill_labels( (array) ( $payload['fields'] ?? [] ), $key_labels, $form_data );
            $out[] = [
                'source_entry_id' => (string) $entry_id,
                'payload'         => $payload,
            ];
        }
        return $out;
    }

    /**
     * Where the form no longer has a field (the adapter fell back to the
     * humanized slug), use the label SureForms encoded in the stored key.
     */
    public static function fill_labels( array $fields, array $key_labels, array $values ): array {
        $slugs = array_keys( $values );
        foreach ( $fields as $i => $f ) {
            $slug  = (string) ( $slugs[ $i ] ?? '' );
            $human = ucwords( trim( str_replace( [ '-', '_' ], ' ', $slug ) ) );
            if ( '' !== $slug && isset( $key_labels[ $slug ] ) && (string) ( $f['label'] ?? '' ) === $human ) {
                $fields[ $i ]['label'] = $key_labels[ $slug ];
            }
        }
        return $fields;
    }

    /**
     * Turn a stored entry's form_data into the [ slug => value ] map the live
     * srfm_form_submit hook hands the adapter, so past and live cards share
     * field keys, labels and export columns.
     *
     * SureForms stores raw request keys, "srfm-<type>-<block id>-lbl-<base64
     * label>-<slug>"; live data is keyed by the slug after the encoded label
     * (Field_Validation::get_slug_from_key). Keys without "-lbl-" (form-id,
     * captcha, honeypot) are not fields and are left out, as live does.
     * Upload URLs are joined with ", " as live does. When the form no longer
     * has the field, the label carried in the key is used.
     *
     * @param array<string,mixed> $form_data
     * @return array{0:array<string,mixed>,1:array<string,string>} [ slug => value ], [ slug => label from the key ]
     */
    public static function live_shape( array $form_data ): array {
        $values = [];
        $labels = [];
        $encoded = false;
        foreach ( array_keys( $form_data ) as $key ) {
            if ( false !== strpos( (string) $key, '-lbl-' ) ) {
                $encoded = true;
                break;
            }
        }
        if ( ! $encoded ) {
            // Nothing encoded: an older shape already keyed by slug.
            return [ $form_data, [] ];
        }
        foreach ( $form_data as $key => $value ) {
            $key = (string) $key;
            $at  = strpos( $key, '-lbl-' );
            if ( false === $at ) {
                continue;
            }
            $after = substr( $key, $at + 5 );
            $dash  = strpos( $after, '-' );
            if ( false === $dash ) {
                continue;
            }
            $slug = substr( $after, $dash + 1 );
            if ( '' === $slug ) {
                continue;
            }
            $parts = explode( '-', substr( $key, 0, $at ) );
            if ( 'upload' === ( $parts[1] ?? '' ) && is_array( $value ) ) {
                $value = implode( ', ', array_map( 'rawurldecode', array_map( 'strval', $value ) ) );
            }
            $values[ $slug ] = $value;
            $enc             = substr( $after, 0, $dash );
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SureForms' own label encoding.
            $label = '' !== $enc ? base64_decode( $enc . str_repeat( '=', ( 4 - strlen( $enc ) % 4 ) % 4 ), true ) : false;
            if ( is_string( $label ) && '' !== trim( $label ) && wp_check_invalid_utf8( $label ) === $label ) {
                $labels[ $slug ] = trim( html_entity_decode( wp_strip_all_tags( $label ) ) );
            }
        }
        return [ $values, $labels ];
    }
}
