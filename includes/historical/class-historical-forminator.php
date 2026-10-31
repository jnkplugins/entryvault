<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_Forminator;

defined( 'ABSPATH' ) || exit;

class Historical_Forminator extends Provider_Base {

    public function source(): string {
        return 'forminator';
    }

    public function label(): string {
        return 'Forminator';
    }

    public function access(): string {
        return self::ACCESS_API;
    }

    public function source_version(): string {
        return defined( 'FORMINATOR_VERSION' ) ? (string) FORMINATOR_VERSION : '';
    }

    protected function adapter_class(): string {
        return Adapter_Forminator::class;
    }

    /**
     * The Forminator classes EntryVault reads through. Filterable so tests can
     * hand in stand-ins with the same static methods.
     *
     * @return array{entry:string,api:string}
     */
    protected function api(): array {
        $api = [ 'entry' => '\\Forminator_Form_Entry_Model', 'api' => '\\Forminator_API' ];
        /**
         * Filter the Forminator classes EntryVault reads past entries with.
         *
         * @param array{entry:string,api:string} $api Class names.
         */
        $api = apply_filters( 'entryvault_forminator_api', $api );
        return [
            'entry' => (string) ( $api['entry'] ?? '' ),
            'api'   => (string) ( $api['api'] ?? '' ),
        ];
    }

    public function available(): bool {
        $class = $this->api()['entry'];
        return '' !== $class && class_exists( $class );
    }

    public function forms(): array {
        $api = $this->api()['api'];
        if ( '' === $api || ! class_exists( $api ) ) {
            return [];
        }
        $forms = $api::get_forms( null, 1, -1 );
        if ( ! is_array( $forms ) ) {
            return [];
        }
        $out = [];
        foreach ( $forms as $form ) {
            $form = is_object( $form ) ? $form : (object) (array) $form;
            $id   = (int) ( $form->id ?? 0 );
            if ( ! $id ) {
                continue;
            }
            // The form's name as the owner typed it lives in settings['formName'];
            // $form->name is the post slug.
            $settings = (array) ( $form->settings ?? [] );
            $raw      = isset( $form->raw ) ? (object) $form->raw : null;
            $title    = trim( (string) ( $settings['formName'] ?? '' ) );
            if ( '' === $title && $raw ) {
                $title = trim( (string) ( $raw->post_title ?? '' ) );
            }
            if ( '' === $title ) {
                $title = (string) ( $form->name ?? '' );
            }
            $out[] = [ 'id' => $id, 'title' => $title ];
        }
        return $out;
    }

    /** Entry statuses that are real submissions (not drafts, abandoned, failed payments or spam). */
    private const STATUSES = [ 'active', 'pending' ];

    /**
     * Whether query_entries() still has the old ( $form_id, $args ) signature
     * (Forminator before the args-array API).
     */
    private function old_query_signature( string $class ): bool {
        try {
            $params = ( new \ReflectionMethod( $class, 'query_entries' ) )->getParameters();
        } catch ( \ReflectionException $e ) {
            return false;
        }
        return isset( $params[0] ) && 'args' !== $params[0]->getName() && isset( $params[1] ) && 'get_count' !== $params[1]->getName();
    }

    public function count( int $form_id ): ?int {
        $class = $this->api()['entry'];
        if ( ! $this->available() ) {
            return null;
        }
        try {
            if ( method_exists( $class, 'count_entries' ) ) {
                // Counts active and pending entries: the same ones entries() reads.
                return (int) $class::count_entries( (int) $form_id );
            }
            if ( method_exists( $class, 'query_entries' ) && ! $this->old_query_signature( $class ) ) {
                $res = $class::query_entries( [ 'form_id' => (int) $form_id, 'status' => self::STATUSES ], true );
                return is_array( $res ) && isset( $res['count'] ) ? (int) $res['count'] : null;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        return null;
    }

    public function entries( int $form_id, int $offset, int $limit ): array {
        $adapter = $this->adapter();
        if ( ! $adapter || ! $this->available() ) {
            return [];
        }
        $class   = $this->api()['entry'];
        $form_id = (int) $form_id;
        $limit   = max( 1, (int) $limit );
        $offset  = max( 0, (int) $offset );
        if ( $this->old_query_signature( $class ) ) {
            $entries = $class::query_entries( $form_id, [
                'per_page' => $limit,
                'page'     => (int) floor( $offset / $limit ) + 1,
                'order'    => 'ASC',
            ] );
        } else {
            // order is only honoured together with order_by.
            $entries = $class::query_entries( [
                'form_id'  => $form_id,
                'per_page' => $limit,
                'offset'   => $offset,
                'order_by' => 'entries.entry_id',
                'order'    => 'ASC',
                'status'   => self::STATUSES,
            ] );
        }
        if ( ! is_array( $entries ) || empty( $entries ) ) {
            return [];
        }
        $out = [];
        foreach ( $entries as $row ) {
            $entry_id = is_object( $row ) ? (int) ( $row->entry_id ?? 0 ) : (int) ( $row['entry_id'] ?? 0 );
            if ( ! $entry_id ) {
                continue;
            }
            $entry = is_object( $row ) && isset( $row->meta_data ) ? $row : new $class( $entry_id );
            if ( empty( $entry->entry_id ) ) {
                continue;
            }
            // meta_data is [ slug => [ 'id' => meta id, 'value' => value ] ];
            // the adapter wants [ slug => value ], as the submit hook hands it.
            $meta = [];
            foreach ( (array) ( $entry->meta_data ?? [] ) as $slug => $m ) {
                $meta[ (string) $slug ] = is_array( $m ) && array_key_exists( 'value', $m ) ? $m['value'] : $m;
            }
            $out[] = [
                'source_entry_id' => (string) $entry_id,
                'payload'         => $adapter->build_normalized(
                    $form_id,
                    $entry_id,
                    $meta,
                    // Forminator writes date_created with date_i18n( 'Y-m-d H:i:s' ):
                    // the site's clock, so convert it to UTC.
                    \EntryVault\Dates::to_utc( (string) ( $entry->date_created_sql ?? '' ) ) ?: null
                ),
            ];
        }
        return $out;
    }
}
