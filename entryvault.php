<?php
/**
 * Plugin Name:       EntryVault
 * Plugin URI:        https://jnkplugins.com/entryvault/
 * Update URI:        https://api.jnkplugins.com/
 * Description:       Lightweight CRM & entry manager for WordPress form plugins.
 * Version:           1.15.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            JNK Plugins
 * Author URI:        https://jnkplugins.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       entryvault
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ENTRYVAULT_VERSION', '1.15.0' );
define( 'ENTRYVAULT_FILE', __FILE__ );
define( 'ENTRYVAULT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ENTRYVAULT_URL',  plugin_dir_url( __FILE__ ) );

if ( file_exists( ENTRYVAULT_PATH . 'vendor/autoload.php' ) ) {
    require_once ENTRYVAULT_PATH . 'vendor/autoload.php';
}

spl_autoload_register( static function ( $class ) {
    if ( strpos( $class, 'EntryVault\\' ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( 'EntryVault\\' ) );
    $parts    = explode( '\\', $relative );
    $leaf     = array_pop( $parts );
    $sub      = '';
    foreach ( $parts as $part ) {
        $sub .= strtolower( $part ) . '/';
    }
    $file = $sub . 'class-' . strtolower( str_replace( '_', '-', $leaf ) ) . '.php';
    // Free classes live in includes/; premium classes live in pro/includes/, which
    // is omitted from the free build (so those classes simply never load there).
    foreach ( [ ENTRYVAULT_PATH . 'includes/', ENTRYVAULT_PATH . 'pro/includes/' ] as $base ) {
        if ( file_exists( $base . $file ) ) {
            require_once $base . $file;
            return;
        }
    }
} );

// Pro module: licensing SDK + premium features. Absent from the free wordpress.org
// build, so the plugin runs as the fully-functional free tier when it isn't present.
if ( file_exists( ENTRYVAULT_PATH . 'pro/bootstrap.php' ) ) {
    require_once ENTRYVAULT_PATH . 'pro/bootstrap.php';
}

register_activation_hook(   __FILE__, [ 'EntryVault\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'EntryVault\\Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
    \EntryVault\Plugin::instance()->boot();
} );
