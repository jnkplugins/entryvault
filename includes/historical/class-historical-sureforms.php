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
        $ok = class_exists( '\\SRFM\\Inc\\Database\\Tables\\Entries' ) && method_exists( '\\SRFM\\Inc\\Database\\Tables\\Entries', 'get_all' );
        return [ 'ok' => $ok, 'missing' => $ok ? [] : [ 'SRFM\\Inc\\Database\\Tables\\Entries::get_all' ] ];
    }

    public function source_version(): string {
        return defined( 'SRFM_VER' ) ? (string) SRFM_VER : '';
    }

    protected function adapter_class(): string {
        return Adapter_Sureforms::class;
    }

    public function available(): bool {
        return class_exists( '\\SRFM\\Inc\\Database\\Tables\\Entries' );
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
        $entries = \SRFM\Inc\Database\Tables\Entries::get_all( [ 'form_id' => (int) $form_id ], false );
        if ( ! is_array( $entries ) ) {
            return [];
        }
        $slice = array_slice( $entries, max( 0, (int) $offset ), max( 1, (int) $limit ) );
        $out   = [];
        foreach ( $slice as $entry ) {
            $entry_id = (int) ( $entry['ID'] ?? $entry['id'] ?? 0 );
            if ( ! $entry_id ) {
                continue;
            }
            $form_data = is_array( $entry['form_data'] ?? null ) ? $entry['form_data'] : [];
            $out[]     = [
                'source_entry_id' => (string) $entry_id,
                'payload'         => $adapter->build_normalized(
                    (int) $form_id,
                    $entry_id,
                    $form_data,
                    // SureForms writes created_at with current_time( 'mysql' ) (form-
                    // submit.php): the site's clock, so convert it to UTC.
                    \EntryVault\Dates::to_utc( (string) ( $entry['created_at'] ?? '' ) ) ?: null
                ),
            ];
        }
        return $out;
    }
}
