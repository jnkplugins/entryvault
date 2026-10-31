<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Timeline {

    public static function log( int $submission_id, string $type, ?int $user_id = null, array $data = [], ?int $parent_id = null, array $meta = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'entryvault_timeline', [
            'submission_id' => $submission_id,
            'parent_id'     => $parent_id ?: null,
            'user_id'       => $user_id ?: null,
            'type'          => substr( $type, 0, 20 ),
            'data'          => $data ? wp_json_encode( $data ) : null,
            'meta'          => $meta ? wp_json_encode( $meta ) : null,
            'created_at'    => current_time( 'mysql', true ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * One readable sentence for a timeline row (data already decoded).
     *
     * The panel used to print stage moves as "Moved from 1 → 2" and any type it
     * did not know as raw JSON. Names are resolved here, where the database is.
     * Extensions describe their own event types through the
     * entryvault_timeline_summary filter.
     *
     * @param array<string,mixed> $row id, type, data (array|null), user_id, created_at.
     */
    public static function summary( array $row ): string {
        $type = (string) ( $row['type'] ?? '' );
        $d    = is_array( $row['data'] ?? null ) ? $row['data'] : [];
        $s    = '';

        switch ( $type ) {
            case 'stage_changed':
            case 'stage_change':
                $from = (int) ( $d['from_stage_id'] ?? ( $d['from'] ?? 0 ) );
                $to   = (int) ( $d['to_stage_id'] ?? ( $d['to'] ?? 0 ) );
                /* translators: 1: previous stage name, 2: new stage name. */
                $s = sprintf( __( 'Moved from %1$s to %2$s', 'entryvault' ), self::stage_name( $from ), self::stage_name( $to ) );
                if ( ! empty( $d['to_pipeline_id'] ) && (int) ( $d['from_pipeline_id'] ?? 0 ) !== (int) $d['to_pipeline_id'] ) {
                    /* translators: 1: previous pipeline name, 2: new pipeline name. */
                    $s .= ' ' . sprintf( __( '(pipeline %1$s → %2$s)', 'entryvault' ), self::pipeline_name( (int) ( $d['from_pipeline_id'] ?? 0 ) ), self::pipeline_name( (int) $d['to_pipeline_id'] ) );
                }
                break;
            case 'note':
                $s = ! empty( $d['deleted'] )
                    ? __( 'Note deleted', 'entryvault' )
                    : (string) ( $d['body'] ?? '' );
                break;
            case 'status':
                $event = self::event_label( (string) ( $d['event'] ?? 'created' ) );
                $s     = ! empty( $d['source'] )
                    /* translators: 1: what happened ("Created", "Updated"), 2: form plugin name. */
                    ? sprintf( __( '%1$s from %2$s', 'entryvault' ), $event, Sources::label( (string) $d['source'] ) )
                    : $event;
                break;
            case 'email_sent':
                /* translators: 1: recipient, 2: subject. */
                $s = sprintf( __( 'Email to %1$s: %2$s', 'entryvault' ), (string) ( $d['to'] ?? '' ), (string) ( $d['subject'] ?? '' ) );
                if ( array_key_exists( 'sent', $d ) && ! $d['sent'] ) {
                    /* translators: 1: recipient, 2: why it failed. */
                    $s = sprintf( __( 'Email to %1$s NOT sent: %2$s', 'entryvault' ), (string) ( $d['to'] ?? '' ), (string) ( $d['error'] ?? '' ) );
                }
                break;
            case 'tag_added':
            case 'tag_removed':
                $name = (string) ( $d['tag_name'] ?? '' );
                if ( '' === $name && ! empty( $d['tag_id'] ) ) {
                    $name = self::tag_name( (int) $d['tag_id'] );
                }
                $s = 'tag_added' === $type
                    /* translators: %s: tag name. */
                    ? sprintf( __( 'Tagged %s', 'entryvault' ), $name )
                    /* translators: %s: tag name. */
                    : sprintf( __( 'Removed tag %s', 'entryvault' ), $name );
                break;
            case 'owner_assigned':
                /* translators: %s: user display name. */
                $s = sprintf( __( 'Assigned to %s', 'entryvault' ), self::user_name( (int) ( $d['to'] ?? ( $d['user_id'] ?? 0 ) ) ) );
                if ( ! empty( $d['from'] ) ) {
                    /* translators: %s: user display name. */
                    $s .= ' ' . sprintf( __( '(was %s)', 'entryvault' ), self::user_name( (int) $d['from'] ) );
                }
                break;
            case 'owner_unassigned':
                $s = ! empty( $d['from'] )
                    /* translators: %s: user display name. */
                    ? sprintf( __( 'Unassigned from %s', 'entryvault' ), self::user_name( (int) $d['from'] ) )
                    : __( 'Unassigned', 'entryvault' );
                break;
            case 'archived':
                $s = __( 'Archived', 'entryvault' );
                if ( 'spam' === ( $d['source_status'] ?? '' ) ) {
                    /* translators: %s: form plugin name. */
                    $s = sprintf( __( 'Archived: marked as spam in %s', 'entryvault' ), Sources::label( (string) ( $d['source'] ?? '' ) ) );
                } elseif ( 'trash' === ( $d['source_status'] ?? '' ) ) {
                    /* translators: %s: form plugin name. */
                    $s = sprintf( __( 'Archived: moved to the trash in %s', 'entryvault' ), Sources::label( (string) ( $d['source'] ?? '' ) ) );
                }
                break;
            case 'unarchived':
                $s = __( 'Restored from the archive', 'entryvault' );
                break;
            case 'ai_read':
                /* translators: %s: user display name the assistant signed in as. */
                $s = sprintf( __( 'Opened by an AI assistant signed in as %s', 'entryvault' ), self::user_name( (int) ( $row['user_id'] ?? 0 ) ) );
                break;
            case 'ai_change':
                /* translators: %s: what the assistant changed. */
                $s = sprintf( __( 'Changed by an AI assistant: %s', 'entryvault' ), (string) ( $d['summary'] ?? '' ) );
                break;
        }

        /**
         * Filter the sentence shown for a timeline row.
         *
         * @param string $s    Summary ('' when core does not know the type).
         * @param string $type Event type.
         * @param array  $d    Decoded event data.
         * @param array  $row  The whole row.
         */
        $s = (string) apply_filters( 'entryvault_timeline_summary', $s, $type, $d, $row );

        if ( '' === $s ) {
            $s = ucfirst( str_replace( '_', ' ', $type ) );
        }
        return $s;
    }

    /**
     * The words for a stored status event key. The key stays in the database
     * ("created", "updated"); printing it raw gave "Created من WPForms" on an
     * Arabic site.
     */
    public static function event_label( string $event ): string {
        switch ( $event ) {
            case 'created':
                return _x( 'Created', 'timeline event: an entry arrived', 'entryvault' );
            case 'updated':
                return _x( 'Updated', 'timeline event: the form plugin changed an entry', 'entryvault' );
        }
        return ucfirst( str_replace( '_', ' ', $event ) );
    }

    private static function stage_name( int $id ): string {
        global $wpdb;
        if ( $id <= 0 ) {
            return '—';
        }
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}entryvault_stages WHERE id = %d", $id ) );
        /* translators: %d: stage id. */
        return $name ? Default_Names::label( $name ) : sprintf( __( 'a deleted stage (#%d)', 'entryvault' ), $id );
    }

    private static function pipeline_name( int $id ): string {
        global $wpdb;
        $name = $id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}entryvault_pipelines WHERE id = %d", $id ) ) : '';
        return $name ? Default_Names::label( $name ) : '#' . $id;
    }

    private static function tag_name( int $id ): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}entryvault_tags WHERE id = %d", $id ) );
        return $name ? (string) $name : '#' . $id;
    }

    private static function user_name( int $id ): string {
        if ( $id <= 0 ) {
            return __( 'nobody', 'entryvault' );
        }
        $u = get_user_by( 'id', $id );
        return $u ? (string) $u->display_name : '#' . $id;
    }

    /**
     * Update the meta JSON column on a timeline row.
     */
    public static function update_meta( int $id, array $meta ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $wpdb->prefix . 'entryvault_timeline',
            [ 'meta' => $meta ? wp_json_encode( $meta ) : null ],
            [ 'id' => $id ]
        );
        return $ok !== false;
    }

    /**
     * Fetch a single timeline row by id.
     *
     * @return array<string,mixed>|null
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}entryvault_timeline WHERE id = %d",
            $id
        ), ARRAY_A );
        return $row ?: null;
    }
}
