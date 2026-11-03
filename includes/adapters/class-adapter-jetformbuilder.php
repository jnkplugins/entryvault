<?php
namespace EntryVault\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for JetFormBuilder (Crocoblock, free on wordpress.org).
 *
 * JetFormBuilder fires `jet-form-builder/form-handler/after-send`
 * ( Form_Handler $handler, bool $is_success ) once a submit has run its actions.
 * The values come from its request context (jet_fb_context()), with each
 * field's label and type from the form's blocks. When the form has a "Save
 * Form Record" action, the record id is our entry id; otherwise an id of our
 * own ("jfb_…").
 */
class Adapter_JetFormBuilder extends Adapter_Base {

	/** Field types that carry no visitor input. */
	private const SKIP_TYPES = [ 'submit-field', 'form-break-field', 'form-break-start', 'heading-field', 'group-break-field', 'conditional-block', 'repeater-row', 'captcha-container' ];

	public function slug(): string {
		return 'jetformbuilder';
	}

	public function is_active(): bool {
		return function_exists( 'jet_form_builder' ) || defined( 'JET_FORM_BUILDER_VERSION' );
	}

	public function register_hooks(): void {
		add_action( 'jet-form-builder/form-handler/after-send', [ $this, 'on_after_send' ], 20, 2 );
	}

	/**
	 * @param object $handler
	 * @param bool   $is_success
	 */
	public function on_after_send( $handler, $is_success = true ): void {
		if ( ! $is_success || ! is_object( $handler ) || ! function_exists( 'jet_fb_context' ) ) {
			return;
		}
		$form_id = (int) ( $handler->form_id ?? 0 );
		try {
			$ctx     = jet_fb_context();
			$request = (array) $ctx->resolve_request();
			$meta    = [];
			foreach ( array_keys( $request ) as $name ) {
				$meta[ $name ] = [
					'label' => (string) $ctx->get_setting( 'label', $name ),
					'type'  => (string) $ctx->get_field_type( $name ),
				];
			}
			$record = function_exists( 'jet_fb_action_handler' ) ? (int) jet_fb_action_handler()->get_context( 'save_record', 'id' ) : 0;
		} catch ( \Throwable $e ) {
			return;
		}
		$payload = $this->payload( $form_id, $request, $meta, $record );
		if ( $payload ) {
			$this->dispatch( $payload );
		}
	}

	/**
	 * @param array<string,mixed>                           $request name => value.
	 * @param array<string,array{label:string,type:string}> $meta    name => label, type.
	 * @return array|null Null when nothing a visitor typed is left.
	 */
	public function payload( int $form_id, array $request, array $meta, int $record_id = 0 ): ?array {
		$fields = [];
		foreach ( $request as $name => $value ) {
			$name = (string) $name;
			$type = (string) ( $meta[ $name ]['type'] ?? '' );
			if ( '' === $name || '_' === $name[0] || in_array( $type, self::SKIP_TYPES, true ) ) {
				continue;
			}
			$label    = trim( wp_strip_all_tags( (string) ( $meta[ $name ]['label'] ?? '' ) ) );
			$label    = '' !== $label ? $label : ucwords( str_replace( [ '_', '-' ], ' ', $name ) );
			$value    = $this->flatten_value( $value );
			$fields[] = [
				'key'   => sanitize_key( $name ) ?: 'field_' . count( $fields ),
				'label' => $label,
				'type'  => $this->map_type( $type, $label, $value ),
				'value' => $value,
			];
		}
		if ( ! $fields ) {
			return null;
		}
		$entry = $record_id > 0 ? (string) $record_id : $this->make_entry_id( 'jfb_', (string) $form_id, $request );
		$title = $form_id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $form_id ) : '';
		return $this->build_payload( (string) $form_id, $entry, $fields, $title );
	}

	private function map_type( string $type, string $label, $value ): string {
		$map = [ 'text-field' => 'text', 'textarea-field' => 'textarea', 'select-field' => 'select', 'radio-field' => 'radio', 'checkbox-field' => 'checkbox', 'number-field' => 'number', 'date-field' => 'date', 'datetime-field' => 'datetime', 'time-field' => 'time', 'media-field' => 'file', 'hidden-field' => 'hidden', 'range-field' => 'number', 'wysiwyg-field' => 'textarea' ];
		if ( 'text-field' === $type || '' === $type ) {
			return $this->infer_type( $label, $value );
		}
		return $map[ $type ] ?? $this->infer_type( $label, $value );
	}
}
