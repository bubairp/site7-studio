/**
 * Site7 Studio - Starter Kit wizard (Save Current Site as Starter Kit / Update
 * Starter Kit) and the Install Starter Kit trigger on the package detail page.
 */
(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    const Site7StarterKitWizard = Garnish.Modal.extend({
        $entryList: null,
        $nameInput: null,
        $saveBtn: null,
        $resultsPanel: null,
        $formPanel: null,
        updateHandle: null,
        tree: null,

        /**
         * @param {Object} [options] When options.handle is given, the wizard opens in
         *   "Update Starter Kit" mode: the page tree pre-checks whatever pages the
         *   target Starter Kit's manifest already lists (see
         *   WebsiteTreeService::resolvePageUidsFromManifest()), the form is
         *   pre-filled from options, and saving overwrites that same package handle
         *   instead of minting a new one.
         */
        init: function(options) {
            options = options || {};
            this.updateHandle = options.handle || null;
            const isUpdate = !!this.updateHandle;

            const $container = $('<div class="modal cs-modal site7-starter-kit-wizard-modal" style="padding: 0; display: flex; flex-direction: column; overflow: hidden; opacity: 0;"></div>').appendTo($(document.body));

            const $header = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #e1e5ea); background: var(--bg-color, #fff);"></div>').appendTo($container);
            $header.append('<h2 class="h3" style="margin: 0;">' + (isUpdate ? 'Update Starter Kit' : 'Save Current Site as Starter Kit') + '</h2>');
            const $closeBtn = $('<button type="button" class="btn" style="padding: 6px 12px;">Close</button>').appendTo($header);

            const $body = $('<div class="cs-content" style="padding: 24px; overflow-y: auto;"></div>').appendTo($container);

            this.$formPanel = $(`
                <div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7skw-name">Starter Kit Name</label></div>
                        <div class="input"><input type="text" id="site7skw-name" class="text fullwidth" required></div>
                    </div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7skw-description">Description</label></div>
                        <div class="input"><textarea id="site7skw-description" class="text fullwidth" rows="3"></textarea></div>
                    </div>
                    <div class="flex flex-gap-m" style="margin-bottom: 16px;">
                        <div class="field flex-grow">
                            <div class="heading"><label for="site7skw-version">Version</label></div>
                            <div class="input"><input type="text" id="site7skw-version" class="text fullwidth" value="1.0.0"></div>
                        </div>
                        <div class="field flex-grow">
                            <div class="heading"><label for="site7skw-author">Author</label></div>
                            <div class="input"><input type="text" id="site7skw-author" class="text fullwidth"></div>
                        </div>
                    </div>
                    <div class="flex flex-gap-m" style="margin-bottom: 16px;">
                        <div class="field flex-grow">
                            <div class="heading"><label for="site7skw-category">Category</label></div>
                            <div class="input"><input type="text" id="site7skw-category" class="text fullwidth"></div>
                        </div>
                        <div class="field flex-grow">
                            <div class="heading"><label for="site7skw-tags">Tags</label></div>
                            <div class="input"><input type="text" id="site7skw-tags" class="text fullwidth" placeholder="Comma-separated"></div>
                        </div>
                    </div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7skw-preview-image">Preview Image (optional)</label></div>
                        <div class="input"><input type="file" id="site7skw-preview-image" accept="image/*"></div>
                    </div>
                    <div class="field" style="margin-bottom: 0;">
                        <div class="heading"><label>Pages to Include</label></div>
                        <p class="light" style="margin: 0 0 6px;">Header/Footer/General-style content is captured automatically and doesn't need to be selected here.</p>
                        <div class="input">
                            <div id="site7skw-entry-list" class="site7-starter-kit-entry-list">
                                <p class="light">Loading pages&hellip;</p>
                            </div>
                        </div>
                    </div>
                </div>
            `).appendTo($body);

            this.$resultsPanel = $('<div style="display: none;"></div>').appendTo($body);

            this.$nameInput = this.$formPanel.find('#site7skw-name');
            this.$entryList = this.$formPanel.find('#site7skw-entry-list');

            if (isUpdate) {
                this.$nameInput.val(options.name || '');
                this.$formPanel.find('#site7skw-description').val(options.description || '');
                this.$formPanel.find('#site7skw-version').val(options.version || '1.0.0');
                this.$formPanel.find('#site7skw-author').val(options.author || '');
                this.$formPanel.find('#site7skw-category').val(options.category || '');
                this.$formPanel.find('#site7skw-tags').val(options.tags || '');
            }

            const $footer = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--border-color, #e1e5ea);"></div>').appendTo($container);
            this.$saveBtn = $('<button type="button" class="btn submit">' + (isUpdate ? 'Update Starter Kit' : 'Save Starter Kit') + '</button>').appendTo($footer);
            this.$doneBtn = $('<button type="button" class="btn" style="display: none;">Done</button>').appendTo($footer);

            this.base($container, {
                resizable: false,
                autoShow: true,
                fade: true
            });

            this.on('hide', $.proxy(function() {
                setTimeout($.proxy(function() {
                    this.destroy();
                }, this), 300);
            }, this));

            $closeBtn.on('click', $.proxy(this, 'hide'));
            this.$doneBtn.on('click', $.proxy(this, 'hide'));
            this.$saveBtn.on('click', $.proxy(this, 'onSave'));

            this.loadEntries();
        },

        /**
         * Renders the same reusable Website Structure tree the Package Editor's
         * read-only Starter Kit view and "Import Existing Website" already use
         * (Site7WebsiteTree, backed by WebsiteTreeService::buildTree()) instead of a
         * flat, ungrouped checklist - grouped by Section, with a cascading
         * Section-level checkbox, search box, and (in Update mode) whichever pages
         * the target Starter Kit's manifest already lists pre-checked via
         * entry.included (see WebsiteTreeService::markIncluded()/
         * resolvePageUidsFromManifest()).
         */
        loadEntries: function() {
            let url = Craft.getActionUrl('site7-studio/starter-kit-generator/get-entries');
            if (this.updateHandle) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'handle=' + encodeURIComponent(this.updateHandle);
            }
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(res => res.json())
                .then($.proxy(function(response) {
                    this.$entryList.empty();
                    const tree = response && response.tree;
                    const hasPages = tree && (
                        (tree.channels || []).some(s => (s.entries || []).length) ||
                        (tree.structures || []).some(s => (s.entries || []).length)
                    );
                    if (!hasPages) {
                        this.$entryList.append('<p class="light">No pages found.</p>');
                        return;
                    }
                    if (typeof Site7WebsiteTree === 'undefined') {
                        this.$entryList.append('<p class="light">Could not load the page picker.</p>');
                        return;
                    }
                    this.tree = Site7WebsiteTree.render(this.$entryList, tree, {
                        selectionMode: 'grouped-multiple',
                        // Show the "Imported"/"Open Package" info (a page already
                        // captured into a Page/Template package elsewhere is useful
                        // context - selecting it here reuses that same package, see
                        // StarterKitGeneratorService::findExistingTemplateHandle())
                        // but deliberately WITHOUT lockImported - unlike "Import
                        // Existing Page/Website", an already-imported page must
                        // remain fully selectable here, never disabled.
                        showImportStatus: true
                    });
                }, this))
                .catch($.proxy(function() {
                    this.$entryList.empty().append('<p class="light">Could not load pages.</p>');
                }, this));
        },

        /**
         * Replaces the form with a visible summary of what was captured/updated and
         * what was skipped (and why) - this used to only be reachable via
         * console.warn, so a non-technical user had no way to see it.
         */
        showResults: function(heading, skipped, extraLines) {
            this.$formPanel.hide();
            this.$saveBtn.hide();
            this.$doneBtn.show();

            const $panel = this.$resultsPanel.empty().show();
            $panel.append('<p>' + Craft.escapeHtml(heading) + '</p>');

            (extraLines || []).forEach(function(line) {
                $panel.append('<p class="light">' + Craft.escapeHtml(line) + '</p>');
            });

            if (skipped && skipped.length) {
                $panel.append('<p><strong>' + skipped.length + ' page(s) skipped:</strong></p>');
                const $list = $('<ul style="margin: 0 0 0 20px;"></ul>').appendTo($panel);
                skipped.forEach(function(reason) {
                    $list.append('<li class="light">' + Craft.escapeHtml(reason) + '</li>');
                });
            }
        },

        onSave: function() {
            const name = this.$nameInput.val().trim();
            if (!name) {
                Craft.cp.displayError('A Starter Kit name is required.');
                return;
            }

            const entryIds = (this.tree && this.tree.selectedIds) || [];

            if (!entryIds.length) {
                Craft.cp.displayError('Choose at least one page to include.');
                return;
            }

            const formData = new FormData();
            formData.append('name', name);
            formData.append('description', this.$formPanel.find('#site7skw-description').val());
            formData.append('version', this.$formPanel.find('#site7skw-version').val());
            formData.append('author', this.$formPanel.find('#site7skw-author').val());
            formData.append('category', this.$formPanel.find('#site7skw-category').val());
            formData.append('tags', this.$formPanel.find('#site7skw-tags').val());
            if (this.updateHandle) {
                formData.append('handle', this.updateHandle);
            }
            entryIds.forEach(function(id) {
                formData.append('entryIds[]', id);
            });

            const fileInput = this.$formPanel.find('#site7skw-preview-image')[0];
            if (fileInput && fileInput.files && fileInput.files[0]) {
                formData.append('previewImage', fileInput.files[0]);
            }

            this.$saveBtn.addClass('loading').prop('disabled', true);

            const url = Craft.getActionUrl('site7-studio/starter-kit-generator/save-as-starter-kit');
            fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue
                }
            })
                .then(res => res.json())
                .then($.proxy(function(response) {
                    this.$saveBtn.removeClass('loading').prop('disabled', false);
                    if (response.success) {
                        const heading = (this.updateHandle ? 'Starter Kit updated: ' : 'Starter Kit saved: ') + response.handle;
                        if (response.skipped && response.skipped.length) {
                            console.warn('[Site7 Studio] Starter Kit pages skipped:', response.skipped);
                            this.showResults(heading, response.skipped);
                        } else {
                            Craft.cp.displayNotice(heading);
                            this.hide();
                        }
                    } else {
                        Craft.cp.displayError(response.error || 'Could not save Starter Kit.');
                    }
                }, this))
                .catch($.proxy(function() {
                    this.$saveBtn.removeClass('loading').prop('disabled', false);
                    Craft.cp.displayError('Error saving Starter Kit.');
                }, this));
        }
    });

    function bindSaveTrigger() {
        const btn = document.getElementById('site7-save-as-starter-kit-btn');
        if (btn) {
            btn.addEventListener('click', function() {
                new Site7StarterKitWizard();
            });
        }
    }

    function bindUpdateTrigger() {
        const btn = document.getElementById('site7-update-starter-kit-btn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function() {
            new Site7StarterKitWizard({
                handle: btn.getAttribute('data-handle'),
                name: btn.getAttribute('data-name') || '',
                description: btn.getAttribute('data-description') || '',
                version: btn.getAttribute('data-version') || '1.0.0',
                author: btn.getAttribute('data-author') || '',
                category: btn.getAttribute('data-category') || '',
                tags: btn.getAttribute('data-tags') || ''
            });
        });
    }

    function bindInstallTrigger() {
        const btn = document.getElementById('site7-install-starter-kit-btn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function() {
            if (!confirm('Install this Starter Kit? This will create new pages in the current project.')) {
                return;
            }
            btn.classList.add('loading');
            btn.disabled = true;

            const url = Craft.getActionUrl('site7-studio/starter-kit-generator/install');
            const body = new URLSearchParams();
            body.append('handle', btn.getAttribute('data-handle'));

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue
                }
            })
                .then(res => res.json())
                .then(function(response) {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                    if (response.success) {
                        const parts = ['Installed ' + response.createdCount + ' page(s).'];
                        if (response.installedSiteStructure && response.installedSiteStructure.length) {
                            parts.push(response.installedSiteStructure.length + ' site structure section(s) updated (' + response.installedSiteStructure.join(', ') + ').');
                        }
                        if (response.installedGlobals && response.installedGlobals.length) {
                            parts.push(response.installedGlobals.length + ' global(s) updated.');
                        }
                        if (response.skipped && response.skipped.length) {
                            parts.push(response.skipped.length + ' item(s) skipped - see console.');
                            console.warn('[Site7 Studio] Starter Kit install skipped:', response.skipped);
                        }
                        Craft.cp.displayNotice(parts.join(' '));
                    } else {
                        Craft.cp.displayError(response.error || 'Could not install Starter Kit.');
                    }
                })
                .catch(function() {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                    Craft.cp.displayError('Error installing Starter Kit.');
                });
        });
    }

    window.Site7StarterKitWizard = Site7StarterKitWizard;

    $(function() {
        bindSaveTrigger();
        bindUpdateTrigger();
        bindInstallTrigger();
    });
})(jQuery);
