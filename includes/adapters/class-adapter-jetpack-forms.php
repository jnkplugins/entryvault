<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Jetpack Forms (the Jetpack contact form block / [contact-form]).
 *
 * Jetpack saves every response as a `feedback` post, then fires
 * `grunion_after_feedback_post_inserted` ( $post_id, $fields, $is_spam, $entry_values )
 * where $fields are the form's Contact_Form_Field objects (minus fields that
 * conditional logic hid). Same hook Jetpack's own webhooks and Post-to-URL use.
 *
 * Form identity: Jetpack has no numeric form id. We use the form's own "id"
 * attribute, which Jetpack computes from where the form lives: the page/post ID
 * ("123"), plus "-2", "-3" for a second or third form on that page, or
 * "widget-…" / "block-template-…" for forms outside post content. It stays the
 * same across submissions, so it groups entries the way a form id would.
 * Entry id: the feedback post ID (Jetpack stores entries).
 */
class Adapter_Jetpack_Forms extends Adapter_Base {

    private const FEEDBACK_CLASS = '\\Automattic\\Jetpack\\Forms\\ContactForm\\Feedback';

    public function slug(): string {
        return 'jetpack-forms';
    }

    public function is_active(): bool {
        return defined( 'JETPACK__VERSION' )
            || class_exists( '\\Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form' );
    }

    public function register_hooks(): void {
        add_action( 'grunion_after_feedback_post_inserted', [ $this, 'on_feedback_inserted' ], 20, 4 );
    }

    /**
     * @param int   $post_id      Feedback post ID.
     * @param array $fields       Contact_Form_Field objects keyed by field id.
     * @param bool  $is_spam      Akismet verdict.
     * @param array $entry_values entry_title, entry_permalink, feedback_id, ...
     */
    public function on_feedback_inserted( $post_id, $fields = [], $is_spam = false, $entry_values = [] ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        // Spam and block-listed responses are saved as spam/trash.
        $status = function_exists( 'get_post_status' ) ? (string) get_post_status( $post_id ) : '';
        if ( 'trash' === $status ) {
            return;
        }
        if ( ( $is_spam || 'spam' === $status ) && $this->skip_as_spam() ) {
            return;
        }
        $fields       = is_array( $fields ) ? $fields : [];
        $entry_values = is_array( $entry_values ) ? $entry_values : [];

        $feedback = $this->load_feedback( $post_id );
        // A test submission from the form preview is not a lead.
        if ( $feedback && method_exists( $feedback, 'is_test' ) && $feedback->is_test() ) {
            return;
        }

        $normalized = $feedback ? $this->fields_from_feedback( $feedback ) : [];
        if ( ! $normalized ) {
            $normalized = $this->fields_from_objects( $fields );
        }
        if ( ! $normalized ) {
            return;
        }

        $form_id = $this->form_id( $fields, $feedback );
        // post_date is the site's clock; post_date_gmt is the same moment in UTC.
        $created = function_exists( 'get_post_field' ) ? (string) get_post_field( 'post_date_gmt', $post_id ) : '';
        if ( '' === $created || 0 === strpos( $created, '0000' ) ) {
            $created = '';
        }
        $page    = isset( $entry_values['entry_title'] ) && is_scalar( $entry_values['entry_title'] ) ? (string) $entry_values['entry_title'] : '';

        $this->dispatch( $this->build_payload( $form_id, (string) $post_id, $normalized, $page, $created ?: null ) );
    }

    /** @return object|null The saved response as Jetpack's Feedback object. */
    private function load_feedback( int $post_id ) {
        if ( ! class_exists( self::FEEDBACK_CLASS ) || ! method_exists( self::FEEDBACK_CLASS, 'get' ) ) {
            return null;
        }
        try {
            $feedback = call_user_func( [ ltrim( self::FEEDBACK_CLASS, '\\' ), 'get' ], $post_id );
        } catch ( \Throwable $e ) {
            return null;
        }
        return is_object( $feedback ) ? $feedback : null;
    }

    /** Fields from the saved response: label, display value, type, id. */
    private function fields_from_feedback( $feedback ): array {
        if ( ! method_exists( $feedback, 'get_compiled_fields' ) ) {
            return [];
        }
        try {
            $compiled = $feedback->get_compiled_fields( 'default', 'collection' );
        } catch ( \Throwable $e ) {
            return [];
        }
        $out = [];
        foreach ( (array) $compiled as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id    = (string) ( $row['id'] ?? '' );
            $label = is_scalar( $row['label'] ?? null ) ? (string) $row['label'] : '';
            $out[] = $this->field( $id !== '' ? $id : (string) ( $row['key'] ?? '' ), $label, (string) ( $row['type'] ?? '' ), $row['value'] ?? '', count( $out ) );
        }
        return $out;
    }

    /** Fallback when the Feedback class is missing: read the hook's field objects. */
    private function fields_from_objects( array $fields ): array {
        $out = [];
        foreach ( $fields as $field_id => $field ) {
            if ( ! is_object( $field ) ) {
                continue;
            }
            $label = method_exists( $field, 'get_attribute' ) ? $field->get_attribute( 'label' ) : null;
            $type  = method_exists( $field, 'get_attribute' ) ? $field->get_attribute( 'type' ) : null;
            $value = property_exists( $field, 'value' ) || isset( $field->value ) ? $field->value : '';
            $out[] = $this->field( (string) $field_id, is_scalar( $label ) ? (string) $label : '', is_scalar( $type ) ? (string) $type : '', $value, count( $out ) );
        }
        return $out;
    }

    private function field( string $id, string $label, string $type, $value, int $index ): array {
        $label = trim( wp_strip_all_tags( $label ) );
        $value = $this->flatten_value( $value );
        if ( '' === $label ) {
            $label = $id !== '' ? $this->humanize_key( $id ) : 'Field ' . ( $index + 1 );
        }
        return [
            'key'   => sanitize_key( $id ) ?: 'field_' . $index,
            'label' => $label,
            'type'  => $this->map_type( $type, $label, $value ),
            'value' => $value,
        ];
    }

    private function map_type( string $type, string $label, $value ): string {
        switch ( $type ) {
            case 'telephone':         return 'phone';
            case 'checkbox-multiple': return 'checkbox';
            case 'consent':           return 'checkbox';
            case '':                  return $this->infer_type( $label, $value );
            default:                  return $type;
        }
    }

    /** The Contact_Form "id" attribute, else the page the response came from. */
    private function form_id( array $fields, $feedback ): string {
        foreach ( $fields as $field ) {
            if ( is_object( $field ) && isset( $field->form ) && is_object( $field->form ) && method_exists( $field->form, 'get_attribute' ) ) {
                $id = $field->form->get_attribute( 'id' );
                if ( is_scalar( $id ) && '' !== (string) $id ) {
                    return (string) $id;
                }
            }
        }
        if ( $feedback && method_exists( $feedback, 'get_entry_id' ) ) {
            $source = $feedback->get_entry_id();
            if ( is_scalar( $source ) && '' !== (string) $source && '0' !== (string) $source ) {
                return (string) $source;
            }
        }
        return '0';
    }
}
