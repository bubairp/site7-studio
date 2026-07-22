/**
 * Site7 Studio - Starter Kit Builder.
 *
 * Same mini-app pattern as the Pattern/Template Builders: one in-memory
 * "composition" array (here, the Starter Kit's Pages), rendered from and
 * serialized back to a single hidden JSON input on save. Each Page is a
 * structural reference to a Template (title/slug/entry type + the
 * Template's own handle) - the Starter Kit never stores page content
 * itself, matching the existing "Save Current Site as Starter Kit" schema.
 */
(function() {
    var root = document.getElementById('site7-starter-kit-builder');
    if (!root) {
        return;
    }

    var availableTemplates = JSON.parse(root.getAttribute('data-available-templates') || '[]');
    var eligibleEntryTypes = JSON.parse(root.getAttribute('data-eligible-entry-types') || '[]');
    var composition = JSON.parse(root.getAttribute('data-composition') || '[]');
    var selectedIndex = null;
    var dirty = false;

    var libraryEl = document.getElementById('site7-sk-library');
    var canvasEl = document.getElementById('site7-sk-canvas-list');
    var propertiesEl = document.getElementById('site7-sk-properties-body');
    var searchEl = document.getElementById('site7-sk-search');
    var form = document.getElementById('site7-starter-kit-builder-form');
    var compositionInput = document.getElementById('site7-sk-composition-input');

    function findTemplateDef(handle) {
        return availableTemplates.find(function(t) { return t.handle === handle; });
    }

    function findEntryTypeOption(entryTypeHandle) {
        return eligibleEntryTypes.find(function(o) { return o.entryTypeHandle === entryTypeHandle; });
    }

    function slugify(str) {
        return (str || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'page';
    }

    // The canvas lives entirely in memory until "Save Starter Kit" is
    // clicked. The page also has a separate General-info form with its own
    // Save button - submitting that (or navigating away some other way)
    // reloads the page and silently discards any unsaved canvas edits.
    function markDirty() {
        dirty = true;
    }

    window.addEventListener('beforeunload', function(e) {
        if (!dirty) {
            return;
        }
        e.preventDefault();
        e.returnValue = '';
    });

    // ----- Library (left sidebar) -----

    function renderLibrary() {
        var query = (searchEl.value || '').toLowerCase().trim();
        var matches = availableTemplates.filter(function(t) {
            return !query || t.name.toLowerCase().indexOf(query) !== -1 || t.category.toLowerCase().indexOf(query) !== -1;
        });

        var byCategory = {};
        matches.forEach(function(t) {
            (byCategory[t.category] = byCategory[t.category] || []).push(t);
        });

        libraryEl.innerHTML = '';
        Object.keys(byCategory).sort().forEach(function(category) {
            var heading = document.createElement('div');
            heading.className = 'site7-pb-category-heading';
            heading.textContent = category;
            libraryEl.appendChild(heading);

            byCategory[category].forEach(function(template) {
                var card = document.createElement('div');
                card.className = 'site7-pb-library-card';
                card.draggable = true;
                card.dataset.templateHandle = template.handle;

                var img = document.createElement('img');
                img.src = template.previewImageUrl;
                img.alt = '';
                img.onerror = function() { this.style.visibility = 'hidden'; };
                card.appendChild(img);

                var label = document.createElement('span');
                label.textContent = template.name;
                card.appendChild(label);

                card.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('application/x-site7-template', template.handle);
                    e.dataTransfer.effectAllowed = 'copy';
                });

                libraryEl.appendChild(card);
            });
        });

        if (!matches.length) {
            var empty = document.createElement('p');
            empty.className = 'light';
            empty.textContent = 'No Templates found.';
            libraryEl.appendChild(empty);
        }
    }

    // ----- Canvas (center) -----

    function renderCanvas() {
        canvasEl.innerHTML = '';

        if (!composition.length) {
            var empty = document.createElement('p');
            empty.className = 'light';
            empty.textContent = 'Drag Templates here to build the Starter Kit.';
            canvasEl.appendChild(empty);
            return;
        }

        composition.forEach(function(item, index) {
            var card = document.createElement('div');
            card.className = 'site7-pb-card' + (index === selectedIndex ? ' site7-pb-selected' : '');
            card.draggable = true;

            var number = document.createElement('span');
            number.className = 'site7-pb-card-number';
            number.textContent = (index + 1) + '.';
            card.appendChild(number);

            var name = document.createElement('span');
            name.className = 'site7-pb-card-name';
            name.textContent = item.title || '(untitled)';
            card.appendChild(name);

            var actions = document.createElement('div');
            actions.className = 'site7-pb-card-actions';

            var collapseBtn = makeIconButton('expand', item.collapsed ? 'Expand' : 'Collapse');
            collapseBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                item.collapsed = !item.collapsed;
                renderCanvas();
            });
            actions.appendChild(collapseBtn);

            var duplicateBtn = makeIconButton('copy', 'Duplicate');
            duplicateBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var clone = {
                    title: item.title + ' Copy',
                    slug: slugify(item.title + '-copy'),
                    entryTypeHandle: item.entryTypeHandle,
                    templateHandle: item.templateHandle,
                    templateName: item.templateName,
                };
                composition.splice(index + 1, 0, clone);
                markDirty();
                renderCanvas();
            });
            actions.appendChild(duplicateBtn);

            var deleteBtn = makeIconButton('xmark', 'Delete');
            deleteBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                composition.splice(index, 1);
                markDirty();
                if (selectedIndex === index) {
                    selectedIndex = null;
                    renderProperties();
                } else if (selectedIndex !== null && selectedIndex > index) {
                    selectedIndex--;
                }
                renderCanvas();
            });
            actions.appendChild(deleteBtn);

            card.appendChild(actions);

            card.addEventListener('click', function() {
                selectedIndex = index;
                renderCanvas();
                renderProperties();
            });

            card.addEventListener('dragstart', function(e) {
                e.dataTransfer.setData('application/x-site7-reorder', String(index));
                e.dataTransfer.effectAllowed = 'move';
            });

            canvasEl.appendChild(card);

            if (!item.collapsed) {
                var summary = document.createElement('div');
                summary.className = 'light';
                summary.style.cssText = 'margin: -4px 0 8px 28px; font-size: 12px;';
                var entryTypeOption = findEntryTypeOption(item.entryTypeHandle);
                summary.textContent = item.templateName + ' → /' + (item.slug || '') +
                    (entryTypeOption ? ' (' + entryTypeOption.entryTypeName + ')' : '');
                canvasEl.appendChild(summary);
            }
        });
    }

    function makeIconButton(icon, title) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn small chromeless';
        btn.title = title;
        btn.setAttribute('aria-label', title);
        btn.setAttribute('data-icon', icon);
        return btn;
    }

    // ----- Properties (right sidebar) -----

    function renderProperties() {
        propertiesEl.innerHTML = '';

        if (selectedIndex === null || !composition[selectedIndex]) {
            var placeholder = document.createElement('p');
            placeholder.className = 'light';
            placeholder.textContent = 'Select a Page in the canvas to edit its Title, Slug, and Entry Type.';
            propertiesEl.appendChild(placeholder);
            return;
        }

        var item = composition[selectedIndex];

        var heading = document.createElement('p');
        heading.innerHTML = '<strong>' + escapeHtml(item.templateName) + '</strong>';
        propertiesEl.appendChild(heading);

        var titleWrap = document.createElement('div');
        titleWrap.className = 'site7-pb-properties-field';
        var titleLabel = document.createElement('label');
        titleLabel.textContent = 'Title';
        titleWrap.appendChild(titleLabel);
        var titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'text fullwidth';
        titleInput.value = item.title || '';
        titleInput.addEventListener('input', function() {
            item.title = titleInput.value;
            markDirty();
            renderCanvasSummaryOnly();
        });
        titleWrap.appendChild(titleInput);
        propertiesEl.appendChild(titleWrap);

        var slugWrap = document.createElement('div');
        slugWrap.className = 'site7-pb-properties-field';
        var slugLabel = document.createElement('label');
        slugLabel.textContent = 'Slug';
        slugWrap.appendChild(slugLabel);
        var slugInput = document.createElement('input');
        slugInput.type = 'text';
        slugInput.className = 'text fullwidth code';
        slugInput.value = item.slug || '';
        slugInput.addEventListener('input', function() {
            item.slug = slugInput.value;
            markDirty();
            renderCanvasSummaryOnly();
        });
        slugWrap.appendChild(slugInput);
        propertiesEl.appendChild(slugWrap);

        var entryTypeWrap = document.createElement('div');
        entryTypeWrap.className = 'site7-pb-properties-field';
        var entryTypeLabel = document.createElement('label');
        entryTypeLabel.textContent = 'Entry Type';
        entryTypeWrap.appendChild(entryTypeLabel);
        var entryTypeSelect = document.createElement('select');
        entryTypeSelect.className = 'text fullwidth';
        eligibleEntryTypes.forEach(function(option) {
            var opt = document.createElement('option');
            opt.value = option.entryTypeHandle;
            opt.textContent = option.entryTypeName + ' (' + option.sectionName + ')';
            if (option.entryTypeHandle === item.entryTypeHandle) {
                opt.selected = true;
            }
            entryTypeSelect.appendChild(opt);
        });
        entryTypeSelect.addEventListener('change', function() {
            item.entryTypeHandle = entryTypeSelect.value;
            markDirty();
            renderCanvasSummaryOnly();
        });
        entryTypeWrap.appendChild(entryTypeSelect);
        propertiesEl.appendChild(entryTypeWrap);

        if (!eligibleEntryTypes.length) {
            var none = document.createElement('p');
            none.className = 'light';
            none.textContent = 'No eligible Entry Types found - configure the Site7 Matrix field on at least one Entry Type first.';
            propertiesEl.appendChild(none);
        }
    }

    function renderCanvasSummaryOnly() {
        renderCanvas();
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ----- Drop handling -----

    canvasEl.addEventListener('dragover', function(e) {
        e.preventDefault();
        canvasEl.classList.add('site7-pb-dragover');
    });

    canvasEl.addEventListener('dragleave', function() {
        canvasEl.classList.remove('site7-pb-dragover');
    });

    canvasEl.addEventListener('drop', function(e) {
        e.preventDefault();
        canvasEl.classList.remove('site7-pb-dragover');

        var dropIndex = computeDropIndex(e.clientY);

        var templateHandle = e.dataTransfer.getData('application/x-site7-template');
        var reorderIndexRaw = e.dataTransfer.getData('application/x-site7-reorder');

        if (templateHandle) {
            var templateDef = findTemplateDef(templateHandle);
            if (!templateDef) {
                return;
            }

            var defaultEntryType = eligibleEntryTypes.find(function(o) {
                return templateDef.sourceEntryType && o.entryTypeHandle === templateDef.sourceEntryType;
            }) || eligibleEntryTypes[0] || null;

            composition.splice(dropIndex, 0, {
                title: templateDef.name,
                slug: slugify(templateDef.name),
                entryTypeHandle: defaultEntryType ? defaultEntryType.entryTypeHandle : '',
                templateHandle: templateDef.handle,
                templateName: templateDef.name,
            });
            selectedIndex = dropIndex;
            markDirty();
            renderCanvas();
            renderProperties();
        } else if (reorderIndexRaw !== '') {
            var fromIndex = parseInt(reorderIndexRaw, 10);
            var item = composition[fromIndex];
            if (!item) {
                return;
            }
            composition.splice(fromIndex, 1);
            var adjustedIndex = dropIndex > fromIndex ? dropIndex - 1 : dropIndex;
            composition.splice(adjustedIndex, 0, item);
            selectedIndex = adjustedIndex;
            markDirty();
            renderCanvas();
            renderProperties();
        }
    });

    function computeDropIndex(clientY) {
        var cards = Array.from(canvasEl.querySelectorAll('.site7-pb-card'));
        for (var i = 0; i < cards.length; i++) {
            var rect = cards[i].getBoundingClientRect();
            if (clientY < rect.top + rect.height / 2) {
                return i;
            }
        }
        return cards.length;
    }

    // ----- Wiring -----

    searchEl.addEventListener('input', renderLibrary);

    form.addEventListener('submit', function() {
        dirty = false;
        compositionInput.value = JSON.stringify(composition.map(function(item) {
            return {
                title: item.title,
                slug: item.slug,
                entryTypeHandle: item.entryTypeHandle,
                templateHandle: item.templateHandle,
            };
        }));
    });

    renderLibrary();
    renderCanvas();
    renderProperties();
})();
