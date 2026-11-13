<?php
/**
 * Public submissions table template.
 *
 * Expected in-scope vars (provided by EntryVault\Frontend::render_table):
 *
 * @var array               $attrs       Original shortcode/block attributes.
 * @var array<int,array>    $submissions Result rows.
 * @var string[]            $columns     Resolved column keys.
 * @var array<string,mixed> $pagination  ['page','per_page','total','pages','search','sort'].
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

// Base for sort/search/page links: the post permalink, never a background
// request URL (see Frontend::table_base_url()).
$base_url   = \EntryVault\Frontend::table_base_url();
$show_email = isset( $show_email ) ? (bool) $show_email : true;

$current_sort = isset( $pagination['sort'] ) ? (string) $pagination['sort'] : 'submitted_at';
$current_page = isset( $pagination['page'] ) ? (int) $pagination['page'] : 1;
$total_pages  = isset( $pagination['pages'] ) ? (int) $pagination['pages'] : 1;
$search       = isset( $pagination['search'] ) ? (string) $pagination['search'] : '';
// Active visitor filters as query args; every sort and page link keeps them.
$filter_args  = isset( $pagination['filters'] ) && is_array( $pagination['filters'] ) ? $pagination['filters'] : [];
$filter_ui    = isset( $filter_ui ) && is_array( $filter_ui ) ? $filter_ui : [];
$filter_opts  = isset( $filter_options ) && is_array( $filter_options ) ? $filter_options : [ 'stages' => [], 'tags' => [], 'fields' => [] ];
$active       = isset( $filters ) && is_array( $filters ) ? $filters : [];
$table_uid    = 'evt-' . substr( md5( (string) ( $attrs['pipeline'] ?? '' ) . wp_json_encode( $columns ) ), 0, 8 );
?>
<div
	class="evt-wrap"
	data-pipeline="<?php echo esc_attr( $attrs['pipeline'] ?? '' ); ?>"
	data-columns="<?php echo esc_attr( implode( ',', $columns ) ); ?>"
>

	<form class="evt-search" method="get" action="<?php echo '' !== $base_url ? esc_url( $base_url ) : ''; ?>">
		<?php
		// A GET form drops the query string of its action URL, so carry the
		// base URL's own query args (e.g. ?page_id=5 with plain permalinks).
		$base_query = [];
		wp_parse_str( (string) wp_parse_url( $base_url, PHP_URL_QUERY ), $base_query );
		foreach ( $base_query as $k => $v ) {
			$k = (string) $k;
			if ( in_array( $k, array_merge( \EntryVault\Frontend::VIEW_PARAMS, \EntryVault\Frontend::FILTER_PARAMS ), true ) || ! is_scalar( $v ) ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $k ),
				esc_attr( (string) $v )
			);
		}
		if ( $current_sort !== 'submitted_at' ) {
			printf( '<input type="hidden" name="evs" value="%s" />', esc_attr( $current_sort ) );
		}
		// A view around the form can ask to keep its own place (e.g. the month).
		foreach ( ( isset( $extra_hidden ) && is_array( $extra_hidden ) ? $extra_hidden : [] ) as $k => $v ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $k ), esc_attr( (string) $v ) );
		}
		?>
		<label class="evt-search-label">
			<span class="screen-reader-text"><?php esc_html_e( 'Search', 'entryvault' ); ?></span>
			<input
				type="search"
				name="evq"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search…', 'entryvault' ); ?>"
				class="evt-search-input"
			/>
		</label>
		<?php if ( $filter_ui ) : ?>
			<fieldset class="evt-filters">
				<legend class="screen-reader-text"><?php esc_html_e( 'Filter entries', 'entryvault' ); ?></legend>
				<?php if ( in_array( 'stage', $filter_ui, true ) ) : ?>
					<label class="evt-filter" for="<?php echo esc_attr( $table_uid . '-stage' ); ?>">
						<span class="evt-filter-label"><?php esc_html_e( 'Stage', 'entryvault' ); ?></span>
						<select name="evst" id="<?php echo esc_attr( $table_uid . '-stage' ); ?>">
							<option value=""><?php esc_html_e( 'Any stage', 'entryvault' ); ?></option>
							<?php foreach ( $filter_opts['stages'] as $st ) : ?>
								<option value="<?php echo esc_attr( (string) $st['id'] ); ?>" <?php selected( (int) ( $active['stage_id'] ?? 0 ), (int) $st['id'] ); ?>><?php echo esc_html( $st['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( in_array( 'tag', $filter_ui, true ) ) : ?>
					<label class="evt-filter" for="<?php echo esc_attr( $table_uid . '-tag' ); ?>">
						<span class="evt-filter-label"><?php esc_html_e( 'Tag', 'entryvault' ); ?></span>
						<select name="evtag" id="<?php echo esc_attr( $table_uid . '-tag' ); ?>">
							<option value=""><?php esc_html_e( 'Any tag', 'entryvault' ); ?></option>
							<?php foreach ( $filter_opts['tags'] as $tg ) : ?>
								<option value="<?php echo esc_attr( (string) $tg['id'] ); ?>" <?php selected( (int) ( $active['tag_id'] ?? 0 ), (int) $tg['id'] ); ?>><?php echo esc_html( $tg['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( in_array( 'date', $filter_ui, true ) ) : ?>
					<label class="evt-filter" for="<?php echo esc_attr( $table_uid . '-from' ); ?>">
						<span class="evt-filter-label"><?php esc_html_e( 'Submitted from', 'entryvault' ); ?></span>
						<input type="date" name="evfrom" id="<?php echo esc_attr( $table_uid . '-from' ); ?>" value="<?php echo esc_attr( (string) ( $active['from'] ?? '' ) ); ?>" />
					</label>
					<label class="evt-filter" for="<?php echo esc_attr( $table_uid . '-to' ); ?>">
						<span class="evt-filter-label"><?php esc_html_e( 'to', 'entryvault' ); ?></span>
						<input type="date" name="evto" id="<?php echo esc_attr( $table_uid . '-to' ); ?>" value="<?php echo esc_attr( (string) ( $active['to'] ?? '' ) ); ?>" />
					</label>
				<?php endif; ?>
				<?php foreach ( $filter_opts['fields'] as $fkey => $flabel ) : ?>
					<label class="evt-filter" for="<?php echo esc_attr( $table_uid . '-f-' . $fkey ); ?>">
						<span class="evt-filter-label"><?php echo esc_html( $flabel ); ?></span>
						<input type="text" name="<?php echo esc_attr( 'evf[' . $fkey . ']' ); ?>" id="<?php echo esc_attr( $table_uid . '-f-' . $fkey ); ?>" value="<?php echo esc_attr( (string) ( $active['fields'][ $fkey ] ?? '' ) ); ?>" />
					</label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<button type="submit" class="evt-search-submit"><?php echo $filter_ui ? esc_html__( 'Apply', 'entryvault' ) : esc_html__( 'Search', 'entryvault' ); ?></button>
		<?php if ( $filter_args || '' !== $search ) : ?>
			<a class="evt-filter-clear" href="<?php echo esc_url( \EntryVault\Frontend::table_url( [ 'evs' => $current_sort !== 'submitted_at' ? $current_sort : false ], $base_url ) ); ?>"><?php esc_html_e( 'Clear filters', 'entryvault' ); ?></a>
		<?php endif; ?>
	</form>
	<?php if ( $filter_ui && empty( $hide_count ) ) : ?>
		<p class="evt-result-count" role="status">
			<?php
			$ev_total = isset( $pagination['total'] ) ? (int) $pagination['total'] : 0;
			/* translators: %s: number of entries matching the filters. */
			echo esc_html( sprintf( _n( '%s entry', '%s entries', $ev_total, 'entryvault' ), number_format_i18n( $ev_total ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php
	/**
	 * Extra tools shown above the entries (add-ons: a download link).
	 * Return escaped HTML.
	 *
	 * @param string $html Empty by default.
	 * @param array  $info attrs, columns, filters (active), search, sort, order, pipeline.
	 */
	$ev_tools = (string) apply_filters( 'entryvault_public_table_tools', '', [
		'attrs'    => isset( $attrs ) ? (array) $attrs : [],
		'columns'  => (array) $columns,
		'filters'  => $active,
		'search'   => $search,
		'sort'     => $current_sort,
		'pipeline' => isset( $pipeline ) ? (array) $pipeline : [],
	] );
	if ( '' !== $ev_tools ) {
		echo '<div class="evt-tools">' . $ev_tools . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the add-on that returned it.
	}
	?>
	<?php if ( isset( $body_html ) && is_string( $body_html ) ) : ?>
		<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by the layout that returned it. ?>
	<?php else : ?>
	<table class="evt-table">
		<thead>
			<tr>
				<?php foreach ( $columns as $col ) :
					$sort_url = \EntryVault\Frontend::table_url(
						array_merge( [
							'evs' => $col,
							'evq' => $search !== '' ? $search : false,
						], $filter_args ),
						$base_url
					);
					$is_active = ( $col === $current_sort );
				?>
					<th
						scope="col"
						class="evt-th evt-th-<?php echo esc_attr( $col ); ?><?php echo $is_active ? ' is-active' : ''; ?>"
					>
						<a href="<?php echo esc_url( $sort_url ); ?>" class="evt-sort-link">
							<?php echo esc_html( \EntryVault\Frontend::column_label( $col ) ); ?>
						</a>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $submissions ) ) : ?>
				<tr class="evt-empty">
					<td colspan="<?php echo esc_attr( (string) max( 1, count( $columns ) ) ); ?>">
						<?php esc_html_e( 'No submissions found.', 'entryvault' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $submissions as $row ) : ?>
					<tr class="evt-row" data-id="<?php echo esc_attr( (string) ( $row['id'] ?? '' ) ); ?>">
						<?php foreach ( $columns as $col ) : ?>
							<td class="evt-td evt-td-<?php echo esc_attr( $col ); ?>" data-label="<?php echo esc_attr( \EntryVault\Frontend::column_label( $col ) ); ?>">
								<?php
								$cell = \EntryVault\Frontend::format_cell( $col, $row, $show_email );
								if ( $col === 'stage' && ! empty( $row['stage_color'] ) ) :
									?>
									<span
										class="evt-stage-pill"
										style="background-color: <?php echo esc_attr( (string) $row['stage_color'] ); ?>; color: <?php echo esc_attr( \EntryVault\Frontend::readable_text_color( (string) $row['stage_color'] ) ); ?>;"
									><?php echo esc_html( $cell ); ?></span>
								<?php else : ?>
									<?php echo esc_html( $cell ); ?>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="evt-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'entryvault' ); ?>">
			<?php
			$prev_url = \EntryVault\Frontend::table_url(
				array_merge( [
					'evp' => max( 1, $current_page - 1 ),
					'evq' => $search !== '' ? $search : false,
					'evs' => $current_sort !== 'submitted_at' ? $current_sort : false,
				], $filter_args ),
				$base_url
			);
			$next_url = \EntryVault\Frontend::table_url(
				array_merge( [
					'evp' => min( $total_pages, $current_page + 1 ),
					'evq' => $search !== '' ? $search : false,
					'evs' => $current_sort !== 'submitted_at' ? $current_sort : false,
				], $filter_args ),
				$base_url
			);
			?>
			<?php if ( $current_page > 1 ) : ?>
				<a class="evt-page-prev" href="<?php echo esc_url( $prev_url ); ?>">
					<?php esc_html_e( '« Previous', 'entryvault' ); ?>
				</a>
			<?php endif; ?>

			<span class="evt-page-status">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'entryvault' ),
					(int) $current_page,
					(int) $total_pages
				);
				?>
			</span>

			<?php if ( $current_page < $total_pages ) : ?>
				<a class="evt-page-next" href="<?php echo esc_url( $next_url ); ?>">
					<?php esc_html_e( 'Next »', 'entryvault' ); ?>
				</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

</div>
