<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Ninja_Forms;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads Ninja Forms' posts and tables directly.

/**
 * Past Ninja Forms submissions.
 *
 * Storage (Ninja Forms 3.x, read from its source): each submission is an
 * `nf_sub` post with `_form_id` meta and one `_field_{field id}` meta per
 * answer. Field labels, keys and types are in `{prefix}nf3_fields`
 * (parent_id = form id); forms are in `{prefix}nf3_forms`. Only published
 * submissions are imported (trashed ones are left out). The post id is the
 * same "sub_id" live capture records, so entries already on the board are
 * skipped by id.
 */
class Historical_Ninja_Forms extends Provider_Base {

    public function source(): string {
        return 'ninja-forms';
    }

    public function label(): string {
        return 'Ninja Forms';
    }

    protected function expected_schema(): array {
        return [
            'nf3_forms'  => [ 'id', 'title' ],
            'nf3_fields' => [ 'id', 'label', 'key', 'type', 'parent_id', 'order' ],
        ];
    }

    public function source_version(): string {
        return class_exists( '\\Ninja_Forms' ) && defined( 'Ninja_Forms::VERSION' ) ? (string) \Ninja_Forms::VERSION : '';
    }

    protected function adapter_class(): string {
        return Adapter_Ninja_Forms::class;
    }

    public function available(): bool {
        global $wpdb;
        return self::table_exists( $wpdb->prefix . 'nf3_forms' ) && self::table_exists( $wpdb->prefix . 'nf3_fields' );
    }

    public function forms(): array {
        global $wpdb;
        if ( ! $this->available() ) {
            return [];
        }
        $out = [];
        foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}nf3_forms ORDER BY id ASC" ) as $r ) {
            $out[] = [ 'id' => (int) $r->id, 'title' => '' !== (string) $r->title ? (string) $r->title : 'Form #' . $r->id ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        global $wpdb;
        $adapter = $this->adapter();
        if ( ! $adapter instanceof Adapter_Ninja_Forms || ! $this->available() ) {
            return [];
        }
        $subs = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_date FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_form_id'
              WHERE p.post_type = 'nf_sub' AND p.post_status = 'publish' AND m.meta_value = %s
              ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            (string) $form_id,
            $limit,
            $offset
        ) );
        if ( ! $subs ) {
            return [];
        }
        $defs = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label, `key`, type FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d ORDER BY `order` ASC, id ASC",
            $form_id
        ) );
        // Choice options (value => label) live in nf3_field_meta.
        $options = [];
        if ( $defs && self::table_exists( $wpdb->prefix . 'nf3_field_meta' ) ) {
            $ids = implode( ',', array_map( static fn( $d ) => (int) $d->id, $defs ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer id list.
            foreach ( (array) $wpdb->get_results( "SELECT parent_id, meta_value FROM {$wpdb->prefix}nf3_field_meta WHERE meta_key = 'options' AND parent_id IN ({$ids})" ) as $m ) {
                $options[ (int) $m->parent_id ] = $m->meta_value;
            }
        }

        $out = [];
        foreach ( $subs as $sub ) {
            $post_id = (int) $sub->ID;
            $raw     = [];
            foreach ( $defs as $def ) {
                $meta_key = '_field_' . (int) $def->id;
                if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
                    continue;
                }
                $raw[] = [
                    'key'   => (string) $def->key,
                    'label' => (string) $def->label,
                    'type'  => (string) $def->type,
                    'value'   => get_post_meta( $post_id, $meta_key, true ),
                    'options' => $options[ (int) $def->id ] ?? null,
                ];
            }
            $out[] = [
                'source_entry_id' => (string) $post_id,
                // nf_sub is a post: post_date is the site's clock, so convert it to UTC.
                'payload'         => $adapter->build_normalized( $form_id, (string) $post_id, $raw, \EntryVault\Dates::to_utc( (string) $sub->post_date ) ?: null ),
            ];
        }
        return $out;
    }
}
