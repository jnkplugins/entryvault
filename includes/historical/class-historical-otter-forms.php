<?php
namespace EntryVault\Historical;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads Otter's record posts directly.

/**
 * Past Otter Blocks form submissions.
 *
 * Otter (free, 3.x) saves each submission as an `otter_form_record` post with
 * one meta `otter_form_record_meta`: [ form => [ value: block formId ],
 * post_id => [ value: page id ], inputs => [ key => { label, value, type } ] ].
 * The input keys are the last 8 characters of the field id, the key the live
 * adapter uses too, and the entry id is the record post id, so a past entry
 * and its live capture are the same row. Records are grouped by the page the
 * form sits on; read and unread records come in, unpaid drafts and trash not.
 */
class Historical_Otter_Forms extends Provider_Base {

	public const TYPE = 'otter_form_record';
	public const META = 'otter_form_record_meta';

	public function source(): string {
		return 'otter-forms';
	}

	public function label(): string {
		return 'Otter Blocks';
	}

	protected function adapter_class(): string {
		return \EntryVault\Adapters\Adapter_Otter_Forms::class;
	}

	public function source_version(): string {
		return defined( 'OTTER_BLOCKS_VERSION' ) ? (string) OTTER_BLOCKS_VERSION : '';
	}

	public function installed(): bool {
		return defined( 'OTTER_BLOCKS_VERSION' ) || $this->available();
	}

	public function available(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", self::TYPE ) );
	}

	public function schema_check(): array {
		global $wpdb;
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::TYPE ) );
		$keyed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s", self::META, self::TYPE ) );
		$ok    = 0 === $posts || $keyed > 0;
		return [ 'ok' => $ok, 'missing' => $ok ? [] : [ 'postmeta.' . self::META ] ];
	}

	/** @return array<int,array{id:int,meta:array,date:string}> All live records, oldest first. */
	private function records(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_date, m.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			  WHERE p.post_type = %s AND p.post_status IN ( 'unread', 'read', 'publish' ) ORDER BY p.ID ASC",
			self::META,
			self::TYPE
		) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$meta  = \EntryVault\Safe_Unserialize::maybe( $r->meta_value );
			$out[] = [ 'id' => (int) $r->ID, 'meta' => is_array( $meta ) ? $meta : [], 'date' => (string) $r->post_date ];
		}
		return $out;
	}

	private static function page_of( array $meta ): int {
		return (int) ( $meta['post_id']['value'] ?? 0 );
	}

	public function forms(): array {
		$pages = [];
		foreach ( $this->records() as $r ) {
			$pages[ self::page_of( $r['meta'] ) ] = true;
		}
		ksort( $pages );
		$out = [];
		foreach ( array_keys( $pages ) as $page ) {
			$title = $page > 0 ? (string) get_the_title( $page ) : '';
			/* translators: %s: page title. */
			$out[] = [ 'id' => max( 1, $page ), 'title' => '' !== $title ? sprintf( __( 'Form on "%s"', 'entryvault' ), $title ) : __( 'Forms on an unknown page', 'entryvault' ) ];
		}
		return $out;
	}

	public function count( int $form_id ): ?int {
		return count( $this->for_page( $form_id ) );
	}

	private function for_page( int $form_id ): array {
		return array_values( array_filter( $this->records(), static fn( $r ) => max( 1, self::page_of( $r['meta'] ) ) === $form_id ) );
	}

	public function entries( int $form_id, int $offset, int $limit ): array {
		$adapter = $this->adapter();
		if ( ! $adapter instanceof \EntryVault\Adapters\Adapter_Otter_Forms ) {
			return [];
		}
		$out     = [];
		foreach ( array_slice( $this->for_page( $form_id ), $offset, $limit ) as $r ) {
			$fields = [];
			foreach ( (array) ( $r['meta']['inputs'] ?? [] ) as $key => $input ) {
				if ( ! is_array( $input ) ) {
					continue;
				}
				$type  = (string) ( $input['type'] ?? '' );
				$value = $input['value'] ?? '';
				if ( 'file' === $type ) {
					$value = (string) ( $input['metadata']['name'] ?? ( is_scalar( $value ) ? $value : '' ) );
				}
				$value    = is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value;
				$label    = trim( wp_strip_all_tags( (string) ( $input['label'] ?? '' ) ) );
				$fields[] = [
					'key'   => sanitize_key( 'file' === $type ? substr( (string) $key, 0, 8 ) : (string) $key ) ?: 'field_' . count( $fields ),
					'label' => '' !== $label ? $label : 'Field ' . ( count( $fields ) + 1 ),
					'type'  => 'tel' === $type ? 'phone' : ( 'multiple' === $type ? 'checkbox' : ( '' !== $type ? $type : 'text' ) ),
					'value' => $value,
				];
			}
			if ( ! $fields ) {
				$out[] = [ 'source_entry_id' => (string) $r['id'], 'payload' => null ];
				continue;
			}
			$form_key = (string) ( $r['meta']['form']['value'] ?? '' );
			$page     = self::page_of( $r['meta'] );
			$form_key = '' !== $form_key ? $form_key : ( $page > 0 ? (string) $page : '0' );
			$payload  = $adapter->build_entry_payload( $form_key, (string) $r['id'], $fields, $page > 0 ? (string) get_the_title( $page ) : '', \EntryVault\Dates::to_utc( $r['date'] ) ?: null ); // a post: post_date is the site's clock.
			$out[]    = [ 'source_entry_id' => (string) $r['id'], 'payload' => $payload ];
		}
		return $out;
	}
}
