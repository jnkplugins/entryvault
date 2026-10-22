<?php
namespace EntryVault\Adapters;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * Adapter for Trinity Forms (https://jnkplugins.com/trinity-forms/).
 *
 * Captures every Trinity Forms submission into EntryVault via the
 * `trinity_forms_entry_created` action (which fires for both free and payment forms,
 * before the payment branch). Trinity Forms stores the field label + type alongside
 * each value in its entry-meta table, so no form-schema parsing is needed to
 * normalize an entry.
 */
class Adapter_Trinity_Forms extends Adapter_Base {

    public function slug(): string {
        return "trinity-forms";
    }

    public function is_active(): bool {
        return defined( "TRINITY_FORMS_VERSION" ) || class_exists( "TrinityForms\\Plugin" );
    }

    public function register_hooks(): void {
        // Fires once per submission, after the entry + meta rows are written and
        // before the payment redirect, so leads are captured even when payment
        // is still pending. Two args: ( $entry_id, $form_id ).
        add_action( "trinity_forms_entry_created", [ $this, "on_entry_created" ], 20, 2 );
        // Lets matchers (automation conditions) treat an option value and its
        // label as the same answer, now that entries are stored as labels.
        add_filter( "entryvault_field_choice_labels", [ $this, "field_choice_labels" ], 10, 4 );
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function field_choice_labels( $labels, $source = "", $form_id = "", $field_key = "" ): array {
        if ( $source !== $this->slug() || (int) $form_id <= 0 ) {
            return (array) $labels;
        }
        static $cache = [];
        $form_id = (int) $form_id;
        if ( ! isset( $cache[ $form_id ] ) ) {
            $cache[ $form_id ] = $this->choice_labels( $form_id );
        }
        return $cache[ $form_id ][ (string) $field_key ] ?? (array) $labels;
    }

    public function on_entry_created( $entry_id, $form_id = 0 ): void {
        $normalized = $this->build_normalized( (int) $entry_id );
        if ( $normalized !== null ) {
            $this->dispatch( $normalized );
        }
    }

    /**
     * Choice value => label for every option field on a Trinity form.
     *
     * Read from the form definition through Trinity's public Form class when it
     * is loaded; an unknown form or a changed API just means no labels.
     *
     * @return array<string,array<string,string>> field id => [ value => label ]
     */
    public function choice_labels( int $form_id ): array {
        if ( $form_id <= 0 || ! class_exists( "TrinityForms\\Form" ) ) {
            return [];
        }
        $post = get_post( $form_id );
        if ( ! $post instanceof \WP_Post ) {
            return [];
        }
        $out = [];
        try {
            $form = new \TrinityForms\Form( $post );
            foreach ( (array) $form->fields() as $field ) {
                $config  = is_object( $field ) && isset( $field->config ) && is_array( $field->config ) ? $field->config : [];
                $options = isset( $config["options"] ) && is_array( $config["options"] ) ? $config["options"] : [];
                $id      = (string) ( $config["id"] ?? "" );
                if ( $id === "" || ! $options ) {
                    continue;
                }
                foreach ( $options as $opt ) {
                    if ( is_array( $opt ) ) {
                        $label = (string) ( $opt["label"] ?? $opt["value"] ?? "" );
                        $value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
                    } else {
                        $label = $value = (string) $opt;
                    }
                    if ( $value !== "" && $label !== "" ) {
                        $out[ $id ][ $value ] = $label;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            return [];
        }
        return $out;
    }

    /**
     * Read a Trinity Forms entry + its meta and shape it into EntryVault's normalized
     * payload. Returns null if the entry doesn't exist.
     */
    public function build_normalized( int $entry_id ): ?array {
        global $wpdb;
        if ( $entry_id <= 0 ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant prefix.
        $entry = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, form_id, submitted_at FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d",
            $entry_id
        ), ARRAY_A );
        if ( ! $entry ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant prefix.
        $meta = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, field_label, field_type, value
             FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d ORDER BY id ASC",
            $entry_id
        ), ARRAY_A );

        // Trinity stores what was submitted (option values such as "pro", JSON
        // lists, file objects carrying the server path). Store what the person
        // chose instead: the option labels from the form, file names, Yes/No.
        $labels = $this->choice_labels( (int) $entry["form_id"] );

        $fields = [];
        foreach ( (array) $meta as $m ) {
            $key = (string) $m["field_id"];
            // Trinity's own bookkeeping rows (_quiz_score, _quiz_max, …) are
            // not answers the person gave.
            if ( $key === "" || $key[0] === "_" ) {
                continue;
            }
            $type  = (string) ( $m["field_type"] !== "" ? $m["field_type"] : "text" );
            $fields[] = [
                "key"   => $key,
                "label" => (string) ( $m["field_label"] !== "" ? $m["field_label"] : $key ),
                "type"  => $type,
                "value" => \EntryVault\Field_Values::display( $m["value"], $type, $labels[ $key ] ?? [] ),
            ];
        }

        $email = $this->pick_email( $fields );
        $title = $this->pick_title( $fields, $email );

        return [
            "source_plugin"   => $this->slug(),
            "source_form_id"  => (string) $entry["form_id"],
            "source_entry_id" => (string) $entry["id"],
            "title"           => $title,
            "email"           => $email,
            "fields"          => $fields,
            "created_at"      => (string) ( $entry["submitted_at"] ?: current_time( "mysql" ) ),
        ];
    }
}
