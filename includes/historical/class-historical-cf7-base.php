<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_CF7;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads WordPress core tables for Contact Form 7 forms.

/**
 * Shared base for importers that read Contact Form 7 messages another plugin
 * stored (Flamingo, CFDB7). Entries land as normal Contact Form 7 entries
 * (source contact-form-7, the CF7 form's post id), so they sit next to the ones
 * EntryVault captured live, and automations/routing treat them the same.
 *
 * CF7 itself stores nothing, so live capture invents its own entry id. A
 * message EntryVault already captured live is therefore found by content
 * (same form, email and answers within ten minutes), not by id.
 */
abstract class Historical_CF7_Base extends Provider_Base {

    public function source(): string {
        return 'contact-form-7';
    }

    protected function adapter_class(): string {
        return Adapter_CF7::class;
    }

    public function is_already_captured( array $payload ): bool {
        return $this->matches_existing_entry( $payload );
    }

    /**
     * CF7 forms on this site, even when Contact Form 7 itself is switched off.
     *
     * @return array<int,object{ID:string,post_title:string,post_name:string}>
     */
    protected function cf7_forms(): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT ID, post_title, post_name FROM {$wpdb->posts}
              WHERE post_type = 'wpcf7_contact_form' AND post_status <> 'trash' ORDER BY ID ASC"
        );
    }

    protected function cf7_form_title( int $form_id ): string {
        $title = function_exists( 'get_the_title' ) ? (string) get_the_title( $form_id ) : '';
        return $title;
    }

    /**
     * Turn one stored message into the payload live capture would have built.
     *
     * @param array<string,mixed> $posted Field name => value.
     */
    protected function payload( int $form_id, string $entry_id, array $posted, string $created_at ): ?array {
        $adapter = $this->adapter();
        if ( ! $adapter instanceof Adapter_CF7 ) {
            return null;
        }
        $tag_map = [];
        if ( class_exists( '\\WPCF7_ContactForm' ) ) {
            $form = \WPCF7_ContactForm::get_instance( $form_id );
            if ( $form ) {
                $tag_map = $adapter->build_tag_map( $form );
            }
        }
        $payload = $adapter->build_normalized( $form_id, $this->cf7_form_title( $form_id ), $posted, $tag_map, $created_at );
        $payload['source_entry_id'] = $entry_id;
        return $payload;
    }
}
