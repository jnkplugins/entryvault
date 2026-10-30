<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Everest_Forms;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads Everest Forms' own tables directly.

/**
 * Past Everest Forms entries.
 *
 * Storage (Everest Forms 3.x, read from its source): `{prefix}evf_entries`
 * (entry_id, form_id, status, date_created in GMT, and `fields` = the JSON of
 * the same field_id => { name, value, type, meta_key } map live capture gets).
 * status is "publish", "spam" (its anti-spam checks), "pending" (admin approval),
 * "trash" or "draft" (a half-filled form kept by Save and Continue, which
 * Everest's own reports leave out too). Spam, trash and drafts are left out.
 * Entry ids match live capture.
 * Forms are `everest_form` posts.
 */
class Historical_Everest_Forms extends Provider_Base {

    public function source(): string {
        return 'everest-forms';
    }

    public function label(): string {
        return 'Everest Forms';
    }

    /**
     * Everest has evf_search_entries(), but it is gated on the current user's
     * capability (a background batch has no user) and its evf_get_entry() marks
     * entries as viewed when read. So: its table, checked first, Experimental.
     */
    protected function expected_schema(): array {
        return [ 'evf_entries' => [ 'entry_id', 'form_id', 'fields', 'status', 'date_created' ] ];
    }

    public function source_version(): string {
        return defined( 'EVF_VERSION' ) ? (string) EVF_VERSION : '';
    }

    protected function adapter_class(): string {
        return Adapter_Everest_Forms::class;
    }

    public function available(): bool {
        global $wpdb;
        return self::table_exists( $wpdb->prefix . 'evf_entries' );
    }

    public function forms(): array {
        global $wpdb;
        if ( ! $this->available() ) {
            return [];
        }
        $ids = array_map( 'intval', (array) $wpdb->get_col(
            "SELECT DISTINCT form_id FROM {$wpdb->prefix}evf_entries ORDER BY form_id ASC"
        ) );
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
        if ( ! $adapter instanceof Adapter_Everest_Forms || ! $this->available() ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entry_id, fields, date_created FROM {$wpdb->prefix}evf_entries
              WHERE form_id = %d AND status NOT IN ( 'spam', 'trash', 'draft' )
              ORDER BY entry_id ASC LIMIT %d OFFSET %d",
            $form_id,
            $limit,
            $offset
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $fields = $this->decode_json_map( (string) $row->fields );
            $id     = (string) (int) $row->entry_id;
            $out[]  = [
                'source_entry_id' => $id,
                // Everest writes date_created with current_time( 'mysql', true ):
                // UTC, as EntryVault stores it, so kept as is.
                'payload'         => $fields
                    ? $adapter->build_normalized( $form_id, $id, $fields, (string) $row->date_created )
                    : null,
            ];
        }
        return $out;
    }
}
