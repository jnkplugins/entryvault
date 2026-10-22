<?php
defined( 'ABSPATH' ) || exit;

\EntryVault\Submissions_Admin::register();
// Renders the whole screen, page chrome and the free-tier hint included.
\EntryVault\Submissions_Admin::render();
