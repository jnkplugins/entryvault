<?php
namespace EntryVault\Historical;

use EntryVault\Adapters\Adapter_WS_Form;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- form list and counts from WS Form's own tables.

/**
 * Past WS Form entries (WS Form Lite and Pro both keep submissions).
 *
 * Read through WS Form's own submit class (WS_Form_Submit::db_read_all with
 * its meta), the same object the live hook hands us, and turned into a payload
 * by the live adapter, so keys, labels and the entry id (the submit id) match
 * live captures and nothing is copied twice. Only submitted entries
 * (status "publish") are brought in: not drafts (unfinished), spam or trash.
 * WS Form stores dates in UTC.
 */
class Historical_WS_Form extends Provider_Base {

	public function source(): string {
		return 'ws-form';
	}

	public function label(): string {
		return 'WS Form';
	}

	public function access(): string {
		return self::ACCESS_API;
	}

	public function source_version(): string {
		return defined( 'WS_FORM_VERSION' ) ? (string) WS_FORM_VERSION : '';
	}

	protected function adapter_class(): string {
		return Adapter_WS_Form::class;
	}

	public function installed(): bool {
		return class_exists( '\\WS_Form_Submit' );
	}

	public function available(): bool {
		global $wpdb;
		return $this->installed() && self::table_exists( $wpdb->prefix . 'wsf_submit' );
	}

	public function count( int $form_id ): ?int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wsf_submit WHERE form_id = %d AND status = 'publish'", $form_id ) );
	}

	public function forms(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT DISTINCT s.form_id, f.label FROM {$wpdb->prefix}wsf_submit s LEFT JOIN {$wpdb->prefix}wsf_form f ON f.id = s.form_id WHERE s.status = 'publish' ORDER BY s.form_id ASC" );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			$id = (int) $r->form_id;
			if ( $id > 0 ) {
				$out[] = [ 'id' => $id, 'title' => '' !== (string) $r->label ? (string) $r->label : 'Form #' . $id ];
			}
		}
		return $out;
	}

	public function entries( int $form_id, int $offset, int $limit ): array {
		$adapter = $this->adapter();
		if ( ! $adapter instanceof Adapter_WS_Form || ! class_exists( '\\WS_Form_Submit' ) ) {
			return [];
		}
		try {
			$reader  = new \WS_Form_Submit();
			$submits = $reader->db_read_all( '', 'status = \'publish\' AND form_id = ' . (int) $form_id, '', 'id ASC', (string) $limit, (string) $offset, true, false, true );
		} catch ( \Throwable $e ) {
			return [];
		}
		$labels = null;
		$out    = [];
		foreach ( (array) $submits as $submit ) {
			$id = (string) (int) ( $submit->id ?? 0 );
			if ( null === $labels ) {
				$labels = $adapter->field_labels( $submit, $form_id );
			}
			$payload = $adapter->payload_for_submit( $submit, (string) ( $submit->date_added ?? '' ) ?: null, $labels );
			$out[]   = [ 'source_entry_id' => $id, 'payload' => $payload['fields'] ? $payload : null ];
		}
		return $out;
	}
}
