<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Custom capabilities for EntryVault.
 *
 * Introduces:
 *   - entryvault_manage         — primitive cap, granted to administrators.
 *   - entryvault_view_pipeline  — meta cap, requires (int $pipeline_id) arg.
 *   - entryvault_edit_pipeline  — meta cap, requires (int $pipeline_id) arg.
 *
 * The two meta caps resolve via map_meta_cap(): a user passes if
 *   (a) they have entryvault_manage, OR
 *   (b) their entryvault_pipeline_access user-meta array contains the
 *       requested pipeline_id.
 *
 * Plugin::boot() is expected to call:
 *   add_action( 'init',          [ Capabilities::class, 'maybe_seed_admin' ] );
 *   add_filter( 'map_meta_cap',  [ Capabilities::class, 'map_meta_cap' ], 10, 4 );
 *
 * Tests can call seed/map_meta_cap directly.
 */
class Capabilities {

    public const MANAGE        = 'entryvault_manage';
    public const VIEW_PIPELINE = 'entryvault_view_pipeline';
    public const EDIT_PIPELINE = 'entryvault_edit_pipeline';

    private const SEED_OPTION = 'entryvault_caps_seeded';

    /**
     * Idempotently grant entryvault_manage to administrators on first run.
     * Hook this on `init` (do NOT touch Activator).
     */
    public static function maybe_seed_admin(): void {
        if ( function_exists( 'get_option' ) && get_option( self::SEED_OPTION ) ) {
            return;
        }
        if ( function_exists( 'get_role' ) ) {
            $role = get_role( 'administrator' );
            if ( $role && method_exists( $role, 'add_cap' ) ) {
                $role->add_cap( self::MANAGE );
            }
        }
        if ( function_exists( 'update_option' ) ) {
            update_option( self::SEED_OPTION, 1 );
        }
    }

    /**
     * map_meta_cap filter: resolve our two meta caps to primitive checks.
     *
     * Signature: function ( array $caps, string $cap, int $user_id, array $args ): array
     */
    public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
        if ( $cap !== self::VIEW_PIPELINE && $cap !== self::EDIT_PIPELINE ) {
            return $caps;
        }
        $pipeline_id = isset( $args[0] ) ? (int) $args[0] : 0;
        $user_id     = (int) $user_id;

        // Admins / managers always pass.
        if ( $user_id > 0 && function_exists( 'user_can' ) ) {
            if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, self::MANAGE ) ) {
                return [ 'exist' ];
            }
        }

        if ( $pipeline_id > 0 && self::user_has_pipeline_access( $user_id, $pipeline_id ) ) {
            return [ 'exist' ];
        }

        // Otherwise require manage — non-admins without explicit access are denied.
        return [ self::MANAGE ];
    }

    /**
     * Read user-meta `entryvault_pipeline_access` and check membership.
     */
    public static function user_has_pipeline_access( int $user_id, int $pipeline_id ): bool {
        if ( $user_id <= 0 || $pipeline_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
            return false;
        }
        $list = get_user_meta( $user_id, 'entryvault_pipeline_access', true );
        if ( ! is_array( $list ) ) {
            return false;
        }
        return in_array( $pipeline_id, array_map( 'intval', $list ), true );
    }

    /**
     * Helper: can the current user view a specific pipeline?
     *
     * Falls back to manage so admins always pass without per-pipeline meta.
     */
    public static function current_user_can_view_pipeline( int $pipeline_id ): bool {
        if ( self::current_user_can_manage() ) {
            return true;
        }
        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $uid > 0 && self::user_has_pipeline_access( $uid, $pipeline_id ) ) {
            return true;
        }
        return false;
    }

    /**
     * Helper: can the current user manage EntryVault globally?
     *
     * Passes for admins (manage_options) and for users with the explicit
     * entryvault_manage cap.
     */
    public static function current_user_can_manage(): bool {
        if ( ! function_exists( 'current_user_can' ) ) {
            return false;
        }
        return current_user_can( 'manage_options' ) || current_user_can( self::MANAGE );
    }

    /**
     * Can the current user use the board at all: a manager, or someone who has
     * been granted at least one pipeline? Used for the Kanban menu item and the
     * read-only lookups the board needs before it shows anything (pipeline list,
     * owners, tags). Per-record checks still apply on top of this.
     */
    public static function current_user_has_pipeline_access(): bool {
        if ( self::current_user_can_manage() ) {
            return true;
        }
        return ! empty( self::viewable_pipeline_ids_for_current_user() );
    }

    /**
     * Can the current user see and work on one submission? True for managers,
     * and for users granted the submission's pipeline.
     */
    public static function current_user_can_view_submission( int $submission_id ): bool {
        if ( self::current_user_can_manage() ) {
            return true;
        }
        if ( $submission_id <= 0 ) {
            return false;
        }
        $row = Submission_Store::get( $submission_id );
        if ( ! $row ) {
            return false;
        }
        return self::current_user_can_view_pipeline( (int) ( $row['pipeline_id'] ?? 0 ) );
    }

    /**
     * Permission-callback helper: read the submission id from a REST request
     * (or anything exposing get_param) and check it.
     *
     * @param mixed $req
     */
    public static function request_can_view_submission( $req ): bool {
        if ( self::current_user_can_manage() ) {
            return true;
        }
        $id = 0;
        if ( is_object( $req ) && method_exists( $req, 'get_param' ) ) {
            $id = (int) $req->get_param( 'id' );
        } elseif ( is_array( $req ) && isset( $req['id'] ) ) {
            $id = (int) $req['id'];
        }
        return self::current_user_can_view_submission( $id );
    }

    /**
     * List the pipeline ids the current user is allowed to view. Returns
     * an empty array for managers (interpret as "no restriction") so the
     * caller can decide whether to apply a filter.
     *
     * @return int[]
     */
    public static function viewable_pipeline_ids_for_current_user(): array {
        if ( self::current_user_can_manage() ) {
            return [];
        }
        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $uid <= 0 ) {
            return [];
        }
        $list = function_exists( 'get_user_meta' ) ? get_user_meta( $uid, 'entryvault_pipeline_access', true ) : [];
        if ( ! is_array( $list ) ) {
            return [];
        }
        return array_values( array_unique( array_map( 'intval', $list ) ) );
    }
}
