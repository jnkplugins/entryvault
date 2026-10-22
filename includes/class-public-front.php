<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Public-facing assets and conditional enqueues.
 *
 * Loads `public/assets/css/public.css` and `public/assets/js/public.js`
 * only when the current request actually needs them (i.e. the queried
 * post contains the `[entryvault_table]` shortcode or one of the
 * EntryVault Gutenberg blocks). Also localizes the REST URL + nonce
 * into `EntryVaultPublicBoot` so the JS can talk to /entryvault/v1/public.
 */
class Public_Front {

    const HANDLE_CSS = 'entryvault-public';
    const HANDLE_JS  = 'entryvault-public';

    /**
     * Wire WordPress hooks. Idempotent — Plugin::boot() can call this once.
     */
    public static function register(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'init', [ __CLASS__, 'register_blocks' ] );
    }

    /**
     * Register the free-core submissions-table block. It is a dynamic
     * (render_callback) block so Public_Front can lazily enqueue the right
     * assets. The premium kanban-public block is registered separately from
     * pro/ (see pro/bootstrap.php) so free core carries no reference to a
     * directory that does not ship in the Lite build.
     */
    public static function register_blocks(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        $base = defined( 'ENTRYVAULT_PATH' ) ? ENTRYVAULT_PATH : ( dirname( __DIR__ ) . '/' );
        $table = $base . 'blocks/submissions-table';
        if ( is_dir( $table ) && file_exists( $table . '/block.json' ) ) {
            $block = register_block_type( $table );
            // The editor script uses wp.i18n; point it at the bundled JSON files.
            if ( $block instanceof \WP_Block_Type ) {
                foreach ( (array) $block->editor_script_handles as $handle ) {
                    wp_set_script_translations( $handle, 'entryvault', $base . 'languages' );
                }
            }
        }
    }

    /**
     * Decide whether the current page actually needs our assets, register
     * + enqueue them, and localize the boot object.
     */
    public static function enqueue_assets(): void {
        if ( ! self::page_needs_assets() ) {
            return;
        }
        self::force_enqueue();
    }

    /**
     * Force-register and enqueue regardless of post content. Used by block
     * render callbacks where the parent loop hasn't necessarily set up the
     * global $post yet (e.g. REST-rendered blocks, FSE templates).
     */
    public static function force_enqueue(): void {
        $version = defined( 'ENTRYVAULT_VERSION' ) ? ENTRYVAULT_VERSION : '0.0.0';
        $base    = defined( 'ENTRYVAULT_URL' )     ? ENTRYVAULT_URL     : plugin_dir_url( dirname( __DIR__ ) . '/entryvault.php' );

        if ( ! wp_style_is( self::HANDLE_CSS, 'registered' ) ) {
            wp_register_style(
                self::HANDLE_CSS,
                $base . 'public/assets/css/public.css',
                [],
                $version
            );
        }
        if ( ! wp_script_is( self::HANDLE_JS, 'registered' ) ) {
            wp_register_script(
                self::HANDLE_JS,
                $base . 'public/assets/js/public.js',
                [],
                $version,
                true
            );
        }

        wp_enqueue_style( self::HANDLE_CSS );
        wp_enqueue_script( self::HANDLE_JS );

        wp_localize_script(
            self::HANDLE_JS,
            'EntryVaultPublicBoot',
            [
                'restUrl' => esc_url_raw( rest_url( 'entryvault/v1/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }

    /**
     * Detect whether the queried post embeds an EntryVault shortcode/block.
     */
    protected static function page_needs_assets(): bool {
        if ( is_admin() ) {
            return false;
        }
        $post = get_post();
        if ( ! $post || empty( $post->post_content ) ) {
            return false;
        }
        $content = (string) $post->post_content;

        if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'entryvault_table' ) ) {
            return true;
        }
        if ( function_exists( 'has_block' ) ) {
            if ( has_block( 'entryvault/submissions-table', $post ) ) {
                return true;
            }
            if ( has_block( 'entryvault/kanban-public', $post ) ) {
                return true;
            }
        }
        return false;
    }
}
