<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Ninja Forms (https://ninjaforms.com/).
 *
 * Ninja Forms passes a rich $form_data array to `ninja_forms_after_submission`
 * whose `fields` carry label/key/type/value, so little recovery is needed — but
 * we stay defensive and fall back to humanize()/guess_type() if a field is
 * missing its metadata. When the save action ran, Ninja exposes the persisted
 * submission id at $form_data['actions']['save']['sub_id']; otherwise (e.g. a
 * form with no save action) we synthesize a unique id to avoid dedupe collisions.
 */
class Adapter_Ninja_Forms extends Adapter_Base {

    public function slug(): string {
        return 'ninja-forms';
    }

    public function is_active(): bool {
        return function_exists( 'Ninja_Forms' ) || class_exists( '\\Ninja_Forms' );
    }

    public function register_hooks(): void {
        add_action( 'ninja_forms_after_submission', [ $this, 'on_after_submission' ], 20, 1 );
        // Choice fields are stored as their labels; matchers (automation
        // conditions) still accept the option value through this map.
        add_filter( 'entryvault_field_choice_labels', [ $this, 'field_choice_labels' ], 10, 4 );
    }

    /** Field types that hold no answer (buttons, layout, anti-spam). */
    private const SKIP_TYPES = [ 'submit', 'html', 'hr', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'spam', 'note' ];

    /**
     * Hook callback for `ninja_forms_after_submission`.
     *
     * @param array $form_data { form_id, fields => [ [ key, label, type, value ], ... ], actions }
     */
    public function on_after_submission( $form_data ): void {
        if ( ! is_array( $form_data ) ) {
            return;
        }
        $form_id  = (int) ( $form_data['form_id'] ?? 0 );
        // Ninja's Akismet action flags spam as errors.form.spam; the
        // after-submission hook still fires.
        if ( ! empty( $form_data['errors']['form']['spam'] ) && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }
        $raw      = is_array( $form_data['fields'] ?? null ) ? $form_data['fields'] : [];
        $entry_id = $this->resolve_entry_id( $form_data, $form_id );

        $normalized = $this->build_normalized( $form_id, $entry_id, $raw, current_time( 'mysql' ) );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from Ninja's `fields` list.
     *
     * @param array $raw_fields List of field arrays, each ideally with key/label/type/value.
     */
    public function build_normalized( int $form_id, string $entry_id, array $raw_fields, ?string $created_at ): array {
        $fields = [];
        foreach ( $raw_fields as $index => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? $field['id'] ?? $index );
            if ( $key === '' ) {
                $key = 'field_' . count( $fields );
            }
            $type = (string) ( $field['type'] ?? '' );
            if ( in_array( strtolower( $type ), self::SKIP_TYPES, true ) ) {
                continue;
            }
            $value = $field['value'] ?? '';
            // List fields (radio, select, checkboxes) post the option value;
            // the visitor saw, and the owner expects to read, the label.
            $choices = self::choice_map( $field['options'] ?? null );
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( static fn( $v ) => (string) ( $choices[ (string) $v ] ?? $v ), $value ) );
            } elseif ( is_scalar( $value ) && isset( $choices[ (string) $value ] ) ) {
                $value = $choices[ (string) $value ];
            }
            $label = (string) ( $field['label'] ?? '' );
            if ( $label === '' ) {
                $label = $this->humanize( $key );
            }
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
            'created_at'      => $created_at ?: current_time( 'mysql' ),
        ];
    }

    /**
     * Option value => label from a Ninja `options` setting (a list of
     * [ label, value, ... ] rows, possibly still serialized).
     *
     * @return array<string,string>
     */
    public static function choice_map( $options ): array {
        if ( is_string( $options ) ) {
            $options = maybe_unserialize( $options );
        }
        $out = [];
        foreach ( is_array( $options ) ? $options : [] as $opt ) {
            if ( ! is_array( $opt ) ) {
                continue;
            }
            $value = isset( $opt['value'] ) && is_scalar( $opt['value'] ) ? (string) $opt['value'] : '';
            $label = isset( $opt['label'] ) && is_scalar( $opt['label'] ) ? (string) $opt['label'] : '';
            if ( '' !== $value && '' !== $label ) {
                $out[ $value ] = $label;
            }
        }
        return $out;
    }

    /**
     * entryvault_field_choice_labels: value => label for one Ninja field, read
     * from Ninja's own field tables.
     *
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function field_choice_labels( $labels, $source = '', $form_id = '', $field_key = '' ): array {
        if ( $source !== $this->slug() || (int) $form_id <= 0 || '' === (string) $field_key ) {
            return (array) $labels;
        }
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Ninja's own field tables.
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT m.meta_value FROM {$wpdb->prefix}nf3_fields f
               INNER JOIN {$wpdb->prefix}nf3_field_meta m ON m.parent_id = f.id AND m.meta_key = 'options'
              WHERE f.parent_id = %d AND f.`key` = %s LIMIT 1",
            (int) $form_id,
            (string) $field_key
        ) );
        $wpdb->suppress_errors( $suppress );
        $map = self::choice_map( $raw );
        return $map ? $map : (array) $labels;
    }

    private function resolve_entry_id( array $form_data, int $form_id ): string {
        $sub_id = $form_data['actions']['save']['sub_id'] ?? null;
        if ( ! empty( $sub_id ) ) {
            return (string) $sub_id;
        }
        // No persisted submission (save action disabled): mint a unique id so the
        // dedupe triple never collides across independent submissions.
        return 'nf_' . md5( $form_id . '|' . microtime( true ) . '|' . uniqid( '', true ) );
    }

    /**
     * Ninja types (email/phone/textbox/textarea/...) already line up well with
     * ours; only "phone" and stray "textbox" need a light nudge.
     */
    private function normalize_type( string $type ): string {
        $type = strtolower( $type );
        if ( $type === 'textbox' ) {
            return 'text';
        }
        return $type !== '' ? $type : 'text';
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
