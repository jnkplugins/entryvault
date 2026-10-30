<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Metform;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads MetForm's posts and meta directly.

/**
 * Past MetForm entries (saved when the form's "Store entries" is on).
 *
 * Storage (MetForm 3.x/4.x, read from its source): each entry is a
 * `metform-entry` post with `metform_entries__form_id` (the `metform-form` post
 * id), `metform_entries__form_data` (name => value) and
 * `metform_entries__file_upload_new` (name => uploaded files). Only published
 * entries are imported. Live capture usually records the same post id; when it
 * could not (it then mints an "mf_" id), the entry is matched by content.
 */
class Historical_Metform extends Provider_Base {

    public function source(): string {
        return 'metform';
    }

    public function label(): string {
        return 'MetForm';
    }

    /**
     * MetForm keeps entries as "metform-entry" posts with its own meta keys.
     * Check that the entries still carry the form id where we look for it.
     */
    public function schema_check(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only probe.
        $posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'metform-entry'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only probe.
        $keyed = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'metform_entries__form_id' WHERE p.post_type = 'metform-entry'" );
        $ok    = 0 === $posts || $keyed > 0;
        return [ 'ok' => $ok, 'missing' => $ok ? [] : [ 'postmeta.metform_entries__form_id' ] ];
    }

    public function source_version(): string {
        return defined( 'METFORM_VERSION' ) ? (string) METFORM_VERSION : ( class_exists( '\\MetForm\\Plugin' ) && method_exists( '\\MetForm\\Plugin', 'version' ) ? (string) \MetForm\Plugin::version() : '' );
    }

    protected function adapter_class(): string {
        return Adapter_Metform::class;
    }

    public function available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = 'metform-entry' LIMIT 1" );
    }

    public function is_already_captured( array $payload ): bool {
        return $this->matches_existing_entry( $payload );
    }

    public function forms(): array {
        global $wpdb;
        $ids = array_map( 'intval', (array) $wpdb->get_col(
            "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m
               INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE m.meta_key = 'metform_entries__form_id' AND p.post_type = 'metform-entry'"
        ) );
        sort( $ids );
        $out = [];
        foreach ( $ids as $id ) {
            if ( $id <= 0 ) {
                continue;
            }
            $title = (string) get_the_title( $id );
            $out[] = [ 'id' => $id, 'title' => '' !== $title ? $title : 'Form #' . $id ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        global $wpdb;
        $adapter = $this->adapter();
        if ( ! $adapter instanceof Adapter_Metform ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_date FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'metform_entries__form_id'
              WHERE p.post_type = 'metform-entry' AND p.post_status = 'publish' AND m.meta_value = %s
              ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            (string) $form_id,
            $limit,
            $offset
        ) );
        if ( ! $rows ) {
            return [];
        }
        $map   = $adapter->field_map( $form_id );
        $title = (string) get_the_title( $form_id );
        $out   = [];
        foreach ( $rows as $row ) {
            $post_id = (int) $row->ID;
            $data    = get_post_meta( $post_id, 'metform_entries__form_data', true );
            $files   = get_post_meta( $post_id, 'metform_entries__file_upload_new', true );
            $fields  = is_array( $data ) ? $adapter->build_fields( $data, is_array( $files ) ? $files : [], $map ) : [];
            if ( ! $fields ) {
                $out[] = [ 'source_entry_id' => (string) $post_id, 'payload' => null ];
                continue;
            }
            $payload = $adapter->build_entry_payload( (string) $form_id, (string) $post_id, $fields, $title, \EntryVault\Dates::to_utc( (string) $row->post_date ) ?: null ); // metform-entry is a post: post_date is the site's clock.
            $out[]   = [ 'source_entry_id' => (string) $post_id, 'payload' => $payload ];
        }
        return $out;
    }
}
