/**
 * EntryVault CSV Import screen — preview/map/run CSV imports over REST.
 * Enqueued on the Import screen; data + i18n from EntryVaultImportBoot (was inline).
 */
(function () {
    var boot = window.EntryVaultImportBoot || {};
    var I18N = boot.i18n || {};
    var REST_ROOT  = boot.restRoot || '';
    var REST_NONCE = boot.restNonce || '';
    var STAGES     = boot.stages || [];

    var fileInput  = document.getElementById('ev-import-file');
    var textarea   = document.getElementById('ev-import-textarea');
    var previewBtn = document.getElementById('ev-import-preview-btn');
    var runBtn     = document.getElementById('ev-import-run-btn');
    var statusEl   = document.getElementById('ev-import-status');
    var previewWrap = document.getElementById('ev-import-preview-wrap');
    var mappingTbody = document.querySelector('#ev-import-mapping-table tbody');
    var pipelineSel = document.getElementById('ev-import-pipeline');
    var stageSel    = document.getElementById('ev-import-stage');
    var dryRunChk   = document.getElementById('ev-import-dryrun');
    var resultsEl   = document.getElementById('ev-import-results');

    var lastCsv     = '';
    var lastHeaders = [];

    function setStatus(msg, isError) {
        statusEl.textContent = msg || '';
        statusEl.style.color = isError ? '#b32d2e' : '';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Messages come back in the user's language, not the site's (as wp.apiFetch does).
    function evLocale( u ) { return u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + '_locale=user'; }

    function api(path, body) {
        return fetch(evLocale(REST_ROOT + path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': REST_NONCE,
                'Accept': 'application/json'
            },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        });
    }

    fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) { return; }
        var reader = new FileReader();
        reader.onload = function () {
            textarea.value = String(reader.result || '');
        };
        reader.readAsText(f);
    });

    function refreshStages() {
        var pid = parseInt(pipelineSel.value, 10) || 0;
        stageSel.innerHTML = '<option value="0">' + escapeHtml( I18N.firstStage || 'First stage of the pipeline' ) + '</option>';
        STAGES.forEach(function (s) {
            if (pid > 0 && s.pipeline_id !== pid) { return; }
            var opt = document.createElement('option');
            opt.value = String(s.id);
            opt.textContent = s.name;
            stageSel.appendChild(opt);
        });
    }
    pipelineSel.addEventListener('change', refreshStages);
    refreshStages();

    previewBtn.addEventListener('click', function () {
        var csv = textarea.value || '';
        if (!csv.trim()) {
            setStatus(I18N.please_choose_a, true);
            return;
        }
        setStatus(I18N.loading_preview);
        api('/import/preview', { csv: csv }).then(function (res) {
            if (res.status !== 200 || !res.body || !res.body.success) {
                setStatus(I18N.preview_failed, true);
                return;
            }
            lastCsv     = csv;
            lastHeaders = res.body.data.headers || [];
            renderMapping(res.body.data);
            previewWrap.style.display = '';
            setStatus(
                I18N.loaded_s_rows
                    .replace('%s', String(res.body.data.total_rows || 0))
            );
        }).catch(function () {
            setStatus(I18N.network_error, true);
        });
    });

    /**
     * The column that says when an entry was sent ("created_at", "Date",
     * "Submitted on"...). Mapped as Date, the entries keep their own dates
     * instead of all landing as "just now".
     */
    function isDateHeader(lower) {
        if (/updated|modified|last|birth|due|expir/.test(lower)) { return false; }
        return /(^|[^a-z])(date|created|submitted|submission time|time|timestamp)([^a-z]|$)/.test(lower)
            || lower === 'created_at' || lower === 'date_created';
    }

    function renderMapping(data) {
        var dateTaken = false;
        mappingTbody.innerHTML = '';
        var headers = data.headers || [];
        var rows = data.rows || [];
        headers.forEach(function (h) {
            var tr = document.createElement('tr');
            var sample = rows[0] ? (rows[0][h] || '') : '';

            var tdName = document.createElement('td');
            tdName.innerHTML = '<code>' + escapeHtml(h) + '</code>';
            tr.appendChild(tdName);

            var tdSample = document.createElement('td');
            tdSample.textContent = String(sample).slice(0, 80);
            tr.appendChild(tdSample);

            var tdMap = document.createElement('td');
            var sel = document.createElement('select');
            sel.dataset.col = h;
            sel.className = 'ev-import-map';
            [
                ['ignore', I18N.ignore],
                ['title',  I18N.title],
                ['email',  I18N.email],
                ['source', I18N.source],
                ['date',   I18N.date],
                ['field',  I18N.field]
            ].forEach(function (pair) {
                var opt = document.createElement('option');
                opt.value = pair[0];
                opt.textContent = pair[1];
                sel.appendChild(opt);
            });
            // Best-effort autodetect.
            var lower = String(h).toLowerCase();
            if (lower.indexOf('email') !== -1) sel.value = 'email';
            else if (lower === 'title' || lower === 'name' || lower === 'subject') sel.value = 'title';
            else if (lower === 'source') sel.value = 'source';
            else if (!dateTaken && isDateHeader(lower)) { sel.value = 'date'; dateTaken = true; }
            else sel.value = 'field';
            tdMap.appendChild(sel);
            tr.appendChild(tdMap);

            mappingTbody.appendChild(tr);
        });

        resolveDuplicateTitles();
    }

    /**
     * Only one column can be the title, so if the guesses picked several, keep the
     * best one and store the rest as fields.
     *
     * "Name" and "Subject" both used to be guessed as Title. Two columns claiming
     * the same target meant the last one silently won and the other was dropped
     * altogether — importing Name,Email,Subject,Message stored the subject as the
     * title and lost the person's name, with nothing on screen to say so.
     */
    function resolveDuplicateTitles() {
        var selects = Array.prototype.slice.call(
            mappingTbody.querySelectorAll('select.ev-import-map')
        );
        var titled = selects.filter(function (s) { return s.value === 'title'; });
        if (titled.length < 2) {
            return;
        }
        // A person's name is the most useful label for a card; fall back to an
        // explicit "title" column, then anything else.
        var rank = function (s) {
            var l = String(s.dataset.col || '').toLowerCase();
            if (l === 'name' || l.indexOf('name') !== -1) return 0;
            if (l === 'title') return 1;
            return 2;
        };
        titled.sort(function (a, b) { return rank(a) - rank(b); });
        titled.slice(1).forEach(function (s) { s.value = 'field'; });
    }

    runBtn.addEventListener('click', function () {
        var mapping = { fields_cols: [] };
        var selects = mappingTbody.querySelectorAll('select.ev-import-map');
        var titleCols = [];
        selects.forEach(function (s) {
            if (s.value === 'title') { titleCols.push(s.dataset.col); }
        });
        // Say so rather than quietly discarding one of them.
        if (titleCols.length > 1) {
            setStatus(I18N.one_title_only.replace('%s', titleCols.join(', ')), true);
            return;
        }
        selects.forEach(function (s) {
            var col = s.dataset.col;
            switch (s.value) {
                case 'title':  mapping.title_col = col; break;
                case 'email':  mapping.email_col = col; break;
                case 'source': mapping.source_col = col; break;
                case 'date':   if (!mapping.date_col) { mapping.date_col = col; } else { mapping.fields_cols.push(col); } break;
                case 'field':  mapping.fields_cols.push(col); break;
                default: break;
            }
        });
        if (!mapping.email_col) {
            setStatus(I18N.you_must_map, true);
            return;
        }
        mapping.pipeline_id = parseInt(pipelineSel.value, 10) || 0;
        mapping.stage_id    = parseInt(stageSel.value, 10) || 0;

        var dryRun = !!dryRunChk.checked;
        setStatus(I18N.running);
        runBtn.disabled = true;
        var runRulesChk = document.getElementById('ev-import-run-rules');
        api('/import/run', { csv: lastCsv, mapping: mapping, dry_run: dryRun, run_rules: !!(runRulesChk && runRulesChk.checked) }).then(function (res) {
            runBtn.disabled = false;
            if (res.status !== 200 || !res.body || !res.body.success) {
                setStatus(I18N.import_failed, true);
                return;
            }
            renderResults(res.body.data);
            setStatus(I18N.done);
        }).catch(function () {
            runBtn.disabled = false;
            setStatus(I18N.network_error, true);
        });
    });

    function renderResults(data) {
        var html = '<h2>' + escapeHtml(I18N.results) + '</h2>';
        html += '<p>';
        if (data.dry_run) {
            html += '<em>' + escapeHtml(I18N.dry_run_no) + '</em><br>';
        }
        // A dry run inserts nothing, so labelling the count "Imported" directly
        // under "no rows were inserted" contradicted itself.
        var countLabel = data.dry_run ? I18N.would_import : I18N.imported;
        html += escapeHtml(countLabel) + ' <strong>' + (parseInt(data.imported, 10) || 0) + '</strong>';
        // Duplicates used to be counted as imports, so re-running a file reported
        // rows added that were in fact recognised and skipped.
        var dupes = parseInt(data.duplicates, 10) || 0;
        if (dupes) {
            html += ' · ' + escapeHtml(I18N.duplicates) + ' <strong>' + dupes + '</strong>';
        }
        html += ' · ' + escapeHtml(I18N.skipped) + ' <strong>' + (parseInt(data.skipped, 10) || 0) + '</strong>';
        html += '</p>';
        if (dupes && !data.dry_run) {
            html += '<p class="description">' + escapeHtml(I18N.duplicates_hint) + '</p>';
        }
        var badDates = parseInt(data.bad_dates, 10) || 0;
        if (badDates) {
            html += '<p class="description">' + escapeHtml(I18N.bad_dates.replace('%s', String(badDates))) + '</p>';
        }
        if (!data.dry_run && (parseInt(data.imported, 10) || 0) > 0 && boot.boardUrl) {
            html += '<p><a class="button button-primary" href="' + escapeHtml(boot.boardUrl) + '">' + escapeHtml(I18N.open_board) + '</a></p>';
        }
        var errs = (data.errors && data.errors.length) ? data.errors : [];
        if (errs.length) {
            html += '<h3>' + escapeHtml(I18N.errors) + '</h3>';
            html += '<ul>';
            errs.forEach(function (e) {
                html += '<li>'
                     + escapeHtml(I18N.row)
                     + ' ' + escapeHtml(e.row) + ': ' + escapeHtml(e.message) + '</li>';
            });
            html += '</ul>';
        }
        resultsEl.innerHTML = html;
    }
})();
