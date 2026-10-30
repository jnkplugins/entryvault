<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Formidable;

defined( 'ABSPATH' ) || exit;

/**
 * Past Formidable Forms entries, read through Formidable's public PHP API
 * (FrmForm::getAll, FrmField::getAll, FrmEntry::getAll with paging and
 * metas, FrmEntry::getRecordCount), so it is not Experimental.
 *
 * Only submitted, top-level entries are imported: drafts (is_draft 1), spam
 * (is_draft 4, Formidable 7.0+) and repeater child rows are left out. Entry
 * ids match live capture, so entries already on the board are skipped by id.
 *
 * Fallback, only when that API is missing (a Formidable without those
 * classes): the old direct read of `frm_items`, `frm_item_metas`,
 * `frm_fields` and `frm_forms`, with its schema check, marked Experimental.
 */
class Historical_Formidable extends Provider_Base {

    public function source(): string {
        return 'formidable';
    }

    public function label(): string {
        return 'Formidable Forms';
    }

    /**
     * The Formidable classes EntryVault reads through, or null when any is
     * missing. Filterable so tests can hand in stand-ins with the same
     * static methods.
     *
     * @return array{entry:string,form:string,field:string}|null
     */
    protected function api(): ?array {
        $api = [ 'entry' => '\\FrmEntry', 'form' => '\\FrmForm', 'field' => '\\FrmField' ];
        /**
         * Filter the Formidable classes EntryVault reads past entries with.
         *
         * @param array{entry:string,form:string,field:string} $api Class names.
         */
        $api = apply_filters( 'entryvault_formidable_api', $api );
        if ( ! is_array( $api ) ) {
            return null;
        }
        $need = [ 'entry' => [ 'getAll', 'getRecordCount' ], 'form' => [ 'getAll' ], 'field' => [ 'getAll' ] ];
        foreach ( $need as $k => $methods ) {
            $class = (string) ( $api[ $k ] ?? '' );
            if ( '' === $class || ! class_exists( $class ) ) {
                return null;
            }
            foreach ( $methods as $m ) {
                if ( ! method_exists( $class, $m ) ) {
                    return null;
                }
            }
        }
        return $api;
    }

    public function access(): string {
        return $this->api() ? self::ACCESS_API : self::ACCESS_DIRECT;
    }

    protected function expected_schema(): array {
        return [
            'frm_forms'      => [ 'id', 'name', 'is_template', 'parent_form_id' ],
            'frm_items'      => [ 'id', 'form_id', 'created_at', 'is_draft', 'parent_item_id' ],
            'frm_fields'     => [ 'id', 'name', 'type', 'form_id' ],
            'frm_item_metas' => [ 'id', 'item_id', 'field_id', 'meta_value' ],
        ];
    }

    public function source_version(): string {
        return class_exists( '\\FrmAppHelper' ) && method_exists( '\\FrmAppHelper', 'plugin_version' ) ? (string) \FrmAppHelper::plugin_version() : '';
    }

    protected function adapter_class(): string {
        return Adapter_Formidable::class;
    }

    public function available(): bool {
        if ( $this->api() ) {
            return true;
        }
        global $wpdb;
        return self::table_exists( $wpdb->prefix . 'frm_items' ) && self::table_exists( $wpdb->prefix . 'frm_forms' );
    }

    /** Where clause for submitted, top-level entries of one form (Formidable's FrmDb array form). */
    private static function entry_where( int $form_id ): array {
        return [ 'it.form_id' => $form_id, 'it.is_draft' => 0, 'it.parent_item_id' => [ null, 0 ] ];
    }

    public function count( int $form_id ): ?int {
        $api = $this->api();
        if ( ! $api ) {
            return null;
        }
        $n = call_user_func( [ $api['entry'], 'getRecordCount' ], self::entry_where( $form_id ) );
        return is_numeric( $n ) ? (int) $n : null;
    }

    public function forms(): array {
        $api = $this->api();
        if ( ! $api ) {
            return $this->forms_direct();
        }
        $rows = call_user_func(
            [ $api['form'], 'getAll' ],
            [ 'is_template' => 0, 'status' => [ null, '', 'published' ], 'parent_form_id' => [ null, 0 ] ],
            'id'
        );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            if ( ! is_object( $r ) ) {
                continue;
            }
            $out[] = [ 'id' => (int) $r->id, 'title' => '' !== (string) $r->name ? (string) $r->name : 'Form #' . $r->id ];
        }
        return $out;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $api     = $this->api();
        $adapter = $this->adapter();
        if ( ! $api ) {
            return $this->entries_direct( $form_id, $offset, $limit );
        }
        if ( ! $adapter instanceof Adapter_Formidable ) {
            return [];
        }
        $items = call_user_func(
            [ $api['entry'], 'getAll' ],
            self::entry_where( $form_id ),
            ' ORDER BY it.id ASC',
            ' LIMIT ' . max( 0, $offset ) . ',' . max( 1, $limit ),
            true,
            false
        );
        if ( ! is_array( $items ) || ! $items ) {
            return [];
        }
        $defs = [];
        foreach ( (array) call_user_func( [ $api['field'], 'getAll' ], [ 'fi.form_id' => $form_id ] ) as $f ) {
            if ( is_object( $f ) ) {
                $defs[ (string) $f->id ] = [ 'label' => (string) $f->name, 'type' => (string) $f->type ];
            }
        }
        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }
            $entry_id = (int) $item->id;
            $metas    = [];
            foreach ( (array) ( $item->metas ?? [] ) as $field_id => $value ) {
                $metas[ (string) $field_id ] = maybe_unserialize( $value );
            }
            $out[] = [
                'source_entry_id' => (string) $entry_id,
                // Formidable keeps created_at in UTC, as EntryVault does.
                'payload'         => $adapter->build_normalized( $form_id, $entry_id, $metas, $defs, (string) $item->created_at ),
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Fallback: Formidable's tables, only when its API is missing.
     * ------------------------------------------------------------------ */

    // phpcs:disable WordPress.DB.DirectDatabaseQuery -- fallback reads Formidable's own tables directly.
    private function forms_direct(): array {
        global $wpdb;
        if ( ! $this->available() ) {
            return [];
        }
        $rows = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}frm_forms
              WHERE is_template = 0 AND ( parent_form_id = 0 OR parent_form_id IS NULL )
                AND ( status IS NULL OR status NOT IN ( 'trash', 'draft' ) )
              ORDER BY id ASC"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [ 'id' => (int) $r->id, 'title' => '' !== (string) $r->name ? (string) $r->name : 'Form #' . $r->id ];
        }
        return $out;
    }

    private function entries_direct( int $form_id, int $offset, int $limit ): array {
        global $wpdb;
        $adapter = $this->adapter();
        if ( ! $adapter instanceof Adapter_Formidable || ! $this->available() ) {
            return [];
        }
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, created_at FROM {$wpdb->prefix}frm_items
              WHERE form_id = %d AND is_draft = 0 AND ( parent_item_id = 0 OR parent_item_id IS NULL )
              ORDER BY id ASC LIMIT %d OFFSET %d",
            $form_id,
            $limit,
            $offset
        ) );
        if ( ! $items ) {
            return [];
        }
        $defs = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, type FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $form_id
        ) ) as $f ) {
            $defs[ (string) $f->id ] = [ 'label' => (string) $f->name, 'type' => (string) $f->type ];
        }

        $out = [];
        foreach ( $items as $item ) {
            $entry_id = (int) $item->id;
            $metas    = [];
            foreach ( (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d AND field_id > 0 ORDER BY id ASC",
                $entry_id
            ) ) as $m ) {
                $metas[ (string) $m->field_id ] = maybe_unserialize( $m->meta_value );
            }
            $out[] = [
                'source_entry_id' => (string) $entry_id,
                // Formidable stores frm_items.created_at in UTC, as EntryVault
                // does, so kept as is.
                'payload'         => $adapter->build_normalized( $form_id, $entry_id, $metas, $defs, (string) $item->created_at ),
            ];
        }
        return $out;
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery
}
