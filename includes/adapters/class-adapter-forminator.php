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

        // Forminator writes date_created with date_i18n( 'Y-m-d H:i:s' ): the
        // site's clock, so convert it to UTC.
        $normalized = $this->build_normalized( $form_id, $entry_id, $meta, $created ? \EntryVault\Dates::to_utc( (string) $created ) : current_time( 'mysql', true ) );
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
            // Forminator adds its own entry meta to the same list: the visitor's
            // IP (_forminator_user_ip), user id, draft and integration markers.
            // None of it is something the visitor typed.
            if ( str_starts_with( $slug, '_' ) ) {
                continue;
            }
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
            'created_at'      => $created_at ?: current_time( 'mysql', true ),
        ];
    }

    private function build_field_meta( int $form_id ): array {
        static $cache = [];
        /** This filter is documented in includes/historical/class-historical-forminator.php */
        $api = apply_filters( 'entryvault_forminator_api', [ 'entry' => '\\Forminator_Form_Entry_Model', 'api' => '\\Forminator_API' ] );
        $api = (string) ( $api['api'] ?? '' );
        $ck  = $api . '|' . $form_id;
        if ( isset( $cache[ $ck ] ) ) {
            return $cache[ $ck ];
        }
        if ( '' === $api || ! class_exists( $api ) || ! method_exists( $api, 'get_form_fields' ) ) {
            return $cache[ $ck ] = [];
        }
        $fields = $api::get_form_fields( $form_id );
        if ( is_wp_error( $fields ) || empty( $fields ) ) {
            return $cache[ $ck ] = [];
        }
        $map = [];
        foreach ( (array) $fields as $field ) {
            // Forminator_Form_Field_Model keeps its settings in a protected $raw,
            // so isset( $field->raw ) was always false and (array) $field gave
            // mangled keys: every label was guessed from the slug. to_array()
            // is public and returns element_id, field_label and type.
            if ( is_object( $field ) && method_exists( $field, 'to_array' ) ) {
                $raw = (array) $field->to_array();
            } elseif ( is_object( $field ) ) {
                $raw = [
                    'element_id'  => $field->slug ?? ( $field->element_id ?? '' ),
                    'field_label' => $field->field_label ?? null,
                    'type'        => $field->type ?? null,
                ];
            } else {
                $raw = (array) $field;
            }
            $element_id = (string) ( $raw['element_id'] ?? '' );
            if ( $element_id === '' ) {
                continue;
            }
            $label = trim( wp_strip_all_tags( (string) ( $raw['field_label'] ?? $raw['label'] ?? '' ) ) );
            $map[ $element_id ] = [
                'label' => '' !== $label ? $label : $this->humanize( $element_id ),
                'type'  => (string) ( $raw['type'] ?? $this->type_from_slug( $element_id ) ),
            ];
        }
        return $cache[ $ck ] = $map;
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
