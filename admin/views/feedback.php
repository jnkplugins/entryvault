<?php
/**
 * EntryVault — "Feedback & Support" page (free core; shows in Lite and Pro).
 *
 * Sends the plugin author feedback / bug reports through the site's own wp_mail().
 * No external HTTP. See EntryVault\Feedback for the admin-post handler.
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( \EntryVault\Feedback::CAPABILITY ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'entryvault' ) );
}

$ev_docs_url     = 'https://jnkplugins.com/entryvault/';
$ev_types        = \EntryVault\Feedback::types();
$ev_current_user = wp_get_current_user();
$ev_status       = isset( $_GET['entryvault_feedback'] ) ? sanitize_key( wp_unslash( (string) $_GET['entryvault_feedback'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
?>
<?php
\EntryVault\Admin_UI::open(
    __( 'Help and feedback', 'entryvault' ),
    __( 'Found a bug, have an idea, or just want to say hello? Send a note straight to the people who make EntryVault.', 'entryvault' ),
    '<a class="button" href="' . esc_url( $ev_docs_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read the guides', 'entryvault' ) . '</a>'
);
?>
<div class="entryvault-feedback">

    <?php if ( 'sent' === $ev_status ) : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e( 'Thanks! Your feedback was sent to the EntryVault author.', 'entryvault' ); ?></p>
        </div>
    <?php elseif ( 'ratelimited' === $ev_status ) : ?>
        <div class="notice notice-warning inline">
            <p><?php esc_html_e( 'You’ve sent several messages recently. Please try again in a little while.', 'entryvault' ); ?></p>
        </div>
    <?php elseif ( 'error' === $ev_status ) : ?>
        <div class="notice notice-error inline">
            <p><?php esc_html_e( 'Sorry, your feedback could not be sent. Please check your message and your site’s email configuration, then try again.', 'entryvault' ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jnkui-card" style="max-width:760px;">
        <?php wp_nonce_field( 'entryvault_feedback' ); ?>
        <input type="hidden" name="action" value="entryvault_send_feedback">

        <?php /* Honeypot: visually hidden, never filled by a real user. */ ?>
        <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:1px;width:1px;overflow:hidden;">
            <label for="entryvault_website"><?php esc_html_e( 'Website', 'entryvault' ); ?></label>
            <input type="text" id="entryvault_website" name="entryvault_website" tabindex="-1" autocomplete="off" value="">
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="entryvault_type"><?php esc_html_e( 'What is it about?', 'entryvault' ); ?></label></th>
                <td>
                    <select name="entryvault_type" id="entryvault_type">
                        <?php foreach ( $ev_types as $ev_key => $ev_label ) : ?>
                            <option value="<?php echo esc_attr( $ev_key ); ?>"><?php echo esc_html( $ev_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="entryvault_message"><?php esc_html_e( 'Message', 'entryvault' ); ?></label></th>
                <td>
                    <textarea name="entryvault_message" id="entryvault_message" rows="7" class="large-text" required></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="entryvault_reply_email"><?php esc_html_e( 'Your email (optional, so we can reply)', 'entryvault' ); ?></label></th>
                <td>
                    <input type="email" name="entryvault_reply_email" id="entryvault_reply_email" class="regular-text"
                           placeholder="<?php echo esc_attr( $ev_current_user->user_email ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Site details', 'entryvault' ); ?></th>
                <td>
                    <label for="entryvault_site_info">
                        <input type="checkbox" name="entryvault_site_info" id="entryvault_site_info" value="1" checked>
                        <?php esc_html_e( 'Include basic site details to help us find the problem', 'entryvault' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Sends: WordPress version, PHP version, EntryVault version, active theme name and your site URL. No personal data.', 'entryvault' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <p class="jnkui-card-foot">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'entryvault' ); ?></button>
            <span class="description"><?php esc_html_e( 'Your message goes out through your site’s own email.', 'entryvault' ); ?></span>
        </p>
    </form>
</div>
<?php \EntryVault\Admin_UI::close(); ?>
