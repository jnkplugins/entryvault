<?php
defined( "WP_UNINSTALL_PLUGIN" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

global $wpdb;

$settings = get_option( "entryvault_settings", [] );
if ( empty( $settings["delete_data_on_uninstall"] ) ) {
    return;
}

$tables = [
    "entryvault_submissions", "entryvault_submission_data", "entryvault_pipelines", "entryvault_stages",
    "entryvault_tags", "entryvault_submission_tags", "entryvault_timeline", "entryvault_inboxes",
    "entryvault_emails", "entryvault_automations",
    // Pre-1.0.7 table names, in case an install never ran the rename migration.
    "ev_submissions", "ev_submission_data", "ev_pipelines", "ev_stages",
    "ev_tags", "ev_submission_tags", "ev_timeline", "ev_inboxes",
    "ev_emails", "ev_automations",
];
foreach ( $tables as $t ) {
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $t ) );
}

delete_option( "entryvault_settings" );
delete_option( "entryvault_version" );
delete_option( "entryvault_pro_schema" );
delete_option( "entryvault_retention" );
delete_option( "entryvault_retention_state" );
delete_option( "entryvault_form_pipelines" );
delete_option( "entryvault_spam_skips" );
delete_option( "entryvault_spam_skip_days" );
wp_clear_scheduled_hook( "entryvault_retention_run" );
delete_option( "ev_version" ); // pre-1.0.7 name
delete_transient( "entryvault_imap_manual_poll_cooldown" );

// LicensorPro SDK state (license key, cached license/update info).
delete_option( "licensorpro_entryvault_key" );
delete_option( "licensorpro_entryvault_license" );
delete_option( "licensorpro_entryvault_remote" );
delete_transient( "licensorpro_entryvault_validate" );
delete_transient( "licensorpro_entryvault_update" );
