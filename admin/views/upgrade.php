<?php
/**
 * EntryVault — "Upgrade to Pro" page (free build only).
 *
 * A single, restrained in-admin upsell: a feature summary, the in-place upgrade
 * reassurance, and a link out to pricing. Registered only when the Pro module is
 * absent (see Plugin::register_admin_menu); the Pro build shows its License page
 * instead. No premium code, no phone-home — just static marketing + a link.
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'entryvault' ) );
}

// utm_content records HOW the visitor reached this page, so the admin-menu item, the
// Plugins-row link and any future in-context pointer can be told apart on the far
// side. Whitelisted rather than passed through, since the value lands in a URL.
$ev_src     = isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only attribution, changes no state.
$ev_allowed = [ 'plugins-row', 'entries-empty', 'pipelines', 'settings' ];
$ev_content = in_array( $ev_src, $ev_allowed, true ) ? $ev_src : 'admin-menu';

/** Where "Get EntryVault Pro" points. Filterable so the URL can change later. */
$ev_pricing_url = apply_filters( 'entryvault_upgrade_url', 'https://jnkplugins.com/entryvault/pricing.php?utm_source=entryvault&utm_medium=plugin&utm_campaign=pro-upgrade&utm_content=' . rawurlencode( $ev_content ) );

$ev_pro_features = [
    [ __( 'Automation engine', 'entryvault' ), __( 'Set up rules once: when a form comes in, a stage changes, a tag is added or someone replies, send an email, move the entry, tag it, assign it or notify another app.', 'entryvault' ) ],
    [ __( 'Integrations', 'entryvault' ), __( 'Send people to Mailchimp or MailerLite, notify other apps safely, and tag entries by form.', 'entryvault' ) ],
    [ __( 'Email reply tracking', 'entryvault' ), __( 'Connect a mailbox and replies to your emails are filed under the right entry, so the whole conversation sits in one place.', 'entryvault' ) ],
    [ __( 'Multiple pipelines', 'entryvault' ), __( 'Run separate Kanban boards for different teams or workflows.', 'entryvault' ) ],
    [ __( 'Historical backfill', 'entryvault' ), __( 'Pull past form entries into EntryVault in one pass.', 'entryvault' ) ],
    [ __( 'Public Kanban board', 'entryvault' ), __( 'Show a view-only board on any page, behind a private link. Plus approvals, quick edits and exports emailed on a schedule.', 'entryvault' ) ],
];
?>
<?php
\EntryVault\Admin_UI::open(
    __( 'EntryVault Pro', 'entryvault' ),
    __( 'You are using the free EntryVault: a complete board for one pipeline. Pro adds the rules, connections, email replies and extra pipelines a growing team needs.', 'entryvault' ),
    '<a href="' . esc_url( $ev_pricing_url ) . '" target="_blank" rel="noopener noreferrer" class="button jnkui-btn-brand">' . esc_html__( 'See Pro plans', 'entryvault' ) . '</a>'
);
?>
<div class="jnkui-callout is-ok">
    <p><strong><?php esc_html_e( 'Upgrading keeps everything.', 'entryvault' ); ?></strong>
    <?php esc_html_e( 'Install Pro over this version. It is the same plugin with the same database, so your pipelines, entries, tags and settings carry over untouched.', 'entryvault' ); ?></p>
</div>

<div class="jnkui-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
    <?php foreach ( $ev_pro_features as $f ) : ?>
        <div class="jnkui-stat">
            <div class="jnkui-stat-label" style="color:var(--jnk-ink);font-size:14px"><span class="jnkui-dot is-brand"></span><?php echo esc_html( $f[0] ); ?></div>
            <div class="jnkui-stat-foot" style="font-size:13px;margin-top:8px"><?php echo esc_html( $f[1] ); ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( 'Already bought Pro?', 'entryvault' ); ?></h2></div></div>
    <ol class="jnkui-steps">
        <li><strong><?php esc_html_e( 'Download the Pro zip', 'entryvault' ); ?></strong><?php esc_html_e( 'From your account on jnkplugins.com, under your license.', 'entryvault' ); ?>
            <br><a class="button button-primary" style="margin-top:6px" href="<?php echo esc_url( 'https://license.jnkplugins.com/account/licenses?utm_source=plugin&utm_medium=upgrade-page&utm_campaign=entryvault' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download the Pro zip', 'entryvault' ); ?></a></li>
        <li><strong><?php esc_html_e( 'Upload it over this one', 'entryvault' ); ?></strong><?php esc_html_e( 'Plugins, Add New, Upload Plugin. WordPress shows this copy as EntryVault and the upload as EntryVault Pro. Choose "Replace current with uploaded".', 'entryvault' ); ?></li>
        <li><strong><?php esc_html_e( 'Enter your license key', 'entryvault' ); ?></strong><?php esc_html_e( 'A notice at the top of the screen takes you to the box, in EntryVault Settings under License. The key is in your purchase email.', 'entryvault' ); ?></li>
    </ol>
    <div class="jnkui-card-body" style="border-top:1px solid var(--jnk-line-2)">
        <p class="description" style="margin:0"><?php esc_html_e( 'The Pro zip is a few megabytes. If your host refuses the upload, unzip it and copy the entryvault folder over the existing one in wp-content/plugins with SFTP or your host\'s file manager.', 'entryvault' ); ?></p>
    </div>
</div>
<?php \EntryVault\Admin_UI::close(); ?>
