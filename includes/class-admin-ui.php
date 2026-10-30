<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Page chrome for every EntryVault admin screen: the branded top bar with the
 * plugin's own tabs, and a page header (title, one plain line, actions).
 *
 * Markup only. Menus, capabilities, nonces and REST routes are untouched, so a
 * screen behaves exactly as before; this layer makes the screens look like one
 * product. Styles: admin/assets/css/jnk-ui.css (the shared JnK kit, scoped
 * under .jnkui) plus admin/assets/css/admin.css (EntryVault's own touches).
 *
 * The class name is EntryVault's own on purpose: several JnK plugins can be
 * active together, and two plugins declaring the same class is a fatal error.
 */
class Admin_UI {

    /** Main tabs, in order: page slug => label. */
    private static function primary_tabs(): array {
        return [
            'entryvault'             => __( 'Board', 'entryvault' ),
            'entryvault-dashboard'   => __( 'Overview', 'entryvault' ),
            'entryvault-submissions' => __( 'Submissions', 'entryvault' ),
            'entryvault-tags'        => __( 'Tags', 'entryvault' ),
            'entryvault-backfill'    => __( 'Past entries', 'entryvault' ),
            'entryvault-import'      => __( 'Import', 'entryvault' ),
            'entryvault-health'      => __( 'Health', 'entryvault' ),
        ];
    }

    /**
     * Extra tabs shown under "More tools". The free plugin adds none; add-ons
     * list their own screens here.
     *
     * Each entry: page slug => [ 'label' => string, 'locked' => bool ].
     */
    private static function more_tabs(): array {
        $tabs = (array) apply_filters( 'entryvault_ui_more_tabs', [] );
        $out  = [];
        foreach ( $tabs as $slug => $tab ) {
            $tab = is_array( $tab ) ? $tab : [ 'label' => (string) $tab ];
            $out[ sanitize_key( (string) $slug ) ] = [
                'label'  => (string) ( $tab['label'] ?? $slug ),
                'locked' => ! empty( $tab['locked'] ),
            ];
        }
        return $out;
    }

    public static function url( string $slug ): string {
        return admin_url( 'admin.php?page=' . $slug );
    }

    private static function current_page(): string {
        return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'entryvault'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks the active tab.
    }

    private static function tab( string $slug, string $label, string $current, string $class = 'jnkui-tab', bool $locked = false ): string {
        $active = $slug === $current ? ' is-active' : '';
        $badge  = $locked ? ' <span class="jnkui-pill">' . esc_html__( 'Locked', 'entryvault' ) . '</span>' : '';
        return '<a class="' . esc_attr( trim( $class . $active ) ) . '" href="' . esc_url( self::url( $slug ) ) . '"'
            . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . $badge . '</a>';
    }

    /**
     * Open a screen. $actions is trusted HTML built by the caller (buttons).
     *
     * @param array{wide?:bool} $opts wide: let the page use the full width (the board).
     */
    public static function open( string $title, string $description = '', string $actions = '', array $opts = [] ): void {
        $current = self::current_page();
        // People who were only given a pipeline see the board and nothing else,
        // so they get the brand bar without tabs that would lead to "not allowed".
        $manager = current_user_can( 'manage_options' );

        echo '<div class="wrap jnkui ev-ui">';
        echo '<div class="jnkui-topbar">';
        echo '<a class="jnkui-brand" href="' . esc_url( self::url( 'entryvault' ) ) . '">'
            . '<span class="jnkui-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/></svg></span>'
            . '<b>' . esc_html__( 'EntryVault', 'entryvault' ) . '</b>'
            . '<span class="jnkui-ver">' . esc_html( 'v' . ( defined( 'ENTRYVAULT_VERSION' ) ? ENTRYVAULT_VERSION : '' ) ) . '</span>'
            // Says Pro is on, rather than leaving people to infer it from tabs that unlock.
            // Free core has no licence code; the Pro module answers through this filter.
            . ( apply_filters( 'entryvault_pro_active', false ) ? '<span class="jnkui-pro-badge" style="margin-inline-start:6px;padding:1px 7px;border-radius:999px;background:#4f46e5;color:#fff;font-size:11px;font-weight:600;letter-spacing:.02em;vertical-align:middle">Pro</span>' : '' )
            . '</a>';

        if ( $manager ) {
            $more    = self::more_tabs();
            $in_more = isset( $more[ $current ] );

            echo '<nav class="jnkui-tabs" aria-label="' . esc_attr__( 'EntryVault sections', 'entryvault' ) . '">';
            foreach ( self::primary_tabs() as $slug => $label ) {
                echo self::tab( $slug, $label, $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above.
            }
            if ( $more ) {
                echo '<details class="jnkui-more"><summary class="jnkui-tab' . ( $in_more ? ' is-active' : '' ) . '">'
                    . esc_html__( 'More tools', 'entryvault' ) . ' <span aria-hidden="true">&#9662;</span></summary><div class="jnkui-menu">';
                foreach ( $more as $slug => $tab ) {
                    echo self::tab( $slug, $tab['label'], $current, '', $tab['locked'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above.
                }
                echo '</div></details>';
            }
            echo '</nav>';

            echo '<div class="jnkui-topbar-end">';
            if ( ! defined( 'ENTRYVAULT_PRO' ) ) {
                echo '<a class="ev-ui-upgrade" href="' . esc_url( self::url( 'entryvault-upgrade' ) . '&src=tab' ) . '">' . esc_html__( 'Upgrade', 'entryvault' ) . '</a>';
            }
            echo self::tab( 'entryvault-feedback', __( 'Help', 'entryvault' ), $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above.
            echo self::tab( 'entryvault-settings', __( 'Settings', 'entryvault' ), $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above.
            echo '</div>';
        }
        echo '</div>';

        // WordPress moves admin notices to just after this marker.
        echo '<hr class="wp-header-end" style="display:none">';

        echo '<div class="jnkui-page' . ( ! empty( $opts['wide'] ) ? ' is-wide' : '' ) . '"><div class="jnkui-head"><div><h1>' . esc_html( $title ) . '</h1>';
        if ( '' !== $description ) {
            echo '<p>' . esc_html( $description ) . '</p>';
        }
        echo '</div>';
        if ( '' !== $actions ) {
            echo '<div class="jnkui-head-actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted markup from the calling view.
        }
        echo '</div>';
    }

    public static function close(): void {
        echo '</div></div>';
    }

    /** A card with a heading row. Close it with card_close(). $actions is trusted HTML. */
    public static function card_open( string $title = '', string $description = '', string $actions = '', string $id = '' ): void {
        echo '<div class="jnkui-card"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
        if ( '' !== $title ) {
            echo '<div class="jnkui-card-head"><div><h2>' . esc_html( $title ) . '</h2>';
            if ( '' !== $description ) {
                echo '<p>' . esc_html( $description ) . '</p>';
            }
            echo '</div>' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted markup from the calling view.
        }
    }

    public static function card_close(): void {
        echo '</div>';
    }

    /** A friendly empty state. $action is trusted HTML (a button) or ''. */
    public static function empty_state( string $title, string $text, string $action = '', string $icon = 'check' ): string {
        $icons = [
            'check'  => '<path d="M20 6 9 17l-5-5"/>',
            'tray'   => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'lock'   => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        ];
        return '<div class="jnkui-empty"><div class="jnkui-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . ( $icons[ $icon ] ?? $icons['check'] ) . '</svg></div><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $text ) . '</p>' . $action . '</div>';
    }
}
