<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Everest Forms (https://everestforms.net/).
 *
 * Everest persists entries and fires `everest_forms_complete_entry_save` with
 * ( $entry_id, $fields, $entry, $form_id ). The `$fields` array is keyed by field
 * id and each item carries name (label) / value / type, so recovery is light —
 * but we stay defensive and fall back to humanize()/guess_type() for any field
 * missing its metadata, and synthesize a unique id if the entry id is absent.
 */
class Adapter_Everest_Forms extends Adapter_Base {

    public function slug(): string {
        return 'everest-forms';
    }

    public function is_active(): bool {
        return defined( 'EVF_VERSION' )
            || function_exists( 'evf' )
            || class_exists( '\\EverestForms' );
    }

    public function register_hooks(): void {
        // Args: ( $entry_id, $fields, $entry, $form_id ).
        add_action( 'everest_forms_complete_entry_save', [ $this, 'on_entry_save' ], 20, 4 );
    }

    /**
     * Hook callback for `everest_forms_complete_entry_save`.
     *
     * @param int   $entry_id Newly created entry id (0/false if entries disabled).
     * @param array $fields   field_id => [ name, value, type, ... ].
     * @param array $entry    Raw entry payload (unused; kept for signature parity).
     * @param int   $form_id  Form id.
     */
    public function on_entry_save( $entry_id, $fields = [], $entry = [], $form_id = 0 ): void {
        $form_id = (int) $form_id;
        // Everest's anti-spam checks set evf_spam_status = "spam" on the entry
        // and save it; this hook still fires.
        if ( is_array( $entry ) && 'spam' === (string) ( $entry['evf_spam_status'] ?? '' ) && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }
        $raw     = is_array( $fields ) ? $fields : [];
        $eid     = (int) $entry_id;
        $entry_key = $eid > 0
            ? (string) $eid
            : 'evf_' . md5( $form_id . '|' . microtime( true ) . '|' . uniqid( '', true ) );

        $normalized = $this->build_normalized( $form_id, $entry_key, $raw, current_time( 'mysql', true ) );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from Everest's `fields` map.
     *
     * @param array $raw_fields field_id => [ name, value, type, ... ].
     */
    public function build_normalized( int $form_id, string $entry_id, array $raw_fields, ?string $created_at ): array {
        $fields = [];
        foreach ( $raw_fields as $fid => $field ) {
            if ( ! is_array( $field ) ) {
                // Bare value map fallback.
                $field = [ 'value' => $field ];
            }
            $key = (string) ( $field['meta_key'] ?? $field['id'] ?? $fid );
            if ( $key === '' ) {
                $key = 'field_' . count( $fields );
            }
            $value = $field['value'] ?? '';
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
            }
            $label = (string) ( $field['name'] ?? $field['label'] ?? '' );
            if ( $label === '' ) {
                $label = $this->humanize( $key );
            }
            $type = (string) ( $field['type'] ?? '' );
            if ( $type === '' ) {
                $type = $this->guess_type( $label, $value );
            }
            $fields[] = [
                'key'   => sanitize_key( $key ) ?: 'field_' . count( $fields ),
                'label' => $label,
                'type'  => $this->normalize_type( $type ),
                'value' => $value,
            ];
        }

        $email = $this->pick_email( $fields );
        $title = $this->pick_title( $fields, $email );

        return [
            'source_plugin'   => $this->slug(),
            'source_form_id'  => (string) $form_id,
            'source_entry_id' => $entry_id,
            'title'           => $title,
            'email'           => $email,
            'fields'          => $fields,
            'created_at'      => $created_at ?: current_time( 'mysql', true ),
        ];
    }

    /** Everest types (email/phone/text/textarea/select/...) mostly line up; nudge a couple. */
    private function normalize_type( string $type ): string {
        $type = strtolower( $type );
        switch ( $type ) {
            case 'first-name':
            case 'last-name':
            case 'name':     return 'text';
            case 'tel':      return 'phone';
            default:         return $type !== '' ? $type : 'text';
        }
    }

    private function humanize( string $slug ): string {
        return ucwords( trim( str_replace( [ '-', '_' ], ' ', $slug ) ) );
    }

    private function guess_type( string $label, $value ): string {
        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
            return 'email';
        }
        $lower = strtolower( $label );
        if ( strpos( $lower, 'email' ) !== false ) {
            return 'email';
        }
        if ( strpos( $lower, 'phone' ) !== false ) {
            return 'phone';
        }
        if ( is_array( $value ) ) {
            return 'array';
        }
        return 'text';
    }
}
