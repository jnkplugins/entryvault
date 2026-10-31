<?php
namespace EntryVault\Historical;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads Flamingo's posts, meta and terms directly so it works with Flamingo switched off.

/**
 * Contact Form 7 messages saved by Flamingo.
 *
 * Storage (Flamingo 2.x, read from its source): each message is a
 * `flamingo_inbound` post. Its field names are the keys of the `_fields` meta;
 * each value sits in `_field_{sanitize_key(name)}`. Messages are grouped by a
 * `flamingo_inbound_channel` term; Contact Form 7 records each form's channel
 * term id in the form's `_flamingo` meta (older installs: a child term of
 * "contact-form-7" whose slug is the form's post_name).
 *
 * Imported: published messages only. Spam (post status `flamingo-spam`, or an
 * Akismet spam verdict in `_akismet`) and trashed messages are left out.
 */
class Historical_Flamingo extends Historical_CF7_Base {

    private const POST_TYPE = 'flamingo_inbound';
    private const TAXONOMY  = 'flamingo_inbound_channel';

    public function key(): string {
        return 'flamingo';
    }

    public function label(): string {
        /* translators: %s: the plugin that saved the Contact Form 7 messages (Flamingo or CFDB7). */
        return sprintf( __( 'Contact Form 7 (saved by %s)', 'entryvault' ), 'Flamingo' );
    }

    /**
     * Flamingo's public message class, when Flamingo is active. Filterable so
     * tests can hand in a stand-in.
     */
    private function api_class(): string {
        $class = (string) apply_filters( 'entryvault_flamingo_message_class', 'Flamingo_Inbound_Message' );
        return class_exists( $class ) && method_exists( $class, 'find' ) ? $class : '';
    }

    /**
     * Through Flamingo_Inbound_Message::find() while Flamingo is active. With
     * Flamingo switched off its messages are still there, and are read from the
     * posts table (Experimental).
     */
    public function access(): string {
        return '' !== $this->api_class() ? self::ACCESS_API : self::ACCESS_DIRECT;
    }

    public function source_version(): string {
        return defined( 'FLAMINGO_VERSION' ) ? (string) FLAMINGO_VERSION : '';
    }

    public function schema_check(): array {
        global $wpdb;
        if ( '' !== $this->api_class() ) {
            return [ 'ok' => true, 'missing' => [] ];
        }
        $posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE ) );
        $keyed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fields' WHERE p.post_type = %s",
            self::POST_TYPE
        ) );
        $ok = 0 === $posts || $keyed > 0;
        return [ 'ok' => $ok, 'missing' => $ok ? [] : [ 'postmeta._fields' ] ];
    }

    public function count( int $form_id ): ?int {
        global $wpdb;
        $slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $form_id ) );
        $term = $this->channel_term( $form_id, $slug );
        if ( ! $term ) {
            return 0;
        }
        $class = $this->api_class();
        if ( '' !== $class && method_exists( $class, 'count' ) ) {
            return (int) $class::count( [ 'channel_id' => $term, 'post_status' => 'publish' ] );
        }
        return $this->count_messages( $term );
    }

    /** Flamingo messages exist, whether or not Flamingo is active now. */
    public function available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1",
            self::POST_TYPE
        ) );
    }

    public function forms(): array {
        $out = [];
        foreach ( $this->cf7_forms() as $form ) {
            $term = $this->channel_term( (int) $form->ID, (string) $form->post_name );
            if ( ! $term || 0 === $this->count_messages( $term ) ) {
                continue;
            }
            $out[] = [
                'id'    => (int) $form->ID,
                'title' => '' !== (string) $form->post_title ? (string) $form->post_title : 'Form #' . $form->ID,
            ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        global $wpdb;
        if ( ! $this->adapter() ) {
            return [];
        }
        $slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $form_id ) );
        $term = $this->channel_term( $form_id, $slug );
        if ( ! $term ) {
            return [];
        }
        $class = $this->api_class();
        if ( '' !== $class ) {
            return $this->entries_via_api( $class, $form_id, $term, $offset, $limit );
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_date FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
              WHERE tt.term_id = %d AND tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'
              ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            $term,
            self::TAXONOMY,
            self::POST_TYPE,
            $limit,
            $offset
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $post_id = (int) $row->ID;
            $akismet = get_post_meta( $post_id, '_akismet', true );
            if ( is_array( $akismet ) && ! empty( $akismet['spam'] ) ) {
                // Read but not imported: keeps the offset moving.
                $out[] = [ 'source_entry_id' => 'flamingo_' . $post_id, 'payload' => null ];
                continue;
            }
            $out[] = [
                'source_entry_id' => 'flamingo_' . $post_id,
                // flamingo_inbound is a post: post_date is the site's clock, so convert it to UTC.
                'payload'         => $this->payload( $form_id, 'flamingo_' . $post_id, $this->posted( $post_id ), \EntryVault\Dates::to_utc( (string) $row->post_date ) ),
            ];
        }
        return $out;
    }

    /**
     * One batch through Flamingo_Inbound_Message::find().
     *
     * @return array<int,array{source_entry_id:string,payload:?array}>
     */
    private function entries_via_api( string $class, int $form_id, int $term, int $offset, int $limit ): array {
        $messages = $class::find( [
            'channel_id'     => $term,
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'post_status'    => 'publish',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );
        $out = [];
        foreach ( (array) $messages as $m ) {
            $post_id = (int) ( $m->id ?? 0 );
            if ( ! $post_id ) {
                continue;
            }
            $id = 'flamingo_' . $post_id;
            if ( ! empty( $m->spam ) || ( is_array( $m->akismet ?? null ) && ! empty( $m->akismet['spam'] ) ) ) {
                $out[] = [ 'source_entry_id' => $id, 'payload' => null ];
                continue;
            }
            $post  = get_post( $post_id );
            $out[] = [
                'source_entry_id' => $id,
                // A post: post_date is the site's clock, so convert it to UTC.
                'payload'         => $this->payload( $form_id, $id, is_array( $m->fields ?? null ) ? $m->fields : [], \EntryVault\Dates::to_utc( $post ? (string) $post->post_date : '' ) ),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> field name => value, as Flamingo stored it. */
    private function posted( int $post_id ): array {
        $names  = get_post_meta( $post_id, '_fields', true );
        $posted = [];
        foreach ( is_array( $names ) ? array_keys( $names ) : [] as $name ) {
            $meta_key = sanitize_key( '_field_' . $name );
            $posted[ (string) $name ] = metadata_exists( 'post', $post_id, $meta_key )
                ? get_post_meta( $post_id, $meta_key, true )
                : ( $names[ $name ] ?? '' );
        }
        return $posted;
    }

    /** The channel term id holding one CF7 form's messages, or 0. */
    private function channel_term( int $form_id, string $form_slug ): int {
        global $wpdb;
        $meta = get_post_meta( $form_id, '_flamingo', true );
        if ( is_array( $meta ) && ! empty( $meta['channel'] ) ) {
            return (int) $meta['channel'];
        }
        if ( '' === $form_slug ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
              WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1",
            self::TAXONOMY,
            $form_slug
        ) );
    }

    private function count_messages( int $term_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
              WHERE tt.term_id = %d AND tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'",
            $term_id,
            self::TAXONOMY,
            self::POST_TYPE
        ) );
    }
}
