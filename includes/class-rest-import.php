<?php
namespace EntryVault;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints powering the CSV import admin tool.
 *
 *   POST /entryvault/v1/import/preview { csv }
 *   POST /entryvault/v1/import/run     { csv, mapping, dry_run? }
 */
class REST_Import {

    private const NS = 'entryvault/v1';

    public function register_routes(): void {
        register_rest_route( self::NS, '/import/preview', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'preview' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/import/run', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'run' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );
    }

    public function can_manage(): bool {
        return current_user_can( 'manage_options' );
    }

    private const MAX_CSV_BYTES = 5 * 1024 * 1024; // 5 MB

    public function preview( WP_REST_Request $req ): WP_REST_Response {
        $params = self::read_params( $req );
        $csv    = (string) ( $params['csv'] ?? '' );
        if ( $csv === '' ) {
            return self::envelope( null, [ 'empty_csv' ], 400 );
        }
        if ( strlen( $csv ) > self::MAX_CSV_BYTES ) {
            return self::envelope( null, [ 'csv_too_large' ], 400 );
        }
        $parsed  = CSV_Importer::parse( $csv );
        $preview = array_slice( $parsed['rows'], 0, 10 );
        return self::envelope( [
            'headers'    => $parsed['headers'],
            'rows'       => $preview,
            'total_rows' => count( $parsed['rows'] ),
        ] );
    }

    public function run( WP_REST_Request $req ): WP_REST_Response {
        $params  = self::read_params( $req );
        $csv     = (string) ( $params['csv'] ?? '' );
        $mapping = isset( $params['mapping'] ) && is_array( $params['mapping'] ) ? $params['mapping'] : [];
        $dry_run = ! empty( $params['dry_run'] );
        // Off unless the import screen explicitly asks for it (see entryvault_import_options).
        $run_rules = ! empty( $params['run_rules'] );

        if ( $csv === '' ) {
            return self::envelope( null, [ 'empty_csv' ], 400 );
        }
        if ( strlen( $csv ) > self::MAX_CSV_BYTES ) {
            return self::envelope( null, [ 'csv_too_large' ], 400 );
        }
        if ( empty( $mapping['email_col'] ) ) {
            return self::envelope( null, [ 'missing_email_mapping' ], 400 );
        }

        $parsed  = CSV_Importer::parse( $csv );
        $summary = CSV_Importer::import( $parsed['rows'], $mapping, [ 'dry_run' => $dry_run, 'run_rules' => $run_rules ] );

        return self::envelope( array_merge( [ 'dry_run' => $dry_run ], $summary ) );
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
