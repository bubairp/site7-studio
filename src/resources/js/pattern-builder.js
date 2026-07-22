/**
 * Site7 Studio - Pattern Builder.
 *
 * A small vanilla-JS mini-app operating on one in-memory array (the
 * canvas "composition"), rendered from and serialized back to a single
 * hidden JSON input on save - no framework, no per-row form field naming
 * scheme to keep in sync with the server.
 */
(function() {
    var root = document.getElementById('site7-pattern-builder');
    if (!root) {
        return;
    }

    var availableSections = JSON.parse(root.getAttribute('data-available-sections') || '[]');
    var composition = JSON.parse(root.getAttribute('data-composition') || '[]');
    var selectedIndex = null;
    var dragReorderIndex = null;

    var libraryEl = document.getElementById('site7-pb-library');
    var canvasEl = document.getElementById('site7-pb-canvas-list');
    var propertiesEl = document.getElementById('site7-pb-properties-body');
    var searchEl = document.getElementById('site7-pb-search');
    var form = document.getElementById('site7-pattern-builder-form');
    var compositionInput = document.getElementById('site7-pb-composition-input');

    function findSectionDef(handle) {
        return availableSections.find(function(s) { return s.handle === handle; });
    }

    // ----- Library (left sidebar) -----

    function renderLibrary() {
        var query = (searchEl.value || '').toLowerCase().trim();
        var matches = availableSections.filter(function(s) {
            return !query || s.name.toLowerCase().indexOf(query) !== -1 || s.category.toLowerCase().indexOf(query) !== -1;
        });

        var byCategory = {};
        matches.forEach(function(s) {
            (byCategory[s.category] = byCategory[s.category] || []).push(s);
        });

        libraryEl.innerHTML = '';
        Object.keys(byCategory).sort().forEach(function(category) {
            var heading = document.createElement('div');
            heading.className = 'site7-pb-category-heading';
            heading.textContent = category;
            libraryEl.appendChild(heading);

            byCategory[category].forEach(function(section) {
                var card = document.createElement('div');
                card.className = 'site7-pb-library-card';
                card.draggable = true;
                card.dataset.sectionHandle = section.handle;

                var img = document.createElement('img');
                img.src = section.previewImageUrl;
                img.alt = '';
                img.onerror = function() { this.style.visibility = 'hidden'; };
                card.appendChild(img);

                var label = document.createElement('span');
                label.textContent = section.name;
                card.appendChild(label);

                card.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('application/x-site7-section', section.handle);
                    e.dataTransfer.effectAllowed = 'copy';
                });

                libraryEl.appendChild(card);
            });
        });

        if (!matches.length) {
            var empty = document.createElement('p');
            empty.className = 'light';
            empty.textContent = 'No Sections found.';
            libraryEl.appendChild(empty);
        }
    }

    // ----- Canvas (center) -----

    function renderCanvas() {
        canvasEl.innerHTML = '';

        if (!composition.length) {
            var empty = document.createElement('p');
            empty.className = 'light';
            empty.textContent = 'Drag Sections here to build the Pattern.';
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
            name.textContent = item.sectionName;
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
                var clone = { sectionHandle: item.sectionHandle, sectionName: item.sectionName, defaultValues: Object.assign({}, item.defaultValues) };
                composition.splice(index + 1, 0, clone);
                renderCanvas();
            });
            actions.appendChild(duplicateBtn);

            var deleteBtn = makeIconButton('xmark', 'Delete');
            deleteBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                composition.splice(index, 1);
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
                dragReorderIndex = index;
                e.dataTransfer.setData('application/x-site7-reorder', String(index));
                e.dataTransfer.effectAllowed = 'move';
            });

            card.addEventListener('dragend', function() {
                dragReorderIndex = null;
            });

            canvasEl.appendChild(card);

            if (!item.collapsed) {
                var summaryEntries = Object.keys(item.defaultValues || {}).filter(function(k) { return item.defaultValues[k]; });
                if (summaryEntries.length) {
                    var summary = document.createElement('div');
                    summary.className = 'light';
                    summary.style.cssText = 'margin: -4px 0 8px 28px; font-size: 12px;';
                    summary.textContent = summaryEntries.map(function(k) { return k + ': ' + item.defaultValues[k]; }).join(', ');
                    canvasEl.appendChild(summary);
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
        var sectionDef = findSectionDef(item.sectionHandle);
        var fields = sectionDef ? sectionDef.fields : [];

        var heading = document.createElement('p');
        heading.innerHTML = '<strong>' + escapeHtml(item.sectionName) + '</strong>';
        propertiesEl.appendChild(heading);

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
                renderCanvasSummaryOnly();
            });
            wrap.appendChild(input);

            propertiesEl.appendChild(wrap);
        });
    }

    // Re-render just the canvas (to refresh the collapsed Default Values
    // summary) without losing focus on the properties panel's inputs.
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

        var sectionHandle = e.dataTransfer.getData('application/x-site7-section');
        var reorderIndexRaw = e.dataTransfer.getData('application/x-site7-reorder');

        if (sectionHandle) {
            var sectionDef = findSectionDef(sectionHandle);
            if (!sectionDef) {
                return;
            }
            composition.splice(dropIndex, 0, {
                sectionHandle: sectionDef.handle,
                sectionName: sectionDef.name,
                defaultValues: {},
            });
            selectedIndex = dropIndex;
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
        compositionInput.value = JSON.stringify(composition.map(function(item) {
            return {
                sectionHandle: item.sectionHandle,
                defaultValues: item.defaultValues || {},
            };
        }));
    });

    renderLibrary();
    renderCanvas();
    renderProperties();
})();
