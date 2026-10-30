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
        // Trinity files spam without firing entry_created, so its spam never reached
        // EntryVault, not even with "Keep entries marked as spam" ticked, and nothing
        // was counted. Both now follow the setting, and an entry marked "not spam"
        // in Trinity afterwards comes to the board.
        add_action( "trinity_forms_spam_rejected", [ $this, "on_spam_rejected" ], 20, 2 );
        add_action( "trinity_forms_entry_status_changed", [ $this, "on_status_changed" ], 20, 2 );
        // An entry edited in Trinity kept its old email and message on the card,
        // and one deleted there (by hand, by its retention period, or by a
        // personal-data erasure) stayed in EntryVault for ever.
        add_action( "trinity_forms_entry_updated", [ $this, "on_entry_updated" ], 20, 1 );
        add_action( "trinity_forms_entry_deleted", [ $this, "on_entry_deleted" ], 20, 1 );
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

    public function on_spam_rejected( $entry_id, $form_id = 0 ): void {
        if ( $this->skip_as_spam( (string) (int) $form_id, (string) (int) $entry_id ) ) {
            return;
        }
        $this->on_entry_created( $entry_id, $form_id );
    }

    /**
     * Trinity moved an entry between Entries, Spam and Trash.
     *
     * Restored ("completed"): bring it in if EntryVault has no copy (it used to
     * look only in the skip list, which keeps the latest 100, so an older spam
     * entry restored there never arrived), and undo an archive we made.
     * Spam: archive the card when spam is left out, else tag it Spam.
     * Trash: archive the card, so it leaves the board but can come back.
     */
    public function on_status_changed( $entry_id, $status = "" ): void {
        $entry_ref = (string) (int) $entry_id;
        $status    = (string) $status;
        $ids       = \EntryVault\Submission_Store::find_by_source_entry( $this->slug(), $entry_ref );

        if ( "completed" === $status ) {
            self::forget_spam_skip( $this->slug(), $entry_ref );
            if ( ! $ids ) {
                // Not when the owner deleted the card here on purpose.
                if ( ! \EntryVault\Submission_Store::was_deleted_here( $this->slug(), $entry_ref ) ) {
                    $this->on_entry_created( $entry_id );
                }
                return;
            }
            $spam_tag = \EntryVault\Tag_Manager::spam_tag_id( false );
            foreach ( $ids as $id ) {
                \EntryVault\Submission_Store::unarchive_for_source( $id );
                if ( $spam_tag > 0 && in_array( $spam_tag, array_map( "intval", wp_list_pluck( \EntryVault\Tag_Manager::for_submission( $id ), "id" ) ), true ) ) {
                    \EntryVault\Tag_Manager::detach( $id, $spam_tag );
                }
            }
            return;
        }

        if ( "spam" === $status && ! self::skip_spam_enabled() ) {
            $spam_tag = \EntryVault\Tag_Manager::spam_tag_id();
            foreach ( $ids as $id ) {
                if ( $spam_tag > 0 ) {
                    \EntryVault\Tag_Manager::attach( $id, $spam_tag );
                }
            }
            return;
        }

        if ( "spam" === $status || "trash" === $status ) {
            foreach ( $ids as $id ) {
                \EntryVault\Submission_Store::archive_for_source( $id, $status );
            }
        }
    }

    /** An entry edited in Trinity: update the copy EntryVault already has. */
    public function on_entry_updated( $entry_id ): void {
        if ( \EntryVault\Submission_Store::find_by_source_entry( $this->slug(), (string) (int) $entry_id ) ) {
            $this->on_entry_created( $entry_id );
        }
    }

    /**
     * An entry deleted for good in Trinity. EntryVault keeps its copy as a
     * backup unless "Delete a card when its form entry is deleted" is ticked
     * in Settings; then the copy goes too, with its fields, notes and emails.
     */
    public function on_entry_deleted( $entry_id ): void {
        $entry_ref = (string) (int) $entry_id;
        self::forget_spam_skip( $this->slug(), $entry_ref );
        /**
         * Filter whether a submission is deleted when its Trinity Forms entry is.
         *
         * @param bool   $delete    Default from Settings > Data (off).
         * @param string $entry_ref Trinity entry id.
         */
        if ( ! apply_filters( "entryvault_delete_with_source_entry", self::delete_with_source_enabled(), $entry_ref, $this->slug() ) ) {
            // Kept as a backup, but no longer tied to that id: Trinity can hand the
            // same id to a new entry (MySQL resets AUTO_INCREMENT after the newest
            // rows are deleted), and the new lead was merged into this old card.
            global $wpdb;
            foreach ( \EntryVault\Submission_Store::find_by_source_entry( $this->slug(), $entry_ref ) as $id ) {
                $wpdb->update(
                    $wpdb->prefix . "entryvault_submissions",
                    [ "source_entry_id" => $entry_ref . "-deleted-" . $id ],
                    [ "id" => $id ],
                    [ "%s" ],
                    [ "%d" ]
                );
            }
            \EntryVault\Submission_Store::forget_deleted( $this->slug(), $entry_ref );
            return;
        }
        foreach ( \EntryVault\Submission_Store::find_by_source_entry( $this->slug(), $entry_ref ) as $id ) {
            \EntryVault\Submission_Store::delete( $id );
            \EntryVault\Logger::info( "sync", "Deleted a submission whose Trinity Forms entry was deleted", [
                "submission_id" => $id,
                "entry_id"      => $entry_ref,
            ] );
        }
        // The entry is gone for good: a later entry with this id is a new one.
        \EntryVault\Submission_Store::forget_deleted( $this->slug(), $entry_ref );
    }

    public function on_entry_created( $entry_id, $form_id = 0 ): void {
        $normalized = $this->build_normalized( (int) $entry_id );
        if ( $normalized === null ) {
            return;
        }
        // An entry Trinity imported from another plugin whose original is on the
        // board already (restored from spam, re-saved) is not a new lead.
        if ( ! \EntryVault\Submission_Store::find_by_source_entry( $this->slug(), (string) (int) $entry_id )
            && \EntryVault\Import_Dedupe::origin_copy_of( $normalized ) ) {
            return;
        }
        $this->dispatch( $normalized );
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
            "SELECT * FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d",
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
        $origin = [];
        foreach ( (array) $meta as $m ) {
            $key = (string) $m["field_id"];
            // Trinity's importer notes where an imported entry came from.
            if ( "_imported_from" === $key || "_imported_entry_id" === $key ) {
                $origin[ "_imported_from" === $key ? "source" : "entry_id" ] = (string) $m["value"];
                continue;
            }
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

        $out = [
            "source_plugin"   => $this->slug(),
            "source_form_id"  => (string) $entry["form_id"],
            "source_entry_id" => (string) $entry["id"],
            "title"           => $title,
            "email"           => $email,
            "fields"          => $fields,
            "created_at"      => self::created_at_utc( (string) ( $entry["submitted_at"] ?? "" ) ),
        ];
        if ( isset( $origin["source"], $origin["entry_id"] ) ) {
            $out["imported_from"] = $origin;
        }
        // Kept spam ("Keep entries marked as spam") is tagged Spam and no
        // automation answers it (Sync_Engine, Pro_Boot).
        if ( "spam" === (string) ( $entry["status"] ?? "" ) ) {
            $out["is_spam"] = true;
        }
        return $out;
    }

    /**
     * Trinity's submitted_at in UTC. Trinity 2.15.0 stores UTC and sets the
     * option trinity_forms_times_utc once its data is converted; older Trinity
     * stores the site's clock. Asking per entry keeps a new EntryVault with an
     * old Trinity (and the other way round) right.
     */
    public static function created_at_utc( string $submitted_at ): string {
        if ( "" === $submitted_at || 0 === strpos( $submitted_at, "0000" ) ) {
            return current_time( "mysql", true );
        }
        if ( get_option( "trinity_forms_times_utc" ) ) {
            return $submitted_at;
        }
        return \EntryVault\Dates::to_utc( $submitted_at );
    }
}
