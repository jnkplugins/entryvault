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

        $dry_run        = ! empty( $opts['dry_run'] );
        $default_source = (string) ( $opts['default_source'] ?? 'csv_import' );

        $imported   = 0;
        $skipped    = 0;
        $duplicates = 0;
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

            // Build a stable source_entry_id so re-imports are idempotent
            // through Submission_Store::find_by_source(). We use a hash of
            // (email + title + row_index) so duplicate rows in the same file
            // don't all collapse onto one submission.
            $entry_id = substr( md5( $email . '|' . $title . '|' . $row_num . '|' . self::serialize_row( $row ) ), 0, 32 );

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

    private static function serialize_row( array $row ): string {
        ksort( $row );
        return implode( '|', array_map( static fn( $v ) => (string) $v, $row ) );
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
