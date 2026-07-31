/**
 * Site7 Studio – Section Package Update Workflow (Phase 9.1)
 *
 * Backs the Package Editor's "Review & Update" button for an imported
 * Section package that has drifted from its live Craft source
 * (sectionImportStatus.updateAvailable, set by PackageAuthoringService::
 * getSectionImportStatus()). Fetches the diff, renders it, and on
 * confirmation POSTs the update - a small, page-local script (like
 * library.js) rather than growing the wizard-only resource-import-wizard.js,
 * since this belongs to the Package Editor, not the Import wizard.
 */
(function() {
    var reviewBtn = document.getElementById('site7-review-update-btn');
    if (!reviewBtn) {
        return;
    }

    var panel = document.getElementById('site7-update-diff-panel');
    var handle = reviewBtn.getAttribute('data-handle');

    function escapeHtml(value) {
        return Craft.escapeHtml(String(value));
    }

    function renderFieldList(fields) {
        if (!fields.length) {
            return '<span class="light">&mdash;</span>';
        }
        return '<ul>' + fields.map(function(f) {
            return '<li><code>' + escapeHtml(f.handle) + '</code> (' + escapeHtml(f.type) + ')</li>';
        }).join('') + '</ul>';
    }

    function renderChangedList(changed) {
        if (!changed.length) {
            return '<span class="light">&mdash;</span>';
        }
        return '<ul>' + changed.map(function(c) {
            return '<li><code>' + escapeHtml(c.handle) + '</code>: ' +
                escapeHtml(c.from.type) + ' &rarr; ' + escapeHtml(c.to.type) + '</li>';
        }).join('') + '</ul>';
    }

    function loadDiff() {
        panel.style.display = 'block';
        panel.innerHTML = '<div class="spinner"></div>';

        fetch(Craft.getActionUrl('site7-studio/resource-import/diff-section-update', { handle: handle }), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        }).then(function(res) { return res.json(); }).then(function(res) {
            if (!res.success) {
                panel.innerHTML = '<p class="error">' + escapeHtml(res.error || 'Could not load the update diff.') + '</p>';
                return;
            }

            var d = res.diff;
            panel.innerHTML =
                '<h3>' + Craft.t('site7-studio', 'Added Fields') + '</h3>' + renderFieldList(d.added) +
                '<h3>' + Craft.t('site7-studio', 'Removed Fields') + '</h3>' + renderFieldList(d.removed) +
                '<h3>' + Craft.t('site7-studio', 'Changed Fields') + '</h3>' + renderChangedList(d.changed) +
                '<div style="margin-top:12px;"><button type="button" class="btn submit" id="site7-confirm-update-btn">' +
                Craft.t('site7-studio', 'Confirm Update') + '</button></div>';

            document.getElementById('site7-confirm-update-btn').addEventListener('click', confirmUpdate);
        }).catch(function() {
            panel.innerHTML = '<p class="error">' + Craft.t('site7-studio', 'Error loading the update diff.') + '</p>';
        });
    }

    function confirmUpdate() {
        if (!confirm(Craft.t('site7-studio', 'Update this Section package to match its live Craft source? This cannot be undone.'))) {
            return;
        }

        var body = new URLSearchParams();
        body.set('handle', handle);
        body.set('confirmed', '1');

        fetch(Craft.getActionUrl('site7-studio/resource-import/update-section-package'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': Craft.csrfTokenValue,
            },
            body: body.toString(),
        }).then(function(res) { return res.json(); }).then(function(res) {
            if (!res.success) {
                Craft.cp.displayError(res.error || 'Update failed.');
                return;
            }
            Craft.cp.displayNotice(Craft.t('site7-studio', 'Section package updated.'));
            window.location.reload();
        }).catch(function() {
            Craft.cp.displayError('Update failed.');
        });
    }

    reviewBtn.addEventListener('click', loadDiff);
})();
