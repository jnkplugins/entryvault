<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * The names EntryVault gives the pipeline and stages it creates itself.
 *
 * Up to 1.18 the activation seed stored them in English ("Inbox", "New",
 * "Contacted", ...) on every site, and the board, Settings and Overview printed
 * them as stored, so an Arabic or Korean site showed English stage names. New
 * installs now store them in the site language of that day. label() shows a
 * stored default (in English or any shipped language) in the current
 * language. A name the owner typed is shown as typed.
 */
final class Default_Names {

    public const PIPELINE = 'Inbox';

    /** English stage name => [ color, is_terminal, is_won ], in board order. */
    public const STAGES = [
        'New'       => [ '#3B82F6', 0, 0 ],
        'Contacted' => [ '#F59E0B', 0, 0 ],
        'Qualified' => [ '#10B981', 0, 0 ],
        'Won'       => [ '#16A34A', 1, 1 ],
        'Lost'      => [ '#EF4444', 1, 0 ],
    ];

    /** The translated name for one of the English defaults (unknown names pass through). */
    public static function translate( string $english ): string {
        switch ( $english ) {
            case 'Inbox':
                return _x( 'Inbox', 'default pipeline name', 'entryvault' );
            case 'New':
                return __( 'New', 'entryvault' );
            case 'Contacted':
                return __( 'Contacted', 'entryvault' );
            case 'Qualified':
                return __( 'Qualified', 'entryvault' );
            case 'Won':
                return __( 'Won', 'entryvault' );
            case 'Lost':
                return __( 'Lost', 'entryvault' );
        }
        return $english;
    }

    /**
     * The English default a stored name stands for, or '' when it is a name
     * the owner typed.
     *
     * The default pipeline and stages are stored in the language the site had
     * on the day they were created (English up to 1.18, then the site
     * language), so a stored name is recognised in English and in every
     * language EntryVault ships, not just the current one: an Arabic install
     * later used in Spanish shows "Ganada", not "مكسوب". Exact match only, so
     * "won" typed by the owner stays "won".
     */
    public static function english_for( $name ): string {
        $name = (string) $name;
        if ( '' === $name ) {
            return '';
        }
        $known = self::known();
        return $known[ $name ] ?? '';
    }

    /** What to show for a stored pipeline or stage name. */
    public static function label( $name ): string {
        $name    = (string) $name;
        $english = self::english_for( $name );
        return '' !== $english ? self::translate( $english ) : $name;
    }

    /** Request cache: stored form => English default. */
    private static ?array $known = null;

    /** Forget the request cache (tests, locale switches). */
    public static function reset_cache(): void {
        self::$known = null;
    }

    /** The English defaults: the pipeline, then the stages. */
    private static function english_names(): array {
        return array_merge( [ self::PIPELINE ], array_keys( self::STAGES ) );
    }

    /**
     * Every stored form of a default name (English, the current translation,
     * each shipped translation) => its English default.
     *
     * @return array<string,string>
     */
    private static function known(): array {
        $map = self::$known;
        if ( null === $map ) {
            $map = [];
            foreach ( self::english_names() as $english ) {
                $map[ $english ] = $english;
            }
            foreach ( self::shipped_translations() as $stored => $english ) {
                $map[ (string) $stored ] = $map[ (string) $stored ] ?? $english;
            }
            self::$known = $map;
        }
        // The current translation (a locale switch, a translation file we do
        // not ship) is added on every call, not cached.
        foreach ( self::english_names() as $english ) {
            $t         = self::translate( $english );
            $map[ $t ] = $map[ $t ] ?? $english;
        }
        return $map;
    }

    /** The .mo files EntryVault ships, plus any in wp-content/languages/plugins. */
    private static function mo_files(): array {
        $files = [];
        if ( defined( 'ENTRYVAULT_PATH' ) ) {
            $files = (array) glob( ENTRYVAULT_PATH . 'languages/entryvault-*.mo' );
        }
        if ( defined( 'WP_LANG_DIR' ) ) {
            $files = array_merge( $files, (array) glob( WP_LANG_DIR . '/plugins/entryvault-*.mo' ) );
        }
        return array_values( array_filter( $files, 'is_readable' ) );
    }

    /**
     * Translations of the defaults in every shipped language, read from the
     * .mo files once and kept until those files change.
     *
     * @return array<string,string> translated => English
     */
    private static function shipped_translations(): array {
        $files = self::mo_files();
        $sig   = [];
        foreach ( $files as $f ) {
            $sig[] = basename( $f ) . ':' . (int) @filemtime( $f ) . ':' . (int) @filesize( $f );
        }
        $sig    = md5( implode( '|', $sig ) );
        $cached = get_transient( 'entryvault_default_names' );
        if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig && is_array( $cached['map'] ?? null ) ) {
            return $cached['map'];
        }
        $map = [];
        if ( $files && ! class_exists( '\\MO' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }
        if ( class_exists( '\\MO' ) ) {
            foreach ( $files as $f ) {
                $mo = new \MO();
                if ( ! $mo->import_from_file( $f ) ) {
                    continue;
                }
                foreach ( self::english_names() as $english ) {
                    $t = self::PIPELINE === $english
                        ? $mo->translate( $english, 'default pipeline name' )
                        : $mo->translate( $english );
                    $t = (string) $t;
                    if ( '' !== $t && $t !== $english && ! isset( $map[ $t ] ) ) {
                        $map[ $t ] = $english;
                    }
                }
            }
        }
        set_transient( 'entryvault_default_names', [ 'sig' => $sig, 'map' => $map ] );
        return $map;
    }

    /**
     * Every stored name a stage filter typed by a person may mean.
     *
     * A shortcode written as stage="Won" keeps working whatever language the
     * stages were stored in ("مكسوب", "Ganada"), and stage="مكسوب" finds a
     * stage stored as "Won" or "Ganada". Matching is case-insensitive, like
     * the database comparison. Names the owner typed only match themselves.
     *
     * @return string[]
     */
    public static function stage_aliases( string $name ): array {
        $name = trim( $name );
        if ( '' === $name ) {
            return [];
        }
        $out   = [ $name ];
        $lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
        $want  = $lower( $name );
        $hits  = [];
        foreach ( self::known() as $stored => $english ) {
            if ( self::PIPELINE !== $english && $lower( (string) $stored ) === $want ) {
                $hits[ $english ] = true;
            }
        }
        foreach ( self::known() as $stored => $english ) {
            if ( isset( $hits[ $english ] ) ) {
                $out[] = (string) $stored;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Rewrite the given keys of each row (arrays or objects) with label().
     *
     * @param array<int,array|object> $rows
     * @param string[]                $keys
     * @return array<int,array|object>
     */
    public static function label_rows( $rows, array $keys = [ 'name' ] ) {
        if ( ! is_array( $rows ) ) {
            return $rows;
        }
        foreach ( $rows as $i => $row ) {
            foreach ( $keys as $k ) {
                if ( is_array( $row ) && isset( $row[ $k ] ) ) {
                    $rows[ $i ][ $k ] = self::label( $row[ $k ] );
                } elseif ( is_object( $row ) && isset( $row->$k ) ) {
                    $row->$k = self::label( $row->$k );
                }
            }
        }
        return $rows;
    }
}
