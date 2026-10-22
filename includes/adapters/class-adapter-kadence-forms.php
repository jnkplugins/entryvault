<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Kadence Blocks forms. Kadence has two form blocks:
 *
 * - The simple Form block fires `kadence_blocks_form_submission`
 *   ( $form_args, $fields, $form_id, $post_id ) after its actions ran. $fields is
 *   index => [ type, label, value ]; $form_id is the block's unique id (a string)
 *   and $post_id the page it sits on.
 * - The Advanced Form block (a `kadence_form` post) fires
 *   `kadence_blocks_advanced_form_submission` ( $form_args, $processed_fields, $post_id )
 *   before its actions. Each processed field has label, type, value, uniqueID, name.
 *   Uploaded files arrive as URLs joined by ", ".
 *
 * Both fire only after the honeypot / captcha checks passed. Kadence Blocks
 * (free) stores no entries, so every submission gets a "kb_" id of our own. Form
 * ids: the simple block's unique id, or the advanced form's post ID; the two
 * never collide (block ids are not plain numbers).
 */
class Adapter_Kadence_Forms extends Adapter_Base {

    public function slug(): string {
        return 'kadence-forms';
    }

    public function is_active(): bool {
        return defined( 'KADENCE_BLOCKS_VERSION' ) || class_exists( '\\KB_Ajax_Form' ) || class_exists( '\\KB_Ajax_Advanced_Form' );
    }

    public function register_hooks(): void {
        add_action( 'kadence_blocks_form_submission', [ $this, 'on_form_submission' ], 20, 4 );
        add_action( 'kadence_blocks_advanced_form_submission', [ $this, 'on_advanced_form_submission' ], 20, 3 );
    }

    /** Simple Form block. */
    public function on_form_submission( $form_args, $fields = [], $form_id = '', $post_id = 0 ): void {
        if ( ! is_array( $fields ) || ! $fields ) {
            return;
        }
        $form_key = is_scalar( $form_id ) ? trim( (string) $form_id ) : '';
        if ( '' === $form_key ) {
            $form_key = (string) (int) $post_id;
        }
        if ( '' === $form_key || '0' === $form_key ) {
            return;
        }
        $out = [];
        foreach ( $fields as $index => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $out[] = $this->field( 'kb_field_' . $index, $field, count( $out ) );
        }
        if ( ! $out ) {
            return;
        }
        $title = (int) $post_id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $post_id ) : '';
        $this->dispatch( $this->build_payload( $form_key, $this->make_entry_id( 'kb_', $form_key, $fields ), $out, $title ) );
    }

    /** Advanced Form block. */
    public function on_advanced_form_submission( $form_args, $processed_fields = [], $post_id = 0 ): void {
        $form_id = (int) $post_id;
        if ( $form_id <= 0 || ! is_array( $processed_fields ) || ! $processed_fields ) {
            return;
        }
        $out = [];
        foreach ( $processed_fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = is_scalar( $field['name'] ?? null ) && '' !== (string) $field['name']
                ? (string) $field['name']
                : 'field' . ( is_scalar( $field['uniqueID'] ?? null ) ? (string) $field['uniqueID'] : count( $out ) );
            $out[] = $this->field( $name, $field, count( $out ) );
        }
        if ( ! $out ) {
            return;
        }
        $title = function_exists( 'get_the_title' ) ? (string) get_the_title( $form_id ) : '';
        $this->dispatch( $this->build_payload( (string) $form_id, $this->make_entry_id( 'kb_', (string) $form_id, $processed_fields ), $out, $title ) );
    }

    private function field( string $key, array $field, int $index ): array {
        $label = is_scalar( $field['label'] ?? null ) ? trim( wp_strip_all_tags( (string) $field['label'] ) ) : '';
        $value = $this->flatten_value( $field['value'] ?? '' );
        if ( '' === $label ) {
            $label = 'Field ' . ( $index + 1 );
        }
        $type = is_scalar( $field['type'] ?? null ) ? (string) $field['type'] : '';
        return [
            'key'   => sanitize_key( $key ) ?: 'field_' . $index,
            'label' => $label,
            'type'  => $this->map_type( $type, $label, $value ),
            'value' => $value,
        ];
    }

    private function map_type( string $type, string $label, $value ): string {
        switch ( $type ) {
            case 'tel':    return 'phone';
            case 'accept': return 'checkbox';
            case '':       return $this->infer_type( $label, $value );
            default:       return $type;
        }
    }
}
