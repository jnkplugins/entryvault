<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Forminator;

defined( 'ABSPATH' ) || exit;

class Historical_Forminator extends Provider_Base {

    public function source(): string {
        return 'forminator';
    }

    public function label(): string {
        return 'Forminator';
    }

    public function access(): string {
        return self::ACCESS_API;
    }

    public function source_version(): string {
        return defined( 'FORMINATOR_VERSION' ) ? (string) FORMINATOR_VERSION : '';
    }

    protected function adapter_class(): string {
        return Adapter_Forminator::class;
    }

    public function available(): bool {
        return class_exists( '\\Forminator_Form_Entry_Model' );
    }

    public function forms(): array {
        if ( ! class_exists( '\\Forminator_API' ) ) {
            return [];
        }
        $forms = \Forminator_API::get_forms( null, 1, -1 );
        $out   = [];
        foreach ( (array) $forms as $form ) {
            $id    = is_object( $form ) ? ( $form->id ?? 0 ) : ( $form['id'] ?? 0 );
            $title = '';
            if ( is_object( $form ) ) {
                $title = (string) ( $form->name ?? ( isset( $form->settings['formName'] ) ? $form->settings['formName'] : '' ) );
            } elseif ( is_array( $form ) ) {
                $title = (string) ( $form['name'] ?? ( $form['settings']['formName'] ?? '' ) );
            }
            $out[] = [ 'id' => (int) $id, 'title' => $title ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $adapter = $this->adapter();
        if ( ! $adapter || ! $this->available() ) {
            return [];
        }
        $form_id = (int) $form_id;
        $limit   = max( 1, (int) $limit );
        $page    = (int) floor( max( 0, (int) $offset ) / $limit ) + 1;
        $entries = \Forminator_Form_Entry_Model::query_entries( $form_id, [
            'per_page' => $limit,
            'page'     => $page,
            'order'    => 'ASC',
        ] );
        if ( ! is_array( $entries ) || empty( $entries ) ) {
            return [];
        }
        $out = [];
        foreach ( $entries as $row ) {
            $entry_id = is_object( $row ) ? (int) ( $row->entry_id ?? 0 ) : (int) ( $row['entry_id'] ?? 0 );
            if ( ! $entry_id ) {
                continue;
            }
            $entry = new \Forminator_Form_Entry_Model( $entry_id );
            if ( empty( $entry->entry_id ) ) {
                continue;
            }
            $meta  = is_array( $entry->meta_data ) ? $entry->meta_data : [];
            $out[] = [
                'source_entry_id' => (string) $entry_id,
                'payload'         => $adapter->build_normalized(
                    $form_id,
                    $entry_id,
                    $meta,
                    // Forminator writes date_created with date_i18n( 'Y-m-d H:i:s' ):
                    // the site's clock, so convert it to UTC.
                    \EntryVault\Dates::to_utc( (string) ( $entry->date_created_sql ?? '' ) ) ?: null
                ),
            ];
        }
        return $out;
    }
}
