<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * One stored field value as a person should read it.
 *
 * Form plugins hand over checkbox groups as JSON lists, consent boxes as "1",
 * repeaters as lists of objects and file uploads as objects that include the
 * absolute path on the server. Every screen and export printed those raw:
 * `["a"]`, `{"filename":…,"path":"/home/…"}`. They all go through here now.
 */
class Field_Values {

	/** Keys that describe where a file sits on disk. Never shown. */
	private const HIDDEN_KEYS = [ 'path', 'filepath', 'file_path', 'tmp_name', 'dir', 'realpath' ];

	/**
	 * @param mixed  $value Stored value (string, possibly JSON, or already decoded).
	 * @param string $type  Stored field type, when known.
	 * @param array<string,string> $labels Choice value => label, when known.
	 */
	public static function display( $value, string $type = '', array $labels = [] ): string {
		if ( is_string( $value ) ) {
			$trim = trim( $value );
			if ( '' !== $trim && ( '[' === $trim[0] || '{' === $trim[0] ) ) {
				$decoded = json_decode( $trim, true );
				if ( is_array( $decoded ) ) {
					$value = $decoded;
				}
			}
		}

		if ( 'consent' === $type || 'acceptance' === $type ) {
			$yes = is_array( $value )
				? [] !== array_filter( $value )
				: ! in_array( strtolower( trim( (string) $value ) ), [ '', '0', 'no', 'false', 'off' ], true );
			return $yes ? __( 'Yes', 'entryvault' ) : __( 'No', 'entryvault' );
		}

		if ( ! is_array( $value ) ) {
			$text = is_scalar( $value ) || null === $value ? (string) $value : '';
			return (string) ( $labels[ $text ] ?? $text );
		}

		return self::flatten( $value, $labels );
	}

	/**
	 * Every spelling a stored value can be compared as.
	 *
	 * Choice fields used to be stored as option values ("pro", ["a"], "1") and
	 * are now stored as what the person saw ("Pro", "Option A", "Yes"). A rule
	 * written against either form must keep matching entries stored in either
	 * form, so matchers compare against all of these.
	 *
	 * @param mixed                $stored Stored value.
	 * @param array<string,string> $labels Choice value => label for the field.
	 * @return string[]
	 */
	public static function match_candidates( $stored, string $type = '', array $labels = [] ): array {
		$raw      = is_scalar( $stored ) || null === $stored ? (string) $stored : (string) wp_json_encode( $stored );
		$out      = [ $raw ];
		$by_label = [];
		foreach ( $labels as $value => $label ) {
			$by_label[ strtolower( (string) $label ) ] = (string) $value;
		}

		// The individual choices, whichever form they were stored in.
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
			$items = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $decoded );
		} elseif ( $labels && false !== strpos( $raw, ', ' ) ) {
			$items = explode( ', ', $raw );
		} else {
			$items = [ $raw ];
		}

		$as_values = [];
		$as_labels = [];
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$value       = $by_label[ strtolower( $item ) ] ?? $item;
			$as_values[] = $value;
			$as_labels[] = $labels[ $value ] ?? $item;
		}
		if ( $as_values ) {
			$out[] = implode( ', ', $as_values );
			$out[] = implode( ', ', $as_labels );
			$out[] = (string) wp_json_encode( $as_values );
			foreach ( array_merge( $as_values, $as_labels ) as $one ) {
				$out[] = $one;
			}
		}

		if ( 'consent' === $type || 'acceptance' === $type ) {
			$yes = __( 'Yes', 'entryvault' ) === self::display( $raw, $type );
			$out = array_merge( $out, $yes ? [ 'Yes', '1', 'true', 'on' ] : [ 'No', '0', '' ] );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Candidates for one field of one submission, or null when it has no such field.
	 *
	 * The label map comes from the source form plugin through the
	 * `entryvault_field_choice_labels` filter, so old stored values and new
	 * stored labels resolve to each other.
	 *
	 * @return string[]|null
	 */
	public static function candidates_for( int $submission_id, string $field_key ): ?array {
		global $wpdb;
		if ( $submission_id <= 0 || '' === $field_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- single-row lookup on a custom table.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.value, d.field_type, s.source_plugin, s.source_form_id
			   FROM {$wpdb->prefix}entryvault_submission_data d
			   INNER JOIN {$wpdb->prefix}entryvault_submissions s ON s.id = d.submission_id
			  WHERE d.submission_id = %d AND d.field_key = %s
			  ORDER BY d.is_stale ASC, d.id ASC LIMIT 1",
			$submission_id,
			$field_key
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		/**
		 * Choice value => label for a captured field, supplied by its source adapter.
		 *
		 * @param array<string,string> $labels    Empty by default.
		 * @param string               $source    Stored source_plugin.
		 * @param string               $form_id   Stored source_form_id.
		 * @param string               $field_key Field key.
		 */
		$labels = (array) apply_filters( 'entryvault_field_choice_labels', [], (string) $row['source_plugin'], (string) $row['source_form_id'], $field_key );
		return self::match_candidates( $row['value'], (string) $row['field_type'], array_map( 'strval', $labels ) );
	}

	/**
	 * @param array<mixed>         $value
	 * @param array<string,string> $labels
	 */
	private static function flatten( array $value, array $labels ): string {
		// A single file upload object.
		if ( isset( $value['filename'] ) && is_scalar( $value['filename'] ) ) {
			return (string) $value['filename'];
		}

		$is_list = array_is_list( $value );
		$parts   = [];
		foreach ( $value as $key => $item ) {
			if ( ! $is_list && in_array( strtolower( (string) $key ), self::HIDDEN_KEYS, true ) ) {
				continue;
			}
			if ( is_array( $item ) ) {
				$text = self::flatten( $item, $labels );
			} elseif ( is_bool( $item ) ) {
				$text = $item ? __( 'Yes', 'entryvault' ) : '';
			} elseif ( is_scalar( $item ) ) {
				$text = (string) ( $labels[ (string) $item ] ?? $item );
			} else {
				$text = '';
			}
			$text = trim( $text );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		// Lists (checkbox choices, several files, repeater rows) read as a list;
		// an object's parts (first + last name, address lines) read as one phrase.
		return implode( $is_list ? ', ' : ' ', $parts );
	}
}
