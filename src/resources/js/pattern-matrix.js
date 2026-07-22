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
            
            // Inject global CSS once to hide default buttons
            if (!this.cssInjected) {
                $('<style>.site7-matrix-override .buttons > *:not(.site7-btn-group), .site7-matrix-override .flex-inline > *:not(.site7-btn-group) { display: none !important; } .site7-matrix-override .site7-btn-group, .site7-matrix-override .site7-add-block-btn, .site7-matrix-override .site7-insert-pattern-btn { display: flex !important; }</style>').appendTo(document.head);
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
            
            // Create "Add Section" and "Insert Pattern" buttons
            const $addBlockBtn = $('<div class="btn dashed add icon site7-add-block-btn" style="flex: 1; justify-content: center; border-color: #5b32d5; color: #5b32d5; cursor: pointer; padding: 12px; font-weight: bold; text-align: center;">Add Section</div>');
            const $insertPatternBtn = $('<div class="btn dashed add icon site7-insert-pattern-btn" style="flex: 1; justify-content: center; border-color: #5b32d5; color: #5b32d5; cursor: pointer; padding: 12px; font-weight: bold; text-align: center;">Insert Pattern</div>');
            
            $btnGroup.append($addBlockBtn).append($insertPatternBtn);
            
            // Append directly to the buttons container
            $btnContainer.append($btnGroup);
            
            $matrixContainer.addClass('site7-matrix-override');
            $matrixContainer.closest('.field').addClass('site7-matrix-override');

            // Bind clicks
            $addBlockBtn.on('click', $.proxy(function(e) { this.openPatternModal($matrixContainer, 'section', e); }, this));
            $insertPatternBtn.on('click', $.proxy(function(e) { this.openPatternModal($matrixContainer, 'pattern', e); }, this));
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
                if (typeId != null && createAttributes.typeId != null && Number(createAttributes.typeId) !== Number(typeId)) {
                    return null;
                }
                return createAttributes;
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

            // If Craft 4 MatrixInput
            if (matrixInstance) {
                const $addBtn = matrixInstance.$container.find(`.buttons .btn[data-type="${searchHandle}"]`);
                if ($addBtn.length) {
                    $addBtn.trigger('click').trigger('activate');
                    Craft.cp.displayNotice('Section inserted.');
                    return;
                }
            }

            // Fallback: Look up by attribute data-type inside container buttons, or page menus
            let $addBtn = $matrixContainer.find(`.buttons [data-type="${searchHandle}"], .flex-inline [data-type="${searchHandle}"]`);
            if ($addBtn.length === 0) {
                $addBtn = $(`.menu [data-type="${searchHandle}"], [data-type="${searchHandle}"]`);
            }

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

                // Fallback
                let $addBtn = $matrixContainer.find(`.buttons [data-type="${searchHandle}"], .flex-inline [data-type="${searchHandle}"]`);
                if ($addBtn.length === 0) {
                    $addBtn = $(`.menu [data-type="${searchHandle}"], [data-type="${searchHandle}"]`);
                }

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
