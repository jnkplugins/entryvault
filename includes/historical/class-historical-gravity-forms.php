<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Gravity_Forms;

defined( 'ABSPATH' ) || exit;

class Historical_Gravity_Forms extends Provider_Base {

    public function source(): string {
        return 'gravity-forms';
    }

    public function label(): string {
        return 'Gravity Forms';
    }

    public function access(): string {
        return self::ACCESS_API;
    }

    public function source_version(): string {
        return class_exists( '\\GFForms' ) && isset( \GFForms::$version ) ? (string) \GFForms::$version : '';
    }

    public function count( int $form_id ): ?int {
        return class_exists( '\\GFAPI' ) ? (int) \GFAPI::count_entries( $form_id, [ 'status' => 'active' ] ) : null;
    }

    protected function adapter_class(): string {
        return Adapter_Gravity_Forms::class;
    }

    public function available(): bool {
        return class_exists( '\\GFAPI' );
    }

    public function forms(): array {
        if ( ! class_exists( '\\GFAPI' ) ) {
            return [];
        }
        $forms = \GFAPI::get_forms();
        $out   = [];
        foreach ( (array) $forms as $form ) {
            $out[] = [
                'id'    => (int) ( $form['id'] ?? 0 ),
                'title' => (string) ( $form['title'] ?? '' ),
            ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $adapter = $this->adapter();
        if ( ! $adapter || ! $this->available() ) {
            return [];
        }
        $form    = \GFAPI::get_form( (int) $form_id );
        $sorting = [ 'key' => 'date_created', 'direction' => 'ASC' ];
        $paging  = [ 'offset' => (int) $offset, 'page_size' => (int) $limit ];
        $entries = \GFAPI::get_entries( (int) $form_id, [], $sorting, $paging );
        if ( is_wp_error( $entries ) || ! is_array( $entries ) ) {
            return [];
        }
        $out = [];
        foreach ( $entries as $entry ) {
            $entry_id = (int) ( $entry['id'] ?? 0 );
            if ( ! $entry_id ) {
                continue;
            }
            $out[] = [
                'source_entry_id' => (string) $entry_id,
                'payload'         => $adapter->build_normalized(
                    (int) $form_id,
                    $entry_id,
                    (array) $entry,
                    is_array( $form ) ? $form : null,
                    // GF stores date_created in UTC, as EntryVault does: kept as is.
                    ! empty( $entry['date_created'] ) ? (string) $entry['date_created'] : null
                ),
            ];
        }
        return $out;
    }
}
