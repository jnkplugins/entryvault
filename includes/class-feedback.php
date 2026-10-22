<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Feedback & Support — free core.
 *
 * Lets a site admin send the plugin author feedback / bug reports through the
 * site's own wp_mail(). No external HTTP, no phone-home — wordpress.org-clean:
 * nothing here references the licensing client or any premium endpoint.
 */
class Feedback {

    /** Same capability the Settings submenu uses. */
    public const CAPABILITY = 'manage_options';

    /** Max feedback messages per user per hour. */
    private const RATE_LIMIT = 5;

    /**
     * Hook the admin-post handler. Idempotent.
     */
    public static function register(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;
        add_action( 'admin_post_entryvault_send_feedback', [ __CLASS__, 'handle' ] );
    }

    /**
     * Handle a submitted Feedback & Support form. Verifies the nonce + capability,
     * drops honeypot hits silently, rate-limits, then mails the author via wp_mail().
     */
    public static function handle(): void {
        check_admin_referer( 'entryvault_feedback' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'entryvault' ) );
        }

        $redirect = admin_url( 'admin.php?page=entryvault-feedback' );

        // Honeypot: a real user never fills a hidden field. Pretend success and drop.
        if ( '' !== trim( sanitize_text_field( wp_unslash( (string) ( $_POST['entryvault_website'] ?? '' ) ) ) ) ) {
            self::redirect( $redirect, 'sent' );
        }

        // Rate-limit: max RATE_LIMIT per hour per user.
        $rl_key = 'entryvault_feedback_rl_' . get_current_user_id();
        $count  = (int) get_transient( $rl_key );
        if ( $count >= self::RATE_LIMIT ) {
            self::redirect( $redirect, 'ratelimited' );
        }

        // Sanitize input.
        $types = self::types();
        $type  = sanitize_key( wp_unslash( (string) ( $_POST['entryvault_type'] ?? '' ) ) );
        if ( ! isset( $types[ $type ] ) ) {
            $type = 'other';
        }

        $message = sanitize_textarea_field( wp_unslash( (string) ( $_POST['entryvault_message'] ?? '' ) ) );
        if ( '' === trim( $message ) ) {
            self::redirect( $redirect, 'error' );
        }

        $reply_email = sanitize_email( wp_unslash( (string) ( $_POST['entryvault_reply_email'] ?? '' ) ) );
        $has_reply   = ( '' !== $reply_email && is_email( $reply_email ) );

        $site_info = ! empty( $_POST['entryvault_site_info'] );

        $host    = wp_parse_url( home_url(), PHP_URL_HOST );
        $host    = is_string( $host ) && '' !== $host ? $host : 'site';
        $subject = sprintf( '[EntryVault Feedback] %s — %s', $types[ $type ], $host );

        $body  = $message . "\n\n";
        $body .= 'Reported by: ' . ( $has_reply ? $reply_email : 'not provided' ) . "\n";

        if ( $site_info ) {
            $theme = wp_get_theme();
            $body .= "\n--- Site info (no personal data) ---\n";
            $body .= 'WordPress: ' . get_bloginfo( 'version' ) . "\n";
            $body .= 'PHP: ' . PHP_VERSION . "\n";
            $body .= 'EntryVault: ' . ( defined( 'ENTRYVAULT_VERSION' ) ? ENTRYVAULT_VERSION : 'unknown' ) . "\n";
            $body .= 'Active theme: ' . $theme->get( 'Name' ) . "\n";
            $body .= 'Site URL: ' . home_url() . "\n";
        }

        /** Where feedback is delivered. Filterable so forks/resellers can redirect it. */
        $to = apply_filters( 'entryvault_feedback_recipient', 'support+entryvault@jnkplugins.com' );

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
        if ( $has_reply ) {
            $headers[] = 'Reply-To: ' . $reply_email;
        }

        $sent = wp_mail( $to, $subject, $body, $headers );

        // Count this attempt against the hourly limit regardless of mail result.
        set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );

        self::redirect( $redirect, $sent ? 'sent' : 'error' );
    }

    /**
     * Whitelisted feedback types: key => display label.
     *
     * @return array<string,string>
     */
    public static function types(): array {
        return [
            'bug'     => __( 'Bug report', 'entryvault' ),
            'feature' => __( 'Feature request', 'entryvault' ),
            'question' => __( 'Question', 'entryvault' ),
            'praise'  => __( 'Praise', 'entryvault' ),
            'other'   => __( 'Other', 'entryvault' ),
        ];
    }

    /**
     * Redirect back to the feedback page with a status flag, then exit.
     */
    private static function redirect( string $base, string $status ): void {
        wp_safe_redirect( add_query_arg( 'entryvault_feedback', $status, $base ) );
        exit;
    }
}
