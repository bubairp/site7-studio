/**
 * Site7 Studio - Pattern Browser Modal
 */
(function($) {
    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }


    const Site7PatternBrowser = Garnish.Modal.extend({
        $body: null,
        $sidebar: null,
        $main: null,
        $grid: null,
        $grid: null,
        $search: null,
        $tabs: null,
        packages: [],
        activeTab: 'section',
        activeCategory: 'all',
        searchQuery: '',

        init: function(defaultTab, onSelectCallback) {
            if (typeof defaultTab === 'function') {
                this.onSelectCallback = defaultTab;
                this.activeTab = 'section';
            } else {
                this.onSelectCallback = onSelectCallback;
                this.activeTab = defaultTab || 'section';
            }
            
            // Garnish.Modal clears the container's inline width/height/min-width/min-height
            // to "" every time it measures/positions the modal (see updateSizeAndPosition() in
            // garnish.js), so sizing set via inline style here is wiped out and the modal ends up
            // shrink-wrapped to whatever content happens to be rendered at that moment (e.g. the
            // loading spinner) instead of the intended 90vw x 90vh. A real stylesheet rule survives
            // that reset (only the inline override is cleared), so the size is declared there instead.
            // Craft's own `.modal:not(.fitted, .fullscreen)` rule (width/height: 66%) has higher
            // specificity (two classes) than a plain `.site7-pattern-browser-modal` selector, so it
            // silently wins the cascade and overrides our intended size. Matching that specificity
            // here (two classes) is what actually lets our size take effect.
            if ($('#site7-pattern-browser-modal-styles').length === 0) {
                $('<style id="site7-pattern-browser-modal-styles">.modal.site7-pattern-browser-modal { width: 90vw; height: 90vh; max-width: 1200px; }</style>').appendTo(document.head);
            }

            // Build Modal HTML matching Craft CMS native cs-modal structure.
            // Starts at opacity:0 because Garnish.Modal's show() only fades the container in
            // *after* it's already appended and visible on the page (see show() in garnish.js) —
            // without this, the fully-built, unpositioned container flashes on screen for a frame
            // before Garnish takes over and fades it in, which reads as a "blink" on open.
            const $container = $('<div class="modal cs-modal site7-pattern-browser-modal" style="padding: 0; display: flex; flex-direction: column; overflow: hidden; opacity: 0;"></div>').appendTo($(document.body));
            
            // Header
            const $header = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #e1e5ea); background: var(--bg-color, #fff);"></div>').appendTo($container);
            
            const $headerLeft = $('<div style="display: flex; align-items: center; gap: 30px;"></div>').appendTo($header);
            $headerLeft.append('<h2 class="h3" style="margin: 0;">Site7 Content Browser</h2>');
            
            // Tabs
            this.$tabs = $('<div class="site7-tabs flex gap-xs" style="display: flex;"></div>').appendTo($headerLeft);
            this.$tabs.append(`<button type="button" class="btn ${this.activeTab === 'section' ? 'active' : ''}" data-tab="section">Sections</button>`);
            this.$tabs.append(`<button type="button" class="btn ${this.activeTab === 'pattern' ? 'active' : ''}" data-tab="pattern">Patterns</button>`);
            this.$tabs.append(`<button type="button" class="btn ${this.activeTab === 'template' ? 'active' : ''}" data-tab="template">Templates</button>`);
            
            // Search & Close Group
            const $headerRight = $('<div style="display: flex; align-items: center; gap: 16px;"></div>').appendTo($header);
            const $searchContainer = $('<div class="texticon search icon clearable" style="width: 250px; margin: 0;"></div>').appendTo($headerRight);
            this.$search = $('<input type="text" class="text fullwidth" placeholder="Search content..." aria-label="Search">').appendTo($searchContainer);
            
            const $closeBtn = $('<button type="button" class="btn" style="padding: 6px 12px;">Close</button>').appendTo($headerRight);
            
            // Body container
            const $bodyContainer = $('<div class="cs-body" style="display: flex; flex: 1; overflow: hidden; height: 100%;"></div>').appendTo($container);
            
            // Sidebar
            this.$sidebar = $('<div class="cs-sidebar cs-selected-screen" role="navigation"></div>').appendTo($bodyContainer);
            
            // Sidebar Header (matching native Pages/Sources header)
            const $sidebarHeader = $('<div class="cs-header"></div>').appendTo(this.$sidebar);
            $sidebarHeader.append('<h2 class="h3">Categories</h2>');
            
            const $sidebarContent = $('<div class="cs-sidebar-content"></div>').appendTo(this.$sidebar);
            this.$categoryList = $('<ol class="cs-sidebar-list"></ol>').appendTo($sidebarContent);
            
            // Main Content Area
            this.$main = $('<div class="cs-content" style="flex: 1; padding: 24px; overflow-y: auto; background: var(--bg-light-color, #f8fafc); width: 100%; height: 100%; position: relative;"></div>').appendTo($bodyContainer);
            this.$grid = $('<div class="site7-card-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;"></div>').appendTo(this.$main);

            // Loading State. Centered against $main itself (absolute + transform) rather than
            // as a grid item, since $grid only ever sizes to its own content (the spinner) and
            // that content isn't tall enough to center against — the previous version had the
            // spinner sitting near the top of the modal instead of in the middle of it.
            this.$loader = $('<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: flex; justify-content: center; align-items: center;"><div class="spinner big"></div></div>').appendTo(this.$main);
            
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

            this.loadData();
            this.bindEvents();
        },

        bindEvents: function() {
            this.$search.on('input', $.proxy(this, 'onSearch'));
            this.$tabs.on('click', 'button', $.proxy(this, 'onTabSelect'));
            this.$categoryList.on('click', '.cs-item__btn', $.proxy(this, 'onCategorySelect'));
            this.$grid.on('click', '.site7-pattern-insert-btn', $.proxy(this, 'onInsertClick'));
            this.$grid.on('click', '.site7-pattern-preview-btn', $.proxy(this, 'onPreviewClick'));
        },

        onTabSelect: function(e) {
            e.preventDefault();
            if (!this.packages || !this.packages.length) {
                return;
            }
            this.activeTab = $(e.currentTarget).data('tab');
            this.activeCategory = 'all'; // Reset category on tab change
            
            // Update tab UI
            this.$tabs.find('button').removeClass('active');
            $(e.currentTarget).addClass('active');
            
            this.renderCategories();
            this.renderGrid();
        },

        loadData: function() {
            Craft.postActionRequest('site7-studio/package-action/get-browser-data', { type: 'all' }, $.proxy(function(response, textStatus) {
                this.$loader.remove();
                if (textStatus === 'success' && response && response.success) {
                    this.packages = response.packages || [];
                    this.renderCategories();
                    this.renderGrid();
                } else {
                    const errorMsg = (response && response.error) ? response.error : 'Failed to load content.';
                    this.$grid.html(`<p class="error">${errorMsg}</p>`);
                }
            }, this));
        },

        tabLabel: function() {
            if (this.activeTab === 'section') return 'Sections';
            if (this.activeTab === 'pattern') return 'Patterns';
            return 'Templates';
        },

        renderCategories: function() {
            this.$categoryList.empty();
            
            // Add Recently Used category at the top
            this.$categoryList.append(
                `<li class="cs-item ${this.activeCategory === 'recently-used' ? 'sel' : ''}"><div class="cs-item__btn cs-item__page-btn" data-category="recently-used" tabindex="0" role="button"><div class="cp-icon"></div><div class="label">Recently Used</div></div></li>`
            );
            
            const categories = new Set();
            this.packages.forEach(p => {
                if (p.type.toLowerCase() === this.activeTab && p.category) {
                    categories.add(p.category);
                }
            });
            
            const cats = Array.from(categories).sort();
            
            this.$categoryList.append(
                `<li class="cs-item ${this.activeCategory === 'all' ? 'sel' : ''}"><div class="cs-item__btn cs-item__page-btn" data-category="all" tabindex="0" role="button"><div class="cp-icon"></div><div class="label">All ${this.tabLabel()}</div></div></li>`
            );
            
            cats.forEach(c => {
                this.$categoryList.append(
                    `<li class="cs-item ${this.activeCategory === c ? 'sel' : ''}"><div class="cs-item__btn cs-item__page-btn" data-category="${c}" tabindex="0" role="button"><div class="cp-icon"></div><div class="label">${c}</div></div></li>`
                );
            });
        },

        renderGrid: function() {
            this.$grid.empty();
            
            let filtered = [];
            if (this.activeCategory === 'recently-used') {
                let recentlyUsed = [];
                try {
                    recentlyUsed = JSON.parse(localStorage.getItem('site7-recently-used') || '[]');
                } catch(err) {}
                
                recentlyUsed = recentlyUsed.filter(item => item && item.handle && item.type);
                
                const recentlyUsedHandles = recentlyUsed
                    .filter(item => item.type === this.activeTab)
                    .map(item => item.handle);
                
                recentlyUsedHandles.forEach(h => {
                    const pkg = this.packages.find(p => p.handle === h);
                    if (pkg && pkg.type.toLowerCase() === this.activeTab) {
                        filtered.push(pkg);
                    }
                });
            } else {
                filtered = this.packages.filter(p => {
                    if (p.type.toLowerCase() !== this.activeTab) return false;
                    if (this.activeCategory !== 'all' && p.category !== this.activeCategory) return false;
                    return true;
                });
            }
            
            // Search filter across: Name, Description, Category, Tags
            if (this.searchQuery) {
                const q = this.searchQuery.toLowerCase();
                filtered = filtered.filter(p => {
                    const nameMatch = (p.name || '').toLowerCase().includes(q);
                    const descMatch = (p.description || '').toLowerCase().includes(q);
                    const catMatch = (p.category || '').toLowerCase().includes(q);
                    
                    let tagsMatch = false;
                    if (Array.isArray(p.tags)) {
                        tagsMatch = p.tags.some(t => (t || '').toLowerCase().includes(q));
                    }
                    
                    return nameMatch || descMatch || catMatch || tagsMatch;
                });
            }
            
            if (filtered.length === 0) {
                this.$grid.html('<p class="light" style="grid-column: 1 / -1; text-align: center; padding: 40px;">No content found.</p>');
                return;
            }
            
            filtered.forEach(p => {
                let includedHtml = '';
                if (p.type.toLowerCase() === 'template' && p.requires) {
                    const patterns = Array.isArray(p.requires.patterns) ? p.requires.patterns : [];
                    const sections = Array.isArray(p.requires.sections) ? p.requires.sections : [];
                    if (patterns.length) {
                        includedHtml += `<div style="margin-bottom: 4px; font-size: 12px; color: #6b7a8a;"><strong>Included Patterns:</strong> ${patterns.join(', ')}</div>`;
                    }
                    if (sections.length) {
                        includedHtml += `<div style="margin-bottom: 8px; font-size: 12px; color: #6b7a8a;"><strong>Included Sections:</strong> ${sections.join(', ')}</div>`;
                    }
                }

                const $card = $(`
                    <div class="site7-card" style="display: flex; flex-direction: column; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
                        <div class="site7-card-image" style="height: 160px; background: #f3f5f8; border-bottom: 1px solid #e1e5ea; position: relative;">
                            <img src="${p.previewImageUrl}" alt="${p.name}" loading="lazy" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none';">
                        </div>
                        <div class="site7-card-body" style="padding: 16px; flex: 1; display: flex; flex-direction: column;">
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 11px; text-transform: uppercase; color: #8f98a3; font-weight: 600;">${p.category}</span>
                            </div>
                            <h4 style="margin: 0 0 8px 0; font-size: 15px; color: #3f4d5a;">${p.name}</h4>
                            <p style="margin: 0 0 16px 0; font-size: 13px; color: #6b7a8a; flex: 1;">${p.description || 'No description.'}</p>
                            ${includedHtml}
                            <div style="display: flex; gap: 8px; margin-top: auto;">
                                <button type="button" class="btn site7-pattern-preview-btn" data-url="${p.renderUrl}" style="flex: 1; justify-content: center;">Preview</button>
                                <button type="button" class="btn submit site7-pattern-insert-btn" data-handle="${p.handle}" data-type="${p.type.toLowerCase()}" data-block-type-handle="${p.blockTypeHandle || ''}" data-block-type-id="${p.blockTypeId || ''}" style="flex: 1; justify-content: center;">Insert</button>
                            </div>
                        </div>
                    </div>
                `);

                this.$grid.append($card);
            });
        },

        onSearch: function(e) {
            this.searchQuery = ($(e.currentTarget).val() || '').toLowerCase();
            this.renderGrid();
        },

        onCategorySelect: function(e) {
            e.preventDefault();
            if (!this.packages || !this.packages.length) {
                return;
            }
            this.activeCategory = $(e.currentTarget).data('category');
            this.renderCategories(); // update active state
            this.renderGrid();
        },

        onPreviewClick: function(e) {
            e.preventDefault();
            const url = $(e.currentTarget).data('url');
            
            // Open a nested modal or takeover for preview using native Craft CMS cs-modal design
            const $previewContainer = $('<div class="modal cs-modal site7-pattern-preview-modal" style="width: 95vw; height: 95vh; max-width: 1400px; padding: 0; display: flex; flex-direction: column; overflow: hidden;"></div>').appendTo($(document.body));
            
            const $header = $('<div class="cs-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #e1e5ea); background: var(--bg-color, #fff);"></div>').appendTo($previewContainer);
            $header.append('<h2 class="h3" style="margin: 0;">Component Preview</h2>');
            const $closeBtn = $('<button type="button" class="btn" style="padding: 6px 12px;">Close</button>').appendTo($header);
            
            const $bodyContainer = $('<div class="cs-body" style="display: flex; flex: 1; overflow: hidden; height: 100%; background: var(--bg-light-color, #f8fafc);"></div>').appendTo($previewContainer);
            const $iframeContainer = $('<div class="cs-content" style="flex: 1; overflow: hidden; position: relative; padding: 0; height: 100%; width: 100%;"></div>').appendTo($bodyContainer);
            $iframeContainer.append(`<iframe src="${url}" style="width: 100%; height: 100%; border: none;"></iframe>`);
            
            const previewModal = new Garnish.Modal($previewContainer, {
                resizable: true,
                autoShow: true,
                fade: true
            });
            
            previewModal.on('hide', function() {
                setTimeout(() => {
                    previewModal.destroy();
                }, 300);
            });
            
            $closeBtn.on('click', function() {
                previewModal.hide();
            });
        },

        onInsertClick: function(e) {
            e.preventDefault();
            const handle = $(e.currentTarget).data('handle');
            const type = $(e.currentTarget).data('type');
            const blockTypeHandle = $(e.currentTarget).data('block-type-handle');
            const blockTypeId = $(e.currentTarget).data('block-type-id') || null;

            // Save to recently used in localStorage
            let recentlyUsed = [];
            try {
                recentlyUsed = JSON.parse(localStorage.getItem('site7-recently-used') || '[]');
            } catch(err) {}
            
            recentlyUsed = recentlyUsed.filter(item => item && item.handle && item.type);
            recentlyUsed = recentlyUsed.filter(item => item.handle !== handle);
            recentlyUsed.unshift({ handle: handle, type: type, timestamp: Date.now() });
            recentlyUsed = recentlyUsed.slice(0, 10);
            localStorage.setItem('site7-recently-used', JSON.stringify(recentlyUsed));
            
            this.hide();
            
            if (this.onSelectCallback) {
                this.onSelectCallback(handle, type, blockTypeHandle, blockTypeId);
            }
        }
    });

    // Expose globally for pattern-matrix.js
    window.Site7PatternBrowser = Site7PatternBrowser;

})(jQuery);
