<?php
/**
 * Dependencies + version for the hand-authored submissions-table editor script.
 *
 * WordPress's register_block_script_handle() reads this file (matched by the
 * `.asset.php` sibling of the `file:` editorScript) to enqueue the right wp-*
 * packages and set the cache-busting version. Because index.js is authored by
 * hand rather than compiled by @wordpress/scripts, we maintain this manually.
 * Keep `version` in step with ENTRYVAULT_VERSION on each release.
 */
return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
	),
	'version'      => '1.12.0',
);
