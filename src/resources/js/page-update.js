/**
 * Site7 Studio – Page Package Update Workflow (Phase 9.2)
 *
 * Backs the Package Editor's "Review & Update" button for an imported Page
 * package that has drifted from its live Craft source
 * (pageImportStatus.updateAvailable, set by PackageAuthoringService::
 * getPageImportStatus()). Mirrors section-update.js (Phase 9.1) exactly,
 * against the Page-update endpoints/diff shape instead of the Section ones.
 * Safe to load alongside section-update.js on every Package Editor page load -
 * both guard on their own DOM ids, and a package is only ever one type, so
 * only one of the two ever finds its button and proceeds.
 */
(function() {
    var reviewBtn = document.getElementById('site7-review-update-btn');
    if (!reviewBtn || reviewBtn.getAttribute('data-package-kind') !== 'page') {
        return;
    }

    var panel = document.getElementById('site7-update-diff-panel');
    var handle = reviewBtn.getAttribute('data-handle');

    function escapeHtml(value) {
        return Craft.escapeHtml(String(value));
    }

    function renderKeyList(keys) {
        if (!keys.length) {
            return '<span class="light">&mdash;</span>';
        }
        return '<ul>' + keys.map(function(k) {
            return '<li><code>' + escapeHtml(k) + '</code></li>';
        }).join('') + '</ul>';
    }

    function loadDiff() {
        panel.style.display = 'block';
        panel.innerHTML = '<div class="spinner"></div>';

        fetch(Craft.getActionUrl('site7-studio/resource-import/diff-page-update', { handle: handle }), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        }).then(function(res) { return res.json(); }).then(function(res) {
            if (!res.success) {
                panel.innerHTML = '<p class="error">' + escapeHtml(res.error || 'Could not load the update diff.') + '</p>';
                return;
            }

            var d = res.diff;
            panel.innerHTML =
                '<h3>' + Craft.t('site7-studio', 'Added') + '</h3>' + renderKeyList(d.addedKeys) +
                '<h3>' + Craft.t('site7-studio', 'Removed') + '</h3>' + renderKeyList(d.removedKeys) +
                '<h3>' + Craft.t('site7-studio', 'Changed') + '</h3>' + renderKeyList(d.changedKeys) +
                '<div style="margin-top:12px;"><button type="button" class="btn submit" id="site7-confirm-update-btn">' +
                Craft.t('site7-studio', 'Confirm Update') + '</button></div>';

            document.getElementById('site7-confirm-update-btn').addEventListener('click', confirmUpdate);
        }).catch(function() {
            panel.innerHTML = '<p class="error">' + Craft.t('site7-studio', 'Error loading the update diff.') + '</p>';
        });
    }

    function confirmUpdate() {
        if (!confirm(Craft.t('site7-studio', 'Update this Page package to match its live Craft source? This cannot be undone.'))) {
            return;
        }

        var body = new URLSearchParams();
        body.set('handle', handle);
        body.set('confirmed', '1');

        fetch(Craft.getActionUrl('site7-studio/resource-import/update-page-package'), {
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
            Craft.cp.displayNotice(Craft.t('site7-studio', 'Page package updated.'));
            window.location.reload();
        }).catch(function() {
            Craft.cp.displayError('Update failed.');
        });
    }

    reviewBtn.addEventListener('click', loadDiff);
})();
