<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Formidable Forms (https://formidableforms.com/).
 *
 * Formidable persists entries and entry meta, but its submission hook
 * (`frm_after_create_entry`) only passes the entry id + form id, so we read the
 * values back through Formidable's own classes. Those classes are guarded with
 * class_exists so a version mismatch degrades gracefully to an empty field set
 * rather than a fatal. Labels/types come from FrmField; anything missing falls
 * back to humanize()/guess_type().
 */
class Adapter_Formidable extends Adapter_Base {

    public function slug(): string {
        return 'formidable';
    }

    public function is_active(): bool {
        return class_exists( '\\FrmAppHelper' )
            || class_exists( '\\FrmEntry' )
            || class_exists( '\\FrmEntryMeta' );
    }

    public function register_hooks(): void {
        // Args: ( $entry_id, $form_id ). Priority 30 lets Formidable finish
        // writing the entry meta before we read it back.
        add_action( 'frm_after_create_entry', [ $this, 'on_after_create_entry' ], 30, 2 );
    }

    /**
     * Hook callback for `frm_after_create_entry`.
     *
     * @param int $entry_id Newly created entry id.
     * @param int $form_id  Form id.
     */
    public function on_after_create_entry( $entry_id, $form_id = 0 ): void {
        $entry_id = (int) $entry_id;
        $form_id  = (int) $form_id;
        if ( ! $entry_id ) {
            return;
        }
        // Formidable 7 stores spam with is_draft = 4 and still fires
        // frm_after_create_entry for it.
        if ( 4 === $this->stored_draft_flag( $entry_id ) && $this->skip_as_spam( (string) $form_id ) ) {
            return;
        }
        $metas      = $this->read_entry_metas( $entry_id );
        $field_defs = $this->read_field_defs( $form_id );

        $normalized = $this->build_normalized( $form_id, $entry_id, $metas, $field_defs, current_time( 'mysql', true ) );
        $this->dispatch( $normalized );
    }

    /**
     * Build the normalized payload from Formidable meta.
     *
     * @param array $metas      field_id => value map (value may be a string or array).
     * @param array $field_defs field_id => [ 'label' => ..., 'type' => ... ] from FrmField.
     */
    public function build_normalized( int $form_id, int $entry_id, array $metas, array $field_defs, ?string $created_at ): array {
        $fields = [];
        foreach ( $metas as $field_id => $value ) {
            $field_id = (string) $field_id;
            $meta     = $field_defs[ $field_id ] ?? null;
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
            }
            $label = $meta['label'] ?? $this->humanize( $field_id );
            $type  = $meta['type']  ?? $this->guess_type( $label, $value );
            $fields[] = [
                'key'   => sanitize_key( $field_id ) ?: 'field_' . count( $fields ),
                'label' => $label,
                'type'  => $this->normalize_type( (string) $type, $label, $value ),
                'value' => $value,
            ];
        }

        $email = $this->pick_email( $fields );
        $title = $this->pick_title( $fields, $email );

        return [
            'source_plugin'   => $this->slug(),
            'source_form_id'  => (string) $form_id,
            'source_entry_id' => (string) $entry_id,
            'title'           => $title,
            'email'           => $email,
            'fields'          => $fields,
            'created_at'      => $created_at ?: current_time( 'mysql', true ),
        ];
    }

    /** frm_items.is_draft for an entry (0 submitted, 1 draft, 4 spam), or -1. */
    private function stored_draft_flag( int $entry_id ): int {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-row lookup in Formidable's table.
        $flag = $wpdb->get_var( $wpdb->prepare( "SELECT is_draft FROM {$wpdb->prefix}frm_items WHERE id = %d", $entry_id ) );
        $wpdb->suppress_errors( $suppress );
        return null === $flag ? -1 : (int) $flag;
    }

    /**
     * Read entry meta as [ field_id => value ] via FrmEntry::getOne( $id, true ),
     * whose returned object exposes a ->metas map. Guarded so a missing/changed
     * class never fatals.
     */
    private function read_entry_metas( int $entry_id ): array {
        if ( ! class_exists( '\\FrmEntry' ) || ! method_exists( '\\FrmEntry', 'getOne' ) ) {
            return [];
        }
        $entry = \FrmEntry::getOne( $entry_id, true );
        if ( is_object( $entry ) && isset( $entry->metas ) && is_array( $entry->metas ) ) {
            return $entry->metas;
        }
        return [];
    }

    /**
     * Read [ field_id => [ label, type ] ] from FrmField for the form. Guarded.
     */
    private function read_field_defs( int $form_id ): array {
        $map = [];
        if ( ! $form_id || ! class_exists( '\\FrmField' ) || ! method_exists( '\\FrmField', 'get_all_for_form' ) ) {
            return $map;
        }
        $defs = \FrmField::get_all_for_form( $form_id );
        foreach ( (array) $defs as $f ) {
            $id = is_object( $f ) ? ( $f->id ?? '' ) : ( is_array( $f ) ? ( $f['id'] ?? '' ) : '' );
            if ( $id === '' ) {
                continue;
            }
            $name = is_object( $f ) ? ( $f->name ?? '' ) : ( $f['name'] ?? '' );
            $type = is_object( $f ) ? ( $f->type ?? '' ) : ( $f['type'] ?? '' );
            $map[ (string) $id ] = [
                'label' => (string) $name,
                'type'  => (string) $type,
            ];
        }
        return $map;
    }

    /** Map a few Formidable field types onto our vocabulary; fall back on value/label. */
    private function normalize_type( string $type, string $label, $value ): string {
        $type = strtolower( $type );
        switch ( $type ) {
            case 'email':   return 'email';
            case 'phone':   return 'phone';
            case 'text':    return 'text';
            case 'textarea':return 'textarea';
            case 'select':  return 'select';
            case 'checkbox':return 'checkbox';
            case 'radio':   return 'radio';
            case 'url':     return 'url';
            case 'number':  return 'number';
            case 'date':    return 'date';
            case '':        return $this->guess_type( $label, $value );
            default:        return $type;
        }
    }

    private function humanize( string $slug ): string {
        return ucwords( trim( str_replace( [ '-', '_' ], ' ', $slug ) ) );
    }

    private function guess_type( string $label, $value ): string {
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
        if ( is_array( $value ) ) {
            return 'array';
        }
        return 'text';
    }
}
