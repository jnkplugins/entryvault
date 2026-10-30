<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function boot(): void {
        // Priority 0: the blocks register on init (10) and need the domain loaded.
        add_action( 'init', [ $this, 'load_textdomain' ], 0 );

        // First request on 1.17.0+: note where the site-time rows end, before
        // anything new (UTC) is written. Two autoloaded options otherwise.
        Utc_Migration::record_marks();

        Adapter_Registry::instance()->boot();

        add_action( 'rest_api_init', [ new REST_API(), 'register_routes' ] );
        REST_Tags::register();
        REST_Reports::register();
        REST_Settings::register();
        REST_Health::register();
        add_action( 'rest_api_init', [ new REST_Import(), 'register_routes' ] );
        // Past entries (free since 1.20.0): the REST routes, and the batch handler
        // WP-Cron runs between the page's polls.
        add_action( 'rest_api_init', [ new REST_Backfill(), 'register_routes' ] );
        add_action( Backfill::HOOK, [ Backfill::class, 'run' ] );

        // Premium REST controllers (pipelines/automations/owners) live in
        // pro/ and self-register on this action; in the free build nothing listens.
        do_action( 'entryvault_register_rest_routes' );

        add_action( 'init',         [ Capabilities::class, 'maybe_seed_admin' ] );
        add_action( 'init',         [ Activator::class, 'clear_orphaned_premium_events' ] );
        add_filter( 'map_meta_cap', [ Capabilities::class, 'map_meta_cap' ], 10, 4 );

        Frontend::register();
        // Tools > Export / Erase Personal Data.
        Privacy::register();
        REST_Public::register();
        Public_Front::register();

        if ( is_admin() ) {
            // Apply pending schema migrations on update (the activation hook does
            // not fire on in-place upgrades, e.g. free → Pro via zip upload).
            add_action( 'admin_init', [ Activator::class, 'maybe_upgrade' ] );
            Schema_Check::register();
            Submissions_Admin::register();
            Feedback::register();
            Ready_Notice::register();
            add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
            add_filter( 'plugin_action_links_' . plugin_basename( ENTRYVAULT_FILE ), [ $this, 'plugin_action_links' ] );
            add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
        }

        // Premium engine + listeners (automations, IMAP) plug in here (pro/ only).
        do_action( 'entryvault_boot' );
    }

    /**
     * Load the translations bundled in languages/. wordpress.org language packs
     * (wp-content/languages/plugins/) still win when present, since WordPress
     * checks there first.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain( 'entryvault', false, dirname( plugin_basename( ENTRYVAULT_FILE ) ) . '/languages' );
    }

    /**
     * Action links on the Plugins screen (next to Deactivate): Settings, and
     * an Upgrade link on the free tier. In Pro the licensing SDK owns
     * activation UI, so the upgrade link is dropped.
     *
     * @param array<string,string> $links
     * @return array<string,string>
     */
    public function plugin_action_links( array $links ): array {
        $settings = '<a href="' . esc_url( admin_url( 'admin.php?page=entryvault-settings' ) ) . '">'
            . esc_html__( 'Settings', 'entryvault' ) . '</a>';
        array_unshift( $links, $settings );

        if ( ! defined( 'ENTRYVAULT_PRO' ) ) {
            // `src` distinguishes this entry point from the admin-menu item in the
            // upgrade page's outbound utm_content, which was otherwise constant.
            $links['entryvault_upgrade'] = '<a href="' . esc_url( admin_url( 'admin.php?page=entryvault-upgrade&src=plugins-row' ) ) . '" '
                . 'style="color:#b45309;font-weight:600;">' . esc_html__( 'Upgrade to Pro', 'entryvault' ) . '</a>';
        }
        return $links;
    }

    /**
     * Row meta on the Plugins screen (after "View details"): Docs + Support.
     *
     * @param array<int,string> $meta
     * @return array<int,string>
     */
    public function plugin_row_meta( array $meta, string $file ): array {
        if ( plugin_basename( ENTRYVAULT_FILE ) === $file ) {
            $meta[] = '<a href="https://jnkplugins.com/entryvault/" target="_blank" rel="noopener">'
                . esc_html__( 'Docs', 'entryvault' ) . '</a>';
            // The wordpress.org forum for this plugin is closed. Support is the
            // in-plugin Feedback & Support form, which mails the JNK Plugins team
            // at jnkplugins.com (that site has no public support page to link).
            $meta[] = '<a href="' . esc_url( admin_url( 'admin.php?page=entryvault-feedback' ) ) . '">'
                . esc_html__( 'Support', 'entryvault' ) . '</a>';
        }
        return $meta;
    }

    public function register_admin_menu(): void {
        $cap = 'manage_options';

        // A user who is not an administrator but has been granted one or more
        // pipelines gets the board, and only the board. Every REST call it makes
        // is checked per pipeline, so the menu is the only thing missing.
        if ( ! current_user_can( $cap ) && Capabilities::current_user_has_pipeline_access() ) {
            add_menu_page(
                __( 'EntryVault', 'entryvault' ),
                __( 'EntryVault', 'entryvault' ),
                'read',
                'entryvault',
                [ $this, 'render_view_kanban' ],
                'dashicons-archive',
                26
            );
            return;
        }

        add_menu_page(
            __( 'EntryVault', 'entryvault' ),
            __( 'EntryVault', 'entryvault' ),
            $cap,
            'entryvault',
            [ $this, 'render_view_kanban' ],
            'dashicons-archive',
            26
        );

        $items = [
            [ 'entryvault',             __( 'Kanban', 'entryvault' ),      'render_view_kanban' ],
            [ 'entryvault-dashboard',   __( 'Dashboard', 'entryvault' ),   'render_view_dashboard' ],
            [ 'entryvault-submissions', __( 'Submissions', 'entryvault' ), 'render_view_submissions' ],
            [ 'entryvault-tags',        __( 'Tags', 'entryvault' ),        'render_view_tags' ],
            [ 'entryvault-backfill',    __( 'Past entries', 'entryvault' ), 'render_view_past_entries' ],
            [ 'entryvault-import',      __( 'Import', 'entryvault' ),      'render_view_import' ],
            [ 'entryvault-health',      __( 'Health', 'entryvault' ),      'render_view_health' ],
            [ 'entryvault-settings',    __( 'Settings', 'entryvault' ),    'render_view_settings' ],
            [ 'entryvault-feedback',    __( 'Feedback', 'entryvault' ),    'render_view_feedback' ],
        ];

        foreach ( $items as [ $slug, $label, $cb ] ) {
            $page_hook = add_submenu_page( 'entryvault', $label, $label, $cap, $slug, [ $this, $cb ] );
            if ( 'entryvault-submissions' === $slug && $page_hook ) {
                add_action( 'load-' . $page_hook, [ Submissions_Admin::class, 'setup_screen' ] );
            }
        }

        // Premium submenus (Pipelines/Owners/Automations/Inboxes) register
        // here from pro/; the free build adds nothing further.
        do_action( 'entryvault_register_admin_menu', $cap );

        // Show the "Upgrade to Pro" entry point only in the free build. The Pro
        // build defines ENTRYVAULT_PRO and manages licensing via the inline License
        // panel on Settings, so it never shows this upsell. Free core carries no
        // license logic at all — the menu is a plain informational link.
        if ( ! defined( 'ENTRYVAULT_PRO' ) ) {
            add_submenu_page(
                'entryvault',
                __( 'Upgrade to Pro', 'entryvault' ),
                '<span class="ev-upgrade-menu" style="color:#f0b849;">' . esc_html__( 'Upgrade to Pro', 'entryvault' ) . '</span>',
                $cap,
                'entryvault-upgrade',
                [ $this, 'render_view_upgrade' ]
            );
        }
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'entryvault' ) === false ) {
            return;
        }

        // Shared JnK UI kit first (the same file ships in every JnK plugin, every
        // rule scoped under .jnkui), then EntryVault's own colours and pieces.
        // Handles carry the plugin name so they never clash with another JnK plugin.
        wp_enqueue_style(
            'entryvault-jnk-ui',
            ENTRYVAULT_URL . 'admin/assets/css/jnk-ui.css',
            [],
            ENTRYVAULT_VERSION
        );
        wp_enqueue_style(
            'entryvault-admin',
            ENTRYVAULT_URL . 'admin/assets/css/admin.css',
            [ 'entryvault-jnk-ui' ],
            ENTRYVAULT_VERSION
        );

        // Settings screen: enqueue the tab/save script (previously inline) and hand
        // it the REST root, nonce and defaults via a localized bootstrap object.
        // Independent of the Kanban assets below.
        if ( str_ends_with( $hook, 'entryvault-settings' ) ) {
            wp_enqueue_script(
                'entryvault-settings',
                ENTRYVAULT_URL . 'admin/assets/js/settings.js',
                [],
                ENTRYVAULT_VERSION,
                true
            );
            $ev_opt = get_option( 'entryvault_settings', [] );
            wp_localize_script( 'entryvault-settings', 'EntryVaultSettingsBoot', [
                'restUrl'         => esc_url_raw( rest_url( 'entryvault/v1' ) ),
                'nonce'           => wp_create_nonce( 'wp_rest' ),
                'defaultPipeline' => ( is_array( $ev_opt ) && isset( $ev_opt['default_pipeline'] ) ) ? (int) $ev_opt['default_pipeline'] : 0,
                'i18n'            => [
                    'saved'      => __( 'Saved.', 'entryvault' ),
                    'saveFailed' => __( 'Save failed.', 'entryvault' ),
                    /* translators: %s: the settings section, e.g. "Mailchimp". */
                    'labelSaved' => __( '%s saved.', 'entryvault' ),
                    /* translators: 1: the settings section, 2: the error message. */
                    'labelFail'  => __( '%1$s save failed: %2$s', 'entryvault' ),
                    'general'    => __( 'General', 'entryvault' ),
                    'owners'     => __( 'Owners', 'entryvault' ),
                ],
            ] );
        }

        // Submissions screen: bulk-action reveal helper (was inline) and column widths.
        if ( str_ends_with( $hook, 'entryvault-submissions' ) ) {
            wp_enqueue_style(
                'entryvault-submissions',
                ENTRYVAULT_URL . 'admin/assets/css/submissions.css',
                [],
                ENTRYVAULT_VERSION
            );
            wp_enqueue_script(
                'entryvault-submissions',
                ENTRYVAULT_URL . 'admin/assets/js/submissions.js',
                [],
                ENTRYVAULT_VERSION,
                true
            );
        }

        // Health screen: fetches + renders the /health report (was inline).
        if ( str_ends_with( $hook, 'entryvault-health' ) ) {
            wp_enqueue_script(
                'entryvault-health',
                ENTRYVAULT_URL . 'admin/assets/js/health.js',
                [ 'wp-i18n' ],
                ENTRYVAULT_VERSION,
                true
            );
            wp_set_script_translations( 'entryvault-health', 'entryvault', ENTRYVAULT_PATH . 'languages' );
            wp_localize_script( 'entryvault-health', 'EntryVaultHealthBoot', [
                'restRoot' => esc_url_raw( rest_url( 'entryvault/v1' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            ] );
        }

        // Tags screen: list/create/edit/delete tags over REST (was inline).
        if ( str_ends_with( $hook, 'entryvault-tags' ) ) {
            wp_enqueue_script(
                'entryvault-tags',
                ENTRYVAULT_URL . 'admin/assets/js/tags.js',
                [],
                ENTRYVAULT_VERSION,
                true
            );
            wp_localize_script( 'entryvault-tags', 'EntryVaultTagsBoot', [
                'rest'  => esc_url_raw( rest_url( 'entryvault/v1/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'i18n'  => [
                    'edit'          => __( 'Edit', 'entryvault' ),
                    'delete'        => __( 'Delete', 'entryvault' ),
                    'saved'         => __( 'Saved.', 'entryvault' ),
                    'error'         => __( 'Error.', 'entryvault' ),
                    'confirmDelete' => __( 'Delete this tag? It comes off every entry that has it.', 'entryvault' ),
                    'empty'         => __( 'No tags yet. Add your first one with the form next to this list.', 'entryvault' ),
                    'addTitle'      => __( 'Add a tag', 'entryvault' ),
                    'editTitle'     => __( 'Edit tag', 'entryvault' ),
                ],
            ] );
        }

        // Dashboard (Reports) screen: filters + REST-driven charts (was inline).
        if ( str_ends_with( $hook, 'entryvault-dashboard' ) ) {
            wp_enqueue_style(
                'entryvault-dashboard',
                ENTRYVAULT_URL . 'admin/assets/css/dashboard.css',
                [],
                ENTRYVAULT_VERSION
            );
            wp_enqueue_script(
                'entryvault-dashboard',
                ENTRYVAULT_URL . 'admin/assets/js/dashboard.js',
                [ 'wp-i18n' ],
                ENTRYVAULT_VERSION,
                true
            );
            wp_set_script_translations( 'entryvault-dashboard', 'entryvault', ENTRYVAULT_PATH . 'languages' );
            // "Today", "this week" and "this month" are the site's days, not
            // the browser's (or UTC's).
            $ev_today = Dates::site_today();
            $ev_ts    = (int) strtotime( $ev_today . ' 00:00:00 UTC' );
            wp_localize_script( 'entryvault-dashboard', 'EntryVaultDashboardBoot', [
                'rest'       => esc_url_raw( rest_url( 'entryvault/v1' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'today'      => $ev_today,
                'weekStart'  => gmdate( 'Y-m-d', $ev_ts - ( (int) gmdate( 'w', $ev_ts ) ) * DAY_IN_SECONDS ),
                'monthStart' => gmdate( 'Y-m-01', $ev_ts ),
            ] );
        }

        // Import (CSV) screen: preview/map/run importer (was inline).
        if ( str_ends_with( $hook, 'entryvault-import' ) ) {
            wp_enqueue_script(
                'entryvault-import',
                ENTRYVAULT_URL . 'admin/assets/js/import.js',
                [],
                ENTRYVAULT_VERSION,
                true
            );
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the plugin's own custom table to populate the import stage dropdown.
            $ev_stage_rows = (array) $wpdb->get_results( "SELECT id, name, pipeline_id FROM {$wpdb->prefix}entryvault_stages ORDER BY pipeline_id ASC, position ASC, name ASC", ARRAY_A );
            $entryvault_stages = array_values( array_map( static function ( $s ) {
                return [ 'id' => (int) $s['id'], 'name' => Default_Names::label( $s['name'] ), 'pipeline_id' => (int) $s['pipeline_id'] ];
            }, $ev_stage_rows ) );
            wp_localize_script( 'entryvault-import', 'EntryVaultImportBoot', [
                'restRoot'  => esc_url_raw( rest_url( 'entryvault/v1' ) ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'stages'    => $entryvault_stages,
                'boardUrl'  => admin_url( 'admin.php?page=entryvault' ),
                'i18n'      => [
                    'firstStage'      => __( 'First stage of the pipeline', 'entryvault' ),
                    'please_choose_a' => __( 'Please choose a file or paste CSV first.', 'entryvault' ),
                    'loading_preview' => __( 'Loading preview…', 'entryvault' ),
                    'preview_failed'  => __( 'Preview failed.', 'entryvault' ),
                    /* translators: %s: number of rows loaded. */
                    'loaded_s_rows'   => __( 'Loaded %s rows.', 'entryvault' ),
                    'network_error'   => __( 'Network error.', 'entryvault' ),
                    'ignore'          => __( 'Ignore', 'entryvault' ),
                    'title'           => __( 'Title', 'entryvault' ),
                    'email'           => __( 'Email', 'entryvault' ),
                    'source'          => __( 'Source', 'entryvault' ),
                    'field'           => __( 'Field', 'entryvault' ),
                    'date'            => __( 'Date received', 'entryvault' ),
                    /* translators: %s: number of rows. */
                    'bad_dates'       => __( 'The date in %s rows could not be read, so those entries got today\'s date.', 'entryvault' ),
                    'open_board'      => __( 'Open the board', 'entryvault' ),
                    'you_must_map'    => __( 'You must map one column to Email.', 'entryvault' ),
                    'running'         => __( 'Running…', 'entryvault' ),
                    'import_failed'   => __( 'Import failed.', 'entryvault' ),
                    'done'            => __( 'Done.', 'entryvault' ),
                    'results'         => __( 'Results', 'entryvault' ),
                    'dry_run_no'      => __( 'Test run: nothing was saved.', 'entryvault' ),
                    'imported'        => __( 'Imported:', 'entryvault' ),
                    'would_import'    => __( 'Would import:', 'entryvault' ),
                    /* translators: %s: comma-separated column names. */
                    'one_title_only'  => __( 'Only one column can be the title, but these are all set to Title: %s. Set the others to Field so their values are kept.', 'entryvault' ),
                    'duplicates'      => __( 'Already present:', 'entryvault' ),
                    'duplicates_hint' => __( 'These rows were already on the board, from an earlier import or from the form plugin itself, and were left as they are. Nothing was copied twice.', 'entryvault' ),
                    'skipped'         => __( 'Skipped:', 'entryvault' ),
                    'errors'          => __( 'Errors', 'entryvault' ),
                    'row'             => __( 'Row', 'entryvault' ),
                ],
            ] );
        }

        // The board bundle (React + editor packages) is only for the board. It
        // was loaded on every EntryVault screen, the Upgrade page included.
        // Extensions that decorate other screens can opt in with this filter.
        if ( 'toplevel_page_entryvault' !== $hook && ! apply_filters( 'entryvault_enqueue_board_app', false, $hook ) ) {
            return;
        }

        $build_dir = ENTRYVAULT_PATH . 'admin/assets/kanban/build/';
        $asset_php = $build_dir . 'index.asset.php';
        $script    = $build_dir . 'index.js';

        if ( ! file_exists( $script ) || ! file_exists( $asset_php ) ) {
            return;
        }

        $asset = include $asset_php;
        wp_enqueue_script(
            'entryvault-kanban',
            ENTRYVAULT_URL . 'admin/assets/kanban/build/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? ENTRYVAULT_VERSION,
            true
        );
        // The board uses wp.i18n; its JSON is named after the built index.js path.
        wp_set_script_translations( 'entryvault-kanban', 'entryvault', ENTRYVAULT_PATH . 'languages' );
        $ev_user = wp_get_current_user();
        wp_localize_script( 'entryvault-kanban', 'EntryVaultBoot', [
            'restUrl'         => esc_url_raw( rest_url( 'entryvault/v1/' ) ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'defaultPipeline' => self::default_pipeline_for_current_user(),
            // The page shows the "past entries found" box above the board, which
            // then leaves out its own "Nothing here yet" box (admin/views/kanban.php).
            'pastEntriesBox'  => ! empty( Past_Entries::board_box()['offer'] ),
            // ?submission=<id> deep links open that entry on its own pipeline.
            'linkedPipeline'  => self::linked_pipeline_id(),
            // The Kanban app reads currentUser to decide note ownership (who may
            // delete a note) and to label "Posting as …". Without it readBootUser()
            // returns null, so the note delete control never renders for anyone.
            'currentUser'     => [
                'id'           => (int) $ev_user->ID,
                'display_name' => $ev_user->display_name,
                'is_admin'     => current_user_can( 'manage_options' ),
            ],
            // Card.jsx resolves owner initials from this list and falls back to
            // "#<id>" when it cannot find a match. It was never sent, so the
            // fallback fired for every assigned card on every site and a team of
            // five read as #4, #7, #12 — while Notes and the by-owner report showed
            // the same people by name.
            'owners'          => self::assignable_owners(),
            // Stored times are UTC; the board shows clock times in the site's
            // zone ("Asia/Kolkata", or "+05:30" for a fixed-offset site).
            'timezone'        => wp_timezone_string(),
            // Dates on the board are written in the user's WordPress language
            // (Profile > Language), not the browser's. BCP 47 tag, e.g. "ko-KR".
            'locale'          => self::js_locale(),
        ] );
        if ( file_exists( $build_dir . 'index.css' ) ) {
            wp_enqueue_style(
                'entryvault-kanban',
                ENTRYVAULT_URL . 'admin/assets/kanban/build/index.css',
                [],
                $asset['version'] ?? ENTRYVAULT_VERSION
            );
        }
    }

    public function render_view_kanban():       void { Ready_Notice::done(); include ENTRYVAULT_PATH . 'admin/views/kanban.php'; }
    public function render_view_dashboard():    void { include ENTRYVAULT_PATH . 'admin/views/dashboard.php'; }
    public function render_view_submissions():  void { include ENTRYVAULT_PATH . 'admin/views/submissions-list.php'; }
    public function render_view_tags():         void { include ENTRYVAULT_PATH . 'admin/views/tags.php'; }
    public function render_view_import():       void { include ENTRYVAULT_PATH . 'admin/views/import.php'; }
    public function render_view_past_entries(): void { include ENTRYVAULT_PATH . 'admin/views/past-entries.php'; }
    public function render_view_health():       void { include ENTRYVAULT_PATH . 'admin/views/health.php'; }
    public function render_view_settings():     void { include ENTRYVAULT_PATH . 'admin/views/settings.php'; }
    public function render_view_feedback():     void { include ENTRYVAULT_PATH . 'admin/views/feedback.php'; }
    public function render_view_upgrade():      void { include ENTRYVAULT_PATH . 'admin/views/upgrade.php'; }

    /**
     * The pipeline the board opens on: the configured default, or, for a user
     * who may not view that one, the first pipeline they were granted.
     */
    /**
     * The current user's WordPress locale as a BCP 47 tag for Intl in the
     * browser: "ar" stays "ar", "ko_KR" becomes "ko-KR".
     */
    public static function js_locale(): string {
        $locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
        return str_replace( '_', '-', (string) $locale );
    }

    public static function default_pipeline_for_current_user(): int {
        $opt     = get_option( 'entryvault_settings' );
        $default = is_array( $opt ) ? (int) ( $opt['default_pipeline'] ?? 0 ) : 0;
        if ( Capabilities::current_user_can_manage() ) {
            return $default;
        }
        $viewable = Capabilities::viewable_pipeline_ids_for_current_user();
        if ( in_array( $default, $viewable, true ) ) {
            return $default;
        }
        return $viewable ? (int) $viewable[0] : 0;
    }

    /**
     * Users who can own a submission, as { id, display_name }.
     *
     * Mirrors the /owners REST endpoint so the board and the owner dropdown never
     * disagree about who exists.
     *
     * @return array<int,array{id:int,display_name:string}>
     */
    /**
     * Every user who may own a submission: anyone who can edit posts, plus the
     * roles ticked under Settings > Owners. The board, the card dropdown and the
     * Submissions list all use this, so they agree on who can own a lead (the
     * list used to offer administrators only).
     *
     * @return \WP_User[]
     */
    public static function owner_users(): array {
        if ( ! function_exists( 'get_users' ) ) {
            return [];
        }
        $base  = [ 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 200 ];
        $users = (array) get_users( $base + [ 'capability' => [ 'edit_posts' ] ] );

        $settings = get_option( 'entryvault_settings', [] );
        $roles    = is_array( $settings ) && ! empty( $settings['owner_roles'] ) && is_array( $settings['owner_roles'] )
            ? array_values( array_filter( array_map( 'sanitize_key', $settings['owner_roles'] ) ) )
            : [];
        if ( $roles ) {
            $users = array_merge( $users, (array) get_users( $base + [ 'role__in' => $roles ] ) );
        }

        $by_id = [];
        foreach ( $users as $user ) {
            if ( $user instanceof \WP_User && ! isset( $by_id[ $user->ID ] ) ) {
                $by_id[ $user->ID ] = $user;
            }
        }
        $by_id = array_values( $by_id );
        usort( $by_id, static fn( $a, $b ) => strcasecmp( (string) $a->display_name, (string) $b->display_name ) );

        /**
         * Filter the users offered as submission owners.
         *
         * @param \WP_User[] $users
         */
        return (array) apply_filters( 'entryvault_owner_users', $by_id );
    }

    /**
     * Pipeline of the submission named in ?submission=, or 0.
     */
    private static function linked_pipeline_id(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
        $id = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
        if ( $id <= 0 ) {
            return 0;
        }
        $row = Submission_Store::get( $id );
        if ( ! $row || ! Capabilities::current_user_can_view_pipeline( (int) $row['pipeline_id'] ) ) {
            return 0;
        }
        return (int) $row['pipeline_id'];
    }

    public static function assignable_owners(): array {
        $out = [];
        foreach ( self::owner_users() as $user ) {
            $out[] = [
                'id'           => (int) $user->ID,
                'display_name' => (string) $user->display_name,
            ];
        }
        return $out;
    }
}
