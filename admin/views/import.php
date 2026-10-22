<?php
/**
 * EntryVault CSV Import admin page.
 *
 * Vanilla-JS UI: choose a CSV file (read locally via FileReader) or paste,
 * preview the columns, map them to title / email / source / fields / ignore,
 * pick a target pipeline + stage, and run the import (with optional dry run).
 *
 * All work goes through REST endpoints under /entryvault/v1/import/*.
 * Menu wiring lives in class-plugin.php (the entryvault-import submenu).
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'entryvault' ) );
}

$ev_rest_root  = esc_url_raw( rest_url( 'entryvault/v1' ) );
$ev_rest_nonce = wp_create_nonce( 'wp_rest' );

global $wpdb;
$entryvault_pipelines = (array) $wpdb->get_results(
    "SELECT id, name FROM {$wpdb->prefix}entryvault_pipelines ORDER BY is_default DESC, name ASC",
    ARRAY_A
);
$entryvault_stages = (array) $wpdb->get_results(
    "SELECT id, name, pipeline_id FROM {$wpdb->prefix}entryvault_stages ORDER BY pipeline_id ASC, position ASC, name ASC",
    ARRAY_A
);
?>
<?php
\EntryVault\Admin_UI::open(
    __( 'Import', 'entryvault' ),
    __( 'Bring in entries from a spreadsheet. Save it as a CSV file first, with column names in the first row.', 'entryvault' )
);
?>
<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><span class="ev-step">1</span><?php esc_html_e( 'Choose your file', 'entryvault' ); ?></h2>
    <p><?php esc_html_e( 'The file is read in your browser first, so you can check it before anything is saved.', 'entryvault' ); ?></p></div></div>
    <div class="jnkui-card-body">
        <label class="jnkui-field" for="ev-import-file"><?php esc_html_e( 'CSV file', 'entryvault' ); ?>
            <input type="file" id="ev-import-file" accept=".csv,text/csv">
        </label>
        <div class="ev-import-or"><?php esc_html_e( 'or', 'entryvault' ); ?></div>
        <label class="jnkui-field" for="ev-import-textarea"><?php esc_html_e( 'Paste the CSV text', 'entryvault' ); ?>
            <textarea id="ev-import-textarea" rows="6" cols="80"></textarea>
        </label>
    </div>
    <p class="jnkui-card-foot">
        <button type="button" class="button button-primary" id="ev-import-preview-btn">
            <?php esc_html_e( 'Preview', 'entryvault' ); ?>
        </button>
        <span id="ev-import-status" role="status" aria-live="polite"></span>
    </p>
</div>

<div id="ev-import-preview-wrap" style="display:none;">
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><span class="ev-step">2</span><?php esc_html_e( 'Match the columns', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'Tell EntryVault what each column holds. One column must be the email address.', 'entryvault' ); ?></p></div></div>
        <div class="jnkui-table-wrap">
            <table class="widefat" id="ev-import-mapping-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Column', 'entryvault' ); ?></th>
                        <th><?php esc_html_e( 'First value', 'entryvault' ); ?></th>
                        <th><?php esc_html_e( 'Use as', 'entryvault' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><span class="ev-step">3</span><?php esc_html_e( 'Where they go', 'entryvault' ); ?></h2>
        <p><?php esc_html_e( 'Pick the pipeline and stage for the new entries. Try a test run first: it checks the file and saves nothing.', 'entryvault' ); ?></p></div></div>
        <div class="jnkui-card-body">
            <div class="ev-import-dest">
                <label class="jnkui-field" for="ev-import-pipeline"><?php esc_html_e( 'Pipeline', 'entryvault' ); ?>
                    <select id="ev-import-pipeline">
                        <option value="0"><?php esc_html_e( 'Use the default pipeline', 'entryvault' ); ?></option>
                        <?php foreach ( $entryvault_pipelines as $p ) : ?>
                            <option value="<?php echo esc_attr( (int) $p['id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="jnkui-field" for="ev-import-stage"><?php esc_html_e( 'Stage', 'entryvault' ); ?>
                    <select id="ev-import-stage">
                        <option value="0"><?php esc_html_e( 'First stage of the pipeline', 'entryvault' ); ?></option>
                    </select>
                </label>
            </div>
            <p style="margin:16px 0 0">
                <label class="ev-check">
                    <input type="checkbox" id="ev-import-dryrun" checked>
                    <?php esc_html_e( 'Test run only: check the file but do not save anything', 'entryvault' ); ?>
                </label>
            </p>
            <?php
            /**
             * Extra import options (checkboxes etc.) rendered under "Dry run". An
             * element with id="ev-import-run-rules" is sent as run_rules.
             */
            do_action( 'entryvault_import_options' );
            ?>
        </div>
        <p class="jnkui-card-foot">
            <button type="button" class="button button-primary" id="ev-import-run-btn">
                <?php esc_html_e( 'Run import', 'entryvault' ); ?>
            </button>
        </p>
    </div>
</div>

<div id="ev-import-results" class="jnkui-card jnkui-card-body"></div>
<?php \EntryVault\Admin_UI::close(); ?>

<?php
// The CSV preview/map/run UI lives in the enqueued admin/assets/js/import.js
// (enqueued on the Import screen; data + i18n via EntryVaultImportBoot).
