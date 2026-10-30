<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Trinity_Forms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads the source plugin's custom tables directly.

class Historical_Trinity_Forms extends Provider_Base {

    public function source(): string {
        return "trinity-forms";
    }

    public function label(): string {
        return "Trinity Forms";
    }

    /** Our own plugin: its tables change only together with this reader. */
    public function access(): string {
        return self::ACCESS_OWN;
    }

    public function source_version(): string {
        return defined( 'TRINITY_FORMS_VERSION' ) ? (string) TRINITY_FORMS_VERSION : '';
    }

    protected function adapter_class(): string {
        return Adapter_Trinity_Forms::class;
    }

    /**
     * An entry Trinity imported from another plugin is already here when the
     * entry it was copied from is (persona run 30 Oct: 64 cards for 33 people).
     */
    public function is_already_captured( array $payload ): bool {
        return \EntryVault\Import_Dedupe::origin_copy_of( $payload ) > 0;
    }

    public function available(): bool {
        $adapter = $this->adapter();
        return $adapter ? $adapter->is_active() : false;
    }

    public function forms(): array {
        $posts = get_posts( [
            "post_type"      => "trinity_forms_form",
            "post_status"    => [ "publish", "draft" ],
            "numberposts"    => -1,
            "orderby"        => "title",
            "order"          => "ASC",
            "suppress_filters" => false,
        ] );
        $out = [];
        foreach ( (array) $posts as $post ) {
            $out[] = [
                "id"    => (int) $post->ID,
                "title" => (string) ( $post->post_title !== "" ? $post->post_title : ( "Form #" . $post->ID ) ),
            ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        global $wpdb;
        $adapter = $this->adapter();
        if ( ! $adapter || ! $this->available() ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant prefix.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}trinity_forms_entries
             WHERE form_id = %d AND status NOT IN ( 'spam', 'trash', 'partial' )
             ORDER BY id ASC LIMIT %d OFFSET %d",
            (int) $form_id, (int) $limit, (int) $offset
        ) );
        $out = [];
        foreach ( (array) $ids as $id ) {
            $normalized = $adapter->build_normalized( (int) $id );
            if ( $normalized === null ) {
                continue;
            }
            $out[] = [
                "source_entry_id" => (string) $id,
                "payload"         => $normalized,
            ];
        }
        return $out;
    }
}
