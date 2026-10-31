<?php
/**
 * Frees this site's seat on the licensing server when the plugin is deleted.
 *
 * Included from the plugin's uninstall.php, which sets $uninstall_seat_slug
 * (the product_slug it passes to the client) and, if not the default,
 * $uninstall_seat_api. Deactivating a plugin keeps the seat; deleting it
 * frees it, whether or not the plugin's data is deleted too (retest, 31 Oct 2026).
 *
 * Self-contained on purpose: during uninstall another JnK plugin may have loaded
 * an older copy of the client class that has no such method.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

$uninstall_seat_slug = sanitize_key((string) ($uninstall_seat_slug ?? ''));
$uninstall_seat_key  = $uninstall_seat_slug !== ''
    ? (string) get_option('licensorpro_' . $uninstall_seat_slug . '_key', '')
    : '';
if ($uninstall_seat_key !== '') {
    $uninstall_seat_api = untrailingslashit((string) apply_filters(
        'licensorpro_api_url',
        (string) ($uninstall_seat_api ?? 'https://api.jnkplugins.com'),
        $uninstall_seat_slug
    ));
    if ($uninstall_seat_api !== '') {
        // Short: a slow licensing server must not hang the Plugins screen.
        $uninstall_seat_resp = wp_remote_post($uninstall_seat_api . '/api/v1/license/deactivate', [
            'timeout'   => 5,
            'sslverify' => true,
            'headers'   => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'      => wp_json_encode(['license_key' => $uninstall_seat_key, 'site_url' => home_url('/')]),
        ]);
        $uninstall_seat_data = is_wp_error($uninstall_seat_resp) ? null
            : json_decode((string) wp_remote_retrieve_body($uninstall_seat_resp), true);
        if (is_array($uninstall_seat_data) && !empty($uninstall_seat_data['ok'])) {
            // The seat is gone, so the key stored here no longer activates this
            // site. Kept, a reinstall showed "active" until the daily check and
            // then switched Pro off saying "Renew". Without it the licence box
            // asks for the key, as on a new site. The "ever licensed" marker
            // stays. If the server could not be reached the seat is still held,
            // and so is the key.
            foreach (['_key', '_license', '_remote', '_lapse'] as $uninstall_seat_suffix) {
                delete_option('licensorpro_' . $uninstall_seat_slug . $uninstall_seat_suffix);
            }
            delete_transient('licensorpro_' . $uninstall_seat_slug . '_validate');
            delete_transient('licensorpro_' . $uninstall_seat_slug . '_update');
        }
    }
}
