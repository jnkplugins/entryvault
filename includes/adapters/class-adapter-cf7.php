<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Contact Form 7 (https://contactform7.com/).
 *
 * CF7 is the metadata-poorest source we support: it persists NOTHING (no entry
 * row, no entry id) and the posted data is a flat name => value map with no
 * labels or types. We recover labels/types by scanning the form's tags and,
 * because there is no natural entry id, we synthesize a UNIQUE one per
 * submission — see synthesize_entry_id() for why this is load-bearing.
 */
class Adapter_CF7 extends Adapter_Base {

    public function slug(): string {
        return 'contact-form-7';
    }

    public function is_active(): bool {
        return defined( 'WPCF7_VERSION' ) || class_exists( '\\WPCF7_ContactForm' );
    }

    public function register_hooks(): void {
        // Capture on before-send-mail — the same hook CF7's own Flamingo store uses —
        // so a lead is recorded even when the notification email fails to send (a
        // common misconfiguration). Fires once per valid, non-spam submission.
        // Args: ( WPCF7_ContactForm $contact_form, bool &$abort, WPCF7_Submission $submission ).
        add_action( 'wpcf7_before_send_mail', [ $this, 'on_before_send_mail' ], 20, 3 );
    }

    /**
     * Hook callback for `wpcf7_before_send_mail`.
     *
     * @param object $contact_form A WPCF7_ContactForm instance.
     * @param bool   $abort        Whether sending is being aborted (unused).
     * @param object $submission   The WPCF7_Submission for this request (preferred source).
     */
    public function on_before_send_mail( $contact_form, $abort = false, $submission = null ): void {
        if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
            return;
        }
        $form_id    = (int) $contact_form->id();
        $form_title = method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '';

        // Prefer the submission handed to the hook; fall back to the singleton.
        if ( ! is_object( $submission ) && class_exists( '\\WPCF7_Submission' ) ) {
            $submission = \WPCF7_Submission::get_instance();
        }
        $posted = ( is_object( $submission ) && method_exists( $submission, 'get_posted_data' ) )
            ? (array) $submission->get_posted_data()
            : [];

        $tag_map    = $this->build_tag_map( $contact_form );
        $normalized = $this->build_normalized( $form_id, $form_title, $posted, $tag_map, current_time( 'mysql', true ) );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from CF7's flat posted-data map.
     *
     * @param int    $form_id    CF7 form post id.
     * @param string $form_title CF7 form title (title fallback).
     * @param array  $posted     name => value (value may be a string or array).
     * @param array  $tag_map    name => [ 'label' => ..., 'type' => ... ] recovered from form tags.
     */
    public function build_normalized( int $form_id, string $form_title, array $posted, array $tag_map, ?string $created_at ): array {
        $fields = [];
        foreach ( $posted as $name => $value ) {
            $name = (string) $name;
            // CF7 injects private control keys (`_wpcf7`, `_wpcf7_version`, ...).
            if ( strpos( $name, '_wpcf7' ) === 0 ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', $value ) );
            }
            $meta  = $tag_map[ $name ] ?? null;
            $label = $meta['label'] ?? $this->humanize( $name );
            $type  = $meta['type']  ?? $this->guess_type( $label, $value );
            $key   = sanitize_key( $name );
            if ( $key === '' ) {
                $key = 'field_' . count( $fields );
            }
            $fields[] = [
                'key'   => $key,
                'label' => $label,
                'type'  => $type,
                'value' => $value,
            ];
        }

        $email = $this->pick_email( $fields );
        $title = $this->pick_title( $fields, $email );
        if ( $title === '' && $form_title !== '' ) {
            $title = $form_title;
        }

        return [
            'source_plugin'   => $this->slug(),
            'source_form_id'  => (string) $form_id,
            'source_entry_id' => $this->synthesize_entry_id( $form_id, $posted ),
            'title'           => $title,
            'email'           => $email,
            'fields'          => $fields,
            'created_at'      => $created_at ?: current_time( 'mysql', true ),
        ];
    }

    /**
     * CF7 has no entry id, so we mint a per-submission unique id. This is the
     * single most important correctness point of the adapter: Sync_Engine
     * dedupes on the (source_plugin, source_form_id, source_entry_id) triple, so
     * a reused id would silently MERGE unrelated submissions into one record.
     * uniqid( '', true ) + microtime guarantees a distinct value even for two
     * identical submissions in the same request.
     */
    private function synthesize_entry_id( int $form_id, array $posted ): string {
        $seed = $form_id . '|' . microtime( true ) . '|' . uniqid( '', true );
        if ( function_exists( 'wp_json_encode' ) ) {
            $seed .= '|' . wp_json_encode( $posted );
        }
        return 'cf7_' . md5( $seed );
    }

    /**
     * Recover a name => [ label, type ] map from the form's tags via
     * scan_form_tags(). Each tag exposes ->name and ->basetype
     * (email/tel/text/textarea/...). CF7 has no separate human label, so the
     * label is the humanized field name.
     */
    public function build_tag_map( $contact_form ): array {
        $map = [];
        if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
            return $map;
        }
        foreach ( (array) $contact_form->scan_form_tags() as $tag ) {
            $name     = is_object( $tag ) ? (string) ( $tag->name ?? '' ) : (string) ( $tag['name'] ?? '' );
            if ( $name === '' ) {
                continue;
            }
            $basetype = is_object( $tag ) ? (string) ( $tag->basetype ?? '' ) : (string) ( $tag['basetype'] ?? '' );
            $map[ $name ] = [
                'label' => $this->humanize( $name ),
                'type'  => $this->map_basetype( $basetype ),
            ];
        }
        return $map;
    }

    private function map_basetype( string $basetype ): string {
        switch ( $basetype ) {
            case 'email':    return 'email';
            case 'tel':      return 'phone';
            case 'textarea': return 'textarea';
            case 'number':   return 'number';
            case 'date':     return 'date';
            case 'url':      return 'url';
            case 'select':   return 'select';
            case 'checkbox': return 'checkbox';
            case 'radio':    return 'radio';
            case 'file':     return 'file';
            default:         return $basetype !== '' ? $basetype : 'text';
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
