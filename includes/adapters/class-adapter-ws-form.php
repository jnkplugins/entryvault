<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for WS Form (https://wsform.com/).
 *
 * WS Form fires `wsf_submit_complete` with a submit object. The submit carries
 * its data in a `meta` bag keyed by field references (e.g. `field_123`), and
 * label/type live in the form config rather than on the submit — so this
 * adapter extracts defensively and leans on humanize()/guess_type() for the
 * label/type fallback. Everything is guarded so a differing internal shape
 * degrades gracefully instead of fataling.
 */
class Adapter_WS_Form extends Adapter_Base {

    public function slug(): string {
        return 'ws-form';
    }

    public function is_active(): bool {
        return defined( 'WS_FORM_VERSION' )
            || class_exists( '\\WS_Form' )
            || function_exists( 'wsf_form_get' );
    }

    public function register_hooks(): void {
        add_action( 'wsf_submit_complete', [ $this, 'on_submit_complete' ], 20, 1 );
        // WS Form 1.12 (free) fires wsf_submit_post_complete, not
        // wsf_submit_complete, once a submission and its actions are done.
        // Both pass the WS_Form_Submit; the dedupe key keeps one entry.
        add_action( 'wsf_submit_post_complete', [ $this, 'on_submit_complete' ], 20, 1 );
    }

    /**
     * Hook callback for `wsf_submit_complete`.
     *
     * @param object|array $submit WS Form submit object (id, form_id, meta).
     */
    public function on_submit_complete( $submit ): void {
        $form_id  = (int) $this->prop( $submit, 'form_id' );
        // "Save" (a draft the visitor can come back to) is not a submission.
        if ( 'save' === (string) $this->prop( $submit, 'post_mode' ) ) {
            return;
        }
        if ( 'spam' === (string) $this->prop( $submit, 'status' ) && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }
        $entry_id = $this->resolve_entry_id( $submit, $form_id );
        $raw      = $this->extract_fields( $submit, $this->field_labels( $submit, $form_id ) );

        $normalized = $this->build_normalized( $form_id, $entry_id, $raw, current_time( 'mysql' ) );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from a defensively-extracted field map.
     *
     * @param array $raw_fields ref => value, OR ref => [ label?, type?, value ].
     */
    public function build_normalized( int $form_id, string $entry_id, array $raw_fields, ?string $created_at ): array {
        $fields = [];
        foreach ( $raw_fields as $ref => $item ) {
            $ref = (string) $ref;
            if ( is_array( $item ) && array_key_exists( 'value', $item ) ) {
                $value = $item['value'];
                $label = (string) ( $item['label'] ?? '' );
                $type  = (string) ( $item['type'] ?? '' );
            } else {
                $value = $item;
                $label = '';
                $type  = '';
            }
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
            }
            if ( $label === '' ) {
                $label = $this->humanize( $ref );
            }
            if ( $type === '' ) {
                $type = $this->guess_type( $label, $value );
            }
            $fields[] = [
                'key'   => sanitize_key( $ref ) ?: 'field_' . count( $fields ),
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
            'source_entry_id' => $entry_id,
            'title'           => $title,
            'email'           => $email,
            'fields'          => $fields,
            'created_at'      => $created_at ?: current_time( 'mysql' ),
        ];
    }

    /**
     * Pull a field map out of the submit object as defensively as possible.
     * Prefers a `meta` bag ([ field_ref => value | { value, label, type } ]);
     * ignores WS Form's internal/system keys.
     */
    private function extract_fields( $submit, array $labels = [] ): array {
        $meta = $this->prop( $submit, 'meta' );
        if ( ! is_array( $meta ) ) {
            $meta = is_object( $meta ) ? get_object_vars( $meta ) : [];
        }
        $out = [];
        foreach ( $meta as $ref => $entry ) {
            $ref = (string) $ref;
            if ( $ref === '' || strpos( $ref, '_' ) === 0 ) {
                continue; // skip WS Form's private/system meta (e.g. _wsf_*).
            }
            // WS Form's own bookkeeping, not something the visitor typed:
            // the list of hidden fields and the page the form sat on.
            if ( in_array( $ref, self::SYSTEM_META, true ) || strpos( $ref, 'wsf_' ) === 0 ) {
                continue;
            }
            // A meta entry may be a scalar, or an object/array carrying
            // value + optional label/type. Normalize to what build_normalized wants.
            if ( is_object( $entry ) ) {
                $entry = get_object_vars( $entry );
            }
            $known = $labels[ $ref ] ?? [];
            if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
                $out[ $ref ] = [
                    'value' => $entry['value'],
                    'label' => (string) ( $entry['label'] ?? $entry['name'] ?? $known['label'] ?? '' ),
                    'type'  => (string) ( $entry['type'] ?? '' ),
                ];
            } elseif ( $known ) {
                $out[ $ref ] = [ 'value' => $entry, 'label' => (string) $known['label'], 'type' => '' ];
            } else {
                $out[ $ref ] = $entry;
            }
        }
        return $out;
    }

    /** Meta keys WS Form adds to every submit that are not form fields. */
    private const SYSTEM_META = [ 'post_id', 'wsf_meta_key_hidden' ];

    /**
     * Field labels from the form definition, keyed like the submit meta
     * ("field_12" => [ label ]). The submit's meta carries values and types but
     * no labels, so without this every row read "Field 1", "Field 2"...
     * Uses the submit's own form object when present, else the published form.
     *
     * @return array<string, array{label: string}>
     */
    public function field_labels( $submit, int $form_id ): array {
        $form = $this->prop( $submit, 'form_object' );
        if ( ! is_object( $form ) && $form_id > 0 && class_exists( '\\WS_Form_Form' ) ) {
            try {
                $reader     = new \WS_Form_Form();
                $reader->id = $form_id;
                $form       = $reader->db_read_published( true );
            } catch ( \Throwable $e ) {
                $form = null;
            }
        }
        $map = [];
        if ( ! is_object( $form ) ) {
            return $map;
        }
        foreach ( (array) ( $form->groups ?? [] ) as $group ) {
            foreach ( (array) ( is_object( $group ) ? ( $group->sections ?? [] ) : [] ) as $section ) {
                foreach ( (array) ( is_object( $section ) ? ( $section->fields ?? [] ) : [] ) as $field ) {
                    if ( is_object( $field ) && isset( $field->id ) && '' !== trim( (string) ( $field->label ?? '' ) ) ) {
                        $map[ 'field_' . (int) $field->id ] = [ 'label' => trim( (string) $field->label ) ];
                    }
                }
            }
        }
        return $map;
    }

    private function resolve_entry_id( $submit, int $form_id ): string {
        $id = $this->prop( $submit, 'id' );
        if ( ! empty( $id ) ) {
            return (string) $id;
        }
        return 'wsf_' . md5( $form_id . '|' . microtime( true ) . '|' . uniqid( '', true ) );
    }

    /** Read a property/key off an object or array uniformly. */
    private function prop( $source, string $name ) {
        if ( is_object( $source ) ) {
            return $source->$name ?? null;
        }
        if ( is_array( $source ) ) {
            return $source[ $name ] ?? null;
        }
        return null;
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
