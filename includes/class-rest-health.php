<?php
namespace EntryVault;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the EntryVault Health page.
 *
 * Self-registers on `rest_api_init`. Bootstrap from Plugin::boot():
 *   REST_Health::register();
 *
 * Routes:
 *   GET /entryvault/v1/health — read-only health check report (DB / composer /
 *   versions, plus the IMAP cron card when premium).
 *
 * The IMAP operator actions (run-imap, reschedule-cron) are premium and register
 * from the pro/ module — the free build exposes no way to trigger or reschedule
 * the IMAP poll.
 *
 * All routes require manage_options.
 */
class REST_Health {

    private const NS = 'entryvault/v1';

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_health' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'manage_options' );
    }

    public static function get_health( WP_REST_Request $req ): WP_REST_Response {
        return self::envelope( Health::check() );
    }

    public static function envelope( $data, array $errors = [], int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => empty( $errors ),
            'data'    => $data,
            'errors'  => $errors,
        ], $status );
    }
}
