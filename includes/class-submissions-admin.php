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
        add_action( 'admin_post_entryvault_empty_spam', [ __CLASS__, 'handle_empty_spam' ] );
        add_action( 'admin_post_entryvault_not_spam', [ __CLASS__, 'handle_not_spam' ] );
    }

    /** Leave a one-shot notice and go back to the list. */
    private static function back_with( string $notice, string $fallback ): void {
        set_transient( self::notice_key( get_current_user_id() ), $notice, 5 * MINUTE_IN_SECONDS );
        $back = remove_query_arg( [ 'entryvault_notice', 'ev_notice' ], wp_get_referer() ?: $fallback );
        wp_safe_redirect( add_query_arg( 'ev_notice', '1', $back ) );
        exit;
    }

    /** admin-post: delete every entry in the Spam folder. */
    public static function handle_empty_spam(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden.', 'entryvault' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'entryvault_empty_spam' );
        $n = Spam_Folder::delete( null );
        /* translators: %d: number of entries. */
        self::back_with( sprintf( _n( 'Deleted %d spam entry.', 'Deleted %d spam entries.', $n, 'entryvault' ), $n ), admin_url( 'admin.php?page=entryvault-submissions&status=spam' ) );
    }

    /** admin-post: the "Not spam" row action. */
    public static function handle_not_spam(): void {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden.', 'entryvault' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'entryvault_not_spam_' . $id );
        $n = Spam_Folder::release( [ $id ], false, get_current_user_id() ?: null );
        self::back_with( self::not_spam_notice( $n, false ), admin_url( 'admin.php?page=entryvault-submissions&status=spam' ) );
    }

    private static function not_spam_notice( int $n, bool $ran ): string {
        return $ran
            /* translators: %d: number of entries. */
            ? sprintf( _n( '%d entry is no longer spam and is on its board again; what it skipped has run.', '%d entries are no longer spam and are on their boards again; what they skipped has run.', $n, 'entryvault' ), $n )
            /* translators: %d: number of entries. */
            : sprintf( _n( '%d entry is no longer spam and is on its board again.', '%d entries are no longer spam and are on their boards again.', $n, 'entryvault' ), $n );
    }

    /**
     * Read filter args from $_GET and sanitize them.
     *
     * @return array<string,mixed>
     */
    public static function args_from_request( array $request ): array {
        $status_in   = isset( $request['status'] ) ? sanitize_key( wp_unslash( (string) $request['status'] ) ) : '';
        $status_safe = in_array( $status_in, [ 'archived', 'spam', 'open', 'closed', 'any', 'all' ], true ) ? $status_in : '';

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
            'starred'     => ! empty( $request['starred'] ),
            'unread'      => ! empty( $request['unread'] ),
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
        $pipelines = Default_Names::label_rows( $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}entryvault_pipelines ORDER BY name ASC", ARRAY_A ) );
        $stages    = Default_Names::label_rows( $wpdb->get_results( "SELECT id, name, pipeline_id FROM {$wpdb->prefix}entryvault_stages ORDER BY pipeline_id ASC, position ASC, name ASC", ARRAY_A ) );
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

        ?>
        <?php
        Admin_UI::open(
            __( 'Submissions', 'entryvault' ),
            __( 'Every entry in one list. Filter it, tick rows to change many at once, or export what you see to a spreadsheet.', 'entryvault' ),
            '<a href="#ev-export" class="button" data-ev-export-open>' . esc_html__( 'Export', 'entryvault' ) . '</a>'
            . '<a href="' . esc_url( Admin_UI::url( 'entryvault' ) ) . '" class="button button-primary">' . esc_html__( 'Open the board', 'entryvault' ) . '</a>'
        );
        ?>

            <?php
            $current_status = (string) $args['status'];
            $base_url       = admin_url( 'admin.php?page=entryvault-submissions' );
            $all_url        = remove_query_arg( 'status', $base_url );
            $archived_url   = add_query_arg( 'status', 'archived', $base_url );
            $is_archived    = ( $current_status === 'archived' );
            $is_spam        = ( $current_status === Spam_Folder::STATUS );
            $ev_spam_n      = Spam_Folder::count();
            $is_unread      = ! empty( $args['unread'] );
            $is_starred     = ! empty( $args['starred'] );
            $ev_unread      = Entry_Flags::unread_total();
            ?>
            <ul class="subsubsub">
                <li class="all">
                    <a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo ( $is_archived || $is_spam || $is_unread || $is_starred ) ? '' : 'current'; ?>">
                        <?php esc_html_e( 'All', 'entryvault' ); ?>
                    </a> |
                </li>
                <li class="unread">
                    <a href="<?php echo esc_url( add_query_arg( 'unread', '1', $base_url ) ); ?>" class="<?php echo $is_unread ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Unread', 'entryvault' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $ev_unread ) ); ?>)</span>
                    </a> |
                </li>
                <li class="starred">
                    <a href="<?php echo esc_url( add_query_arg( 'starred', '1', $base_url ) ); ?>" class="<?php echo $is_starred ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Starred', 'entryvault' ); ?>
                    </a> |
                </li>
                <li class="archived">
                    <a href="<?php echo esc_url( $archived_url ); ?>" class="<?php echo $is_archived ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Archived', 'entryvault' ); ?>
                    </a> |
                </li>
                <li class="spam">
                    <a href="<?php echo esc_url( add_query_arg( 'status', Spam_Folder::STATUS, $base_url ) ); ?>" class="<?php echo $is_spam ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Spam', 'entryvault' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $ev_spam_n ) ); ?>)</span>
                    </a>
                </li>
            </ul>
            <?php if ( $is_spam ) : ?>
                <p class="jnkui-muted ev-spam-note"><?php
                    /**
                     * Filter the note above the Spam folder.
                     *
                     * @param string $note Default note.
                     */
                    echo esc_html( (string) apply_filters( 'entryvault_spam_folder_note', __( 'Entries a form plugin marked as spam, and the ones you moved here. They stay off the board, the counts and exports. "Not spam" puts one on the board where it would have gone. Spam is kept until you delete it.', 'entryvault' ) ) );
                ?></p>
            <?php endif; ?>
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
                <?php foreach ( [ 'unread', 'starred' ] as $ev_flag ) : ?>
                    <?php if ( ! empty( $args[ $ev_flag ] ) ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( $ev_flag ); ?>" value="1">
                    <?php endif; ?>
                <?php endforeach; ?>
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

            <?php self::render_export_panel( $_GET, $args ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters. ?>

            <?php
            /**
             * Fires between the filter bar (and export panel) and the table.
             *
             * @param array $request Current $_GET.
             * @param array $args    Current list args.
             */
            do_action( 'entryvault_submissions_before_table', $_GET, $args ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
            ?>

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
     * The export panel: format, columns (tick and order them) and the list's
     * own filters as hidden fields. Works without JavaScript; the script only
     * adds the move up / move down buttons.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $args
     */
    public static function render_export_panel( array $request, array $args ): void {
        $prefs   = Export::prefs();
        $all     = Export::available( Export::field_labels( $args ) );
        $chosen  = array_values( array_filter( $prefs['cols'], static fn( $k ) => isset( $all[ $k ] ) ) );
        // Saved order first, then the rest (unticked), in the default order.
        $ordered = $chosen ? array_merge( array_fill_keys( $chosen, true ), array_fill_keys( array_keys( $all ), false ) ) : array_fill_keys( array_keys( $all ), true );
        foreach ( $chosen as $k ) {
            $ordered[ $k ] = true;
        }
        $skip = [ 'page', 'paged', 'action', 'action2', 'filter_action', '_wpnonce', '_wp_http_referer', 'entryvault_notice', 'ev_notice', 'format', 'cols' ];
        ?>
        <details class="jnkui-card ev-export" id="ev-export">
            <summary><strong><?php esc_html_e( 'Export what this list shows', 'entryvault' ); ?></strong></summary>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ev-export-form">
                <input type="hidden" name="action" value="entryvault_export_submissions">
                <?php wp_nonce_field( 'entryvault_export_submissions', '_wpnonce', false ); ?>
                <?php foreach ( $request as $key => $value ) :
                    $key = sanitize_key( (string) $key );
                    if ( '' === $key || in_array( $key, $skip, true ) || ! is_scalar( $value ) || '' === (string) $value ) {
                        continue;
                    }
                    ?>
                    <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( (string) $value ) ) ); ?>">
                <?php endforeach; ?>
                <fieldset class="ev-export-formats">
                    <legend><?php esc_html_e( 'File type', 'entryvault' ); ?></legend>
                    <?php foreach ( [ 'csv' => __( 'CSV (Excel, Google Sheets, Numbers)', 'entryvault' ), 'xlsx' => __( 'Excel (.xlsx)', 'entryvault' ), 'json' => __( 'JSON (for developers)', 'entryvault' ) ] as $fmt => $label ) : ?>
                        <label><input type="radio" name="format" value="<?php echo esc_attr( $fmt ); ?>" <?php checked( $prefs['format'], $fmt ); ?>> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset>
                    <legend><?php esc_html_e( 'Columns, in this order', 'entryvault' ); ?></legend>
                    <p class="jnkui-muted"><?php esc_html_e( 'Untick what you do not need. Use the arrows to change the order; the file keeps it, and so does the next export.', 'entryvault' ); ?></p>
                    <p><button type="button" class="button-link" data-ev-cols="all"><?php esc_html_e( 'Tick all', 'entryvault' ); ?></button> · <button type="button" class="button-link" data-ev-cols="none"><?php esc_html_e( 'Untick all', 'entryvault' ); ?></button></p>
                    <ol class="ev-export-cols">
                        <?php foreach ( $ordered as $key => $on ) :
                            if ( ! isset( $all[ $key ] ) ) {
                                continue;
                            }
                            $fid = 'ev-col-' . substr( md5( (string) $key ), 0, 10 );
                            ?>
                            <li>
                                <input type="checkbox" id="<?php echo esc_attr( $fid ); ?>" name="cols[]" value="<?php echo esc_attr( (string) $key ); ?>" <?php checked( $on ); ?>>
                                <label for="<?php echo esc_attr( $fid ); ?>"><bdi><?php echo esc_html( $all[ $key ]['label'] ); ?></bdi><?php if ( 0 === strpos( (string) $key, 'f:' ) ) : ?> <span class="jnkui-muted"><?php esc_html_e( '(form field)', 'entryvault' ); ?></span><?php endif; ?></label>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </fieldset>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Download', 'entryvault' ); ?></button>
                <span class="jnkui-muted"><?php esc_html_e( 'Only the entries the list is filtering, all pages.', 'entryvault' ); ?></span></p>
            </form>
        </details>
        <?php
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
        } elseif ( in_array( $action, [ 'bulk_mark_read', 'bulk_mark_unread', 'bulk_star', 'bulk_unstar' ], true ) ) {
            $flag   = [ 'bulk_mark_read' => 'read', 'bulk_mark_unread' => 'unread', 'bulk_star' => 'star', 'bulk_unstar' => 'unstar' ][ $action ];
            $count  = Entry_Flags::bulk( $ids, $flag );
            $notice = [
                /* translators: %d: number of submissions. */
                'read'   => sprintf( _n( 'Marked %d submission as read.', 'Marked %d submissions as read.', $count, 'entryvault' ), $count ),
                /* translators: %d: number of submissions. */
                'unread' => sprintf( _n( 'Marked %d submission as unread.', 'Marked %d submissions as unread.', $count, 'entryvault' ), $count ),
                /* translators: %d: number of submissions. */
                'star'   => sprintf( _n( 'Starred %d submission.', 'Starred %d submissions.', $count, 'entryvault' ), $count ),
                /* translators: %d: number of submissions. */
                'unstar' => sprintf( _n( 'Removed the star from %d submission.', 'Removed the star from %d submissions.', $count, 'entryvault' ), $count ),
            ][ $flag ];
        } elseif ( $action === 'bulk_spam' ) {
            $count  = Spam_Folder::mark( $ids, get_current_user_id() ?: null );
            $notice = sprintf(
                /* translators: %d: count */
                _n( 'Moved %d submission to spam.', 'Moved %d submissions to spam.', $count, 'entryvault' ),
                $count
            );
        } elseif ( $action === 'bulk_not_spam' ) {
            $run    = ! empty( $_POST['ev_spam_run'] ) && '' !== Spam_Folder::release_option_label();
            $count  = Spam_Folder::release( $ids, $run, get_current_user_id() ?: null );
            $notice = self::not_spam_notice( $count, $run );
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

        // Format, columns and their order come from the export panel; a bare link
        // (older bookmarks) still gets the full CSV.
        Export::stream( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
    }

    /**
     * Write the export (header + every matching row, page by page) to $out.
     *
     * @param resource $out
     * @return int Rows written.
     */
    public static function write_csv( $out, array $args ): int {
        return Export::write_csv( $out, $args, Export::columns( null, Export::field_labels( $args ) ) );
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
            // Never two columns with one heading (two "Email" fields both
            // renamed to "Email (form field)").
            $base = $heading;
            for ( $n = 2; in_array( strtolower( $heading ), $taken, true ); $n++ ) {
                $heading = $base . ' ' . $n;
            }
            $taken[] = strtolower( $heading );
            $out[]   = $heading;
        }
        return $out;
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

