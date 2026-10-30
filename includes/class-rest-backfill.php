<?php
namespace EntryVault;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints for the F-03 historical backfill controller.
 *
 *   POST /entryvault/v1/backfill/start  { form_id, source_plugin }
 *   GET  /entryvault/v1/backfill/state  ?form_id=&source_plugin=
 *   POST /entryvault/v1/backfill/cancel { form_id, source_plugin }
 *
 * All routes require `manage_options`.
 */
class REST_Backfill {

    private const NS = 'entryvault/v1';

    public function register_routes(): void {
        register_rest_route( self::NS, '/backfill/start', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'start' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/backfill/state', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'state' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        // The board's day-one box and the Past entries screen.
        register_rest_route( self::NS, '/past-entries', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => static fn() => self::envelope( Past_Entries::found( true ) ),
            'permission_callback' => [ $this, 'can_manage' ],
        ] );
        register_rest_route( self::NS, '/past-entries/dismiss', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => static function () {
                Past_Entries::dismiss();
                return self::envelope( [ 'dismissed' => true ] );
            },
            'permission_callback' => [ $this, 'can_manage' ],
        ] );
        register_rest_route( self::NS, '/past-entries/report', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'report' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/backfill/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'cancel' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );
    }

    public function can_manage(): bool {
        return current_user_can( 'manage_options' );
    }

    public function start( WP_REST_Request $req ): WP_REST_Response {
        $params        = self::read_params( $req );
        $form_id       = (int) ( $params['form_id'] ?? 0 );
        $source_plugin = (string) ( $params['source_plugin'] ?? '' );
        if ( $form_id <= 0 || $source_plugin === '' ) {
            return self::envelope( null, [ 'invalid_args' ], 400 );
        }
        $state = Backfill::start( $form_id, $source_plugin );
        return self::envelope( $state );
    }

    public function state( WP_REST_Request $req ): WP_REST_Response {
        $form_id       = (int) $req->get_param( 'form_id' );
        $source_plugin = (string) $req->get_param( 'source_plugin' );
        if ( $form_id <= 0 || $source_plugin === '' ) {
            return self::envelope( null, [ 'invalid_args' ], 400 );
        }
        // The page's poll keeps a run going where WP-Cron does not.
        Backfill::run_if_stalled( $form_id, $source_plugin );
        return self::envelope( Backfill::state( $form_id, $source_plugin ) );
    }

    public function cancel( WP_REST_Request $req ): WP_REST_Response {
        $params        = self::read_params( $req );
        $form_id       = (int) ( $params['form_id'] ?? 0 );
        $source_plugin = (string) ( $params['source_plugin'] ?? '' );
        if ( $form_id <= 0 || $source_plugin === '' ) {
            return self::envelope( null, [ 'invalid_args' ], 400 );
        }
        Backfill::cancel( $form_id, $source_plugin );
        return self::envelope( Backfill::state( $form_id, $source_plugin ) );
    }

    /** Send a "Report a problem" for one direct-read source (owner's click only). */
    public function report( WP_REST_Request $req ): WP_REST_Response {
        $params = self::read_params( $req );
        $source = sanitize_key( (string) ( $params['source_plugin'] ?? '' ) );
        $result = Past_Entries::send_report( $source );
        if ( 'unknown' === $result ) {
            return self::envelope( null, [ 'unknown_source' ], 400 );
        }
        return self::envelope( [ 'result' => $result ] );
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
