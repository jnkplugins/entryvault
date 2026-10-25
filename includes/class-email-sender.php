<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Email_Sender {

    /** Why the last send() failed ('' after a success). */
    private static string $last_error = '';

    /** The mailer's reason for the last failed send(), e.g. "SMTP Error: Could not authenticate." */
    public static function last_error(): string {
        return self::$last_error;
    }

    /**
     * Send an outbound email tied to a submission.
     *
     * Injects a Reply-To header with the submission's reply_token so inbound
     * IMAP polling can match replies back to the originating submission.
     * Logs a timeline event and inserts a row into wp_entryvault_emails.
     *
     * @param int    $submission_id Submission to associate the email with.
     * @param string $to            Recipient email address.
     * @param string $subject       Subject line.
     * @param string $body          Body (plain text or HTML).
     * @param array  $opts          Optional: from, headers (array), html (bool), attachments (array).
     * @return bool True on wp_mail success.
     */
    public static function send( int $submission_id, string $to, string $subject, string $body, array $opts = [] ): bool {
        $submission = Submission_Store::get( $submission_id );
        if ( ! $submission ) {
            Logger::error( 'email', 'send() called for unknown submission', [ 'submission_id' => $submission_id ] );
            return false;
        }

        $token   = (string) ( $submission['reply_token'] ?? '' );
        $host    = self::reply_host();

        // The plus-addressed Reply-To only leads anywhere if something is polling
        // that mailbox, which is the premium inbound-email feature. Adding it
        // regardless pointed every reply at an address that does not exist on most
        // hosts: the customer hits Reply, the message bounces or vanishes, and the
        // site owner never learns they missed it. Without the header the reply goes
        // to the From address and lands in a real inbox, so only set it when
        // inbound matching is actually running.
        $reply_to = ( $token && self::inbound_tracking_active() )
            ? sprintf( 'pipeline+%s@%s', $token, $host )
            : '';

        $headers = (array) ( $opts['headers'] ?? [] );
        if ( $reply_to ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $from = self::from_header( $opts );
        if ( '' !== $from ) {
            $headers[] = 'From: ' . $from;
        }
        if ( ! empty( $opts['html'] ) ) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        // Generate a stable Message-ID client-side so we can correlate replies
        // (In-Reply-To / References) back to this outbound email.
        // The id's domain is the site's, not the reply host: that host falls back
        // to the IMAP server setting, so outgoing mail advertised the mail server
        // (even a mistyped one) in every Message-ID.
        $message_id = sprintf(
            '<ev-%s-%d@%s>',
            $token !== '' ? $token : substr( md5( (string) $submission_id . microtime( true ) ), 0, 16 ),
            time(),
            self::message_id_host( $host )
        );

        // Hand it to PHPMailer as its own MessageID instead of adding a custom
        // header. As a header, PHPMailer still generated its own Message-Id as
        // well, and every reply went out with two — RFC 5322 allows one, and
        // spam filters and threading disagreed about which one counted.
        $set_message_id = static function ( $phpmailer ) use ( $message_id ) {
            if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'MessageID' ) ) {
                $phpmailer->MessageID = $message_id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
            }
        };
        add_action( 'phpmailer_init', $set_message_id );

        $attachments = (array) ( $opts['attachments'] ?? [] );

        // A failed send was stored and shown as sent: keep the mailer's reason.
        $failure      = '';
        $catch_failed = static function ( $error ) use ( &$failure ) {
            $failure = is_wp_error( $error ) ? (string) $error->get_error_message() : 'wp_mail failed';
        };
        add_action( 'wp_mail_failed', $catch_failed );

        $sent = wp_mail( $to, $subject, $body, $headers, $attachments );

        remove_action( 'phpmailer_init', $set_message_id );
        remove_action( 'wp_mail_failed', $catch_failed );
        // WordPress keeps one PHPMailer for the whole request and never clears
        // MessageID, so the next wp_mail() (Trinity's admin notification, its
        // autoresponder) went out with this email's Message-ID.
        global $phpmailer;
        if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'MessageID' ) ) {
            $phpmailer->MessageID = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
        }
        if ( ! $sent && '' === $failure ) {
            $failure = __( 'The mailer did not accept the email.', 'entryvault' );
        }
        self::$last_error = $sent ? '' : substr( $failure, 0, 500 );

        $now = current_time( 'mysql' );
        $from_addr = ! empty( $opts['from'] ) ? (string) $opts['from'] : ( function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'admin_email' ) : '' );

        global $wpdb;
        $row = [
            'submission_id' => $submission_id,
            'inbox_id'      => null,
            'direction'     => 'out',
            'message_id'    => substr( $message_id, 0, 500 ),
            'in_reply_to'   => null,
            'from_addr'     => substr( $from_addr, 0, 254 ),
            'to_addr'       => substr( $to, 0, 500 ),
            'subject'       => substr( $subject, 0, 500 ),
            'body_text'     => empty( $opts['html'] ) ? $body : null,
            'body_html'     => ! empty( $opts['html'] ) ? $body : null,
            'raw_headers'   => $headers ? wp_json_encode( $headers ) : null,
            'sent_at'       => $now,
        ];
        if ( ! $sent ) {
            // The column arrives with the schema update; until it does, a failed
            // email is left out of the conversation rather than shown as sent.
            if ( self::has_error_column() ) {
                $row['send_error'] = self::$last_error;
                $wpdb->insert( $wpdb->prefix . 'entryvault_emails', $row );
            }
        } else {
            $wpdb->insert( $wpdb->prefix . 'entryvault_emails', $row );
        }

        Timeline::log( $submission_id, 'email_sent', null, [
            'to'       => $to,
            'subject'  => $subject,
            'body'     => $body,
            'reply_to' => $reply_to,
            'sent'     => (bool) $sent,
            'error'    => self::$last_error,
        ] );

        do_action( 'entryvault_email_sent', $submission_id, $to, $subject, (bool) $sent );

        return (bool) $sent;
    }

    private static function has_error_column(): bool {
        static $has = null;
        if ( null === $has ) {
            global $wpdb;
            $has = (bool) $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $wpdb->prefix . 'entryvault_emails', 'send_error' ) );
        }
        return $has;
    }

    /**
     * Is anything actually collecting replies sent to the plus-addressed mailbox?
     *
     * True only when the premium inbound module is present and has at least one
     * inbox configured; the filter lets a bespoke setup declare its own handling.
     */
    private static function inbound_tracking_active(): bool {
        // Answered entirely through the filter. The premium inbound module hooks
        // it; a bespoke setup can too. Naming the premium class here would put a
        // premium symbol in the free build, which the Lite gate rejects — and it
        // would also make the free build's behaviour depend on code it cannot see.
        return (bool) apply_filters( 'entryvault_inbound_tracking_active', false );
    }

    /**
     * The From header for outbound mail.
     *
     * With nothing set, WordPress sends as "WordPress <wordpress@yourdomain>", so
     * someone who wrote to a company received the reply from "WordPress". The
     * sender name and address are now settings; the site name and admin address
     * are the defaults, which are right far more often than WordPress's own.
     */
    private static function from_header( array $opts ): string {
        $explicit = sanitize_email( (string) ( $opts['from'] ?? '' ) );
        if ( '' !== $explicit ) {
            return str_replace( [ "\r", "\n" ], '', $explicit );
        }

        $settings = get_option( 'entryvault_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];

        $address = sanitize_email( (string) ( $settings['from_email'] ?? '' ) );
        if ( '' === $address ) {
            $address = (string) get_bloginfo( 'admin_email' );
        }
        if ( '' === $address ) {
            return '';
        }

        $name = trim( (string) ( $settings['from_name'] ?? '' ) );
        if ( '' === $name ) {
            $name = (string) get_bloginfo( 'name' );
        }
        // WordPress stores the site title HTML-escaped: "Duniya &amp; Friends"
        // went out as the sender name.
        $name = wp_specialchars_decode( $name, ENT_QUOTES );
        $name = str_replace( [ "\r", "\n", '"' ], '', $name );

        return '' !== $name
            ? sprintf( '%s <%s>', $name, $address )
            : $address;
    }

    /**
     * Resolve the host portion used for the plus-addressed Reply-To. Uses the
     * "Reply-to host" setting, falling back to the site host. Extension
     * modules can override via the entryvault_reply_host filter.
     */
    private static function reply_host(): string {
        $settings = get_option( 'entryvault_settings', [] );
        $host     = is_array( $settings ) ? (string) ( $settings['reply_to_host'] ?? '' ) : '';
        $host     = (string) apply_filters( 'entryvault_reply_host', $host );
        if ( $host !== '' ) {
            return $host;
        }
        if ( function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ) {
            $parsed = wp_parse_url( home_url() );
            if ( ! empty( $parsed['host'] ) ) {
                return (string) $parsed['host'];
            }
        }
        // Do not use HTTP_HOST — it is user-controlled in some server configs.
        return 'localhost';
    }

    /** Domain for generated Message-IDs: the site's host, else the given fallback. */
    public static function message_id_host( string $fallback = '' ): string {
        $host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
        if ( '' === $host ) {
            $host = '' !== $fallback ? $fallback : 'localhost';
        }
        return strtolower( $host );
    }
}
