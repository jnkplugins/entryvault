<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer.

/**
 * WordPress privacy tools: Tools > Export Personal Data and Erase Personal Data.
 *
 * EntryVault keeps copies of form entries, so a request for "everything you
 * hold about me" or "delete me" must reach them too. Both work by email
 * address: entries whose email is the requester's, plus emails sent to or
 * received from that address. Free, because the free build stores the data.
 *
 * Erasing deletes the entry with everything attached (fields, notes, tags,
 * history, emails, approvals) through Submission_Store::delete(). An extension
 * can keep an entry (for example under a legal hold) with the
 * `entryvault_privacy_erase_submission` filter; it is then reported as
 * retained, with the reason, on the request screen.
 */
class Privacy {

    private const PER_PAGE = 50;
    private const GROUP    = 'entryvault-entries';

    public static function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
        add_action( 'admin_init', [ __CLASS__, 'privacy_policy_text' ] );
    }

    /** @param array $exporters */
    public static function register_exporter( $exporters ): array {
        $exporters = is_array( $exporters ) ? $exporters : [];
        $exporters['entryvault'] = [
            'exporter_friendly_name' => __( 'EntryVault form entries', 'entryvault' ),
            'callback'               => [ __CLASS__, 'export' ],
        ];
        return $exporters;
    }

    /** @param array $erasers */
    public static function register_eraser( $erasers ): array {
        $erasers = is_array( $erasers ) ? $erasers : [];
        $erasers['entryvault'] = [
            'eraser_friendly_name' => __( 'EntryVault form entries', 'entryvault' ),
            'callback'             => [ __CLASS__, 'erase' ],
        ];
        return $erasers;
    }

    /** Suggested text for Settings > Privacy > Policy Guide. */
    public static function privacy_policy_text(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        wp_add_privacy_policy_content(
            'EntryVault',
            '<p>' . esc_html__( 'When you send a form on this site, a copy of what you entered (including your name and email address if the form asks for them) is kept so our team can follow up. We keep it until it is no longer needed, then delete it. You can ask for a copy of it, or for it to be deleted, by contacting us.', 'entryvault' ) . '</p>'
        );
    }

    /**
     * Exporter callback.
     *
     * @return array{data:array<int,array<string,mixed>>,done:bool}
     */
    public static function export( $email, $page = 1 ): array {
        $email = self::clean_email( $email );
        $page  = max( 1, (int) $page );
        if ( '' === $email ) {
            return [ 'data' => [], 'done' => true ];
        }

        $ids  = self::submission_ids( $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
        $data = [];
        foreach ( $ids as $id ) {
            $item = self::export_item( $id );
            if ( $item ) {
                $data[] = $item;
            }
        }

        // Emails with no entry attached (unmatched replies) go out on the first page.
        if ( 1 === $page ) {
            foreach ( self::loose_emails( $email ) as $row ) {
                $data[] = [
                    'group_id'    => self::GROUP . '-emails',
                    'group_label' => __( 'Emails', 'entryvault' ),
                    'item_id'     => 'entryvault-email-' . (int) $row['id'],
                    'data'        => self::email_pairs( $row ),
                ];
            }
        }

        return [ 'data' => $data, 'done' => count( $ids ) < self::PER_PAGE ];
    }

    /**
     * Eraser callback. Always erases from the first page: rows deleted on one
     * call are gone on the next, so an offset would skip entries.
     *
     * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
     */
    public static function erase( $email, $page = 1 ): array {
        $email    = self::clean_email( $email );
        $removed  = false;
        $retained = false;
        $messages = [];
        if ( '' === $email ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }

        // Entries an extension keeps are skipped by id so the loop moves on.
        $kept = (array) get_transient( self::kept_key( $email ) );
        $ids  = self::submission_ids( $email, self::PER_PAGE, 0, array_map( 'intval', $kept ) );
        foreach ( $ids as $id ) {
            /**
             * Return a reason (string) to keep this entry instead of erasing it.
             *
             * @param string|null $keep_reason   Null to erase.
             * @param int         $submission_id Entry id.
             * @param string      $email         Requester's email.
             */
            $reason = apply_filters( 'entryvault_privacy_erase_submission', null, $id, $email );
            if ( is_string( $reason ) && '' !== $reason ) {
                $retained   = true;
                $kept[]     = $id;
                $messages[] = sprintf( /* translators: 1: entry id, 2: reason. */ __( 'EntryVault entry #%1$d was kept: %2$s', 'entryvault' ), $id, $reason );
                continue;
            }
            if ( Submission_Store::delete( $id ) ) {
                $removed = true;
            }
        }
        $done = count( $ids ) < self::PER_PAGE;

        if ( $done ) {
            global $wpdb;
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}entryvault_emails WHERE submission_id IS NULL AND ( from_addr = %s OR to_addr = %s )",
                $email,
                $email
            ) );
            if ( $deleted ) {
                $removed = true;
            }
            delete_transient( self::kept_key( $email ) );
        } else {
            set_transient( self::kept_key( $email ), array_values( array_unique( $kept ) ), HOUR_IN_SECONDS );
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => $done,
        ];
    }

    /* ------------------------------------------------------------------ */

    private static function clean_email( $email ): string {
        $email = is_string( $email ) ? strtolower( trim( $email ) ) : '';
        return ( '' !== $email && is_email( $email ) ) ? $email : '';
    }

    private static function kept_key( string $email ): string {
        return 'entryvault_privacy_kept_' . md5( $email );
    }

    /**
     * Entries for one email: the entry's own email column, or an email sent to
     * or from it that is linked to the entry.
     *
     * @param int[] $exclude
     * @return int[]
     */
    private static function submission_ids( string $email, int $limit, int $offset, array $exclude = [] ): array {
        global $wpdb;
        $s = $wpdb->prefix . 'entryvault_submissions';
        $e = $wpdb->prefix . 'entryvault_emails';
        $not_in = '';
        $params = [ $email, $email, $email ];
        $exclude = array_values( array_filter( $exclude, static fn( $v ) => $v > 0 ) );
        if ( $exclude ) {
            $not_in = ' AND s.id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
            $params = array_merge( $params, $exclude );
        }
        $params[] = $limit;
        $params[] = $offset;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names are prefixed constants; $not_in is %d placeholders bound below.
        $sql = "SELECT s.id FROM {$s} s
                 WHERE LOWER( s.email ) = %s
                    OR EXISTS ( SELECT 1 FROM {$e} m WHERE m.submission_id = s.id AND ( LOWER( m.from_addr ) = %s OR LOWER( m.to_addr ) = %s ) )
                 {$not_in}
                 ORDER BY s.id ASC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared here.
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
    }

    /** @return array<int,array<string,mixed>> */
    private static function loose_emails( string $email ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, direction, from_addr, to_addr, subject, body_text, sent_at FROM {$wpdb->prefix}entryvault_emails
              WHERE submission_id IS NULL AND ( LOWER( from_addr ) = %s OR LOWER( to_addr ) = %s ) ORDER BY id ASC",
            $email,
            $email
        ), ARRAY_A );
    }

    /** One entry as the exporter's name/value pairs. */
    private static function export_item( int $id ): ?array {
        global $wpdb;
        $row = Submission_Store::get( $id );
        if ( ! $row ) {
            return null;
        }
        $source  = (string) $row['source_plugin'];
        $form_id = (string) $row['source_form_id'];
        $form    = ctype_digit( $form_id ) ? Sources::form_title( $source, (int) $form_id ) : '';

        $pairs = [
            [ 'name' => __( 'Received', 'entryvault' ), 'value' => (string) $row['created_at'] ],
            [ 'name' => __( 'Source', 'entryvault' ), 'value' => trim( Sources::label( $source ) . ( '' !== $form ? ' / ' . $form : '' ) ) ],
            [ 'name' => __( 'Title', 'entryvault' ), 'value' => (string) $row['title'] ],
            [ 'name' => __( 'Email', 'entryvault' ), 'value' => (string) $row['email'] ],
        ];
        $fields = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT field_label, field_key, field_type, value FROM {$wpdb->prefix}entryvault_submission_data WHERE submission_id = %d ORDER BY id ASC",
            $id
        ), ARRAY_A );
        foreach ( $fields as $f ) {
            $value = Field_Values::display( $f['value'], (string) $f['field_type'] );
            if ( '' === trim( $value ) ) {
                continue;
            }
            $pairs[] = [ 'name' => '' !== (string) $f['field_label'] ? (string) $f['field_label'] : (string) $f['field_key'], 'value' => $value ];
        }

        $notes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT data, meta FROM {$wpdb->prefix}entryvault_timeline WHERE submission_id = %d AND type = 'note' ORDER BY id ASC",
            $id
        ), ARRAY_A );
        foreach ( $notes as $note ) {
            $meta = json_decode( (string) $note['meta'], true );
            if ( is_array( $meta ) && ! empty( $meta['deleted_at'] ) ) {
                continue;
            }
            $data = json_decode( (string) $note['data'], true );
            $text = is_array( $data ) ? (string) ( $data['body'] ?? $data['text'] ?? $data['note'] ?? '' ) : '';
            if ( '' !== $text ) {
                $pairs[] = [ 'name' => __( 'Note', 'entryvault' ), 'value' => $text ];
            }
        }

        $emails = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, direction, from_addr, to_addr, subject, body_text, sent_at FROM {$wpdb->prefix}entryvault_emails WHERE submission_id = %d ORDER BY id ASC",
            $id
        ), ARRAY_A );
        foreach ( $emails as $m ) {
            foreach ( self::email_pairs( $m ) as $p ) {
                $pairs[] = $p;
            }
        }

        return [
            'group_id'          => self::GROUP,
            'group_label'       => __( 'Form entries', 'entryvault' ),
            'group_description' => __( 'Copies of forms you sent on this site, kept by EntryVault.', 'entryvault' ),
            'item_id'           => 'entryvault-entry-' . $id,
            'data'              => $pairs,
        ];
    }

    /** @param array<string,mixed> $m */
    private static function email_pairs( array $m ): array {
        $label = 'out' === (string) $m['direction'] ? __( 'Email sent', 'entryvault' ) : __( 'Email received', 'entryvault' );
        return [
            [ 'name' => $label, 'value' => trim( (string) $m['sent_at'] . ' ' . (string) $m['from_addr'] . ' > ' . (string) $m['to_addr'] . ': ' . (string) $m['subject'] ) ],
            [ 'name' => __( 'Email text', 'entryvault' ), 'value' => (string) $m['body_text'] ],
        ];
    }
}
