<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Sync_Engine {

    /**
     * Ingest a submission, returning its id.
     *
     * Note that a positive return value does NOT mean a row was created — a
     * duplicate returns the id of the row it matched. Callers that need to tell
     * the difference (the CSV importer counts "imported" vs "skipped") must use
     * ingest_result() instead; using this and testing `> 0` reports every
     * duplicate as a fresh import.
     */
    public function ingest( array $normalized ): int {
        return (int) $this->ingest_result( $normalized )['id'];
    }

    /**
     * Ingest a submission and say what actually happened to it.
     *
     * @return array{id:int, created:bool}
     */
    /**
     * A value the owner changed in "Edit captured values" stays as they set it
     * when the source entry is edited later: the panel promises the edit lives in
     * EntryVault, and a Trinity edit to another field used to undo it silently.
     *
     * @param array<int,array<string,mixed>> $fields     Incoming fields.
     * @param array<int,array<string,mixed>> $old_fields Stored fields (key, value).
     * @return array<int,array<string,mixed>>
     */
    private static function keep_owner_edits( int $submission_id, array $fields, array $old_fields ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT data FROM {$wpdb->prefix}entryvault_timeline WHERE submission_id = %d AND type = 'field_edited'",
            $submission_id
        ) );
        if ( ! $rows ) {
            return $fields;
        }
        $edited = [];
        foreach ( $rows as $json ) {
            $d = json_decode( (string) $json, true );
            if ( is_array( $d ) && '' !== (string) ( $d['field_key'] ?? '' ) ) {
                $edited[ (string) $d['field_key'] ] = true;
            }
        }
        $stored = [];
        foreach ( $old_fields as $f ) {
            $stored[ (string) ( $f['key'] ?? '' ) ] = $f['value'] ?? '';
        }
        foreach ( $fields as $i => $f ) {
            $key = (string) ( $f['key'] ?? '' );
            if ( isset( $edited[ $key ] ) && array_key_exists( $key, $stored ) ) {
                $fields[ $i ]['value'] = $stored[ $key ];
            }
        }
        return $fields;
    }

    public function ingest_result( array $normalized ): array {
        $normalized = apply_filters( 'entryvault_normalize_submission', $normalized );

        $existing = Submission_Store::find_by_source(
            (string) ( $normalized['source_plugin']   ?? '' ),
            (string) ( $normalized['source_form_id']  ?? '' ),
            (string) ( $normalized['source_entry_id'] ?? '' )
        );
        if ( $existing ) {
            $old_fields = Submission_Store::fields_for( (int) $existing );
            $incoming   = (array) ( $normalized['fields'] ?? [] );
            $kept       = self::keep_owner_edits( (int) $existing, $incoming, $old_fields );
            if ( $kept !== $incoming ) {
                // The card's email and name follow the owner's edit too.
                $normalized['email'] = Adapters\Adapter_Base::email_from_fields( $kept );
                $normalized['title'] = Adapters\Adapter_Base::title_from_fields( $kept, $normalized['email'] );
            }
            // F-04: reconcile field set on re-sync — mark missing fields stale,
            // un-stale reappearing ones, upsert in place. Preserves history.
            Submission_Store::reconcile_fields( $existing, $kept );
            // An entry edited at the source got new field values, but the card
            // kept the old email (Reply went to it) and the old name.
            $old_email = Adapters\Adapter_Base::email_from_fields( $old_fields );
            $changed   = Submission_Store::refresh_contact(
                (int) $existing,
                [ 'email' => $old_email, 'title' => Adapters\Adapter_Base::title_from_fields( $old_fields, $old_email ) ],
                [ 'email' => $normalized['email'] ?? null, 'title' => (string) ( $normalized['title'] ?? '' ) ]
            );
            if ( $changed ) {
                Timeline::log( (int) $existing, 'status', null, [ 'event' => 'updated', 'source' => $normalized['source_plugin'] ?? '' ] );
            }
            return [ 'id' => (int) $existing, 'created' => false ];
        }

        $pipeline_id = $this->resolve_initial_pipeline( $normalized );
        $stage_id    = $this->resolve_initial_stage( $pipeline_id, $normalized );
        if ( ! $pipeline_id || ! $stage_id ) {
            Logger::error( 'sync', 'No pipeline/stage available; submission dropped', [
                'source' => $normalized['source_plugin'] ?? '',
            ] );
            return [ 'id' => 0, 'created' => false ];
        }

        $owner = $this->resolve_owner( $normalized );

        $submission_id = Submission_Store::insert( [
            'source_plugin'   => substr( (string) $normalized['source_plugin'], 0, 40 ),
            'source_form_id'  => substr( (string) $normalized['source_form_id'], 0, 100 ),
            'source_entry_id' => substr( (string) $normalized['source_entry_id'], 0, 100 ),
            'pipeline_id'     => $pipeline_id,
            'stage_id'        => $stage_id,
            'owner_user_id'   => $owner,
            'title'           => substr( (string) ( $normalized['title'] ?? '' ), 0, 500 ),
            'email'           => $normalized['email'] ?? null,
            'created_at'      => $normalized['created_at'] ?? current_time( 'mysql', true ),
        ] );

        if ( ! $submission_id ) {
            return [ 'id' => 0, 'created' => false ];
        }

        foreach ( (array) ( $normalized['fields'] ?? [] ) as $field ) {
            Submission_Store::insert_field( $submission_id, $field );
        }

        Timeline::log( $submission_id, 'status', null, [ 'event' => 'created', 'source' => $normalized['source_plugin'] ?? '' ] );

        // Spam its form plugin flagged, kept because "Keep entries marked as
        // spam" is ticked: say so on the card. Automations skip it (Pro_Boot).
        if ( ! empty( $normalized['is_spam'] ) ) {
            $spam_tag = Tag_Manager::spam_tag_id();
            if ( $spam_tag > 0 ) {
                Tag_Manager::attach( $submission_id, $spam_tag );
            }
        }

        do_action( 'entryvault_submission_synced', $submission_id, $normalized['source_plugin'] ?? '' );
        // Premium automations subscribe to this action (pro/ only); free core just fires it.
        do_action( 'entryvault_submission_created', $submission_id, $normalized );

        return [ 'id' => (int) $submission_id, 'created' => true ];
    }

    private function resolve_initial_pipeline( array $normalized ): int {
        global $wpdb;
        $settings = get_option( 'entryvault_settings', [] );
        $default  = (int) ( $settings['default_pipeline'] ?? 0 );
        if ( ! $default ) {
            $default = (int) $wpdb->get_var(
                "SELECT id FROM {$wpdb->prefix}entryvault_pipelines ORDER BY is_default DESC, id ASC LIMIT 1"
            );
        }

        /**
         * Which pipeline a new entry lands in. Extensions can route by form or
         * source; an id that is not a real pipeline falls back to the default.
         *
         * @param int   $pipeline_id Default pipeline id.
         * @param array $normalized  Normalized submission payload.
         */
        $chosen = (int) apply_filters( 'entryvault_initial_pipeline', $default, $normalized );
        if ( $chosen > 0 && $chosen !== $default ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}entryvault_pipelines WHERE id = %d",
                $chosen
            ) );
            if ( $exists ) {
                return $chosen;
            }
        }
        return $default;
    }

    private function resolve_initial_stage( int $pipeline_id, array $normalized ): int {
        global $wpdb;
        $stage_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}entryvault_stages WHERE pipeline_id = %d ORDER BY position ASC, id ASC LIMIT 1",
            $pipeline_id
        ) );
        return (int) apply_filters( 'entryvault_initial_stage', $stage_id, $pipeline_id, $normalized );
    }

    private function resolve_owner( array $normalized ): ?int {
        // Automatic owner assignment (e.g. team round-robin) is provided by
        // extension modules via this filter; free core assigns no owner.
        $owner = apply_filters( 'entryvault_resolve_owner', null, $normalized );
        return is_numeric( $owner ) && (int) $owner > 0 ? (int) $owner : null;
    }
}
