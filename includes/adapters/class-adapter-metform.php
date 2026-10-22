<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for MetForm (Elementor form builder by Wpmet).
 *
 * MetForm fires `metform_after_store_form_data` ( $form_id, $form_data, $settings,
 * $attributes ) right after it saved the entry, once the nonce, captcha and field
 * validation have passed. $form_data is the posted name => value map (with some
 * control keys), $attributes['file_upload_info'] holds uploaded files per field.
 *
 * Entry id: MetForm saves entries as `metform-entry` posts when "Store entries" is
 * on, but the hook does not pass that post ID. MetForm keeps it on its Action
 * singleton, so we read it from there and check the post really is this form's
 * entry. When storing is off (or the id can't be read) we mint an "mf_" id.
 */
class Adapter_Metform extends Adapter_Base {

    private const ACTION_CLASS = '\\MetForm\\Core\\Entries\\Action';

    /** Posted keys that are MetForm plumbing, not answers. */
    private const CONTROL_KEYS = [
        'action', 'id', 'form_nonce', 'hidden-fields', 'g-recaptcha-response',
        'g-recaptcha-response-v3', 'mf-captcha-challenge', 'mf_entry_id', 'mf-woo-checkout',
    ];

    /** Widgets that hold no answer worth keeping. */
    private const SKIP_WIDGETS = [ 'mf-recaptcha', 'mf-simple-captcha', 'mf-button' ];

    public function slug(): string {
        return 'metform';
    }

    public function is_active(): bool {
        return defined( 'METFORM_VERSION' ) || class_exists( '\\MetForm\\Plugin' );
    }

    public function register_hooks(): void {
        add_action( 'metform_after_store_form_data', [ $this, 'on_after_store' ], 20, 4 );
        // Choice fields are stored as their labels; matchers (automation
        // conditions) still accept the option value through this map.
        add_filter( 'entryvault_field_choice_labels', [ $this, 'field_choice_labels' ], 10, 4 );
    }

    /**
     * entryvault_field_choice_labels: value => label for one MetForm field.
     *
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function field_choice_labels( $labels, $source = '', $form_id = '', $field_key = '' ): array {
        if ( $source !== $this->slug() || (int) $form_id <= 0 || '' === (string) $field_key ) {
            return (array) $labels;
        }
        foreach ( $this->field_map( (int) $form_id ) as $name => $meta ) {
            if ( ( sanitize_key( (string) $name ) === (string) $field_key ) && ! empty( $meta['options'] ) ) {
                return $meta['options'];
            }
        }
        return (array) $labels;
    }

    public function on_after_store( $form_id, $form_data = [], $settings = [], $attributes = [] ): void {
        $form_id = (int) $form_id;
        if ( $form_id <= 0 || ! is_array( $form_data ) || ! $form_data ) {
            return;
        }
        $attributes = is_array( $attributes ) ? $attributes : [];
        $files      = is_array( $attributes['file_upload_info'] ?? null ) ? $attributes['file_upload_info'] : [];

        $fields = $this->build_fields( $form_data, $files, $this->field_map( $form_id ) );
        if ( ! $fields ) {
            return;
        }

        $entry_id = $this->stored_entry_id( $form_id );
        $source   = $entry_id > 0 ? (string) $entry_id : $this->make_entry_id( 'mf_', (string) $form_id, $form_data );
        $title    = function_exists( 'get_the_title' ) ? (string) get_the_title( $form_id ) : '';

        $this->dispatch( $this->build_payload( (string) $form_id, $source, $fields, $title ) );
    }

    /** The normalized payload for already-built fields (used by the Pro importer). */
    public function build_entry_payload( string $form_id, string $entry_id, array $fields, string $title = '', ?string $created_at = null ): array {
        return $this->build_payload( $form_id, $entry_id, $fields, $title, $created_at );
    }

    /**
     * @param array $form_data Posted name => value.
     * @param array $files     name => [ [ name, url, file, type ], ... ].
     * @param array $map       name => [ label, type ] from the form's widgets ([] if unknown).
     */
    public function build_fields( array $form_data, array $files, array $map ): array {
        $out = [];
        if ( $map ) {
            foreach ( $map as $name => $meta ) {
                if ( in_array( $meta['widget'], self::SKIP_WIDGETS, true ) ) {
                    continue;
                }
                if ( isset( $files[ $name ] ) ) {
                    $value = $this->flatten_value( $files[ $name ] );
                } elseif ( array_key_exists( $name, $form_data ) ) {
                    $value = $this->flatten_value( self::to_labels( $form_data[ $name ], $meta['options'] ?? [] ) );
                } else {
                    continue;
                }
                $label = $meta['label'] !== '' ? $meta['label'] : $this->humanize_key( (string) $name );
                $out[] = [
                    'key'   => sanitize_key( (string) $name ) ?: 'field_' . count( $out ),
                    'label' => $label,
                    'type'  => $this->map_type( $meta['widget'], $label, $value ),
                    'value' => $value,
                ];
            }
            return $out;
        }

        // No widget map (form data unreadable): keep every posted answer.
        foreach ( $form_data as $name => $raw ) {
            $name = (string) $name;
            if ( '' === $name || in_array( $name, self::CONTROL_KEYS, true ) || strpos( $name, '_' ) === 0 ) {
                continue;
            }
            $value = $this->flatten_value( isset( $files[ $name ] ) ? $files[ $name ] : $raw );
            $label = $this->humanize_key( $name );
            $out[] = [
                'key'   => sanitize_key( $name ) ?: 'field_' . count( $out ),
                'label' => $label,
                'type'  => isset( $files[ $name ] ) ? 'file' : $this->infer_type( $label, $value ),
                'value' => $value,
            ];
        }
        return $out;
    }

    /** name => [ label, widget ] from the form's Elementor data, via MetForm's own reader. */
    public function field_map( int $form_id ): array {
        if ( ! class_exists( self::ACTION_CLASS ) || ! method_exists( self::ACTION_CLASS, 'instance' ) ) {
            return [];
        }
        try {
            $action = call_user_func( [ ltrim( self::ACTION_CLASS, '\\' ), 'instance' ] );
            $raw    = is_object( $action ) && method_exists( $action, 'get_fields' ) ? $action->get_fields( $form_id ) : [];
        } catch ( \Throwable $e ) {
            return [];
        }
        $map = [];
        foreach ( (array) $raw as $name => $widget ) {
            if ( is_array( $widget ) ) {
                $widget = (object) $widget;
            }
            if ( ! is_object( $widget ) ) {
                continue;
            }
            $label = isset( $widget->mf_input_label ) && is_scalar( $widget->mf_input_label ) ? (string) $widget->mf_input_label : '';
            $map[ (string) $name ] = [
                'label'   => trim( wp_strip_all_tags( $label ) ),
                'widget'  => isset( $widget->widgetType ) && is_scalar( $widget->widgetType ) ? str_replace( '[]', '', (string) $widget->widgetType ) : '',
                'options' => self::choice_map( $widget->mf_input_list ?? null ),
            ];
        }
        return $map;
    }

    /**
     * Option value => text from a choice widget's `mf_input_list` repeater
     * (select, multi-select, radio, checkbox).
     *
     * @return array<string,string>
     */
    public static function choice_map( $list ): array {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $row ) {
            $row   = is_object( $row ) ? (array) $row : $row;
            if ( ! is_array( $row ) ) {
                continue;
            }
            $value = isset( $row['mf_input_option_value'] ) && is_scalar( $row['mf_input_option_value'] ) ? (string) $row['mf_input_option_value'] : '';
            $text  = isset( $row['mf_input_option_text'] ) && is_scalar( $row['mf_input_option_text'] ) ? trim( wp_strip_all_tags( (string) $row['mf_input_option_text'] ) ) : '';
            if ( '' !== $value && '' !== $text ) {
                $out[ $value ] = $text;
            }
        }
        return $out;
    }

    /**
     * Swap posted option values for the text the visitor saw. Lists map item by
     * item; a comma-joined string (multi-select, checkboxes) maps only when every
     * part is a known option, so free text with commas is left alone.
     *
     * @param array<string,string> $choices
     * @return mixed
     */
    private static function to_labels( $value, array $choices ) {
        if ( ! $choices ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            return array_map( static fn( $v ) => is_scalar( $v ) && isset( $choices[ (string) $v ] ) ? $choices[ (string) $v ] : $v, $value );
        }
        if ( ! is_scalar( $value ) ) {
            return $value;
        }
        $value = (string) $value;
        if ( isset( $choices[ $value ] ) ) {
            return $choices[ $value ];
        }
        $parts = array_map( 'trim', explode( ',', $value ) );
        if ( count( $parts ) > 1 && ! array_diff( $parts, array_map( 'strval', array_keys( $choices ) ) ) ) {
            return implode( ', ', array_map( static fn( $p ) => $choices[ $p ], $parts ) );
        }
        return $value;
    }

    /**
     * The `metform-entry` post MetForm just created, read from its Action singleton
     * (the property is private and the hook doesn't pass it). 0 when unavailable.
     */
    private function stored_entry_id( int $form_id ): int {
        if ( ! class_exists( self::ACTION_CLASS ) || ! method_exists( self::ACTION_CLASS, 'instance' ) ) {
            return 0;
        }
        try {
            $action = call_user_func( [ ltrim( self::ACTION_CLASS, '\\' ), 'instance' ] );
            if ( ! is_object( $action ) || ! property_exists( $action, 'entry_id' ) ) {
                return 0;
            }
            $read = \Closure::bind( function () {
                return $this->entry_id;
            }, $action, get_class( $action ) );
            $id = (int) $read();
        } catch ( \Throwable $e ) {
            return 0;
        }
        if ( $id <= 0 || ! function_exists( 'get_post_type' ) || get_post_type( $id ) !== 'metform-entry' ) {
            return 0;
        }
        return (int) get_post_meta( $id, 'metform_entries__form_id', true ) === $form_id ? $id : 0;
    }

    private function map_type( string $widget, string $label, $value ): string {
        $types = [
            'mf-email'           => 'email',
            'mf-telephone'       => 'phone',
            'mf-textarea'        => 'textarea',
            'mf-number'          => 'number',
            'mf-url'             => 'url',
            'mf-date'            => 'date',
            'mf-time'            => 'time',
            'mf-select'          => 'select',
            'mf-multi-select'    => 'select',
            'mf-checkbox'        => 'checkbox',
            'mf-radio'           => 'radio',
            'mf-file-upload'     => 'file',
            'mf-listing-fname'   => 'name',
            'mf-listing-lname'   => 'name',
            'mf-gdpr-consent'    => 'checkbox',
            'mf-listing-optin'   => 'checkbox',
        ];
        if ( isset( $types[ $widget ] ) ) {
            return $types[ $widget ];
        }
        return $this->infer_type( $label, $value );
    }
}
