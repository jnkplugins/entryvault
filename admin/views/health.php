<?php
/**
 * EntryVault Health admin page.
 *
 * Vanilla JS screen that calls /entryvault/v1/health and renders status
 * cards. Read-only in the free build; the premium IMAP operator buttons (run
 * poll now / reschedule cron) are injected via the entryvault_health_actions
 * hook from pro/. This view only bootstraps a nonce + REST root and renders
 * markup; it exposes window.EntryVaultHealth so premium actions can reload it.
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'entryvault' ) );
}

$ev_rest_root = esc_url_raw( rest_url( 'entryvault/v1' ) );
$ev_nonce     = wp_create_nonce( 'wp_rest' );
?>
<?php
ob_start();
/**
 * Extra operator buttons are injected here from add-ons. The free build shows none.
 */
do_action( 'entryvault_health_actions' );
$ev_extra_actions = (string) ob_get_clean();

\EntryVault\Admin_UI::open(
    __( 'Health', 'entryvault' ),
    __( 'A quick check that EntryVault is set up right and is picking up your forms.', 'entryvault' ),
    '<span id="ev-health-status" class="jnkui-muted" aria-live="polite"></span>'
    . $ev_extra_actions
    . '<button type="button" class="button button-primary" id="ev-health-refresh">' . esc_html__( 'Check again', 'entryvault' ) . '</button>'
);
?>
<div class="jnkui-card">
    <div class="jnkui-card-body">
        <div id="ev-health-overall" class="ev-health-overall"></div>
    </div>
</div>

<div id="ev-health-cards" class="ev-health-grid"></div>
<?php \EntryVault\Admin_UI::close(); ?>

<?php
// The health report fetch/render lives in the enqueued admin/assets/js/health.js
// (enqueued on the Health screen; REST root + nonce via EntryVaultHealthBoot).
