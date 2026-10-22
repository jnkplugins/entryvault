<?php
namespace EntryVault;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for Tag CRUD + submission tag attach/detach.
 *
 * Self-registers on `rest_api_init`. Bootstrap from Plugin::boot():
 *   REST_Tags::register();
 *
 * Response envelope mirrors the other REST controllers:
 *   { success: bool, data: mixed, errors: array }
 */
class REST_Tags {

    private const NS = 'entryvault/v1';

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/tags', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'list_tags' ],
                // Users granted a pipeline need the tag list to work their board.
                'permission_callback' => [ Capabilities::class, 'current_user_has_pipeline_access' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'create_tag' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/tags/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_tag' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
                'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ __CLASS__, 'delete_tag' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
                'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
            ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/tags', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'attach_tag' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)/tags/(?P<tag_id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ __CLASS__, 'detach_tag' ],
            'permission_callback' => [ Capabilities::class, 'request_can_view_submission' ],
            'args'                => [
                'id'     => [ 'type' => 'integer', 'required' => true ],
                'tag_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return Capabilities::current_user_can_manage();
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    public static function list_tags( WP_REST_Request $req ): WP_REST_Response {
        return self::envelope( Tag_Manager::all() );
    }

    public static function create_tag( WP_REST_Request $req ): WP_REST_Response {
        $params = self::params( $req );
        $name   = isset( $params['name'] )  ? sanitize_text_field( (string) $params['name'] )  : '';
        $color  = isset( $params['color'] ) ? sanitize_text_field( (string) $params['color'] ) : null;
        $result = Tag_Manager::create( $name, $color );
        return self::respond( $result );
    }

    public static function update_tag( WP_REST_Request $req ): WP_REST_Response {
        $id     = (int) $req['id'];
        $params = self::params( $req );
        $args   = [];
        foreach ( [ 'name', 'slug', 'color' ] as $k ) {
            if ( array_key_exists( $k, $params ) ) {
                $args[ $k ] = sanitize_text_field( (string) $params[ $k ] );
            }
        }
        $result = Tag_Manager::update( $id, $args );
        return self::respond( $result );
    }

    public static function delete_tag( WP_REST_Request $req ): WP_REST_Response {
        $id     = (int) $req['id'];
        $result = Tag_Manager::delete( $id );
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }
        return self::envelope( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function attach_tag( WP_REST_Request $req ): WP_REST_Response {
        $submission_id = (int) $req['id'];
        $params        = self::params( $req );
        $tag_id        = isset( $params['tag_id'] ) ? (int) $params['tag_id'] : 0;
        $result        = Tag_Manager::attach( $submission_id, $tag_id );
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }
        return self::envelope( [ 'submission_id' => $submission_id, 'tag_id' => $tag_id, 'attached' => true ] );
    }

    public static function detach_tag( WP_REST_Request $req ): WP_REST_Response {
        $submission_id = (int) $req['id'];
        $tag_id        = (int) $req['tag_id'];
        $result        = Tag_Manager::detach( $submission_id, $tag_id );
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }
        return self::envelope( [ 'submission_id' => $submission_id, 'tag_id' => $tag_id, 'detached' => true ] );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function params( WP_REST_Request $req ): array {
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

    private static function respond( $result ): WP_REST_Response {
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }
        return self::envelope( [ 'id' => (int) $result ] );
    }

    private static function error_response( WP_Error $err ): WP_REST_Response {
        $code   = $err->get_error_code();
        $status = self::status_for_code( $code );
        return self::envelope( null, [ [ 'code' => $code, 'message' => $err->get_error_message() ] ], $status );
    }

    private static function status_for_code( string $code ): int {
        switch ( $code ) {
            case 'not_found':
                return 404;
            case 'db_error':
                return 500;
            default:
                return 400;
        }
    }

    private static function envelope( $data, array $errors = [], int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => empty( $errors ),
            'data'    => $data,
            'errors'  => $errors,
        ], $status );
    }
}
