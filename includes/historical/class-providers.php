<?php
namespace EntryVault\Historical;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of premium historical-import providers, keyed by provider key
 * (the source slug, except Flamingo and CFDB7, which both import Contact
 * Form 7 messages).
 */
class Providers {

    private const MAP = [
        'wpforms'       => Historical_WPForms::class,
        'gravity-forms' => Historical_Gravity_Forms::class,
        'forminator'    => Historical_Forminator::class,
        'fluent-forms'  => Historical_Fluent_Forms::class,
        'trinity-forms' => Historical_Trinity_Forms::class,
        'sureforms'     => Historical_Sureforms::class,
        // 1.12.0
        'flamingo'      => Historical_Flamingo::class,
        'cfdb7'         => Historical_CFDB7::class,
        'formidable'    => Historical_Formidable::class,
        'ninja-forms'   => Historical_Ninja_Forms::class,
        'everest-forms' => Historical_Everest_Forms::class,
        'metform'       => Historical_Metform::class,
    ];

    /** @return array<string,Provider_Base> keyed by source slug */
    public static function all(): array {
        $out = [];
        foreach ( self::MAP as $slug => $class ) {
            $out[ $slug ] = new $class();
        }
        return $out;
    }

    public static function get( string $slug ): ?Provider_Base {
        $class = self::MAP[ $slug ] ?? null;
        if ( $class ) {
            return new $class();
        }
        // Allow tests to inject an arbitrary provider for unknown slugs.
        $injected = apply_filters( 'entryvault_backfill_provider', null, $slug );
        return $injected instanceof Provider_Base ? $injected : null;
    }
}
