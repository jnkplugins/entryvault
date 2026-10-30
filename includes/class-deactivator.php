<?php
namespace EntryVault;

defined( "ABSPATH" ) || exit;

class Deactivator {
    public static function deactivate(): void {
        // A past-entries run in progress schedules its next batch; leave none
        // behind. Premium modules register their own deactivation cleanup.
        wp_clear_scheduled_hook( 'entryvault_backfill_run' );
        delete_option( 'entryvault_ready_notice' );
    }
}
