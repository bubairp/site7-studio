/**
 * Site7 Studio – Starter Kit Synchronize Workflow + Details Tree (Phase 9.3)
 *
 * Two independent, self-guarding pieces on the Package Editor:
 *  - "Review & Synchronize", mirroring section-update.js/page-update.js
 *    (Phases 9.1/9.2) but against StarterKitReferenceResolverService's
 *    References list instead of a field-level diff (a Starter Kit has no
 *    fields of its own to diff - only which referenced packages drifted).
 *  - The read-only Website Structure tree (#site7-sk-tree), rendered via
 *    the same reusable Site7WebsiteTree component in browse-only mode.
 * Safe to load alongside section-update.js/page-update.js on every Package
 * Editor page load - all three guard on `data-package-kind`, and a package
 * is only ever one type, so only one ever proceeds.
 */
(function() {
    var reviewBtn = document.getElementById('site7-review-update-btn');
    if (reviewBtn && reviewBtn.getAttribute('data-package-kind') === 'website') {
        var panel = document.getElementById('site7-update-diff-panel');
        var handle = reviewBtn.getAttribute('data-handle');

        var escapeHtml = function(value) {
            return Craft.escapeHtml(String(value));
        };

        var renderRefList = function(refs) {
            if (!refs.length) {
                return '<span class="light">&mdash;</span>';
            }
            return '<ul>' + refs.map(function(r) {
                return '<li>' + escapeHtml(r.name) + ' <span class="light">(' + escapeHtml(r.type) + ')</span></li>';
            }).join('') + '</ul>';
        };

        var loadDiff = function() {
            panel.style.display = 'block';
            panel.innerHTML = '<div class="spinner"></div>';

            fetch(Craft.getActionUrl('site7-studio/resource-import/get-starter-kit-references', { handle: handle }), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            }).then(function(res) { return res.json(); }).then(function(res) {
                if (!res.success) {
                    panel.innerHTML = '<p class="error">' + escapeHtml(res.error || 'Could not load references.') + '</p>';
                    return;
                }

                var drifted = res.references.filter(function(r) { return r.updateAvailable; });
                panel.innerHTML =
                    '<h3>' + Craft.t('site7-studio', 'References with updates available') + '</h3>' + renderRefList(drifted) +
                    '<div style="margin-top:12px;"><button type="button" class="btn submit" id="site7-confirm-update-btn">' +
                    Craft.t('site7-studio', 'Confirm Synchronize') + '</button></div>';

                document.getElementById('site7-confirm-update-btn').addEventListener('click', confirmSync);
            }).catch(function() {
                panel.innerHTML = '<p class="error">' + Craft.t('site7-studio', 'Error loading references.') + '</p>';
            });
        };

        var confirmSync = function() {
            if (!confirm(Craft.t('site7-studio', 'Synchronize this Starter Kit\'s drifted references? This cannot be undone.'))) {
                return;
            }

            var body = new URLSearchParams();
            body.set('handle', handle);
            body.set('confirmed', '1');

            fetch(Craft.getActionUrl('site7-studio/resource-import/sync-starter-kit'), {
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
                    Craft.cp.displayError(res.error || 'Synchronize failed.');
                    return;
                }
                Craft.cp.displayNotice(Craft.t('site7-studio', 'Starter Kit synchronized.'));
                window.location.reload();
            }).catch(function() {
                Craft.cp.displayError('Synchronize failed.');
            });
        };

        reviewBtn.addEventListener('click', loadDiff);
    }

    var treeContainer = document.getElementById('site7-sk-tree');
    if (treeContainer && typeof Site7WebsiteTree !== 'undefined') {
        var treeData = JSON.parse(treeContainer.getAttribute('data-tree'));
        Site7WebsiteTree.render($(treeContainer), treeData, { readOnly: true });
    }
})();
