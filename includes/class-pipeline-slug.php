<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tiny lookups on the plugin's own pipelines table.

/**
 * Pipeline slugs that every public lookup can read back.
 *
 * The shortcode, the block and the public REST routes all pass a pipeline slug
 * through sanitize_key(), which keeps only a-z, 0-9, "-" and "_". A slug made
 * with sanitize_title() from an Arabic or Korean name is percent-encoded bytes
 * ("%d8%a7..."), so sanitize_key() turned it into something no row matched and
 * the table said "Pipeline not found". Slugs are now ASCII: transliterated
 * where the server can, else "pipeline-<id>". Sites that already have an
 * encoded slug get it replaced once, and the old value keeps working as an
 * alias so a shortcode already on a page still finds its pipeline.
 */
class Pipeline_Slug {

    /** Option holding [ sanitize_key( old slug ) => new slug ]. */
    public const ALIAS_OPTION = 'entryvault_pipeline_slug_aliases';

    /**
     * An ASCII slug for a name, or '' when nothing usable is left.
     */
    public static function from_text( string $text ): string {
        $text = remove_accents( $text );
        if ( function_exists( 'transliterator_transliterate' ) && preg_match( '/[^\x00-\x7F]/', $text ) ) {
            $latin = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $text );
            if ( is_string( $latin ) ) {
                // Marks such as the Arabic ayn ("ʿ") survive Latin-ASCII; drop them.
                $text = (string) preg_replace( '/[^\x00-\x7F]/', '', $latin );
            }
        }
        $slug = sanitize_title( $text );
        if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $slug ) ) {
            return '';
        }
        return rtrim( substr( $slug, 0, 60 ), '-' );
    }

    /** Whether a stored slug is one sanitize_key() reads back unchanged. */
    public static function is_safe( string $slug ): bool {
        return '' !== $slug && sanitize_key( $slug ) === $slug;
    }

    /** First free slug of "$base", "$base-2", "$base-3"... */
    public static function unique( string $base, int $ignore_id = 0 ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'entryvault_pipelines';
        $slug  = $base;
        $i     = 2;
        while ( true ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE slug = %s AND id <> %d',
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

    /**
     * The slug for pipeline $id: from $text when that gives an ASCII slug,
     * else "pipeline-<id>". Always unique.
     */
    public static function for_pipeline( string $text, int $id ): string {
        $base = self::from_text( $text );
        if ( '' === $base ) {
            $base = 'pipeline-' . $id;
        }
        return self::unique( $base, $id );
    }

    /** The current slug an old (encoded) slug was replaced with, or ''. */
    public static function alias( string $key ): string {
        $key = sanitize_key( $key );
        if ( '' === $key ) {
            return '';
        }
        $map = get_option( self::ALIAS_OPTION, [] );
        return is_array( $map ) && isset( $map[ $key ] ) ? (string) $map[ $key ] : '';
    }

    /**
     * Replace every stored slug that sanitize_key() would change. Idempotent:
     * a site with only ASCII slugs is left alone.
     *
     * @return int Number of pipelines changed.
     */
    public static function migrate(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'entryvault_pipelines';
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, slug FROM %i ORDER BY id ASC', $table ), ARRAY_A );
        $map   = get_option( self::ALIAS_OPTION, [] );
        $map   = is_array( $map ) ? $map : [];
        $done  = 0;
        foreach ( $rows as $row ) {
            $old = (string) $row['slug'];
            if ( self::is_safe( $old ) ) {
                continue;
            }
            $id  = (int) $row['id'];
            $new = self::for_pipeline( (string) $row['name'], $id );
            if ( false === $wpdb->update( $table, [ 'slug' => $new ], [ 'id' => $id ] ) ) {
                continue;
            }
            // A shortcode printed before this release carries the old slug;
            // every lookup sanitize_key()s it first, so key the alias the same way.
            $key = sanitize_key( $old );
            if ( '' !== $key && $key !== $new ) {
                $map[ $key ] = $new;
            }
            $done++;
        }
        if ( $done ) {
            update_option( self::ALIAS_OPTION, $map, false );
        }
        return $done;
    }
}
