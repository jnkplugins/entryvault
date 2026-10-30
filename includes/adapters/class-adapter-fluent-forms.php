<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Fluent_Forms extends Adapter_Base {

    public function slug(): string {
        return 'fluent-forms';
    }

    public function is_active(): bool {
        return function_exists( 'wpFluent' )
            || defined( 'FLUENTFORM' )
            || class_exists( '\\FluentForm\\Framework\\Foundation\\Application' );
    }

    public function register_hooks(): void {
        add_action( 'fluentform/submission_inserted', [ $this, 'on_submission_inserted' ], 20, 3 );
    }

    /**
     * Hook callback for `fluentform/submission_inserted`.
     *
     * @param int   $entry_id   Submission row id.
     * @param array $form_data  Sanitized field-name => value map.
     * @param object|array $form Fluent form model.
     */
    public function on_submission_inserted( $entry_id, $form_data, $form ): void {
        $entry_id = (int) $entry_id;
        if ( ! $entry_id ) {
            return;
        }
        $form_id = 0;
        if ( is_object( $form ) ) {
            $form_id = (int) ( $form->id ?? 0 );
        } elseif ( is_array( $form ) ) {
            $form_id = (int) ( $form['id'] ?? 0 );
        }
        // Fluent Forms saves an Akismet/CleanTalk hit with status "spam" and,
        // unless set to "skip processing", still fires submission_inserted.
        if ( 'spam' === $this->stored_status( $entry_id ) && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }
        $payload = is_array( $form_data ) ? $form_data : [];
        $normalized = $this->build_normalized(
            $form_id,
            $entry_id,
            $payload,
            $form_id,
            // Captured as it arrives: now, in UTC.
            current_time( 'mysql', true )
        );
        $this->dispatch( $normalized );
    }

    /**
     * Build a normalized payload from a Fluent Forms response map.
     * Response is keyed by field name (e.g. "names", "email") -> value
     * (string or array for compound/multi-select fields).
     */
    public function build_normalized( int $form_id, int $entry_id, array $response, int $resolve_form_id, ?string $created_at ): array {
        $labels = $this->build_label_map( $resolve_form_id );

        $fields = [];
        foreach ( $response as $key => $value ) {
            $key = (string) $key;
            // Fluent hands over its own nonce, referer and embed post id with the
            // visitor's answers; they mean nothing on a card.
            if ( self::is_internal_key( $key ) ) {
                continue;
            }
            $meta = $labels[ $key ] ?? null;
            $label = $meta['label'] ?? $this->humanize( $key );
            $type  = $meta['type']  ?? $this->guess_type( $label, $value );
            // A Name field arrives as first/middle/last parts: keep it as one
            // string so the card can be titled with it.
            if ( is_array( $value ) && ( 'input_name' === $type || array_intersect_key( $value, array_flip( self::NAME_PARTS ) ) ) ) {
                $value = self::join_name( $value );
                $type  = 'input_name';
            }
            $fields[] = [
                'key'   => sanitize_key( $key ) ?: 'field_' . count( $fields ),
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

    /**
     * Walk the Fluent form definition to recover [ field_name => [ label, type ] ].
     */
    private function build_label_map( int $form_id ): array {
        static $cache = [];
        if ( isset( $cache[ $form_id ] ) ) {
            return $cache[ $form_id ];
        }
        if ( ! $form_id || ! function_exists( 'wpFluent' ) ) {
            return $cache[ $form_id ] = [];
        }
        $form = wpFluent()->table( 'fluentform_forms' )->where( 'id', $form_id )->first();
        if ( ! $form ) {
            return $cache[ $form_id ] = [];
        }
        $form = (array) $form;
        $raw  = $form['form_fields'] ?? '';
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true );
        }
        if ( ! is_array( $raw ) ) {
            return $cache[ $form_id ] = [];
        }
        $map = [];
        $this->collect_fluent_fields( $raw['fields'] ?? [], $map );
        return $cache[ $form_id ] = $map;
    }

    private function collect_fluent_fields( $nodes, array &$map ): void {
        if ( ! is_array( $nodes ) ) {
            return;
        }
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $attrs = (array) ( $node['attributes'] ?? [] );
            $name  = (string) ( $attrs['name'] ?? '' );
            if ( $name !== '' ) {
                $settings = (array) ( $node['settings'] ?? [] );
                $map[ $name ] = [
                    // A Name container has no "label", only "admin_field_label".
                    'label' => (string) ( ( $settings['label'] ?? '' ) ?: ( $settings['admin_field_label'] ?? '' ) ?: ( $attrs['placeholder'] ?? '' ) ?: $name ),
                    'type'  => (string) ( $node['element'] ?? 'text' ),
                ];
            }
            if ( ! empty( $node['fields'] ) ) {
                $this->collect_fluent_fields( $node['fields'], $map );
            }
            if ( ! empty( $node['columns'] ) && is_array( $node['columns'] ) ) {
                foreach ( $node['columns'] as $col ) {
                    if ( is_array( $col ) && ! empty( $col['fields'] ) ) {
                        $this->collect_fluent_fields( $col['fields'], $map );
                    }
                }
            }
        }
    }

    private const NAME_PARTS = [ 'first_name', 'middle_name', 'last_name' ];

    /** Keys Fluent adds itself: _fluentform_N_fluentformnonce, _wp_http_referer, __fluent_form_embded_post_id, captcha tokens. */
    private static function is_internal_key( string $key ): bool {
        return '' === $key
            || '_' === $key[0]
            || in_array( $key, [ 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response' ], true );
    }

    /** [ first_name => Sofia, last_name => Bergström ] -> "Sofia Bergström". */
    private static function join_name( array $value ): string {
        $parts = [];
        foreach ( self::NAME_PARTS as $part ) {
            if ( isset( $value[ $part ] ) && is_scalar( $value[ $part ] ) && '' !== trim( (string) $value[ $part ] ) ) {
                $parts[] = trim( (string) $value[ $part ] );
            }
        }
        return implode( ' ', $parts );
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

    /** The submission's status in Fluent's own table, or ''. */
    private function stored_status( int $entry_id ): string {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-row lookup in Fluent Forms' table.
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $entry_id ) );
        $wpdb->suppress_errors( $suppress );
        return is_string( $status ) ? $status : '';
    }
}
