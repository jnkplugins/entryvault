<?php
/**
 * EntryVault "Past entries" page (was the Pro Backfill screen; free since 1.20.0).
 *
 * For each active source-plugin adapter, list detected forms and offer a
 * "Backfill" button that hits POST /entryvault/v1/backfill/start. Progress
 * is polled from /backfill/state every couple of seconds until completed.
 *
 * Slug: entryvault-backfill, kept so old links still open (menu in class-plugin.php).
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'entryvault' ) );
}

$ev_rest_root  = esc_url_raw( rest_url( 'entryvault/v1' ) );
$ev_rest_nonce = wp_create_nonce( 'wp_rest' );

$ev_found = \EntryVault\Past_Entries::found( true );
// Forms to list: readable sources only. Sources that keep no entries, or whose
// database check failed, get a note instead of a table.
$ev_view_data = $ev_found;
?>
<?php
\EntryVault\Admin_UI::open(
    __( 'Past entries', 'entryvault' ),
    __( 'Bring in the entries your forms collected before EntryVault was installed. Run it as often as you like: entries already here are skipped, never copied twice.', 'entryvault' )
);
?>
<?php if ( empty( $ev_view_data ) ) : ?>
    <div class="jnkui-card"><div class="jnkui-card-body">
        <?php echo \EntryVault\Admin_UI::empty_state( __( 'Nothing to bring in', 'entryvault' ), __( 'No past entries found that EntryVault can read. It can bring in entries from Gravity Forms, WPForms (not WPForms Lite, which keeps none), Fluent Forms, Forminator, SureForms, Trinity Forms, Formidable, Ninja Forms, Everest Forms, MetForm, and Contact Form 7 messages saved by Flamingo or CFDB7.', 'entryvault' ), '', 'tray' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
    </div></div>
<?php endif; ?>

<?php foreach ( $ev_view_data as $a ) : ?>
    <div class="jnkui-card" data-source-card="<?php echo esc_attr( $a['key'] ); ?>">
        <div class="jnkui-card-head"><div><h2><?php echo esc_html( $a['label'] ); ?>
            <?php if ( $a['experimental'] ) : ?> <span class="jnkui-pill is-warn"><?php esc_html_e( 'Experimental', 'entryvault' ); ?></span><?php endif; ?></h2>
        <p><?php
            if ( empty( $a['keeps_entries'] ) ) {
                // Said in the card body below; how it would be read does not matter.
                echo '';
            } elseif ( 'api' === $a['access'] ) {
                /* translators: %s: form plugin name. */
                echo esc_html( sprintf( __( '%s offers a public way to read its entries, and EntryVault uses it.', 'entryvault' ), $a['label'] ) );
            } elseif ( 'own' === $a['access'] ) {
                esc_html_e( 'Made by the same team as EntryVault, which reads its entries directly and is kept in step with it.', 'entryvault' );
            } else {
                /* translators: %s: form plugin name. */
                echo esc_html( sprintf( __( '%s offers no public way to read its entries, so EntryVault reads its database directly. This can stop working when the form plugin changes its database.', 'entryvault' ), $a['label'] ) );
            }
        ?></p></div>
        <?php if ( $a['experimental'] ) : ?>
            <button type="button" class="button button-small ev-pe-report" data-source="<?php echo esc_attr( $a['key'] ); ?>"><?php esc_html_e( 'Report a problem', 'entryvault' ); ?></button>
        <?php endif; ?>
        </div>
        <?php if ( $a['experimental'] ) : ?>
            <details class="jnkui-card-body ev-pe-report-what">
                <summary><?php esc_html_e( 'What "Report a problem" sends', 'entryvault' ); ?></summary>
                <p class="jnkui-muted"><?php esc_html_e( 'Sent by your site\'s email to the EntryVault team, only when you click: the form plugin and its version, the EntryVault, WordPress and PHP versions, and the database check. No entries, names or email addresses, and not your site address.', 'entryvault' ); ?></p>
                <pre style="white-space:pre-wrap"><?php echo esc_html( \EntryVault\Past_Entries::report_text( $a['key'] ) ); ?></pre>
            </details>
            <div class="jnkui-card-body ev-pe-report-result" role="status" aria-live="polite" style="display:none"></div>
        <?php endif; ?>
        <?php if ( empty( $a['keeps_entries'] ) ) : ?>
            <div class="jnkui-card-body"><?php
                /* translators: %s: form plugin name. */
                echo esc_html( sprintf( __( '%s does not keep entries on this site (its free version only emails them), so there are no past entries to bring in. New submissions are captured from now on.', 'entryvault' ), $a['label'] ) );
            ?></div>
        </div>
        <?php continue; endif; ?>
        <?php if ( empty( $a['schema']['ok'] ) ) : ?>
            <div class="jnkui-card-body"><p class="jnkui-pill is-bad" style="display:inline-block"><?php esc_html_e( 'Cannot read', 'entryvault' ); ?></p>
            <p><?php
                /* translators: 1: form plugin name, 2: list of missing tables or columns. */
                echo esc_html( sprintf( __( 'EntryVault checked the %1$s database first and did not find what it expects (missing: %2$s). Nothing was read. This usually means %1$s changed its database in an update. Please use "Report a problem" so EntryVault can be fixed.', 'entryvault' ), $a['label'], implode( ', ', (array) $a['schema']['missing'] ) ) );
            ?></p></div>
        </div>
        <?php continue; endif; ?>
        <?php if ( empty( $a['forms'] ) ) : ?>
            <div class="jnkui-card-body jnkui-muted"><?php esc_html_e( 'No forms found in this plugin.', 'entryvault' ); ?></div>
        <?php else : ?>
            <div class="jnkui-table-wrap">
            <table class="widefat" data-source="<?php echo esc_attr( $a['key'] ); ?>">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Form', 'entryvault' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'entryvault' ); ?></th>
                        <th style="width:30%"><?php esc_html_e( 'Progress', 'entryvault' ); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'entryvault' ); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $a['forms'] as $f ) : ?>
                        <tr data-form-id="<?php echo esc_attr( (int) $f['id'] ); ?>"
                            data-source="<?php echo esc_attr( $a['key'] ); ?>">
                            <td>
                                <strong><?php echo esc_html( $f['title'] ?: ( '#' . (int) $f['id'] ) ); ?></strong>
                                <span class="jnkui-sub"><?php
                                    echo esc_html( sprintf( /* translators: %d: form ID. */ __( 'Form ID %d', 'entryvault' ), (int) $f['id'] ) );
                                    if ( null !== $f['count'] ) {
                                        /* translators: %s: number of entries. */
                                        echo ' &middot; ' . esc_html( sprintf( _n( '%s entry', '%s entries', (int) $f['count'], 'entryvault' ), number_format_i18n( (int) $f['count'] ) ) );
                                    }
                                ?></span>
                            </td>
                            <td class="ev-bf-status"><span class="jnkui-pill"><?php esc_html_e( 'Not run yet', 'entryvault' ); ?></span></td>
                            <td class="ev-bf-progress"><span class="jnkui-muted">&ndash;</span></td>
                            <td style="text-align:end;white-space:nowrap">
                                <button type="button" class="button ev-bf-start">
                                    <?php esc_html_e( 'Bring in entries', 'entryvault' ); ?>
                                </button>
                                <button type="button" class="button ev-bf-cancel" style="display:none;">
                                    <?php esc_html_e( 'Stop', 'entryvault' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php \EntryVault\Admin_UI::close(); ?>

<script>
(function () {
    var REST_ROOT  = <?php echo wp_json_encode( $ev_rest_root ); ?>;
    var REST_NONCE = <?php echo wp_json_encode( $ev_rest_nonce ); ?>;

    function api(method, path, body) {
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': REST_NONCE }
        };
        if ( body ) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        // REST_ROOT may already carry a query string (plain permalinks:
        // index.php?rest_route=/entryvault/v1). Splice the path's own query in
        // with '&' in that case — naive concatenation yields two '?' and a 404.
        var url  = REST_ROOT + path;
        var qpos = path.indexOf('?');
        if ( qpos !== -1 && REST_ROOT.indexOf('?') !== -1 ) {
            url = REST_ROOT + path.slice(0, qpos) + '&' + path.slice(qpos + 1);
        }
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        });
    }

    var LABELS = <?php echo wp_json_encode( [
        'none'      => __( 'Not run yet', 'entryvault' ),
        'running'   => __( 'Running', 'entryvault' ),
        'completed' => __( 'Done', 'entryvault' ),
        'cancelled' => __( 'Stopped', 'entryvault' ),
        'paused'    => __( 'Paused', 'entryvault' ),
        'failed'    => __( 'Failed', 'entryvault' ),
        'schema'    => __( 'Stopped: the form plugin database does not look as expected. Nothing was read.', 'entryvault' ),
        /* translators: %s: number of entries. */
        'processed' => __( '%s brought in', 'entryvault' ),
        /* translators: %s: number of entries. */
        'present'   => __( '%s already here', 'entryvault' ),
        /* translators: %s: number of entries. */
        'skipped'   => __( '%s left out (spam or unreadable)', 'entryvault' ),
    ] ); ?>;
    var PILLS = { running: 'is-info', completed: 'is-ok', cancelled: '', paused: 'is-warn', failed: 'is-bad' };

    function pill(text, cls) {
        var s = document.createElement('span');
        s.className = 'jnkui-pill' + (cls ? ' ' + cls : '');
        s.textContent = text;
        return s;
    }

    function applyState(row, state) {
        var statusEl   = row.querySelector('.ev-bf-status');
        var progressEl = row.querySelector('.ev-bf-progress');
        var startBtn   = row.querySelector('.ev-bf-start');
        var cancelBtn  = row.querySelector('.ev-bf-cancel');
        statusEl.textContent = '';
        progressEl.textContent = '';
        if ( ! state || ! state.status ) {
            statusEl.appendChild(pill(LABELS.none, ''));
            progressEl.innerHTML = '<span class="jnkui-muted">&ndash;</span>';
            return false;
        }
        statusEl.appendChild(pill(LABELS[state.status] || state.status, PILLS[state.status] || ''));
        var done  = parseInt(state.done || 0, 10);
        // "total" is only set at the end and always equals "done", so a
        // progress bar read "2 / 2" even when a rerun added nothing. Say what
        // happened instead: how many became new entries, how many were left out.
        progressEl.textContent = LABELS.processed.replace('%s', done);
        // Entries already on the board and entries left out are told apart.
        var present = parseInt(state.present || 0, 10);
        var skipped = Math.max(0, parseInt(state.skipped || 0, 10) - present);
        [ [ present, LABELS.present ], [ skipped, LABELS.skipped ] ].forEach(function (p) {
            if ( p[0] > 0 ) {
                var sk = document.createElement('span');
                sk.className = 'jnkui-sub';
                sk.textContent = p[1].replace('%s', p[0]);
                progressEl.appendChild(sk);
            }
        });
        if ( state.status === 'failed' && (state.errors || []).some(function (e) { return String(e).indexOf('schema_mismatch') === 0; }) ) {
            var sm = document.createElement('span');
            sm.className = 'jnkui-sub';
            sm.textContent = LABELS.schema;
            progressEl.appendChild(sm);
        }
        var running = state.status === 'running';
        startBtn.style.display  = running ? 'none' : '';
        cancelBtn.style.display = running ? '' : 'none';
        return running;
    }

    function poll(row) {
        var formId = row.getAttribute('data-form-id');
        var source = row.getAttribute('data-source');
        api('GET', '/backfill/state?form_id=' + encodeURIComponent(formId) + '&source_plugin=' + encodeURIComponent(source))
            .then(function (res) {
                var state = res && res.body && res.body.data;
                var running = applyState(row, state);
                if ( running ) {
                    setTimeout(function () { poll(row); }, 2000);
                }
            });
    }

    document.querySelectorAll('.ev-bf-start').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row    = btn.closest('tr');
            var formId = parseInt(row.getAttribute('data-form-id'), 10);
            var source = row.getAttribute('data-source');
            btn.disabled = true;
            api('POST', '/backfill/start', { form_id: formId, source_plugin: source })
                .then(function (res) {
                    btn.disabled = false;
                    if ( res.body && res.body.success ) {
                        applyState(row, res.body.data);
                        poll(row);
                    }
                });
        });
    });

    document.querySelectorAll('.ev-bf-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row    = btn.closest('tr');
            var formId = parseInt(row.getAttribute('data-form-id'), 10);
            var source = row.getAttribute('data-source');
            api('POST', '/backfill/cancel', { form_id: formId, source_plugin: source })
                .then(function (res) {
                    if ( res.body && res.body.success ) {
                        applyState(row, res.body.data);
                    }
                });
        });
    });

    var REPORT = <?php echo wp_json_encode( [
        'sent'    => __( 'Thanks, the report was sent.', 'entryvault' ),
        'limited' => __( 'A report for this form plugin was already sent in the last hour.', 'entryvault' ),
        'failed'  => __( 'The report could not be sent. Your site may not be able to send email; the Help and feedback page has other ways to reach us.', 'entryvault' ),
    ] ); ?>;
    document.querySelectorAll('.ev-pe-report').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('[data-source-card]');
            var out  = card.querySelector('.ev-pe-report-result');
            btn.disabled = true;
            api('POST', '/past-entries/report', { source_plugin: btn.getAttribute('data-source') }).then(function (res) {
                var r = res.body && res.body.data && res.body.data.result;
                out.textContent = REPORT[r] || REPORT.failed;
                out.style.display = '';
            }).catch(function () { out.textContent = REPORT.failed; out.style.display = ''; btn.disabled = false; });
        });
    });

    // Initial state probe per row.
    document.querySelectorAll('tr[data-form-id]').forEach(function (row) {
        poll(row);
    });
})();
</script>
