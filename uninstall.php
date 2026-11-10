<?php
defined( "WP_UNINSTALL_PLUGIN" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

global $wpdb;

// Deleting the plugin frees this site's licence seat on the licensing server,
// whether or not its data goes too. Only builds with the licence client ship it.
$uninstall_seat_slug = "entryvault";
if ( is_readable( __DIR__ . "/pro/licensorpro-client/uninstall-seat.php" ) ) {
    include __DIR__ . "/pro/licensorpro-client/uninstall-seat.php";
}

// Scheduled events (IMAP poll, retention, scheduled exports, digest, backfill
// batches) go whether or not the data stays: with no plugin left nothing answers
// them (the keep-data default left a daily job behind, review 8 Nov 2026).
foreach ( (array) _get_cron_array() as $entryvault_events ) {
    foreach ( array_keys( (array) $entryvault_events ) as $entryvault_hook ) {
        if ( 0 === strpos( (string) $entryvault_hook, 'entryvault_' ) ) {
            wp_unschedule_hook( $entryvault_hook );
        }
    }
}

$settings = get_option( "entryvault_settings", [] );
if ( empty( $settings["delete_data_on_uninstall"] ) ) {
    return;
}

// Drop every EntryVault table, found by prefix: Pro's too, since a site that
// ran Pro and went back to the free build still has them, and the free build
// ships no pro/ (same as Linkweave, QA 31 Oct 2026). Naming Pro's tables here
// would also put premium names into the free build.
$entryvault_tables = (array) $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "entryvault_" ) . "%" ) );
// Pre-1.0.7 table names, in case an install never ran the rename migration.
foreach ( [ "ev_submissions", "ev_submission_data", "ev_pipelines", "ev_stages", "ev_tags", "ev_submission_tags", "ev_timeline", "ev_inboxes", "ev_emails", "ev_automations" ] as $entryvault_old ) {
    $entryvault_tables[] = $wpdb->prefix . $entryvault_old;
}
foreach ( array_unique( $entryvault_tables ) as $entryvault_table ) {
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $entryvault_table ) );
}

// Every option and transient EntryVault keeps (settings, pipeline tokens,
// backfill state per form, the capability seed flag, past-entries caches,
// scheduled exports, ...) starts with "entryvault_"; the licensing SDK's
// with "licensorpro_entryvault_" (key, licence, update cache, _ever, _lapse).
$entryvault_like = [];
foreach ( [ 'entryvault_', 'licensorpro_entryvault_' ] as $entryvault_prefix ) {
    $entryvault_like[] = $wpdb->esc_like( $entryvault_prefix ) . '%';
    $entryvault_like[] = $wpdb->esc_like( '_transient_' . $entryvault_prefix ) . '%';
    $entryvault_like[] = $wpdb->esc_like( '_transient_timeout_' . $entryvault_prefix ) . '%';
    $entryvault_like[] = $wpdb->esc_like( '_site_transient_' . $entryvault_prefix ) . '%';
    $entryvault_like[] = $wpdb->esc_like( '_site_transient_timeout_' . $entryvault_prefix ) . '%';
}
foreach ( $entryvault_like as $entryvault_pattern ) {
    $entryvault_names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $entryvault_pattern ) );
    foreach ( $entryvault_names as $entryvault_name ) {
        delete_option( $entryvault_name ); // Also clears the options cache.
    }
}
delete_option( "ev_version" ); // pre-1.0.7 name

// Per-user state (pipeline access, "board seen" markers).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'entryvault_' ) . '%' ) );
wp_cache_flush();

// The capability EntryVault gave administrators (and any role it was added to).
if ( function_exists( 'wp_roles' ) ) {
    foreach ( array_keys( wp_roles()->roles ) as $entryvault_role_name ) {
        $entryvault_role = get_role( $entryvault_role_name );
        if ( $entryvault_role ) {
            foreach ( [ 'entryvault_manage', 'entryvault_view_pipeline', 'entryvault_edit_pipeline' ] as $entryvault_cap ) {
                $entryvault_role->remove_cap( $entryvault_cap );
            }
        }
    }
}
