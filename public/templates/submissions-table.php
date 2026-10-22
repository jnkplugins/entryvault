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
			if ( in_array( $k, [ 'evp', 'evq', 'evs' ], true ) || ! is_scalar( $v ) ) {
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
		<button type="submit" class="evt-search-submit"><?php esc_html_e( 'Search', 'entryvault' ); ?></button>
	</form>

	<table class="evt-table">
		<thead>
			<tr>
				<?php foreach ( $columns as $col ) :
					$sort_url = \EntryVault\Frontend::table_url(
						[
							'evs' => $col,
							'evq' => $search !== '' ? $search : false,
						],
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
							<td class="evt-td evt-td-<?php echo esc_attr( $col ); ?>">
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

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="evt-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'entryvault' ); ?>">
			<?php
			$prev_url = \EntryVault\Frontend::table_url(
				[
					'evp' => max( 1, $current_page - 1 ),
					'evq' => $search !== '' ? $search : false,
					'evs' => $current_sort !== 'submitted_at' ? $current_sort : false,
				],
				$base_url
			);
			$next_url = \EntryVault\Frontend::table_url(
				[
					'evp' => min( $total_pages, $current_page + 1 ),
					'evq' => $search !== '' ? $search : false,
					'evs' => $current_sort !== 'submitted_at' ? $current_sort : false,
				],
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
