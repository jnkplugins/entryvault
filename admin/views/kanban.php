<?php
defined( 'ABSPATH' ) || exit;

\EntryVault\Admin_UI::open(
    __( 'Board', 'entryvault' ),
    __( 'Every form submission as a card. Drag a card to move it to the next stage, or open it to reply, add notes and tags.', 'entryvault' ),
    '',
    [ 'wide' => true ]
);

// Entries a form plugin marked as spam are left out by default. Say how many, so a
// real message caught by mistake can still be found.
$ev_skipped = \EntryVault\Adapters\Adapter_Base::skip_spam_enabled()
    ? \EntryVault\Adapters\Adapter_Base::spam_skips( time() - 30 * DAY_IN_SECONDS )
    : [];
// Counted apart from the list, which keeps only the latest 100.
$ev_skipped_n = $ev_skipped ? \EntryVault\Adapters\Adapter_Base::spam_skip_count( time() - 30 * DAY_IN_SECONDS ) : 0;
if ( $ev_skipped ) :
    $ev_from_tf = (bool) array_filter( $ev_skipped, static fn( $s ) => 'trinity-forms' === ( $s['source'] ?? '' ) );
    ?>
    <div class="jnkui-callout ev-spam-skipped"><p>
        <?php
        echo esc_html( sprintf(
            /* translators: 1: number of entries, 2: date of the latest one. */
            _n(
                '%1$d entry in the last 30 days was left out because its form plugin marked it as spam (latest %2$s). Spam filters are sometimes wrong, so it is worth a look.',
                '%1$d entries in the last 30 days were left out because their form plugin marked them as spam (latest %2$s). Spam filters are sometimes wrong, so they are worth a look.',
                $ev_skipped_n,
                'entryvault'
            ),
            $ev_skipped_n,
            wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ev_skipped[0]['at'] )
        ) );
        ?>
        <?php if ( $ev_from_tf ) : ?>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=trinity_forms_form&page=trinity-forms-entries&view=spam' ) ); ?>"><?php esc_html_e( 'Review them in Trinity Forms', 'entryvault' ); ?></a>
            <?php esc_html_e( '(an entry you restore there comes onto this board).', 'entryvault' ); ?>
        <?php endif; ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=entryvault-settings' ) ); ?>"><?php esc_html_e( 'Keep spam entries in EntryVault', 'entryvault' ); ?></a>
    </p></div>
<?php endif; ?>
<?php
// Day one: what the form plugins already hold, with one button to bring it in
// (owner decisions D1/D2, 29 Oct 2026). Before this the board said "Nothing
// here yet" on a site with 30 past Fluent Forms entries.
$ev_box = \EntryVault\Past_Entries::board_box();
if ( ! $ev_box['offer'] && $ev_box['none'] ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- count on the plugin's own table.
    if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'entryvault_submissions' ) ) > 0 ) {
        $ev_box['none'] = [];
    }
}
if ( $ev_box['offer'] || $ev_box['none'] ) :
    $ev_jobs  = [];
    $ev_total = 0;
    ?>
    <div class="jnkui-callout ev-past-box" id="ev-past-box">
        <div>
        <?php if ( $ev_box['offer'] ) : ?>
            <p><strong><?php esc_html_e( 'Past entries found', 'entryvault' ); ?></strong></p>
            <ul class="ev-past-list" style="margin:4px 0 8px;list-style:none;padding:0">
                <?php foreach ( $ev_box['offer'] as $ev_src ) :
                    $ev_forms = [];
                    foreach ( $ev_src['forms'] as $ev_f ) {
                        // Only what is not on the board yet ('new'), so a CSV
                        // import of the same entries leaves nothing to offer.
                        if ( 'completed' === $ev_f['status'] || 0 === $ev_f['new'] ) {
                            continue;
                        }
                        $ev_jobs[] = [ 'source_plugin' => $ev_src['key'], 'form_id' => (int) $ev_f['id'] ];
                        $ev_total += (int) $ev_f['new'];
                        $ev_forms[] = null === $ev_f['new'] ? $ev_f['title'] : $ev_f['title'] . ' ' . number_format_i18n( (int) $ev_f['new'] );
                    }
                    ?>
                    <li>
                        <strong><?php
                        echo esc_html( (int) $ev_src['pending'] > 0 && array_sum( array_map( static fn( $f ) => null === $f['new'] ? 1 : 0, $ev_src['forms'] ) ) === 0
                            /* translators: 1: form plugin name, 2: number of entries. */
                            ? sprintf( _n( '%1$s: %2$s past entry', '%1$s: %2$s past entries', (int) $ev_src['pending'], 'entryvault' ), $ev_src['label'], number_format_i18n( (int) $ev_src['pending'] ) )
                            /* translators: %s: form plugin name. */
                            : sprintf( __( '%s: past entries found', 'entryvault' ), $ev_src['label'] ) );
                        ?></strong>
                        <?php if ( $ev_forms ) : ?>
                            <span class="jnkui-muted">(<?php echo esc_html( implode( ', ', $ev_forms ) ); ?>)</span>
                        <?php endif; ?>
                        <?php if ( $ev_src['experimental'] ) : ?>
                            <span class="jnkui-pill is-warn"><?php esc_html_e( 'Experimental', 'entryvault' ); ?></span>
                            <span class="jnkui-sub"><?php
                                /* translators: %s: form plugin name. */
                                echo esc_html( sprintf( __( '%s offers no public way to read its entries, so EntryVault reads its database directly. This can stop working when the form plugin changes its database.', 'entryvault' ), $ev_src['label'] ) );
                            ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><?php esc_html_e( 'They stay where they are. EntryVault copies them onto this board with their original dates. No emails or automations run for them.', 'entryvault' ); ?></p>
            <p class="ev-board-start-actions">
                <button type="button" class="button button-primary" id="ev-past-go"><?php esc_html_e( 'Bring them in', 'entryvault' ); ?></button>
                <button type="button" class="button" id="ev-past-later"><?php esc_html_e( 'Not now', 'entryvault' ); ?></button>
                <span id="ev-past-status" role="status" aria-live="polite"></span>
            </p>
        <?php endif; ?>
        <?php foreach ( $ev_box['none'] as $ev_src ) : ?>
            <p><?php
                /* translators: %s: form plugin name. */
                echo esc_html( ! empty( $ev_src['note'] ) ? (string) $ev_src['note'] : sprintf( __( '%s does not keep entries on this site (its free version only emails them), so there are no past entries to bring in. New submissions are captured from now on.', 'entryvault' ), $ev_src['label'] ) );
            ?></p>
        <?php endforeach; ?>
        </div>
    </div>
    <?php if ( $ev_box['offer'] ) : ?>
    <script>
    (function () {
        var root  = <?php echo wp_json_encode( esc_url_raw( rest_url( 'entryvault/v1' ) ) ); ?>;
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        var jobs  = <?php echo wp_json_encode( $ev_jobs ); ?>;
        var total = <?php echo (int) $ev_total; ?>;
        var L     = <?php echo wp_json_encode( [
            /* translators: 1: entries brought in so far, 2: entries found. */
            'progress' => __( 'Bringing them in: %1$s of %2$s', 'entryvault' ),
            /* translators: %s: number of entries. */
            'done'     => __( 'Done: %s entries brought in. Loading the board…', 'entryvault' ),
            'failed'   => __( 'Something went wrong. Open Past entries for the details.', 'entryvault' ),
            'later'    => __( 'You can bring them in later from Past entries.', 'entryvault' ),
            'pastUrl'  => admin_url( 'admin.php?page=entryvault-backfill' ),
        ] ); ?>;
        var box = document.getElementById('ev-past-box');
        var st  = document.getElementById('ev-past-status');
        function url(path) {
            var q = path.indexOf('?');
            if (q !== -1 && root.indexOf('?') !== -1) { return root + path.slice(0, q) + '&' + path.slice(q + 1); }
            return root + path;
        }
        // Messages come back in the user's language, not the site's (as wp.apiFetch does).
        function evLocale( u ) { return u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + '_locale=user'; }

        function call(method, path, body) {
            var o = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' } };
            if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
            return fetch(evLocale(url(path)), o).then(function (r) { return r.json(); });
        }
        var brought = 0, failed = false;
        function say(n) { st.textContent = L.progress.replace('%1$s', n).replace('%2$s', total || '?'); }
        function runJob(i) {
            if (i >= jobs.length) {
                if (failed) { st.innerHTML = ''; var a = document.createElement('a'); a.href = L.pastUrl; a.textContent = L.failed; st.appendChild(a); return; }
                st.textContent = L.done.replace('%s', brought);
                setTimeout(function () { window.location.reload(); }, 900);
                return;
            }
            var j = jobs[i], base = 0;
            call('POST', '/backfill/start', j).then(function () {
                (function poll() {
                    call('GET', '/backfill/state?form_id=' + j.form_id + '&source_plugin=' + encodeURIComponent(j.source_plugin)).then(function (res) {
                        var s = (res && res.data) || {};
                        say(brought + (parseInt(s.done, 10) || 0));
                        if (s.status === 'running') { setTimeout(poll, 1500); return; }
                        if (s.status !== 'completed') { failed = true; }
                        brought += parseInt(s.done, 10) || 0;
                        runJob(i + 1);
                    }).catch(function () { failed = true; runJob(i + 1); });
                })();
            }).catch(function () { failed = true; runJob(i + 1); });
        }
        document.getElementById('ev-past-go').addEventListener('click', function () {
            this.disabled = true;
            document.getElementById('ev-past-later').style.display = 'none';
            say(0);
            runJob(0);
        });
        document.getElementById('ev-past-later').addEventListener('click', function () {
            call('POST', '/past-entries/dismiss', {});
            box.querySelector('.ev-board-start-actions').textContent = L.later;
            setTimeout(function () { window.location.reload(); }, 1500);
        });
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>
<div id="entryvault-kanban-root" class="ev-board-root">
    <div class="ev-board-loading">
        <span class="jnkui-spin" aria-hidden="true"></span>
        <?php esc_html_e( 'Loading the board…', 'entryvault' ); ?>
        <p class="jnkui-muted">
            <?php esc_html_e( 'If this message stays, the board files are missing. Run', 'entryvault' ); ?>
            <code>npm install &amp;&amp; npm run build</code>
            <?php esc_html_e( 'in the plugin folder.', 'entryvault' ); ?>
        </p>
    </div>
</div>
<?php
\EntryVault\Admin_UI::close();
