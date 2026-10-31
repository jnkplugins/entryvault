/**
 * EntryVault Tags screen — list/create/edit/delete tags over REST. Enqueued on the
 * Tags screen; reads REST root, nonce and i18n from EntryVaultTagsBoot (was inline).
 */
(function () {
    var boot  = window.EntryVaultTagsBoot || {};
    var REST  = boot.rest || '';
    var NONCE = boot.nonce || '';
    var I18N  = boot.i18n || {};

    // Messages come back in the user's language, not the site's (as wp.apiFetch does).
    function evLocale( u ) { return u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + '_locale=user'; }

    function api( path, method, body ) {
        return fetch( evLocale( REST + path ), {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE,
                'Accept': 'application/json',
            },
            body: body ? JSON.stringify( body ) : undefined,
        } ).then( function ( r ) { return r.json(); } );
    }

    var tagsCache = [];

    function renderTags() {
        var tbody = document.querySelector( '#ev-tags-table tbody' );
        tbody.innerHTML = '';
        if ( ! tagsCache.length ) {
            tbody.innerHTML = '<tr><td colspan="4" class="jnkui-muted">' + escapeHtml( I18N.empty || 'No tags yet. Add your first one on the right.' ) + '</td></tr>';
            return;
        }
        tagsCache.forEach( function ( t ) {
            var tr = document.createElement( 'tr' );
            tr.innerHTML =
                '<td><span class="ev-tag-swatch" style="background:' + escapeHtml( t.color ) + '"></span><strong>' + escapeHtml( t.name ) + '</strong></td>' +
                '<td><code>' + escapeHtml( t.slug ) + '</code></td>' +
                '<td class="jnkui-muted">' + escapeHtml( t.color ) + '</td>' +
                '<td class="is-num" style="white-space:nowrap">' +
                  '<button type="button" class="button button-small" data-edit="' + t.id + '">' + escapeHtml( I18N.edit || 'Edit' ) + '</button> ' +
                  '<button type="button" class="button button-small button-link-delete" data-delete="' + t.id + '">' + escapeHtml( I18N.delete || 'Delete' ) + '</button>' +
                '</td>';
            tbody.appendChild( tr );
        } );
    }

    function setFormTitle( editing ) {
        var h = document.getElementById( 'ev-tag-form-title' );
        if ( h ) {
            h.textContent = editing ? ( I18N.editTitle || 'Edit tag' ) : ( I18N.addTitle || 'Add a tag' );
        }
    }

    function escapeHtml( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function loadTags() {
        return api( 'tags', 'GET' ).then( function ( res ) {
            tagsCache = ( res && res.data ) ? res.data : [];
            renderTags();
        } );
    }

    document.getElementById( 'ev-tag-form' ).addEventListener( 'submit', function ( e ) {
        e.preventDefault();
        var id    = document.getElementById( 'ev-tag-id' ).value;
        var name  = document.getElementById( 'ev-tag-name' ).value;
        var color = document.getElementById( 'ev-tag-color' ).value;
        var p     = id
            ? api( 'tags/' + id, 'PUT', { name: name, color: color } )
            : api( 'tags', 'POST', { name: name, color: color } );
        p.then( function ( res ) {
            var msg = document.getElementById( 'ev-tag-msg' );
            if ( res && res.success ) {
                msg.style.color = 'var(--jnk-ok, #047857)';
                msg.textContent = I18N.saved || 'Saved.';
                resetForm();
                loadTags();
            } else {
                msg.style.color = 'var(--jnk-bad, #b91c1c)';
                msg.textContent = ( res && res.errors && res.errors[0] ) ? res.errors[0].message : ( I18N.error || 'Error.' );
            }
        } );
    } );

    document.getElementById( 'ev-tag-cancel' ).addEventListener( 'click', resetForm );

    function resetForm() {
        document.getElementById( 'ev-tag-id' ).value    = '';
        document.getElementById( 'ev-tag-name' ).value  = '';
        document.getElementById( 'ev-tag-color' ).value = '#888888';
        document.getElementById( 'ev-tag-cancel' ).style.display = 'none';
        setFormTitle( false );
    }

    document.querySelector( '#ev-tags-table tbody' ).addEventListener( 'click', function ( e ) {
        var btn      = e.target.closest ? e.target.closest( 'button' ) : e.target;
        if ( ! btn ) { return; }
        var editId   = btn.getAttribute( 'data-edit' );
        var deleteId = btn.getAttribute( 'data-delete' );
        if ( editId ) {
            var t = tagsCache.find( function ( x ) { return String( x.id ) === editId; } );
            if ( t ) {
                document.getElementById( 'ev-tag-id' ).value    = t.id;
                document.getElementById( 'ev-tag-name' ).value  = t.name;
                document.getElementById( 'ev-tag-color' ).value = t.color;
                document.getElementById( 'ev-tag-cancel' ).style.display = '';
                setFormTitle( true );
                document.getElementById( 'ev-tag-name' ).focus();
            }
        } else if ( deleteId ) {
            if ( ! window.confirm( I18N.confirmDelete || 'Delete this tag?' ) ) {
                return;
            }
            api( 'tags/' + deleteId, 'DELETE' ).then( loadTags );
        }
    } );

    loadTags();
} )();
