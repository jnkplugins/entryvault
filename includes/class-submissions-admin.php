<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * Admin "Submissions" list view: WP_List_Table, bulk actions, filters,
 * and CSV export handler.
 */
class Submissions_Admin {

    public const CAPABILITY = 'manage_options';

    /**
     * UTF-8 byte order mark written at the start of every CSV export. Without
     * it Excel on Windows reads the file as ANSI and Korean, Japanese or emoji
     * cells turn into mojibake. The CSV importer strips it again on the way in.
     */
    public const CSV_BOM = "\xEF\xBB\xBF";

    /**
     * Hook the admin-post handler. Safe to call from a view file.
     */
    public static function register(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;
        add_action( 'admin_post_entryvault_export_submissions', [ __CLASS__, 'handle_export' ] );
        // Process bulk actions on admin_init — before any output — so the post-action
        // wp_safe_redirect() (and its success notice) work. Running it from render()
        // happened after the admin header was already sent ("headers already sent").
        add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
    }

    /**
     * Read filter args from $_GET and sanitize them.
     *
     * @return array<string,mixed>
     */
    public static function args_from_request( array $request ): array {
        $status_in   = isset( $request['status'] ) ? sanitize_key( wp_unslash( (string) $request['status'] ) ) : '';
        $status_safe = in_array( $status_in, [ 'archived', 'open', 'closed', 'any', 'all' ], true ) ? $status_in : '';

        $args = [
            'pipeline_id' => isset( $request['pipeline_id'] ) ? (int) $request['pipeline_id'] : 0,
            'stage_id'    => isset( $request['stage_id'] ) ? (int) $request['stage_id'] : 0,
            // sanitize_text_field, NOT sanitize_key: the value must match a stored
            // source_plugin string verbatim, and sanitize_key lowercases and strips
            // spaces — so "Gravity Forms" became "gravityforms" and matched nothing.
            // Every source except the already-slug-like ones was unfilterable. The
            // query binds this as %s (see Submission_Store::build_where) and caps it
            // at 40 chars, so the sanitiser is not what makes it safe.
            'source'      => isset( $request['source'] ) ? sanitize_text_field( wp_unslash( (string) $request['source'] ) ) : '',
            'search'      => isset( $request['s'] ) ? sanitize_text_field( wp_unslash( (string) $request['s'] ) ) : '',
            'date_from'   => isset( $request['date_from'] ) ? self::sanitize_date( (string) $request['date_from'] ) : '',
            'date_to'     => isset( $request['date_to'] ) ? self::sanitize_date( (string) $request['date_to'] ) : '',
            'paged'       => isset( $request['paged'] ) ? max( 1, (int) $request['paged'] ) : 1,
            'per_page'    => 20,
            'orderby'     => isset( $request['orderby'] ) ? sanitize_key( (string) $request['orderby'] ) : 'created_at',
            'order'       => isset( $request['order'] ) && strtoupper( (string) $request['order'] ) === 'ASC' ? 'ASC' : 'DESC',
            'status'      => $status_safe,
            // Tag and owner filters, so bulk-tagging and assigning lead somewhere.
            'tag_id'      => isset( $request['tag_id'] ) ? (int) $request['tag_id'] : 0,
            // '' means "any"; '0' specifically means unassigned, which is a real
            // thing to want to look at, so it cannot collapse into the same value.
            'owner_id'    => isset( $request['owner_id'] ) && '' !== $request['owner_id']
                ? (string) (int) $request['owner_id']
                : '',
        ];

        // Per-pipeline scoping: managers see everything; otherwise restrict
        // to the pipelines on the user's `entryvault_pipeline_access` meta.
        if ( ! Capabilities::current_user_can_manage() ) {
            $args['viewable_pipelines'] = Capabilities::viewable_pipeline_ids_for_current_user();
        }

        /**
         * Filter the query args built from the submissions list request.
         *
         * @param array $args    Args handed to Submission_Store::query().
         * @param array $request Raw request array (already unslashed values are NOT applied here).
         */
        return apply_filters( 'entryvault_submissions_list_args', $args, $request );
    }

    /**
     * Export link carrying every filter the list is showing.
     *
     * It used to copy a hand-picked subset, and the tag and owner filters were
     * missing, so a filtered export quietly contained every lead. Now it carries
     * all list parameters except the screen's own plumbing; handle_export() runs
     * them through args_from_request() again, so nothing unsanitised is trusted.
     *
     * @param array<string,mixed> $request Usually $_GET.
     */
    public static function export_url( array $request ): string {
        $skip = [ 'page', 'paged', 'action', 'action2', 'filter_action', '_wpnonce', '_wp_http_referer', 'entryvault_notice', 'ev_notice', 'orderby', 'order' ];
        $args = [];
        foreach ( $request as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || in_array( $key, $skip, true ) || ! is_scalar( $value ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( (string) $value ) );
            // "0" is a real filter (Unassigned owner); only empty means "any".
            if ( '' === $value ) {
                continue;
            }
            $args[ $key ] = $value;
        }
        $args['action'] = 'entryvault_export_submissions';
        return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'entryvault_export_submissions' );
    }

    /** Transient key for the one-shot notice after a bulk action. */
    private static function notice_key( int $user_id ): string {
        return 'entryvault_bulk_notice_' . $user_id;
    }

    /**
     * The notice left by this user's last bulk action, shown once.
     *
     * The message used to travel in the URL and the screen printed whatever the
     * URL said, so a crafted link could put any wording into an EntryVault notice.
     */
    public static function take_notice(): string {
        $user_id = get_current_user_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only; the text comes from the server.
        if ( $user_id <= 0 || empty( $_GET['ev_notice'] ) ) {
            return '';
        }
        $key    = self::notice_key( $user_id );
        $notice = (string) get_transient( $key );
        delete_transient( $key );
        return $notice;
    }

    private static function sanitize_date( string $date ): string {
        $date = trim( $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }

    /** Columns hidden until the user turns them on under Screen Options. */
    const DEFAULT_HIDDEN_COLUMNS = [ 'id', 'pipeline', 'owner', 'updated_at' ];

    /**
     * Runs on load-<page>, before the admin header prints, which is the only point
     * where Screen Options can still learn about the table's columns.
     *
     * Twelve columns do not fit a laptop-width admin: every cell wrapped to one word
     * per line and a row grew to 300px. The less-used columns start hidden instead,
     * and each one is a checkbox away.
     */
    public static function setup_screen(): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once ENTRYVAULT_PATH . 'includes/class-submissions-list-table.php';

        $columns = null;
        add_filter( 'manage_' . $screen->id . '_columns', static function () use ( &$columns ) {
            if ( null === $columns ) {
                $columns = ( new Submissions_List_Table() )->get_columns();
            }
            return $columns;
        } );
        add_filter( 'default_hidden_columns', static function ( $hidden, $for_screen ) use ( $screen ) {
            if ( $for_screen instanceof \WP_Screen && $for_screen->id === $screen->id ) {
                return array_values( array_unique( array_merge( (array) $hidden, self::DEFAULT_HIDDEN_COLUMNS ) ) );
            }
            return $hidden;
        }, 10, 2 );
    }

    /**
     * Render the admin page. Capability is enforced before output.
     */
    public static function render(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'entryvault' ) );
        }

        // Bulk actions are processed earlier on admin_init (see register()).

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once ENTRYVAULT_PATH . 'includes/class-submissions-list-table.php';

        $table = new Submissions_List_Table();
        $table->prepare_items();

        global $wpdb;
        $pipelines = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_pipelines ORDER BY name ASC", ARRAY_A );
        $stages    = $wpdb->get_results( "SELECT id, name, pipeline_id FROM {$wpdb->prefix}entryvault_stages ORDER BY pipeline_id ASC, position ASC, name ASC", ARRAY_A );
        $sources   = $wpdb->get_results( "SELECT DISTINCT source_plugin FROM {$wpdb->prefix}entryvault_submissions ORDER BY source_plugin ASC", ARRAY_A );

        // Restrict the pipeline filter dropdown to pipelines the user may view.
        if ( ! Capabilities::current_user_can_manage() ) {
            $allowed = Capabilities::viewable_pipeline_ids_for_current_user();
            $pipelines = array_values( array_filter(
                (array) $pipelines,
                static fn( $p ) => in_array( (int) $p['id'], $allowed, true )
            ) );
            $stages = array_values( array_filter(
                (array) $stages,
                static fn( $s ) => in_array( (int) $s['pipeline_id'], $allowed, true )
            ) );
        }

        $args = self::args_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.

        $export_url = self::export_url( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters from GET; the export link itself is nonced.

        ?>
        <?php
        Admin_UI::open(
            __( 'Submissions', 'entryvault' ),
            __( 'Every entry in one list. Filter it, tick rows to change many at once, or export what you see to a spreadsheet.', 'entryvault' ),
            '<a href="' . esc_url( $export_url ) . '" class="button">' . esc_html__( 'Export CSV', 'entryvault' ) . '</a>'
            . '<a href="' . esc_url( Admin_UI::url( 'entryvault' ) ) . '" class="button button-primary">' . esc_html__( 'Open the board', 'entryvault' ) . '</a>'
        );
        ?>

            <?php
            $current_status = (string) $args['status'];
            $base_url       = admin_url( 'admin.php?page=entryvault-submissions' );
            $all_url        = remove_query_arg( 'status', $base_url );
            $archived_url   = add_query_arg( 'status', 'archived', $base_url );
            $is_archived    = ( $current_status === 'archived' );
            ?>
            <ul class="subsubsub">
                <li class="all">
                    <a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo $is_archived ? '' : 'current'; ?>">
                        <?php esc_html_e( 'All', 'entryvault' ); ?>
                    </a> |
                </li>
                <li class="archived">
                    <a href="<?php echo esc_url( $archived_url ); ?>" class="<?php echo $is_archived ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Archived', 'entryvault' ); ?>
                    </a>
                </li>
            </ul>
            <br class="clear">

            <?php $ev_notice = self::take_notice(); ?>
            <?php if ( '' !== $ev_notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $ev_notice ); ?></p></div>
            <?php endif; ?>

            <form method="get" class="jnkui-card ev-ui-filters">
                <input type="hidden" name="page" value="entryvault-submissions">
                <?php if ( $args['status'] !== '' ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $args['status'] ); ?>">
                <?php endif; ?>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="pipeline_id">
                            <option value=""><?php esc_html_e( 'All pipelines', 'entryvault' ); ?></option>
                            <?php foreach ( $pipelines as $p ) : ?>
                                <option value="<?php echo esc_attr( (int) $p['id'] ); ?>" <?php selected( $args['pipeline_id'], (int) $p['id'] ); ?>>
                                    <?php echo esc_html( $p['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="stage_id">
                            <option value=""><?php esc_html_e( 'All stages', 'entryvault' ); ?></option>
                            <?php
                            // Grouped by pipeline: every pipeline starts with New,
                            // Contacted, … so a flat list repeated the same names.
                            $ev_pipeline_names = wp_list_pluck( (array) $pipelines, 'name', 'id' );
                            $ev_group          = null;
                            foreach ( $stages as $s ) :
                                $ev_pid = (int) $s['pipeline_id'];
                                if ( $ev_pid !== $ev_group ) :
                                    if ( null !== $ev_group ) {
                                        echo '</optgroup>';
                                    }
                                    $ev_group = $ev_pid;
                                    printf( '<optgroup label="%s">', esc_attr( (string) ( $ev_pipeline_names[ $ev_pid ] ?? '#' . $ev_pid ) ) );
                                endif;
                                ?>
                                <option value="<?php echo esc_attr( (int) $s['id'] ); ?>" <?php selected( $args['stage_id'], (int) $s['id'] ); ?>>
                                    <?php echo esc_html( $s['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ( null !== $ev_group ) { echo '</optgroup>'; } ?>
                        </select>
                        <select name="source">
                            <option value=""><?php esc_html_e( 'All sources', 'entryvault' ); ?></option>
                            <?php foreach ( $sources as $src ) : ?>
                                <?php $key = (string) $src['source_plugin']; ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['source'], $key ); ?>>
                                    <?php echo esc_html( Sources::label( $key ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php
                        // Bulk tagging and assigning both worked, but there was no
                        // way to filter by either afterwards — so you could tag two
                        // hundred leads and then not be able to list them.
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small lookup for a filter dropdown.
                        $ev_tag_rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_tags ORDER BY name ASC", ARRAY_A );
                        $ev_owners   = Plugin::owner_users();
                        ?>
                        <?php if ( ! empty( $ev_tag_rows ) ) : ?>
                            <select name="tag_id">
                                <option value=""><?php esc_html_e( 'All tags', 'entryvault' ); ?></option>
                                <?php foreach ( $ev_tag_rows as $ev_tag ) : ?>
                                    <option value="<?php echo esc_attr( (int) $ev_tag['id'] ); ?>" <?php selected( $args['tag_id'] ?? 0, (int) $ev_tag['id'] ); ?>>
                                        <?php echo esc_html( (string) $ev_tag['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <select name="owner_id">
                            <option value=""><?php esc_html_e( 'All owners', 'entryvault' ); ?></option>
                            <option value="0" <?php selected( (string) ( $args['owner_id'] ?? '' ), '0' ); ?>>
                                <?php esc_html_e( 'Unassigned', 'entryvault' ); ?>
                            </option>
                            <?php foreach ( (array) $ev_owners as $ev_owner ) : ?>
                                <option value="<?php echo esc_attr( (int) $ev_owner->ID ); ?>" <?php selected( (string) ( $args['owner_id'] ?? '' ), (string) $ev_owner->ID ); ?>>
                                    <?php echo esc_html( (string) $ev_owner->display_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php
                        /**
                         * Fires inside the submissions list filter bar, after the core dropdowns.
                         *
                         * @param array $args Current list args.
                         */
                        do_action( 'entryvault_submissions_list_filters', $args );
                        ?>
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e( 'From', 'entryvault' ); ?></span>
                            <input type="date" name="date_from" value="<?php echo esc_attr( $args['date_from'] ); ?>">
                        </label>
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e( 'To', 'entryvault' ); ?></span>
                            <input type="date" name="date_to" value="<?php echo esc_attr( $args['date_to'] ); ?>">
                        </label>
                        <?php submit_button( __( 'Filter', 'entryvault' ), '', 'filter_action', false ); ?>
                    </div>
                </div>
            </form>

            <form method="post">
                <?php
                wp_nonce_field( 'entryvault_submissions_bulk', 'entryvault_bulk_nonce' );
                $table->search_box( __( 'Search', 'entryvault' ), 'entryvault-submission' );
                $table->display();
                ?>
            </form>
            <?php
            if ( ! defined( 'ENTRYVAULT_PRO' ) ) :
                // One restrained line at the point of intent: someone working through
                // submissions by hand is exactly who wants to know the rules engine
                // exists. Nothing padlocked, nothing disabled.
                ?>
                <p class="description" style="margin-top:1em">
                    <?php
                    printf(
                        /* translators: 1: opening link tag, 2: closing link tag. */
                        esc_html__( 'Tagging, assigning and replying to these by hand? %1$sSee what Pro automates%2$s.', 'entryvault' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=entryvault-upgrade&src=entries-empty' ) ) . '">',
                        '</a>'
                    );
                    ?>
                </p>
                <?php
            endif;
            Admin_UI::close();
    }

    /**
     * Process bulk actions posted from the list table form. Hooked on admin_init
     * (registered in register()); no-ops unless the bulk nonce is present.
     */
    public static function handle_actions(): void {
        if ( empty( $_POST['entryvault_bulk_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['entryvault_bulk_nonce'] ) ), 'entryvault_submissions_bulk' ) ) {
            return;
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $action = '-1';
        foreach ( [ 'action', 'action2' ] as $field ) {
            if ( isset( $_POST[ $field ] ) && $_POST[ $field ] !== '-1' && $_POST[ $field ] !== '' ) {
                $action = sanitize_key( wp_unslash( (string) $_POST[ $field ] ) );
                break;
            }
        }
        $ids = isset( $_POST['submission'] ) && is_array( $_POST['submission'] )
            ? array_values( array_filter( array_map( 'intval', wp_unslash( $_POST['submission'] ) ) ) )
            : [];

        if ( empty( $ids ) || $action === '-1' ) {
            return;
        }

        $notice = '';
        if ( $action === 'delete' ) {
            $deleted = 0;
            foreach ( $ids as $id ) {
                if ( Submission_Store::delete( (int) $id ) ) {
                    $deleted++;
                }
            }
            $notice = sprintf(
                /* translators: %d: count */
                _n( 'Deleted %d submission.', 'Deleted %d submissions.', $deleted, 'entryvault' ),
                $deleted
            );
        } elseif ( strpos( $action, 'move-to-stage-' ) === 0 ) {
            $stage_id = (int) substr( $action, strlen( 'move-to-stage-' ) );
            $res      = Submission_Store::bulk_move( $ids, $stage_id, get_current_user_id() );
            $notice   = sprintf(
                /* translators: 1: moved 2: skipped */
                __( 'Moved %1$d submission(s); %2$d skipped.', 'entryvault' ),
                $res['moved'],
                $res['skipped']
            );
        } elseif ( $action === 'bulk_assign_owner' ) {
            $owner_id = isset( $_POST['bulk_owner_id'] ) ? (int) $_POST['bulk_owner_id'] : 0;
            $count    = Submission_Store::bulk_assign_owner( $ids, $owner_id ?: null, get_current_user_id() );
            $notice   = $owner_id > 0
                ? sprintf(
                    /* translators: %d: count */
                    _n( 'Assigned owner on %d submission.', 'Assigned owner on %d submissions.', $count, 'entryvault' ),
                    $count
                )
                : sprintf(
                    /* translators: %d: count */
                    _n( 'Cleared owner on %d submission.', 'Cleared owner on %d submissions.', $count, 'entryvault' ),
                    $count
                );
        } elseif ( $action === 'bulk_add_tag' ) {
            $tag_id = isset( $_POST['bulk_tag_id'] ) ? (int) $_POST['bulk_tag_id'] : 0;
            $count  = Submission_Store::bulk_add_tag( $ids, $tag_id, get_current_user_id() );
            $notice = sprintf(
                /* translators: %d: count */
                _n( 'Tagged %d submission.', 'Tagged %d submissions.', $count, 'entryvault' ),
                $count
            );
        } elseif ( $action === 'bulk_archive' ) {
            $count  = Submission_Store::bulk_archive( $ids, get_current_user_id() );
            $notice = sprintf(
                /* translators: %d: count */
                _n( 'Archived %d submission.', 'Archived %d submissions.', $count, 'entryvault' ),
                $count
            );
        } elseif ( $action === 'bulk_unarchive' ) {
            $count  = Submission_Store::bulk_unarchive( $ids, get_current_user_id() );
            $notice = sprintf(
                /* translators: %d: count */
                _n( 'Restored %d submission.', 'Restored %d submissions.', $count, 'entryvault' ),
                $count
            );
        } else {
            /**
             * Filter the notice for a bulk action the core screen does not handle.
             * Returning a non-empty string means the action was handled.
             *
             * @param string $notice  Empty string.
             * @param string $action  Sanitised action key.
             * @param int[]  $ids     Selected submission IDs.
             */
            $notice = (string) apply_filters( 'entryvault_submissions_bulk_action', '', $action, $ids );
        }

        if ( $notice !== '' ) {
            set_transient( self::notice_key( get_current_user_id() ), $notice, 5 * MINUTE_IN_SECONDS );
            $back     = remove_query_arg( [ 'entryvault_notice', 'ev_notice' ], wp_get_referer() ?: admin_url( 'admin.php?page=entryvault-submissions' ) );
            $redirect = add_query_arg( 'ev_notice', '1', $back );
            wp_safe_redirect( $redirect );
            exit;
        }
    }

    /**
     * admin-post.php handler that streams a CSV export of the filtered query.
     */
    public static function handle_export(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden.', 'entryvault' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'entryvault_export_submissions' );

        $args = self::args_from_request( $_GET );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="entryvault-submissions-' . gmdate( 'Ymd-His' ) . '.csv"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV to php://output; WP_Filesystem has no streaming API.
        $out = fopen( 'php://output', 'w' );
        self::write_csv( $out, $args );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV to php://output; WP_Filesystem has no streaming API.
        fclose( $out );
        exit;
    }

    /**
     * Write the export (header + every matching row, page by page) to $out.
     *
     * @param resource $out
     * @return int Rows written.
     */
    public static function write_csv( $out, array $args ): int {
        // query() returns at most MAX_PER_PAGE rows; asking for 1000 got 200 and
        // the loop thought it had them all, so the file stopped at 200 rows.
        $args['per_page'] = Submission_Store::MAX_PER_PAGE;
        $args['paged']    = 1;
        $written          = 0;

        // The export used to carry only the envelope — ids, timestamps and numeric
        // pipeline/stage references — and none of what people had actually
        // submitted. Exporting form entries without the entries made the file
        // useless for the thing it exists for: handing leads to someone else.
        // Resolve names, and append one column per submitted field.
        $field_labels = self::exportable_field_labels( $args );
        $names        = self::pipeline_stage_names();

        // Pass an explicit $escape ('' = no escape char, matching WP core) — the
        // 4-arg/implicit form is deprecated on PHP 8.4 and removed in PHP 9.
        $header = [ 'ID', 'Title', 'Email', 'Pipeline', 'Stage', 'Owner', 'Tags', 'Source', 'Source Form', 'Source Entry', 'Status', 'Created', 'Updated' ];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming CSV; WP_Filesystem has no streaming API.
        fwrite( $out, self::CSV_BOM );
        fputcsv( $out, array_merge( $header, self::field_headers( $header, $field_labels ) ), ',', '"', '' );

        do {
            $page = Submission_Store::query( $args );
            foreach ( $page['rows'] as $row ) {
                $id     = (int) ( $row['id'] ?? 0 );
                $fields = self::field_values_by_label( $id );

                $line = [
                    $row['id']              ?? '',
                    $row['title']           ?? '',
                    $row['email']           ?? '',
                    $names['pipelines'][ (int) ( $row['pipeline_id'] ?? 0 ) ] ?? ( $row['pipeline_id'] ?? '' ),
                    $names['stages'][ (int) ( $row['stage_id'] ?? 0 ) ]       ?? ( $row['stage_id'] ?? '' ),
                    self::owner_name( (int) ( $row['owner_user_id'] ?? 0 ) ),
                    self::tag_names( $id ),
                    Sources::label( (string) ( $row['source_plugin'] ?? '' ) ),
                    $row['source_form_id']  ?? '',
                    $row['source_entry_id'] ?? '',
                    $row['status']          ?? '',
                    // Exports keep the site's clock, as before 1.17.0.
                    Dates::to_site( $row['created_at'] ?? '' ),
                    Dates::to_site( $row['updated_at'] ?? '' ),
                ];
                foreach ( $field_labels as $label ) {
                    $line[] = $fields[ $label ] ?? '';
                }

                fputcsv( $out, array_map( [ self::class, 'csv_safe' ], $line ), ',', '"', '' );
                $written++;
            }
            $args['paged']++;
            $more = ( $args['paged'] - 1 ) * $args['per_page'] < (int) $page['total'];
        } while ( $more );

        return $written;
    }

    /**
     * Every distinct field label present in the export, in a stable order.
     *
     * One column per label keeps the CSV rectangular even though submissions come
     * from different forms with different fields.
     *
     * @return string[]
     */
    private static function exportable_field_labels( array $args ): array {
        global $wpdb;
        $data = $wpdb->prefix . 'entryvault_submission_data';
        $subs = $wpdb->prefix . 'entryvault_submissions';

        // Scope to the same filters the export itself uses where we can do so
        // cheaply; a label list slightly wider than the rows is harmless (empty
        // column) whereas a missing label would silently drop data.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom tables; export path, uncached by design.
        $labels = $wpdb->get_col(
            "SELECT DISTINCT d.field_label
             FROM {$data} d
             INNER JOIN {$subs} s ON s.id = d.submission_id
             WHERE d.field_label <> '' AND d.is_stale = 0
             ORDER BY d.field_label ASC
             LIMIT 200"
        );

        return array_map( 'strval', (array) $labels );
    }

    /**
     * Column headings for the submitted fields, never repeating a fixed heading.
     * A form's own "Email" field used to produce two columns called "Email".
     *
     * @param string[] $fixed
     * @param string[] $labels
     * @return string[]
     */
    public static function field_headers( array $fixed, array $labels ): array {
        $taken = array_map( 'strtolower', $fixed );
        $out   = [];
        foreach ( $labels as $label ) {
            $heading = (string) $label;
            if ( in_array( strtolower( $heading ), $taken, true ) ) {
                /* translators: %s: form field label that clashes with a fixed column, e.g. "Email". */
                $heading = sprintf( __( '%s (form field)', 'entryvault' ), $heading );
            }
            $taken[] = strtolower( $heading );
            $out[]   = $heading;
        }
        return $out;
    }

    /** Field values for one submission, keyed by label. @return array<string,string> */
    private static function field_values_by_label( int $submission_id ): array {
        global $wpdb;
        $data = $wpdb->prefix . 'entryvault_submission_data';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; export path.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT field_label, field_type, value FROM {$data} WHERE submission_id = %d AND is_stale = 0",
                $submission_id
            ),
            ARRAY_A
        );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $label = (string) $row['field_label'];
            $value = Field_Values::display( (string) $row['value'], (string) ( $row['field_type'] ?? '' ) );
            // A repeated label (checkbox groups) joins rather than overwrites.
            $out[ $label ] = isset( $out[ $label ] ) ? $out[ $label ] . ', ' . $value : $value;
        }
        return $out;
    }

    /** Pipeline and stage names keyed by id, so the CSV never ships raw numbers. */
    private static function pipeline_stage_names(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'entryvault_pipelines';
        $s = $wpdb->prefix . 'entryvault_stages';

        $out = [ 'pipelines' => [], 'stages' => [] ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small lookup tables.
        foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$p}", ARRAY_A ) as $row ) {
            $out['pipelines'][ (int) $row['id'] ] = (string) $row['name'];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small lookup tables.
        foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$s}", ARRAY_A ) as $row ) {
            $out['stages'][ (int) $row['id'] ] = (string) $row['name'];
        }
        return $out;
    }

    /** Display name for an owner id, or empty when unassigned. */
    private static function owner_name( int $user_id ): string {
        if ( $user_id <= 0 ) {
            return '';
        }
        $user = get_userdata( $user_id );
        return $user ? (string) $user->display_name : sprintf( '#%d', $user_id );
    }

    /** Comma-separated tag names for a submission. */
    private static function tag_names( int $submission_id ): string {
        global $wpdb;
        $st = $wpdb->prefix . 'entryvault_submission_tags';
        $t  = $wpdb->prefix . 'entryvault_tags';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom tables; export path.
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.name FROM {$st} st INNER JOIN {$t} t ON t.id = st.tag_id WHERE st.submission_id = %d ORDER BY t.name ASC",
            $submission_id
        ) );
        return implode( ', ', array_map( 'strval', (array) $names ) );
    }

    /**
     * Neutralize CSV/formula injection: prefix a leading apostrophe to any cell
     * starting with =, +, -, @ (or a tab/CR), so spreadsheet apps treat it as text
     * instead of executing it as a formula. Mirrors the Trinity Forms/Automatic Linker exports.
     */
    public static function csv_safe( $value ): string {
        $value = (string) $value;
        if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }
}

// Register the export handler whenever this class file is loaded. The view
// file forces this load on the list screen; admin-post.php requests pick it
// up through the autoloader because the action callback below names the
// class directly, which triggers autoload before the action fires.
if ( function_exists( 'add_action' ) ) {
    add_action( 'admin_post_entryvault_export_submissions', [ Submissions_Admin::class, 'handle_export' ] );
}

