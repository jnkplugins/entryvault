<?php
namespace EntryVault;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the EntryVault Settings page.
 *
 * Self-registers on `rest_api_init`. Bootstrap from Plugin::boot():
 *   REST_Settings::register();
 *
 * Settings live in the `entryvault_settings` option. This controller manages
 * only the writable subset listed in WRITABLE_KEYS — fields owned by other
 * surfaces (keys registered by extension modules) pass through unchanged.
 *
 * The free build ships no sensitive/premium keys. Premium modules (the Pro
 * Mailchimp integration, IMAP) extend this controller purely through filters:
 *   - entryvault_settings_writable_keys   (array<string>)  — keys it may write
 *   - entryvault_settings_sensitive_keys  (array<string>)  — redacted on read,
 *       and "*** = leave unchanged" on write
 *   - entryvault_settings_sanitize_value  (mixed $value, string $key) — sanitize
 *       a key the free core doesn't know about (e.g. encrypt the Mailchimp key)
 *   - entryvault_settings_decrypt         (array $option)   — decrypt sensitive
 *       values for internal use (masked again by redact() before responses)
 * In the free wordpress.org build none of those filters are registered, so the
 * controller exposes only the free keys and never touches an external service.
 */
class REST_Settings {

    private const NS       = 'entryvault/v1';
    private const OPTION   = 'entryvault_settings';
    private const REDACTED = '***';

    /**
     * Keys the free core writes. Premium modules add their own (e.g.
     * mailchimp_api_key) via the entryvault_settings_writable_keys filter.
     */
    private const WRITABLE_KEYS = [
        'default_pipeline',
        'reply_to_host',
        'from_name',
        'from_email',
        'owner_roles',
        'delete_data_on_uninstall',
        'skip_spam',
        'delete_with_source',
    ];

    /** Writable keys plus any registered by premium modules. @return string[] */
    private static function writable_keys(): array {
        return array_values( array_unique( array_map(
            'strval',
            (array) apply_filters( 'entryvault_settings_writable_keys', self::WRITABLE_KEYS )
        ) ) );
    }

    /**
     * Keys redacted on read and treated as "*** = no change" on write. The free
     * build has none; premium registers theirs (Mailchimp key, IMAP password).
     *
     * @return string[]
     */
    private static function sensitive_keys(): array {
        return array_values( array_unique( array_map(
            'strval',
            (array) apply_filters( 'entryvault_settings_sensitive_keys', [] )
        ) ) );
    }

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_settings' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'update_settings' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return Capabilities::current_user_can_manage();
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    public static function get_settings( WP_REST_Request $req ): WP_REST_Response {
        return self::envelope( self::redact( self::current_option() ) );
    }

    public static function update_settings( WP_REST_Request $req ): WP_REST_Response {
        $params    = self::params( $req );
        $writable  = self::writable_keys();
        $sensitive = self::sensitive_keys();

        // Reject unknown keys (anything outside the writable subset).
        $unknown = array_diff( array_keys( $params ), $writable );
        if ( ! empty( $unknown ) ) {
            return self::envelope(
                null,
                [ [
                    'code'    => 'unknown_keys',
                    'message' => 'Unknown setting keys: ' . implode( ', ', $unknown ),
                ] ],
                400
            );
        }

        // Merge onto the RAW stored option (not the decrypted view) so untouched
        // sensitive values are never rewritten as plaintext.
        $merged = self::raw_option();

        foreach ( $writable as $key ) {
            if ( ! array_key_exists( $key, $params ) ) {
                continue;
            }
            $value = $params[ $key ];

            // Sensitive: '***' means "no change".
            if ( in_array( $key, $sensitive, true ) && self::REDACTED === $value ) {
                continue;
            }

            $merged[ $key ] = self::sanitize_value( $key, $value );
        }

        update_option( self::OPTION, $merged );

        return self::envelope( self::redact( self::current_option() ) );
    }

    /* ---------------------------------------------------------------------
     * Sanitization
     * ------------------------------------------------------------------ */

    private static function sanitize_value( string $key, $value ) {
        switch ( $key ) {
            case 'default_pipeline':
                return (int) $value;

            case 'delete_data_on_uninstall':
            case 'skip_spam':
            case 'delete_with_source':
                return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );

            case 'reply_to_host':
            case 'from_name':
                return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

            case 'from_email':
                return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';

            case 'owner_roles':
                if ( ! is_array( $value ) ) {
                    return [];
                }
                $out = [];
                foreach ( $value as $role ) {
                    if ( ! is_scalar( $role ) ) {
                        continue;
                    }
                    $slug = sanitize_key( (string) $role );
                    if ( '' !== $slug ) {
                        $out[] = $slug;
                    }
                }
                return array_values( array_unique( $out ) );
        }

        // Premium-registered keys (e.g. mailchimp_api_key) sanitize/encrypt here.
        return apply_filters( 'entryvault_settings_sanitize_value', $value, $key );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /** Raw stored option, exactly as persisted (sensitive values still encrypted). */
    private static function raw_option(): array {
        $opt = get_option( self::OPTION, [] );
        return is_array( $opt ) ? $opt : [];
    }

    /**
     * Stored option with sensitive values decrypted for internal use. Premium
     * modules hook entryvault_settings_decrypt to decrypt their keys; redact()
     * masks them again before anything leaves over REST.
     */
    public static function current_option(): array {
        return (array) apply_filters( 'entryvault_settings_decrypt', self::raw_option() );
    }

    private static function redact( array $opt ): array {
        foreach ( self::sensitive_keys() as $k ) {
            if ( ! empty( $opt[ $k ] ) ) {
                $opt[ $k ] = self::REDACTED;
            }
        }
        return $opt;
    }

    public static function params( WP_REST_Request $req ): array {
        $json = $req->get_json_params();
        if ( is_array( $json ) && ! empty( $json ) ) {
            return $json;
        }
        $body = $req->get_body_params();
        if ( is_array( $body ) && ! empty( $body ) ) {
            return $body;
        }
        return $req->get_params();
    }

    public static function envelope( $data, array $errors = [], int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => empty( $errors ),
            'data'    => $data,
            'errors'  => $errors,
        ], $status );
    }
}
