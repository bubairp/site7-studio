/**
 * Site7 Studio - Template Builder.
 *
 * Same mini-app pattern as the Pattern Builder (pattern-builder.js): one
 * in-memory "composition" array, rendered from and serialized back to a
 * single hidden JSON input on save. The Template Builder's canvas can hold
 * both Sections and Patterns (a Pattern Builder's canvas only holds
 * Sections), and each item tracks its own `type` alongside its `handle`.
 */
(function() {
    var root = document.getElementById('site7-template-builder');
    if (!root) {
        return;
    }

    var availableItems = JSON.parse(root.getAttribute('data-available-items') || '[]');
    var composition = JSON.parse(root.getAttribute('data-composition') || '[]');
    var selectedIndex = null;
    var activeFilter = 'all';
    var dirty = false;

    var libraryEl = document.getElementById('site7-tb-library');
    var canvasEl = document.getElementById('site7-tb-canvas-list');
    var propertiesEl = document.getElementById('site7-tb-properties-body');
    var searchEl = document.getElementById('site7-tb-search');
    var filterEl = document.getElementById('site7-tb-filter');
    var form = document.getElementById('site7-template-builder-form');
    var compositionInput = document.getElementById('site7-tb-composition-input');

    function findItemDef(type, handle) {
        return availableItems.find(function(i) { return i.type === type && i.handle === handle; });
    }

    // The canvas lives entirely in memory until "Save Template" is clicked.
    // The page also has a separate General-info form with its own Save
    // button - submitting that (or navigating away some other way) reloads
    // the page and silently discards any unsaved canvas edits. Warn instead.
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
        var matches = availableItems.filter(function(i) {
            if (activeFilter !== 'all' && i.type !== activeFilter) {
                return false;
            }
            return !query || i.name.toLowerCase().indexOf(query) !== -1 || i.category.toLowerCase().indexOf(query) !== -1;
        });

        var byCategory = {};
        matches.forEach(function(i) {
            (byCategory[i.category] = byCategory[i.category] || []).push(i);
        });

        libraryEl.innerHTML = '';
        Object.keys(byCategory).sort().forEach(function(category) {
            var heading = document.createElement('div');
            heading.className = 'site7-pb-category-heading';
            heading.textContent = category;
            libraryEl.appendChild(heading);

            byCategory[category].forEach(function(item) {
                var card = document.createElement('div');
                card.className = 'site7-pb-library-card';
                card.draggable = true;
                card.dataset.itemHandle = item.handle;
                card.dataset.itemType = item.type;

                var img = document.createElement('img');
                img.src = item.previewImageUrl;
                img.alt = '';
                img.onerror = function() { this.style.visibility = 'hidden'; };
                card.appendChild(img);

                var label = document.createElement('span');
                label.textContent = item.name;
                label.style.flex = '1';
                card.appendChild(label);

                var badge = document.createElement('span');
                badge.className = 'site7-tb-type-badge site7-tb-type-' + item.type;
                badge.textContent = item.type;
                card.appendChild(badge);

                card.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('application/x-site7-item', JSON.stringify({ type: item.type, handle: item.handle }));
                    e.dataTransfer.effectAllowed = 'copy';
                });

                libraryEl.appendChild(card);
            });
        });

        if (!matches.length) {
            var empty = document.createElement('p');
            empty.className = 'light';
            empty.textContent = 'No Sections or Patterns found.';
            libraryEl.appendChild(empty);
        }
    }

    filterEl.addEventListener('click', function(e) {
        var btn = e.target.closest('button[data-filter]');
        if (!btn) {
            return;
        }
        activeFilter = btn.dataset.filter;
        Array.from(filterEl.querySelectorAll('button')).forEach(function(b) {
            b.classList.toggle('active', b === btn);
        });
        renderLibrary();
    });

    // ----- Canvas (center) -----

    function renderCanvas() {
        canvasEl.innerHTML = '';

        if (!composition.length) {
            var empty = document.createElement('p');
            empty.className = 'light';
            empty.textContent = 'Drag Sections or Patterns here to build the Template.';
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

            var badge = document.createElement('span');
            badge.className = 'site7-tb-type-badge site7-tb-type-' + item.type;
            badge.textContent = item.type;
            card.appendChild(badge);

            var name = document.createElement('span');
            name.className = 'site7-pb-card-name';
            name.textContent = item.name;
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
                var clone = { type: item.type, handle: item.handle, name: item.name, defaultValues: Object.assign({}, item.defaultValues) };
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
                if (item.type === 'pattern') {
                    var patternNote = document.createElement('div');
                    patternNote.className = 'light';
                    patternNote.style.cssText = 'margin: -4px 0 8px 28px; font-size: 12px;';
                    patternNote.textContent = 'Pattern - content configured in its own editor.';
                    canvasEl.appendChild(patternNote);
                } else {
                    var summaryEntries = Object.keys(item.defaultValues || {}).filter(function(k) { return item.defaultValues[k]; });
                    if (summaryEntries.length) {
                        var summary = document.createElement('div');
                        summary.className = 'light';
                        summary.style.cssText = 'margin: -4px 0 8px 28px; font-size: 12px;';
                        summary.textContent = summaryEntries.map(function(k) { return k + ': ' + item.defaultValues[k]; }).join(', ');
                        canvasEl.appendChild(summary);
                    }
                }
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
            placeholder.textContent = 'Select a Section in the canvas to edit its Default Values.';
            propertiesEl.appendChild(placeholder);
            return;
        }

        var item = composition[selectedIndex];
        var itemDef = findItemDef(item.type, item.handle);

        var heading = document.createElement('p');
        heading.innerHTML = '<strong>' + escapeHtml(item.name) + '</strong>';
        propertiesEl.appendChild(heading);

        if (item.type === 'pattern') {
            var note = document.createElement('p');
            note.className = 'light';
            note.textContent = 'A Template only references a Pattern - its content is configured in the Pattern\'s own editor.';
            propertiesEl.appendChild(note);

            if (itemDef && itemDef.editUrl) {
                var link = document.createElement('a');
                link.href = itemDef.editUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'btn small';
                link.textContent = 'Edit ' + item.name;
                propertiesEl.appendChild(link);
            }
            return;
        }

        var fields = itemDef ? itemDef.fields : [];

        if (!fields.length) {
            var none = document.createElement('p');
            none.className = 'light';
            none.textContent = 'This Section has no fields yet.';
            propertiesEl.appendChild(none);
            return;
        }

        fields.forEach(function(field) {
            var wrap = document.createElement('div');
            wrap.className = 'site7-pb-properties-field';

            var label = document.createElement('label');
            label.textContent = field.name;
            wrap.appendChild(label);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'text fullwidth';
            input.value = (item.defaultValues && item.defaultValues[field.handle]) || '';
            input.addEventListener('input', function() {
                item.defaultValues = item.defaultValues || {};
                item.defaultValues[field.handle] = input.value;
                markDirty();
                renderCanvas();
            });
            wrap.appendChild(input);

            propertiesEl.appendChild(wrap);
        });
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

        var itemRaw = e.dataTransfer.getData('application/x-site7-item');
        var reorderIndexRaw = e.dataTransfer.getData('application/x-site7-reorder');

        if (itemRaw) {
            var parsed;
            try {
                parsed = JSON.parse(itemRaw);
            } catch (err) {
                return;
            }
            var itemDef = findItemDef(parsed.type, parsed.handle);
            if (!itemDef) {
                return;
            }
            composition.splice(dropIndex, 0, {
                type: itemDef.type,
                handle: itemDef.handle,
                name: itemDef.name,
                defaultValues: {},
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
                type: item.type,
                handle: item.handle,
                defaultValues: item.defaultValues || {},
            };
        }));
    });

    renderLibrary();
    renderCanvas();
    renderProperties();
})();
