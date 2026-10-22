<?php
/**
 * Server-side render for the entryvault/submissions-table block.
 *
 * @var array $attributes Block attributes (pipeline, stage, access_token, columns).
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

$attributes = isset( $attributes ) && is_array( $attributes ) ? $attributes : [];

// Block stores `columns` as an array; the shared renderer accepts either an
// array or a comma-separated string.
if ( isset( $attributes['columns'] ) && is_array( $attributes['columns'] ) ) {
    $attributes['columns'] = implode( ',', $attributes['columns'] );
}

if ( class_exists( '\\EntryVault\\Public_Front' ) ) {
    \EntryVault\Public_Front::force_enqueue();
}

echo \EntryVault\Frontend::render_table( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Frontend::render_table escapes per-cell.
