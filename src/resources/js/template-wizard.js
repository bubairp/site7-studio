/**
 * Site7 Studio - Save as Template wizard
 */
(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    const Site7TemplateWizard = Garnish.Modal.extend({
        $form: null,
        $saveBtn: null,
        entryId: null,

        init: function(entryId) {
            this.entryId = entryId;

            const $container = $('<div class="modal cs-modal site7-template-wizard-modal" style="padding: 0; display: flex; flex-direction: column; overflow: hidden; opacity: 0;"></div>').appendTo($(document.body));

            const $header = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #e1e5ea); background: var(--bg-color, #fff);"></div>').appendTo($container);
            $header.append('<h2 class="h3" style="margin: 0;">Save as Template</h2>');
            const $closeBtn = $('<button type="button" class="btn" style="padding: 6px 12px;">Close</button>').appendTo($header);

            const $body = $('<div class="cs-content" style="padding: 24px; overflow-y: auto;"></div>').appendTo($container);

            this.$form = $(`
                <div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7tw-name">Template Name</label></div>
                        <div class="input"><input type="text" id="site7tw-name" class="text fullwidth" required></div>
                    </div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7tw-description">Description</label></div>
                        <div class="input"><textarea id="site7tw-description" class="text fullwidth" rows="3"></textarea></div>
                    </div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7tw-category">Category</label></div>
                        <div class="input"><input type="text" id="site7tw-category" class="text fullwidth"></div>
                    </div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7tw-tags">Tags</label></div>
                        <div class="input"><input type="text" id="site7tw-tags" class="text fullwidth" placeholder="Comma-separated"></div>
                    </div>
                    <div class="field" style="margin-bottom: 0;">
                        <div class="heading"><label for="site7tw-preview-image">Preview Image (optional)</label></div>
                        <div class="input"><input type="file" id="site7tw-preview-image" accept="image/*"></div>
                    </div>
                </div>
            `).appendTo($body);

            const $footer = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--border-color, #e1e5ea);"></div>').appendTo($container);
            this.$saveBtn = $('<button type="button" class="btn submit">Save Template</button>').appendTo($footer);

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
        },

        onSave: function() {
            const name = $('#site7tw-name').val().trim();
            if (!name) {
                Craft.cp.displayError('A Template name is required.');
                return;
            }

            const formData = new FormData();
            formData.append('entryId', this.entryId);
            formData.append('name', name);
            formData.append('description', $('#site7tw-description').val());
            formData.append('category', $('#site7tw-category').val());
            formData.append('tags', $('#site7tw-tags').val());

            const fileInput = document.getElementById('site7tw-preview-image');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                formData.append('previewImage', fileInput.files[0]);
            }

            this.$saveBtn.addClass('loading').prop('disabled', true);

            const url = Craft.getActionUrl('site7-studio/template-generator/save-as-template');
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
                        Craft.cp.displayNotice('Template saved: ' + response.handle);
                        this.hide();
                    } else {
                        Craft.cp.displayError(response.error || 'Could not save Template.');
                    }
                }, this))
                .catch($.proxy(function() {
                    this.$saveBtn.removeClass('loading').prop('disabled', false);
                    Craft.cp.displayError('Error saving Template.');
                }, this));
        }
    });

    const Site7CreateFromTemplateWizard = Garnish.Modal.extend({
        $entryTypeSelect: null,
        $titleInput: null,
        $slugInput: null,
        $createBtn: null,
        templateHandle: null,

        init: function(templateHandle) {
            this.templateHandle = templateHandle;

            const $container = $('<div class="modal cs-modal site7-template-wizard-modal" style="padding: 0; display: flex; flex-direction: column; overflow: hidden; opacity: 0;"></div>').appendTo($(document.body));

            const $header = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #e1e5ea); background: var(--bg-color, #fff);"></div>').appendTo($container);
            $header.append('<h2 class="h3" style="margin: 0;">Create Page from Template</h2>');
            const $closeBtn = $('<button type="button" class="btn" style="padding: 6px 12px;">Close</button>').appendTo($header);

            const $body = $('<div class="cs-content" style="padding: 24px; overflow-y: auto;"></div>').appendTo($container);

            const $form = $(`
                <div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7cft-section">Create In</label></div>
                        <div class="input"><select id="site7cft-section" class="fullwidth"><option value="">Loading&hellip;</option></select></div>
                    </div>
                    <div class="field" style="margin-bottom: 16px;">
                        <div class="heading"><label for="site7cft-title">Title</label></div>
                        <div class="input"><input type="text" id="site7cft-title" class="text fullwidth" required></div>
                    </div>
                    <div class="field" style="margin-bottom: 0;">
                        <div class="heading"><label for="site7cft-slug">Slug (optional)</label></div>
                        <div class="input"><input type="text" id="site7cft-slug" class="text fullwidth"></div>
                    </div>
                </div>
            `).appendTo($body);

            this.$entryTypeSelect = $form.find('#site7cft-section');
            this.$titleInput = $form.find('#site7cft-title');
            this.$slugInput = $form.find('#site7cft-slug');

            const $footer = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--border-color, #e1e5ea);"></div>').appendTo($container);
            this.$createBtn = $('<button type="button" class="btn submit">Create Page</button>').appendTo($footer);

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
            this.$createBtn.on('click', $.proxy(this, 'onCreate'));

            this.loadEntryTypes();
        },

        loadEntryTypes: function() {
            const url = Craft.getActionUrl('site7-studio/template-generator/get-create-options');
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(res => res.json())
                .then($.proxy(function(response) {
                    this.$entryTypeSelect.empty();
                    const entryTypes = (response && response.entryTypes) || [];
                    if (!entryTypes.length) {
                        this.$entryTypeSelect.append('<option value="">No eligible Section found</option>');
                        this.$createBtn.prop('disabled', true);
                        return;
                    }
                    entryTypes.forEach(function(et) {
                        this.$entryTypeSelect.append(
                            $('<option></option>')
                                .val(et.entryTypeId)
                                .text(et.sectionName + ' — ' + et.entryTypeName)
                        );
                    }, this);
                }, this))
                .catch($.proxy(function() {
                    this.$entryTypeSelect.empty().append('<option value="">Could not load Sections</option>');
                }, this));
        },

        onCreate: function() {
            const entryTypeId = this.$entryTypeSelect.val();
            const title = this.$titleInput.val().trim();

            if (!entryTypeId) {
                Craft.cp.displayError('Choose where to create the page.');
                return;
            }
            if (!title) {
                Craft.cp.displayError('A Title is required.');
                return;
            }

            this.$createBtn.addClass('loading').prop('disabled', true);

            const url = Craft.getActionUrl('site7-studio/template-generator/create-from-template');
            const body = new URLSearchParams();
            body.append('handle', this.templateHandle);
            body.append('entryTypeId', entryTypeId);
            body.append('title', title);
            body.append('slug', this.$slugInput.val().trim());

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
                .then($.proxy(function(response) {
                    this.$createBtn.removeClass('loading').prop('disabled', false);
                    if (response.success) {
                        window.location.href = response.cpEditUrl;
                    } else {
                        Craft.cp.displayError(response.error || 'Could not create the page.');
                    }
                }, this))
                .catch($.proxy(function() {
                    this.$createBtn.removeClass('loading').prop('disabled', false);
                    Craft.cp.displayError('Error creating the page.');
                }, this));
        }
    });

    function checkForCreateFromTemplateTrigger() {
        $(document).on('click', '#site7-create-from-template-btn', function(e) {
            e.preventDefault();
            const handle = $(this).data('handle');
            if (handle) {
                new Site7CreateFromTemplateWizard(handle);
            }
        });
    }

    window.Site7CreateFromTemplateWizard = Site7CreateFromTemplateWizard;

    function checkForSaveAsTemplateTrigger() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('site7SaveAsTemplate') === '1') {
            const entryId = params.get('site7EntryId');
            params.delete('site7SaveAsTemplate');
            params.delete('site7EntryId');
            const query = params.toString();
            const newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.replaceState({}, document.title, newUrl);

            if (entryId) {
                new Site7TemplateWizard(entryId);
            }
        }
    }

    window.Site7TemplateWizard = Site7TemplateWizard;

    $(checkForSaveAsTemplateTrigger);
    $(checkForCreateFromTemplateTrigger);

})(jQuery);
