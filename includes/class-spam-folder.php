<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own submissions table.

/**
 * The Spam folder: entries with status "spam".
 *
 * An entry lands here when its form plugin marked it as spam and "Leave out
 * entries your form plugin marked as spam" is unticked (it used to go onto the
 * board with a Spam tag), or when someone moves it here from Submissions. It
 * keeps the pipeline and stage it would have gone to, so "Not spam" just opens
 * it: the board, counts, reports, exports and public views never see spam.
 *
 * Automations do not run for spam. "Not spam" can run the ones it skipped when
 * the owner ticks that (an extension listens to entryvault_spam_released).
 */
class Spam_Folder {

	public const STATUS = 'spam';

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'entryvault_submissions';
	}

	/**
	 * How many entries wait in the Spam folder (for the current user's pipelines).
	 */
	public static function count(): int {
		global $wpdb;
		$sql    = 'SELECT COUNT(*) FROM %i WHERE status = %s';
		$params = [ self::table(), self::STATUS ];
		if ( ! Capabilities::current_user_can_manage() ) {
			$ids = array_values( array_filter( array_map( 'intval', Capabilities::viewable_pipeline_ids_for_current_user() ) ) );
			if ( ! $ids ) {
				return 0;
			}
			$sql   .= ' AND pipeline_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $ids );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Move entries into the Spam folder.
	 *
	 * @param int[] $ids
	 */
	public static function mark( array $ids, ?int $actor = null ): int {
		global $wpdb;
		$n = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$row = $id > 0 ? Submission_Store::get( $id ) : null;
			if ( ! $row || self::STATUS === (string) $row['status'] ) {
				continue;
			}
			$wpdb->update( self::table(), [ 'status' => self::STATUS, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			Timeline::log( $id, 'spam_marked', $actor, [ 'from' => (string) $row['status'] ] );
			$n++;
		}
		return $n;
	}

	/**
	 * "Not spam": open the entries in the pipeline and stage they would have
	 * gone to, take the Spam tag off, and say so on the timeline.
	 *
	 * @param int[] $ids
	 * @param bool  $run_automations Run the "submission created" rules it skipped.
	 */
	public static function release( array $ids, bool $run_automations = false, ?int $actor = null ): int {
		global $wpdb;
		$spam_tag = Tag_Manager::spam_tag_id( false );
		$n        = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$row = $id > 0 ? Submission_Store::get( $id ) : null;
			if ( ! $row || self::STATUS !== (string) $row['status'] ) {
				continue;
			}
			$wpdb->update( self::table(), [ 'status' => 'open', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			if ( $spam_tag > 0 ) {
				$wpdb->delete( $wpdb->prefix . 'entryvault_submission_tags', [ 'submission_id' => $id, 'tag_id' => $spam_tag ] );
			}
			Timeline::log( $id, 'spam_released', $actor, [ 'automations' => $run_automations ? 1 : 0 ] );
			/**
			 * An entry came out of the Spam folder.
			 *
			 * @param int  $id              Submission id.
			 * @param bool $run_automations The owner asked to run what it skipped.
			 */
			do_action( 'entryvault_spam_released', $id, $run_automations );
			$n++;
		}
		return $n;
	}

	/**
	 * Delete spam for good: the given ids, or the whole folder (the current
	 * user's pipelines) when $ids is null.
	 *
	 * @param int[]|null $ids
	 */
	public static function delete( ?array $ids = null ): int {
		global $wpdb;
		if ( null === $ids ) {
			$sql    = 'SELECT id FROM %i WHERE status = %s';
			$params = [ self::table(), self::STATUS ];
			if ( ! Capabilities::current_user_can_manage() ) {
				$vp = array_values( array_filter( array_map( 'intval', Capabilities::viewable_pipeline_ids_for_current_user() ) ) );
				if ( ! $vp ) {
					return 0;
				}
				$sql   .= ' AND pipeline_id IN (' . implode( ',', array_fill( 0, count( $vp ), '%d' ) ) . ')';
				$params = array_merge( $params, $vp );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql . ' LIMIT 5000', $params ) ) );
		}
		$n = 0;
		foreach ( $ids as $id ) {
			$row = Submission_Store::get( (int) $id );
			if ( $row && self::STATUS === (string) $row['status'] && Submission_Store::delete( (int) $id ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * The label of the "also run what it skipped" checkbox on "Not spam", or ''
	 * when nothing would run (no extension that runs rules on new entries).
	 */
	public static function release_option_label(): string {
		/**
		 * Filter the label of the "run what was skipped" option offered with "Not spam".
		 *
		 * @param string $label '' hides the option.
		 */
		return (string) apply_filters( 'entryvault_spam_release_option', '' );
	}
}
