<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Sureforms extends Adapter_Base {

    public function slug(): string {
        return 'sureforms';
    }

    public function is_active(): bool {
        return defined( 'SRFM_VERSION' ) || class_exists( '\\SRFM\\Inc\\Form_Submit' );
    }

    public function register_hooks(): void {
        add_action( 'srfm_form_submit', [ $this, 'on_submit' ], 20, 1 );
    }

    /**
     * Hook callback for `srfm_form_submit`.
     * Response shape: success, form_id, entry_id, to_emails, form_name, message, data.
     * `data` is [ label => value ] from prepare_submission_data().
     */
    public function on_submit( $response ): void {
        if ( ! is_array( $response ) || empty( $response['success'] ) || empty( $response['entry_id'] ) ) {
            return;
        }
        $form_id  = (int) ( $response['form_id']  ?? 0 );
        $entry_id = (int) ( $response['entry_id'] ?? 0 );
        if ( ! $form_id || ! $entry_id ) {
            return;
        }
        $data = is_array( $response['data'] ?? null ) ? $response['data'] : [];

        $normalized = $this->build_normalized( $form_id, $entry_id, $data, current_time( 'mysql' ) );
        if ( ! empty( $response['form_name'] ) && empty( $normalized['title'] ) ) {
            $normalized['title'] = (string) $response['form_name'];
        }
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from a SureForms `[ slug => value ]` map.
     * SureForms stores the prepared key as the slugified label, so we look up
     * the form's blocks to recover the original label and field type.
     */
    public function build_normalized( int $form_id, int $entry_id, array $slug_value_map, ?string $created_at ): array {
        $schema = $this->build_schema_map( $form_id );

        $fields = [];
        foreach ( $slug_value_map as $slug => $value ) {
            $slug = (string) $slug;
            $meta = $schema[ $slug ] ?? null;
            $key  = sanitize_key( $slug );
            if ( $key === '' ) {
                $key = 'field_' . count( $fields );
            }
            $label = $meta['label'] ?? $this->humanize( $slug );
            $type  = $meta['type']  ?? $this->guess_type( $label, $value );
            $fields[] = [
                'key'   => $key,
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

    private const BLOCK_TYPE_MAP = [
        'srfm/input'            => 'text',
        'srfm/email'            => 'email',
        'srfm/textarea'         => 'textarea',
        'srfm/phone'            => 'phone',
        'srfm/number'           => 'number',
        'srfm/url'              => 'url',
        'srfm/dropdown'         => 'select',
        'srfm/multi-choice'     => 'radio',
        'srfm/checkbox'         => 'checkbox',
        'srfm/date-time-picker' => 'date',
        'srfm/upload'           => 'file',
        'srfm/hidden'           => 'hidden',
        'srfm/rating'           => 'rating',
        'srfm/address'          => 'address',
        'srfm/gdpr'             => 'gdpr',
    ];

    /**
     * Walk the form post blocks and return [ slug => [ 'label' => ..., 'type' => ... ] ].
     */
    private function build_schema_map( int $form_id ): array {
        static $cache = [];
        if ( isset( $cache[ $form_id ] ) ) {
            return $cache[ $form_id ];
        }
        $post = get_post( $form_id );
        if ( ! $post || empty( $post->post_content ) ) {
            return $cache[ $form_id ] = [];
        }
        $map = [];
        $this->collect_block_fields( parse_blocks( $post->post_content ), $map );
        return $cache[ $form_id ] = $map;
    }

    private function collect_block_fields( array $blocks, array &$map ): void {
        foreach ( $blocks as $block ) {
            $name  = (string) ( $block['blockName'] ?? '' );
            $attrs = (array) ( $block['attrs'] ?? [] );
            if ( $name && isset( self::BLOCK_TYPE_MAP[ $name ], $attrs['slug'] ) ) {
                $map[ (string) $attrs['slug'] ] = [
                    'label' => trim( (string) ( $attrs['label'] ?? '' ) ),
                    'type'  => self::BLOCK_TYPE_MAP[ $name ],
                ];
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                $this->collect_block_fields( $block['innerBlocks'], $map );
            }
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
