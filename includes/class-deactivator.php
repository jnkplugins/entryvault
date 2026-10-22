<?php
namespace EntryVault;

defined( "ABSPATH" ) || exit;

class Deactivator {
    public static function deactivate(): void {
        // Free core schedules no cron events; premium modules register their
        // own deactivation cleanup. Kept as the deactivation-hook target.
    }
}
