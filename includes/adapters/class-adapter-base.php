<?php
namespace EntryVault\Adapters;

use EntryVault\Sync_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for live form-plugin capture adapters. Each adapter detects its
 * source plugin, hooks its submission event, and normalizes entries into the
 * payload shape Sync_Engine::ingest() expects.
 */
abstract class Adapter_Base {

    abstract public function slug(): string;
    abstract public function is_active(): bool;
    abstract public function register_hooks(): void;

    /**
     * Human-readable name, for the screen that lists which form plugins were
     * detected. Derived from the slug so every adapter has one without editing
     * every adapter file; override where the derived name reads badly.
     */
    public function label(): string {
        // The shared source names ("WPForms", not "Wpforms" from the slug).
        return \EntryVault\Sources::label( $this->slug() );
    }

    /**
     * Whether to leave out an entry the form plugin itself marked as spam.
     * On unless "Keep entries marked as spam" is ticked in Settings.
     */
    public static function skip_spam_enabled(): bool {
        $settings = get_option( 'entryvault_settings', [] );
        $on       = ! is_array( $settings ) || ! array_key_exists( 'skip_spam', $settings ) || ! empty( $settings['skip_spam'] );
        /**
         * Filter whether entries marked as spam by their form plugin are skipped.
         *
         * @param bool $on Default from the setting.
         */
        return (bool) apply_filters( 'entryvault_skip_spam', $on );
    }

    /**
     * Call when the source marked this submission as spam. Returns true (and
     * logs it) when the entry should be skipped.
     */
    protected function skip_as_spam( string $form_id = '' ): bool {
        if ( ! self::skip_spam_enabled() ) {
            return false;
        }
        \EntryVault\Logger::info( 'sync', 'Skipped an entry its form plugin marked as spam', [
            'source'  => $this->slug(),
            'form_id' => $form_id,
        ] );
        do_action( 'entryvault_spam_skipped', $this->slug(), $form_id );
        return true;
    }

    protected function dispatch( array $normalized ): int {
        $normalized['source_plugin'] = $normalized['source_plugin'] ?? $this->slug();
        return ( new Sync_Engine() )->ingest( $normalized );
    }

    protected function pick_email( array $fields ): ?string {
        foreach ( $fields as $f ) {
            if ( ( $f['type'] ?? '' ) === 'email' && ! empty( $f['value'] ) && is_string( $f['value'] ) ) {
                return $f['value'];
            }
        }
        foreach ( $fields as $f ) {
            $v = $f['value'] ?? '';
            if ( is_string( $v ) && filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
                return $v;
            }
        }
        return null;
    }

    protected function pick_title( array $fields, ?string $email ): string {
        foreach ( $fields as $f ) {
            $key = strtolower( (string) ( $f['key']   ?? '' ) );
            $lab = strtolower( (string) ( $f['label'] ?? '' ) );
            if ( ( $key === 'name' || strpos( $lab, 'name' ) !== false ) && ! empty( $f['value'] ) && is_string( $f['value'] ) ) {
                return $f['value'];
            }
        }
        return $email ?: '';
    }

    /**
     * A per-submission id for sources that store no entry (or don't tell us its id).
     * Sync_Engine dedupes on (source_plugin, source_form_id, source_entry_id), so the
     * id must differ for every submission, even two identical ones in one request.
     * The prefix keeps it apart from any real numeric entry id.
     */
    protected function make_entry_id( string $prefix, string $form_id, $payload = null ): string {
        $seed = $form_id . '|' . microtime( true ) . '|' . uniqid( '', true );
        if ( null !== $payload && function_exists( 'wp_json_encode' ) ) {
            $seed .= '|' . wp_json_encode( $payload );
        }
        return $prefix . md5( $seed );
    }

    /**
     * Turn a submitted value into something a field row can hold. Lists (checkbox
     * groups, multi-selects, several uploads) become "a, b, c"; an uploaded file
     * described as an array becomes its URL (or name). Objects and other odd shapes
     * become '' rather than breaking the capture.
     *
     * @return string|int|float|bool
     */
    protected function flatten_value( $value ) {
        if ( is_scalar( $value ) ) {
            return $value;
        }
        if ( ! is_array( $value ) ) {
            return '';
        }
        if ( ! isset( $value[0] ) ) {
            foreach ( [ 'url', 'value', 'name' ] as $k ) {
                if ( isset( $value[ $k ] ) && is_scalar( $value[ $k ] ) ) {
                    return $value[ $k ];
                }
            }
        }
        $parts = [];
        foreach ( $value as $item ) {
            $flat = $this->flatten_value( $item );
            if ( '' !== (string) $flat ) {
                $parts[] = (string) $flat;
            }
        }
        return implode( ', ', $parts );
    }

    /** Best guess at a field type when the source doesn't say. */
    protected function infer_type( string $label, $value ): string {
        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
            return 'email';
        }
        $lower = strtolower( $label );
        if ( strpos( $lower, 'email' ) !== false ) {
            return 'email';
        }
        if ( strpos( $lower, 'phone' ) !== false ) {
            return 'phone';
        }
        return 'text';
    }

    /** "your_full-name" -> "Your Full Name". */
    protected function humanize_key( string $key ): string {
        return ucwords( trim( str_replace( [ '-', '_' ], ' ', $key ) ) );
    }

    /**
     * The normalized payload every adapter hands to Sync_Engine, with email and
     * title picked the shared way. $fallback_title is used when no field gives a
     * name or an email (usually the form's own name).
     */
    protected function build_payload( string $form_id, string $entry_id, array $fields, string $fallback_title = '', ?string $created_at = null ): array {
        $email = $this->pick_email( $fields );
        $title = $this->pick_title( $fields, $email );
        if ( '' === $title && '' !== $fallback_title ) {
            $title = $fallback_title;
        }
        return [
            'source_plugin'   => $this->slug(),
            'source_form_id'  => $form_id,
            'source_entry_id' => $entry_id,
            'title'           => $title,
            'email'           => $email,
            'fields'          => $fields,
            'created_at'      => $created_at ?: current_time( 'mysql' ),
        ];
    }
}
