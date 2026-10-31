<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * Public-facing submission table renderer.
 *
 * Powers both the [entryvault_table] shortcode and the
 * `entryvault/submissions-table` Gutenberg block.
 */
class Frontend {

	/**
	 * Columns the public table shows unless told otherwise.
	 *
	 * Email is deliberately NOT here. This table goes on a public page, so the
	 * documented usage published every lead's address to anyone who opened it —
	 * and the setting that would have changed that had no UI. Ask for it
	 * explicitly with columns="…,email" if that is genuinely what you want.
	 */
	const DEFAULT_COLUMNS = [ 'submitted_at', 'name', 'stage' ];
	const PER_PAGE        = 25;

	/**
	 * Whitelist of column keys recognized by the public table.
	 *
	 * Any user-supplied column not in this list is silently dropped (REST
	 * also rejects unknown columns with a 400). Custom-field columns can
	 * be added via the `entryvault_frontend_columns_whitelist` filter.
	 *
	 * @var string[]
	 */
	const ALLOWED_COLUMNS = [
		'submitted_at',
		'title',
		'name',
		'email',
		'stage',
		'owner',
		'tags',
		'created',
	];

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_shortcode( 'entryvault_table', [ __CLASS__, 'shortcode' ] );
	}

	/**
	 * Shortcode entry point.
	 *
	 * @param array|string $atts
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'pipeline'     => '',
				'stage'        => '',
				'access_token' => '',
				'columns'      => '',
			],
			is_array( $atts ) ? $atts : [],
			'entryvault_table'
		);
		return self::render_table( $atts );
	}

	/**
	 * Render the public submissions table.
	 *
	 * Shared entry point for shortcode and block.
	 */
	public static function render_table( array $attrs ): string {
		$pipeline_slug = sanitize_key( (string) ( $attrs['pipeline'] ?? '' ) );
		// The stage is matched by name, so keep spaces and non-Latin letters
		// (sanitize_key emptied "مكسوب" and "In progress"); it is bound below.
		$stage_slug    = sanitize_text_field( (string) ( $attrs['stage'] ?? '' ) );
		$token         = sanitize_text_field( (string) ( $attrs['access_token'] ?? '' ) );

		if ( ! $pipeline_slug ) {
			return '<p class="evt-error">' . esc_html__( 'No pipeline specified.', 'entryvault' ) . '</p>';
		}

		$pipeline = self::get_pipeline_by_slug( $pipeline_slug );
		if ( ! $pipeline ) {
			return '<p class="evt-error">' . esc_html__( 'Pipeline not found.', 'entryvault' ) . '</p>';
		}

		// Access requires per-pipeline view capability (managers / granted users)
		// or a valid per-pipeline access token — never the generic edit_posts cap,
		// which is unrelated to this CRM data.
		$can_view = Capabilities::current_user_can_view_pipeline( (int) $pipeline['id'] )
			|| ( $token !== '' && self::token_matches( $pipeline_slug, $token ) );

		if ( ! $can_view ) {
			$out = '<p class="evt-access-denied">' . esc_html__( 'Access required.', 'entryvault' ) . '</p>';
			// Visitors see only that line. Someone who can edit the page gets told
			// why, since the usual cause is a missing or wrong access_token.
			if ( current_user_can( 'edit_posts' ) ) {
				$out .= '<p class="evt-access-hint"><em>' . esc_html__( 'Only editors see this note: visitors cannot read this table because the access_token is missing or does not match this pipeline. Copy the ready-made shortcode from EntryVault > Settings > General.', 'entryvault' ) . '</em></p>';
			}
			return $out;
		}

		$columns = self::resolve_columns( $attrs['columns'] ?? null );

		// Whether this viewer may see lead email addresses. A visitor holding only
		// the page's token does not, unless the page author asked for the email
		// column on purpose. The name cell used to fall back to the email for a
		// submission without a name, which published the address anyway.
		$show_email = Capabilities::current_user_can_view_pipeline( (int) $pipeline['id'] )
			|| in_array( 'email', $columns, true );

		$page     = isset( $_GET['evp'] ) ? max( 1, (int) $_GET['evp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
		$search   = isset( $_GET['evq'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['evq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
		$sort_raw = isset( $_GET['evs'] ) ? sanitize_key( (string) $_GET['evs'] ) : 'submitted_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
		$sort     = in_array( $sort_raw, $columns, true ) ? $sort_raw : 'submitted_at';

		$with_tags = in_array( 'tags', $columns, true );
		$query = self::query_submissions( (int) $pipeline['id'], $stage_slug, $search, $sort, $page, $with_tags, $show_email );

		$submissions = $query['rows'];
		$total       = $query['total'];

		$pagination = [
			'page'     => $page,
			'per_page' => self::PER_PAGE,
			'total'    => $total,
			'pages'    => (int) max( 1, ceil( $total / self::PER_PAGE ) ),
			'search'   => $search,
			'sort'     => $sort,
		];

		ob_start();
		include ENTRYVAULT_PATH . 'public/templates/submissions-table.php';
		return (string) ob_get_clean();
	}

	/**
	 * Resolve frontend columns from explicit override, settings, then filter.
	 *
	 * Accepts either an array or a comma-separated string (the shortcode
	 * `columns="..."` form). Unknown columns are dropped against the
	 * `ALLOWED_COLUMNS` whitelist (extendable via the
	 * `entryvault_frontend_columns_whitelist` filter).
	 *
	 * @param array|string|null $override Explicit columns from shortcode/block.
	 * @return string[]
	 */
	public static function resolve_columns( $override = null ): array {
		$columns = null;
		if ( is_array( $override ) && $override ) {
			$columns = $override;
		} elseif ( is_string( $override ) && trim( $override ) !== '' ) {
			$columns = array_filter( array_map( 'trim', explode( ',', $override ) ), 'strlen' );
		}
		if ( ! $columns ) {
			$settings = get_option( 'entryvault_settings', [] );
			$columns  = $settings['frontend_default_columns'] ?? self::DEFAULT_COLUMNS;
		}
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			$columns = self::DEFAULT_COLUMNS;
		}
		/** This filter is documented in EntryVault_Spec.md §8.2. */
		$columns = apply_filters( 'entryvault_frontend_columns', $columns );
		$columns = array_values( array_map( 'sanitize_key', (array) $columns ) );
		// Whitelist filter.
		$whitelist = (array) apply_filters( 'entryvault_frontend_columns_whitelist', self::ALLOWED_COLUMNS );
		$out = [];
		foreach ( $columns as $c ) {
			if ( in_array( $c, $whitelist, true ) ) {
				$out[] = $c;
			}
		}
		return $out ?: self::DEFAULT_COLUMNS;
	}

	/**
	 * Whether the given column key is recognized.
	 */
	public static function is_allowed_column( string $col ): bool {
		$whitelist = (array) apply_filters( 'entryvault_frontend_columns_whitelist', self::ALLOWED_COLUMNS );
		return in_array( $col, $whitelist, true );
	}

	/**
	 * Compare a supplied token to the pipeline's stored access token.
	 *
	 * Read-only and existence-guarded: it never creates an option. A token is
	 * only ever created for a real pipeline at a controlled point (pipeline
	 * seeding on activation, or pipeline creation in Pro) via ensure_token().
	 * This prevents unauthenticated requests with arbitrary slugs from spamming
	 * `wp_options` with empty token rows.
	 */
	public static function token_matches( string $pipeline_slug, string $token ): bool {
		$pipeline_slug = sanitize_key( $pipeline_slug );
		if ( $pipeline_slug === '' || $token === '' ) {
			return false;
		}
		$pipeline = self::get_pipeline_by_slug( $pipeline_slug );
		if ( ! $pipeline ) {
			return false;
		}
		// The token is stored under the pipeline's current slug, which differs
		// when an old slug was looked up through its alias.
		$stored = (string) get_option( 'entryvault_pipeline_token_' . (string) $pipeline['slug'], '' );
		if ( $stored === '' ) {
			return false;
		}
		return hash_equals( $stored, $token );
	}

	/**
	 * Ensure a real pipeline has a stored access token, creating one if absent.
	 * Callers must only invoke this for a pipeline they know exists and from a
	 * trusted context (activation seeding, pipeline creation) — never from an
	 * unauthenticated read path. Returns the token, or '' for an unknown slug.
	 */
	public static function ensure_token( string $pipeline_slug ): string {
		$pipeline_slug = sanitize_key( $pipeline_slug );
		$pipeline      = $pipeline_slug === '' ? null : self::get_pipeline_by_slug( $pipeline_slug );
		if ( ! $pipeline ) {
			return '';
		}
		$option_key = 'entryvault_pipeline_token_' . (string) $pipeline['slug'];
		$stored     = (string) get_option( $option_key, '' );
		if ( $stored === '' ) {
			$stored = wp_generate_password( 32, false );
			update_option( $option_key, $stored, false );
		}
		return $stored;
	}

	/**
	 * Look up a pipeline row by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_pipeline_by_slug( string $slug ): ?array {
		global $wpdb;
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}entryvault_pipelines WHERE slug = %s", $slug ),
			ARRAY_A
		);
		if ( ! $row ) {
			// A shortcode or block saved before 1.21.0 may carry a slug that was
			// replaced because it was percent-encoded; follow it to the new one.
			$alias = Pipeline_Slug::alias( $slug );
			if ( $alias !== '' && $alias !== $slug ) {
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}entryvault_pipelines WHERE slug = %s", $alias ),
					ARRAY_A
				);
			}
		}
		return $row ?: null;
	}

	/**
	 * Query paged submissions for the public table.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public static function query_submissions( int $pipeline_id, string $stage_slug, string $search, string $sort, int $page, bool $with_tags = false, bool $search_email = true ): array {
		global $wpdb;

		$where  = [ 's.pipeline_id = %d', "s.status <> 'archived'" ];
		$params = [ $pipeline_id ];

		$stage_names = Default_Names::stage_aliases( $stage_slug );
		if ( $stage_names ) {
			$where[] = 'st.name IN (' . implode( ', ', array_fill( 0, count( $stage_names ), '%s' ) ) . ')';
			array_push( $params, ...$stage_names );
		}

		if ( $search !== '' ) {
			[ $t_sql, $t_params ] = Submission_Store::search_match( 's.title', $search );
			if ( $search_email ) {
				[ $e_sql, $e_params ] = Submission_Store::search_match( 's.email', $search );
				$where[] = "( {$t_sql} OR {$e_sql} )";
				array_push( $params, ...$t_params, ...$e_params );
			} else {
				// Searching addresses would let a visitor test whether an email
				// is in the CRM, even with the email column hidden. A title that
				// is (or contains) an address is masked on output, so it is not
				// searchable here either.
				$where[] = "( {$t_sql} AND s.title NOT LIKE '%%@%%' AND ( s.email IS NULL OR s.title <> s.email ) )";
				array_push( $params, ...$t_params );
			}
		}

		/**
		 * Extra WHERE fragments for public output (table + public board). Each
		 * entry is [ 'sql' => 's.col = %s', 'params' => [ ... ] ], using the `s`
		 * alias for the submissions table; values are bound via prepare().
		 *
		 * @param array $extra       Empty by default.
		 * @param int   $pipeline_id Pipeline being published.
		 */
		foreach ( (array) apply_filters( 'entryvault_public_query_where', [], $pipeline_id ) as $fragment ) {
			if ( empty( $fragment['sql'] ) || ! is_string( $fragment['sql'] ) ) {
				continue;
			}
			$where[] = '(' . $fragment['sql'] . ')';
			foreach ( (array) ( $fragment['params'] ?? [] ) as $value ) {
				$params[] = $value;
			}
		}

		$where_sql = implode( ' AND ', $where );

		switch ( $sort ) {
			case 'name':
			case 'title':
				$order_col = 's.title';
				break;
			case 'email':
				$order_col = 's.email';
				break;
			case 'stage':
				$order_col = 'st.position';
				break;
			case 'owner':
				$order_col = 's.owner_user_id';
				break;
			case 'created':
			case 'submitted_at':
			default:
				$order_col = 's.created_at';
				break;
		}

		$per_page = self::PER_PAGE;
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		$subs_t   = $wpdb->prefix . 'entryvault_submissions';
		$stages_t = $wpdb->prefix . 'entryvault_stages';
		$base = "FROM %i s
			LEFT JOIN %i st ON st.id = s.stage_id
			WHERE {$where_sql}";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $base is a static SQL fragment with %i identifiers, a whitelisted ORDER BY column and %s/%d placeholders; all values are bound via prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) {$base}",
			array_merge( [ $subs_t, $stages_t ], $params )
		) );

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.title, s.email, s.created_at, s.stage_id, s.owner_user_id,
				st.name AS stage_name, st.color AS stage_color
			 {$base}
			 ORDER BY {$order_col} DESC
			 LIMIT %d OFFSET %d",
			array_merge( [ $subs_t, $stages_t ], $params, [ $per_page, $offset ] )
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Hydrate field values keyed by field_key for any column that's not a built-in.
		if ( $rows ) {
			$ids       = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
			$id_in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_in is a runtime-built list of %d placeholders bound via prepare().
			$data_rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT submission_id, field_key, value
				 FROM %i
				 WHERE submission_id IN ({$id_in})",
				array_merge( [ $wpdb->prefix . 'entryvault_submission_data' ], $ids )
			), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$by_id     = [];
			foreach ( $data_rows as $d ) {
				$by_id[ (int) $d['submission_id'] ][ (string) $d['field_key'] ] = (string) $d['value'];
			}
			foreach ( $rows as &$r ) {
				$r['fields'] = $by_id[ (int) $r['id'] ] ?? [];
			}
			unset( $r );

			if ( $with_tags ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $id_in is a runtime-built list of %d placeholders bound via prepare().
				$tag_rows = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT st.submission_id, t.name
					 FROM %i st
					 INNER JOIN %i t ON t.id = st.tag_id
					 WHERE st.submission_id IN ({$id_in})",
					array_merge( [ $wpdb->prefix . 'entryvault_submission_tags', $wpdb->prefix . 'entryvault_tags' ], $ids )
				), ARRAY_A );
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$tags_by  = [];
				foreach ( $tag_rows as $tr ) {
					$tags_by[ (int) $tr['submission_id'] ][] = (string) ( $tr['name'] ?? '' );
				}
				foreach ( $rows as &$r ) {
					$r['tags'] = $tags_by[ (int) $r['id'] ] ?? [];
				}
				unset( $r );
			}
		}

		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * Format a single cell value for a column key.
	 */
	public static function format_cell( string $column, array $row, bool $show_email = true ): string {
		switch ( $column ) {
			case 'submitted_at':
				// Stored in UTC; shown on the site's clock.
				$ts = Dates::timestamp( (string) ( $row['created_at'] ?? '' ) );
				if ( ! $ts ) {
					return '';
				}
				return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
			case 'created':
				return Dates::to_site( $row['created_at'] ?? '' );
			case 'title':
			case 'name':
				return $show_email ? (string) ( $row['title'] ?? '' ) : self::public_title( $row );
			case 'email':
				return $show_email ? (string) ( $row['email'] ?? '' ) : '';
			case 'stage':
				return Default_Names::label( $row['stage_name'] ?? '' );
			case 'owner':
				$uid = (int) ( $row['owner_user_id'] ?? 0 );
				if ( $uid <= 0 ) {
					return '';
				}
				if ( function_exists( 'get_user_by' ) ) {
					$u = get_user_by( 'id', $uid );
					if ( $u ) {
						return (string) ( $u->display_name ?? $u->user_login ?? '' );
					}
				}
				return (string) $uid;
			case 'tags':
				$tags = $row['tags'] ?? [];
				return is_array( $tags ) ? implode( ', ', $tags ) : (string) $tags;
			default:
				return Field_Values::display( $row['fields'][ $column ] ?? '' );
		}
	}

	/**
	 * A submission title that is safe to show someone who may not see emails.
	 *
	 * Adapters fall back to the email address when a form has no name field, so
	 * the title of a name-less lead IS their address. Any address inside a title
	 * is hidden too ("Enquiry from ann@example.com").
	 */
	public static function public_title( array $row ): string {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		$email = trim( (string) ( $row['email'] ?? '' ) );
		if ( '' === $title || ( '' !== $email && 0 === strcasecmp( $title, $email ) ) || is_email( $title ) ) {
			return __( '(no name)', 'entryvault' );
		}
		$masked = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', __( '(email hidden)', 'entryvault' ), $title );
		return '' !== trim( $masked ) ? $masked : __( '(no name)', 'entryvault' );
	}

	/**
	 * The URL the table's sort, search and page links are built on.
	 *
	 * It used to be the current request URL. When another plugin renders post
	 * content in a background request (WP-Cron, Action Scheduler), that URL is
	 * wp-cron.php or admin-ajax.php with a nonce, and those links were stored
	 * and later reported as broken. Use the post's permalink; with no post, an
	 * empty base gives query-only links ("?evs=name") that resolve against
	 * whatever page the table is shown on.
	 */
	public static function table_base_url(): string {
		$post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		if ( $post_id > 0 ) {
			$link = get_permalink( $post_id );
			if ( is_string( $link ) && '' !== $link ) {
				return remove_query_arg( [ 'evp', 'evq', 'evs' ], $link );
			}
		}
		if ( wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return '';
		}
		return remove_query_arg( [ 'evp', 'evq', 'evs' ] );
	}

	/**
	 * Build one table link. An empty base yields a query-only href.
	 *
	 * @param array<string,mixed> $args Query args; false values are dropped.
	 */
	public static function table_url( array $args, string $base ): string {
		if ( '' !== $base ) {
			return add_query_arg( $args, $base );
		}
		$args = array_filter( $args, static fn( $v ) => false !== $v && null !== $v );
		return '?' . http_build_query( $args );
	}

	/**
	 * Translate column key to a display label.
	 */
	public static function column_label( string $column ): string {
		$map = [
			'submitted_at' => __( 'Submitted', 'entryvault' ),
			'created'      => __( 'Created', 'entryvault' ),
			'title'        => __( 'Title', 'entryvault' ),
			'name'         => __( 'Name', 'entryvault' ),
			'email'        => __( 'Email', 'entryvault' ),
			'stage'        => __( 'Stage', 'entryvault' ),
			'owner'        => __( 'Owner', 'entryvault' ),
			'tags'         => __( 'Tags', 'entryvault' ),
		];
		return $map[ $column ] ?? ucwords( str_replace( [ '_', '-' ], ' ', $column ) );
	}

	/**
	 * Pick a readable text colour for a stage pill.
	 *
	 * Stage colours are user-chosen, so a fixed white foreground is a coin toss —
	 * white on the default amber "Contacted" scores about 2.1:1, well under the 4.5:1
	 * WCAG AA threshold. Rather than guess a luminance cutoff, compute the actual
	 * contrast ratio of both candidates against the background and return the winner.
	 *
	 * @param string $hex Background colour, e.g. "#f59e0b". Bad input falls back to white.
	 */
	public static function readable_text_color( string $hex ): string {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return '#ffffff';
		}

		$channel = static function ( int $v ): float {
			$s = $v / 255;
			return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
		};

		$luminance = 0.2126 * $channel( (int) hexdec( substr( $hex, 0, 2 ) ) )
			+ 0.7152 * $channel( (int) hexdec( substr( $hex, 2, 2 ) ) )
			+ 0.0722 * $channel( (int) hexdec( substr( $hex, 4, 2 ) ) );

		// Relative luminance of the two candidates: pure white, and the WP admin ink
		// used everywhere else in this stylesheet.
		$white_contrast = ( 1.0 + 0.05 ) / ( $luminance + 0.05 );
		$dark_contrast  = ( $luminance + 0.05 ) / ( 0.0177 + 0.05 );

		return $dark_contrast > $white_contrast ? '#1d2327' : '#ffffff';
	}
}
