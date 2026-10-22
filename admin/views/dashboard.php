<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only counts on the plugin's own tables for the overview.

use EntryVault\Admin_UI;

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to view this page.', 'entryvault' ) );
}

global $wpdb;
$t_sub   = $wpdb->prefix . 'entryvault_submissions';
$t_stage = $wpdb->prefix . 'entryvault_stages';
$t_tl    = $wpdb->prefix . 'entryvault_timeline';

// Stored datetimes are site-local (current_time( 'mysql' )), so compare with site-local strings.
$now       = (int) current_time( 'timestamp' );
$week_ago  = gmdate( 'Y-m-d H:i:s', $now - WEEK_IN_SECONDS );
$day_start = gmdate( 'Y-m-d 00:00:00', $now );
$stale_at  = gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS );

$ev_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t_sub ) );
$new_week = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $t_sub, $week_ago ) );
$new_day  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $t_sub, $day_start ) );

// "Open" = not archived and not sitting in a final stage (Won, Lost and the like).
$open_where = "s.status <> 'archived' AND COALESCE(st.is_terminal, 0) = 0";
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $open_where is a fixed SQL fragment with no input.
$open       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s LEFT JOIN %i st ON st.id = s.stage_id WHERE {$open_where}", $t_sub, $t_stage ) );
$unassigned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s LEFT JOIN %i st ON st.id = s.stage_id WHERE {$open_where} AND ( s.owner_user_id IS NULL OR s.owner_user_id = 0 )", $t_sub, $t_stage ) );
$waiting    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s LEFT JOIN %i st ON st.id = s.stage_id WHERE {$open_where} AND s.updated_at < %s", $t_sub, $t_stage, $stale_at ) );

// What needs attention: open entries with no owner, or untouched for 3+ days.
$attention = (array) $wpdb->get_results( $wpdb->prepare(
    "SELECT s.id, s.title, s.owner_user_id, s.updated_at, st.name AS stage_name
     FROM %i s LEFT JOIN %i st ON st.id = s.stage_id
     WHERE {$open_where} AND ( s.owner_user_id IS NULL OR s.owner_user_id = 0 OR s.updated_at < %s )
     ORDER BY s.updated_at ASC LIMIT 6",
    $t_sub,
    $t_stage,
    $stale_at
), ARRAY_A );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$won_week = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i s INNER JOIN %i st ON st.id = s.stage_id WHERE st.is_won = 1 AND s.status <> %s AND s.updated_at >= %s', $t_sub, $t_stage, 'archived', $week_ago ) );

// Recent activity across every entry.
$activity = (array) $wpdb->get_results( $wpdb->prepare(
    'SELECT t.id, t.submission_id, t.type, t.data, t.user_id, t.created_at, s.title
     FROM %i t INNER JOIN %i s ON s.id = t.submission_id
     ORDER BY t.created_at DESC, t.id DESC LIMIT 8',
    $t_tl,
    $t_sub
), ARRAY_A );

// Which form plugins are being listened to.
$ev_sources = [];
foreach ( \EntryVault\Adapter_Registry::instance()->all() as $slug => $adapter ) {
    $ev_sources[] = method_exists( $adapter, 'label' ) ? (string) $adapter->label() : (string) $slug;
}
sort( $ev_sources );

$board = static function ( int $id = 0 ): string {
    return Admin_UI::url( 'entryvault' ) . ( $id ? '&submission=' . $id : '' );
};
$entry_title = static function ( array $r, string $id_key = 'id' ): string {
    return '' !== (string) ( $r['title'] ?? '' ) ? (string) $r['title'] : sprintf( '#%d', (int) $r[ $id_key ] );
};

Admin_UI::open(
    __( 'Overview', 'entryvault' ),
    __( 'What came in, what is waiting on you, and how your pipeline is doing.', 'entryvault' ),
    '<a class="button" href="' . esc_url( Admin_UI::url( 'entryvault-submissions' ) ) . '">' . esc_html__( 'All submissions', 'entryvault' ) . '</a>'
    . '<a class="button button-primary" href="' . esc_url( $board() ) . '">' . esc_html__( 'Open the board', 'entryvault' ) . '</a>'
);
?>

<?php if ( 0 === $ev_total ) : ?>
    <div class="jnkui-callout">
        <p><strong><?php esc_html_e( 'Getting started', 'entryvault' ); ?></strong><br>
        <?php esc_html_e( 'EntryVault picks up submissions from the form plugins you already use. There is nothing to change on your forms. Submit one of them once and it appears on the board within moments.', 'entryvault' ); ?></p>
        <p class="ev-ui-callout-actions">
            <a class="button button-primary" href="<?php echo esc_url( $board() ); ?>"><?php esc_html_e( 'Open the board', 'entryvault' ); ?></a>
            <a class="button" href="<?php echo esc_url( Admin_UI::url( 'entryvault-import' ) ); ?>"><?php esc_html_e( 'Import a CSV instead', 'entryvault' ); ?></a>
        </p>
    </div>
<?php endif; ?>

<div class="jnkui-grid">
    <a class="jnkui-stat" href="<?php echo esc_url( Admin_UI::url( 'entryvault-submissions' ) ); ?>">
        <div class="jnkui-stat-label"><span class="jnkui-dot is-brand"></span><?php esc_html_e( 'New in the last 7 days', 'entryvault' ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( $new_week ) ); ?></div>
        <div class="jnkui-stat-foot"><?php
            /* translators: %s: number of submissions received today. */
            printf( esc_html__( '%s today', 'entryvault' ), esc_html( number_format_i18n( $new_day ) ) );
        ?></div>
    </a>
    <a class="jnkui-stat" href="<?php echo esc_url( $board() ); ?>">
        <div class="jnkui-stat-label"><span class="jnkui-dot is-ok"></span><?php esc_html_e( 'Open', 'entryvault' ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( $open ) ); ?></div>
        <div class="jnkui-stat-foot"><?php
            /* translators: %s: total number of submissions. */
            printf( esc_html__( 'Out of %s in total', 'entryvault' ), esc_html( number_format_i18n( $ev_total ) ) );
        ?></div>
    </a>
    <a class="jnkui-stat" href="<?php echo esc_url( add_query_arg( 'owner_id', '0', Admin_UI::url( 'entryvault-submissions' ) ) ); ?>">
        <div class="jnkui-stat-label"><span class="jnkui-dot <?php echo $unassigned ? 'is-warn' : 'is-ok'; ?>"></span><?php esc_html_e( 'Nobody assigned', 'entryvault' ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( $unassigned ) ); ?></div>
        <div class="jnkui-stat-foot"><?php echo $unassigned ? esc_html__( 'Open entries without an owner', 'entryvault' ) : esc_html__( 'Every open entry has an owner', 'entryvault' ); ?></div>
    </a>
    <div class="jnkui-stat">
        <div class="jnkui-stat-label"><span class="jnkui-dot <?php echo $waiting ? 'is-bad' : 'is-ok'; ?>"></span><?php esc_html_e( 'Waiting 3 days or more', 'entryvault' ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( $waiting ) ); ?></div>
        <div class="jnkui-stat-foot"><?php
            /* translators: %s: number of entries moved to a won stage this week. */
            printf( esc_html__( 'Open, and nothing changed lately. %s won in the last 7 days.', 'entryvault' ), esc_html( number_format_i18n( $won_week ) ) );
        ?></div>
    </div>
</div>

<div class="jnkui-grid-2">
    <div class="jnkui-card">
        <div class="jnkui-card-head">
            <div><h2><?php esc_html_e( 'Needs attention', 'entryvault' ); ?></h2>
            <p><?php esc_html_e( 'Open entries with no owner, or that nobody has touched for 3 days. Oldest first.', 'entryvault' ); ?></p></div>
        </div>
        <?php if ( ! $attention ) : ?>
            <div class="jnkui-card-body"><?php echo Admin_UI::empty_state( __( 'All caught up', 'entryvault' ), __( 'Every open entry has an owner and has been looked at recently.', 'entryvault' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
        <?php else : ?>
            <ul class="jnkui-list">
                <?php foreach ( $attention as $a ) : ?>
                    <li>
                        <span class="ev-ui-row-main">
                            <a href="<?php echo esc_url( $board( (int) $a['id'] ) ); ?>"><?php echo esc_html( $entry_title( $a ) ); ?></a>
                            <span class="jnkui-sub"><?php
                                echo esc_html( (string) ( $a['stage_name'] ?? '' ) ) . ' &middot; ';
                                /* translators: %s: relative time, e.g. "3 days ago". */
                                printf( esc_html__( 'last change %s', 'entryvault' ), esc_html( \EntryVault\Dates::relative( (string) $a['updated_at'] ) ) );
                            ?></span>
                        </span>
                        <?php if ( empty( $a['owner_user_id'] ) ) : ?>
                            <span class="jnkui-pill is-warn"><?php esc_html_e( 'No owner', 'entryvault' ); ?></span>
                        <?php else : ?>
                            <span class="jnkui-pill is-bad"><?php esc_html_e( 'Waiting', 'entryvault' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="jnkui-card">
        <div class="jnkui-card-head">
            <div><h2><?php esc_html_e( 'Recent activity', 'entryvault' ); ?></h2>
            <p><?php esc_html_e( 'The latest changes across all your entries.', 'entryvault' ); ?></p></div>
        </div>
        <?php if ( ! $activity ) : ?>
            <div class="jnkui-card-body"><?php echo Admin_UI::empty_state( __( 'No activity yet', 'entryvault' ), __( 'New submissions, stage moves, notes and replies show up here.', 'entryvault' ), '', 'tray' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
        <?php else : ?>
            <ul class="jnkui-list">
                <?php foreach ( $activity as $row ) :
                    $row['data'] = json_decode( (string) $row['data'], true );
                    $who         = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : false; ?>
                    <li>
                        <span class="ev-ui-row-main">
                            <a href="<?php echo esc_url( $board( (int) $row['submission_id'] ) ); ?>"><?php echo esc_html( $entry_title( $row, 'submission_id' ) ); ?></a>
                            <span class="jnkui-sub"><?php echo esc_html( wp_trim_words( \EntryVault\Timeline::summary( $row ), 14 ) ); ?></span>
                        </span>
                        <span class="ev-ui-when"><?php
                            echo esc_html( \EntryVault\Dates::relative( (string) $row['created_at'] ) );
                            if ( $who ) {
                                echo '<span class="jnkui-sub">' . esc_html( $who->display_name ) . '</span>';
                            }
                        ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><?php esc_html_e( 'Where entries come from', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'The form plugins EntryVault is listening to right now.', 'entryvault' ); ?></p></div>
        <a class="button button-small" href="<?php echo esc_url( Admin_UI::url( 'entryvault-health' ) ); ?>"><?php esc_html_e( 'Check health', 'entryvault' ); ?></a>
    </div>
    <div class="jnkui-card-body ev-ui-sources">
        <?php if ( $ev_sources ) : ?>
            <?php foreach ( $ev_sources as $src ) : ?>
                <span class="jnkui-pill is-ok"><?php echo esc_html( $src ); ?></span>
            <?php endforeach; ?>
        <?php else : ?>
            <span class="jnkui-pill is-warn"><?php esc_html_e( 'No form plugin found yet', 'entryvault' ); ?></span>
            <span class="jnkui-muted"><?php esc_html_e( 'Activate a form plugin, or import a CSV file.', 'entryvault' ); ?></span>
        <?php endif; ?>
    </div>
</div>

<h2 class="jnkui-section-title ev-ui-reports-title"><?php esc_html_e( 'Reports', 'entryvault' ); ?></h2>
<p class="jnkui-section-desc"><?php esc_html_e( 'Pick a pipeline and a date range. The numbers below update straight away.', 'entryvault' ); ?></p>

<div class="jnkui-card">
    <div class="jnkui-card-body ev-filters">
        <label class="jnkui-field">
            <span><?php esc_html_e( 'Pipeline', 'entryvault' ); ?></span>
            <select id="ev-pipeline"></select>
        </label>
        <label class="jnkui-field">
            <span><?php esc_html_e( 'From', 'entryvault' ); ?></span>
            <input type="date" id="ev-date-from" />
        </label>
        <label class="jnkui-field">
            <span><?php esc_html_e( 'To', 'entryvault' ); ?></span>
            <input type="date" id="ev-date-to" />
        </label>
        <button type="button" class="button" id="ev-refresh"><?php esc_html_e( 'Refresh', 'entryvault' ); ?></button>
    </div>
</div>

<div class="jnkui-grid" id="ev-cards"></div>

<div class="jnkui-grid-2">
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'By stage', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'How many entries sit in each stage.', 'entryvault' ); ?></p></div></div>
        <div class="jnkui-card-body" id="ev-stage-table"></div>
    </div>
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'Sources', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'Which form plugin each entry came from.', 'entryvault' ); ?></p></div></div>
        <div class="jnkui-card-body" id="ev-source-bars"></div>
    </div>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'Entries per day', 'entryvault' ); ?></h2>
    <p><?php esc_html_e( 'The last 30 days. Hover a bar for the date.', 'entryvault' ); ?></p></div></div>
    <div class="jnkui-card-body"><div id="ev-daily" class="ev-daily"></div></div>
</div>

<div class="jnkui-grid-2">
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'Funnel', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'How many entries reach each stage, and the share that move on.', 'entryvault' ); ?></p></div></div>
        <div class="jnkui-card-body" id="ev-funnel"></div>
    </div>
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'Time in each stage', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'Average hours an entry spends in a stage before it moves.', 'entryvault' ); ?></p></div></div>
        <div class="jnkui-card-body" id="ev-tis"></div>
    </div>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'By owner', 'entryvault' ); ?></h2>
    <p><?php esc_html_e( 'Click a column title to sort.', 'entryvault' ); ?></p></div></div>
    <div class="jnkui-card-body" id="ev-by-owner"></div>
</div>

<?php
Admin_UI::close();
// The report fetch/render lives in the enqueued admin/assets/js/dashboard.js
// (data via EntryVaultDashboardBoot).
