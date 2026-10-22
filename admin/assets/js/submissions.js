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
