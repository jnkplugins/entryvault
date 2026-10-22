/**
 * EntryVault Health screen — fetches the /health report and renders status cards.
 * Enqueued on the Health screen; reads REST root + nonce from EntryVaultHealthBoot
 * (was an inline <script>). Exposes window.EntryVaultHealth so extensions can add
 * operator actions and extra report cards.
 */
(function () {
    var boot      = window.EntryVaultHealthBoot || {};
    var REST_ROOT = boot.restRoot || '';
    var NONCE     = boot.nonce || '';

    var cardsEl   = document.getElementById( 'ev-health-cards' );
    var overallEl = document.getElementById( 'ev-health-overall' );
    var statusEl  = document.getElementById( 'ev-health-status' );

    function escapeHtml( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    var LEVELS = {
        ok:   { pill: 'is-ok',   text: 'All good' },
        warn: { pill: 'is-warn', text: 'Check this' },
        fail: { pill: 'is-bad',  text: 'Needs fixing' }
    };

    function levelOf( level ) {
        return LEVELS.hasOwnProperty( level ) ? level : 'fail';
    }

    function badge( level ) {
        var l = LEVELS[ levelOf( level ) ];
        return '<span class="jnkui-pill ' + l.pill + '">' + escapeHtml( l.text ) + '</span>';
    }

    // Also used by extensions (window.EntryVaultHealth.card) for their own cards.
    function card( title, level, rows ) {
        var html = '<div class="jnkui-card ev-health-card">';
        html += '<div class="jnkui-card-head"><h2>' + escapeHtml( title ) + '</h2>' + badge( level ) + '</div>';
        html += '<dl>';
        rows.forEach( function ( r ) {
            html += '<dt>' + escapeHtml( r[0] ) + '</dt>';
            html += '<dd>' + escapeHtml( r[1] ) + '</dd>';
        } );
        html += '</dl></div>';
        return html;
    }

    var OVERALL = {
        ok:   { title: 'Everything looks good', text: 'EntryVault is set up and listening to your forms.', icon: '<path d="M20 6 9 17l-5-5"/>' },
        warn: { title: 'Working, with one thing to check', text: 'See the card marked "Check this" below.', icon: '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>' },
        fail: { title: 'Something needs fixing', text: 'See the card marked "Needs fixing" below. Deactivating and activating EntryVault once often repairs it.', icon: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>' }
    };

    function render( report ) {
        if ( ! report ) {
            cardsEl.innerHTML  = '';
            overallEl.innerHTML = '';
            return;
        }
        var lvl = levelOf( report.overall );
        var o   = OVERALL[ lvl ];
        overallEl.className = 'ev-health-overall is-' + lvl;
        overallEl.innerHTML = '<div class="jnkui-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + o.icon + '</svg></div>'
            + '<div><h2>' + escapeHtml( o.title ) + '</h2><p>' + escapeHtml( o.text ) + '</p></div>';

        var html = '';

        // "Captures from the form plugins you already use" was impossible to
        // confirm: no screen said which ones had been detected, so the only test
        // available was to go and submit a live form.
        var sources = report.sources || {};
        var active  = sources.active || [];
        // Green "OK" next to "No form plugin detected yet" read as all-clear while
        // nothing was being captured. It is not a failure, but it is a warning.
        html += card( 'Form plugins', active.length ? 'ok' : 'warn', [
            [
                'Listening to',
                active.length
                    ? active.join( ', ' )
                    : 'No form plugin found yet. Activate one, or import a CSV file.'
            ],
        ] );

        var db = report.db || {};
        html += card( 'Database', db.ok ? 'ok' : 'fail', [
            [ 'Tables', db.tables_present ? 'All in place' : 'Some are missing' ],
            [ 'Missing', ( db.missing_tables && db.missing_tables.length ) ? db.missing_tables.join( ', ' ) : 'None' ],
        ] );

        var composer = report.composer || {};
        html += card( 'Plugin files', composer.ok ? 'ok' : 'fail', [
            [ 'Helper code', composer.autoload_ok ? 'Found' : 'Missing. Reinstall EntryVault from a fresh download.' ],
        ] );

        html += card( 'Versions', 'ok', [
            [ 'EntryVault', report.plugin_version || '' ],
            [ 'WordPress', report.wp_version || '' ],
            [ 'PHP', report.php_version || '' ],
        ] );

        cardsEl.textContent = '';
        var tmp = document.createElement( 'div' );
        tmp.innerHTML = html;
        while ( tmp.firstChild ) {
            cardsEl.appendChild( tmp.firstChild );
        }

        // Let extensions append their own cards from the same report.
        document.dispatchEvent( new CustomEvent( 'entryvault:health-report', {
            detail: { report: report, container: cardsEl }
        } ) );
    }

    function setStatus( msg, level ) {
        statusEl.textContent = msg || '';
        statusEl.style.color = level === 'fail' ? 'var(--jnk-bad, #b91c1c)' : ( level === 'ok' ? 'var(--jnk-ok, #047857)' : '' );
    }

    function api( path, opts ) {
        opts = opts || {};
        opts.headers = opts.headers || {};
        opts.headers[ 'X-WP-Nonce' ] = NONCE;
        opts.credentials = 'same-origin';
        return fetch( REST_ROOT + path, opts ).then( function ( r ) {
            return r.json().then( function ( body ) { return { status: r.status, body: body }; } );
        } );
    }

    function load() {
        setStatus( 'Checking…' );
        api( '/health' ).then( function ( res ) {
            if ( res.body && res.body.success ) {
                render( res.body.data );
                setStatus( '' );
            } else {
                setStatus( 'Could not load the check. Reload the page and try again.', 'fail' );
            }
        } ).catch( function () {
            setStatus( 'Could not load the check. Reload the page and try again.', 'fail' );
        } );
    }

    document.getElementById( 'ev-health-refresh' ).addEventListener( 'click', load );

    // Extension point: operator actions injected via the entryvault_health_actions
    // hook reuse these helpers, and `card` renders report cards in the house style.
    window.EntryVaultHealth = {
        restRoot: REST_ROOT,
        nonce: NONCE,
        reload: load,
        setStatus: setStatus,
        api: api,
        card: card
    };

    load();
})();
