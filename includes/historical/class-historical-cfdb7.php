<?php
namespace EntryVault\Historical;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads CFDB7's own table directly.

/**
 * Contact Form 7 messages saved by "Contact Form 7 Database Addon - CFDB7".
 *
 * Storage (CFDB7 1.4.x, read from its source): one table `{prefix}db7_forms`
 * (form_id, form_post_id = the CF7 form's post id, form_value = a PHP-serialized
 * name => value array, form_date = site time). CFDB7 adds `cfdb7_status`
 * (read/unread), escapes quotes and slashes as HTML entities, and stores an
 * upload as `{name}cfdb7_file` => the saved file's name. CFDB7 keeps no spam.
 *
 * form_value is unserialized with allowed_classes => false, so a stored object
 * can never be instantiated.
 */
class Historical_CFDB7 extends Historical_CF7_Base {

    public function key(): string {
        return 'cfdb7';
    }

    public function label(): string {
        return 'Contact Form 7 (saved by CFDB7)';
    }

    protected function expected_schema(): array {
        return [ 'db7_forms' => [ 'form_id', 'form_post_id', 'form_value', 'form_date' ] ];
    }

    public function source_version(): string {
        return defined( 'CFDB7_VERSION' ) ? (string) CFDB7_VERSION : '';
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'db7_forms';
    }

    /** The CFDB7 table exists (it stays after CFDB7 is deactivated). */
    public function available(): bool {
        return self::table_exists( self::table() );
    }

    public function forms(): array {
        global $wpdb;
        if ( ! $this->available() ) {
            return [];
        }
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT form_post_id FROM %i ORDER BY form_post_id ASC',
            self::table()
        ) ) );
        $out = [];
        foreach ( $ids as $id ) {
            if ( $id <= 0 ) {
                continue;
            }
            $title = $this->cf7_form_title( $id );
            $out[] = [ 'id' => $id, 'title' => '' !== $title ? $title : 'Form #' . $id ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        global $wpdb;
        if ( ! $this->available() || ! $this->adapter() ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT form_id, form_value, form_date FROM %i WHERE form_post_id = %d ORDER BY form_id ASC LIMIT %d OFFSET %d',
            self::table(),
            $form_id,
            $limit,
            $offset
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $id     = 'cfdb7_' . (int) $row->form_id;
            $posted = self::decode( (string) $row->form_value );
            $out[]  = [
                'source_entry_id' => $id,
                // CFDB7 writes form_date with current_time( 'Y-m-d H:i:s' ): the
                // site's clock, so convert it to UTC.
                'payload'         => $posted ? $this->payload( $form_id, $id, $posted, \EntryVault\Dates::to_utc( (string) $row->form_date ) ) : null,
            ];
        }
        return $out;
    }

    /**
     * @return array<string,mixed> field name => value, CFDB7's own keys removed.
     */
    public static function decode( string $raw ): array {
        if ( '' === $raw || ! is_serialized( $raw ) ) {
            return [];
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- CFDB7's storage format; objects are refused.
        $data = @unserialize( $raw, [ 'allowed_classes' => false ] );
        if ( ! is_array( $data ) ) {
            return [];
        }
        unset( $data['cfdb7_status'] );
        $out = [];
        foreach ( $data as $key => $value ) {
            $key = (string) $key;
            if ( '' !== $key && str_ends_with( $key, 'cfdb7_file' ) ) {
                $key = substr( $key, 0, -strlen( 'cfdb7_file' ) );
            }
            $out[ $key ] = self::unescape( $value );
        }
        return $out;
    }

    /** Undo CFDB7's quote/slash escaping. @param mixed $value */
    private static function unescape( $value ) {
        if ( is_array( $value ) ) {
            return array_map( [ __CLASS__, 'unescape' ], $value );
        }
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        return str_replace( [ '&quot;', '&#039;', '&#047;', '&#092;' ], [ '"', "'", '/', '\\' ], (string) $value );
    }
}
