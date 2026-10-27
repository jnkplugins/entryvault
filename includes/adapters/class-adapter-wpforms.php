<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_WPForms extends Adapter_Base {

    public function slug(): string {
        return 'wpforms';
    }

    public function is_active(): bool {
        return function_exists( 'wpforms' ) || class_exists( '\\WPForms\\WPForms' );
    }

    public function register_hooks(): void {
        add_action( 'wpforms_process_complete', [ $this, 'on_complete' ], 20, 4 );
    }

    public function on_complete( $fields, $entry_data, $form_data, $entry_id ): void {
        $form_id = (int) ( $form_data['id'] ?? 0 );
        if ( ! $form_id ) {
            return;
        }
        // WPForms Lite never saves an entry, and a paid form with entry storage turned
        // off doesn't either: both arrive here with entry_id 0. Those submissions are
        // exactly the ones with no other record on the site, so capture them under an
        // id of our own instead of dropping them.
        $source_id  = (int) $entry_id > 0 ? (string) (int) $entry_id : $this->synthesize_entry_id( $form_id, (array) $fields );
        $normalized = $this->build_normalized( $form_id, $source_id, (array) $fields, current_time( 'mysql', true ) );
        $this->dispatch( $normalized );
    }

    /**
     * Sync_Engine dedupes on (source_plugin, source_form_id, source_entry_id), so a
     * synthesized id must be unique per submission or unrelated submissions would
     * merge. The "wpf_" prefix can never collide with a real WPForms entry id, which
     * is always numeric. Same approach as the Contact Form 7 adapter.
     */
    private function synthesize_entry_id( int $form_id, array $fields ): string {
        $seed = $form_id . '|' . microtime( true ) . '|' . uniqid( '', true );
        if ( function_exists( 'wp_json_encode' ) ) {
            $seed .= '|' . wp_json_encode( $fields );
        }
        return 'wpf_' . md5( $seed );
    }

    /**
     * @param int|string $entry_id A real WPForms entry id, or a synthesized "wpf_" id.
     */
    public function build_normalized( int $form_id, $entry_id, array $raw_fields, ?string $created_at ): array {
        $fields = [];
        foreach ( $raw_fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = isset( $field['id'] ) ? (string) $field['id'] : sanitize_key( (string) ( $field['name'] ?? '' ) );
            $fields[] = [
                'key'   => $key !== '' ? $key : 'field',
                'label' => (string) ( $field['name'] ?? '' ),
                'type'  => (string) ( $field['type'] ?? 'text' ),
                'value' => $field['value'] ?? '',
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
}
