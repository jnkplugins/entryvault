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

$settings = get_option( "entryvault_settings", [] );
if ( empty( $settings["delete_data_on_uninstall"] ) ) {
    return;
}

$tables = [
    "entryvault_submissions", "entryvault_submission_data", "entryvault_pipelines", "entryvault_stages",
    "entryvault_tags", "entryvault_submission_tags", "entryvault_timeline", "entryvault_inboxes",
    "entryvault_emails", "entryvault_automations", "entryvault_approvals",
    // Pre-1.0.7 table names, in case an install never ran the rename migration.
    "ev_submissions", "ev_submission_data", "ev_pipelines", "ev_stages",
    "ev_tags", "ev_submission_tags", "ev_timeline", "ev_inboxes",
    "ev_emails", "ev_automations",
];
foreach ( $tables as $t ) {
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $t ) );
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

// Scheduled events (IMAP poll, retention, scheduled exports, backfill batches).
foreach ( (array) _get_cron_array() as $entryvault_events ) {
    foreach ( array_keys( (array) $entryvault_events ) as $entryvault_hook ) {
        if ( 0 === strpos( (string) $entryvault_hook, 'entryvault_' ) ) {
            wp_unschedule_hook( $entryvault_hook );
        }
    }
}
