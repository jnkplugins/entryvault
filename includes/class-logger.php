<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

class Logger {

    public static function info( string $channel, string $message, array $context = [] ): void {
        self::write( 'info', $channel, $message, $context );
    }

    public static function error( string $channel, string $message, array $context = [] ): void {
        self::write( 'error', $channel, $message, $context );
    }

    private static function write( string $level, string $channel, string $message, array $context ): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }
        $line = sprintf( '[entryvault:%s:%s] %s', $channel, $level, $message );
        if ( $context ) {
            $line .= ' ' . wp_json_encode( $context );
        }
        error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic log.
    }
}
