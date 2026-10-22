<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

$rest_root = esc_url_raw( rest_url( 'entryvault/v1/' ) );
$nonce     = wp_create_nonce( 'wp_rest' );
$settings  = get_option( 'entryvault_settings', [] );
$auto_map  = isset( $settings['auto_tag_by_form'] ) && is_array( $settings['auto_tag_by_form'] )
    ? $settings['auto_tag_by_form']
    : [];
?>
<?php
\EntryVault\Admin_UI::open(
    __( 'Tags', 'entryvault' ),
    __( 'Labels you can put on any entry, like "Hot lead" or "Wholesale". Use them to filter the board and the Submissions list.', 'entryvault' )
);
?>
<div id="ev-tags-app">
    <div class="jnkui-grid-2">
        <div class="jnkui-card">
            <div class="jnkui-card-head"><div><h2><?php echo esc_html__( 'Your tags', 'entryvault' ); ?></h2>
            <p><?php esc_html_e( 'Edit a tag to rename it or change its colour. Entries keep their tags.', 'entryvault' ); ?></p></div></div>
            <div class="jnkui-table-wrap">
                <table class="jnkui-table" id="ev-tags-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'entryvault' ); ?></th>
                            <th><?php esc_html_e( 'Short name', 'entryvault' ); ?></th>
                            <th><?php esc_html_e( 'Colour', 'entryvault' ); ?></th>
                            <th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'entryvault' ); ?></span></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="jnkui-card">
            <div class="jnkui-card-head"><div><h2 id="ev-tag-form-title"><?php echo esc_html__( 'Add a tag', 'entryvault' ); ?></h2>
            <p><?php esc_html_e( 'Pick a name and a colour. You can also create tags from an entry on the board.', 'entryvault' ); ?></p></div></div>
            <div class="jnkui-card-body">
                <form id="ev-tag-form">
                    <input type="hidden" id="ev-tag-id" value="" />
                    <div class="ev-tag-form">
                        <label class="jnkui-field" for="ev-tag-name"><?php esc_html_e( 'Name', 'entryvault' ); ?>
                            <input type="text" id="ev-tag-name" class="regular-text" required />
                        </label>
                        <label class="jnkui-field" for="ev-tag-color"><?php esc_html_e( 'Colour', 'entryvault' ); ?>
                            <input type="color" id="ev-tag-color" value="#888888" />
                        </label>
                    </div>
                    <p class="ev-ui-actions" style="margin-top:16px">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save tag', 'entryvault' ); ?></button>
                        <button type="button" class="button" id="ev-tag-cancel" style="display:none;"><?php esc_html_e( 'Cancel', 'entryvault' ); ?></button>
                        <span id="ev-tag-msg" role="status" aria-live="polite"></span>
                    </p>
                </form>
            </div>
        </div>
    </div>

    <?php
    /**
     * Extension point: premium modules append UI after the Tags list (e.g. the
     * "Auto-tag by Form" rules, which configure a premium-only feature and POST
     * to a route the free build does not register). The free build renders none.
     */
    do_action( 'entryvault_tags_after', $settings, $auto_map );
    ?>
</div>
<?php \EntryVault\Admin_UI::close(); ?>

<?php
// The tags list/create/edit/delete UI lives in the enqueued admin/assets/js/tags.js
// (enqueued on the Tags screen; REST root, nonce + i18n via EntryVaultTagsBoot).
