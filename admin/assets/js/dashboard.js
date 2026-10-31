/**
 * EntryVault Dashboard (Reports) — pipeline/date filters + REST-driven cards, tables
 * and charts. Enqueued on the Dashboard screen; REST root + nonce from
 * EntryVaultDashboardBoot (was an inline <script>).
 */
(function () {
    var boot = window.EntryVaultDashboardBoot || {};
    var REST = boot.rest || '';
    var NONCE = boot.nonce || '';
    // Translations come from languages/entryvault-<locale>-<md5>.json through
    // wp_set_script_translations(); the fallbacks only matter if wp-i18n is gone.
    var i18n = (window.wp && window.wp.i18n) || {};
    var __ = i18n.__ || function (s) { return s; };
    var _n = i18n._n || function (s, p, n) { return n === 1 ? s : p; };
    var sprintf = i18n.sprintf || function (f) {
        var a = [].slice.call(arguments, 1), i = 0;
        return f.replace(/%(?:(\d+)\$)?[sd]/g, function (m, k) { return a[k ? k - 1 : i++]; });
    };

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

    // Messages come back in the user's language, not the site's (as wp.apiFetch does).
    function evLocale( u ) { return u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + '_locale=user'; }

    function get(path, params) {
        var url = evUrl(path, params);
        return fetch(evLocale(url), { headers: { 'X-WP-Nonce': NONCE, 'accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { return (j && j.success) ? j.data : []; })
            .catch(function () { return []; });
    }

    function empty() {
        return '<p class="jnkui-muted ev-empty-line">' + esc(__('Nothing to show for this range yet.', 'entryvault')) + '</p>';
    }

    // Days are the site's days (Settings > General), sent as boot.today.
    function siteToday() {
        return /^\d{4}-\d{2}-\d{2}$/.test(boot.today || '') ? boot.today : new Date().toISOString().slice(0, 10);
    }

    function defaultDates() {
        // Calendar arithmetic on the site's "Y-m-d", done in UTC so no
        // daylight-saving day is skipped or repeated.
        var to = new Date(siteToday() + 'T00:00:00Z');
        var from = new Date(to.getTime() - 29 * 86400000);
        document.getElementById('ev-date-from').value = from.toISOString().slice(0, 10);
        document.getElementById('ev-date-to').value = to.toISOString().slice(0, 10);
    }

    function loadPipelines() {
        return get('/pipelines').then(function (data) {
            var sel = document.getElementById('ev-pipeline');
            sel.innerHTML = '';
            var rows = Array.isArray(data) ? data : (data && data.pipelines) || [];
            if (!rows.length) {
                sel.innerHTML = '<option value="">' + esc(__('(none)', 'entryvault')) + '</option>';
                return null;
            }
            rows.forEach(function (p) {
                var o = document.createElement('option');
                o.value = p.id;
                /* translators: %d: pipeline ID, shown when the pipeline has no name. */
                o.textContent = p.name || sprintf(__('Pipeline #%d', 'entryvault'), p.id);
                sel.appendChild(o);
            });
            return Number(sel.value);
        });
    }

    function renderCards(stages, totals) {
        var total = stages.reduce(function (s, r) { return s + (r.count || 0); }, 0);
        var data = [
            { label: __('In this date range', 'entryvault'), value: total },
            { label: __('Today', 'entryvault'), value: totals.today },
            { label: __('This week', 'entryvault'), value: totals.week },
            { label: __('This month', 'entryvault'), value: totals.month }
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
            return '<div class="ev-daily-bar" style="height:' + h + '%" title="' + esc(sprintf(
                /* translators: 1: date (YYYY-MM-DD), 2: number of entries that day. */
                _n('%1$s: %2$d entry', '%1$s: %2$d entries', r.count, 'entryvault'), r.date, r.count)) + '"></div>';
        }).join('');
    }

    function renderFunnel(rows) {
        var html = '<table class="jnkui-table ev-table"><thead><tr><th>' + esc(__('Stage', 'entryvault')) + '</th><th class="is-num">' + esc(__('Entries', 'entryvault')) + '</th><th class="is-num">' + esc(__('Moved on', 'entryvault')) + '</th></tr></thead><tbody>';
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
            { key: 'owner_name',          label: __('Owner', 'entryvault') },
            { key: 'total',               label: __('Total', 'entryvault') },
            { key: 'won',                 label: __('Won', 'entryvault') },
            { key: 'lost',                label: __('Lost', 'entryvault') },
            { key: 'win_rate',            label: __('Win rate', 'entryvault') },
            { key: 'avg_time_in_stage_h', label: __('Avg hours per stage', 'entryvault') }
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
            html += '<tr><td colspan="' + headers.length + '">' + esc(__('Nothing to show for this range yet.', 'entryvault')) + '</td></tr>';
        } else {
            sorted.forEach(function (r) {
                var wr = (r.win_rate === null || r.win_rate === undefined) ? '—' : (Math.round(r.win_rate * 100) + '%');
                html += '<tr>';
                html += '<td>' + esc(r.owner_name || __('(Unknown)', 'entryvault')) + '</td>';
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
        var html = '<table class="jnkui-table ev-table"><thead><tr><th>' + esc(__('Stage', 'entryvault')) + '</th><th class="is-num">' + esc(__('Average hours', 'entryvault')) + '</th><th class="is-num">' + esc(__('Based on', 'entryvault')) + '</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr><td>' + esc(r.stage_name) + '</td><td class="is-num">' + esc(r.avg_hours) + '</td><td class="is-num">' + esc(r.sample_size) + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('ev-tis').innerHTML = html;
    }

    function todayStr() { return siteToday(); }
    function startOfWeek() {
        if (boot.weekStart) return boot.weekStart;
        var d = new Date(siteToday() + 'T00:00:00Z');
        return new Date(d.getTime() - d.getUTCDay() * 86400000).toISOString().slice(0, 10);
    }
    function startOfMonth() {
        return boot.monthStart || (siteToday().slice(0, 8) + '01');
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
