<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the Spectra (Ultimate Addons for Gutenberg) Form block.
 *
 * Spectra stores no entries. Its only submission hook is `uagb_form_success`
 * ( $form_data ), and it fires only when wp_mail() reported success, so on a site
 * whose mail is broken every lead would be lost. We therefore listen twice:
 *
 * 1. `uagb_form_success` for the normal case.
 * 2. `wp_mail_failed`, but only while Spectra's own AJAX handler
 *    (`uagb_process_forms`) is running. That point is reached only after Spectra
 *    checked the nonce, found the form block and passed reCAPTCHA, so it is a real
 *    submission whose notification email failed.
 *
 * The two are mutually exclusive in one request, and a per-request flag stops a
 * double capture anyway.
 *
 * $form_data is { "id": <block id>, "<field label>": value, "<checkbox name>": [ ... ] }:
 * Spectra keys answers by the label text. Form id: the block id (posted as
 * block_id, also in $form_data['id']). Entry id: an "spectra_" id of our own.
 */
class Adapter_Spectra_Forms extends Adapter_Base {

    private bool $captured = false;

    public function slug(): string {
        return 'spectra-forms';
    }

    public function is_active(): bool {
        return defined( 'UAGB_VER' ) || class_exists( '\\UAGB_Loader' );
    }

    public function register_hooks(): void {
        add_action( 'uagb_form_success', [ $this, 'on_form_success' ], 20, 1 );
        add_action( 'wp_mail_failed', [ $this, 'on_mail_failed' ], 20, 1 );
    }

    public function on_form_success( $form_data ): void {
        $this->capture( $form_data );
    }

    /** Spectra's notification email failed: keep the submission anyway. */
    public function on_mail_failed( $error = null ): void {
        if ( ! $this->in_spectra_request() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Spectra verified its nonce before sending mail.
        $raw = isset( $_POST['form_data'] ) && is_string( $_POST['form_data'] ) ? json_decode( wp_unslash( $_POST['form_data'] ), true ) : null;
        $this->capture( $raw );
    }

    public function capture( $form_data ): void {
        if ( $this->captured || ! is_array( $form_data ) || ! $form_data ) {
            return;
        }
        $form_id = $this->block_id( $form_data );
        $fields  = [];
        foreach ( $form_data as $label => $value ) {
            $label = trim( wp_strip_all_tags( (string) $label ) );
            if ( '' === $label || 'id' === $label ) {
                continue;
            }
            $is_list  = is_array( $value );
            // Spectra hands over the raw posted JSON, so clean it here.
            $value    = $this->flatten_value( $value );
            $value    = is_string( $value ) ? sanitize_textarea_field( $value ) : $value;
            $fields[] = [
                'key'   => sanitize_key( str_replace( ' ', '_', $label ) ) ?: 'field_' . count( $fields ),
                'label' => $label,
                'type'  => $is_list ? 'checkbox' : $this->infer_type( $label, $value ),
                'value' => $value,
            ];
        }
        if ( ! $fields ) {
            return;
        }
        // One capture per Spectra request (success and mail-failed never both count).
        if ( $this->in_spectra_request() ) {
            $this->captured = true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $title   = $post_id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '';

        $this->dispatch( $this->build_payload( $form_id, $this->make_entry_id( 'spectra_', $form_id, $form_data ), $fields, $title ) );
    }

    private function block_id( array $form_data ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $this->in_spectra_request() && isset( $_POST['block_id'] ) && is_scalar( $_POST['block_id'] ) ) {
            $id = sanitize_text_field( wp_unslash( (string) $_POST['block_id'] ) );
            if ( '' !== $id ) {
                return $id;
            }
        }
        return isset( $form_data['id'] ) && is_scalar( $form_data['id'] ) && '' !== (string) $form_data['id']
            ? sanitize_text_field( (string) $form_data['id'] )
            : '0';
    }

    private function in_spectra_request(): bool {
        return doing_action( 'wp_ajax_uagb_process_forms' ) || doing_action( 'wp_ajax_nopriv_uagb_process_forms' );
    }
}
