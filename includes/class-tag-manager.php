<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * Tag CRUD + attach/detach to submissions, plus auto-tag-by-form helper.
 *
 * Storage: wp_entryvault_tags, wp_entryvault_submission_tags.
 * All write methods return int|true on success or WP_Error on failure.
 */
class Tag_Manager {

    private const COLOR_REGEX = '/^#[0-9a-f]{6}$/i';

    /* ---------------------------------------------------------------------
     * CRUD
     * ------------------------------------------------------------------ */

    public static function create( $name, $color = null ) {
        global $wpdb;

        $name = is_string( $name ) ? trim( $name ) : '';
        if ( $name === '' ) {
            return new \WP_Error( 'invalid_name', 'Tag name is required.' );
        }
        $name = substr( $name, 0, 100 );

        if ( $color === null || $color === '' ) {
            $color = '#888888';
        }
        $color = (string) $color;
        if ( ! preg_match( self::COLOR_REGEX, $color ) ) {
            return new \WP_Error( 'invalid_color', 'Invalid color value.' );
        }

        $base = sanitize_title( $name );
        if ( $base === '' ) {
            $base = 'tag';
        }
        $slug = self::unique_slug( $base );

        $ok = $wpdb->insert( $wpdb->prefix . 'entryvault_tags', [
            'name'  => $name,
            'slug'  => $slug,
            'color' => $color,
        ] );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', 'Could not insert tag.' );
        }
        return (int) $wpdb->insert_id;
    }

    public static function update( $id, array $args ) {
        global $wpdb;
        $id    = (int) $id;
        $table = $wpdb->prefix . 'entryvault_tags';

        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
        if ( ! $existing ) {
            return new \WP_Error( 'not_found', 'Tag not found.' );
        }

        $update = [];
        if ( array_key_exists( 'name', $args ) ) {
            $name = trim( (string) $args['name'] );
            if ( $name === '' ) {
                return new \WP_Error( 'invalid_name', 'Tag name cannot be empty.' );
            }
            $update['name'] = substr( $name, 0, 100 );
        }
        if ( array_key_exists( 'slug', $args ) ) {
            $slug = sanitize_title( (string) $args['slug'] );
            if ( $slug === '' ) {
                return new \WP_Error( 'invalid_slug', 'Invalid slug.' );
            }
            if ( $slug !== $existing['slug'] ) {
                $slug = self::unique_slug( $slug, $id );
            }
            $update['slug'] = $slug;
        }
        if ( array_key_exists( 'color', $args ) ) {
            $color = (string) $args['color'];
            if ( ! preg_match( self::COLOR_REGEX, $color ) ) {
                return new \WP_Error( 'invalid_color', 'Invalid color value.' );
            }
            $update['color'] = $color;
        }

        if ( empty( $update ) ) {
            return $id;
        }

        $ok = $wpdb->update( $table, $update, [ 'id' => $id ] );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', 'Could not update tag.' );
        }
        return $id;
    }

    public static function delete( $id ) {
        global $wpdb;
        $id = (int) $id;

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}entryvault_tags WHERE id = %d",
            $id
        ) );
        if ( ! $exists ) {
            return new \WP_Error( 'not_found', 'Tag not found.' );
        }

        $wpdb->delete( $wpdb->prefix . 'entryvault_submission_tags', [ 'tag_id' => $id ] );
        $ok = $wpdb->delete( $wpdb->prefix . 'entryvault_tags', [ 'id' => $id ] );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', 'Could not delete tag.' );
        }
        return true;
    }

    public static function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}entryvault_tags ORDER BY name ASC",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /* ---------------------------------------------------------------------
     * Attach / Detach
     * ------------------------------------------------------------------ */

    public static function attach( $submission_id, $tag_id ) {
        global $wpdb;
        $submission_id = (int) $submission_id;
        $tag_id        = (int) $tag_id;
        if ( $submission_id <= 0 || $tag_id <= 0 ) {
            return new \WP_Error( 'invalid_args', 'submission_id and tag_id are required.' );
        }

        $tag = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}entryvault_tags WHERE id = %d",
            $tag_id
        ), ARRAY_A );
        if ( ! $tag ) {
            return new \WP_Error( 'not_found', 'Tag not found.' );
        }

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}entryvault_submission_tags WHERE submission_id = %d AND tag_id = %d",
            $submission_id,
            $tag_id
        ) );
        if ( $exists ) {
            return true;
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'entryvault_submission_tags', [
            'submission_id' => $submission_id,
            'tag_id'        => $tag_id,
        ] );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', 'Could not attach tag.' );
        }

        Timeline::log( $submission_id, 'tag_added', null, [
            'tag_id'   => $tag_id,
            'tag_name' => (string) ( $tag['name'] ?? '' ),
        ] );

        do_action( 'entryvault_tag_added', $submission_id, $tag_id );

        return true;
    }

    public static function detach( $submission_id, $tag_id ) {
        global $wpdb;
        $submission_id = (int) $submission_id;
        $tag_id        = (int) $tag_id;
        if ( $submission_id <= 0 || $tag_id <= 0 ) {
            return new \WP_Error( 'invalid_args', 'submission_id and tag_id are required.' );
        }

        $tag = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}entryvault_tags WHERE id = %d",
            $tag_id
        ), ARRAY_A );

        $deleted = $wpdb->delete( $wpdb->prefix . 'entryvault_submission_tags', [
            'submission_id' => $submission_id,
            'tag_id'        => $tag_id,
        ] );

        if ( $deleted ) {
            Timeline::log( $submission_id, 'tag_removed', null, [
                'tag_id'   => $tag_id,
                'tag_name' => (string) ( $tag['name'] ?? '' ),
            ] );
        }
        return true;
    }

    public static function for_submission( $submission_id ): array {
        global $wpdb;
        $submission_id = (int) $submission_id;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$wpdb->prefix}entryvault_tags t
              INNER JOIN {$wpdb->prefix}entryvault_submission_tags st ON st.tag_id = t.id
              WHERE st.submission_id = %d
              ORDER BY t.name ASC",
            $submission_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function unique_slug( string $base, int $ignore_id = 0 ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'entryvault_tags';
        $slug  = $base;
        $i     = 2;
        while ( true ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE slug = %s AND id <> %d",
                $table,
                $slug,
                $ignore_id
            ) );
            if ( ! $exists ) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }
}
