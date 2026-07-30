/**
 * Site7 Studio - Synchronization & Update Engine wizard (Website Starter Kit
 * System Phase 8). Pure presentation/orchestration, following the exact
 * same shape as install-wizard.js: every step calls one of
 * UpdateWizardController's actions and renders the response. No diff or
 * execution decision is ever made in here.
 */
(function ($) {
    if (typeof Craft === 'undefined') {
        return;
    }

    const ENDPOINTS = {
        plan: 'site7-studio/update-wizard/plan',
        execute: 'site7-studio/update-wizard/execute',
        progress: 'site7-studio/update-wizard/progress',
    };

    const POLL_INTERVAL_MS = 1500;

    function postJson(action, params) {
        const body = new URLSearchParams();
        Object.keys(params || {}).forEach(function (key) {
            const value = params[key];
            if (Array.isArray(value)) {
                value.forEach((v) => body.append(key + '[]', v));
            } else {
                body.append(key, value);
            }
        });
        return fetch(Craft.getActionUrl(action), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-Token': Craft.csrfTokenValue },
        }).then((res) => res.json());
    }

    function getJson(action, params) {
        const url = new URL(Craft.getActionUrl(action));
        Object.keys(params || {}).forEach((key) => url.searchParams.set(key, params[key]));
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then((res) => res.json());
    }

    function showPanel($root, step) {
        $root.find('.s7-wizard-panel').attr('hidden', true);
        $root.find('[data-panel="' + step + '"]').removeAttr('hidden');
        $root.find('.s7-wizard-step-indicator').removeClass('is-active');
        $root.find('.s7-wizard-step-indicator[data-step="' + step + '"]').addClass('is-active');
    }

    function renderChecks($container, validationResult) {
        const rows = validationResult.checks
            .map(
                (c) =>
                    '<li class="' +
                    (c.passed ? 's7-check-pass' : 's7-check-fail') +
                    '"><strong>' +
                    Craft.escapeHtml(c.name) +
                    ':</strong> ' +
                    Craft.escapeHtml(c.detail) +
                    '</li>'
            )
            .join('');
        const warnings = validationResult.warnings.map((w) => '<li>' + Craft.escapeHtml(w) + '</li>').join('');
        const errors = validationResult.errors.map((e) => '<li>' + Craft.escapeHtml(e) + '</li>').join('');

        $container.html(
            '<h3>Checks</h3><ul class="bullets">' +
                (rows || '<li>No checks were run.</li>') +
                '</ul>' +
                (errors ? '<h3>Errors</h3><ul class="bullets s7-errors">' + errors + '</ul>' : '') +
                (warnings ? '<h3>Warnings</h3><ul class="bullets s7-warnings">' + warnings + '</ul>' : '')
        );
    }

    function renderPlan($container, plan) {
        const stepRows = plan.steps
            .map((s) => '<tr><td>' + Craft.escapeHtml(s.action) + '</td><td>' + Craft.escapeHtml(s.label) + '</td></tr>')
            .join('');

        const removalRows = plan.removals
            .map(
                (s) =>
                    '<li><label><input type="checkbox" class="s7-removal-checkbox" value="' +
                    Craft.escapeHtml(s.key) +
                    '"> ' +
                    Craft.escapeHtml(s.label) +
                    ' (unchecked = left in place)</label></li>'
            )
            .join('');

        const conflictRows = plan.conflicts
            .map((c) => '<li><strong>' + Craft.escapeHtml(c.resourceKey) + ':</strong> ' + Craft.escapeHtml(c.description) + '</li>')
            .join('');

        $container.html(
            '<h3>' +
                plan.steps.length +
                ' change(s) will be applied automatically</h3><table class="data fullwidth"><thead><tr><th>Action</th><th>Resource</th></tr></thead><tbody>' +
                (stepRows || '<tr><td colspan="2">None.</td></tr>') +
                '</tbody></table>' +
                (plan.removals.length
                    ? '<h3>' + plan.removals.length + ' resource(s) no longer in the new version</h3><ul class="bullets">' + removalRows + '</ul>'
                    : '') +
                (plan.conflicts.length
                    ? '<h3 class="s7-errors">' + plan.conflicts.length + ' conflict(s) require manual review</h3><ul class="bullets s7-errors">' + conflictRows + '</ul>'
                    : '')
        );
    }

    function appendLogEntries($log, entries) {
        entries.forEach((entry) => {
            const li = document.createElement('li');
            li.className = 's7-log-' + entry.status;
            li.textContent = '[' + entry.status + '] ' + entry.label + (entry.message ? ' - ' + entry.message : '');
            $log.append(li);
        });
    }

    function initWizard() {
        const $root = $('#s7-update-wizard');
        if (!$root.length) {
            return;
        }

        let selectedHandle = null;
        let sessionUid = null;
        let renderedLogCount = 0;
        let pollTimer = null;

        $root.on('click', '.s7-select-update', function () {
            selectedHandle = $(this).closest('.s7-kit-card').data('handle');
            $root.find('[data-bind="selectedKitName"]').text(selectedHandle);
            showPanel($root, 'checks');
        });

        $root.on('click', '#s7-run-plan', function () {
            const dryRun = $root.find('#s7-sync-dry-run').is(':checked');
            const $btn = $(this).prop('disabled', true);

            postJson(ENDPOINTS.plan, { handle: selectedHandle, dryRun: dryRun ? 1 : 0 })
                .then((response) => {
                    $btn.prop('disabled', false);
                    if (!response.success) {
                        Craft.cp.displayError(response.error || 'Could not build a synchronization plan.');
                        return;
                    }
                    sessionUid = response.sessionUid;
                    renderChecks($root.find('#s7-sync-checks-results'), response.validationResult);
                    renderPlan($root.find('#s7-sync-plan-preview'), response.plan);
                    showPanel($root, 'preview');
                    if (!response.validationResult.valid) {
                        $root.find('#s7-start-sync').prop('disabled', true);
                    }
                })
                .catch(() => {
                    $btn.prop('disabled', false);
                    Craft.cp.displayError('Could not reach the server to plan this update.');
                });
        });

        $root.on('click', '[data-back-to]', function () {
            showPanel($root, $(this).data('back-to'));
        });

        $root.on('click', '#s7-start-sync', function () {
            $(this).prop('disabled', true);
            showPanel($root, 'execute');
            renderedLogCount = 0;
            $root.find('#s7-sync-progress-log').empty();

            const confirmedRemovalKeys = $root
                .find('.s7-removal-checkbox:checked')
                .map(function () {
                    return this.value;
                })
                .get();

            postJson(ENDPOINTS.execute, { sessionUid: sessionUid, confirmedRemovalKeys: confirmedRemovalKeys }).then((response) => {
                if (!response.success) {
                    Craft.cp.displayError('Could not queue the synchronization.');
                    return;
                }
                pollTimer = setInterval(pollProgress, POLL_INTERVAL_MS);
                pollProgress();
            });
        });

        function pollProgress() {
            getJson(ENDPOINTS.progress, { sessionUid: sessionUid }).then((response) => {
                if (!response.success) {
                    return;
                }

                const newEntries = response.progressLog.slice(renderedLogCount);
                appendLogEntries($root.find('#s7-sync-progress-log'), newEntries);
                renderedLogCount = response.progressLog.length;

                const percent = response.totalStages > 0 ? Math.round((response.stagesCompleted.length / response.totalStages) * 100) : 0;
                $root.find('#s7-sync-progress-fill').css('width', percent + '%');

                if (response.done) {
                    clearInterval(pollTimer);
                    const template = $root.data('summary-url-template');
                    window.location.href = template.replace('__UID__', sessionUid);
                }
            });
        }
    }

    if (typeof Garnish !== 'undefined') {
        Garnish.$doc.ready(initWizard);
    } else {
        $(initWizard);
    }
})(jQuery);
