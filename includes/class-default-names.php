<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * The names EntryVault gives the pipeline and stages it creates itself.
 *
 * Up to 1.18 the activation seed stored them in English ("Inbox", "New",
 * "Contacted", ...) on every site, and the board, Settings and Overview printed
 * them as stored, so an Arabic or Korean site showed English stage names. New
 * installs now store them in the site language; for rows created before that,
 * label() shows the translation while the stored name is still exactly the
 * English default. A name the owner typed is shown as typed.
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

    /** What to show for a stored pipeline or stage name. */
    public static function label( $name ): string {
        $name = (string) $name;
        if ( self::PIPELINE === $name || isset( self::STAGES[ $name ] ) ) {
            return self::translate( $name );
        }
        return $name;
    }

    /**
     * Every stored name a stage filter typed by a person may mean.
     *
     * A shortcode written as stage="Won" on an older site keeps working once
     * the stages are stored translated ("مكسوب"), and stage="مكسوب" finds a stage
     * still stored as "Won" (it is shown translated). Matching is
     * case-insensitive, like the database comparison. Names the owner typed
     * only match themselves.
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
        foreach ( array_keys( self::STAGES ) as $english ) {
            $translated = self::translate( $english );
            if ( $lower( $english ) === $want || $lower( $translated ) === $want ) {
                $out[] = $english;
                $out[] = $translated;
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
