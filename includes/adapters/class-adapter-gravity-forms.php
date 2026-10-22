<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Gravity_Forms extends Adapter_Base {

    public function slug(): string {
        return 'gravity-forms';
    }

    public function is_active(): bool {
        return class_exists( '\\GFAPI' ) || class_exists( '\\GFForms' );
    }

    public function register_hooks(): void {
        add_action( 'gform_after_submission', [ $this, 'on_after_submission' ], 20, 2 );
    }

    /**
     * Hook callback for `gform_after_submission`.
     *
     * @param array $entry GF entry array.
     * @param array $form  GF form definition.
     */
    public function on_after_submission( $entry, $form ): void {
        if ( ! is_array( $entry ) || ! is_array( $form ) ) {
            return;
        }
        $form_id  = (int) ( $form['id'] ?? 0 );
        $entry_id = (int) ( $entry['id'] ?? 0 );
        if ( ! $form_id || ! $entry_id ) {
            return;
        }
        // Gravity Forms saves spam entries with status "spam" and still fires
        // gform_after_submission for them (Entry Object docs: active|spam|trash).
        if ( 'spam' === (string) ( $entry['status'] ?? '' ) && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }
        $normalized = $this->build_normalized(
            $form_id,
            $entry_id,
            $entry,
            $form,
            $entry['date_created'] ?? current_time( 'mysql' )
        );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from a GF entry. GF stores values keyed by
     * field id (and "id.subId" for compound fields like name/address). The
     * form definition supplies labels and types.
     */
    public function build_normalized( int $form_id, int $entry_id, array $entry, ?array $form, ?string $created_at ): array {
        $fields    = [];
        $gf_fields = is_array( $form['fields'] ?? null ) ? $form['fields'] : [];

        foreach ( $gf_fields as $field ) {
            $field = (array) $field;
            $id    = (string) ( $field['id'] ?? '' );
            if ( $id === '' ) {
                continue;
            }
            $label = (string) ( $field['label'] ?? '' );
            $type  = (string) ( $field['type'] ?? 'text' );

            // Compound fields (name, address) expose sub-inputs.
            if ( ! empty( $field['inputs'] ) && is_array( $field['inputs'] ) ) {
                $parts = [];
                foreach ( $field['inputs'] as $input ) {
                    $iid = (string) ( $input['id'] ?? '' );
                    if ( $iid === '' ) {
                        continue;
                    }
                    $val = $entry[ $iid ] ?? '';
                    if ( $val !== '' && $val !== null ) {
                        $parts[] = is_scalar( $val ) ? (string) $val : '';
                    }
                }
                $value = trim( implode( ' ', array_filter( $parts ) ) );
            } else {
                $value = $entry[ $id ] ?? '';
            }

            $fields[] = [
                'key'   => $id,
                'label' => $label,
                'type'  => $type,
                'value' => $value,
            ];
        }

        // Fallback when form definition wasn't available: surface raw numeric keys.
        if ( empty( $fields ) ) {
            foreach ( $entry as $k => $v ) {
                if ( ! is_numeric( $k ) && ! preg_match( '/^\d+\.\d+$/', (string) $k ) ) {
                    continue;
                }
                $fields[] = [
                    'key'   => (string) $k,
                    'label' => (string) $k,
                    'type'  => 'text',
                    'value' => $v,
                ];
            }
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
}
