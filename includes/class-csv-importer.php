<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * CSV import tool for EntryVault submissions.
 *
 * Parses CSV input (handling quoted fields, embedded newlines, and mixed
 * line endings) and pushes each row through Sync_Engine::ingest() using a
 * caller-supplied column mapping.
 */
class CSV_Importer {

    /**
     * Parse a CSV blob into headers + associative rows.
     *
     * Handles \r\n, \n, and \r line endings, quoted fields containing
     * commas/newlines, and "" escaped quotes inside quoted fields. The first
     * non-empty line is treated as the header row.
     *
     * @return array{headers: array<int,string>, rows: array<int,array<string,string>>}
     */
    public static function parse( string $csv ): array {
        // Strip UTF-8 BOM if present.
        if ( substr( $csv, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $csv = substr( $csv, 3 );
        }
        // Normalise line endings to \n. Mac \r becomes \n; Windows \r\n -> \n.
        $csv = str_replace( [ "\r\n", "\r" ], "\n", $csv );

        $lines = self::split_records( $csv );
        if ( empty( $lines ) ) {
            return [ 'headers' => [], 'rows' => [] ];
        }

        $headers = self::parse_line( array_shift( $lines ) );
        $headers = array_map( static fn( $h ) => trim( (string) $h ), $headers );

        $rows = [];
        foreach ( $lines as $line ) {
            if ( $line === '' ) {
                continue;
            }
            $cols = self::parse_line( $line );
            $assoc = [];
            foreach ( $headers as $i => $h ) {
                $assoc[ $h ] = isset( $cols[ $i ] ) ? trim( (string) $cols[ $i ] ) : '';
            }
            $rows[] = $assoc;
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }

    /**
     * Split CSV text into records, respecting quoted fields that may contain
     * embedded newlines.
     *
     * @return array<int,string>
     */
    private static function split_records( string $csv ): array {
        $records = [];
        $buf     = '';
        $in_q    = false;
        $len     = strlen( $csv );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $csv[ $i ];
            if ( $ch === '"' ) {
                // Toggle quote state, but a doubled quote inside a quoted
                // field is an escaped quote — keep it as part of the buffer.
                if ( $in_q && $i + 1 < $len && $csv[ $i + 1 ] === '"' ) {
                    $buf .= '""';
                    $i++;
                    continue;
                }
                $in_q = ! $in_q;
                $buf .= $ch;
                continue;
            }
            if ( $ch === "\n" && ! $in_q ) {
                $records[] = $buf;
                $buf       = '';
                continue;
            }
            $buf .= $ch;
        }
        if ( $buf !== '' ) {
            $records[] = $buf;
        }
        return $records;
    }

    /**
     * Parse a single CSV line into an array of fields.
     *
     * @return array<int,string>
     */
    private static function parse_line( string $line ): array {
        $fields = [];
        $cur    = '';
        $in_q   = false;
        $len    = strlen( $line );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $line[ $i ];
            if ( $in_q ) {
                if ( $ch === '"' ) {
                    if ( $i + 1 < $len && $line[ $i + 1 ] === '"' ) {
                        $cur .= '"';
                        $i++;
                        continue;
                    }
                    $in_q = false;
                    continue;
                }
                $cur .= $ch;
                continue;
            }
            if ( $ch === '"' ) {
                $in_q = true;
                continue;
            }
            if ( $ch === ',' ) {
                $fields[] = $cur;
                $cur      = '';
                continue;
            }
            $cur .= $ch;
        }
        $fields[] = $cur;
        return $fields;
    }

    /**
     * Import rows using the supplied mapping.
     *
     * Mapping keys:
     *   - title_col   (string) header to use as submission title
     *   - email_col   (string) header to use as email (required, validated)
     *   - source_col  (string) optional header to use as source_plugin label
     *   - pipeline_id (int)    forced pipeline id (optional)
     *   - stage_id    (int)    forced stage id (optional)
     *   - fields_cols (string[]) headers to copy as entryvault_submission_data fields
     *   - date_col    (string) optional header holding when the entry was sent;
     *                 read in the site's time zone (or the offset it carries) and
     *                 kept as the entry's date, so old leads keep their dates
     *   - id_col      (string) optional header with the form plugin's entry id
     *                 (found by its name when not given)
     *
     * Options:
     *   - dry_run (bool) skip Sync_Engine::ingest() but still validate rows
     *   - default_source (string) fallback source_plugin when source_col empty
     *   - run_rules (bool) default false. Passed to the entryvault_import_begin
     *     action so extensions that react to new submissions (e.g. sending an
     *     email per new lead) can stay quiet for a bulk import of old rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $mapping
     * @param array<string,mixed>            $opts
     * @return array{imported:int, skipped:int, duplicates:int, errors:array<int,array{row:int,message:string}>}
     */
    public static function import( array $rows, array $mapping, array $opts = [] ): array {
        $title_col   = (string) ( $mapping['title_col']  ?? '' );
        $email_col   = (string) ( $mapping['email_col']  ?? '' );
        $source_col  = (string) ( $mapping['source_col'] ?? '' );
        $pipeline_id = (int)    ( $mapping['pipeline_id'] ?? 0 );
        $stage_id    = (int)    ( $mapping['stage_id']    ?? 0 );
        $fields_cols = (array)  ( $mapping['fields_cols'] ?? [] );
        $date_col    = (string) ( $mapping['date_col'] ?? '' );

        $dry_run        = ! empty( $opts['dry_run'] );
        $default_source = (string) ( $opts['default_source'] ?? 'csv_import' );

        // The form plugin's entry id column, when the file has one (a Fluent Forms
        // export has "entry_id"). Named in the mapping, or found by its header.
        $id_col = (string) ( $mapping['id_col'] ?? '' );
        if ( '' === $id_col && $rows ) {
            $id_col = self::detect_id_column( array_keys( (array) reset( $rows ) ) );
        }
        $seen = [];

        $imported   = 0;
        $skipped    = 0;
        $duplicates = 0;
        $bad_dates  = 0;
        $errors   = [];

        $engine = new Sync_Engine();

        // If the caller forces pipeline/stage, swap the relevant settings
        // for the duration of the import and restore them afterwards.
        $prev_settings = function_exists( 'get_option' ) ? get_option( 'entryvault_settings', [] ) : [];
        if ( $pipeline_id > 0 && function_exists( 'update_option' ) ) {
            $tmp                       = is_array( $prev_settings ) ? $prev_settings : [];
            $tmp['default_pipeline']   = $pipeline_id;
            update_option( 'entryvault_settings', $tmp );
        }
        $stage_filter = null;
        if ( $stage_id > 0 && function_exists( 'add_filter' ) ) {
            $stage_filter = static function ( $resolved, $pid, $normalized ) use ( $stage_id, $pipeline_id ) {
                if ( $pipeline_id > 0 && (int) $pid !== (int) $pipeline_id ) {
                    return $resolved;
                }
                return $stage_id;
            };
            add_filter( 'entryvault_initial_stage', $stage_filter, 99, 3 );
        }

        /**
         * Fires before a (non-dry-run) import ingests its rows.
         *
         * @param array $context { run_rules: bool } — false means listeners that
         *                       act on each new submission should stand down.
         */
        if ( ! $dry_run ) {
            do_action( 'entryvault_import_begin', [ 'run_rules' => ! empty( $opts['run_rules'] ) ] );
        }

        try {
        foreach ( $rows as $i => $raw ) {
            $row_num = $i + 1;
            $row     = self::sanitize_row( $raw );

            if ( $email_col === '' || ! array_key_exists( $email_col, $row ) ) {
                $skipped++;
                $errors[] = [ 'row' => $row_num, 'message' => 'Missing email column in mapping.' ];
                continue;
            }
            $email = (string) $row[ $email_col ];
            if ( $email === '' || ! self::is_email( $email ) ) {
                $skipped++;
                $errors[] = [ 'row' => $row_num, 'message' => 'Invalid email: ' . $email ];
                continue;
            }

            $title  = $title_col !== '' ? (string) ( $row[ $title_col ] ?? '' ) : '';
            $source = $source_col !== '' && ! empty( $row[ $source_col ] )
                ? (string) $row[ $source_col ]
                : $default_source;

            // A stable source_entry_id, so importing the file again (or a newer
            // export of the same form) recognises rows already here. It used to
            // hash the row number: Fluent Forms exports newest first, so every
            // new entry shifted the others down and a second export duplicated
            // every row. Now: the form plugin's own entry id when the file has
            // one, else the email + the row's values. Identical rows in one file
            // still count separately (the 2nd copy gets "#2").
            // When the entry was sent, as the file says. Without it every old lead
            // landed as "just now" and the board read "32 new today".
            $created = '';
            if ( '' !== $date_col && '' !== (string) ( $row[ $date_col ] ?? '' ) ) {
                $created = self::parse_date( (string) $row[ $date_col ] );
                if ( '' === $created ) {
                    $bad_dates++;
                }
            }

            $identity = self::row_identity( $row, $email, $title . '|' . $created, $fields_cols, $id_col );
            $seen[ $identity ] = ( $seen[ $identity ] ?? 0 ) + 1;
            if ( $seen[ $identity ] > 1 ) {
                $identity .= '#' . $seen[ $identity ];
            }
            $entry_id = substr( md5( $identity ), 0, 32 );

            $fields = [];
            foreach ( $fields_cols as $key ) {
                $key = (string) $key;
                if ( $key === '' || ! array_key_exists( $key, $row ) ) {
                    continue;
                }
                $fields[] = [
                    'key'   => $key,
                    'label' => $key,
                    'type'  => 'text',
                    'value' => (string) $row[ $key ],
                ];
            }

            $payload = [
                'source_plugin'   => $source,
                'source_form_id'  => 'csv',
                'source_entry_id' => $entry_id,
                'title'           => $title !== '' ? $title : $email,
                'email'           => $email,
                'fields'          => $fields,
            ];
            if ( '' !== $created ) {
                $payload['created_at'] = $created;
            }

            // Already on the board from the form plugin itself (captured live,
            // or brought in from it directly)? Same person, same moment or the
            // same entry id: a different route to the same entry.
            if ( Import_Dedupe::source_copy_of( $email, $created, '' !== $id_col ? (string) ( $row[ $id_col ] ?? '' ) : '' ) ) {
                $duplicates++;
                continue;
            }

            if ( $dry_run ) {
                $imported++;
                continue;
            }

            try {
                // ingest() returns the id of an existing row when it recognises a
                // duplicate, so testing `> 0` counted every skipped duplicate as a
                // fresh import: re-running the same file reported "Imported: 3"
                // while inserting nothing, and sent people looking for leads that
                // were never added. Ask what actually happened instead.
                $result = $engine->ingest_result( $payload );
                if ( $result['created'] ) {
                    $imported++;
                } elseif ( $result['id'] > 0 ) {
                    $duplicates++;
                } else {
                    $skipped++;
                    $errors[] = [ 'row' => $row_num, 'message' => 'Ingest returned 0 (no pipeline/stage?).' ];
                }
            } catch ( \Throwable $e ) {
                $skipped++;
                $errors[] = [ 'row' => $row_num, 'message' => $e->getMessage() ];
            }
        }
        } finally {
            if ( ! $dry_run ) {
                /** Fires after an import finishes (always paired with entryvault_import_begin). */
                do_action( 'entryvault_import_end' );
                // The board's "Past entries found" box counts what is not on
                // the board yet; these rows may be some of those entries.
                Past_Entries::flush();
            }
            if ( $pipeline_id > 0 && function_exists( 'update_option' ) ) {
                update_option( 'entryvault_settings', $prev_settings );
            }
            if ( $stage_filter !== null && function_exists( 'remove_filter' ) ) {
                remove_filter( 'entryvault_initial_stage', $stage_filter, 99 );
            }
        }

        return [
            'imported'   => $imported,
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
            'bad_dates'  => $bad_dates,
            'errors'     => $errors,
        ];
    }

    /**
     * Apply wp_unslash + trim to every value in the row.
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private static function sanitize_row( array $row ): array {
        $out = [];
        foreach ( $row as $k => $v ) {
            $v = is_array( $v ) ? '' : (string) $v;
            if ( function_exists( 'wp_unslash' ) ) {
                $v = wp_unslash( $v );
            } else {
                $v = stripslashes( $v );
            }
            $out[ (string) $k ] = trim( $v );
        }
        return $out;
    }

    /**
     * What makes a row the same row on the next import.
     *
     * @param array<string,string> $row
     * @param array<int,string>    $fields_cols
     */
    private static function row_identity( array $row, string $email, string $title, array $fields_cols, string $id_col ): string {
        $email = strtolower( $email );
        if ( '' !== $id_col && '' !== (string) ( $row[ $id_col ] ?? '' ) ) {
            return 'id|' . $row[ $id_col ] . '|' . $email;
        }
        $values = [];
        foreach ( $fields_cols as $col ) {
            $col = (string) $col;
            if ( '' !== $col && array_key_exists( $col, $row ) && ! self::is_status_header( $col ) ) {
                $values[ $col ] = (string) $row[ $col ];
            }
        }
        ksort( $values );
        return 'row|' . $email . '|' . $title . '|' . implode( '|', $values );
    }

    /**
     * The header of an entry-id column, or ''. Matches the exports of the common
     * form plugins ("entry_id", "Entry ID", "Submission ID", "ID").
     *
     * @param array<int,string> $headers
     */
    public static function detect_id_column( array $headers ): string {
        foreach ( $headers as $h ) {
            $norm = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $h ) ) );
            if ( in_array( $norm, [ 'entry id', 'entryid', 'submission id', 'id', 'entry', 'lead id' ], true ) ) {
                return (string) $h;
            }
        }
        return '';
    }

    /**
     * A date from the file as a stored (UTC) "Y-m-d H:i:s", or '' when it cannot
     * be read. Read in the site's time zone unless the value carries an offset
     * ("2026-10-29T06:18:59Z", "+02:00"), which is how form plugins export.
     */
    public static function parse_date( string $value ): string {
        $value = trim( $value );
        if ( '' === $value || ! preg_match( '/\d/', $value ) ) {
            return '';
        }
        // A bare number is a Unix timestamp only when it is long enough to be one.
        if ( preg_match( '/^\d{9,10}$/', $value ) ) {
            return gmdate( 'Y-m-d H:i:s', (int) $value );
        }
        try {
            $dt = new \DateTimeImmutable( $value, function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            return '';
        }
        $year = (int) $dt->format( 'Y' );
        if ( $year < 1990 || $year > (int) gmdate( 'Y' ) + 1 ) {
            return '';
        }
        return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }

    /** A read/unread status column changes between exports; it is not part of who sent what. */
    private static function is_status_header( string $h ): bool {
        $norm = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', $h ) ) );
        return in_array( $norm, [ 'status', 'entry status', 'read', 'is read', 'starred', 'is favourite', 'is favorite' ], true );
    }

    /**
     * Email validator. Prefers core is_email() when available.
     */
    private static function is_email( string $email ): bool {
        if ( function_exists( 'is_email' ) ) {
            return (bool) is_email( $email );
        }
        return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
    }
}
