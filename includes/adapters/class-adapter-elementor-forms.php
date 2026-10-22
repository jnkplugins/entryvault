<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Elementor Pro's Form widget.
 *
 * NOTE: built from Elementor's public developer docs
 * (https://developers.elementor.com/docs/hooks/forms), NOT tested against a real
 * Elementor Pro install (it is a paid plugin we don't have). Everything the docs
 * don't promise is read defensively.
 *
 * Documented: `elementor_pro/forms/new_record` ( $record, $ajax_handler ) fires
 * after the form's actions ran; `$record->get( 'fields' )` returns fields keyed by
 * field id, each with 'id' and 'value'; `$record->get_form_settings( 'form_name' )`
 * returns the form name.
 *
 * Not in those docs, used only when present: each field's 'title' (label), 'type'
 * and 'raw_value', and `get_form_settings( 'id' )` (the widget's element id). The
 * field type names below (tel, upload, acceptance, recaptcha, honeypot, ...) are
 * also unverified against a real install.
 *
 * Form id: get_form_settings( 'id' ) when it gives a value, else "name-" plus the
 * form name as a slug. Entry id: Elementor Pro keeps its own submissions table but
 * the docs give no way to read that id from the record, so every submission gets
 * an "elf_" id of our own.
 */
class Adapter_Elementor_Forms extends Adapter_Base {

    public function slug(): string {
        return 'elementor-forms';
    }

    public function is_active(): bool {
        return defined( 'ELEMENTOR_PRO_VERSION' );
    }

    public function register_hooks(): void {
        add_action( 'elementor_pro/forms/new_record', [ $this, 'on_new_record' ], 20, 2 );
    }

    public function on_new_record( $record, $ajax_handler = null ): void {
        if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
            return;
        }
        try {
            $raw_fields = $record->get( 'fields' );
        } catch ( \Throwable $e ) {
            return;
        }
        if ( ! is_array( $raw_fields ) || ! $raw_fields ) {
            return;
        }

        $fields = [];
        foreach ( $raw_fields as $id => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $field_id = is_scalar( $field['id'] ?? null ) && '' !== (string) $field['id'] ? (string) $field['id'] : (string) $id;
            $label    = is_scalar( $field['title'] ?? null ) ? trim( wp_strip_all_tags( (string) $field['title'] ) ) : '';
            if ( '' === $label ) {
                $label = $this->humanize_key( $field_id );
            }
            $value = $field['value'] ?? ( $field['raw_value'] ?? '' );
            $value = $this->flatten_value( $value );
            $type  = is_scalar( $field['type'] ?? null ) ? (string) $field['type'] : '';
            if ( in_array( $type, [ 'recaptcha', 'recaptcha_v3', 'honeypot', 'step', 'html' ], true ) ) {
                continue;
            }
            $fields[] = [
                'key'   => sanitize_key( $field_id ) ?: 'field_' . count( $fields ),
                'label' => $label,
                'type'  => $this->map_type( $type, $label, $value ),
                'value' => $value,
            ];
        }
        if ( ! $fields ) {
            return;
        }

        $form_name = $this->setting( $record, 'form_name' );
        $form_id   = $this->setting( $record, 'id' );
        if ( '' === $form_id ) {
            $form_id = '' !== $form_name ? 'name-' . sanitize_title( $form_name ) : '0';
        }

        $this->dispatch( $this->build_payload( $form_id, $this->make_entry_id( 'elf_', $form_id, $raw_fields ), $fields, $form_name ) );
    }

    private function setting( $record, string $key ): string {
        if ( ! method_exists( $record, 'get_form_settings' ) ) {
            return '';
        }
        try {
            $v = $record->get_form_settings( $key );
        } catch ( \Throwable $e ) {
            return '';
        }
        return is_scalar( $v ) ? trim( (string) $v ) : '';
    }

    private function map_type( string $type, string $label, $value ): string {
        switch ( $type ) {
            case 'tel':        return 'phone';
            case 'upload':     return 'file';
            case 'acceptance': return 'checkbox';
            case '':           return $this->infer_type( $label, $value );
            default:           return $type;
        }
    }
}
