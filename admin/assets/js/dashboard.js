/**
 * EntryVault Dashboard (Reports) — pipeline/date filters + REST-driven cards, tables
 * and charts. Enqueued on the Dashboard screen; REST root + nonce from
 * EntryVaultDashboardBoot (was an inline <script>).
 */
(function () {
    var boot = window.EntryVaultDashboardBoot || {};
    var REST = boot.rest || '';
    var NONCE = boot.nonce || '';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    // Build a REST URL that is well-formed under BOTH pretty permalinks
    // (…/wp-json/entryvault/v1/x?foo=1) and plain permalinks, where rest_url()
    // already contains a query string (…/index.php?rest_route=/entryvault/v1/x).
    // In the plain case params MUST be joined with '&' — a second '?' breaks the
    // route match and every report 404s.
    function evUrl(path, params) {
        var url = REST + path;
        if (params) {
            var qs = Object.keys(params)
                .filter(function (k) { return params[k] !== null && params[k] !== '' && params[k] !== undefined; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
                .join('&');
            if (qs) url += (url.indexOf('?') > -1 ? '&' : '?') + qs;
        }
        return url;
    }

    function get(path, params) {
        var url = evUrl(path, params);
        return fetch(url, { headers: { 'X-WP-Nonce': NONCE }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { return (j && j.success) ? j.data : []; })
            .catch(function () { return []; });
    }

    function empty() {
        return '<p class="jnkui-muted ev-empty-line">' + esc('Nothing to show for this range yet.') + '</p>';
    }

    function defaultDates() {
        var to = new Date();
        var from = new Date();
        from.setDate(to.getDate() - 29);
        function fmt(d) {
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var dd = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + dd;
        }
        document.getElementById('ev-date-from').value = fmt(from);
        document.getElementById('ev-date-to').value = fmt(to);
    }

    function loadPipelines() {
        return get('/pipelines').then(function (data) {
            var sel = document.getElementById('ev-pipeline');
            sel.innerHTML = '';
            var rows = Array.isArray(data) ? data : (data && data.pipelines) || [];
            if (!rows.length) {
                sel.innerHTML = '<option value="">' + esc('(none)') + '</option>';
                return null;
            }
            rows.forEach(function (p) {
                var o = document.createElement('option');
                o.value = p.id;
                o.textContent = p.name || ('Pipeline #' + p.id);
                sel.appendChild(o);
            });
            return Number(sel.value);
        });
    }

    function renderCards(stages, totals) {
        var total = stages.reduce(function (s, r) { return s + (r.count || 0); }, 0);
        var data = [
            { label: 'In this date range', value: total },
            { label: 'Today', value: totals.today },
            { label: 'This week', value: totals.week },
            { label: 'This month', value: totals.month }
        ];
        document.getElementById('ev-cards').innerHTML = data.map(function (c) {
            return '<div class="jnkui-stat"><div class="jnkui-stat-label">' + esc(c.label) + '</div><div class="jnkui-stat-value">' + esc(c.value) + '</div></div>';
        }).join('');
    }

    function renderStageTable(rows) {
        var el = document.getElementById('ev-stage-table');
        if (!rows.length) { el.innerHTML = empty(); return; }
        var max = rows.reduce(function (m, r) { return Math.max(m, r.count || 0); }, 0) || 1;
        el.innerHTML = rows.map(function (r) {
            var pct = Math.round((r.count / max) * 100);
            return '<div class="ev-bar-row"><div class="name"><span class="ev-swatch" style="background:' + esc(r.color || '#999') + '"></span>' + esc(r.stage_name) + '</div>' +
                '<div class="jnkui-bar"><i style="width:' + pct + '%;background:' + esc(r.color || '#7c3aed') + '"></i></div>' +
                '<div class="count">' + esc(r.count) + '</div></div>';
        }).join('');
    }

    function renderSourceBars(rows) {
        var el = document.getElementById('ev-source-bars');
        if (!rows.length) { el.innerHTML = empty(); return; }
        var max = rows.reduce(function (m, r) { return Math.max(m, r.count || 0); }, 0) || 1;
        el.innerHTML = rows.map(function (r) {
            var pct = Math.round((r.count / max) * 100);
            // r.label is the display name ("Contact Form 7"); r.source is the stored slug.
            return '<div class="ev-bar-row"><div class="name">' + esc(r.label || r.source) + '</div>' +
                '<div class="jnkui-bar"><i style="width:' + pct + '%"></i></div>' +
                '<div class="count">' + esc(r.count) + '</div></div>';
        }).join('');
    }

    function renderDaily(rows) {
        var el = document.getElementById('ev-daily');
        if (!rows.length) { el.innerHTML = empty(); return; }
        var max = rows.reduce(function (m, r) { return Math.max(m, r.count || 0); }, 0) || 1;
        el.innerHTML = rows.map(function (r) {
            var h = Math.max(2, Math.round((r.count / max) * 100));
            return '<div class="ev-daily-bar" style="height:' + h + '%" title="' + esc(r.date + ': ' + r.count) + '"></div>';
        }).join('');
    }

    function renderFunnel(rows) {
        var html = '<table class="jnkui-table ev-table"><thead><tr><th>' + esc('Stage') + '</th><th class="is-num">' + esc('Entries') + '</th><th class="is-num">' + esc('Moved on') + '</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            var conv = (r.conversion === null || r.conversion === undefined) ? '—' : (r.conversion + '%');
            html += '<tr><td><span class="ev-swatch" style="background:' + esc(r.color || '#999') + '"></span>' + esc(r.stage_name) + '</td>';
            html += '<td class="is-num">' + esc(r.count) + '</td><td class="is-num">' + esc(conv) + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('ev-funnel').innerHTML = html;
    }

    var ownerSort = { key: 'total', dir: 'desc' };
    var ownerRows = [];

    function renderByOwner(rows) {
        ownerRows = rows || [];
        var headers = [
            { key: 'owner_name',          label: 'Owner' },
            { key: 'total',               label: 'Total' },
            { key: 'won',                 label: 'Won' },
            { key: 'lost',                label: 'Lost' },
            { key: 'win_rate',            label: 'Win rate' },
            { key: 'avg_time_in_stage_h', label: 'Avg hours per stage' }
        ];
        var sorted = ownerRows.slice().sort(function (a, b) {
            var va = a[ownerSort.key];
            var vb = b[ownerSort.key];
            if (va == null && vb == null) return 0;
            if (va == null) return 1;
            if (vb == null) return -1;
            if (typeof va === 'number' && typeof vb === 'number') {
                return ownerSort.dir === 'asc' ? va - vb : vb - va;
            }
            return ownerSort.dir === 'asc'
                ? String(va).localeCompare(String(vb))
                : String(vb).localeCompare(String(va));
        });
        var html = '<div class="jnkui-table-wrap"><table class="jnkui-table ev-table"><thead><tr>';
        headers.forEach(function (h) {
            var arrow = ownerSort.key === h.key ? (ownerSort.dir === 'asc' ? ' ▲' : ' ▼') : '';
            html += '<th data-owner-sort="' + esc(h.key) + '" class="ev-sortable' + (h.key === 'owner_name' ? '' : ' is-num') + '">' + esc(h.label) + esc(arrow) + '</th>';
        });
        html += '</tr></thead><tbody>';
        if (!sorted.length) {
            html += '<tr><td colspan="' + headers.length + '">' + esc('Nothing to show for this range yet.') + '</td></tr>';
        } else {
            sorted.forEach(function (r) {
                var wr = (r.win_rate === null || r.win_rate === undefined) ? '—' : (Math.round(r.win_rate * 100) + '%');
                html += '<tr>';
                html += '<td>' + esc(r.owner_name || '(Unknown)') + '</td>';
                html += '<td class="is-num">' + esc(r.total) + '</td>';
                html += '<td class="is-num">' + esc(r.won) + '</td>';
                html += '<td class="is-num">' + esc(r.lost) + '</td>';
                html += '<td class="is-num">' + esc(wr) + '</td>';
                html += '<td class="is-num">' + esc(r.avg_time_in_stage_h) + '</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table></div>';
        var el = document.getElementById('ev-by-owner');
        el.innerHTML = html;
        // Wire sort handlers.
        var ths = el.querySelectorAll('th[data-owner-sort]');
        for (var i = 0; i < ths.length; i++) {
            (function (th) {
                th.addEventListener('click', function () {
                    var key = th.getAttribute('data-owner-sort');
                    if (ownerSort.key === key) {
                        ownerSort.dir = ownerSort.dir === 'asc' ? 'desc' : 'asc';
                    } else {
                        ownerSort.key = key;
                        ownerSort.dir = key === 'owner_name' ? 'asc' : 'desc';
                    }
                    renderByOwner(ownerRows);
                });
            })(ths[i]);
        }
    }

    function renderTis(rows) {
        var html = '<table class="jnkui-table ev-table"><thead><tr><th>' + esc('Stage') + '</th><th class="is-num">' + esc('Average hours') + '</th><th class="is-num">' + esc('Based on') + '</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr><td>' + esc(r.stage_name) + '</td><td class="is-num">' + esc(r.avg_hours) + '</td><td class="is-num">' + esc(r.sample_size) + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('ev-tis').innerHTML = html;
    }

    function todayStr() { var d = new Date(); return d.toISOString().slice(0, 10); }
    function startOfWeek() {
        var d = new Date();
        d.setDate(d.getDate() - d.getDay());
        return d.toISOString().slice(0, 10);
    }
    function startOfMonth() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01';
    }

    function refresh() {
        var pid = Number(document.getElementById('ev-pipeline').value) || 0;
        var df  = document.getElementById('ev-date-from').value;
        var dt  = document.getElementById('ev-date-to').value;
        if (!pid) return;

        Promise.all([
            get('/reports/counts-by-stage', { pipeline_id: pid, date_from: df, date_to: dt }),
            get('/reports/counts-by-source', { pipeline_id: pid, date_from: df, date_to: dt }),
            get('/reports/daily-volume', { pipeline_id: pid, days: 30 }),
            get('/reports/funnel', { pipeline_id: pid }),
            get('/reports/time-in-stage', { pipeline_id: pid }),
            get('/reports/per-owner', { pipeline_id: pid, date_from: df, date_to: dt }),
            get('/reports/counts-by-source', { pipeline_id: pid, date_from: todayStr(), date_to: todayStr() }),
            get('/reports/counts-by-source', { pipeline_id: pid, date_from: startOfWeek(), date_to: todayStr() }),
            get('/reports/counts-by-source', { pipeline_id: pid, date_from: startOfMonth(), date_to: todayStr() })
        ]).then(function (results) {
            var stages = results[0] || [];
            var sources = results[1] || [];
            var daily = results[2] || [];
            var funnel = results[3] || [];
            var tis = results[4] || [];
            var owners = results[5] || [];
            var sumCount = function (arr) { return (arr || []).reduce(function (s, r) { return s + (r.count || 0); }, 0); };
            renderCards(stages, {
                today: sumCount(results[6]),
                week:  sumCount(results[7]),
                month: sumCount(results[8])
            });
            renderStageTable(stages);
            renderSourceBars(sources);
            renderDaily(daily);
            renderFunnel(funnel);
            renderTis(tis);
            renderByOwner(owners);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        defaultDates();
        loadPipelines().then(function (pid) {
            if (pid) refresh();
        });
        document.getElementById('ev-refresh').addEventListener('click', refresh);
        document.getElementById('ev-pipeline').addEventListener('change', refresh);
        document.getElementById('ev-date-from').addEventListener('change', refresh);
        document.getElementById('ev-date-to').addEventListener('change', refresh);
    });
})();
