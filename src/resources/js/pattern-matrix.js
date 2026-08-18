/**
 * Site7 Studio - Pattern Matrix Insertion JS
 * 
 * Injects an "Insert Pattern" button into the Craft Matrix field UI.
 */
(function($) {
    console.log('[Site7 Studio] JS File Loaded');
    
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        console.warn('[Site7 Studio] Craft or Garnish is undefined');
        return;
    }

    const PatternInserter = Garnish.Base.extend({
        init: function() {
            // Poll for matrix fields to support PJAX page loads and slideouts
            this.pollInterval = setInterval($.proxy(this, 'pollForMatrixFields'), 500);
            this.pollForMatrixFields();
        },
        
        pollForMatrixFields: function() {
            const self = this;
            $('div.matrix, .nested-element-cards').each(function() {
                self.injectButton($(this));
            });
            
            // Inject global CSS once to hide default buttons. The site7-btn-group
            // is appended as a SIBLING of .buttons/.flex-inline (see injectButton()
            // below for why), so hiding "everything except site7-btn-group" can
            // simply hide all of .buttons/.flex-inline's own children.
            if (!this.cssInjected) {
                $('<style>.site7-matrix-override .buttons > *, .site7-matrix-override .flex-inline > * { display: none !important; } .site7-matrix-override .site7-btn-group, .site7-matrix-override .site7-add-block-btn, .site7-matrix-override .site7-insert-pattern-btn { display: flex !important; }</style>').appendTo(document.head);
                this.cssInjected = true;
            }
        },

        injectButton: function($matrixContainer) {
            // Only inject once per field container
            if ($matrixContainer.find('.site7-btn-group').length > 0) {
                return;
            }

            // Check against configured matrix field handle
            if (window.site7Studio && window.site7Studio.matrixFieldHandle) {
                const searchHandle = window.site7Studio.matrixFieldHandle.toLowerCase();
                const containerId = ($matrixContainer.attr('id') || '').toLowerCase();
                const $fieldParent = $matrixContainer.closest('.field');
                const fieldAttr = ($fieldParent.attr('data-attribute') || '').toLowerCase();
                const fieldIdAttr = ($fieldParent.attr('id') || '').toLowerCase();
                
                const hasHandle = containerId.includes(searchHandle) || 
                                  fieldAttr.includes(searchHandle) || 
                                  fieldIdAttr.includes(searchHandle) ||
                                  $matrixContainer.find('input[name*="' + searchHandle + '"]').length > 0;
                
                if (!hasHandle) {
                    return;
                }
            }

            // Wait until the button container is created in the DOM
            let $btnContainer = $matrixContainer.find('.buttons').first();
            if ($btnContainer.length === 0) {
                $btnContainer = $matrixContainer.find('.flex-inline, .flex.flex-inline').first();
            }

            if ($btnContainer.length === 0) {
                return;
            }
            
            console.log('[Site7 Studio] Injecting buttons for: ', $matrixContainer.attr('id'));

            // Create buttons container
            const $btnGroup = $('<div class="site7-btn-group" style="display: flex; gap: 10px; margin-top: 10px; width: 100%;"></div>');

            // Create "Add Section" and "Insert Pattern" buttons. Deliberately NOT
            // using Craft's "btn" class here. Craft's own Matrix/NestedElementManager
            // field JS finds its native add-entry button with a selector scoped to
            // the WHOLE .buttons container - e.g. classic MatrixInput does
            // `this.$addEntryBtn = this.$addEntryBtnContainer.find(".btn:not(.menubtn)")`
            // - which matches ANY .btn-classed element inside .buttons, not just
            // Craft's own button. Avoiding just Craft's "add" class (as a previous
            // version of this comment described) was not enough: these elements
            // still matched ".btn:not(.menubtn)" and so - depending on init timing -
            // could end up with Craft's native click/keydown/"activate" handlers
            // bound directly onto them in addition to our own, silently invoking
            // Craft's native single-entry-type instant-add action (e.g. creating an
            // unwanted "Gallery" block) whenever this button was clicked, regardless
            // of stopPropagation (that only stops bubbling to ancestors, not other
            // handlers bound to the same element). Dropping the "btn" class avoids
            // that match entirely; visual parity with Craft's button style is
            // replicated via inline styles below and site7-btn-group's own CSS.
            const $addBlockBtn = $('<div class="dashed icon site7-add-block-btn" style="flex: 1; justify-content: center; border: 1px dashed #5b32d5; border-radius: 4px; color: #5b32d5; cursor: pointer; padding: 12px; font-weight: bold; text-align: center;">Add Section</div>');
            const $insertPatternBtn = $('<div class="dashed icon site7-insert-pattern-btn" style="flex: 1; justify-content: center; border: 1px dashed #5b32d5; border-radius: 4px; color: #5b32d5; cursor: pointer; padding: 12px; font-weight: bold; text-align: center;">Insert Pattern</div>');

            $btnGroup.append($addBlockBtn).append($insertPatternBtn);

            // Insert as a SIBLING of the buttons container, not a child of it - see
            // the comment above: staying out of .buttons entirely is what keeps
            // Craft's own field JS from ever being able to match these elements
            // with its .buttons-scoped "find the add button" selector.
            $btnContainer.after($btnGroup);

            $matrixContainer.addClass('site7-matrix-override');
            $matrixContainer.closest('.field').addClass('site7-matrix-override');

            // Bind clicks - stopPropagation() as defense in depth so this click
            // can never bubble into any handler Craft has delegated on the
            // shared .buttons container, on top of the fixes above.
            $addBlockBtn.on('click', $.proxy(function(e) { e.stopPropagation(); this.openPatternModal($matrixContainer, 'section', e); }, this));
            $insertPatternBtn.on('click', $.proxy(function(e) { e.stopPropagation(); this.openPatternModal($matrixContainer, 'pattern', e); }, this));
        },

        openPatternModal: function($matrixContainer, defaultTab, e) {
            e.preventDefault();

            if (typeof window.Site7PatternBrowser === 'undefined') {
                Craft.cp.displayError('Site7 Browser component not loaded.');
                return;
            }

            new window.Site7PatternBrowser(defaultTab, $.proxy(function(handle, type, blockTypeHandle, blockTypeId) {
                if (handle && type) {
                    if (type === 'section') {
                        this.insertSection($matrixContainer, handle, blockTypeHandle, blockTypeId);
                    } else if (type === 'pattern') {
                        this.insertPattern($matrixContainer, handle);
                    } else if (type === 'template') {
                        this.insertTemplate($matrixContainer, handle);
                    }
                }
            }, this));
        },

        // Resolves the createAttributes to pass to manager.createElement() for a
        // given target Entry Type. Craft's NestedElementManager represents this
        // two different ways depending on how many Entry Types the field allows:
        //  - Multiple Entry Types: settings.createAttributes is an ARRAY of
        //    {label, attributes} options (one per type), which is what backs the
        //    native "+" button's dropdown menu.
        //  - Exactly one Entry Type: settings.createAttributes is a single plain
        //    OBJECT ({typeId: N, ...}) - there's no dropdown, so Craft skips the
        //    array entirely. Code that only ever handled the array shape (as this
        //    did previously) silently fails every insert in this case, since the
        //    array-only loop is skipped and nothing is ever found.
        // Matching prefers the exact typeId (passed from the server, which knows
        // the real Entry Type id) over fuzzy label/handle string matching, which
        // only worked by coincidence when a Section's name happened to normalize
        // the same as its handle.
        resolveCreateAttributes: function(manager, searchHandle, typeId) {
            if (!manager || !manager.settings) {
                return null;
            }
            const createAttributes = manager.settings.createAttributes;
            if (!createAttributes) {
                return null;
            }

            if (!Array.isArray(createAttributes)) {
                // Only return this single allowed type when we can POSITIVELY confirm
                // it's the one the caller actually asked for - never just because we
                // couldn't prove otherwise. A field with exactly one allowed Entry
                // Type type-checks true against anything unless we're careful here,
                // which previously let this silently substitute the field's one
                // allowed type for whatever the caller actually requested.
                if (typeId != null) {
                    if (createAttributes.typeId == null) {
                        return null;
                    }
                    return Number(createAttributes.typeId) === Number(typeId) ? createAttributes : null;
                }
                const normalize = str => (str || '').toLowerCase().replace(/[^a-z0-9]/g, '');
                const normalizedSearch = normalize(searchHandle);
                const candidateHandle = normalize(createAttributes.typeHandle || createAttributes.type);
                return (candidateHandle && candidateHandle === normalizedSearch) ? createAttributes : null;
            }

            const normalize = str => (str || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            const normalizedSearch = normalize(searchHandle);

            for (let i = 0; i < createAttributes.length; i++) {
                const attrObj = createAttributes[i];
                if (typeId != null && attrObj.attributes && Number(attrObj.attributes.typeId) === Number(typeId)) {
                    return attrObj.attributes;
                }
                const normalizedLabel = normalize(attrObj.label);
                if (normalizedLabel === normalizedSearch ||
                    normalize(attrObj.attributes?.typeHandle) === normalizedSearch ||
                    normalize(attrObj.attributes?.type) === normalizedSearch) {
                    return attrObj.attributes;
                }
            }
            return null;
        },

        insertSection: function($matrixContainer, handle, blockTypeHandle, blockTypeId) {
            const searchHandle = blockTypeHandle || handle;
            const manager = $matrixContainer.data('nestedElementManager') || $matrixContainer.data('nested-element-manager');
            const matrixInstance = $matrixContainer.data('matrix');

            // If Craft 5 NestedElementManager
            if (manager) {
                const attributes = this.resolveCreateAttributes(manager, searchHandle, blockTypeId);
                if (attributes) {
                    manager.createElement(attributes);
                    Craft.cp.displayNotice('Section inserted.');
                    return;
                }
            }

            // If Craft 4 MatrixInput (also used by Craft 5 fields whose View Mode
            // is "Blocks" instead of "Cards" - those still render the classic
            // block editor, not NestedElementManager).
            if (matrixInstance) {
                let $addBtn = matrixInstance.$container.find(`.buttons .btn[data-type="${searchHandle}"]`);
                if ($addBtn.length === 0) {
                    // A field with exactly one allowed Entry Type renders a single
                    // add button with no data-type attribute at all (nothing to
                    // disambiguate) - only treat it as a match when it's genuinely
                    // the only button present, so we never guess wrong.
                    const $onlyButton = matrixInstance.$container.find('.buttons .btn.add');
                    if ($onlyButton.length === 1 && $onlyButton.attr('data-type') === undefined) {
                        $addBtn = $onlyButton;
                    }
                }
                if ($addBtn.length) {
                    $addBtn.trigger('click').trigger('activate');
                    Craft.cp.displayNotice('Section inserted.');
                    return;
                }
                // Do NOT fall through to a page-wide search below - a matrixInstance
                // was found but no button we can trust was, and searching the rest
                // of the page risks matching an unrelated element's identical
                // data-type and inserting the wrong content silently.
                Craft.cp.displayError('Matrix block type button/item not found for: ' + searchHandle);
                return;
            }

            // Fallback for any other Matrix UI shape - scoped to this field's own
            // container only, never the whole document. (A previous version of
            // this fallback searched the entire page for [data-type="..."], which
            // could match an unrelated element elsewhere and insert the wrong
            // content silently - see the bug report this comment accompanies.)
            const $addBtn = $matrixContainer.find(`.buttons [data-type="${searchHandle}"], .flex-inline [data-type="${searchHandle}"], .menu [data-type="${searchHandle}"]`);

            if ($addBtn.length) {
                $addBtn.trigger('click').trigger('activate');
                Craft.cp.displayNotice('Section inserted.');
            } else {
                Craft.cp.displayError('Matrix block type button/item not found for: ' + searchHandle);
            }
        },

        insertPattern: function($matrixContainer, handle) {
            // Fetch template blocks from API
            const url = Craft.getActionUrl ? Craft.getActionUrl('site7-studio/package-action/get-pattern-blocks') : '/admin/site7-studio/package-action/get-pattern-blocks';
            
            $.ajax({
                url: url,
                type: 'GET',
                data: { handle: handle },
                dataType: 'json',
                headers: {
                    'Accept': 'application/json'
                },
                success: $.proxy(function(response) {
                    if (response.success && response.blocks) {
                        this.createBlocksSequentially($matrixContainer, response.blocks);
                    } else {
                        Craft.cp.displayError('Failed to load pattern blocks: ' + (response.error || 'Unknown error'));
                    }
                }, this),
                error: $.proxy(function() {
                    Craft.cp.displayError('Error fetching pattern blocks.');
                }, this)
            });
        },

        insertTemplate: function($matrixContainer, handle) {
            // Fetch the flattened Section list from API. Templates resolve to the same
            // {type, typeId, fields} block shape as Patterns, so block creation is shared.
            const url = Craft.getActionUrl ? Craft.getActionUrl('site7-studio/package-action/get-template-blocks') : '/admin/site7-studio/package-action/get-template-blocks';

            $.ajax({
                url: url,
                type: 'GET',
                data: { handle: handle },
                dataType: 'json',
                headers: {
                    'Accept': 'application/json'
                },
                success: $.proxy(function(response) {
                    if (response.success && response.blocks) {
                        this.createBlocksSequentially($matrixContainer, response.blocks);
                    } else {
                        Craft.cp.displayError('Failed to load template blocks: ' + (response.error || 'Unknown error'));
                    }
                }, this),
                error: $.proxy(function() {
                    Craft.cp.displayError('Error fetching template blocks.');
                }, this)
            });
        },

        createBlocksSequentially: async function($matrixContainer, blocks) {
            if (blocks.length === 0) return;
            
            const manager = $matrixContainer.data('nestedElementManager') || $matrixContainer.data('nested-element-manager');
            const matrixInstance = $matrixContainer.data('matrix');

            if (manager) {
                for (const block of blocks) {
                    const attributes = this.resolveCreateAttributes(manager, block.type, block.typeId);
                    if (attributes) {
                        const createData = Object.assign({
                            elementType: manager.elementType,
                            ownerId: manager.settings.ownerId,
                            fieldId: manager.settings.fieldId,
                            siteId: manager.settings.ownerSiteId
                        }, attributes);

                        try {
                            const createResponse = await Craft.sendActionRequest("POST", "elements/create", { data: createData });
                            const element = createResponse && createResponse.data && createResponse.data.element;
                            if (element) {
                                // elements/create only creates a bare draft; field content must be
                                // applied via elements/save-draft, same as the native editor slideout does.
                                const saveData = {
                                    elementId: element.id,
                                    draftId: element.draftId,
                                    siteId: element.siteId,
                                    fields: block.fields
                                };
                                await Craft.sendActionRequest("POST", "elements/save-draft", { data: saveData });

                                await manager.addElementCard(element);
                                // Not awaited: markAsDirty() only flags the field's unsaved-changes
                                // indicator and its returned promise can hang indefinitely (observed
                                // under automated/backgrounded conditions), which would otherwise
                                // permanently stall this loop after the first block.
                                manager.markAsDirty();
                            }
                        } catch (err) {
                            console.error('Failed to create block:', err);
                        }
                    }
                }
                
                Craft.cp.displayNotice('Content inserted.');
                return;
            }

            const delay = ms => new Promise(res => setTimeout(res, ms));

            for (const block of blocks) {
                const searchHandle = block.type;

                // If Craft 4 MatrixInput
                if (matrixInstance) {
                    const $addBtn = matrixInstance.$container.find(`.buttons .btn[data-type="${searchHandle}"]`);
                    if ($addBtn.length) {
                        $addBtn.trigger('click').trigger('activate');
                        await delay(500);
                        continue;
                    }
                }

                // Fallback - scoped to this field's own container only, never the
                // whole document (see insertSection() above for why: an unscoped
                // [data-type="..."] search can match an unrelated element elsewhere
                // on the page and insert the wrong content silently).
                const $addBtn = $matrixContainer.find(`.buttons [data-type="${searchHandle}"], .flex-inline [data-type="${searchHandle}"], .menu [data-type="${searchHandle}"]`);

                if ($addBtn.length) {
                    $addBtn.trigger('click').trigger('activate');
                    await delay(500);
                } else {
                    console.warn('Matrix block type button not found for: ' + block.type);
                }
            }
            
            Craft.cp.displayNotice('Pattern inserted.');
        }
    });

    $(function() {
        new PatternInserter();
    });

})(jQuery);
