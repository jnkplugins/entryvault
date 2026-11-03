/**
 * EntryVault Submissions list table — reveal the owner/tag pickers only when the
 * matching bulk action is chosen. Enqueued on the Submissions screen (was an
 * inline <script> in Submissions_List_Table).
 */
(function () {
    function sync() {
        var selects = document.querySelectorAll('select[name="action"], select[name="action2"]');
        var val = '-1';
        for (var i = 0; i < selects.length; i++) {
            if (selects[i].value && selects[i].value !== '-1') { val = selects[i].value; break; }
        }
        var ownerEls = document.querySelectorAll('.entryvault-bulk-owner');
        var tagEls   = document.querySelectorAll('.entryvault-bulk-tag');
        for (var j = 0; j < ownerEls.length; j++) {
            ownerEls[j].style.display = (val === 'bulk_assign_owner') ? '' : 'none';
        }
        for (var k = 0; k < tagEls.length; k++) {
            tagEls[k].style.display = (val === 'bulk_add_tag') ? '' : 'none';
        }
    }
    document.addEventListener('change', function (e) {
        if (e.target && (e.target.name === 'action' || e.target.name === 'action2')) {
            sync();
        }
    });
    if (document.readyState !== 'loading') { sync(); }
    else { document.addEventListener('DOMContentLoaded', sync); }
})();

/* Star toggle in the list: POST entryvault/v1/submissions/<id>/flags. */
(function () {
    var boot = window.EntryVaultSubmissionsBoot;
    if (!boot || !boot.restUrl) { return; }
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.ev-star-toggle') : null;
        if (!btn || btn.getAttribute('aria-busy') === 'true') { return; }
        e.preventDefault();
        var on = btn.getAttribute('aria-pressed') !== 'true';
        btn.setAttribute('aria-busy', 'true');
        // _locale=user: messages in the user's language, as wp.apiFetch does.
        var url = boot.restUrl + 'submissions/' + encodeURIComponent(btn.getAttribute('data-id')) + '/flags';
        url += (url.indexOf('?') === -1 ? '?' : '&') + '_locale=user'; // plain permalinks already carry ?rest_route=
        window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' },
            body: JSON.stringify({ starred: on })
        }).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.classList.toggle('is-on', on);
            var icon = btn.querySelector('.dashicons');
            if (icon) {
                icon.classList.toggle('dashicons-star-filled', on);
                icon.classList.toggle('dashicons-star-empty', !on);
            }
        }).catch(function () {
            if (boot.failed) { window.alert(boot.failed); }
        }).then(function () {
            btn.removeAttribute('aria-busy');
        });
    });
})();

// Export panel: open it from the header button, tick all / none, and move
// columns up and down (the form posts cols[] in the order they stand).
(function () {
    var boot = window.EntryVaultSubmissionsBoot || {};
    var box = document.getElementById('ev-export');
    if (!box) { return; }
    var opener = document.querySelector('[data-ev-export-open]');
    if (opener) {
        opener.addEventListener('click', function (e) {
            e.preventDefault();
            box.open = true;
            box.scrollIntoView({ block: 'start' });
            var s = box.querySelector('summary');
            if (s) { s.focus(); }
        });
    }
    var list = box.querySelector('.ev-export-cols');
    box.addEventListener('click', function (e) {
        var t = e.target.closest('[data-ev-cols]');
        if (t) {
            var on = t.getAttribute('data-ev-cols') === 'all';
            list.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = on; });
        }
    });
    if (!list) { return; }
    function button(cls, text, label) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'button-link ' + cls;
        b.textContent = text;
        b.setAttribute('aria-label', label);
        b.title = label;
        return b;
    }
    list.querySelectorAll('li').forEach(function (li) {
        var name = (li.querySelector('label') || {}).textContent || '';
        var up = button('ev-col-up', '↑', (boot.moveUp || 'Move up') + ': ' + name.trim());
        var down = button('ev-col-down', '↓', (boot.moveDown || 'Move down') + ': ' + name.trim());
        up.addEventListener('click', function () {
            if (li.previousElementSibling) { li.parentNode.insertBefore(li, li.previousElementSibling); up.focus(); }
        });
        down.addEventListener('click', function () {
            if (li.nextElementSibling) { li.parentNode.insertBefore(li.nextElementSibling, li); down.focus(); }
        });
        li.appendChild(up);
        li.appendChild(down);
    });
})();
