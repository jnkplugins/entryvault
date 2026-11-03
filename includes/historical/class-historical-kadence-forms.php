<?php
namespace EntryVault\Historical;

defined( 'ABSPATH' ) || exit;

/**
 * Kadence Blocks forms on Past entries.
 *
 * Free Kadence Blocks keeps no submissions: both the Form block and the
 * Advanced Form only email them (its Entries screen is a Kadence Blocks Pro
 * feature, "Entries (Pro Only)"). So the screen says so instead of offering a
 * reader. New submissions are captured live by the Kadence adapter.
 * A reader for Kadence Blocks Pro's entries would need a paid copy to build
 * and test against.
 */
class Historical_Kadence_Forms extends Provider_Base {

	public function source(): string {
		return 'kadence-forms';
	}

	public function label(): string {
		return 'Kadence Blocks';
	}

	protected function adapter_class(): string {
		return \EntryVault\Adapters\Adapter_Kadence_Forms::class;
	}

	public function source_version(): string {
		return defined( 'KADENCE_BLOCKS_VERSION' ) ? (string) KADENCE_BLOCKS_VERSION : '';
	}

	public function installed(): bool {
		return defined( 'KADENCE_BLOCKS_VERSION' );
	}

	public function keeps_entries(): bool {
		return false;
	}

	public function available(): bool {
		return false;
	}

	public function forms(): array {
		return [];
	}

	public function entries( int $form_id, int $offset, int $limit ): array {
		return [];
	}
}
