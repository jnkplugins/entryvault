<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * WP_List_Table for entryvault_submissions.
 */
class Submissions_List_Table extends \WP_List_Table {

    /** @var array<int,array<string,mixed>> */
    private array $pipelines = [];
    /** @var array<int,array<string,mixed>> */
    private array $stages = [];
    /** @var array<int,array<string,mixed>> */
    private array $tags = [];
    /** @var array<int,array<string,mixed>> */
    private array $owners = [];

    public function __construct() {
        parent::__construct( [
            'singular' => 'submission',
            'plural'   => 'submissions',
            'ajax'     => false,
        ] );
        global $wpdb;
        foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_pipelines", ARRAY_A ) as $p ) {
            $p['name']                         = Default_Names::label( $p['name'] ?? '' );
            $this->pipelines[ (int) $p['id'] ] = $p;
        }
        foreach ( (array) $wpdb->get_results( "SELECT id, name, pipeline_id FROM {$wpdb->prefix}entryvault_stages ORDER BY pipeline_id ASC, position ASC", ARRAY_A ) as $s ) {
            $s['name']                      = Default_Names::label( $s['name'] ?? '' );
            $this->stages[ (int) $s['id'] ] = $s;
        }
        foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_tags ORDER BY name ASC", ARRAY_A ) as $t ) {
            $this->tags[ (int) $t['id'] ] = $t;
        }
        if ( function_exists( 'get_users' ) ) {
            foreach ( Plugin::owner_users() as $u ) {
                $this->owners[ (int) $u->ID ] = [
                    'id'   => (int) $u->ID,
                    'name' => (string) ( $u->display_name ?? ( $u->user_login ?? ( '#' . $u->ID ) ) ),
                ];
            }
        }
    }

    public function get_columns(): array {
        $columns = [
            'cb'         => '<input type="checkbox" />',
            'starred'    => '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Starred', 'entryvault' ) . '</span>',
            'id'         => __( 'ID', 'entryvault' ),
            'title'      => __( 'Title', 'entryvault' ),
            'email'      => __( 'Email', 'entryvault' ),
            // Without a preview of what an enquiry actually says, every row has to
            // be opened one at a time to find out — workable with nine entries,
            // useless with two hundred.
            'preview'    => __( 'Message', 'entryvault' ),
            'pipeline'   => __( 'Pipeline', 'entryvault' ),
            'stage'      => __( 'Stage', 'entryvault' ),
            // Tagging and assigning worked, but neither was visible here and
            // neither could be filtered on, so you could tag at scale and then have
            // no way to find anything again.
            'tags'       => __( 'Tags', 'entryvault' ),
            'owner'      => __( 'Owner', 'entryvault' ),
            'source'     => __( 'Source', 'entryvault' ),
            'created_at' => __( 'Created', 'entryvault' ),
            'updated_at' => __( 'Updated', 'entryvault' ),
        ];

        // With a single pipeline (always the case on the free build) every row would
        // say the same thing, and the column costs width the message preview needs.
        if ( count( $this->pipelines ) <= 1 ) {
            unset( $columns['pipeline'] );
        }

        /**
         * Filter the submissions list columns.
         *
         * @param array<string,string> $columns Column key => heading.
         */
        return apply_filters( 'entryvault_submissions_list_columns', $columns );
    }

    public function get_sortable_columns(): array {
        return [
            'id'         => [ 'id', false ],
            'title'      => [ 'title', false ],
            'email'      => [ 'email', false ],
            'created_at' => [ 'created_at', true ],
            'updated_at' => [ 'updated_at', false ],
        ];
    }

    /** The Spam folder is on screen. */
    private function in_spam(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
        return isset( $_GET['status'] ) && Spam_Folder::STATUS === sanitize_key( wp_unslash( (string) $_GET['status'] ) );
    }

    protected function get_bulk_actions(): array {
        if ( $this->in_spam() ) {
            // Spam is only ever released or deleted; moving or tagging it would
            // leave it in the folder anyway.
            return [
                'bulk_not_spam' => __( 'Not spam', 'entryvault' ),
                'delete'        => __( 'Delete permanently', 'entryvault' ),
            ];
        }
        $actions = [ 'delete' => __( 'Delete', 'entryvault' ) ];
        foreach ( $this->stages as $id => $s ) {
            $pipeline_name = $this->pipelines[ (int) $s['pipeline_id'] ]['name'] ?? '';
            $label         = $pipeline_name !== ''
                /* translators: 1: pipeline name, 2: stage name. */
                ? sprintf( __( 'Move to: %1$s / %2$s', 'entryvault' ), $pipeline_name, $s['name'] )
                /* translators: %s: stage name. */
                : sprintf( __( 'Move to: %s', 'entryvault' ), $s['name'] );
            $actions[ 'move-to-stage-' . (int) $id ] = $label;
        }
        $actions['bulk_mark_read']    = __( 'Mark as read', 'entryvault' );
        $actions['bulk_mark_unread']  = __( 'Mark as unread', 'entryvault' );
        $actions['bulk_star']         = __( 'Star', 'entryvault' );
        $actions['bulk_unstar']       = __( 'Remove star', 'entryvault' );
        $actions['bulk_assign_owner'] = __( 'Assign owner', 'entryvault' );
        $actions['bulk_add_tag']      = __( 'Add tag', 'entryvault' );
        $actions['bulk_archive']      = __( 'Archive', 'entryvault' );
        $actions['bulk_unarchive']    = __( 'Unarchive', 'entryvault' );
        $actions['bulk_spam']         = __( 'Move to spam', 'entryvault' );

        /**
         * Filter the bulk actions offered on the submissions list.
         *
         * @param array<string,string> $actions Action key => label.
         */
        return apply_filters( 'entryvault_submissions_bulk_actions', $actions );
    }

    /**
     * Render the owner/tag dropdowns next to the bulk-actions form, plus
     * a tiny inline script that toggles them based on the selected action.
     */
    protected function extra_tablenav( $which ): void {
        if ( $which !== 'top' ) {
            return;
        }
        if ( $this->in_spam() ) {
            $run = Spam_Folder::release_option_label();
            ?>
            <div class="alignleft actions entryvault-spam-extras">
                <?php if ( '' !== $run ) : ?>
                    <label><input type="checkbox" name="ev_spam_run" value="1"> <?php echo esc_html( $run ); ?></label>
                <?php endif; ?>
                <?php if ( $this->has_items() ) : ?>
                    <a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=entryvault_empty_spam' ), 'entryvault_empty_spam' ) ); ?>" onclick="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete every entry in the Spam folder for good? This cannot be undone.', 'entryvault' ) ) ); ?>);"><?php esc_html_e( 'Empty spam', 'entryvault' ); ?></a>
                <?php endif; ?>
            </div>
            <?php
            return;
        }
        ?>
        <div class="alignleft actions entryvault-bulk-extras">
            <label class="entryvault-bulk-owner" style="display:none;">
                <span class="screen-reader-text"><?php esc_html_e( 'Owner', 'entryvault' ); ?></span>
                <select name="bulk_owner_id">
                    <option value="0"><?php esc_html_e( '(unassign)', 'entryvault' ); ?></option>
                    <?php foreach ( $this->owners as $o ) : ?>
                        <option value="<?php echo esc_attr( (int) $o['id'] ); ?>">
                            <?php echo esc_html( $o['name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="entryvault-bulk-tag" style="display:none;">
                <span class="screen-reader-text"><?php esc_html_e( 'Tag', 'entryvault' ); ?></span>
                <select name="bulk_tag_id">
                    <option value="0"><?php esc_html_e( '— Select tag —', 'entryvault' ); ?></option>
                    <?php foreach ( $this->tags as $t ) : ?>
                        <option value="<?php echo esc_attr( (int) $t['id'] ); ?>">
                            <?php echo esc_html( (string) ( $t['name'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <?php
        // The reveal-on-bulk-action behaviour lives in the enqueued
        // admin/assets/js/submissions.js (enqueued on the Submissions screen).
    }

    public function prepare_items(): void {
        $args = Submissions_Admin::args_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.

        $hidden = ( $this->screen instanceof \WP_Screen ) ? (array) get_hidden_columns( $this->screen ) : [];
        $this->_column_headers = [ $this->get_columns(), $hidden, $this->get_sortable_columns() ];

        $result = Submission_Store::query( $args );

        $this->items = $result['rows'];
        $this->set_pagination_args( [
            'total_items' => $result['total'],
            'per_page'    => $args['per_page'],
            'total_pages' => (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ),
        ] );
    }

    protected function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="submission[]" value="%d" />', (int) $item['id'] );
    }

    /** Unread rows carry a class, so the screen can show them in bold. */
    public function single_row( $item ) {
        $unread = empty( $item['read_at'] );
        echo '<tr' . ( $unread ? ' class="ev-unread"' : '' ) . '>';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    protected function column_starred( $item ): string {
        $on    = ! empty( $item['is_starred'] );
        $title = (string) ( $item['title'] ?? '' );
        return sprintf(
            '<button type="button" class="ev-star-toggle%1$s" data-id="%2$d" aria-pressed="%3$s" aria-label="%4$s" title="%5$s"><span class="dashicons %6$s" aria-hidden="true"></span></button>',
            $on ? ' is-on' : '',
            (int) $item['id'],
            $on ? 'true' : 'false',
            /* translators: %s: submission title. */
            esc_attr( sprintf( __( 'Star %s', 'entryvault' ), '' !== $title ? $title : '#' . (int) $item['id'] ) ),
            esc_attr__( 'Star', 'entryvault' ),
            $on ? 'dashicons-star-filled' : 'dashicons-star-empty'
        );
    }

    protected function column_id( $item ): string {
        return (string) (int) $item['id'];
    }

    protected function column_title( $item ): string {
        $title = $item['title'] !== null && $item['title'] !== '' ? $item['title'] : '(untitled)';
        $url   = admin_url( 'admin.php?page=entryvault&submission=' . (int) $item['id'] );
        $dot   = empty( $item['read_at'] )
            ? '<span class="ev-unread-dot" title="' . esc_attr__( 'Unread', 'entryvault' ) . '"></span><span class="screen-reader-text">' . esc_html__( 'Unread', 'entryvault' ) . ' </span>'
            : '';
        $html = sprintf(
            '%s<strong><a href="%s">%s</a></strong>',
            $dot,
            esc_url( $url ),
            esc_html( $title )
        );
        if ( Spam_Folder::STATUS === (string) ( $item['status'] ?? '' ) ) {
            $html .= $this->row_actions( [
                'not_spam' => sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=entryvault_not_spam&id=' . (int) $item['id'] ), 'entryvault_not_spam_' . (int) $item['id'] ) ),
                    esc_html__( 'Not spam', 'entryvault' )
                ),
            ] );
        }
        return $html;
    }

    protected function column_email( $item ): string {
        $email = (string) ( $item['email'] ?? '' );
        // The cell is cut with an ellipsis in submissions.css; the title keeps the whole address.
        return '<span title="' . esc_attr( $email ) . '">' . esc_html( $email ) . '</span>';
    }

    protected function column_pipeline( $item ): string {
        $pid = (int) ( $item['pipeline_id'] ?? 0 );
        return esc_html( $this->pipelines[ $pid ]['name'] ?? ( '#' . $pid ) );
    }

    protected function column_stage( $item ): string {
        $sid = (int) ( $item['stage_id'] ?? 0 );
        return esc_html( $this->stages[ $sid ]['name'] ?? ( '#' . $sid ) );
    }

    /** A short preview of the submitted content, so the list is scannable. */
    protected function column_preview( $item ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'entryvault_submission_data';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- per-row preview on an admin list; page size is capped.
        // Longest value first, but read as text: a file upload's JSON (with its
        // server path) is usually the longest string and used to win.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT value, field_type FROM {$table}
             WHERE submission_id = %d AND is_stale = 0 AND value <> ''
             ORDER BY CHAR_LENGTH(value) DESC
             LIMIT 5",
            (int) $item['id']
        ), ARRAY_A );

        $text = '';
        $file = '';
        foreach ( (array) $rows as $row ) {
            // A file name says less than the message: use it only when nothing else is there.
            if ( File_Links::is_file_type( (string) ( $row['field_type'] ?? '' ) ) ) {
                $file = '' !== $file ? $file : trim( Field_Values::display( (string) $row['value'], (string) $row['field_type'] ) );
                continue;
            }
            $candidate = trim( Field_Values::display( (string) $row['value'], (string) ( $row['field_type'] ?? '' ) ) );
            if ( strlen( $candidate ) > strlen( $text ) ) {
                $text = $candidate;
            }
        }
        if ( '' === $text ) {
            $text = $file;
        }
        if ( '' === $text ) {
            return '<span aria-hidden="true">—</span>';
        }
        return '<span title="' . esc_attr( $text ) . '">' . esc_html( wp_trim_words( $text, 12, '…' ) ) . '</span>';
    }

    protected function column_tags( $item ): string {
        global $wpdb;
        $st = $wpdb->prefix . 'entryvault_submission_tags';
        $t  = $wpdb->prefix . 'entryvault_tags';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- per-row lookup on an admin list; page size is capped.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.name, t.color FROM {$st} st INNER JOIN {$t} t ON t.id = st.tag_id
             WHERE st.submission_id = %d ORDER BY t.name ASC",
            (int) $item['id']
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return '<span aria-hidden="true">—</span>';
        }

        $out = [];
        foreach ( $rows as $row ) {
            // Same soft pill as the board (.ev-tag tints the tag's own colour), so a
            // tag looks the same everywhere. Tag colours are user-chosen: keep only a
            // real hex.
            $color = sanitize_hex_color( (string) $row['color'] ) ?: '#64748b';
            $out[] = sprintf(
                '<span class="ev-tag" style="--ev-tag:%s">%s</span>',
                esc_attr( $color ),
                esc_html( (string) $row['name'] )
            );
        }
        return implode( ' ', $out );
    }

    protected function column_owner( $item ): string {
        $uid = (int) ( $item['owner_user_id'] ?? 0 );
        if ( $uid <= 0 ) {
            return '<span aria-hidden="true">—</span>';
        }
        if ( isset( $this->owners[ $uid ] ) ) {
            return esc_html( (string) $this->owners[ $uid ]['name'] );
        }
        // Someone who owns leads but is no longer in the owner list (role changed).
        $user = get_userdata( $uid );
        return esc_html( $user ? (string) $user->display_name : '#' . $uid );
    }

    protected function column_source( $item ): string {
        return esc_html( Sources::label( (string) ( $item['source_plugin'] ?? '' ) ) );
    }

    protected function column_created_at( $item ): string {
        return Dates::relative_cell( $item['created_at'] ?? '' );
    }

    protected function column_updated_at( $item ): string {
        return Dates::relative_cell( $item['updated_at'] ?? '' );
    }

    protected function column_default( $item, $column_name ): string {
        /**
         * Filter the rendered HTML of a column the core table does not know.
         * Callbacks are responsible for escaping.
         *
         * @param string|null $html        Null lets the default (escaped raw value) render.
         * @param string      $column_name Column key.
         * @param array       $item        Submission row.
         */
        $html = apply_filters( 'entryvault_submissions_list_column', null, $column_name, $item );
        if ( is_string( $html ) ) {
            return $html;
        }
        return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
    }

    public function no_items(): void {
        // Distinguish "your filters matched nothing" from "nothing has arrived yet",
        // and in the second case name the next step instead of ending the road here.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters from GET; no state change.
        // These must be the parameter names the filter form actually submits
        // (see Submissions_Admin::args_from_request). The old list checked
        // "pipeline" and "stage", which the form never sends, so a stage or date
        // filter with no matches told the user nothing had ever arrived.
        $ev_filtered = false;
        foreach ( [ 's', 'pipeline_id', 'stage_id', 'source', 'date_from', 'date_to', 'tag_id', 'owner_id' ] as $ev_key ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters from GET; no state change.
            if ( isset( $_GET[ $ev_key ] ) && '' !== (string) $_GET[ $ev_key ] && '0' !== (string) $_GET[ $ev_key ] ) {
                $ev_filtered = true;
                break;
            }
        }
        // "Unassigned" is owner_id=0, a real filter.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters from GET; no state change.
        if ( isset( $_GET['owner_id'] ) && '0' === (string) $_GET['owner_id'] ) {
            $ev_filtered = true;
        }

        if ( $ev_filtered ) {
            esc_html_e( 'No submissions match these filters.', 'entryvault' );
            return;
        }

        echo '<strong>' . esc_html__( 'No submissions yet.', 'entryvault' ) . '</strong><br>';
        printf(
            /* translators: 1: opening link tag to the import screen, 2: closing link tag. */
            esc_html__( 'Submissions from every supported form plugin land here automatically. You can also %1$simport a CSV%2$s to bring existing ones in.', 'entryvault' ),
            '<a href="' . esc_url( admin_url( 'admin.php?page=entryvault-import' ) ) . '">',
            '</a>'
        );
    }
}
