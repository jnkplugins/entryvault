<?php
namespace EntryVault;

use EntryVault\Historical\Providers;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables, read-only lookups.

/**
 * WP-CLI commands (free).
 *
 *   wp entryvault export [--format=csv|xlsx|json] [--pipeline=..] [--stage=..] [--from=..] [--to=..] [--view=..] [--output=..]
 *   wp entryvault import <file> [--email-col=..] [--dry-run]
 *   wp entryvault backfill <source> [--form=<id>] [--dry-run]
 *   wp entryvault sources list
 *   wp entryvault pipelines list
 *   wp entryvault stages list <pipeline>
 *   wp entryvault entries list [--format=table|csv|json|yaml|count|ids]
 *   wp entryvault views list
 *
 * Output is English, like WP-CLI itself (its help comes from these docblocks,
 * which cannot be translated). WP-CLI runs with no user, so every pipeline is
 * readable: shell access already means full access to the site.
 */
class CLI {

	public const ITEM_FORMATS = [ 'table', 'csv', 'json', 'yaml', 'count', 'ids' ];

	/** Register the commands (only when WP-CLI is running). */
	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}
		$cli = new self();
		$groups = [
			'entryvault'           => 'Export, import and list EntryVault entries.',
			'entryvault sources'   => 'Form plugins past entries can come from.',
			'entryvault pipelines' => 'Pipelines.',
			'entryvault stages'    => 'Stages of a pipeline.',
			'entryvault entries'   => 'Entries.',
			'entryvault views'     => 'Saved views (EntryVault Pro).',
		];
		if ( class_exists( '\\WP_CLI\\Dispatcher\\CommandNamespace' ) ) {
			foreach ( $groups as $name => $desc ) {
				\WP_CLI::add_command( $name, CLI_Group::class, [ 'shortdesc' => $desc ] );
			}
		}
		\WP_CLI::add_command( 'entryvault export', [ $cli, 'export' ] );
		\WP_CLI::add_command( 'entryvault import', [ $cli, 'import' ] );
		\WP_CLI::add_command( 'entryvault backfill', [ $cli, 'backfill' ] );
		\WP_CLI::add_command( 'entryvault sources list', [ $cli, 'sources_list' ] );
		\WP_CLI::add_command( 'entryvault pipelines list', [ $cli, 'pipelines_list' ] );
		\WP_CLI::add_command( 'entryvault stages list', [ $cli, 'stages_list' ] );
		\WP_CLI::add_command( 'entryvault entries list', [ $cli, 'entries_list' ] );
		\WP_CLI::add_command( 'entryvault views list', [ $cli, 'views_list' ] );
	}

	/**
	 * Export entries to CSV, Excel or JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - xlsx
	 *   - json
	 * ---
	 *
	 * [--pipeline=<pipeline>]
	 * : Pipeline id or slug.
	 *
	 * [--stage=<stage>]
	 * : Stage id, or its name within --pipeline.
	 *
	 * [--from=<date>]
	 * : First day (YYYY-MM-DD, site time zone).
	 *
	 * [--to=<date>]
	 * : Last day (YYYY-MM-DD, site time zone).
	 *
	 * [--source=<source>]
	 * : Only entries from this form plugin (e.g. fluent-forms).
	 *
	 * [--status=<status>]
	 * : open, closed, archived, spam or any. Default: everything except archived and spam.
	 *
	 * [--view=<id>]
	 * : A saved view (EntryVault Pro). See `wp entryvault views list`.
	 *
	 * [--columns=<keys>]
	 * : Comma-separated column keys (id,title,email,pipeline,stage,owner,tags,source,source_form,source_entry,status,created,updated, or f:<field label>). Default: all.
	 *
	 * [--output=<file>]
	 * : Write to this file. Required for xlsx; csv and json go to the screen without it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp entryvault export --pipeline=sales --from=2026-10-01 --to=2026-10-31 --output=october.csv
	 *     wp entryvault export --format=json --stage=Won --pipeline=1
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function export( $args, $assoc ): void {
		$assoc  = (array) $assoc;
		$format = strtolower( (string) ( $assoc['format'] ?? 'csv' ) );
		if ( ! in_array( $format, Export::FORMATS, true ) ) {
			\WP_CLI::error( 'Unknown format. Use csv, xlsx or json.' );
			return;
		}
		$query = $this->query_args( $assoc );
		if ( null === $query ) {
			return;
		}
		$chosen  = isset( $assoc['columns'] ) && '' !== trim( (string) $assoc['columns'] )
			? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['columns'] ) ), 'strlen' ) )
			: null;
		$columns = Export::columns( $chosen, Export::field_labels( $query ) );
		$file    = (string) ( $assoc['output'] ?? '' );

		if ( 'xlsx' === $format ) {
			if ( '' === $file ) {
				\WP_CLI::error( 'Excel files need --output=<file>.' );
				return;
			}
			if ( ! Export::write_xlsx( $file, $query, $columns ) ) {
				\WP_CLI::error( 'Could not write the Excel file (the PHP zip extension is missing). Use --format=csv instead.' );
				return;
			}
			\WP_CLI::success( sprintf( 'Wrote %s.', $file ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to a file or STDOUT.
		$out = fopen( '' !== $file ? $file : 'php://output', 'w' );
		if ( ! $out ) {
			\WP_CLI::error( sprintf( 'Could not open %s for writing.', $file ) );
			return;
		}
		$n = 'json' === $format ? Export::write_json( $out, $query, $columns ) : Export::write_csv( $out, $query, $columns );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming.
		fclose( $out );
		if ( '' !== $file ) {
			\WP_CLI::success( sprintf( 'Wrote %d entries to %s.', $n, $file ) );
		}
	}

	/**
	 * Import entries from a CSV file (the same importer as Tools > Import).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--email-col=<header>]
	 * : Column holding the email address. Default: the first header with "email" in it.
	 *
	 * [--title-col=<header>]
	 * : Column used as the entry title (name). Default: the first header with "name" in it.
	 *
	 * [--date-col=<header>]
	 * : Column with the date the entry was sent (keeps old dates).
	 *
	 * [--id-col=<header>]
	 * : Column with the form plugin's entry id (found by name when left out).
	 *
	 * [--source-col=<header>]
	 * : Column naming the source of each row.
	 *
	 * [--fields=<headers>]
	 * : Comma-separated columns to keep as fields. Default: every other column.
	 *
	 * [--pipeline=<pipeline>]
	 * : Pipeline id or slug the entries go to. Default: the default pipeline.
	 *
	 * [--stage=<stage>]
	 * : Stage id, or its name within --pipeline.
	 *
	 * [--run-rules]
	 * : Run "Submission created" automations for the new entries (off by default).
	 *
	 * [--dry-run]
	 * : Check the rows and report, without adding anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp entryvault import leads.csv --dry-run
	 *     wp entryvault import leads.csv --email-col="Email Address" --date-col=Date --pipeline=sales
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function import( $args, $assoc ): void {
		$assoc = (array) $assoc;
		$file  = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named on the command line.
		$parsed  = CSV_Importer::parse( (string) file_get_contents( $file ) );
		$headers = $parsed['headers'];
		if ( ! $headers || ! $parsed['rows'] ) {
			\WP_CLI::error( 'The file has no rows.' );
			return;
		}

		$email = isset( $assoc['email-col'] ) ? (string) $assoc['email-col'] : self::guess_email_column( $headers, $parsed['rows'] );
		if ( '' === $email || ! in_array( $email, $headers, true ) ) {
			\WP_CLI::error( sprintf( 'No email column. Pick one with --email-col (headers: %s).', implode( ', ', $headers ) ) );
			return;
		}
		$title = (string) ( $assoc['title-col'] ?? '' );
		// "Name" in the languages EntryVault ships, then a few more.
		foreach ( isset( $assoc['title-col'] ) ? [] : [ 'name', 'nombre', 'nom', 'اسم', 'الاسم', '이름', 'नाम', '名' ] as $word ) {
			$title = self::guess_header( array_values( array_diff( $headers, [ $email ] ) ), $word );
			if ( '' !== $title ) {
				break;
			}
		}
		foreach ( [ 'title-col', 'date-col', 'id-col', 'source-col' ] as $k ) {
			if ( isset( $assoc[ $k ] ) && ! in_array( (string) $assoc[ $k ], $headers, true ) ) {
				\WP_CLI::error( sprintf( 'Column "%s" is not in the file (headers: %s).', (string) $assoc[ $k ], implode( ', ', $headers ) ) );
				return;
			}
		}

		$pipeline_id = 0;
		$stage_id    = 0;
		if ( isset( $assoc['pipeline'] ) ) {
			$p = self::find_pipeline( (string) $assoc['pipeline'] );
			if ( ! $p ) {
				\WP_CLI::error( sprintf( 'No pipeline "%s". See wp entryvault pipelines list.', (string) $assoc['pipeline'] ) );
				return;
			}
			$pipeline_id = (int) $p['id'];
		}
		if ( isset( $assoc['stage'] ) ) {
			$s = self::find_stage( (string) $assoc['stage'], $pipeline_id );
			if ( ! $s ) {
				\WP_CLI::error( sprintf( 'No stage "%s" in that pipeline.', (string) $assoc['stage'] ) );
				return;
			}
			$stage_id    = (int) $s['id'];
			$pipeline_id = $pipeline_id ?: (int) $s['pipeline_id'];
		}

		$mapped = array_filter( [ $email, $title, (string) ( $assoc['date-col'] ?? '' ), (string) ( $assoc['id-col'] ?? '' ), (string) ( $assoc['source-col'] ?? '' ) ], 'strlen' );
		if ( isset( $assoc['fields'] ) ) {
			$fields = array_values( array_intersect( array_map( 'trim', explode( ',', (string) $assoc['fields'] ) ), $headers ) );
		} else {
			$fields = array_values( array_diff( $headers, $mapped ) );
		}

		$dry     = ! empty( $assoc['dry-run'] );
		$summary = CSV_Importer::import( $parsed['rows'], [
			'email_col'   => $email,
			'title_col'   => $title,
			'date_col'    => (string) ( $assoc['date-col'] ?? '' ),
			'id_col'      => (string) ( $assoc['id-col'] ?? '' ),
			'source_col'  => (string) ( $assoc['source-col'] ?? '' ),
			'pipeline_id' => $pipeline_id,
			'stage_id'    => $stage_id,
			'fields_cols' => $fields,
		], [ 'dry_run' => $dry, 'run_rules' => ! empty( $assoc['run-rules'] ) ] );

		foreach ( array_slice( (array) $summary['errors'], 0, 20 ) as $e ) {
			\WP_CLI::warning( sprintf( 'Row %d: %s', (int) $e['row'], (string) $e['message'] ) );
		}
		if ( ! empty( $summary['bad_dates'] ) ) {
			\WP_CLI::warning( sprintf( '%d dates could not be read; those entries got today\'s date.', (int) $summary['bad_dates'] ) );
		}
		\WP_CLI::success( sprintf(
			$dry ? 'Dry run: %1$d would be imported, %2$d already here, %3$d skipped.' : 'Imported %1$d, %2$d already here, %3$d skipped.',
			(int) $summary['imported'],
			(int) $summary['duplicates'],
			(int) $summary['skipped']
		) );
	}

	/**
	 * Bring in past entries a form plugin already holds (the same reader as Past entries).
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source key, e.g. fluent-forms, wpforms, gravity-forms. See `wp entryvault sources list`.
	 *
	 * [--form=<id>]
	 * : Only this form. Default: every form of the source.
	 *
	 * [--dry-run]
	 * : Count the entries that are not on the board yet, without adding them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp entryvault backfill fluent-forms --dry-run
	 *     wp entryvault backfill wpforms --form=12
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function backfill( $args, $assoc ): void {
		$assoc    = (array) $assoc;
		$key      = (string) ( $args[0] ?? '' );
		$provider = '' !== $key ? Providers::get( $key ) : null;
		if ( ! $provider ) {
			\WP_CLI::error( sprintf( 'Unknown source "%s". See wp entryvault sources list.', $key ) );
			return;
		}
		if ( ! $provider->keeps_entries() ) {
			\WP_CLI::error( sprintf( '%s does not keep entries on this site, so there is nothing to bring in.', $provider->label() ) );
			return;
		}
		if ( ! $provider->available() ) {
			\WP_CLI::error( sprintf( '%s is not active on this site.', $provider->label() ) );
			return;
		}
		if ( $provider->is_experimental() ) {
			$check = $provider->schema_check();
			if ( empty( $check['ok'] ) ) {
				\WP_CLI::error( sprintf( '%s tables do not look as expected (missing: %s).', $provider->label(), implode( ', ', (array) ( $check['missing'] ?? [] ) ) ) );
				return;
			}
		}

		$forms = [];
		foreach ( (array) $provider->forms() as $f ) {
			$id = (int) ( $f['id'] ?? 0 );
			if ( $id > 0 && ( ! isset( $assoc['form'] ) || (int) $assoc['form'] === $id ) ) {
				$forms[ $id ] = (string) ( $f['title'] ?? '' );
			}
		}
		if ( ! $forms ) {
			\WP_CLI::error( isset( $assoc['form'] ) ? sprintf( 'No form %d in %s.', (int) $assoc['form'], $provider->label() ) : sprintf( '%s has no forms.', $provider->label() ) );
			return;
		}

		$dry   = ! empty( $assoc['dry-run'] );
		$total = 0;
		foreach ( $forms as $id => $title ) {
			$name = '' !== $title ? sprintf( '%s (#%d)', $title, $id ) : sprintf( '#%d', $id );
			if ( $dry ) {
				$new    = Past_Entries::not_on_board( $provider, $key, $id, $provider->count( $id ) );
				$total += (int) $new;
				\WP_CLI::log( sprintf( '%s: %s not on the board yet.', $name, null === $new ? 'some' : (string) $new ) );
				continue;
			}
			$state  = self::run_backfill( $id, $key );
			$total += (int) ( $state['done'] ?? 0 );
			foreach ( array_slice( (array) ( $state['errors'] ?? [] ), 0, 5 ) as $err ) {
				\WP_CLI::warning( sprintf( '%s: %s', $name, (string) $err ) );
			}
			\WP_CLI::log( sprintf(
				'%s: %d brought in, %d already here, %d left out (%s).',
				$name,
				(int) ( $state['done'] ?? 0 ),
				(int) ( $state['present'] ?? 0 ),
				max( 0, (int) ( $state['skipped'] ?? 0 ) - (int) ( $state['present'] ?? 0 ) ),
				(string) ( $state['status'] ?? '' )
			) );
		}
		\WP_CLI::success( sprintf( $dry ? 'Dry run: %d entries would be brought in.' : '%d entries brought in.', $total ) );
	}

	/**
	 * Run a whole backfill here, batch after batch, instead of through WP-Cron.
	 *
	 * @return array<string,mixed> The final state.
	 */
	public static function run_backfill( int $form_id, string $key ): array {
		Backfill::start( $form_id, $key );
		for ( $guard = 0; $guard < 100000; $guard++ ) {
			$state = Backfill::state( $form_id, $key );
			if ( 'running' !== ( $state['status'] ?? '' ) ) {
				return $state;
			}
			$next = [ 'form_id' => $form_id, 'source_plugin' => $key, 'offset' => (int) ( $state['offset'] ?? 0 ) ];
			// The batch start() / run() queued for WP-Cron: run it now instead.
			$when = wp_next_scheduled( Backfill::HOOK, [ $next ] );
			if ( $when ) {
				wp_unschedule_event( $when, Backfill::HOOK, [ $next ] );
			}
			Backfill::run( $next );
		}
		return Backfill::state( $form_id, $key );
	}

	/**
	 * List the form plugins past entries can be brought in from.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function sources_list( $args, $assoc ): void {
		$items = [];
		foreach ( Past_Entries::found( true ) as $s ) {
			$items[] = [
				'key'     => (string) $s['key'],
				'label'   => (string) $s['label'],
				'forms'   => count( (array) $s['forms'] ),
				'entries' => (int) $s['total'],
				'new'     => (int) $s['pending'],
				'note'    => empty( $s['keeps_entries'] ) ? 'keeps no entries' : ( ! empty( $s['experimental'] ) ? 'experimental' : '' ),
			];
		}
		self::format( (array) $assoc, $items, [ 'key', 'label', 'forms', 'entries', 'new', 'note' ] );
	}

	/**
	 * List pipelines.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function pipelines_list( $args, $assoc ): void {
		global $wpdb;
		$counts = [];
		foreach ( (array) $wpdb->get_results( "SELECT pipeline_id, COUNT(*) AS n FROM {$wpdb->prefix}entryvault_submissions WHERE status NOT IN ('archived','spam') GROUP BY pipeline_id", ARRAY_A ) as $r ) {
			$counts[ (int) $r['pipeline_id'] ] = (int) $r['n'];
		}
		$items = [];
		foreach ( (array) $wpdb->get_results( "SELECT id, name, slug, is_default FROM {$wpdb->prefix}entryvault_pipelines ORDER BY is_default DESC, id ASC", ARRAY_A ) as $p ) {
			$items[] = [
				'id'      => (int) $p['id'],
				'name'    => Default_Names::label( $p['name'] ),
				'slug'    => (string) $p['slug'],
				'default' => ! empty( $p['is_default'] ) ? 'yes' : '',
				'entries' => $counts[ (int) $p['id'] ] ?? 0,
			];
		}
		self::format( (array) $assoc, $items, [ 'id', 'name', 'slug', 'default', 'entries' ] );
	}

	/**
	 * List the stages of a pipeline.
	 *
	 * ## OPTIONS
	 *
	 * <pipeline>
	 * : Pipeline id or slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function stages_list( $args, $assoc ): void {
		global $wpdb;
		$p = self::find_pipeline( (string) ( $args[0] ?? '' ) );
		if ( ! $p ) {
			\WP_CLI::error( sprintf( 'No pipeline "%s". See wp entryvault pipelines list.', (string) ( $args[0] ?? '' ) ) );
			return;
		}
		$counts = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT stage_id, COUNT(*) AS n FROM {$wpdb->prefix}entryvault_submissions WHERE pipeline_id = %d AND status NOT IN ('archived','spam') GROUP BY stage_id", (int) $p['id'] ), ARRAY_A ) as $r ) {
			$counts[ (int) $r['stage_id'] ] = (int) $r['n'];
		}
		$items = [];
		foreach ( self::stages( (int) $p['id'] ) as $s ) {
			$items[] = [
				'id'       => (int) $s['id'],
				'name'     => Default_Names::label( $s['name'] ),
				'position' => (int) $s['position'],
				'entries'  => $counts[ (int) $s['id'] ] ?? 0,
			];
		}
		self::format( (array) $assoc, $items, [ 'id', 'name', 'position', 'entries' ] );
	}

	/**
	 * List entries.
	 *
	 * ## OPTIONS
	 *
	 * [--pipeline=<pipeline>]
	 * : Pipeline id or slug.
	 *
	 * [--stage=<stage>]
	 * : Stage id, or its name within --pipeline.
	 *
	 * [--from=<date>]
	 * : First day (YYYY-MM-DD, site time zone).
	 *
	 * [--to=<date>]
	 * : Last day (YYYY-MM-DD, site time zone).
	 *
	 * [--source=<source>]
	 * : Only entries from this form plugin.
	 *
	 * [--status=<status>]
	 * : open, closed, archived, spam or any.
	 *
	 * [--search=<text>]
	 * : Name or email contains.
	 *
	 * [--view=<id>]
	 * : A saved view (EntryVault Pro).
	 *
	 * [--limit=<n>]
	 * : At most this many, newest first. 0 for all.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Columns to show.
	 * ---
	 * default: id,title,email,pipeline,stage,status,created
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp entryvault entries list --pipeline=sales --stage=New --format=json
	 *     wp entryvault entries list --from=2026-11-01 --format=count
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function entries_list( $args, $assoc ): void {
		$assoc = (array) $assoc;
		$query = $this->query_args( $assoc );
		if ( null === $query ) {
			return;
		}
		$limit  = max( 0, (int) ( $assoc['limit'] ?? 20 ) );
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( 'count' === $format ) {
			$page = Submission_Store::query( array_merge( $query, [ 'per_page' => 1 ] ) );
			\WP_CLI::line( (string) (int) $page['total'] );
			return;
		}
		$fields = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['fields'] ?? 'id,title,email,pipeline,stage,status,created' ) ) ), 'strlen' ) );
		$keys   = array_map( static fn( $f ) => 'created' === $f || 'updated' === $f || isset( Export::FIXED[ $f ] ) ? $f : 'f:' . $f, $fields );
		$cols   = [];
		foreach ( $keys as $i => $k ) {
			$cols[ $k ] = [ 'heading' => $fields[ $i ], 'label' => $fields[ $i ] ];
		}
		$items = [];
		foreach ( Submission_Store::query_all( $query, $limit ) as $row ) {
			$items[] = array_combine( $fields, Export::cells( $row, $cols ) );
		}
		self::format( $assoc, $items, $fields );
	}

	/**
	 * List saved views (EntryVault Pro), for --view.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function views_list( $args, $assoc ): void {
		/**
		 * Saved views the CLI can use: id => name. Empty unless an add-on keeps views.
		 *
		 * @param array<string,string> $views
		 */
		$views = (array) apply_filters( 'entryvault_cli_views', [] );
		if ( ! $views ) {
			\WP_CLI::warning( 'No saved views on this site.' );
		}
		$items = [];
		foreach ( $views as $id => $name ) {
			$items[] = [ 'id' => (string) $id, 'name' => (string) $name ];
		}
		self::format( (array) $assoc, $items, [ 'id', 'name' ] );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Submission_Store::query() args from the shared filter flags, or null
	 * after reporting an error.
	 *
	 * @param array<string,string> $assoc
	 */
	public function query_args( array $assoc ): ?array {
		$q = [ 'orderby' => 'created_at', 'order' => 'DESC' ];

		if ( isset( $assoc['view'] ) && '' !== (string) $assoc['view'] ) {
			/**
			 * Query args of a saved view for the CLI (null: no such view).
			 *
			 * @param array|null $args Null by default.
			 * @param string     $id   View id.
			 */
			$view = apply_filters( 'entryvault_cli_view_args', null, sanitize_key( (string) $assoc['view'] ) );
			if ( ! is_array( $view ) ) {
				\WP_CLI::error( sprintf( 'No saved view "%s". See wp entryvault views list.', (string) $assoc['view'] ) );
				return null;
			}
			$q = array_merge( $q, $view );
		}

		$pipeline_id = (int) ( $q['pipeline_id'] ?? 0 );
		if ( isset( $assoc['pipeline'] ) ) {
			$p = self::find_pipeline( (string) $assoc['pipeline'] );
			if ( ! $p ) {
				\WP_CLI::error( sprintf( 'No pipeline "%s". See wp entryvault pipelines list.', (string) $assoc['pipeline'] ) );
				return null;
			}
			$pipeline_id      = (int) $p['id'];
			$q['pipeline_id'] = $pipeline_id;
		}
		if ( isset( $assoc['stage'] ) ) {
			$s = self::find_stage( (string) $assoc['stage'], $pipeline_id );
			if ( ! $s ) {
				\WP_CLI::error( sprintf( 'No stage "%s"%s. See wp entryvault stages list <pipeline>.', (string) $assoc['stage'], $pipeline_id ? ' in that pipeline' : '' ) );
				return null;
			}
			$q['stage_id'] = (int) $s['id'];
		}
		foreach ( [ 'from' => 'date_from', 'to' => 'date_to' ] as $flag => $key ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$d = (string) $assoc[ $flag ];
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || ! checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ) ) {
					\WP_CLI::error( sprintf( '--%s must be a date like 2026-11-02.', $flag ) );
					return null;
				}
				$q[ $key ] = $d;
			}
		}
		if ( isset( $assoc['status'] ) ) {
			$st = sanitize_key( (string) $assoc['status'] );
			if ( ! in_array( $st, [ 'open', 'closed', 'archived', 'spam', 'any', 'all' ], true ) ) {
				\WP_CLI::error( '--status must be open, closed, archived, spam or any.' );
				return null;
			}
			$q['status'] = $st;
		}
		if ( isset( $assoc['source'] ) ) {
			$q['source'] = sanitize_text_field( (string) $assoc['source'] );
		}
		if ( isset( $assoc['search'] ) ) {
			$q['search'] = sanitize_text_field( (string) $assoc['search'] );
		}
		return $q;
	}

	/** A pipeline by id or slug. @return array<string,mixed>|null */
	public static function find_pipeline( string $ref ): ?array {
		global $wpdb;
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return null;
		}
		$row = ctype_digit( $ref )
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}entryvault_pipelines WHERE id = %d", (int) $ref ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}entryvault_pipelines WHERE slug = %s", sanitize_key( $ref ) ), ARRAY_A );
		return $row ?: null;
	}

	/** A stage by id, or by name (stored or translated) within a pipeline. @return array<string,mixed>|null */
	public static function find_stage( string $ref, int $pipeline_id ): ?array {
		global $wpdb;
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}entryvault_stages WHERE id = %d", (int) $ref ), ARRAY_A );
			return $row && ( ! $pipeline_id || (int) $row['pipeline_id'] === $pipeline_id ) ? $row : null;
		}
		if ( $pipeline_id <= 0 ) {
			return null;
		}
		$want = function_exists( 'mb_strtolower' ) ? mb_strtolower( $ref ) : strtolower( $ref );
		foreach ( self::stages( $pipeline_id ) as $s ) {
			foreach ( [ (string) $s['name'], Default_Names::label( $s['name'] ) ] as $n ) {
				if ( ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $n ) : strtolower( $n ) ) === $want ) {
					return $s;
				}
			}
		}
		return null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function stages( int $pipeline_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}entryvault_stages WHERE pipeline_id = %d ORDER BY position ASC, id ASC", $pipeline_id ), ARRAY_A );
	}

	/**
	 * The email column: a header with "mail" in it, else the column whose
	 * values are mostly email addresses (headers in any language).
	 *
	 * @param string[]                       $headers
	 * @param array<int,array<string,string>> $rows
	 */
	public static function guess_email_column( array $headers, array $rows ): string {
		$by_name = self::guess_header( $headers, 'mail' );
		if ( '' !== $by_name ) {
			return $by_name;
		}
		$sample = array_slice( $rows, 0, 20 );
		foreach ( $headers as $h ) {
			$seen = 0;
			$hits = 0;
			foreach ( $sample as $r ) {
				$v = trim( (string) ( $r[ $h ] ?? '' ) );
				if ( '' !== $v ) {
					$seen++;
					$hits += is_email( $v ) ? 1 : 0;
				}
			}
			if ( $seen > 0 && $hits * 2 >= $seen ) {
				return (string) $h;
			}
		}
		return '';
	}

	/** First header containing $needle (case-insensitive), or ''. @param string[] $headers */
	private static function guess_header( array $headers, string $needle ): string {
		foreach ( $headers as $h ) {
			if ( false !== mb_stripos( (string) $h, $needle ) ) {
				return (string) $h;
			}
		}
		return '';
	}

	/**
	 * @param array<string,string>             $assoc
	 * @param array<int,array<string,mixed>>   $items
	 * @param string[]                         $fields
	 */
	private static function format( array $assoc, array $items, array $fields ): void {
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( ! in_array( $format, self::ITEM_FORMATS, true ) ) {
			$format = 'table';
		}
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_map( static fn( $i ) => (string) ( $i['id'] ?? '' ), $items ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}
}
