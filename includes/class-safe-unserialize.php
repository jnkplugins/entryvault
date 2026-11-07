<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Reading another plugin's stored (serialized) data.
 *
 * Works as core's maybe_unserialize() for the arrays, strings and numbers form
 * plugins store, but never builds an object: a stored object could run code
 * when it is created or thrown away, and none of the data EntryVault reads
 * needs one. An object in the data reads as an empty string.
 */
final class Safe_Unserialize {

	/**
	 * @param mixed $value A stored value, serialized or not.
	 * @return mixed The value, unserialized when it was serialized.
	 */
	public static function maybe( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- another plugin's storage format; objects are refused.
		return self::without_objects( @unserialize( trim( $value ), [ 'allowed_classes' => false ] ) );
	}

	/**
	 * Refused objects come back as __PHP_Incomplete_Class; leave none behind.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function without_objects( $value ) {
		if ( is_object( $value ) ) {
			return '';
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::without_objects( $v );
			}
		}
		return $value;
	}
}
