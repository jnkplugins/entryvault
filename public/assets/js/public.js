/**
 * EntryVault public submissions table.
 *
 * Hydrates any `.entryvault-submissions-table[data-pipeline]` element on the
 * page by fetching `GET /entryvault/v1/public/table/{slug}?token={t}` and
 * rendering rows into a `<tbody>`. Supports client-side column sort and
 * 10-rows-per-page client pagination.
 *
 * Configuration is provided by the localized `EntryVaultPublicBoot` global.
 */
(function () {
    'use strict';

    var PER_PAGE = 10;

    function boot() {
        if (typeof window.EntryVaultPublicBoot === 'undefined') {
            return;
        }
        var nodes = document.querySelectorAll('.entryvault-submissions-table[data-pipeline]');
        for (var i = 0; i < nodes.length; i++) {
            initTable(nodes[i]);
        }
    }

    function initTable(root) {
        var pipeline = root.getAttribute('data-pipeline') || '';
        var token    = root.getAttribute('data-token') || '';
        var colsAttr = root.getAttribute('data-columns') || '';
        var columns  = colsAttr
            ? colsAttr.split(',').map(function (s) { return s.trim(); }).filter(Boolean)
            : [];
        if (!pipeline) {
            return;
        }

        var state = {
            root: root,
            pipeline: pipeline,
            token: token,
            rows: [],
            columns: columns,
            requestedColumns: columns.slice(),
            sortKey: null,
            sortDir: 'asc',
            page: 1,
            loading: false,
            error: ''
        };

        ensureScaffold(state);
        wireRefresh(state);
        load(state);
    }

    function ensureScaffold(state) {
        var root = state.root;
        if (!root.querySelector('table')) {
            root.innerHTML =
                '<button type="button" class="ev-refresh">Refresh</button>' +
                '<div class="ev-error" hidden></div>' +
                '<div class="ev-loading" hidden>Loading…</div>' +
                '<table>' +
                    '<thead><tr></tr></thead>' +
                    '<tbody></tbody>' +
                '</table>' +
                '<div class="ev-pagination">' +
                    '<button type="button" class="ev-prev">Prev</button>' +
                    '<span class="ev-page-status"></span>' +
                    '<button type="button" class="ev-next">Next</button>' +
                '</div>';
        }
    }

    function wireRefresh(state) {
        var refresh = state.root.querySelector('.ev-refresh');
        if (refresh) {
            refresh.addEventListener('click', function () { load(state); });
        }
        var prev = state.root.querySelector('.ev-prev');
        var next = state.root.querySelector('.ev-next');
        if (prev) {
            prev.addEventListener('click', function () {
                if (state.page > 1) {
                    state.page--;
                    renderRows(state);
                }
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                var pages = totalPages(state);
                if (state.page < pages) {
                    state.page++;
                    renderRows(state);
                }
            });
        }
    }

    function setLoading(state, isLoading) {
        state.loading = isLoading;
        var el = state.root.querySelector('.ev-loading');
        if (el) {
            el.hidden = !isLoading;
        }
    }

    function setError(state, msg) {
        state.error = msg || '';
        var el = state.root.querySelector('.ev-error');
        if (!el) { return; }
        if (msg) {
            el.textContent = msg;
            el.hidden = false;
        } else {
            el.textContent = '';
            el.hidden = true;
        }
    }

    function load(state) {
        var boot = window.EntryVaultPublicBoot || {};
        var base = (boot.restUrl || '/wp-json/entryvault/v1/').replace(/\/+$/, '/');
        var url  = base + 'public/table/' + encodeURIComponent(state.pipeline);
        var qs   = [];
        if (state.token) {
            qs.push('token=' + encodeURIComponent(state.token));
        }
        if (state.requestedColumns && state.requestedColumns.length) {
            qs.push('columns=' + encodeURIComponent(state.requestedColumns.join(',')));
        }
        if (state.sortKey) {
            qs.push('sort=' + encodeURIComponent(state.sortKey));
        }
        if (qs.length) {
            // Under PLAIN permalinks restUrl already contains '?rest_route=…',
            // so params must be joined with '&', never a second '?'.
            url += (url.indexOf('?') > -1 ? '&' : '?') + qs.join('&');
        }

        setError(state, '');
        setLoading(state, true);

        var headers = { 'Accept': 'application/json' };
        if (boot.nonce) {
            headers['X-WP-Nonce'] = boot.nonce;
        }

        fetch(url, { credentials: 'same-origin', headers: headers })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                return resp.json();
            })
            .then(function (json) {
                var data = json && json.data ? json.data : [];
                var meta = json && json.meta ? json.meta : {};
                state.rows = Array.isArray(data) ? data : [];
                if (state.requestedColumns && state.requestedColumns.length) {
                    state.columns = state.requestedColumns.slice();
                } else if (Array.isArray(meta.columns) && meta.columns.length) {
                    state.columns = meta.columns;
                } else {
                    state.columns = defaultColumns(state.rows);
                }
                if (state.sortKey === null && state.columns.length) {
                    state.sortKey = state.columns[0];
                }
                state.page = 1;
                renderHeader(state);
                renderRows(state);
            })
            .catch(function (err) {
                setError(state, 'Failed to load submissions: ' + (err && err.message ? err.message : err));
                state.rows = [];
                renderRows(state);
            })
            .then(function () {
                setLoading(state, false);
            });
    }

    function defaultColumns(rows) {
        if (!rows.length) { return []; }
        var sample = rows[0].cells || rows[0];
        return Object.keys(sample);
    }

    function cellValue(row, key) {
        if (row && row.cells && Object.prototype.hasOwnProperty.call(row.cells, key)) {
            return row.cells[key];
        }
        if (row && Object.prototype.hasOwnProperty.call(row, key)) {
            return row[key];
        }
        return '';
    }

    function renderHeader(state) {
        var tr = state.root.querySelector('thead tr');
        if (!tr) { return; }
        tr.innerHTML = '';
        for (var i = 0; i < state.columns.length; i++) {
            var key = state.columns[i];
            var th  = document.createElement('th');
            th.setAttribute('data-key', key);
            th.textContent = humanize(key);
            if (state.sortKey === key) {
                th.setAttribute('data-sort-dir', state.sortDir);
            }
            (function (col) {
                th.addEventListener('click', function () {
                    if (state.sortKey === col) {
                        state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        state.sortKey = col;
                        state.sortDir = 'asc';
                    }
                    state.page = 1;
                    renderHeader(state);
                    renderRows(state);
                });
            })(key);
            tr.appendChild(th);
        }
    }

    function humanize(key) {
        return String(key)
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function sortedRows(state) {
        var rows = state.rows.slice();
        var key  = state.sortKey;
        if (!key) { return rows; }
        var dir = state.sortDir === 'desc' ? -1 : 1;
        rows.sort(function (a, b) {
            var av = String(cellValue(a, key) || '').toLowerCase();
            var bv = String(cellValue(b, key) || '').toLowerCase();
            if (av < bv) { return -1 * dir; }
            if (av > bv) { return  1 * dir; }
            return 0;
        });
        return rows;
    }

    function totalPages(state) {
        return Math.max(1, Math.ceil(state.rows.length / PER_PAGE));
    }

    function renderRows(state) {
        var tbody = state.root.querySelector('tbody');
        if (!tbody) { return; }
        tbody.innerHTML = '';

        var rows = sortedRows(state);
        var start = (state.page - 1) * PER_PAGE;
        var slice = rows.slice(start, start + PER_PAGE);

        if (!slice.length) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.className = 'ev-empty';
            td.colSpan = Math.max(1, state.columns.length);
            td.textContent = state.error ? '' : 'No submissions found.';
            tr.appendChild(td);
            tbody.appendChild(tr);
        } else {
            for (var i = 0; i < slice.length; i++) {
                var row = slice[i];
                var tr2 = document.createElement('tr');
                for (var j = 0; j < state.columns.length; j++) {
                    var col = state.columns[j];
                    var td2 = document.createElement('td');
                    td2.textContent = String(cellValue(row, col) == null ? '' : cellValue(row, col));
                    tr2.appendChild(td2);
                }
                tbody.appendChild(tr2);
            }
        }

        var pages  = totalPages(state);
        var status = state.root.querySelector('.ev-page-status');
        var prev   = state.root.querySelector('.ev-prev');
        var next   = state.root.querySelector('.ev-next');
        if (status) {
            status.textContent = 'Page ' + state.page + ' of ' + pages;
        }
        if (prev) { prev.disabled = state.page <= 1; }
        if (next) { next.disabled = state.page >= pages; }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
