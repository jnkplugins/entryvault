<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- small counts for the widget.

/**
 * "EntryVault" box on the WordPress Dashboard: unread entries, entries that
 * came in today, open entries nobody owns, the five newest, and links to the
 * board. Add-ons add their own counts (Pro: overdue follow-ups) through the
 * entryvault_dashboard_widget_stats filter. Counts follow the user's pipeline
 * access, like the admin menu badge.
 */
final class Dashboard_Widget {

	public const ID = 'entryvault_dashboard_widget';

	public static function register(): void {
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'setup' ] );
	}

	public static function setup(): void {
		if ( ! Capabilities::current_user_has_pipeline_access() || ! current_user_can( 'read' ) ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'EntryVault inbox', 'entryvault' ), [ __CLASS__, 'render' ] );
	}

	/**
	 * WHERE fragment + params limiting to the user's pipelines (and, by
	 * default, open entries).
	 *
	 * @param string $status Fixed SQL status fragment.
	 * @return array{0:string,1:array}
	 */
	private static function scope( string $status = "status = 'open'" ): array {
		if ( Capabilities::current_user_can_manage() ) {
			return [ $status, [] ];
		}
		$ids = array_values( array_filter( array_map( 'intval', Capabilities::viewable_pipeline_ids_for_current_user() ) ) );
		if ( ! $ids ) {
			return [ '0=1', [] ];
		}
		return [ $status . ' AND pipeline_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ];
	}

	/**
	 * The numbers shown, in order.
	 *
	 * @return array<int,array{key:string,label:string,count:int,url:string,tone?:string}>
	 */
	public static function stats(): array {
		global $wpdb;
		$t               = $wpdb->prefix . 'entryvault_submissions';
		[ $where, $p ]   = self::scope();
		[ $start, $end ] = Dates::site_day_bounds_utc( Dates::site_today() );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds only placeholders.
		$count = static function ( string $extra, array $params = [] ) use ( $wpdb, $t, $where, $p ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where} {$extra}", array_merge( [ $t ], $p, $params ) ) );
		};
		// "Received today" counts what the Overview counts: every entry that
		// came in today except spam, archived ones included. It used to count
		// open entries only, so archiving one made the two screens disagree.
		[ $rwhere, $rp ] = self::scope( "status <> 'spam'" );
		$received_today  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$rwhere} AND created_at BETWEEN %s AND %s", array_merge( [ $t ], $rp, [ $start, $end ] ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list  = admin_url( 'admin.php?page=entryvault-submissions' );
		$stats = [
			[ 'key' => 'unread', 'label' => __( 'Unread', 'entryvault' ), 'count' => $count( 'AND read_at IS NULL' ), 'url' => add_query_arg( 'unread', '1', $list ) ],
			[ 'key' => 'today', 'label' => __( 'Received today', 'entryvault' ), 'count' => $received_today, 'url' => add_query_arg( [ 'date_from' => Dates::site_today(), 'date_to' => Dates::site_today() ], $list ) ],
			[ 'key' => 'unassigned', 'label' => __( 'No owner yet', 'entryvault' ), 'count' => $count( 'AND owner_user_id IS NULL' ), 'url' => add_query_arg( 'owner_id', '0', $list ) ],
		];
		/**
		 * Filter the counts on the Dashboard box.
		 *
		 * @param array $stats List of [ key, label, count, url, tone? ].
		 */
		return array_values( (array) apply_filters( 'entryvault_dashboard_widget_stats', $stats ) );
	}

	/** @return array<int,array<string,mixed>> The newest open entries the user may see. */
	public static function latest( int $n = 5 ): array {
		global $wpdb;
		[ $where, $p ] = self::scope();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds only placeholders.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title, email, source_plugin, read_at, created_at FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d", array_merge( [ $wpdb->prefix . 'entryvault_submissions' ], $p, [ $n ] ) ), ARRAY_A );
	}

	public static function render(): void {
		$stats  = self::stats();
		$latest = self::latest();
		?>
		<style>
			#entryvault_dashboard_widget .ev-dw-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 8px; margin: 0 0 12px; padding: 0; list-style: none; }
			#entryvault_dashboard_widget .ev-dw-stat a { display: block; padding: 8px 10px; border: 1px solid #dcdcde; border-radius: 6px; text-decoration: none; color: inherit; }
			#entryvault_dashboard_widget .ev-dw-stat a:hover, #entryvault_dashboard_widget .ev-dw-stat a:focus { border-color: #2271b1; }
			#entryvault_dashboard_widget .ev-dw-num { display: block; font-size: 22px; line-height: 1.2; font-weight: 600; font-variant-numeric: tabular-nums; }
			#entryvault_dashboard_widget .ev-dw-stat.is-bad .ev-dw-num { color: #b32d2e; }
			#entryvault_dashboard_widget .ev-dw-list { margin: 0 0 12px; padding: 0; list-style: none; }
			#entryvault_dashboard_widget .ev-dw-list li { display: flex; gap: 8px; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f1; min-width: 0; }
			#entryvault_dashboard_widget .ev-dw-list a { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
			#entryvault_dashboard_widget .ev-dw-list .is-unread a { font-weight: 600; }
			#entryvault_dashboard_widget .ev-dw-when { flex: none; color: #646970; }
			#entryvault_dashboard_widget .ev-dw-foot { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
		</style>
		<ul class="ev-dw-stats">
			<?php foreach ( $stats as $s ) : ?>
				<li class="ev-dw-stat<?php echo ! empty( $s['tone'] ) && (int) $s['count'] > 0 ? ' is-' . esc_attr( (string) $s['tone'] ) : ''; ?>">
					<a href="<?php echo esc_url( (string) $s['url'] ); ?>"><span class="ev-dw-num"><?php echo esc_html( number_format_i18n( (int) $s['count'] ) ); ?></span><?php echo esc_html( (string) $s['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $latest ) : ?>
			<h3><?php esc_html_e( 'Latest entries', 'entryvault' ); ?></h3>
			<ul class="ev-dw-list">
				<?php foreach ( $latest as $r ) :
					$title = '' !== trim( (string) $r['title'] ) ? (string) $r['title'] : ( '' !== (string) $r['email'] ? (string) $r['email'] : '#' . (int) $r['id'] );
					?>
					<li class="<?php echo empty( $r['read_at'] ) ? 'is-unread' : ''; ?>">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=entryvault&submission=' . (int) $r['id'] ) ); ?>"><?php echo esc_html( $title ); ?><?php if ( empty( $r['read_at'] ) ) : ?><span class="screen-reader-text"> <?php esc_html_e( '(unread)', 'entryvault' ); ?></span><?php endif; ?></a>
						<span class="ev-dw-when"><?php echo esc_html( Sources::label( (string) $r['source_plugin'] ) ); ?> · <?php echo esc_html( Dates::relative( (string) $r['created_at'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No open entries yet. New form submissions show up here.', 'entryvault' ); ?></p>
		<?php endif; ?>
		<p class="ev-dw-foot">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=entryvault' ) ); ?>"><?php esc_html_e( 'Open the board', 'entryvault' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=entryvault-submissions' ) ); ?>"><?php esc_html_e( 'Submissions', 'entryvault' ); ?></a>
		</p>
		<?php
	}
}
