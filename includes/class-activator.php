<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Activator {

    /**
     * The activation hook: set up, then (single activation from the Plugins
     * screen only) ask the Plugins screen to point at the board once.
     *
     * @param bool $network_wide
     */
    public static function on_activate( $network_wide = false ): void {
        self::activate();
        Ready_Notice::on_activate( $network_wide );
    }

    public static function activate(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // A fresh install stores UTC from the first row: nothing to convert.
        // (Read before the version is stamped at the end of this method.)
        if ( false === get_option( 'entryvault_version' ) && false === get_option( 'ev_version' ) ) {
            Utc_Migration::mark_fresh();
        }

        self::migrate_legacy_tables();
        self::create_tables();
        // dbDelta() says nothing when the database refuses a table; check, and
        // keep the database's own error for the notice and Site Health.
        Schema_Check::record();
        self::migrate_columns();
        self::seed_defaults();
        self::ensure_settings();
        self::ensure_pipeline_tokens();

        if ( ! defined( 'ENTRYVAULT_PRO' ) ) {
            // Clean up the premium IMAP poll event if Pro was removed (or a
            // pre-1.0.7 free install scheduled it); the pro module owns it now.
            wp_clear_scheduled_hook( 'entryvault_imap_poll' );
        }

        update_option( 'entryvault_version', ENTRYVAULT_VERSION );
        delete_option( 'ev_version' ); // pre-1.0.7 name
    }

    /**
     * Free build only: drop the reply-poll event a Pro install left behind.
     *
     * Installing the free zip over Pro ("Replace current with uploaded") does not
     * run activation, and when the version number is unchanged maybe_upgrade()
     * does nothing either. The event stayed, its 5-minute schedule no longer
     * existed, and WP-Cron logged "Cron reschedule event error" on every run.
     * Also forget the premium schema marker, so a later Pro upload schedules the
     * event again. Cheap: the cron array is autoloaded.
     */
    public static function clear_orphaned_premium_events( $pro_present = null ): void {
        $pro_present = is_bool( $pro_present ) ? $pro_present : defined( 'ENTRYVAULT_PRO' );
        if ( $pro_present || ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }
        if ( wp_next_scheduled( 'entryvault_imap_poll' ) ) {
            wp_clear_scheduled_hook( 'entryvault_imap_poll' );
            delete_option( 'entryvault_pro_schema' );
        }
        if ( wp_next_scheduled( 'entryvault_retention_run' ) ) {
            wp_clear_scheduled_hook( 'entryvault_retention_run' );
        }
    }

    /**
     * Run pending schema migrations without a manual deactivate/reactivate.
     *
     * Hooked on admin_init. The schema is created only by the activation hook,
     * which does NOT fire on plugin update (e.g. when a free install is upgraded
     * in place to Pro by uploading the Pro zip). This re-runs the idempotent
     * activation routine once whenever the stored version differs from the
     * current one, so new tables/columns in a release land automatically. Every
     * step in activate() is guarded/idempotent (dbDelta, guarded seeds + cron),
     * so re-running is safe; the version gate keeps it to once per release.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'entryvault_version' ) !== ENTRYVAULT_VERSION ) {
            // activate() stamps the new version; it reads the old one first to
            // tell a fresh install (nothing to convert) from an upgrade.
            self::activate();
            self::drop_email_from_public_columns();
        }
        // Stored times move from the site's clock to UTC once (1.17.0). Not tied
        // to the version check: an interrupted run resumes on the next admin page.
        Utc_Migration::maybe_run();
    }

    /**
     * Stop existing sites publishing lead email addresses on a public page.
     *
     * The public submissions table shipped with `email` among its default
     * columns, so following the documentation put every lead's address on a
     * page anyone could open — and no setting in the UI could change it.
     * Changing the default only helps new installs, so migrate the sites that
     * never customised it. A site that HAS chosen its own columns keeps them:
     * we only rewrite the exact old default.
     */
    private static function drop_email_from_public_columns(): void {
        $settings = get_option( 'entryvault_settings', [] );
        if ( ! is_array( $settings ) || ! isset( $settings['frontend_default_columns'] ) ) {
            return;
        }

        $current = (array) $settings['frontend_default_columns'];
        $old     = [ 'submitted_at', 'name', 'email', 'stage' ];

        if ( $current !== $old ) {
            return;
        }

        $settings['frontend_default_columns'] = [ 'submitted_at', 'name', 'stage' ];
        update_option( 'entryvault_settings', $settings );
    }

    /**
     * One-time rename of pre-1.0.7 `<prefix>ev_*` tables to `<prefix>entryvault_*`.
     *
     * Must run BEFORE create_tables(): dbDelta would otherwise create empty
     * tables under the new names, and the guard below would then skip the
     * rename, stranding existing data in the old tables. RENAME TABLE is an
     * atomic metadata-only operation, so this is instant regardless of size.
     */
    private static function migrate_legacy_tables(): void {
        global $wpdb;
        $names = [
            'submissions', 'submission_data', 'pipelines', 'stages', 'tags',
            'submission_tags', 'timeline', 'inboxes', 'emails', 'automations',
        ];
        foreach ( $names as $t ) {
            $old = $wpdb->prefix . 'ev_' . $t;
            $new = $wpdb->prefix . 'entryvault_' . $t;

            $has_old = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) );
            $has_new = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) );

            if ( $has_old && ! $has_new ) {
                $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) );
            }
        }
    }

    /**
     * Idempotent column migrations that dbDelta cannot reliably perform.
     *
     * dbDelta does not convert an existing column's NULL/NOT NULL state, so
     * loosening a constraint on an already-created table needs an explicit ALTER.
     *
     * emails.submission_id: originally NOT NULL, but the Pro IMAP reply worker
     * logs unmatched inbound replies with a NULL submission_id for triage. On the
     * old schema MySQL rejected that insert, silently dropping the triage row — so
     * make the column nullable on installs created before this fix.
     */
    private static function migrate_columns(): void {
        global $wpdb;
        $emails = $wpdb->prefix . 'entryvault_emails';

        $col = $wpdb->get_row( $wpdb->prepare(
            'SHOW COLUMNS FROM %i LIKE %s',
            $emails,
            'submission_id'
        ) );
        if ( $col && isset( $col->Null ) && strtoupper( (string) $col->Null ) === 'NO' ) {
            $wpdb->query( $wpdb->prepare(
                'ALTER TABLE %i MODIFY submission_id BIGINT UNSIGNED DEFAULT NULL',
                $emails
            ) );
        }
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix . 'entryvault_';

        $statements = [
            "CREATE TABLE {$p}submissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_plugin VARCHAR(40) NOT NULL,
                source_form_id VARCHAR(100) NOT NULL,
                source_entry_id VARCHAR(100) NOT NULL,
                pipeline_id BIGINT UNSIGNED NOT NULL,
                stage_id BIGINT UNSIGNED NOT NULL,
                owner_user_id BIGINT UNSIGNED DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                title VARCHAR(500) DEFAULT NULL,
                email VARCHAR(254) DEFAULT NULL,
                reply_token VARCHAR(40) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY unique_source (source_plugin, source_form_id, source_entry_id),
                KEY idx_pipeline_stage (pipeline_id, stage_id),
                KEY idx_owner (owner_user_id),
                KEY idx_email (email),
                KEY idx_token (reply_token),
                KEY idx_created (created_at)
            ) {$charset};",

            "CREATE TABLE {$p}submission_data (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                submission_id BIGINT UNSIGNED NOT NULL,
                field_key VARCHAR(100) NOT NULL,
                field_label VARCHAR(255) NOT NULL,
                field_type VARCHAR(40) NOT NULL,
                value LONGTEXT,
                is_stale TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY idx_submission (submission_id),
                KEY idx_field (field_key),
                KEY idx_stale (is_stale)
            ) {$charset};",

            "CREATE TABLE {$p}pipelines (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(150) NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT '#2E86AB',
                inbox_id BIGINT UNSIGNED DEFAULT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY unique_slug (slug)
            ) {$charset};",

            "CREATE TABLE {$p}stages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pipeline_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT '#999999',
                position SMALLINT NOT NULL DEFAULT 0,
                wip_limit INT DEFAULT NULL,
                is_terminal TINYINT(1) NOT NULL DEFAULT 0,
                is_won TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY idx_pipeline (pipeline_id)
            ) {$charset};",

            "CREATE TABLE {$p}tags (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT '#888888',
                PRIMARY KEY  (id),
                UNIQUE KEY unique_slug (slug)
            ) {$charset};",

            "CREATE TABLE {$p}submission_tags (
                submission_id BIGINT UNSIGNED NOT NULL,
                tag_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (submission_id, tag_id),
                KEY idx_tag (tag_id)
            ) {$charset};",

            "CREATE TABLE {$p}timeline (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                submission_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED DEFAULT NULL,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                type VARCHAR(20) NOT NULL,
                data LONGTEXT,
                meta LONGTEXT,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_submission (submission_id),
                KEY idx_parent (parent_id),
                KEY idx_type (type),
                KEY idx_created (created_at)
            ) {$charset};",

            "CREATE TABLE {$p}emails (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                submission_id BIGINT UNSIGNED DEFAULT NULL,
                inbox_id BIGINT UNSIGNED DEFAULT NULL,
                direction VARCHAR(4) NOT NULL,
                message_id VARCHAR(500) DEFAULT NULL,
                in_reply_to VARCHAR(500) DEFAULT NULL,
                from_addr VARCHAR(254) NOT NULL,
                to_addr VARCHAR(500) NOT NULL,
                subject VARCHAR(500) DEFAULT NULL,
                body_text LONGTEXT,
                body_html LONGTEXT,
                raw_headers LONGTEXT,
                sent_at DATETIME NOT NULL,
                send_error VARCHAR(500) DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY idx_submission (submission_id),
                KEY idx_message_id (message_id(191))
            ) {$charset};",
        ];

        foreach ( $statements as $sql ) {
            dbDelta( $sql );
        }
    }

    private static function seed_defaults(): void {
        global $wpdb;
        $now       = current_time( 'mysql', true );
        $pipelines = $wpdb->prefix . 'entryvault_pipelines';
        $stages    = $wpdb->prefix . 'entryvault_stages';

        $existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $pipelines ) );
        if ( $existing > 0 ) {
            return;
        }

        // Named in the site language, so a Spanish or Arabic site does not start
        // with an English board. Default_Names translates rows seeded in English
        // by older versions when they are shown. Activation runs after
        // plugins_loaded, so the bundled translations may not be loaded yet.
        if ( ! is_textdomain_loaded( 'entryvault' ) && defined( 'ENTRYVAULT_FILE' ) ) {
            load_plugin_textdomain( 'entryvault', false, dirname( plugin_basename( ENTRYVAULT_FILE ) ) . '/languages' );
        }
        $wpdb->insert( $pipelines, [
            'name'       => Default_Names::translate( Default_Names::PIPELINE ),
            'slug'       => 'inbox',
            'color'      => '#2E86AB',
            'is_default' => 1,
            'created_at' => $now,
        ] );
        $pipeline_id = (int) $wpdb->insert_id;

        $pos = 0;
        foreach ( Default_Names::STAGES as $english => [ $color, $terminal, $won ] ) {
            $wpdb->insert( $stages, [
                'pipeline_id' => $pipeline_id,
                'name'        => Default_Names::translate( $english ),
                'color'       => $color,
                'position'    => $pos++,
                'is_terminal' => $terminal,
                'is_won'      => $won,
            ] );
        }
    }

    /**
     * Give every existing pipeline a stored public-table access token. Runs on
     * activation and (via maybe_upgrade) on version change, so the free Inbox
     * pipeline — and any pipelines a prior Pro install created — get a token
     * without one ever being lazily written during an unauthenticated request.
     * Idempotent: Frontend::ensure_token() only writes when none exists.
     */
    private static function ensure_pipeline_tokens(): void {
        global $wpdb;
        $slugs = (array) $wpdb->get_col( "SELECT slug FROM {$wpdb->prefix}entryvault_pipelines" );
        foreach ( $slugs as $slug ) {
            Frontend::ensure_token( (string) $slug );
        }
    }

    private static function ensure_settings(): void {
        if ( is_array( get_option( 'entryvault_settings' ) ) ) {
            return;
        }
        global $wpdb;
        $default_pipeline = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}entryvault_pipelines WHERE is_default = 1 LIMIT 1"
        );
        // Free defaults only. Premium modules add their own seed values (IMAP
        // poll interval, round-robin, integration creds) via the filter below;
        // the free wordpress.org build seeds none of that premium plumbing.
        $defaults = [
            'default_pipeline'         => $default_pipeline,
            // No email by default: this table is meant for public pages.
            'frontend_default_columns' => [ 'submitted_at', 'name', 'stage' ],
        ];
        update_option( 'entryvault_settings', apply_filters( 'entryvault_default_settings', $defaults ) );
    }
}
