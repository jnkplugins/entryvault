<?php
/**
 * EntryVault Settings admin page.
 *
 * Tabbed interface (vanilla JS, no React). All persistence goes through the
 * REST_Settings controller — this view only bootstraps a nonce + initial data
 * payload and renders the markup. IMAP credentials are NOT managed here; see
 * admin/views/inboxes.php.
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'entryvault' ) );
}

$ev_settings = get_option( 'entryvault_settings', [] );
if ( ! is_array( $ev_settings ) ) {
    $ev_settings = [];
}

$ev_default_pipeline = isset( $ev_settings['default_pipeline'] ) ? (int) $ev_settings['default_pipeline'] : 0;
$ev_reply_to_host    = isset( $ev_settings['reply_to_host'] )    ? (string) $ev_settings['reply_to_host'] : '';
$ev_from_name        = isset( $ev_settings['from_name'] )        ? (string) $ev_settings['from_name'] : '';
$ev_from_email       = isset( $ev_settings['from_email'] )       ? (string) $ev_settings['from_email'] : '';
$ev_owner_roles      = isset( $ev_settings['owner_roles'] ) && is_array( $ev_settings['owner_roles'] )
    ? array_values( $ev_settings['owner_roles'] )
    : [];
$ev_delete_on_uninstall = ! empty( $ev_settings['delete_data_on_uninstall'] );
$ev_skip_spam           = \EntryVault\Adapters\Adapter_Base::skip_spam_enabled();

$ev_all_roles = [];
if ( function_exists( 'wp_roles' ) ) {
    $wp_roles_obj = wp_roles();
    if ( $wp_roles_obj && isset( $wp_roles_obj->roles ) && is_array( $wp_roles_obj->roles ) ) {
        foreach ( $wp_roles_obj->roles as $slug => $info ) {
            $ev_all_roles[ (string) $slug ] = isset( $info['name'] ) ? (string) $info['name'] : (string) $slug;
        }
    }
}

// REST root, nonce and defaults are handed to the enqueued settings script via
// wp_localize_script( 'entryvault-settings', 'EntryVaultSettingsBoot', … ) in
// Plugin::enqueue_admin_assets() — no inline bootstrapping here.
?>
<?php
\EntryVault\Admin_UI::open(
    __( 'Settings', 'entryvault' ),
    __( 'How EntryVault sends replies, who can own entries, and what happens to your data.', 'entryvault' )
);
?>
<div class="entryvault-settings">
    <?php if ( has_action( 'entryvault_settings_after' ) ) : ?>
        <?php // The license card sits under the tabs; the same jump link as in every JnK plugin. ?>
        <p style="margin:0 0 8px;text-align:right"><a href="#lp-entryvault-license"><?php esc_html_e( 'License', 'entryvault' ); ?> &darr;</a></p>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper" id="ev-settings-tabs" role="tablist">
        <a href="#ev-tab-general"   class="nav-tab nav-tab-active" data-ev-tab="general"   role="tab"><?php esc_html_e( 'General', 'entryvault' ); ?></a>
        <a href="#ev-tab-owners"    class="nav-tab"                data-ev-tab="owners"    role="tab"><?php esc_html_e( 'Owners', 'entryvault' ); ?></a>
        <?php
        /**
         * Premium modules (Pro Mailchimp integration, auto-tag rules) append
         * their own <a class="nav-tab" data-ev-tab="…"> here. The free build
         * adds none.
         */
        do_action( 'entryvault_settings_tabs', $ev_settings );
        ?>
    </h2>

    <div id="ev-settings-notice" class="notice inline" style="display:none;"><p></p></div>

    <!-- General -->
    <section id="ev-tab-general" class="ev-tab-panel" role="tabpanel">
        <form id="ev-form-general" class="jnkui-card">
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="ev-default-pipeline"><?php esc_html_e( 'Default pipeline', 'entryvault' ); ?></label></th>
                    <td>
                        <select id="ev-default-pipeline" name="default_pipeline">
                            <option value="0"><?php esc_html_e( 'None', 'entryvault' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'New entries land here unless something else sends them to another pipeline.', 'entryvault' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ev-from-name"><?php esc_html_e( 'Send replies as', 'entryvault' ); ?></label></th>
                    <td>
                        <input type="text" id="ev-from-name" name="from_name" class="regular-text"
                               value="<?php echo esc_attr( $ev_from_name ); ?>"
                               placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
                        <input type="email" id="ev-from-email" name="from_email" class="regular-text"
                               value="<?php echo esc_attr( $ev_from_email ); ?>"
                               placeholder="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'The name and address your replies come from. Leave blank to use the site name and admin address. Without this, WordPress sends as "WordPress", so someone who wrote to your business gets a reply from a stranger.', 'entryvault' ); ?></p>
                    </td>
                </tr>
                <?php
                /**
                 * Whether to show the Reply-To host setting. Replies only carry a
                 * plus-addressed Reply-To when something reads that mailbox, which
                 * the free build never does, so the field did nothing here. An
                 * extension that uses it turns the row on.
                 *
                 * @param bool $show Default false.
                 */
                if ( apply_filters( 'entryvault_settings_show_reply_host', false ) ) :
                    ?>
                <tr>
                    <th scope="row"><label for="ev-reply-to-host"><?php esc_html_e( 'Reply address domain', 'entryvault' ); ?></label></th>
                    <td>
                        <input type="text" id="ev-reply-to-host" name="reply_to_host" class="regular-text"
                               value="<?php echo esc_attr( $ev_reply_to_host ); ?>" placeholder="reply.example.com" />
                        <p class="description"><?php esc_html_e( 'Optional. The domain used in the reply-to address of emails you send, so answers come back to the right entry. Leave blank to use this site\'s domain.', 'entryvault' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show entries on a page', 'entryvault' ); ?></th>
                    <td>
                        <?php
                        // The shortcode is documented as [entryvault_table], but used
                        // exactly as printed it renders "No pipeline specified." — the
                        // pipeline attribute is required, its accepted values are a
                        // slug shown on no screen in the free build, and the access
                        // token that makes the table readable was generated at
                        // activation and never displayed anywhere at all. Print the
                        // whole thing, ready to paste.
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tiny lookup for a settings screen.
                        $ev_pipelines = $wpdb->get_results( "SELECT id, name, slug FROM {$wpdb->prefix}entryvault_pipelines ORDER BY id ASC", ARRAY_A );
                        ?>
                        <?php if ( empty( $ev_pipelines ) ) : ?>
                            <p class="description"><?php esc_html_e( 'No pipelines yet.', 'entryvault' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( (array) $ev_pipelines as $ev_p ) : ?>
                                <?php $ev_token = \EntryVault\Frontend::ensure_token( (string) $ev_p['slug'] ); ?>
                                <p style="margin:0 0 10px;">
                                    <strong><?php echo esc_html( (string) $ev_p['name'] ); ?></strong>
                                    <code style="margin-left:6px;"><?php echo esc_html( (string) $ev_p['slug'] ); ?></code>
                                    <input type="text" class="large-text code" style="margin-top:6px" readonly onclick="this.select();"
                                           value="<?php echo esc_attr( sprintf(
                                               '[entryvault_table pipeline="%s" access_token="%s"]',
                                               (string) $ev_p['slug'],
                                               $ev_token
                                           ) ); ?>" />
                                </p>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e( 'Paste one of these into a page to show that pipeline\'s submissions. Anyone who can open the page can read the table, so treat the token as a password. By default the table shows the date, name and stage, never the email address. A submission without a name shows as "(no name)" rather than its address. Add columns="submitted_at,name,stage,email" only if you really want addresses on a public page. The owner and tags columns are public too once you add them, so leave out internal tags. The Submissions Table block uses the same defaults. If EntryVault is ever deactivated, WordPress prints an unknown shortcode as plain text, token included; the block does not, so prefer the block on public pages.', 'entryvault' ); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Spam', 'entryvault' ); ?></th>
                    <td>
                        <label for="ev-skip-spam">
                            <input type="checkbox" id="ev-skip-spam" name="skip_spam" value="1" <?php checked( $ev_skip_spam ); ?> />
                            <?php esc_html_e( 'Leave out entries your form plugin marked as spam', 'entryvault' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'On by default. Some form plugins save spam and still pass it on (Gravity Forms, Fluent Forms, Forminator, Formidable, Ninja Forms with Akismet, Everest Forms, WS Form, Jetpack). With this on, those never reach your board. Spam stays in the form plugin, so nothing is lost. Untick to bring spam in too.', 'entryvault' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Data', 'entryvault' ); ?></th>
                    <td>
                        <label for="ev-delete-data-on-uninstall">
                            <input type="checkbox" id="ev-delete-data-on-uninstall" name="delete_data_on_uninstall"
                                   value="1" <?php checked( $ev_delete_on_uninstall ); ?> />
                            <?php esc_html_e( 'Delete all EntryVault data when the plugin is deleted', 'entryvault' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Off by default. Your submissions, tags and notes are kept if you deactivate or delete the plugin, or let a Pro license lapse. Tick this only if deleting the plugin should also remove the data.', 'entryvault' ); ?></p>
                    </td>
                </tr>
            </tbody></table>
            <p class="submit jnkui-card-foot">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'entryvault' ); ?></button>
                <span id="ev-general-msg" class="ev-inline-msg" role="status" aria-live="polite"></span>
            </p>
        </form>
    </section>

    <!-- Owners -->
    <section id="ev-tab-owners" class="ev-tab-panel" role="tabpanel" style="display:none;">
        <form id="ev-form-owners" class="jnkui-card">
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Who can own entries', 'entryvault' ); ?></th>
                    <td id="ev-owner-roles">
                        <?php if ( empty( $ev_all_roles ) ) : ?>
                            <p class="description"><?php esc_html_e( 'No roles are available.', 'entryvault' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $ev_all_roles as $slug => $name ) : ?>
                                <label class="ev-check" style="margin-bottom:6px;">
                                    <input type="checkbox" name="owner_roles[]" value="<?php echo esc_attr( $slug ); ?>"
                                        <?php checked( in_array( $slug, $ev_owner_roles, true ) ); ?> />
                                    <?php echo esc_html( $name ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'People with a ticked role can be made the owner of an entry. People who can edit posts are always included.', 'entryvault' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody></table>
            <p class="submit jnkui-card-foot">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'entryvault' ); ?></button>
            </p>
        </form>
    </section>

    <?php
    /**
     * Premium modules append their own tab panels here (each self-contained and
     * reusing window.EntryVaultSettings for REST/nonce plumbing). The free tab
     * switcher picks up any nav-tab / ev-tab-panel they add. Free build adds none.
     */
    do_action( 'entryvault_settings_panels', $ev_settings );

    ob_start();
    /**
     * Premium license/activation UI renders here (from pro/), so EntryVault's
     * settings and licensing live on one screen instead of a separate WP →
     * Settings page. Shown below the settings tabs; free build emits nothing.
     */
    do_action( 'entryvault_settings_after', $ev_settings );
    $ev_after = (string) ob_get_clean();
    if ( '' !== trim( $ev_after ) ) {
        echo '<div class="jnkui-card ev-ui-licence" style="margin-top:24px"><div class="jnkui-card-body">' . $ev_after . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup printed by the extension, captured as-is.
    }
    ?>
</div>
<?php \EntryVault\Admin_UI::close(); ?>

