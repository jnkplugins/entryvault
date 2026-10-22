<?php
namespace EntryVault;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the reporting dashboard.
 *
 * Self-registers on `rest_api_init`. Bootstrapping is a one-liner from
 * Plugin::boot(): `REST_Reports::register();`
 *
 * Response envelope mirrors the other REST controllers:
 *   { success: bool, data: mixed, errors: array }
 */
class REST_Reports {

    private const NS = 'entryvault/v1';

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/reports/counts-by-stage', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'counts_by_stage' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
        register_rest_route( self::NS, '/reports/counts-by-source', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'counts_by_source' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
        register_rest_route( self::NS, '/reports/daily-volume', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'daily_volume' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
        register_rest_route( self::NS, '/reports/funnel', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'funnel' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
        register_rest_route( self::NS, '/reports/time-in-stage', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'time_in_stage' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
        register_rest_route( self::NS, '/reports/per-owner', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'per_owner' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
    }

    public static function can_view(): bool {
        return Capabilities::current_user_can_manage();
    }

    public static function counts_by_stage( WP_REST_Request $req ): WP_REST_Response {
        $pipeline_id = (int) $req->get_param( 'pipeline_id' );
        $date_from   = self::clean_date( $req->get_param( 'date_from' ) );
        $date_to     = self::clean_date( $req->get_param( 'date_to' ) );
        return self::envelope( Reporting::counts_by_stage( $pipeline_id, $date_from, $date_to ) );
    }

    public static function counts_by_source( WP_REST_Request $req ): WP_REST_Response {
        $date_from = self::clean_date( $req->get_param( 'date_from' ) );
        $date_to   = self::clean_date( $req->get_param( 'date_to' ) );
        $pipeline  = $req->get_param( 'pipeline_id' );
        $pipeline  = $pipeline !== null && $pipeline !== '' ? (int) $pipeline : null;
        return self::envelope( Reporting::counts_by_source( $date_from, $date_to, $pipeline ) );
    }

    public static function daily_volume( WP_REST_Request $req ): WP_REST_Response {
        $pipeline_id = $req->get_param( 'pipeline_id' );
        $pipeline_id = $pipeline_id !== null && $pipeline_id !== '' ? (int) $pipeline_id : null;
        $days        = $req->get_param( 'days' );
        $days        = $days !== null && $days !== '' ? (int) $days : 30;
        return self::envelope( Reporting::daily_volume( $pipeline_id, $days ) );
    }

    public static function funnel( WP_REST_Request $req ): WP_REST_Response {
        $pipeline_id = (int) $req->get_param( 'pipeline_id' );
        return self::envelope( Reporting::funnel( $pipeline_id ) );
    }

    public static function time_in_stage( WP_REST_Request $req ): WP_REST_Response {
        $pipeline_id = (int) $req->get_param( 'pipeline_id' );
        return self::envelope( Reporting::time_in_stage( $pipeline_id ) );
    }

    public static function per_owner( WP_REST_Request $req ): WP_REST_Response {
        $pipeline_id = $req->get_param( 'pipeline_id' );
        $pipeline_id = $pipeline_id !== null && $pipeline_id !== '' ? (int) $pipeline_id : null;
        $date_from   = self::clean_date( $req->get_param( 'date_from' ) );
        $date_to     = self::clean_date( $req->get_param( 'date_to' ) );
        return self::envelope( Reporting::per_owner( $pipeline_id, $date_from, $date_to ) );
    }

    /* ------------------------------------------------------------------ */

    private static function clean_date( $val ): ?string {
        if ( ! is_string( $val ) || $val === '' ) {
            return null;
        }
        $val = trim( $val );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $val ) ) {
            return $val;
        }
        return null;
    }

    private static function envelope( $data, array $errors = [], int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => empty( $errors ),
            'data'    => $data,
            'errors'  => $errors,
        ], $status );
    }
}
