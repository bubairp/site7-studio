/**
 * Site7 Studio - Fresh-Install Setup Wizard (Website Starter Kit System
 * Phase 7). Pure presentation/orchestration: every step calls one of
 * InstallWizardController's actions and renders the response. No
 * installation decision is ever made in here - this file only displays
 * whatever InstallationPlanner/InstallationValidator/the queued
 * InstallationSession already produced server-side.
 */
(function ($) {
    if (typeof Craft === 'undefined') {
        return;
    }

    const ENDPOINTS = {
        validate: 'site7-studio/install-wizard/validate',
        execute: 'site7-studio/install-wizard/execute',
        progress: 'site7-studio/install-wizard/progress',
    };

    const POLL_INTERVAL_MS = 1500;

    function postJson(action, params) {
        const body = new URLSearchParams();
        Object.keys(params || {}).forEach(function (key) {
            body.append(key, params[key]);
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

    function renderValidationResult($container, result) {
        const rows = result.checks
            .map(
                (check) =>
                    '<li class="' +
                    (check.passed ? 's7-check-pass' : 's7-check-fail') +
                    '"><strong>' +
                    Craft.escapeHtml(check.name) +
                    ':</strong> ' +
                    Craft.escapeHtml(check.detail) +
                    '</li>'
            )
            .join('');

        const warnings = result.warnings.map((w) => '<li>' + Craft.escapeHtml(w) + '</li>').join('');
        const errors = result.errors.map((e) => '<li>' + Craft.escapeHtml(e) + '</li>').join('');

        $container.html(
            '<h3>Checks</h3><ul class="bullets">' +
                (rows || '<li>No checks were run.</li>') +
                '</ul>' +
                (errors ? '<h3>Errors</h3><ul class="bullets s7-errors">' + errors + '</ul>' : '') +
                (warnings ? '<h3>Warnings</h3><ul class="bullets s7-warnings">' + warnings + '</ul>' : '')
        );
    }

    function renderPlan($container, plan) {
        const rows = plan.steps
            .map((step) => '<tr><td>' + Craft.escapeHtml(step.type) + '</td><td>' + Craft.escapeHtml(step.label) + '</td></tr>')
            .join('');

        $container.html(
            '<p>' +
                plan.steps.length +
                ' step(s) planned.</p><table class="data fullwidth"><thead><tr><th>Type</th><th>Step</th></tr></thead><tbody>' +
                rows +
                '</tbody></table>'
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
        const $root = $('#s7-install-wizard');
        if (!$root.length) {
            return;
        }

        let selectedHandle = null;
        let sessionUid = null;
        let renderedLogCount = 0;
        let pollTimer = null;

        $root.on('click', '.s7-select-kit', function () {
            selectedHandle = $(this).closest('.s7-kit-card').data('handle');
            $root.find('[data-bind="selectedKitName"]').text(selectedHandle);
            showPanel($root, 'validate');
        });

        $root.on('click', '#s7-run-validation', function () {
            const dryRun = $root.find('#s7-dry-run').is(':checked');
            const $btn = $(this).prop('disabled', true);

            postJson(ENDPOINTS.validate, { handle: selectedHandle, dryRun: dryRun ? 1 : 0 })
                .then((response) => {
                    $btn.prop('disabled', false);
                    if (!response.success) {
                        Craft.cp.displayError(response.error || 'Validation failed.');
                        return;
                    }
                    sessionUid = response.sessionUid;
                    renderValidationResult($root.find('#s7-validation-results'), response.validationResult);
                    renderPlan($root.find('#s7-plan-preview'), response.plan);
                    showPanel($root, 'preview');
                    if (!response.validationResult.valid) {
                        $root.find('#s7-start-install').prop('disabled', true);
                    }
                })
                .catch(() => {
                    $btn.prop('disabled', false);
                    Craft.cp.displayError('Could not reach the server to validate this Starter Kit.');
                });
        });

        $root.on('click', '[data-back-to]', function () {
            showPanel($root, $(this).data('back-to'));
        });

        $root.on('click', '#s7-start-install', function () {
            $(this).prop('disabled', true);
            showPanel($root, 'execute');
            renderedLogCount = 0;
            $root.find('#s7-progress-log').empty();

            postJson(ENDPOINTS.execute, { sessionUid: sessionUid }).then((response) => {
                if (!response.success) {
                    Craft.cp.displayError('Could not queue the installation.');
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
                appendLogEntries($root.find('#s7-progress-log'), newEntries);
                renderedLogCount = response.progressLog.length;

                const percent = response.totalStages > 0 ? Math.round((response.stagesCompleted.length / response.totalStages) * 100) : 0;
                $root.find('#s7-progress-fill').css('width', percent + '%');

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
