/**
 * Site7 Studio - Craft Resource Import & Package Generator wizard.
 * Drives the Select -> Analyze -> Preview -> Save flow for all three Library
 * "+ Import Existing ..." entry points (Section, Page, Website), following
 * the same Garnish.Modal shape as starter-kit-wizard.js/template-wizard.js.
 */
(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    const ENDPOINTS = {
        section: {
            listMatrixEntryTypes: 'site7-studio/resource-import/get-matrix-entry-types',
            listSections: 'site7-studio/resource-import/get-craft-sections',
            analyze: 'site7-studio/resource-import/analyze-section',
            save: 'site7-studio/resource-import/import-section',
        },
        page: {
            list: 'site7-studio/resource-import/get-pages',
            analyze: 'site7-studio/resource-import/analyze-page',
            save: 'site7-studio/resource-import/import-page',
        },
        website: {
            list: 'site7-studio/resource-import/get-website-resources',
            analyze: 'site7-studio/resource-import/analyze-website',
            save: 'site7-studio/resource-import/import-website',
        },
    };

    const TITLES = {
        section: 'Import Existing Section',
        page: 'Import Existing Page',
        website: 'Import Existing Website',
    };

    function postJson(action, params) {
        const body = new URLSearchParams();
        Object.keys(params || {}).forEach(function(key) {
            const value = params[key];
            if (Array.isArray(value)) {
                value.forEach(function(v) { body.append(key + '[]', v); });
            } else if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });
        return fetch(Craft.getActionUrl(action), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': Craft.csrfTokenValue },
        }).then(res => res.json());
    }

    // A Section and its own Entry Type are frequently named identically
    // (e.g. "Standard Pages / Standard Pages") - showing both back to back
    // reads as a duplicated label rather than useful context, so the second
    // part is only appended when it actually adds information.
    function formatPageMeta(section, entryType) {
        const parts = [];
        if (section) {
            parts.push(section);
        }
        if (entryType && entryType !== section) {
            parts.push(entryType);
        }
        return parts.join(' &middot; ');
    }

    function getJson(action) {
        return fetch(Craft.getActionUrl(action), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        }).then(res => res.json());
    }

    // Shared by the Page and Website pickers - both group their entries by
    // Section type (Single/Channel/Structure), the grouping a user actually
    // browses a real Craft site by, instead of one long flat list.
    const SECTION_TYPE_GROUPS = [
        { key: 'single', label: 'Singles' },
        { key: 'channel', label: 'Channels' },
        { key: 'structure', label: 'Structures' },
    ];

    // Builds a searchable accordion (grouped by Section type) inside
    // $container. `itemsByType` is {single/channel/structure: [...]}.
    // `renderRow(item)` returns the row's HTML (must include a
    // `data-search="..."` attribute on its root element for the search box
    // to filter against). Returns {$accordion, $search} - the caller reads
    // selections back out of $accordion directly (radio vs checkbox is the
    // caller's concern, not the accordion's).
    function buildSectionTypeAccordion($container, itemsByType, renderRow) {
        $container.append('<div class="input" style="margin-bottom:8px;"><input type="text" class="text fullwidth site7-accordion-search" placeholder="Search by title or section…"></div>');
        const $search = $container.find('.site7-accordion-search');

        // No max-height/overflow here - the wizard's $body is already the
        // one scroll container (see init()); a second scrolling region
        // nested inside it just produces a scrollbar-inside-a-scrollbar.
        const $accordion = $('<div class="site7-website-accordion" style="margin-bottom:8px; border:1px solid var(--hairline-color,#e1e5ea); border-radius:6px;"></div>').appendTo($container);

        const groupEls = SECTION_TYPE_GROUPS.map(function(group) {
            const items = itemsByType[group.key] || [];
            const $section = $('<div class="site7-accordion-section"></div>').appendTo($accordion);
            const $toggle = $(
                '<button type="button" class="site7-accordion-toggle" style="width:100%; text-align:left; display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:var(--gray-050,#f5f6f8); border:none; border-bottom:1px solid var(--hairline-color,#e1e5ea); cursor:pointer; font-weight:600;">' +
                '<span>' + Craft.escapeHtml(group.label) + ' &middot; ' + items.length + '</span>' +
                '<span class="site7-accordion-caret">&#9656;</span>' +
                '</button>'
            ).appendTo($section);
            const $body = $('<div class="site7-accordion-body" style="display:none; padding:4px 12px;"></div>').appendTo($section);

            items.forEach(function(item) {
                $body.append(renderRow(item));
            });
            if (!items.length) {
                $body.append('<p class="light" style="padding:4px 0;">No ' + Craft.escapeHtml(group.label).toLowerCase() + ' found.</p>');
            }

            $toggle.on('click', function() {
                const isOpen = $body.is(':visible');
                $body.slideToggle(120);
                $toggle.find('.site7-accordion-caret').html(isOpen ? '&#9656;' : '&#9662;');
            });

            return { group, $section, $toggle, $body };
        });

        // Open the first non-empty group by default so the picker isn't
        // fully collapsed on first render.
        const firstNonEmpty = groupEls.find(g => (itemsByType[g.group.key] || []).length);
        if (firstNonEmpty) {
            firstNonEmpty.$body.show();
            firstNonEmpty.$toggle.find('.site7-accordion-caret').html('&#9662;');
        }

        $search.on('input', function() {
            const term = $(this).val().trim().toLowerCase();
            groupEls.forEach(function(g) {
                let visibleCount = 0;
                g.$body.find('[data-search]').each(function() {
                    const match = !term || $(this).data('search').indexOf(term) !== -1;
                    $(this).toggle(match);
                    if (match) visibleCount++;
                });
                if (term) {
                    g.$body.show();
                    g.$toggle.find('.site7-accordion-caret').html('&#9662;');
                }
                g.$section.toggle(!term || visibleCount > 0);
            });
        });

        return { $accordion, $search };
    }

    // Injected once - keeps the wizard's markup free of one-off inline styles
    // for anything reused more than a couple of times (rows, groups, the
    // detail panel), while everything structural still uses Craft's own
    // classes/variables so dark mode and CP theming keep working for free.
    function ensureStyles() {
        if (document.getElementById('site7-import-wizard-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'site7-import-wizard-styles';
        style.textContent = `
            .site7-import-wizard-modal .cs-header h2 { display: flex; align-items: center; gap: 8px; }
            .site7-import-wizard-modal .cs-header .light { font-weight: normal; font-size: 13px; }
            .site7-discovery-groups { background: var(--gray-050, #f5f6f8); }
            .site7-discovery-group-heading {
                font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
                color: var(--medium-text-color, #596673); margin: 14px 0 6px; padding: 0 2px;
            }
            .site7-discovery-group-heading:first-child { margin-top: 2px; }
            .site7-discovery-row {
                display: flex; align-items: center; gap: 10px; padding: 8px 10px; cursor: pointer;
                border-radius: 6px; background: var(--white, #fff); border: 1px solid transparent;
                margin-bottom: 4px; transition: background-color .1s, border-color .1s;
            }
            .site7-discovery-row:hover { background: var(--gray-100, #eef1f6); }
            .site7-discovery-row.is-selected { border-color: var(--blue-300, #5e9de0); background: var(--blue-050, #eff6fd); }
            .site7-discovery-row input[type="radio"] { flex-shrink: 0; }
            .site7-discovery-row .site7-row-name { flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .site7-discovery-detail {
                border: 1px solid var(--hairline-color, #e1e5ea); border-radius: 6px; padding: 12px 14px;
                background: var(--gray-050, #f5f6f8);
            }
            .site7-discovery-detail > div { padding: 3px 0; }
            .site7-discovery-detail ul { margin: 4px 0 0 18px; }
            .site7-import-list label:hover { background: var(--gray-050, #f5f6f8); }
        `;
        document.head.appendChild(style);
    }

    const Site7ResourceImportWizard = Garnish.Modal.extend({
        type: null,
        $body: null,
        $footer: null,
        selection: null,
        lastAnalysis: null,

        init: function(type) {
            ensureStyles();

            this.type = type;
            this.selection = {};

            // A fixed height (rather than max-height on $body alone) so the
            // footer always pins to the bottom of the modal via flex, even
            // when a given step's content is short - previously the footer
            // sat directly under short content instead of at a consistent
            // position, which read as the footer "moving" between steps.
            const $container = $('<div class="modal cs-modal site7-import-wizard-modal" style="padding:0; display:flex; flex-direction:column; overflow:hidden; opacity:0; width:760px; max-width:90vw; height:70vh; border-radius:8px;"></div>').appendTo($(document.body));

            const $header = $('<div class="cs-header" style="flex:0 0 auto; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--hairline-color,#e1e5ea);"></div>').appendTo($container);
            $header.append('<h2 class="h3" style="margin:0;"><span data-icon="import" aria-hidden="true"></span>' + (TITLES[this.type] || 'Import Existing Resource') + '</h2>');
            const $closeBtn = $('<button type="button" class="btn" style="padding:6px 12px;">Close</button>').appendTo($header);

            this.$body = $('<div class="cs-content" style="flex:1 1 auto; min-height:0; padding:20px 24px; overflow-y:auto;"></div>').appendTo($container);

            // Fixed two-slot footer (left for Back, right for the primary
            // action) instead of one flex row every step re-fills by hand -
            // see setFooter(). Kept visually separated from $body with its
            // own shadow (rather than relying solely on the border) so it
            // still reads as a distinct action bar when $body's content is
            // short enough that there's no scroll to imply a boundary.
            this.$footer = $('<div class="cs-header" style="flex:0 0 auto; padding:14px 24px; display:flex; justify-content:space-between; align-items:center; gap:8px; border-top:1px solid var(--hairline-color,#e1e5ea); background:var(--gray-050,#f5f6f8); box-shadow:0 -2px 6px rgba(0,0,0,0.04); position:relative; z-index:1;"></div>').appendTo($container);
            this.$footerLeft = $('<div class="site7-footer-left"></div>').appendTo(this.$footer);
            this.$footerRight = $('<div class="site7-footer-right" style="display:flex; gap:8px;"></div>').appendTo(this.$footer);

            this.base($container, { resizable: false, autoShow: true, fade: true });

            this.on('hide', $.proxy(function() {
                setTimeout($.proxy(function() { this.destroy(); }, this), 300);
            }, this));

            $closeBtn.on('click', $.proxy(this, 'hide'));

            this.renderSelectStep();
        },

        // Replaces the footer's contents - `$left` (e.g. a Back button) is
        // pinned to the left edge, `$right` (the primary action, or several
        // buttons) to the right, so Back never visually crowds the primary
        // action the way two buttons both packed against the right edge did.
        setFooter: function($left, $right) {
            this.$footerLeft.empty();
            this.$footerRight.empty();
            if ($left) {
                this.$footerLeft.append($left);
            }
            if ($right) {
                this.$footerRight.append($right);
            }
        },

        // A centered spinner (Craft's own `.spinner` class - see
        // pattern-browser.js's loading state for the same convention)
        // instead of a plain "Loading…" line, plus an optional label under
        // it for longer operations (Analyzing, etc.) where a bare spinner
        // would leave the user unsure anything is scoped to their action.
        renderLoading: function(label) {
            this.$body.html(
                '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; padding:48px 0;">' +
                '<div class="spinner big"></div>' +
                (label ? '<p class="light">' + Craft.escapeHtml(label) + '</p>' : '') +
                '</div>'
            );
            this.setFooter(null, null);
        },

        // --- Step: Select ---

        renderSelectStep: function() {
            this.renderLoading();

            if (this.type === 'section') {
                getJson(ENDPOINTS.section.listMatrixEntryTypes).then($.proxy(function(discoveryRes) {
                    this.renderSectionSelect(discoveryRes);
                }, this));
            } else if (this.type === 'page') {
                getJson(ENDPOINTS.page.list).then($.proxy(function(res) {
                    this.renderPageSelect(res.pagesBySectionType || { single: [], channel: [], structure: [] });
                }, this));
            } else if (this.type === 'website') {
                getJson(ENDPOINTS.website.list).then($.proxy(function(res) {
                    this.renderWebsiteSelect(res.entriesBySectionType || { single: [], channel: [], structure: [] }, res.globalSets || []);
                }, this));
            }
        },

        // Phase 17: Matrix Entry Type source classification groups + display metadata.
        DISCOVERY_GROUPS: [
            { key: 'presentationSections', label: 'Presentation Sections', color: 'green' },
            { key: 'featureComponents', label: 'Feature Components', color: 'blue' },
            { key: 'sharedResources', label: 'Shared Resources', color: 'teal' },
            { key: 'utilities', label: 'Utilities', color: 'gray' },
            { key: 'pluginComponents', label: 'Plugin Components', color: 'orange' },
            { key: 'unknown', label: 'Unknown', color: 'red' },
        ],

        // Every Entry Type across every classification group, flattened once
        // per render so the Matrix-field filter can re-render rows without a
        // second server round-trip.
        renderSectionSelect: function(discovery) {
            const $form = $('<div></div>').appendTo(this.$body.empty());

            const $entryTypeField = $('<div class="field" id="site7ri-entrytype-field"></div>').appendTo($form);
            $entryTypeField.append('<div class="heading"><label>Entry Type</label> <span class="light">discovered &amp; classified from the live Craft project</span></div>');

            const matrixFields = discovery.matrixFields || [];
            let $filterSelect = null;
            if (matrixFields.length > 1) {
                const $filterRow = $('<div class="input" style="margin-bottom:8px;"></div>').appendTo($entryTypeField);
                $filterRow.append('<label for="site7ri-matrixfield-filter" class="light" style="margin-right:6px;">Matrix field:</label>');
                $filterSelect = $('<select id="site7ri-matrixfield-filter"><option value="">All Matrix Fields</option></select>').appendTo($filterRow);
                matrixFields.forEach(function(mf) {
                    $filterSelect.append('<option value="' + Craft.escapeHtml(mf.handle) + '">' + Craft.escapeHtml(mf.name) + '</option>');
                });
            }

            // No max-height/overflow here - this.$body is already the
            // wizard's one scroll container; a second scrolling region
            // nested inside it just produces a scrollbar-inside-a-scrollbar.
            const $groupsContainer = $('<div class="site7-discovery-groups" style="border:1px solid var(--hairline-color,#e1e5ea); border-radius:6px; padding:10px;"></div>').appendTo($entryTypeField);

            // Detached until a row is selected, then moved (via
            // insertAfter) to sit directly under whichever row is
            // currently selected - not fixed after the whole group list,
            // which read as the detail belonging to the last item instead
            // of the one actually picked.
            const $detailPanel = $('<div class="site7-discovery-detail light" style="margin-top:8px; margin-bottom:8px;"></div>');

            let selectedEntryTypeId = null;
            let $selectedRow = null;

            const renderRows = $.proxy(function(matrixFieldFilter) {
                $detailPanel.detach();
                $groupsContainer.empty();
                $selectedRow = null;
                let anyVisible = false;

                this.DISCOVERY_GROUPS.forEach($.proxy(function(group) {
                    const items = (discovery[group.key] || []).filter(function(item) {
                        return !matrixFieldFilter || (item.referencedBy || []).some(r => r.handle === matrixFieldFilter);
                    });
                    if (!items.length) {
                        return;
                    }
                    anyVisible = true;
                    $groupsContainer.append('<div class="site7-discovery-group-heading">' + Craft.escapeHtml(group.label) + ' &middot; ' + items.length + '</div>');
                    items.forEach($.proxy(function(item) {
                        const reviewBadge = item.reviewRequired ? ' <span class="status-label orange" style="flex-shrink:0;">Review Required</span>' : '';
                        const warningBadge = item.warnings && item.warnings.length ? ' <span class="light" style="flex-shrink:0;">' + item.warnings.length + ' note' + (item.warnings.length === 1 ? '' : 's') + '</span>' : '';
                        const $row = $(
                            '<label class="site7-discovery-row">' +
                            '<input type="radio" name="site7ri-entrytype-radio" value="' + item.id + '">' +
                            '<span class="status-label ' + group.color + '" style="flex-shrink:0;">' + Craft.escapeHtml(group.label) + '</span>' +
                            '<span class="site7-row-name">' + Craft.escapeHtml(item.name) + '</span>' +
                            '<span class="light" style="flex-shrink:0;">used in ' + item.usageCount + '</span>' +
                            warningBadge + reviewBadge +
                            '</label>'
                        ).appendTo($groupsContainer);
                        if (item.id === selectedEntryTypeId) {
                            $row.find('input').prop('checked', true);
                            $row.addClass('is-selected');
                            $selectedRow = $row;
                            $detailPanel.insertAfter($row);
                        }
                        $row.find('input').on('change', $.proxy(function() {
                            if ($selectedRow) {
                                $selectedRow.removeClass('is-selected');
                            }
                            $row.addClass('is-selected');
                            $selectedRow = $row;
                            selectedEntryTypeId = item.id;
                            $detailPanel.insertAfter($row);
                            this.loadEntryTypeDetail(item.id, $detailPanel);
                        }, this));
                    }, this));
                }, this));

                if (!anyVisible) {
                    $groupsContainer.append('<p class="light" style="padding:8px;">No Entry Types found for this filter.</p>');
                }
            }, this);

            renderRows('');
            if ($filterSelect) {
                $filterSelect.on('change', function() {
                    renderRows($(this).val());
                });
            }

            const $analyzeBtn = $('<button type="button" class="btn submit">Analyze</button>');
            this.setFooter(null, $analyzeBtn);
            $analyzeBtn.on('click', $.proxy(function() {
                if (!selectedEntryTypeId) {
                    Craft.cp.displayError('Select an Entry Type.');
                    return;
                }
                this.selection = { sourceKind: 'matrix-entry-type', entryTypeId: selectedEntryTypeId };
                this.analyze(ENDPOINTS.section.analyze, this.selection);
            }, this));
        },

        // Phase 17: fetches and renders the Resource Detail view for a single
        // Matrix Entry Type (Classification, Craft Resource Type, Referenced
        // By, Dependencies, Plugin Requirements, Shared Resources, Estimated
        // Package Size, Potential Issues) into the given panel.
        loadEntryTypeDetail: function(entryTypeId, panel) {
            panel.html('<em class="light">Loading details&hellip;</em>');

            fetch(Craft.getActionUrl('site7-studio/resource-import/entry-type-detail?entryTypeId=' + encodeURIComponent(entryTypeId)), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            }).then(res => res.json()).then(function(res) {
                if (!res.success) {
                    panel.html('<span class="error">' + Craft.escapeHtml(res.error || 'Could not load details.') + '</span>');
                    return;
                }
                const d = res.detail;
                let html = '';
                html += '<div><strong>Classification:</strong> ' + Craft.escapeHtml(d.classification) + (d.reviewRequired ? ' <span class="status-label orange">Review Required</span>' : '') + ' <span class="light">(' + d.confidence + '% confidence)</span></div>';
                html += '<div><strong>Craft Resource Type:</strong> ' + Craft.escapeHtml(d.craftResourceType) + '</div>';
                html += '<div><strong>Referenced By:</strong> ' + (d.referencedBy.length ? d.referencedBy.map(r => Craft.escapeHtml(r.name)).join(', ') : '&mdash;') + '</div>';
                html += '<div><strong>Recommendation:</strong> ' + Craft.escapeHtml(d.recommendation) + '</div>';
                html += '<div><strong>Estimated Package Size:</strong> ~' + Math.max(1, Math.round(d.estimatedPackageSize / 1024)) + ' KB</div>';
                if (d.pluginRequirements.length) {
                    html += '<div><strong>Plugin Requirements:</strong> ' + d.pluginRequirements.map(p => Craft.escapeHtml(p.requiredPlugin)).join(', ') + '</div>';
                }
                if (d.sharedResources.length) {
                    html += '<div><strong>Shared Resources:</strong> ' + d.sharedResources.map(Craft.escapeHtml).join(', ') + '</div>';
                }
                if (d.warnings.length) {
                    html += '<div style="margin-top:6px;"><strong>Potential Issues:</strong><ul>';
                    d.warnings.forEach(function(w) { html += '<li>' + Craft.escapeHtml(w) + '</li>'; });
                    html += '</ul></div>';
                }
                panel.html(html);
            }).catch(function() {
                panel.html('<span class="error">Error loading details.</span>');
            });
        },

        renderPageSelect: function(pagesBySectionType) {
            const $container = this.$body.empty();
            $container.append('<div class="heading" style="margin-bottom:4px;"><label>Pages</label></div>');

            const { $accordion } = buildSectionTypeAccordion($container, pagesBySectionType, function(p) {
                const badge = p.hasSite7Content ? ' <span class="chip small" style="background:var(--teal-050);">Site7 content</span>' : '';
                const meta = formatPageMeta(Craft.escapeHtml(p.section || ''), Craft.escapeHtml(p.entryType || ''));
                return (
                    '<label data-search="' + Craft.escapeHtml((p.title + ' ' + (p.section || '') + ' ' + (p.entryType || '')).toLowerCase()) + '" style="display:flex; align-items:center; gap:10px; padding:4px 0;">' +
                    '<input type="radio" name="site7ri-page" value="' + p.id + '">' +
                    '<span style="flex-grow:1;">' + Craft.escapeHtml(p.title) + '</span>' +
                    (meta ? '<span class="light" style="flex-shrink:0;">' + meta + '</span>' : '') +
                    badge + '</label>'
                );
            });

            const $analyzeBtn = $('<button type="button" class="btn submit">Analyze</button>');
            this.setFooter(null, $analyzeBtn);
            $analyzeBtn.on('click', $.proxy(function() {
                const entryId = $accordion.find('input[name="site7ri-page"]:checked').val();
                if (!entryId) {
                    Craft.cp.displayError('Select a page.');
                    return;
                }
                this.selection = { entryId };
                this.analyze(ENDPOINTS.page.analyze, this.selection);
            }, this));
        },

        renderWebsiteSelect: function(entriesBySectionType, globalSets) {
            const $container = this.$body.empty();
            $container.append('<div class="heading" style="margin-bottom:4px;"><label>Pages</label></div>');

            const { $accordion } = buildSectionTypeAccordion($container, entriesBySectionType, function(e) {
                const meta = e.section ? Craft.escapeHtml(e.section) : '';
                return (
                    '<label class="site7-website-row" data-search="' + Craft.escapeHtml((e.title + ' ' + (e.section || '')).toLowerCase()) + '" style="display:flex; align-items:center; gap:10px; padding:4px 0;">' +
                    '<input type="checkbox" name="site7ri-entry" value="' + e.id + '">' +
                    '<span style="flex-grow:1;">' + Craft.escapeHtml(e.title) + '</span>' +
                    (meta ? '<span class="light" style="flex-shrink:0;">' + meta + '</span>' : '') +
                    '</label>'
                );
            });

            $container.append('<div class="heading" style="margin-bottom:4px;">Global Sets (used to approximate Navigation)</div>');
            const $globalList = $('<div class="site7-import-list"></div>').appendTo($container);
            globalSets.forEach(function(g) {
                const navHint = g.likelyNav ? ' <span class="light">(looks like Navigation)</span>' : '';
                $globalList.append('<label style="display:flex; align-items:center; gap:8px; padding:4px 0;"><input type="checkbox" name="site7ri-global" value="' + g.id + '"> <span>' + Craft.escapeHtml(g.name) + '</span>' + navHint + '</label>');
            });

            const $analyzeBtn = $('<button type="button" class="btn submit">Analyze</button>');
            this.setFooter(null, $analyzeBtn);
            $analyzeBtn.on('click', $.proxy(function() {
                const entryIds = $accordion.find('input[name="site7ri-entry"]:checked').map(function() { return this.value; }).get();
                const globalSetIds = $globalList.find('input[name="site7ri-global"]:checked').map(function() { return this.value; }).get();
                if (!entryIds.length) {
                    Craft.cp.displayError('Select at least one page.');
                    return;
                }
                this.selection = { entryIds, globalSetIds };
                this.analyze(ENDPOINTS.website.analyze, this.selection);
            }, this));
        },

        // --- Step: Analyze -> Preview ---

        analyze: function(action, params) {
            this.renderLoading('Analyzing…');

            postJson(action, params).then($.proxy(function(res) {
                if (!res.success) {
                    Craft.cp.displayError(res.error || 'Could not analyze this resource.');
                    this.renderSelectStep();
                    return;
                }
                this.lastAnalysis = res.analysis;
                this.renderPreviewStep(res.analysis);
            }, this)).catch($.proxy(function() {
                Craft.cp.displayError('Error analyzing this resource.');
                this.renderSelectStep();
            }, this));
        },

        renderPreviewStep: function(analysis) {
            const $container = this.$body.empty();

            const $meta = $(`
                <div style="margin-bottom:16px;">
                    <div class="field" style="margin-bottom:12px;">
                        <div class="heading"><label for="site7ri-name">Name</label></div>
                        <div class="input"><input type="text" id="site7ri-name" class="text fullwidth"></div>
                    </div>
                    <div class="field" style="margin-bottom:12px;">
                        <div class="heading"><label for="site7ri-description">Description</label></div>
                        <div class="input"><textarea id="site7ri-description" class="text fullwidth" rows="2"></textarea></div>
                    </div>
                    <div class="flex flex-gap-m">
                        <div class="field flex-grow"><div class="heading"><label for="site7ri-category">Category</label></div><div class="input"><input type="text" id="site7ri-category" class="text fullwidth"></div></div>
                        <div class="field flex-grow"><div class="heading"><label for="site7ri-tags">Tags</label></div><div class="input"><input type="text" id="site7ri-tags" class="text fullwidth" placeholder="Comma-separated"></div></div>
                        <div class="field" style="width:120px;"><div class="heading"><label for="site7ri-version">Version</label></div><div class="input"><input type="text" id="site7ri-version" class="text fullwidth" value="1.0.0"></div></div>
                    </div>
                </div>
            `).appendTo($container);
            $meta.find('#site7ri-name').val(analysis.sourceLabel || '');

            if (analysis.detectedFields && analysis.detectedFields.length) {
                const CLASSIFICATION_COLORS = {
                    'feature-resource': 'green',
                    'feature-dependency': 'green',
                    'nested-resource': 'green',
                    'reusable-component': 'teal',
                    'shared-resource': 'blue',
                    'package-resource': 'blue',
                    'platform-configuration': 'gray',
                    'plugin-dependency': 'orange',
                    'external-dependency': 'orange',
                    'review-required': 'red',
                    'unknown-resource': 'red',
                };
                const $fields = $('<div style="margin-bottom:16px;"><strong>Detected Fields</strong></div>').appendTo($container);
                const $table = $('<table class="data fullwidth"><thead><tr><th>Field</th><th>Type</th><th>Status</th></tr></thead><tbody></tbody></table>').appendTo($fields);
                const $tbody = $table.find('tbody');
                analysis.detectedFields.forEach(function(f) {
                    const color = CLASSIFICATION_COLORS[f.classification] || 'gray';
                    const label = f.statusLabel || (f.supported ? 'Native Resource' : 'Review Required');
                    const status = '<span class="status-label ' + color + '" title="' + Craft.escapeHtml(f.detail || '') + '">' + Craft.escapeHtml(label) + '</span>';
                    $tbody.append('<tr><td>' + Craft.escapeHtml(f.handle) + '</td><td>' + Craft.escapeHtml(f.type) + '</td><td>' + status + '</td></tr>');
                });
            }

            if (analysis.detectedDependencies && analysis.detectedDependencies.length) {
                const $deps = $('<div style="margin-bottom:16px;"><strong>Dependencies</strong></div>').appendTo($container);
                analysis.detectedDependencies.forEach(function(d) {
                    $deps.append('<div class="light">' + Craft.escapeHtml(d.kind) + ' \'' + Craft.escapeHtml(d.handle) + '\' - ' + Craft.escapeHtml(d.status) + '</div>');
                });
            }

            if (analysis.errors && analysis.errors.length) {
                const $errors = $('<div class="site7-notice site7-notice--error" style="margin-bottom:12px;"></div>').appendTo($container);
                analysis.errors.forEach(function(e) { $errors.append('<div>' + Craft.escapeHtml(e) + '</div>'); });
            }
            if (analysis.warnings && analysis.warnings.length) {
                const $warnings = $('<div class="site7-notice site7-notice--warning" style="margin-bottom:12px;"></div>').appendTo($container);
                analysis.warnings.forEach(function(w) { $warnings.append('<div>' + Craft.escapeHtml(w) + '</div>'); });
            }

            const sizeKb = Math.max(1, Math.round((analysis.packageSizeEstimate || 0) / 1024));
            $container.append('<p class="light">Estimated package size: ~' + sizeKb + ' KB</p>');

            const $backBtn = $('<button type="button" class="btn">Back</button>');
            const $saveBtn = $('<button type="button" class="btn submit">Save Package</button>');
            if (!analysis.valid) {
                $saveBtn.prop('disabled', true);
            }
            this.setFooter($backBtn, $saveBtn);

            $backBtn.on('click', $.proxy(this, 'renderSelectStep'));
            $saveBtn.on('click', $.proxy(function() { this.save($meta); }, this));
        },

        // --- Step: Save ---

        save: function($meta) {
            const meta = {
                name: $meta.find('#site7ri-name').val().trim(),
                description: $meta.find('#site7ri-description').val(),
                category: $meta.find('#site7ri-category').val(),
                tags: $meta.find('#site7ri-tags').val(),
                version: $meta.find('#site7ri-version').val() || '1.0.0',
            };
            if (!meta.name) {
                Craft.cp.displayError('A name is required.');
                return;
            }

            const saveAction = this.type === 'section' ? ENDPOINTS.section.save
                : this.type === 'page' ? ENDPOINTS.page.save
                : ENDPOINTS.website.save;

            this.$footer.find('button').prop('disabled', true).addClass('loading');

            postJson(saveAction, Object.assign({}, this.selection, meta)).then($.proxy(function(res) {
                this.$footer.find('button').prop('disabled', false).removeClass('loading');
                if (res.success) {
                    Craft.cp.displayNotice('Package imported successfully.');
                    if (res.skipped && res.skipped.length) {
                        console.warn('[Site7 Studio] Import skipped items:', res.skipped);
                    }
                    if (res.notes && res.notes.length) {
                        console.info('[Site7 Studio] Import notes:', res.notes);
                    }
                    this.hide();
                    window.location.reload();
                } else {
                    Craft.cp.displayError(res.error || 'Could not save package.');
                }
            }, this)).catch($.proxy(function() {
                this.$footer.find('button').prop('disabled', false).removeClass('loading');
                Craft.cp.displayError('Error saving package.');
            }, this));
        },
    });

    function bindTriggers() {
        document.querySelectorAll('.site7-import-trigger').forEach(function(btn) {
            btn.addEventListener('click', function() {
                new Site7ResourceImportWizard(btn.getAttribute('data-import-type'));
            });
        });
    }

    window.Site7ResourceImportWizard = Site7ResourceImportWizard;

    $(function() {
        bindTriggers();
    });
})(jQuery);
