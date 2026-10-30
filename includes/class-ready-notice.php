<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * "EntryVault is ready. Start here" on the Plugins screen, once, after the
 * plugin is activated on its own (not bulk, not network-wide, not WP-CLI).
 *
 * A stranger activated it and then had to find where it lived (persona runs,
 * 29 Oct 2026). The same pattern as the other JnK plugins: no redirect, the
 * owner stays where they were. Gone once the link is followed, the notice is
 * dismissed, the board is opened any other way, or the plugin is deactivated.
 */
final class Ready_Notice {

    public const OPTION = 'entryvault_ready_notice';
    private const AJAX  = 'entryvault_dismiss_ready';

    public static function register(): void {
        add_action( 'admin_notices', [ self::class, 'render' ] );
        add_action( 'wp_ajax_' . self::AJAX, [ self::class, 'dismiss' ] );
    }

    /**
     * Called from the activation hook, after the tables are set up.
     *
     * @param bool $network_wide
     */
    public static function on_activate( $network_wide = false ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, WordPress checked the nonce for this activation.
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
        $bulk    = in_array( 'activate-selected', [ $action, $action2 ], true );
        if ( $network_wide || $bulk || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        update_option( self::OPTION, 1, false );
    }

    /** The board was opened: the notice has done its job. */
    public static function done(): void {
        if ( get_option( self::OPTION ) ) {
            delete_option( self::OPTION );
        }
    }

    public static function render(): void {
        if ( ! get_option( self::OPTION ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'plugins' !== $screen->id ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only: WordPress's own "activated several plugins" flag.
        if ( isset( $_GET['activate-multi'] ) ) {
            delete_option( self::OPTION );
            return;
        }
        printf(
            '<div class="notice notice-success is-dismissible entryvault-ready-notice"><p><strong>%s</strong> <a href="%s">%s</a></p></div>',
            /* translators: %s: plugin name. */
            esc_html( sprintf( __( '%s is ready.', 'entryvault' ), 'EntryVault' ) ),
            esc_url( admin_url( 'admin.php?page=entryvault' ) ),
            esc_html__( 'Start here', 'entryvault' )
        );
        // WordPress adds the dismiss button; tell the server when it is used.
        printf(
            "<script>document.addEventListener('click',function(e){if(e.target.closest&&e.target.closest('.entryvault-ready-notice .notice-dismiss')){var b=new FormData();b.append('action',%s);b.append('_ajax_nonce',%s);fetch(%s,{method:'POST',credentials:'same-origin',body:b});}});</script>",
            wp_json_encode( self::AJAX ),
            wp_json_encode( wp_create_nonce( self::AJAX ) ),
            wp_json_encode( admin_url( 'admin-ajax.php' ) )
        );
    }

    /** AJAX: the notice was dismissed. */
    public static function dismiss(): void {
        check_ajax_referer( self::AJAX );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }
        delete_option( self::OPTION );
        wp_send_json_success();
    }
}
