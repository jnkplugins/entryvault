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
     * Whether a submission is deleted when its entry is deleted in the form
     * plugin. Off by default: EntryVault keeps its copy as a backup. Ticked in
     * Settings > Data. Privacy erase requests reach EntryVault through its own
     * eraser either way.
     */
    public static function delete_with_source_enabled(): bool {
        $settings = get_option( 'entryvault_settings', [] );
        return is_array( $settings ) && ! empty( $settings['delete_with_source'] );
    }

    /**
     * Call when the source marked this submission as spam. Returns true (and
     * logs it) when the entry should be skipped.
     */
    protected function skip_as_spam( string $form_id = '', string $entry_ref = '' ): bool {
        if ( ! self::skip_spam_enabled() ) {
            return false;
        }
        \EntryVault\Logger::info( 'sync', 'Skipped an entry its form plugin marked as spam', [
            'source'  => $this->slug(),
            'form_id' => $form_id,
        ] );
        self::record_spam_skip( $this->slug(), $form_id, $entry_ref );
        do_action( 'entryvault_spam_skipped', $this->slug(), $form_id );
        return true;
    }

    public const SPAM_SKIPS_OPTION = 'entryvault_spam_skips';
    private const SPAM_SKIPS_KEEP  = 100;
    /** Skips per day (Y-m-d => n), uncapped: the list above keeps only the latest 100. */
    public const SPAM_SKIP_DAYS_OPTION = 'entryvault_spam_skip_days';

    /**
     * Remember a skipped spam entry. Skipping was silent, so a real message a form
     * plugin got wrong (a live site, 24 Oct 2026: a phone's autofill tripped the
     * honeypot) never reached the board and nothing said it had been left out.
     */
    public static function record_spam_skip( string $source, string $form_id = '', string $entry_ref = '' ): void {
        $list = self::spam_skips();
        array_unshift( $list, [ 'at' => time(), 'source' => $source, 'form_id' => $form_id, 'entry' => $entry_ref ] );
        update_option( self::SPAM_SKIPS_OPTION, array_slice( $list, 0, self::SPAM_SKIPS_KEEP ), false );
        self::count_spam_skip( time(), 1 );
    }

    private static function count_spam_skip( int $at, int $by ): void {
        $days = get_option( self::SPAM_SKIP_DAYS_OPTION, [] );
        $days = is_array( $days ) ? $days : [];
        $day  = gmdate( 'Y-m-d', $at );
        $days[ $day ] = max( 0, (int) ( $days[ $day ] ?? 0 ) + $by );
        $cut  = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
        $days = array_filter( $days, static fn( $n, $d ) => $n > 0 && (string) $d >= $cut, ARRAY_FILTER_USE_BOTH );
        update_option( self::SPAM_SKIP_DAYS_OPTION, $days, false );
    }

    /**
     * How many entries were left out as spam since $since. The board said "99"
     * once the 100-row list was full, however many more had come in.
     */
    public static function spam_skip_count( int $since ): int {
        $days = get_option( self::SPAM_SKIP_DAYS_OPTION, [] );
        if ( ! is_array( $days ) || ! $days ) {
            return count( self::spam_skips( $since ) ); // skips recorded before the counter existed
        }
        $from = gmdate( 'Y-m-d', $since );
        $n    = 0;
        foreach ( $days as $day => $count ) {
            if ( (string) $day >= $from ) {
                $n += (int) $count;
            }
        }
        return max( $n, count( self::spam_skips( $since ) ) );
    }

    /** @return list<array{at:int,source:string,form_id:string,entry:string}> newest first */
    public static function spam_skips( int $since = 0 ): array {
        $list = get_option( self::SPAM_SKIPS_OPTION, [] );
        $list = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : [];
        return $since > 0 ? array_values( array_filter( $list, static fn( $s ) => (int) ( $s['at'] ?? 0 ) >= $since ) ) : $list;
    }

    /** Forget a skip, once the entry reached EntryVault after all. */
    public static function forget_spam_skip( string $source, string $entry_ref ): bool {
        $list = self::spam_skips();
        $kept = array_values( array_filter( $list, static fn( $s ) => ! ( $s['source'] === $source && (string) $s['entry'] === $entry_ref ) ) );
        if ( count( $kept ) === count( $list ) ) {
            return false;
        }
        update_option( self::SPAM_SKIPS_OPTION, $kept, false );
        foreach ( $list as $s ) {
            if ( $s['source'] === $source && (string) $s['entry'] === $entry_ref ) {
                self::count_spam_skip( (int) ( $s['at'] ?? time() ), -1 );
            }
        }
        return true;
    }

    protected function dispatch( array $normalized ): int {
        $normalized['source_plugin'] = $normalized['source_plugin'] ?? $this->slug();
        return ( new Sync_Engine() )->ingest( $normalized );
    }

    protected function pick_email( array $fields ): ?string {
        return self::email_from_fields( $fields );
    }

    protected function pick_title( array $fields, ?string $email ): string {
        return self::title_from_fields( $fields, $email );
    }

    /** The email an entry's fields give: the first email field, else any value that is an address. */
    public static function email_from_fields( array $fields ): ?string {
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

    /** Field types form plugins use for a person's name (WPForms, Gravity Forms, Fluent, Forminator). */
    private const NAME_TYPES = [ 'name', 'input_name' ];

    /**
     * The card title an entry's fields give: a name field, else the email.
     *
     * The field type is checked first: on a non-English site the label is
     * "الاسم" or "Nombre" and the key a number, so a WPForms card was titled with
     * the email.
     */
    public static function title_from_fields( array $fields, ?string $email ): string {
        foreach ( $fields as $f ) {
            if ( in_array( strtolower( (string) ( $f['type'] ?? '' ) ), self::NAME_TYPES, true ) && is_string( $f['value'] ?? null ) && '' !== trim( $f['value'] ) ) {
                return trim( $f['value'] );
            }
        }
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
            'created_at'      => $created_at ?: current_time( 'mysql', true ),
        ];
    }
}
