<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_WPForms;

defined( 'ABSPATH' ) || exit;

class Historical_WPForms extends Provider_Base {

    public function source(): string {
        return 'wpforms';
    }

    public function label(): string {
        return 'WPForms';
    }

    public function access(): string {
        return self::ACCESS_API;
    }

    public function source_version(): string {
        return defined( 'WPFORMS_VERSION' ) ? (string) WPFORMS_VERSION : '';
    }

    /** WPForms Lite is installed but keeps no entries (it only emails them). */
    public function installed(): bool {
        return function_exists( 'wpforms' );
    }

    public function keeps_entries(): bool {
        return $this->available();
    }

    public function count( int $form_id ): ?int {
        if ( ! $this->available() || ! method_exists( wpforms()->entry, 'get_entries' ) ) {
            return null;
        }
        return (int) wpforms()->entry->get_entries( [ 'form_id' => $form_id ], true );
    }

    protected function adapter_class(): string {
        return Adapter_WPForms::class;
    }

    public function available(): bool {
        return function_exists( 'wpforms' ) && ! empty( wpforms()->entry );
    }

    public function forms(): array {
        if ( ! function_exists( 'wpforms' ) ) {
            return [];
        }
        $forms = wpforms()->form->get( '', [ 'post_status' => 'publish' ] );
        $out   = [];
        foreach ( (array) $forms as $form ) {
            $out[] = [ 'id' => (int) $form->ID, 'title' => $form->post_title ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $adapter = $this->adapter();
        if ( ! $adapter || ! $this->available() ) {
            return [];
        }
        $entries = wpforms()->entry->get_entries( [
            'form_id' => (int) $form_id,
            'number'  => (int) $limit,
            'offset'  => (int) $offset,
        ] );
        $out = [];
        foreach ( (array) $entries as $entry ) {
            $entry_id = (int) ( $entry->entry_id ?? 0 );
            if ( ! $entry_id ) {
                continue;
            }
            $fields = json_decode( (string) ( $entry->fields ?? '' ), true ) ?: [];
            $out[]  = [
                'source_entry_id' => (string) $entry_id,
                // WPForms writes entries.date with gmdate() (Forms\Submission): UTC,
                // as EntryVault stores it, so kept as is.
                'payload'         => $adapter->build_normalized( (int) $form_id, $entry_id, $fields, $entry->date ?? null ),
            ];
        }
        return $out;
    }
}
