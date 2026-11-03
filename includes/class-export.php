<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables, export path.

/**
 * Manual export from the Submissions screen: CSV, Excel (.xlsx) or JSON, with
 * the columns the owner ticks, in the order they put them, of exactly what the
 * list is filtering.
 *
 * Column keys: the fixed ones in FIXED, "f:<field label>" for a submitted
 * field, and whatever the entryvault_export_columns filter adds (with an
 * entryvault_export_value filter for its values).
 */
class Export {

	public const FORMATS = [ 'csv', 'xlsx', 'json' ];
	public const PREF    = 'entryvault_export_prefs';

	/** Fixed column key => heading (headings stay English, as in older exports, so spreadsheets keep working). */
	public const FIXED = [
		'id'           => 'ID',
		'title'        => 'Title',
		'email'        => 'Email',
		'pipeline'     => 'Pipeline',
		'stage'        => 'Stage',
		'owner'        => 'Owner',
		'tags'         => 'Tags',
		'source'       => 'Source',
		'source_form'  => 'Source Form',
		'source_entry' => 'Source Entry',
		'status'       => 'Status',
		'created'      => 'Created',
		'updated'      => 'Updated',
	];

	/** Labels shown in the column chooser (translated). @return array<string,string> */
	public static function fixed_labels(): array {
		return [
			'id'           => __( 'ID', 'entryvault' ),
			'title'        => __( 'Title', 'entryvault' ),
			'email'        => __( 'Email', 'entryvault' ),
			'pipeline'     => __( 'Pipeline', 'entryvault' ),
			'stage'        => __( 'Stage', 'entryvault' ),
			'owner'        => __( 'Owner', 'entryvault' ),
			'tags'         => __( 'Tags', 'entryvault' ),
			'source'       => __( 'Source', 'entryvault' ),
			'source_form'  => __( 'Form ID', 'entryvault' ),
			'source_entry' => __( 'Entry ID in the form plugin', 'entryvault' ),
			'status'       => __( 'Status', 'entryvault' ),
			'created'      => __( 'Created', 'entryvault' ),
			'updated'      => __( 'Updated', 'entryvault' ),
		];
	}

	/**
	 * Every column the export can hold, in the default order.
	 *
	 * @return array<string,array{heading:string,label:string}>
	 */
	public static function available( array $field_labels ): array {
		$out    = [];
		$labels = self::fixed_labels();
		foreach ( self::FIXED as $key => $heading ) {
			$out[ $key ] = [ 'heading' => $heading, 'label' => $labels[ $key ] ];
		}
		/**
		 * Extra export columns: key => [ 'heading' => ..., 'label' => ... ].
		 * Fill their values with entryvault_export_value.
		 *
		 * @param array $extra
		 */
		foreach ( (array) apply_filters( 'entryvault_export_columns', [] ) as $key => $col ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && ! isset( $out[ $key ] ) && is_array( $col ) ) {
				$out[ $key ] = [ 'heading' => (string) ( $col['heading'] ?? $key ), 'label' => (string) ( $col['label'] ?? $col['heading'] ?? $key ) ];
			}
		}
		$headings = Submissions_Admin::field_headers( array_column( $out, 'heading' ), $field_labels );
		foreach ( array_values( $field_labels ) as $i => $label ) {
			$out[ 'f:' . $label ] = [ 'heading' => $headings[ $i ], 'label' => (string) $label ];
		}
		return $out;
	}

	/**
	 * The chosen columns, in order; unknown keys dropped; all when none chosen.
	 *
	 * @param string[]|null $chosen
	 * @return array<string,array{heading:string,label:string}>
	 */
	public static function columns( ?array $chosen, array $field_labels ): array {
		$all = self::available( $field_labels );
		if ( ! $chosen ) {
			return $all;
		}
		$out = [];
		foreach ( $chosen as $key ) {
			$key = (string) $key;
			if ( isset( $all[ $key ] ) ) {
				$out[ $key ] = $all[ $key ];
			}
		}
		return $out ?: $all;
	}

	/**
	 * Distinct field labels of the entries the filters match (at most 200).
	 *
	 * @return string[]
	 */
	public static function field_labels( array $args ): array {
		global $wpdb;
		$ids = array_map( static fn( $r ) => (int) $r['id'], Submission_Store::query_all( array_merge( $args, [ 'orderby' => 'id' ] ), 5000 ) );
		if ( ! $ids ) {
			return [];
		}
		$labels = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT field_label FROM {$wpdb->prefix}entryvault_submission_data WHERE field_label <> '' AND is_stale = 0 AND submission_id IN ({$in})", $chunk ) ) as $l ) {
				$labels[ (string) $l ] = true;
			}
		}
		$labels = array_keys( $labels );
		sort( $labels, SORT_NATURAL | SORT_FLAG_CASE );
		return array_slice( $labels, 0, 200 );
	}

	/**
	 * Every matching row, as cells in column order. Calls $emit per row.
	 *
	 * @param array<string,array{heading:string,label:string}> $columns
	 * @param callable(array<int,string>):void $emit
	 */
	public static function each_row( array $args, array $columns, callable $emit ): int {
		$args['per_page'] = Submission_Store::MAX_PER_PAGE;
		$args['paged']    = 1;
		$names            = self::names();
		$written          = 0;
		do {
			$page = Submission_Store::query( $args );
			foreach ( $page['rows'] as $row ) {
				$emit( self::cells( $row, $columns, $names ) );
				$written++;
			}
			$args['paged']++;
			$more = ( $args['paged'] - 1 ) * $args['per_page'] < (int) $page['total'];
		} while ( $more );
		return $written;
	}

	/** @return array<int,string> */
	public static function cells( array $row, array $columns, ?array $names = null ): array {
		$names  = $names ?? self::names();
		$id     = (int) ( $row['id'] ?? 0 );
		$fields = null;
		$out    = [];
		foreach ( array_keys( $columns ) as $key ) {
			switch ( $key ) {
				case 'id':           $v = (string) $id; break;
				case 'title':        $v = (string) ( $row['title'] ?? '' ); break;
				case 'email':        $v = (string) ( $row['email'] ?? '' ); break;
				case 'pipeline':     $v = (string) ( $names['pipelines'][ (int) ( $row['pipeline_id'] ?? 0 ) ] ?? ( $row['pipeline_id'] ?? '' ) ); break;
				case 'stage':        $v = (string) ( $names['stages'][ (int) ( $row['stage_id'] ?? 0 ) ] ?? ( $row['stage_id'] ?? '' ) ); break;
				case 'owner':        $v = self::owner_name( (int) ( $row['owner_user_id'] ?? 0 ) ); break;
				case 'tags':         $v = implode( ', ', array_map( static fn( $t ) => (string) $t['name'], Tag_Manager::for_submission( $id ) ) ); break;
				case 'source':       $v = Sources::label( (string) ( $row['source_plugin'] ?? '' ) ); break;
				case 'source_form':  $v = (string) ( $row['source_form_id'] ?? '' ); break;
				case 'source_entry': $v = (string) ( $row['source_entry_id'] ?? '' ); break;
				case 'status':       $v = (string) ( $row['status'] ?? '' ); break;
				// Exports keep the site's clock, as before 1.17.0.
				case 'created':      $v = Dates::to_site( $row['created_at'] ?? '' ); break;
				case 'updated':      $v = Dates::to_site( $row['updated_at'] ?? '' ); break;
				default:
					if ( 0 === strpos( $key, 'f:' ) ) {
						$fields = $fields ?? self::field_values( $id );
						$v      = $fields[ substr( $key, 2 ) ] ?? '';
					} else {
						/**
						 * Value of an extra export column.
						 *
						 * @param string $value ''.
						 * @param string $key   Column key.
						 * @param array  $row   Submission row.
						 */
						$v = (string) apply_filters( 'entryvault_export_value', '', $key, $row );
					}
			}
			$out[] = (string) $v;
		}
		return $out;
	}

	/** @return array<string,string> label => value */
	private static function field_values( int $submission_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT field_label, field_type, value FROM {$wpdb->prefix}entryvault_submission_data WHERE submission_id = %d AND is_stale = 0",
			$submission_id
		), ARRAY_A );
		$out = [];
		foreach ( $rows as $r ) {
			$label = (string) $r['field_label'];
			$value = Field_Values::display( (string) $r['value'], (string) ( $r['field_type'] ?? '' ) );
			$out[ $label ] = isset( $out[ $label ] ) ? $out[ $label ] . ', ' . $value : $value;
		}
		return $out;
	}

	/** @return array{pipelines:array<int,string>,stages:array<int,string>} */
	private static function names(): array {
		global $wpdb;
		$out = [ 'pipelines' => [], 'stages' => [] ];
		foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_pipelines", ARRAY_A ) as $r ) {
			$out['pipelines'][ (int) $r['id'] ] = Default_Names::label( $r['name'] );
		}
		foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_stages", ARRAY_A ) as $r ) {
			$out['stages'][ (int) $r['id'] ] = Default_Names::label( $r['name'] );
		}
		return $out;
	}

	private static function owner_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : sprintf( '#%d', $user_id );
	}

	/* ------------------------------------------------------------------
	 * Writers
	 * ---------------------------------------------------------------- */

	/** CSV with the UTF-8 BOM (Excel on Windows), formula-safe cells. @param resource $out */
	public static function write_csv( $out, array $args, array $columns ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming; WP_Filesystem has no streaming API.
		fwrite( $out, Submissions_Admin::CSV_BOM );
		fputcsv( $out, array_column( $columns, 'heading' ), ',', '"', '' );
		return self::each_row( $args, $columns, static function ( $cells ) use ( $out ) {
			fputcsv( $out, array_map( [ Submissions_Admin::class, 'csv_safe' ], $cells ), ',', '"', '' );
		} );
	}

	/** JSON: an array of objects keyed by heading. @param resource $out */
	public static function write_json( $out, array $args, array $columns ): int {
		$headings = array_column( $columns, 'heading' );
		$first    = true;
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming; WP_Filesystem has no streaming API.
		fwrite( $out, "[\n" );
		$n = self::each_row( $args, $columns, static function ( $cells ) use ( $out, $headings, &$first ) {
			fwrite( $out, ( $first ? '' : ",\n" ) . wp_json_encode( array_combine( $headings, $cells ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$first = false;
		} );
		fwrite( $out, "\n]\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		return $n;
	}

	/** XLSX to a file path; cells stay text, so no formula ever runs. */
	public static function write_xlsx( string $path, array $args, array $columns ): bool {
		$rows = [];
		self::each_row( $args, $columns, static function ( $cells ) use ( &$rows ) {
			if ( count( $rows ) < XLSX_Writer::MAX_ROWS ) {
				$rows[] = $cells;
			}
		} );
		return XLSX_Writer::write( $path, array_column( $columns, 'heading' ), $rows );
	}

	/* ------------------------------------------------------------------
	 * Request handling
	 * ---------------------------------------------------------------- */

	/** @return array{format:string,cols:string[]} The user's last choice. */
	public static function prefs(): array {
		$p = get_user_meta( get_current_user_id(), self::PREF, true );
		$p = is_array( $p ) ? $p : [];
		return [
			'format' => in_array( $p['format'] ?? '', self::FORMATS, true ) ? (string) $p['format'] : 'csv',
			'cols'   => array_values( array_map( 'strval', (array) ( $p['cols'] ?? [] ) ) ),
		];
	}

	/** Stream the export for a request (admin-post). */
	public static function stream( array $request ): void {
		$args   = Submissions_Admin::args_from_request( $request );
		$format = isset( $request['format'] ) ? sanitize_key( (string) $request['format'] ) : 'csv';
		$format = in_array( $format, self::FORMATS, true ) ? $format : 'csv';
		$chosen = isset( $request['cols'] ) && is_array( $request['cols'] )
			? array_values( array_map( static fn( $c ) => sanitize_text_field( wp_unslash( (string) $c ) ), $request['cols'] ) )
			: null;
		$columns = self::columns( $chosen, self::field_labels( $args ) );
		if ( null !== $chosen ) {
			update_user_meta( get_current_user_id(), self::PREF, [ 'format' => $format, 'cols' => array_keys( $columns ) ] );
		}
		$name = 'entryvault-submissions-' . gmdate( 'Ymd-His' );
		nocache_headers();
		if ( 'xlsx' === $format ) {
			$tmp = wp_tempnam( $name . '.xlsx' );
			if ( ! self::write_xlsx( $tmp, $args, $columns ) ) {
				wp_die( esc_html__( 'Could not build the Excel file on this server (the PHP zip extension is missing). Choose CSV instead.', 'entryvault' ) );
			}
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.xlsx"' );
			header( 'Content-Length: ' . filesize( $tmp ) );
			readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a temp file.
			wp_delete_file( $tmp );
			exit;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to php://output.
		$out = fopen( 'php://output', 'w' );
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.json"' );
			self::write_json( $out, $args, $columns );
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.csv"' );
			self::write_csv( $out, $args, $columns );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming.
		fclose( $out );
		exit;
	}
}
