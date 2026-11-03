<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

// A WP-CLI command group (wp entryvault pipelines, ...), so `wp help entryvault`
// shows a line for each group. Loaded only by WP-CLI itself (CLI::register()
// checks the parent class exists first). No docblock: WP-CLI would print it as help.
class CLI_Group extends \WP_CLI\Dispatcher\CommandNamespace {
}
