<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Otter Blocks forms (Themeisle).
 *
 * Otter fires `otter_form_after_submit` ( Form_Data_Request $form_data ) after the
 * anti-spam checks passed, the submission was saved as a record and the main
 * delivery (email or integration) ran. It fires even when that delivery failed
 * (Otter flags an error on $form_data instead of skipping the hook), so a lead is
 * kept when the notification email does not go out.
 *
 * Paid forms: the first submit is saved as a draft and the hook fires again,
 * marked duplicate, once payment is confirmed. We skip the unconfirmed draft and
 * capture the confirmed one.
 *
 * $form_data->get_fields() is a list of [ id, label, value, type, metadata ].
 * Form id: the payload's formId (the block's id), else the page's postId.
 * Entry id: Otter's saved record (an `otter_form_record` post ID) when there is
 * one, else an "otter_" id of our own.
 */
class Adapter_Otter_Forms extends Adapter_Base {

    public function slug(): string {
        return 'otter-forms';
    }

    public function is_active(): bool {
        return defined( 'OTTER_BLOCKS_VERSION' ) || class_exists( '\\ThemeIsle\\GutenbergBlocks\\Integration\\Form_Data_Request' );
    }

    public function register_hooks(): void {
        add_action( 'otter_form_after_submit', [ $this, 'on_after_submit' ], 20, 1 );
    }

    public function on_after_submit( $form_data ): void {
        if ( ! is_object( $form_data ) || ! method_exists( $form_data, 'get_fields' ) ) {
            return;
        }
        $temporary = method_exists( $form_data, 'is_temporary' ) && $form_data->is_temporary();
        $duplicate = method_exists( $form_data, 'is_duplicate' ) && $form_data->is_duplicate();
        if ( $temporary && ! $duplicate ) {
            return; // Awaiting payment confirmation; captured when it is confirmed.
        }

        $inputs = $form_data->get_fields();
        if ( ! is_array( $inputs ) || ! $inputs ) {
            return;
        }
        $fields = [];
        foreach ( $inputs as $input ) {
            if ( ! is_array( $input ) ) {
                continue;
            }
            $id    = is_scalar( $input['id'] ?? null ) ? (string) $input['id'] : '';
            $label = is_scalar( $input['label'] ?? null ) ? trim( wp_strip_all_tags( (string) $input['label'] ) ) : '';
            $value = $this->flatten_value( $input['value'] ?? '' );
            $type  = is_scalar( $input['type'] ?? null ) ? (string) $input['type'] : '';
            if ( '' === $label ) {
                $label = 'Field ' . ( count( $fields ) + 1 );
            }
            // Otter's field ids are long block ids; their last 8 chars are what Otter
            // itself uses as the key when saving a record.
            $key      = '' !== $id ? substr( $id, -8 ) : '';
            $fields[] = [
                'key'   => sanitize_key( $key ) ?: 'field_' . count( $fields ),
                'label' => $label,
                'type'  => $this->map_type( $type, $label, $value ),
                'value' => $value,
            ];
        }
        if ( ! $fields ) {
            return;
        }

        $form_id   = $this->payload_value( $form_data, 'formId' );
        $post_id   = $this->payload_value( $form_data, 'postId' );
        $form_key  = '' !== $form_id ? $form_id : ( '' !== $post_id ? $post_id : '0' );
        $record_id = method_exists( $form_data, 'has_record_id' ) && $form_data->has_record_id() && method_exists( $form_data, 'get_record_id' )
            ? (int) $form_data->get_record_id()
            : 0;
        $entry_id  = $record_id > 0 ? (string) $record_id : $this->make_entry_id( 'otter_', $form_key, $inputs );
        $title     = '' !== $post_id && function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $post_id ) : '';

        $this->dispatch( $this->build_payload( $form_key, $entry_id, $fields, $title ) );
    }

    private function payload_value( $form_data, string $key ): string {
        if ( ! method_exists( $form_data, 'get_data_from_payload' ) ) {
            return '';
        }
        $v = $form_data->get_data_from_payload( $key );
        return is_scalar( $v ) ? trim( (string) $v ) : '';
    }

    private function map_type( string $type, string $label, $value ): string {
        switch ( $type ) {
            case 'tel':      return 'phone';
            case 'multiple': return 'checkbox';
            case '':         return $this->infer_type( $label, $value );
            default:         return $type;
        }
    }
}
