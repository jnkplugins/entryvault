<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for MW WP Form (free on wordpress.org, popular in Japan).
 *
 * MW WP Form fires `mwform_after_send_{form_key}` ( MW_WP_Form_Data $data )
 * after the admin mail went out, where form_key is "mw-wp-form-<post id>". The
 * hook name carries the form, so one listener is added per published form.
 * Fields have names only (no labels), so labels come from the names. When the
 * form saves to the database ("usedb"), the saved post id is the entry id;
 * otherwise an id of our own ("mwf_…").
 */
class Adapter_MW_WP_Form extends Adapter_Base {

	public const POST_TYPE = 'mw-wp-form';

	public function slug(): string {
		return 'mw-wp-form';
	}

	public function is_active(): bool {
		return class_exists( '\\MW_WP_Form' ) || defined( 'MWF_VERSION' ) || class_exists( '\\MWF_Config' );
	}

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'listen' ], 99 );
	}

	/** One listener per published MW WP Form form. */
	public function listen(): void {
		$ids = get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids', 'no_found_rows' => true ] );
		foreach ( (array) $ids as $id ) {
			add_action( 'mwform_after_send_mw-wp-form-' . (int) $id, [ $this, 'on_after_send' ], 20, 1 );
		}
	}

	/** @param object $data MW_WP_Form_Data */
	public function on_after_send( $data ): void {
		if ( ! is_object( $data ) || ! method_exists( $data, 'gets' ) ) {
			return;
		}
		$key     = method_exists( $data, 'get_form_key' ) ? (string) $data->get_form_key() : '';
		$form_id = (int) preg_replace( '/\D+/', '', $key );
		$values  = [];
		foreach ( array_keys( (array) $data->gets() ) as $name ) {
			$values[ (string) $name ] = method_exists( $data, 'get' ) ? $data->get( $name ) : '';
		}
		$saved   = method_exists( $data, 'get_saved_mail_id' ) ? (int) $data->get_saved_mail_id() : 0;
		$payload = $this->payload( $form_id, $values, $saved, self::button_names( $form_id ) );
		if ( $payload ) {
			$this->dispatch( $payload );
		}
	}

	/**
	 * Names of the form's buttons (their value is posted like a field).
	 *
	 * @return string[]
	 */
	public static function button_names( int $form_id ): array {
		$content = $form_id > 0 ? (string) get_post_field( 'post_content', $form_id ) : '';
		preg_match_all( '/\[mwform_(?:submitButton|submit|backButton|bbutton|button)\b[^\]]*\bname="([^"]+)"/i', $content, $m );
		return array_values( array_unique( array_merge( [ 'submitConfirm', 'submitBack', 'submit', 'send' ], $m[1] ) ) );
	}

	/**
	 * @param array<string,mixed> $values  name => value.
	 * @param string[]            $buttons Button names to leave out.
	 */
	public function payload( int $form_id, array $values, int $saved_id = 0, array $buttons = [] ): ?array {
		$fields = [];
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( in_array( $name, $buttons, true ) ) {
				continue;
			}
			// MW WP Form's own bookkeeping: token, form id, upload map, children.
			if ( '' === $name || '_' === $name[0] || preg_match( '/^(mw[-_]wp[-_]form|mwf_|mw_wp_form|__)/i', $name ) || 'token' === $name ) {
				continue;
			}
			$value    = $this->flatten_value( $value );
			$label    = ucwords( trim( str_replace( [ '-', '_' ], ' ', $name ) ) );
			$fields[] = [
				'key'   => sanitize_key( $name ) ?: 'field_' . count( $fields ),
				'label' => $label,
				'type'  => $this->infer_type( $label, $value ),
				'value' => $value,
			];
		}
		if ( ! $fields ) {
			return null;
		}
		$entry = $saved_id > 0 ? (string) $saved_id : $this->make_entry_id( 'mwf_', (string) $form_id, $values );
		$title = $form_id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $form_id ) : '';
		return $this->build_payload( (string) $form_id, $entry, $fields, $title );
	}
}
