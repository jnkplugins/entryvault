<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Public REST endpoints (token- or capability-gated).
 *
 * Free build: GET /entryvault/v1/public/table/{slug} (the [entryvault_table]
 * shortcode / submissions-table block backend). The public Kanban board feed
 * (/public/board/{slug}) is premium and registers from the pro/ module, reusing
 * this controller's shared permission_check().
 *
 * Spec §7.6: GET /entryvault/v1/public/table/{slug}
 */
class REST_Public {

	const NAMESPACE = 'entryvault/v1';

	/**
	 * Wire the rest_api_init hook. Called once from Plugin::boot().
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/table/(?P<slug>[a-z0-9_-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_table' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
				'args'                => [
					'slug'  => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'token' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'page'  => [
						'type'    => 'integer',
						'default' => 1,
					],
					'q'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'sort'  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'stage' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'columns' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Gate the public table endpoint. Access requires EITHER per-pipeline view
	 * capability (managers / users granted that pipeline) OR a valid per-pipeline
	 * access token. The generic `edit_posts` capability is deliberately NOT
	 * accepted: it is unrelated to CRM/submission data and would over-expose it.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function permission_check( $request ) {
		// Managers may view any pipeline (handle_table 404s if it doesn't exist).
		if ( Capabilities::current_user_can_manage() ) {
			return true;
		}
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		if ( $slug === '' ) {
			return self::forbid();
		}
		$pipeline = Frontend::get_pipeline_by_slug( $slug );
		if ( ! $pipeline ) {
			return self::forbid();
		}
		// A user explicitly granted access to this pipeline, or a valid token.
		if ( Capabilities::current_user_can_view_pipeline( (int) $pipeline['id'] ) ) {
			return true;
		}
		$token = (string) $request->get_param( 'token' );
		if ( $token !== '' && Frontend::token_matches( $slug, $token ) ) {
			return true;
		}
		return self::forbid();
	}

	private static function forbid(): \WP_Error {
		return new \WP_Error(
			'entryvault_forbidden',
			__( 'Access required.', 'entryvault' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_table( $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );

		$pipeline = Frontend::get_pipeline_by_slug( $slug );
		if ( ! $pipeline ) {
			return new \WP_Error(
				'entryvault_pipeline_not_found',
				__( 'Pipeline not found.', 'entryvault' ),
				[ 'status' => 404 ]
			);
		}

		// Token/anonymous callers (no per-pipeline view capability) get the
		// email column stripped; managers and pipeline-granted users see it.
		$token_only = ! Capabilities::current_user_can_view_pipeline( (int) $pipeline['id'] );

		$columns_param = $request->get_param( 'columns' );
		if ( is_string( $columns_param ) && trim( $columns_param ) !== '' ) {
			$requested = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $columns_param ) ) ), 'strlen' );
			foreach ( $requested as $c ) {
				if ( ! Frontend::is_allowed_column( $c ) ) {
					return new \WP_Error(
						'entryvault_invalid_column',
						/* translators: %s: column name. */
						sprintf( __( 'Unknown column: %s', 'entryvault' ), $c ),
						[ 'status' => 400 ]
					);
				}
				if ( $token_only && $c === 'email' ) {
					return new \WP_Error(
						'entryvault_forbidden_column',
						__( 'Column not available without authentication.', 'entryvault' ),
						[ 'status' => 403 ]
					);
				}
			}
			$columns = Frontend::resolve_columns( $requested );
		} else {
			$columns = Frontend::resolve_columns();
		}

		// Strip email from token-only responses regardless of defaults.
		if ( $token_only ) {
			$columns = array_values( array_filter( $columns, fn( $c ) => $c !== 'email' ) );
		}

		$page  = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$q     = (string) $request->get_param( 'q' );
		$sort  = (string) $request->get_param( 'sort' );
		$stage = (string) $request->get_param( 'stage' );

		if ( ! in_array( $sort, $columns, true ) ) {
			$sort = 'submitted_at';
		}

		$with_tags = in_array( 'tags', $columns, true );
		$result    = Frontend::query_submissions( (int) $pipeline['id'], $stage, $q, $sort, $page, $with_tags, ! $token_only );

		$data = [];
		foreach ( $result['rows'] as $row ) {
			$cells = [];
			foreach ( $columns as $col ) {
				$cells[ $col ] = Frontend::format_cell( $col, $row, ! $token_only );
			}
			$data[] = [
				'id'    => (int) $row['id'],
				'cells' => $cells,
			];
		}

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $data,
				'errors'  => [],
				'meta'    => [
					'page'     => $page,
					'per_page' => Frontend::PER_PAGE,
					'total'    => (int) $result['total'],
					'columns'  => $columns,
				],
			]
		);
	}
}
