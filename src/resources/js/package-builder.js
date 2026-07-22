/**
 * Site7 Studio - Package Builder (Section Builder's field-row add/remove).
 * The submitted `fields[N][...]` indices don't need to be contiguous -
 * PackageAuthoringService::saveSectionFields() just iterates whatever comes
 * through - so new rows only need a counter that never repeats, not one that
 * fills gaps left by removed rows.
 */
(function() {
    var table = document.getElementById('site7-section-fields-table');
    var addBtn = document.getElementById('site7-add-field-row');
    if (!table || !addBtn) {
        return;
    }

    var nextIndex = table.querySelectorAll('tbody tr').length;

    function bindRemove(row) {
        var btn = row.querySelector('.site7-remove-field-row');
        if (btn) {
            btn.addEventListener('click', function() {
                row.remove();
            });
        }
    }

    table.querySelectorAll('tbody tr').forEach(bindRemove);

    addBtn.addEventListener('click', function() {
        var index = nextIndex++;
        var row = document.createElement('tr');
        row.innerHTML =
            '<td><input type="text" name="fields[' + index + '][handle]" class="text code"></td>' +
            '<td><input type="text" name="fields[' + index + '][name]" class="text"></td>' +
            '<td><input type="text" name="fields[' + index + '][instructions]" class="text fullwidth"></td>' +
            '<td><input type="text" name="fields[' + index + '][demoValue]" class="text fullwidth"></td>' +
            '<td><button type="button" class="btn delete icon site7-remove-field-row" title="Remove"></button></td>';
        table.querySelector('tbody').appendChild(row);
        bindRemove(row);
    });
})();
