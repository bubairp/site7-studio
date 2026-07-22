/**
 * Site7 Studio - Starter Kit wizard (Save Current Site as Starter Kit) and the
 * Install Starter Kit trigger on the package detail page.
 */
(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    const Site7StarterKitWizard = Garnish.Modal.extend({
        $entryList: null,
        $nameInput: null,
        $saveBtn: null,

        init: function() {
            const $container = $('<div class="modal cs-modal site7-starter-kit-wizard-modal" style="padding: 0; display: flex; flex-direction: column; overflow: hidden; opacity: 0;"></div>').appendTo($(document.body));

            const $header = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #e1e5ea); background: var(--bg-color, #fff);"></div>').appendTo($container);
            $header.append('<h2 class="h3" style="margin: 0;">Save Current Site as Starter Kit</h2>');
            const $closeBtn = $('<button type="button" class="btn" style="padding: 6px 12px;">Close</button>').appendTo($header);

            const $body = $('<div class="cs-content" style="padding: 24px; overflow-y: auto;"></div>').appendTo($container);

            const $form = $(`
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
                        <div class="input">
                            <div id="site7skw-entry-list" class="site7-starter-kit-entry-list">
                                <p class="light">Loading pages&hellip;</p>
                            </div>
                        </div>
                    </div>
                </div>
            `).appendTo($body);

            this.$nameInput = $form.find('#site7skw-name');
            this.$entryList = $form.find('#site7skw-entry-list');

            const $footer = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--border-color, #e1e5ea);"></div>').appendTo($container);
            this.$saveBtn = $('<button type="button" class="btn submit">Save Starter Kit</button>').appendTo($footer);

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
            this.$saveBtn.on('click', $.proxy(this, 'onSave'));

            this.loadEntries();
        },

        loadEntries: function() {
            const url = Craft.getActionUrl('site7-studio/starter-kit-generator/get-entries');
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(res => res.json())
                .then($.proxy(function(response) {
                    this.$entryList.empty();
                    const entries = (response && response.entries) || [];
                    if (!entries.length) {
                        this.$entryList.append('<p class="light">No eligible pages found.</p>');
                        return;
                    }
                    entries.forEach(function(entry) {
                        const $label = $('<label style="display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer;"></label>');
                        $('<input type="checkbox" name="entryIds">').val(entry.id).appendTo($label);
                        $label.append('<span>' + Craft.escapeHtml(entry.title) + '</span>');
                        if (entry.section) {
                            $label.append('<span class="light"> (' + Craft.escapeHtml(entry.section) + ')</span>');
                        }
                        this.$entryList.append($label);
                    }, this);
                }, this))
                .catch($.proxy(function() {
                    this.$entryList.empty().append('<p class="light">Could not load pages.</p>');
                }, this));
        },

        onSave: function() {
            const name = this.$nameInput.val().trim();
            if (!name) {
                Craft.cp.displayError('A Starter Kit name is required.');
                return;
            }

            const entryIds = this.$entryList.find('input[name="entryIds"]:checked').map(function() {
                return this.value;
            }).get();

            if (!entryIds.length) {
                Craft.cp.displayError('Choose at least one page to include.');
                return;
            }

            const formData = new FormData();
            formData.append('name', name);
            formData.append('description', $('#site7skw-description').val());
            formData.append('version', $('#site7skw-version').val());
            formData.append('author', $('#site7skw-author').val());
            formData.append('category', $('#site7skw-category').val());
            formData.append('tags', $('#site7skw-tags').val());
            entryIds.forEach(function(id) {
                formData.append('entryIds[]', id);
            });

            const fileInput = document.getElementById('site7skw-preview-image');
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
                        let message = 'Starter Kit saved: ' + response.handle;
                        if (response.skipped && response.skipped.length) {
                            message += ' (' + response.skipped.length + ' page(s) skipped - see log)';
                            console.warn('[Site7 Studio] Starter Kit pages skipped:', response.skipped);
                        }
                        Craft.cp.displayNotice(message);
                        this.hide();
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
                        let message = 'Installed ' + response.createdCount + ' page(s).';
                        if (response.skipped && response.skipped.length) {
                            message += ' ' + response.skipped.length + ' skipped - see console.';
                            console.warn('[Site7 Studio] Starter Kit install skipped:', response.skipped);
                        }
                        Craft.cp.displayNotice(message);
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
        bindInstallTrigger();
    });
})(jQuery);
