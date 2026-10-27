/**
 * EntryVault Settings screen — tab switching, the default-pipeline dropdown, and
 * REST-backed save handlers. Enqueued on the Settings admin page; reads its
 * bootstrap data (REST root, nonce, defaults, i18n) from the localized
 * EntryVaultSettingsBoot object rather than an inline <script>.
 */
(function () {
    var boot    = window.EntryVaultSettingsBoot || {};
    var REST    = boot.restUrl || '';
    var NONCE   = boot.nonce || '';
    var INITIAL = { default_pipeline: parseInt( boot.defaultPipeline, 10 ) || 0 };
    var I18N    = boot.i18n || {};

    function api( path, opts ) {
        opts = opts || {};
        opts.headers = opts.headers || {};
        opts.headers['X-WP-Nonce']   = NONCE;
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['Accept']       = 'application/json';
        opts.credentials = 'same-origin';
        return fetch( REST + path, opts ).then( function ( r ) {
            return r.json().then( function ( j ) { return { status: r.status, body: j }; } );
        } );
    }

    function notice( kind, msg ) {
        var el = document.getElementById( 'ev-settings-notice' );
        if ( ! el ) return;
        el.className = 'notice notice-' + ( kind === 'error' ? 'error' : 'success' ) + ' is-dismissible';
        el.querySelector( 'p' ).textContent = msg;
        el.style.display = '';
        window.setTimeout( function () { el.style.display = 'none'; }, 4000 );
    }

    function envelopeError( res ) {
        if ( res && res.body && res.body.errors && res.body.errors.length ) {
            return res.body.errors.map( function ( e ) { return e.message || e.code; } ).join( '; ' );
        }
        return 'HTTP ' + ( res && res.status );
    }

    function saveSettings( payload, label ) {
        return api( '/settings', {
            method: 'POST',
            body: JSON.stringify( payload )
        } ).then( function ( res ) {
            if ( res.status >= 200 && res.status < 300 && res.body && res.body.success ) {
                notice( 'success', label + ' saved.' );
                return true;
            }
            notice( 'error', label + ' save failed: ' + envelopeError( res ) );
            return false;
        } ).catch( function ( err ) {
            notice( 'error', label + ' save failed: ' + ( err && err.message ? err.message : err ) );
            return false;
        } );
    }

    function inlineMsg( id, kind, text ) {
        var el = document.getElementById( id );
        if ( ! el ) return;
        el.style.color = ( kind === 'error' ) ? '#a00' : '#3c763d';
        el.textContent = text;
        window.setTimeout( function () {
            if ( el.textContent === text ) { el.textContent = ''; }
        }, 4000 );
    }

    /* Expose the shared REST + UI helpers so extra settings panels injected
       via the entryvault_settings_panels filter can reuse them instead of
       re-implementing fetch/nonce plumbing. Defined synchronously (before
       DOMContentLoaded) so deferred panel scripts find it ready. */
    window.EntryVaultSettings = {
        api: api,
        saveSettings: saveSettings,
        notice: notice,
        envelopeError: envelopeError
    };

    function valueOf( id ) {
        var el = document.getElementById( id );
        return el ? String( el.value || '' ).trim() : '';
    }

    function openTab( name ) {
        var tabs   = document.querySelectorAll( '#ev-settings-tabs .nav-tab' );
        var target = document.getElementById( 'ev-tab-' + name );
        if ( ! target ) return false;
        tabs.forEach( function ( t ) {
            t.classList.toggle( 'nav-tab-active', t.getAttribute( 'data-ev-tab' ) === name );
        } );
        document.querySelectorAll( '.ev-tab-panel' ).forEach( function ( p ) { p.style.display = 'none'; } );
        target.style.display = '';
        return true;
    }

    function openTabFromHash() {
        var m = /^#ev-tab-([a-z0-9_-]+)$/i.exec( window.location.hash || '' );
        if ( m ) openTab( m[1] );
    }

    function init() {
        /* Tabs */
        var tabs = document.querySelectorAll( '#ev-settings-tabs .nav-tab' );
        tabs.forEach( function ( tab ) {
            tab.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var name = tab.getAttribute( 'data-ev-tab' );
                openTab( name );
                // Keep the address shareable and survive a reload or save redirect.
                if ( window.history && window.history.replaceState ) {
                    window.history.replaceState( null, '', '#ev-tab-' + name );
                }
            } );
        } );
        // Links such as …page=entryvault-settings#ev-tab-owners (and the
        // redirect after saving a tab) used to land on General every time.
        openTabFromHash();
        window.addEventListener( 'hashchange', openTabFromHash );

        /* Pipelines: populate the default-pipeline dropdown */
        var pipelineSel = document.getElementById( 'ev-default-pipeline' );
        if ( pipelineSel ) {
            api( '/pipelines' ).then( function ( res ) {
                if ( res.status >= 200 && res.status < 300 && res.body && res.body.success ) {
                    var list = res.body.data || [];
                    list.forEach( function ( p ) {
                        var opt = document.createElement( 'option' );
                        opt.value = String( p.id );
                        opt.textContent = p.name || ( 'Pipeline #' + p.id );
                        if ( parseInt( p.id, 10 ) === INITIAL.default_pipeline ) opt.selected = true;
                        pipelineSel.appendChild( opt );
                    } );
                }
            } );
        }

        /* Save handlers */
        var generalForm = document.getElementById( 'ev-form-general' );
        if ( generalForm ) {
            generalForm.addEventListener( 'submit', function ( e ) {
                e.preventDefault();
                // Every field on the form has to be listed here. 1.3.2 added the
                // "Send replies as" inputs to the markup but not to this payload,
                // so the screen said "General saved." while both were dropped.
                var payload = {
                    default_pipeline: parseInt( document.getElementById( 'ev-default-pipeline' ).value, 10 ) || 0,
                    from_name: valueOf( 'ev-from-name' ),
                    from_email: valueOf( 'ev-from-email' ),
                    delete_data_on_uninstall: document.getElementById( 'ev-delete-data-on-uninstall' ).checked
                };
                if ( document.getElementById( 'ev-skip-spam' ) ) {
                    payload.skip_spam = document.getElementById( 'ev-skip-spam' ).checked;
                }
                if ( document.getElementById( 'ev-delete-with-source' ) ) {
                    payload.delete_with_source = document.getElementById( 'ev-delete-with-source' ).checked;
                }
                // Only shown when something can use it; don't wipe a stored value
                // just because the row is hidden.
                if ( document.getElementById( 'ev-reply-to-host' ) ) {
                    payload.reply_to_host = valueOf( 'ev-reply-to-host' );
                }
                saveSettings( payload, 'General' ).then( function ( ok ) {
                    inlineMsg( 'ev-general-msg', ok ? 'success' : 'error',
                        ok ? ( I18N.saved || 'Saved.' ) : ( I18N.saveFailed || 'Save failed.' ) );
                } );
            } );
        }

        var ownersForm = document.getElementById( 'ev-form-owners' );
        if ( ownersForm ) {
            ownersForm.addEventListener( 'submit', function ( e ) {
                e.preventDefault();
                var roles = [];
                document.querySelectorAll( '#ev-owner-roles input[name="owner_roles[]"]:checked' ).forEach( function ( cb ) {
                    roles.push( cb.value );
                } );
                saveSettings( { owner_roles: roles }, 'Owners' );
            } );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})();
