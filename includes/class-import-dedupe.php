<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Recognises the same entry arriving by two routes: a CSV export of a form,
 * and the same form's entries brought in directly (or captured live).
 *
 * Each route has its own id (a CSV row id vs the form plugin's entry id), so
 * Sync_Engine's (source, form, entry id) check never matched them. The persona
 * run of 29 Oct imported a Fluent export, then ran Backfill: 62 cards for 32
 * people. Two rules, both needing the same email:
 *
 *   1. Same moment: sent within two minutes of each other (the CSV row's
 *      mapped date vs the stored date).
 *   2. Same entry id: the CSV row has an entry-id column whose value is the
 *      other entry's id in its form plugin.
 *
 * And a third route: Trinity Forms' importer copies another plugin's entries
 * into Trinity and notes where each came from (entry meta _imported_from +
 * _imported_entry_id, Trinity 2.18.0). The persona run of 30 Oct brought in a
 * Fluent form, imported it into Trinity, then brought in Trinity's entries:
 * 64 cards for 33 people. Such an entry and its origin are one card.
 */
class Import_Dedupe {

    /** Seconds either side that still count as the same moment. */
    public const WINDOW = 120;

    /** CSV rows are stored under this form id (CSV_Importer). */
    public const CSV_FORM = 'csv';

    /**
     * Entry-id field keys a CSV import may hold (the column header is the key).
     *
     * @var string[]
     */
    private const ID_KEYS = [ 'entry_id', 'Entry ID', 'entry id', 'Entry Id', 'Submission ID', 'submission_id', 'ID', 'id', 'Lead ID' ];

    /**
     * Is an entry from a form plugin (payload about to be ingested) already on
     * the board from a CSV import?
     *
     * @param array<string,mixed> $payload Sync_Engine payload.
     * @return int The CSV entry's id, or 0.
     */
    public static function csv_copy_of( array $payload ): int {
        global $wpdb;
        $email = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
        if ( '' === $email ) {
            return 0;
        }
        $t = $wpdb->prefix . 'entryvault_submissions';

        $created = (string) ( $payload['created_at'] ?? '' );
        $ts      = '' !== $created ? Dates::timestamp( $created ) : 0;
        if ( $ts ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE source_form_id = %s AND LOWER(email) = %s AND created_at BETWEEN %s AND %s ORDER BY id ASC LIMIT 1',
                $t,
                self::CSV_FORM,
                $email,
                gmdate( 'Y-m-d H:i:s', $ts - self::WINDOW ),
                gmdate( 'Y-m-d H:i:s', $ts + self::WINDOW )
            ) );
            if ( $id ) {
                return $id;
            }
        }

        $entry_id = (string) ( $payload['source_entry_id'] ?? '' );
        if ( '' === $entry_id ) {
            return 0;
        }
        $keys = implode( ',', array_fill( 0, count( self::ID_KEYS ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own tables; $keys is placeholders only.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT s.id FROM %i s INNER JOIN %i d ON d.submission_id = s.id
              WHERE s.source_form_id = %s AND LOWER(s.email) = %s AND d.field_key IN ({$keys}) AND d.value = %s
              ORDER BY s.id ASC LIMIT 1",
            array_merge( [ $t, $wpdb->prefix . 'entryvault_submission_data', self::CSV_FORM, $email ], self::ID_KEYS, [ $entry_id ] )
        ) );
    }

    /**
     * Is a CSV row already on the board from a form plugin (captured live or
     * brought in directly)?
     *
     * @param string $email      The row's email.
     * @param string $created    The row's mapped date, stored form (UTC), or ''.
     * @param string $entry_id   The row's entry-id column value, or ''.
     * @return int The existing entry's id, or 0.
     */
    public static function source_copy_of( string $email, string $created, string $entry_id ): int {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        if ( '' === $email ) {
            return 0;
        }
        $t  = $wpdb->prefix . 'entryvault_submissions';
        $ts = '' !== $created ? Dates::timestamp( $created ) : 0;
        if ( $ts ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE source_form_id <> %s AND LOWER(email) = %s AND created_at BETWEEN %s AND %s ORDER BY id ASC LIMIT 1',
                $t,
                self::CSV_FORM,
                $email,
                gmdate( 'Y-m-d H:i:s', $ts - self::WINDOW ),
                gmdate( 'Y-m-d H:i:s', $ts + self::WINDOW )
            ) );
            if ( $id ) {
                return $id;
            }
        }
        $entry_id = trim( $entry_id );
        if ( '' === $entry_id ) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE source_form_id <> %s AND LOWER(email) = %s AND source_entry_id = %s ORDER BY id ASC LIMIT 1',
            $t,
            self::CSV_FORM,
            $email,
            $entry_id
        ) );
    }

    /** Trinity importer slug => EntryVault source slug, where the two differ. */
    private const TRINITY_SLUGS = [ 'cf7' => 'contact-form-7' ];

    /**
     * Is this entry already on the board by another route: a CSV export of it,
     * its origin (a Trinity entry imported from another plugin), or a Trinity
     * copy of it (an entry another plugin holds, already imported into Trinity
     * and brought in from there)?
     *
     * @param array<string,mixed> $payload Sync_Engine payload.
     * @return int The entry already on the board, or 0.
     */
    public static function copy_elsewhere( array $payload ): int {
        return self::csv_copy_of( $payload ) ?: ( self::origin_copy_of( $payload ) ?: self::trinity_copy_of( $payload ) );
    }

    /**
     * A Trinity entry imported from another plugin (payload imported_from, set
     * by the Trinity adapter): is the entry it was copied from on the board?
     *
     * @param array<string,mixed> $payload
     */
    public static function origin_copy_of( array $payload ): int {
        $origin = $payload['imported_from'] ?? null;
        if ( ! is_array( $origin ) ) {
            return 0;
        }
        $source = (string) ( $origin['source'] ?? '' );
        $entry  = (string) ( $origin['entry_id'] ?? '' );
        if ( '' === $source || '' === $entry ) {
            return 0;
        }
        $ids = Submission_Store::find_by_source_entry( self::TRINITY_SLUGS[ $source ] ?? $source, $entry );
        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * An entry from another form plugin: was it imported into Trinity Forms and
     * is that Trinity copy on the board?
     *
     * @param array<string,mixed> $payload
     */
    public static function trinity_copy_of( array $payload ): int {
        global $wpdb;
        $source = (string) ( $payload['source_plugin'] ?? '' );
        $entry  = (string) ( $payload['source_entry_id'] ?? '' );
        if ( '' === $source || 'trinity-forms' === $source || '' === $entry ) {
            return 0;
        }
        $meta     = $wpdb->prefix . 'trinity_forms_entry_meta';
        $slugs    = array_merge( [ $source ], array_keys( self::TRINITY_SLUGS, $source, true ) );
        $suppress = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Trinity's table. Missing table = no Trinity = no copy.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT entry_id FROM %i WHERE field_id = '_imported_entry_id' AND value = %s",
            $meta,
            $entry
        ) );
        foreach ( (array) $ids as $id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Trinity's table.
            $from = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT value FROM %i WHERE entry_id = %d AND field_id = '_imported_from' LIMIT 1",
                $meta,
                (int) $id
            ) );
            $found = in_array( $from, $slugs, true ) ? Submission_Store::find_by_source_entry( 'trinity-forms', (string) (int) $id ) : [];
            if ( $found ) {
                $wpdb->suppress_errors( $suppress );
                return (int) $found[0];
            }
        }
        $wpdb->suppress_errors( $suppress );
        return 0;
    }
}
