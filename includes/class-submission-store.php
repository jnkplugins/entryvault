<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Submission_Store {

    public static function find_by_source( string $plugin, string $form_id, string $entry_id ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}entryvault_submissions
              WHERE source_plugin = %s AND source_form_id = %s AND source_entry_id = %s",
            $plugin, $form_id, $entry_id
        ) );
        return $id ? (int) $id : null;
    }

    public static function insert( array $row ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'entryvault_submissions', array_merge( [
            'status'      => 'open',
            'created_at'  => $now,
            'updated_at'  => $now,
            // Lowercase hex (not wp_generate_password's mixed-case alphanumeric):
            // this token round-trips through an email Reply-To local part
            // (pipeline+{token}@host) and is matched inbound by the premium
            // reply-tracker's /pipeline\+([a-f0-9]{32})@/ regex. Mail servers may
            // lowercase the local part in transit, so the token must be
            // case-invariant or the reply would never match. 16 random bytes =
            // 32 hex chars, fits reply_token VARCHAR(40).
            'reply_token' => bin2hex( random_bytes( 16 ) ),
        ], $row ) );
        return (int) $wpdb->insert_id;
    }

    public static function insert_field( int $submission_id, array $field ): void {
        global $wpdb;
        $value = is_array( $field['value'] ?? null ) ? wp_json_encode( $field['value'] ) : ( $field['value'] ?? '' );
        $wpdb->insert( $wpdb->prefix . 'entryvault_submission_data', [
            'submission_id' => $submission_id,
            'field_key'     => substr( (string) ( $field['key']   ?? '' ), 0, 100 ),
            'field_label'   => substr( (string) ( $field['label'] ?? '' ), 0, 255 ),
            'field_type'    => substr( (string) ( $field['type']  ?? 'text' ), 0, 40 ),
            'value'         => $value,
            'is_stale'      => 0,
        ] );
    }

    /**
     * Upsert field rows for a submission and mark previously-stored fields not
     * present in the new payload as stale (per F-04). Existing rows are
     * updated in-place by `field_key`; new rows are inserted. Fields that
     * reappear after being stale are un-staled.
     *
     * @param int   $submission_id
     * @param array $fields_payload Each entry: [ key, label, type, value ]
     * @return array{updated:int, inserted:int, staled:int, unstaled:int}
     */
    public static function reconcile_fields( int $submission_id, array $fields_payload ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'entryvault_submission_data';

        $existing = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key, is_stale FROM %i WHERE submission_id = %d",
            $table,
            $submission_id
        ), ARRAY_A );
        $existing = is_array( $existing ) ? $existing : [];

        $by_key = [];
        foreach ( $existing as $row ) {
            $by_key[ (string) ( $row['field_key'] ?? '' ) ] = $row;
        }

        $seen_keys = [];
        $updated   = 0;
        $inserted  = 0;
        $unstaled  = 0;

        foreach ( $fields_payload as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key   = substr( (string) ( $field['key'] ?? '' ), 0, 100 );
            if ( $key === '' ) {
                continue;
            }
            $seen_keys[] = $key;
            $value       = is_array( $field['value'] ?? null )
                ? wp_json_encode( $field['value'] )
                : ( $field['value'] ?? '' );
            $label = substr( (string) ( $field['label'] ?? '' ), 0, 255 );
            $type  = substr( (string) ( $field['type']  ?? 'text' ), 0, 40 );

            if ( isset( $by_key[ $key ] ) ) {
                $row_id = (int) ( $by_key[ $key ]['id'] ?? 0 );
                $was_stale = (int) ( $by_key[ $key ]['is_stale'] ?? 0 );
                $wpdb->update(
                    $table,
                    [
                        'field_label' => $label,
                        'field_type'  => $type,
                        'value'       => $value,
                        'is_stale'    => 0,
                    ],
                    [ 'id' => $row_id ]
                );
                $updated++;
                if ( $was_stale ) {
                    $unstaled++;
                }
            } else {
                $wpdb->insert( $table, [
                    'submission_id' => $submission_id,
                    'field_key'     => $key,
                    'field_label'   => $label,
                    'field_type'    => $type,
                    'value'         => $value,
                    'is_stale'      => 0,
                ] );
                $inserted++;
            }
        }

        $staled = 0;
        foreach ( $existing as $row ) {
            $key = (string) ( $row['field_key'] ?? '' );
            if ( in_array( $key, $seen_keys, true ) ) {
                continue;
            }
            if ( (int) ( $row['is_stale'] ?? 0 ) === 1 ) {
                continue;
            }
            $wpdb->update(
                $table,
                [ 'is_stale' => 1 ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ]
            );
            $staled++;
        }

        return [
            'updated'  => $updated,
            'inserted' => $inserted,
            'staled'   => $staled,
            'unstaled' => $unstaled,
        ];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}entryvault_submissions WHERE id = %d",
            $id
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Update whitelisted columns on a submission row.
     *
     * Only `title` and `owner_user_id` are accepted. Other keys are silently
     * ignored. Returns true on success, false if the row doesn't exist, or a
     * WP_Error on validation/db failure.
     *
     * @param int   $id
     * @param array $args { title?: string, owner_id?: int|null, owner_user_id?: int|null }
     * @return bool|\WP_Error
     */
    public static function update( int $id, array $args ) {
        global $wpdb;
        if ( $id <= 0 ) {
            return new \WP_Error( 'invalid_id', 'Submission id is required.' );
        }
        $existing = self::get( $id );
        if ( ! $existing ) {
            return new \WP_Error( 'not_found', 'Submission not found.' );
        }

        $update = [];

        if ( array_key_exists( 'title', $args ) ) {
            $title = is_string( $args['title'] ) ? trim( $args['title'] ) : '';
            $update['title'] = substr( $title, 0, 255 );
        }

        // Accept owner_id (REST-facing) or owner_user_id (column name).
        $owner_key = null;
        if ( array_key_exists( 'owner_user_id', $args ) ) {
            $owner_key = 'owner_user_id';
        } elseif ( array_key_exists( 'owner_id', $args ) ) {
            $owner_key = 'owner_id';
        }
        if ( $owner_key !== null ) {
            $owner = $args[ $owner_key ];
            if ( $owner === null || $owner === '' || (int) $owner <= 0 ) {
                $update['owner_user_id'] = null;
            } else {
                $update['owner_user_id'] = (int) $owner;
            }
        }

        if ( empty( $update ) ) {
            return true;
        }

        $update['updated_at'] = current_time( 'mysql' );

        $ok = $wpdb->update( $wpdb->prefix . 'entryvault_submissions', $update, [ 'id' => $id ] );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', 'Could not update submission.' );
        }
        return true;
    }

    public static function move( int $submission_id, int $stage_id, ?int $actor_id = null ): bool {
        global $wpdb;
        $current = self::get( $submission_id );
        if ( ! $current ) {
            return false;
        }
        $stage = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, pipeline_id FROM {$wpdb->prefix}entryvault_stages WHERE id = %d",
            $stage_id
        ), ARRAY_A );
        if ( ! $stage || (int) $stage['pipeline_id'] !== (int) $current['pipeline_id'] ) {
            return false;
        }
        $old_stage_id = (int) $current['stage_id'];
        $wpdb->update(
            $wpdb->prefix . 'entryvault_submissions',
            [ 'stage_id' => $stage_id, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $submission_id ]
        );
        // One row per move. This used to write a 'stage_change' row for the
        // timeline and a 'stage_changed' row for reporting, so every drag
        // stored the same event twice. The single row carries both key
        // spellings so older readers of either shape keep working.
        Timeline::log( $submission_id, 'stage_changed', $actor_id, [
            'from_stage_id' => $old_stage_id,
            'to_stage_id'   => (int) $stage_id,
            'from'          => $old_stage_id,
            'to'            => (int) $stage_id,
        ] );
        // Premium automations subscribe to this action (pro/ only); free core just fires it.
        do_action( 'entryvault_submission_stage_changed', $submission_id, $old_stage_id, (int) $stage_id );
        return true;
    }

    /**
     * Query submissions with optional filters and pagination.
     *
     * Supported args:
     *   - pipeline_id (int)
     *   - stage_id    (int)
     *   - source      (string, source_plugin)
     *   - search      (string, matches title or email LIKE)
     *   - date_from   (Y-m-d)
     *   - date_to     (Y-m-d)
     *   - paged       (int, 1-based)
     *   - per_page    (int, default 20, max 200)
     *   - orderby     (id|title|email|created_at|updated_at|pipeline_id|stage_id|source_plugin)
     *   - order       (ASC|DESC)
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public static function query( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'pipeline_id'        => 0,
            'stage_id'           => 0,
            'source'             => '',
            'search'             => '',
            'date_from'          => '',
            'date_to'            => '',
            'paged'              => 1,
            'per_page'           => 20,
            'orderby'            => 'created_at',
            'order'              => 'DESC',
            'viewable_pipelines' => null,
            // Status filter:
            //   '' or 'active'    => exclude archived (default)
            //   'archived'        => only archived
            //   'open'/'closed'   => exact status match
            //   'any' / 'all'     => no status filter
            'status'             => '',
            'tag_id'             => 0,
            // '' = any owner; '0' = unassigned specifically.
            'owner_id'           => '',
        ];
        $args = array_merge( $defaults, $args );

        $allowed_orderby = [ 'id', 'title', 'email', 'created_at', 'updated_at', 'pipeline_id', 'stage_id', 'source_plugin' ];
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( (string) $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = max( 1, min( 200, (int) $args['per_page'] ) );
        $paged    = max( 1, (int) $args['paged'] );
        $offset   = ( $paged - 1 ) * $per_page;

        $where  = [ '1=1' ];
        $params = [];

        if ( (int) $args['pipeline_id'] > 0 ) {
            $where[]  = 'pipeline_id = %d';
            $params[] = (int) $args['pipeline_id'];
        }
        // Restrict to a viewable subset when an explicit list is supplied.
        // Empty array => deny all (returns no rows). null => no restriction.
        if ( is_array( $args['viewable_pipelines'] ) ) {
            $vp = array_values( array_unique( array_map( 'intval', $args['viewable_pipelines'] ) ) );
            $vp = array_filter( $vp, static fn( $v ) => $v > 0 );
            if ( empty( $vp ) ) {
                $where[] = '0=1';
            } else {
                $placeholders = implode( ',', array_fill( 0, count( $vp ), '%d' ) );
                $where[]      = "pipeline_id IN ({$placeholders})";
                foreach ( $vp as $v ) {
                    $params[] = $v;
                }
            }
        }
        if ( (int) $args['stage_id'] > 0 ) {
            $where[]  = 'stage_id = %d';
            $params[] = (int) $args['stage_id'];
        }
        // Filter by tag via EXISTS rather than a JOIN, so a submission carrying
        // several tags is still returned once.
        if ( (int) $args['tag_id'] > 0 ) {
            $where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}entryvault_submission_tags st
                                  WHERE st.submission_id = {$wpdb->prefix}entryvault_submissions.id
                                  AND st.tag_id = %d )";
            $params[] = (int) $args['tag_id'];
        }
        if ( '' !== (string) $args['owner_id'] ) {
            if ( 0 === (int) $args['owner_id'] ) {
                $where[] = 'owner_user_id IS NULL';
            } else {
                $where[]  = 'owner_user_id = %d';
                $params[] = (int) $args['owner_id'];
            }
        }
        if ( $args['source'] !== '' ) {
            $where[]  = 'source_plugin = %s';
            $params[] = substr( (string) $args['source'], 0, 40 );
        }
        if ( $args['search'] !== '' ) {
            $like     = '%' . self::esc_like( (string) $args['search'] ) . '%';
            $where[]  = '(title LIKE %s OR email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $args['date_from'] !== '' ) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $args['date_from'] . ' 00:00:00';
        }
        if ( $args['date_to'] !== '' ) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $args['date_to'] . ' 23:59:59';
        }
        $status_arg = is_string( $args['status'] ) ? strtolower( trim( $args['status'] ) ) : '';
        if ( $status_arg === 'any' || $status_arg === 'all' ) {
            // no filter
        } elseif ( $status_arg === 'archived' ) {
            $where[]  = 'status = %s';
            $params[] = 'archived';
        } elseif ( $status_arg === 'open' || $status_arg === 'closed' ) {
            $where[]  = 'status = %s';
            $params[] = $status_arg;
        } else {
            $where[]  = "(status IS NULL OR status <> %s)";
            $params[] = 'archived';
        }

        /**
         * Filter extra WHERE fragments for the submissions query. Each entry is
         * [ 'sql' => 'col = %s', 'params' => [ 'value' ] ]; placeholders are bound
         * through $wpdb->prepare() with the rest of the query.
         *
         * @param array $extra Extra fragments, empty by default.
         * @param array $args  Query args.
         */
        $extra = apply_filters( 'entryvault_submissions_query_where', [], $args );
        foreach ( (array) $extra as $fragment ) {
            if ( empty( $fragment['sql'] ) || ! is_string( $fragment['sql'] ) ) {
                continue;
            }
            $where[] = '(' . $fragment['sql'] . ')';
            foreach ( (array) ( $fragment['params'] ?? [] ) as $value ) {
                $params[] = $value;
            }
        }

        $where_sql = implode( ' AND ', $where );
        $table     = $wpdb->prefix . 'entryvault_submissions';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is built from static fragments of %s/%d placeholders, $orderby/$order are whitelisted; %i identifier and all values bound via prepare().
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE {$where_sql}",
            array_merge( [ $table ], $params )
        ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                array_merge( [ $table ], $params, [ $per_page, $offset ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'rows'  => is_array( $rows ) ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * Return all email rows for a submission, oldest first.
     *
     * Output shape:
     *   [ id, direction, from, to, subject, body, created_at, message_id ]
     *
     * `body` resolves to body_text when present, else body_html.
     * `created_at` is mapped from the underlying `sent_at` column.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function emails_for( int $submission_id ): array {
        global $wpdb;
        if ( $submission_id <= 0 ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, direction, from_addr, to_addr, subject, body_text, body_html, sent_at, message_id
               FROM {$wpdb->prefix}entryvault_emails
              WHERE submission_id = %d
           ORDER BY sent_at ASC, id ASC",
            $submission_id
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $row ) {
            $body_text = (string) ( $row['body_text'] ?? '' );
            $body_html = (string) ( $row['body_html'] ?? '' );
            $body      = $body_text !== '' ? $body_text : $body_html;
            $out[] = [
                'id'         => (int) ( $row['id'] ?? 0 ),
                'direction'  => (string) ( $row['direction'] ?? '' ),
                'from'       => (string) ( $row['from_addr'] ?? '' ),
                'to'         => (string) ( $row['to_addr'] ?? '' ),
                'subject'    => (string) ( $row['subject'] ?? '' ),
                'body'       => $body,
                'created_at' => (string) ( $row['sent_at'] ?? '' ),
                'message_id' => (string) ( $row['message_id'] ?? '' ),
            ];
        }
        return $out;
    }

    private static function esc_like( string $text ): string {
        return str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $text );
    }

    /**
     * Delete a submission and cascade to related rows.
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) {
            return false;
        }
        $exists = self::get( $id );
        if ( ! $exists ) {
            return false;
        }
        $wpdb->delete( $wpdb->prefix . 'entryvault_submission_data', [ 'submission_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'entryvault_submission_tags', [ 'submission_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'entryvault_timeline',        [ 'submission_id' => $id ] );
        // Emails sent to or received from this person, and Pro approval records,
        // used to stay behind after the entry was deleted. Approvals only exist
        // with Pro, so skip any table this install does not have.
        foreach ( [ 'entryvault_emails', 'entryvault_approvals' ] as $table ) {
            $name = $wpdb->prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name ) {
                $wpdb->delete( $name, [ 'submission_id' => $id ] );
            }
        }
        $ok = $wpdb->delete( $wpdb->prefix . 'entryvault_submissions', [ 'id' => $id ] );
        return $ok !== false && $ok > 0;
    }

    /**
     * Bulk-move submissions to a stage. Validates stage exists and that
     * every id belongs to the same pipeline as the target stage.
     *
     * @param array<int,int> $ids
     * @return array{moved:int, skipped:int}
     */
    public static function bulk_move( array $ids, int $stage_id, ?int $actor_id = null ): array {
        global $wpdb;
        $stage = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, pipeline_id FROM {$wpdb->prefix}entryvault_stages WHERE id = %d",
            $stage_id
        ), ARRAY_A );
        if ( ! $stage ) {
            return [ 'moved' => 0, 'skipped' => count( $ids ) ];
        }
        $moved   = 0;
        $skipped = 0;
        foreach ( $ids as $sid ) {
            $sid = (int) $sid;
            if ( $sid <= 0 ) {
                $skipped++;
                continue;
            }
            $current = self::get( $sid );
            if ( ! $current ) {
                $skipped++;
                continue;
            }
            // The bulk menu lists every pipeline's stages, but a stage in another
            // pipeline used to be skipped with no reason given ("Moved 0; 1
            // skipped"). Choosing it is an explicit request to move the entry
            // across, so do that.
            if ( (int) $current['pipeline_id'] !== (int) $stage['pipeline_id'] ) {
                if ( self::move_to_pipeline( $sid, (int) $stage['pipeline_id'], $stage_id, $actor_id ) ) {
                    $moved++;
                } else {
                    $skipped++;
                }
                continue;
            }
            if ( self::move( $sid, $stage_id, $actor_id ) ) {
                $moved++;
            } else {
                $skipped++;
            }
        }
        return [ 'moved' => $moved, 'skipped' => $skipped ];
    }

    /**
     * Move a submission into a stage of a different pipeline.
     *
     * Records a stage_changed row carrying both pipelines so the timeline can say
     * where the entry came from, and fires the usual stage-changed action.
     */
    public static function move_to_pipeline( int $submission_id, int $pipeline_id, int $stage_id, ?int $actor_id = null ): bool {
        global $wpdb;
        $current = self::get( $submission_id );
        if ( ! $current || $pipeline_id <= 0 || $stage_id <= 0 ) {
            return false;
        }
        $stage = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, pipeline_id FROM {$wpdb->prefix}entryvault_stages WHERE id = %d",
            $stage_id
        ), ARRAY_A );
        if ( ! $stage || (int) $stage['pipeline_id'] !== $pipeline_id ) {
            return false;
        }
        $old_stage_id    = (int) $current['stage_id'];
        $old_pipeline_id = (int) $current['pipeline_id'];
        $ok = $wpdb->update(
            $wpdb->prefix . 'entryvault_submissions',
            [ 'pipeline_id' => $pipeline_id, 'stage_id' => $stage_id, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $submission_id ]
        );
        if ( false === $ok ) {
            return false;
        }
        Timeline::log( $submission_id, 'stage_changed', $actor_id, [
            'from_stage_id'    => $old_stage_id,
            'to_stage_id'      => $stage_id,
            'from'             => $old_stage_id,
            'to'               => $stage_id,
            'from_pipeline_id' => $old_pipeline_id,
            'to_pipeline_id'   => $pipeline_id,
        ] );
        do_action( 'entryvault_submission_stage_changed', $submission_id, $old_stage_id, $stage_id );
        return true;
    }

    public static function bulk_assign_owner( array $ids, ?int $owner_id, ?int $actor_id = null ): int {
        global $wpdb;
        $owner_id = ( $owner_id === null || (int) $owner_id <= 0 ) ? null : (int) $owner_id;
        $modified = 0;
        $now      = current_time( 'mysql' );
        foreach ( $ids as $sid ) {
            $sid = (int) $sid;
            if ( $sid <= 0 ) {
                continue;
            }
            $current = self::get( $sid );
            if ( ! $current ) {
                continue;
            }
            $previous = isset( $current['owner_user_id'] ) && $current['owner_user_id'] !== null
                ? (int) $current['owner_user_id']
                : null;
            $next = $owner_id;
            if ( $previous === $next ) {
                continue;
            }
            $wpdb->update(
                $wpdb->prefix . 'entryvault_submissions',
                [ 'owner_user_id' => $next, 'updated_at' => $now ],
                [ 'id' => $sid ]
            );
            if ( $next === null ) {
                Timeline::log( $sid, 'owner_unassigned', $actor_id, [
                    'from' => $previous,
                ] );
                do_action( 'entryvault_submission_assigned', $sid, null, $previous );
            } else {
                Timeline::log( $sid, 'owner_assigned', $actor_id, [
                    'from' => $previous,
                    'to'   => $next,
                ] );
                do_action( 'entryvault_submission_assigned', $sid, $next, $previous );
            }
            $modified++;
        }
        return $modified;
    }

    /**
     * Bulk-add a tag to submissions, idempotently.
     *
     * Returns the count of newly created links (existing links are skipped).
     *
     * @param array<int,int> $ids
     */
    public static function bulk_add_tag( array $ids, int $tag_id, ?int $actor_id = null ): int {
        global $wpdb;
        $tag_id = (int) $tag_id;
        if ( $tag_id <= 0 ) {
            return 0;
        }
        $tag_exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}entryvault_tags WHERE id = %d",
            $tag_id
        ) );
        if ( $tag_exists <= 0 ) {
            return 0;
        }
        $added = 0;
        foreach ( $ids as $sid ) {
            $sid = (int) $sid;
            if ( $sid <= 0 ) {
                continue;
            }
            if ( ! self::get( $sid ) ) {
                continue;
            }
            $linked = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}entryvault_submission_tags WHERE submission_id = %d AND tag_id = %d",
                $sid,
                $tag_id
            ) );
            if ( $linked > 0 ) {
                continue;
            }
            $wpdb->insert( $wpdb->prefix . 'entryvault_submission_tags', [
                'submission_id' => $sid,
                'tag_id'        => $tag_id,
            ] );
            Timeline::log( $sid, 'tag_added', $actor_id, [ 'tag_id' => $tag_id ] );
            do_action( 'entryvault_tag_added', $sid, $tag_id );
            $added++;
        }
        return $added;
    }

    /**
     * Set status='archived' on the given submissions.
     *
     * @param array<int,int> $ids
     */
    public static function bulk_archive( array $ids, ?int $actor_id = null ): int {
        return self::bulk_set_status( $ids, 'archived', 'archived', $actor_id );
    }

    /**
     * Restore archived submissions to status='open'.
     *
     * @param array<int,int> $ids
     */
    public static function bulk_unarchive( array $ids, ?int $actor_id = null ): int {
        return self::bulk_set_status( $ids, 'open', 'unarchived', $actor_id );
    }

    /**
     * @param array<int,int> $ids
     */
    private static function bulk_set_status( array $ids, string $status, string $event, ?int $actor_id ): int {
        global $wpdb;
        $modified = 0;
        $now      = current_time( 'mysql' );
        foreach ( $ids as $sid ) {
            $sid = (int) $sid;
            if ( $sid <= 0 ) {
                continue;
            }
            $current = self::get( $sid );
            if ( ! $current ) {
                continue;
            }
            if ( (string) ( $current['status'] ?? '' ) === $status ) {
                continue;
            }
            $wpdb->update(
                $wpdb->prefix . 'entryvault_submissions',
                [ 'status' => $status, 'updated_at' => $now ],
                [ 'id' => $sid ]
            );
            Timeline::log( $sid, $event, $actor_id, [
                'from' => (string) ( $current['status'] ?? '' ),
                'to'   => $status,
            ] );
            $modified++;
        }
        return $modified;
    }
}
