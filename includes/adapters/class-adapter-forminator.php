<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Forminator extends Adapter_Base {

    public function slug(): string {
        return 'forminator';
    }

    public function is_active(): bool {
        return class_exists( '\\Forminator_API' ) || class_exists( '\\Forminator' );
    }

    public function register_hooks(): void {
        add_action( 'forminator_custom_form_submit_before_set_fields', [ $this, 'on_submit' ], 20, 3 );
    }

    /**
     * Hook callback for `forminator_custom_form_submit_before_set_fields`.
     *
     * @param object $entry            Forminator_Form_Entry_Model instance (entry already saved).
     * @param int    $form_id          Form id.
     * @param array  $field_data_array Array of [ 'name' => slug, 'value' => mixed ].
     */
    public function on_submit( $entry, $form_id, $field_data_array ): void {
        $entry_id = 0;
        if ( is_object( $entry ) && isset( $entry->entry_id ) ) {
            $entry_id = (int) $entry->entry_id;
        } elseif ( is_array( $entry ) ) {
            $entry_id = (int) ( $entry['entry_id'] ?? 0 );
        }
        $form_id = (int) $form_id;
        if ( ! $form_id || ! $entry_id ) {
            return;
        }
        // Forminator stores a spam-flagged entry (is_spam = 1) and fires this
        // hook for it too.
        $is_spam = is_object( $entry ) ? ! empty( $entry->is_spam ) : ( is_array( $entry ) && ! empty( $entry['is_spam'] ) );
        if ( $is_spam && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }

        $created = null;
        if ( is_object( $entry ) ) {
            $created = $entry->date_created_sql ?? null;
        }

        // Convert the [ ['name'=>..,'value'=>..], ... ] shape into the
        // [ slug => value ] map our build_normalized() expects.
        $meta = [];
        if ( is_array( $field_data_array ) ) {
            foreach ( $field_data_array as $row ) {
                if ( ! is_array( $row ) || ! isset( $row['name'] ) ) {
                    continue;
                }
                $meta[ (string) $row['name'] ] = $row['value'] ?? '';
            }
        }

        $normalized = $this->build_normalized( $form_id, $entry_id, $meta, $created ?: current_time( 'mysql' ) );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from a Forminator field-data map.
     * Keys look like "name-1", "email-1", "text-2"; the prefix carries the
     * field type. Values for compound fields (name, address) are arrays.
     */
    public function build_normalized( int $form_id, int $entry_id, array $meta, ?string $created_at ): array {
        $field_meta = $this->build_field_meta( $form_id );

        $fields = [];
        foreach ( $meta as $slug => $value ) {
            $slug = (string) $slug;
            $info = $field_meta[ $slug ] ?? null;
            $type = $info['type'] ?? $this->type_from_slug( $slug );
            $label = $info['label'] ?? $this->humanize( $slug );

            if ( is_array( $value ) ) {
                // Forminator name/address are associative arrays — flatten for display.
                $flat = [];
                foreach ( $value as $sub_key => $sub_val ) {
                    if ( is_scalar( $sub_val ) && (string) $sub_val !== '' ) {
                        $flat[] = (string) $sub_val;
                    }
                }
                $display = trim( implode( ' ', $flat ) );
                $fields[] = [
                    'key'   => sanitize_key( $slug ) ?: 'field_' . count( $fields ),
                    'label' => $label,
                    'type'  => $type,
                    'value' => $display !== '' ? $display : $value,
                ];
                continue;
            }

            $fields[] = [
                'key'   => sanitize_key( $slug ) ?: 'field_' . count( $fields ),
                'label' => $label,
                'type'  => $type,
                'value' => $value,
            ];
        }

        $email = $this->pick_email( $fields );
        $title = $this->pick_title( $fields, $email );

        return [
            'source_plugin'   => $this->slug(),
            'source_form_id'  => (string) $form_id,
            'source_entry_id' => (string) $entry_id,
            'title'           => $title,
            'email'           => $email,
            'fields'          => $fields,
            'created_at'      => $created_at ?: current_time( 'mysql' ),
        ];
    }

    private function build_field_meta( int $form_id ): array {
        static $cache = [];
        if ( isset( $cache[ $form_id ] ) ) {
            return $cache[ $form_id ];
        }
        if ( ! class_exists( '\\Forminator_API' ) ) {
            return $cache[ $form_id ] = [];
        }
        $fields = \Forminator_API::get_form_fields( $form_id );
        if ( is_wp_error( $fields ) || empty( $fields ) ) {
            return $cache[ $form_id ] = [];
        }
        $map = [];
        foreach ( (array) $fields as $field ) {
            $raw = is_object( $field ) && isset( $field->raw ) ? (array) $field->raw : (array) $field;
            $element_id = (string) ( $raw['element_id'] ?? '' );
            if ( $element_id === '' ) {
                continue;
            }
            $map[ $element_id ] = [
                'label' => (string) ( $raw['field_label'] ?? $raw['label'] ?? $this->humanize( $element_id ) ),
                'type'  => (string) ( $raw['type'] ?? $this->type_from_slug( $element_id ) ),
            ];
        }
        return $cache[ $form_id ] = $map;
    }

    private function type_from_slug( string $slug ): string {
        if ( preg_match( '/^([a-z]+)/', $slug, $m ) ) {
            return $m[1];
        }
        return 'text';
    }

    private function humanize( string $slug ): string {
        $slug = preg_replace( '/-\d+$/', '', $slug );
        return ucwords( trim( str_replace( [ '-', '_' ], ' ', (string) $slug ) ) );
    }
}
