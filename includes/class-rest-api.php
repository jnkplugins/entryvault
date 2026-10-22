<?php
namespace EntryVault;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class REST_API {

    private const NS = 'entryvault/v1';

    public function register_routes(): void {
        register_rest_route( self::NS, '/board/(?P<pipeline_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_board' ],
            'permission_callback' => [ $this, 'can_view_board' ],
            'args'                => [
                'pipeline_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/pipelines', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_pipelines' ],
            'permission_callback' => [ Capabilities::class, 'current_user_has_pipeline_access' ],
        ] );

        register_rest_route( self::NS, '/submissions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_submissions' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_submission' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/notes', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'add_note' ],
                'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
                'args'                => [
                    'id'        => [ 'type' => 'integer', 'required' => true ],
                    'body'      => [ 'type' => 'string',  'required' => true ],
                    'parent_id' => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_notes' ],
                'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/notes/(?P<note_id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_note' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'note_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/move', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'move_submission' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [
                'id'       => [ 'type' => 'integer', 'required' => true ],
                'stage_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'patch_submission' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/email', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'send_submission_email' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/submissions/export.csv', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'export_submissions_csv' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/emails', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_submission_emails' ],
            'permission_callback' => [ $this, 'can_view_submission_emails' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/owners', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_owners' ],
            'permission_callback' => [ Capabilities::class, 'current_user_has_pipeline_access' ],
        ] );

        register_rest_route( self::NS, '/pipelines/(?P<id>\d+)/seen', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'mark_pipeline_seen' ],
            'permission_callback' => [ $this, 'can_view_pipeline_id' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
    }

    /**
     * Permission callback for /board/{pipeline_id}: gate by per-pipeline view cap.
     */
    public function can_view_board( $req ): bool {
        $pipeline_id = 0;
        if ( is_object( $req ) && method_exists( $req, 'get_param' ) ) {
            $pipeline_id = (int) $req->get_param( 'pipeline_id' );
        } elseif ( is_array( $req ) && isset( $req['pipeline_id'] ) ) {
            $pipeline_id = (int) $req['pipeline_id'];
        }
        if ( ! Capabilities::current_user_can_view_pipeline( $pipeline_id ) ) {
            return false;
        }
        return true;
    }

    /**
     * Permission callback for /pipelines/{id}/seen: the same per-pipeline view
     * check as the board, keyed on the `id` route param.
     */
    public function can_view_pipeline_id( $req ): bool {
        if ( Capabilities::current_user_can_manage() ) {
            return true;
        }
        $pipeline_id = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'id' ) : 0;
        return Capabilities::current_user_can_view_pipeline( $pipeline_id );
    }

    public function can_edit(): bool {
        return Capabilities::current_user_can_manage();
    }

    public function can_manage(): bool {
        return Capabilities::current_user_can_manage();
    }

    public function list_pipelines(): WP_REST_Response {
        global $wpdb;
        $pipelines = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i ORDER BY is_default DESC, id ASC",
            $wpdb->prefix . 'entryvault_pipelines'
        ), ARRAY_A );
        // A user granted specific pipelines sees only those. Managers see all.
        if ( ! Capabilities::current_user_can_manage() ) {
            $viewable  = Capabilities::viewable_pipeline_ids_for_current_user();
            $pipelines = array_values( array_filter( (array) $pipelines, static function ( $p ) use ( $viewable ) {
                return in_array( (int) $p['id'], $viewable, true );
            } ) );
        }
        foreach ( $pipelines as &$p ) {
            $p['stages'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}entryvault_stages WHERE pipeline_id = %d ORDER BY position ASC, id ASC",
                $p['id']
            ), ARRAY_A );
        }
        return self::envelope( $pipelines );
    }

    public function get_board( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $pipeline_id = (int) $req['pipeline_id'];

        $pipeline = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}entryvault_pipelines WHERE id = %d",
            $pipeline_id
        ), ARRAY_A );
        if ( ! $pipeline ) {
            return self::envelope( null, [ 'pipeline_not_found' ], 404 );
        }

        $stages = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}entryvault_stages WHERE pipeline_id = %d ORDER BY position ASC, id ASC",
            $pipeline_id
        ), ARRAY_A );

        // Optional owner filter (single id or array via ?owner_id[]=).
        $owner_filter = self::parse_owner_filter( $req );

        foreach ( $stages as &$stage ) {
            $owner_sql    = '';
            $owner_params = [];
            if ( ! empty( $owner_filter['ids'] ) || $owner_filter['unassigned'] ) {
                $clauses = [];
                if ( ! empty( $owner_filter['ids'] ) ) {
                    $ids          = array_map( 'intval', $owner_filter['ids'] );
                    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                    $clauses[]    = "owner_user_id IN ({$placeholders})";
                    $owner_params = $ids;
                }
                if ( $owner_filter['unassigned'] ) {
                    $clauses[] = 'owner_user_id IS NULL';
                }
                $owner_sql = ' AND (' . implode( ' OR ', $clauses ) . ')';
            }

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $owner_sql is a static SQL fragment holding only %d placeholders; %i identifier and all values are bound via prepare().
            $cards = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, title, email, source_plugin, owner_user_id, created_at, updated_at
                   FROM %i
                  WHERE pipeline_id = %d AND stage_id = %d AND status = 'open'{$owner_sql}
               ORDER BY updated_at DESC
                  LIMIT 50",
                array_merge( [ $wpdb->prefix . 'entryvault_submissions', $pipeline_id, (int) $stage['id'] ], $owner_params )
            ), ARRAY_A );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( is_array( $cards ) ) {
                foreach ( $cards as &$card ) {
                    $card['tags']         = Tag_Manager::for_submission( (int) $card['id'] );
                    $card['source_label'] = Sources::label( (string) ( $card['source_plugin'] ?? '' ) );
                }
                unset( $card );
            } else {
                $cards = [];
            }
            $stage['cards'] = $cards;
        }
        unset( $stage );

        $user_id   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $last_seen = '';
        if ( $user_id > 0 && function_exists( 'get_user_meta' ) ) {
            $last_seen = (string) get_user_meta( $user_id, 'entryvault_kanban_seen_' . $pipeline_id, true );
        }

        return self::envelope( [
            'pipeline'  => $pipeline,
            'stages'    => $stages,
            'last_seen' => $last_seen,
        ] );
    }

    public function mark_pipeline_seen( WP_REST_Request $req ): WP_REST_Response {
        $pipeline_id = (int) $req['id'];
        if ( $pipeline_id <= 0 ) {
            return self::envelope( null, [ 'invalid_pipeline' ], 400 );
        }
        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id <= 0 ) {
            return self::envelope( null, [ 'not_logged_in' ], 401 );
        }
        $now = current_time( 'mysql' );
        if ( function_exists( 'update_user_meta' ) ) {
            update_user_meta( $user_id, 'entryvault_kanban_seen_' . $pipeline_id, $now );
        }
        return self::envelope( [ 'pipeline_id' => $pipeline_id, 'last_seen' => $now ] );
    }

    /**
     * Parse owner_id query param into ids[] + unassigned flag.
     * Accepts: ?owner_id=5  | ?owner_id[]=5&owner_id[]=7  | "0" or "unassigned" => unassigned bucket.
     */
    private static function parse_owner_filter( WP_REST_Request $req ): array {
        $raw = $req->get_param( 'owner_id' );
        $ids = [];
        $unassigned = false;
        if ( $raw === null || $raw === '' ) {
            return [ 'ids' => $ids, 'unassigned' => $unassigned ];
        }
        $values = is_array( $raw ) ? $raw : [ $raw ];
        foreach ( $values as $v ) {
            if ( $v === 'unassigned' || (string) $v === '0' ) {
                $unassigned = true;
                continue;
            }
            $iv = (int) $v;
            if ( $iv > 0 ) {
                $ids[] = $iv;
            }
        }
        $ids = array_values( array_unique( $ids ) );
        return [ 'ids' => $ids, 'unassigned' => $unassigned ];
    }

    public function list_submissions( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $pipeline_id = (int) $req->get_param( 'pipeline_id' );
        if ( $pipeline_id > 0 ) {
            if ( ! Capabilities::current_user_can_view_pipeline( $pipeline_id ) ) {
                return self::envelope( null, [ 'forbidden' ], 403 );
            }
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, source_plugin, source_form_id, pipeline_id, stage_id, title, email, status, created_at
                   FROM {$wpdb->prefix}entryvault_submissions
                  WHERE pipeline_id = %d
                  ORDER BY created_at DESC LIMIT 100",
                $pipeline_id
            ), ARRAY_A );
        } else {
            $viewable = Capabilities::viewable_pipeline_ids_for_current_user();
            if ( empty( $viewable ) ) {
                return self::envelope( [] );
            }
            $placeholders = implode( ',', array_fill( 0, count( $viewable ), '%d' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a runtime-built list of %d; %i identifier and all values are bound via prepare().
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, source_plugin, source_form_id, pipeline_id, stage_id, title, email, status, created_at
                   FROM %i
                  WHERE pipeline_id IN ({$placeholders})
                  ORDER BY created_at DESC LIMIT 100",
                array_merge( [ $wpdb->prefix . 'entryvault_submissions' ], $viewable )
            ), ARRAY_A );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        }
        return self::envelope( $rows );
    }

    public function get_submission( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req['id'];

        $row = Submission_Store::get( $id );
        if ( ! $row ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }

        $fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_key, field_label, field_type, value, is_stale
               FROM {$wpdb->prefix}entryvault_submission_data
              WHERE submission_id = %d ORDER BY id ASC",
            $id
        ), ARRAY_A );
        if ( is_array( $fields ) ) {
            foreach ( $fields as &$f_row ) {
                $f_row['is_stale'] = (int) ( $f_row['is_stale'] ?? 0 );
                // Stored JSON (checkbox lists, file objects with a server path)
                // reads as text, and a path is never sent to the browser.
                $f_row['value'] = Field_Values::display( $f_row['value'] ?? '', (string) ( $f_row['field_type'] ?? '' ) );
            }
            unset( $f_row );
        }

        // A stage move is now one 'stage_changed' row. Installs from before that
        // change hold a 'stage_change' twin for every move; hide those so old
        // moves are not listed twice.
        $timeline = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, data, meta, user_id, created_at
               FROM {$wpdb->prefix}entryvault_timeline
              WHERE submission_id = %d AND type != 'stage_change'
              ORDER BY created_at DESC, id DESC LIMIT 100",
            $id
        ), ARRAY_A );
        foreach ( $timeline as &$t ) {
            $t['data'] = $t['data'] ? json_decode( $t['data'], true ) : null;
            $meta      = ! empty( $t['meta'] ) ? json_decode( (string) $t['meta'], true ) : null;
            unset( $t['meta'] );
            // A deleted note showed "[deleted]" under Notes while its full text
            // stayed readable here on the Timeline tab.
            if ( 'note' === $t['type'] && is_array( $meta ) && ! empty( $meta['deleted_at'] ) ) {
                $t['data']    = [ 'deleted' => true ];
                $t['deleted'] = true;
            }
            // Every row carries a readable sentence so the panel never has to
            // print a raw payload (stage ids, approval/edit JSON, …).
            $t['summary'] = Timeline::summary( $t );
        }
        unset( $t );

        $stage = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, color, position FROM {$wpdb->prefix}entryvault_stages WHERE id = %d",
            (int) $row['stage_id']
        ), ARRAY_A );

        $tags = Tag_Manager::for_submission( $id );

        $row['source_label']      = Sources::label( (string) ( $row['source_plugin'] ?? '' ) );
        $row['source_form_title'] = Sources::form_title( (string) ( $row['source_plugin'] ?? '' ), (int) ( $row['source_form_id'] ?? 0 ) );

        $detail = [
            'submission' => $row,
            'stage'      => $stage,
            'fields'     => $fields,
            'timeline'   => $timeline,
            'tags'       => $tags,
        ];

        /**
         * Filter the entry detail payload served to the detail panel.
         *
         * @param array $detail Payload keys: submission, stage, fields, timeline, tags.
         * @param int   $id     Submission ID.
         */
        $detail = apply_filters( 'entryvault_submission_detail', $detail, $id );

        return self::envelope( $detail );
    }

    public function add_note( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id     = (int) $req['id'];
        $params = self::read_params( $req );
        $body   = isset( $params['body'] ) ? trim( (string) $params['body'] ) : '';
        if ( $body === '' ) {
            return self::envelope( null, [ 'empty_note' ], 400 );
        }
        if ( ! Submission_Store::get( $id ) ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }

        $parent_id = isset( $params['parent_id'] ) ? (int) $params['parent_id'] : 0;
        if ( $parent_id > 0 ) {
            $parent = Timeline::get( $parent_id );
            if ( ! $parent
                || (int) ( $parent['submission_id'] ?? 0 ) !== $id
                || (string) ( $parent['type'] ?? '' ) !== 'note'
            ) {
                return self::envelope( null, [ 'invalid_parent' ], 400 );
            }
        } else {
            $parent_id = 0;
        }

        $user_id  = (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
        $entry_id = Timeline::log(
            $id,
            'note',
            $user_id ?: null,
            [ 'body' => $body ],
            $parent_id ?: null,
            [ 'user_id' => $user_id ]
        );

        $row = Timeline::get( $entry_id );
        return self::envelope( [
            'id'            => $entry_id,
            'submission_id' => $id,
            'parent_id'     => $parent_id ?: null,
            'user_id'       => $user_id ?: null,
            'data'          => [ 'body' => $body ],
            'meta'          => [ 'user_id' => $user_id ],
            'created_at'    => $row['created_at'] ?? null,
        ] );
    }

    /**
     * GET /submissions/{id}/notes — list note timeline rows ordered ASC.
     */
    public function list_notes( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req['id'];
        if ( ! Submission_Store::get( $id ) ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, submission_id, parent_id, user_id, data, meta, created_at
               FROM {$wpdb->prefix}entryvault_timeline
              WHERE submission_id = %d AND type = 'note'
           ORDER BY created_at ASC, id ASC",
            $id
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }
        $out = [];
        foreach ( $rows as $row ) {
            $data = isset( $row['data'] ) && $row['data'] ? json_decode( (string) $row['data'], true ) : null;
            $meta = isset( $row['meta'] ) && $row['meta'] ? json_decode( (string) $row['meta'], true ) : null;
            // Deleting a note only stamps deleted_at, and the API kept serving the
            // full text. Keep the row (replies hang off it) but not its body.
            if ( is_array( $meta ) && ! empty( $meta['deleted_at'] ) ) {
                $data = [ 'body' => '', 'deleted' => true ];
            }
            $out[] = [
                'id'            => (int) ( $row['id'] ?? 0 ),
                'submission_id' => (int) ( $row['submission_id'] ?? 0 ),
                'parent_id'     => isset( $row['parent_id'] ) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'user_id'       => isset( $row['user_id'] ) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'data'          => $data,
                'meta'          => $meta,
                'created_at'    => (string) ( $row['created_at'] ?? '' ),
            ];
        }
        return self::envelope( $out );
    }

    /**
     * DELETE /submissions/{id}/notes/{note_id} — soft-delete a note by stamping
     * meta.deleted_at. Author or admin only.
     */
    public function delete_note( WP_REST_Request $req ): WP_REST_Response {
        $id      = (int) $req['id'];
        $note_id = (int) $req['note_id'];
        if ( ! Submission_Store::get( $id ) ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }
        $row = Timeline::get( $note_id );
        if ( ! $row
            || (int) ( $row['submission_id'] ?? 0 ) !== $id
            || (string) ( $row['type'] ?? '' ) !== 'note'
        ) {
            return self::envelope( null, [ 'note_not_found' ], 404 );
        }

        $meta = [];
        if ( isset( $row['meta'] ) && $row['meta'] ) {
            $decoded = json_decode( (string) $row['meta'], true );
            if ( is_array( $decoded ) ) {
                $meta = $decoded;
            }
        }
        $author_id  = (int) ( $meta['user_id'] ?? ( $row['user_id'] ?? 0 ) );
        $current_id = (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
        $is_admin   = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;

        if ( ! $is_admin && ( $current_id <= 0 || $current_id !== $author_id ) ) {
            return self::envelope( null, [ 'forbidden' ], 403 );
        }

        $meta['deleted_at']    = current_time( 'mysql' );
        $meta['deleted_by']    = $current_id ?: null;
        Timeline::update_meta( $note_id, $meta );

        return self::envelope( [
            'id'         => $note_id,
            'deleted_at' => $meta['deleted_at'],
        ] );
    }

    public function move_submission( WP_REST_Request $req ): WP_REST_Response {
        $id       = (int) $req['id'];
        $stage_id = (int) $req['stage_id'];
        $ok       = Submission_Store::move( $id, $stage_id, get_current_user_id() );
        if ( ! $ok ) {
            return self::envelope( null, [ 'move_failed' ], 400 );
        }
        return self::envelope( [ 'id' => $id, 'stage_id' => $stage_id ] );
    }

    public function patch_submission( WP_REST_Request $req ): WP_REST_Response {
        $id     = (int) $req['id'];
        $params = self::read_params( $req );

        $existing = Submission_Store::get( $id );
        if ( ! $existing ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }

        // Stage change is routed through Submission_Store::move().
        if ( array_key_exists( 'stage_id', $params ) ) {
            $stage_id = (int) $params['stage_id'];
            if ( $stage_id > 0 && $stage_id !== (int) $existing['stage_id'] ) {
                $ok = Submission_Store::move( $id, $stage_id, get_current_user_id() );
                if ( ! $ok ) {
                    return self::envelope( null, [ 'move_failed' ], 400 );
                }
            }
        }

        // Whitelisted column updates go through update().
        $update_args = [];
        if ( array_key_exists( 'title', $params ) ) {
            $update_args['title'] = sanitize_text_field( (string) $params['title'] );
        }
        if ( array_key_exists( 'owner_id', $params ) ) {
            $update_args['owner_id'] = $params['owner_id'];
        }
        if ( ! empty( $update_args ) ) {
            $result = Submission_Store::update( $id, $update_args );
            if ( is_wp_error( $result ) ) {
                return self::envelope( null, [ [
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ] ], 400 );
            }
        }

        // Owner changes from the detail panel saved the owner but left no trace on
        // the timeline, while the same change made in bulk did. Record it here the
        // same way bulk assignment does.
        if ( array_key_exists( 'owner_id', $update_args ) ) {
            $previous = isset( $existing['owner_user_id'] ) && null !== $existing['owner_user_id'] && (int) $existing['owner_user_id'] > 0
                ? (int) $existing['owner_user_id']
                : null;
            $next = ( null === $update_args['owner_id'] || '' === $update_args['owner_id'] || (int) $update_args['owner_id'] <= 0 )
                ? null
                : (int) $update_args['owner_id'];
            if ( $previous !== $next ) {
                $actor = (int) get_current_user_id();
                if ( null === $next ) {
                    Timeline::log( $id, 'owner_unassigned', $actor ?: null, [ 'from' => $previous ] );
                } else {
                    Timeline::log( $id, 'owner_assigned', $actor ?: null, [ 'from' => $previous, 'to' => $next ] );
                }
                do_action( 'entryvault_submission_assigned', $id, $next, $previous );
            }
        }

        $row = Submission_Store::get( $id );
        return self::envelope( [ 'submission' => $row ] );
    }

    public function send_submission_email( WP_REST_Request $req ): WP_REST_Response {
        $id     = (int) $req['id'];
        $params = self::read_params( $req );

        if ( ! Submission_Store::get( $id ) ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }

        $to      = isset( $params['to'] ) ? sanitize_email( (string) $params['to'] ) : '';
        $subject = isset( $params['subject'] ) ? sanitize_text_field( (string) $params['subject'] ) : '';
        $body    = isset( $params['body'] ) ? (string) $params['body'] : '';
        $html    = ! empty( $params['html'] );

        if ( ! $to || ! is_email( $to ) ) {
            return self::envelope( null, [ 'invalid_to' ], 400 );
        }
        if ( $subject === '' ) {
            return self::envelope( null, [ 'invalid_subject' ], 400 );
        }
        if ( trim( $body ) === '' ) {
            return self::envelope( null, [ 'invalid_body' ], 400 );
        }

        $sent = Email_Sender::send( $id, $to, $subject, $body, [ 'html' => $html ] );
        if ( ! $sent ) {
            return self::envelope( [ 'sent' => false ], [ 'send_failed' ], 500 );
        }
        return self::envelope( [ 'sent' => true ] );
    }

    /**
     * Permission callback for /submissions/{id}/emails: gate by per-pipeline view cap.
     */
    public function can_view_submission_emails( $req ): bool {
        $id = 0;
        if ( is_object( $req ) && method_exists( $req, 'get_param' ) ) {
            $id = (int) $req->get_param( 'id' );
        } elseif ( is_array( $req ) && isset( $req['id'] ) ) {
            $id = (int) $req['id'];
        }
        if ( $id <= 0 ) {
            return false;
        }
        $sub = Submission_Store::get( $id );
        if ( ! $sub ) {
            return false;
        }
        return Capabilities::current_user_can_view_pipeline( (int) ( $sub['pipeline_id'] ?? 0 ) );
    }

    /**
     * GET /submissions/{id}/emails — return the email thread for a submission.
     */
    public function list_submission_emails( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req['id'];
        if ( ! Submission_Store::get( $id ) ) {
            return self::envelope( null, [ 'not_found' ], 404 );
        }
        $emails = Submission_Store::emails_for( $id );
        return self::envelope( $emails );
    }

    /**
     * GET /submissions/export.csv — stream a CSV of filtered submissions.
     *
     * Reuses Submission_Store::query() filters; bounded at 5000 rows.
     */
    public function export_submissions_csv( WP_REST_Request $req ): WP_REST_Response {
        $params = $req->get_params();
        $args   = [
            'pipeline_id' => isset( $params['pipeline_id'] ) ? (int) $params['pipeline_id'] : 0,
            'stage_id'    => isset( $params['stage_id'] ) ? (int) $params['stage_id'] : 0,
            // sanitize_text_field, NOT sanitize_key — same bug as the admin screen.
            // sanitize_key lowercases and strips spaces, so "Gravity Forms" became
            // "gravityforms" and matched no rows: the export silently returned an
            // empty CSV, which reads as "no submissions" rather than "broken filter".
            'source'      => isset( $params['source'] ) ? sanitize_text_field( (string) $params['source'] ) : '',
            'search'      => isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '',
            'date_from'   => isset( $params['date_from'] ) ? self::sanitize_date( (string) $params['date_from'] ) : '',
            'date_to'     => isset( $params['date_to'] ) ? self::sanitize_date( (string) $params['date_to'] ) : '',
            'paged'       => 1,
            'per_page'    => 5000,
            'orderby'     => 'created_at',
            'order'       => 'DESC',
        ];

        $result = Submission_Store::query( $args );
        $rows   = $result['rows'];

        global $wpdb;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV to php://output; WP_Filesystem has no streaming API.
        $fh  = fopen( 'php://temp', 'w+' );
        $cols = [
            'id', 'title', 'email', 'source_plugin', 'pipeline_id',
            'stage_id', 'owner_user_id', 'created_at', 'updated_at', 'fields_json',
        ];
        // Explicit $escape ('' = no escape char) — implicit form is deprecated on
        // PHP 8.4. Header cells are static/safe; no injection guard needed there.
        fputcsv( $fh, $cols, ',', '"', '' );

        foreach ( $rows as $row ) {
            $sid    = (int) ( $row['id'] ?? 0 );
            $fields = [];
            if ( $sid > 0 ) {
                $field_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT field_key, value FROM {$wpdb->prefix}entryvault_submission_data WHERE submission_id = %d",
                    $sid
                ), ARRAY_A );
                if ( is_array( $field_rows ) ) {
                    foreach ( $field_rows as $fr ) {
                        $fields[ (string) ( $fr['field_key'] ?? '' ) ] = (string) ( $fr['value'] ?? '' );
                    }
                }
            }
            fputcsv( $fh, array_map( [ Submissions_Admin::class, 'csv_safe' ], [
                (int) ( $row['id'] ?? 0 ),
                (string) ( $row['title'] ?? '' ),
                (string) ( $row['email'] ?? '' ),
                (string) ( $row['source_plugin'] ?? '' ),
                (int) ( $row['pipeline_id'] ?? 0 ),
                (int) ( $row['stage_id'] ?? 0 ),
                $row['owner_user_id'] === null ? '' : (int) $row['owner_user_id'],
                (string) ( $row['created_at'] ?? '' ),
                (string) ( $row['updated_at'] ?? '' ),
                wp_json_encode( $fields ),
            ] ), ',', '"', '' );
        }

        rewind( $fh );
        $body = stream_get_contents( $fh );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV to php://output; WP_Filesystem has no streaming API.
        fclose( $fh );

        $filename = 'entryvault-submissions-' . gmdate( 'Ymd-His' ) . '.csv';
        $response = new WP_REST_Response( $body, 200 );
        if ( method_exists( $response, 'header' ) ) {
            $response->header( 'Content-Type', 'application/octet-stream' );
            $response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
        }
        return $response;
    }

    private static function sanitize_date( string $date ): string {
        $date = trim( $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }

    public function list_owners(): WP_REST_Response {
        $users = Plugin::owner_users();

        $owners = [];
        foreach ( (array) $users as $u ) {
            $owners[] = [
                'id'           => (int) $u->ID,
                'display_name' => (string) $u->display_name,
                'user_email'   => (string) ( $u->user_email ?? '' ),
            ];
        }
        return self::envelope( $owners );
    }

    private static function read_params( WP_REST_Request $req ): array {
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

    private static function envelope( $data, array $errors = [], int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => empty( $errors ),
            'data'    => $data,
            'errors'  => $errors,
        ], $status );
    }
}
