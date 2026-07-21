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
            
            // Build Modal HTML
            const $container = $('<div class="modal site7-pattern-browser-modal" style="width: 90vw; height: 90vh; max-width: 1200px; padding: 0; display: flex; flex-direction: column; overflow: hidden; background: #f8fafc;"></div>').appendTo($(document.body));
            
            // Header
            const $header = $('<div class="header" style="padding: 20px 24px; background: #fff; border-bottom: 1px solid #e1e5ea; display: flex; justify-content: space-between; align-items: center;"></div>').appendTo($container);
            
            const $headerLeft = $('<div style="display: flex; align-items: center; gap: 30px;"></div>').appendTo($header);
            $headerLeft.append('<h2 style="margin: 0; font-size: 18px; color: #3f4d5a;">Site7 Content Browser</h2>');
            
            // Tabs
            this.$tabs = $('<div class="site7-tabs" style="display: flex; gap: 20px;"></div>').appendTo($headerLeft);
            
            const sectionStyle = this.activeTab === 'section'
                ? 'text-decoration: none; font-weight: bold; color: #5b32d5; border-bottom: 2px solid #5b32d5; padding-bottom: 4px;'
                : 'text-decoration: none; color: #8f98a3; padding-bottom: 4px;';
                
            const patternStyle = this.activeTab === 'pattern'
                ? 'text-decoration: none; font-weight: bold; color: #5b32d5; border-bottom: 2px solid #5b32d5; padding-bottom: 4px;'
                : 'text-decoration: none; color: #8f98a3; padding-bottom: 4px;';
                
            this.$tabs.append(`<a href="#" data-tab="section" style="${sectionStyle}">Sections</a>`);
            this.$tabs.append(`<a href="#" data-tab="pattern" style="${patternStyle}">Patterns</a>`);
            
            const $searchContainer = $('<div class="texticon search icon clearable" style="width: 300px;"></div>').appendTo($header);
            this.$search = $('<input type="text" class="text fullwidth" placeholder="Search content..." aria-label="Search">').appendTo($searchContainer);
            
            // Body container
            const $bodyContainer = $('<div class="body" style="display: flex; flex: 1; overflow: hidden;"></div>').appendTo($container);
            
            // Sidebar
            this.$sidebar = $('<div class="sidebar" style="width: 220px; background: #fff; border-right: 1px solid #e1e5ea; padding: 20px; overflow-y: auto;"></div>').appendTo($bodyContainer);
            this.$sidebar.append('<h3 style="font-size: 11px; text-transform: uppercase; color: #8f98a3; margin-bottom: 10px; letter-spacing: 0.5px;">Categories</h3>');
            this.$categoryList = $('<ul style="list-style: none; padding: 0; margin: 0;"></ul>').appendTo(this.$sidebar);
            
            // Main Content
            this.$main = $('<div class="main" style="flex: 1; padding: 24px; overflow-y: auto;"></div>').appendTo($bodyContainer);
            this.$grid = $('<div class="site7-card-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;"></div>').appendTo(this.$main);
            
            // Loading State
            this.$grid.append('<div class="spinner big"></div>');
            
            this.base($container, {
                resizable: false,
                autoShow: true
            });
            
            this.loadData();
            this.bindEvents();
        },

        bindEvents: function() {
            this.$search.on('input', $.proxy(this, 'onSearch'));
            this.$tabs.on('click', 'a', $.proxy(this, 'onTabSelect'));
            this.$categoryList.on('click', 'a', $.proxy(this, 'onCategorySelect'));
            this.$grid.on('click', '.site7-pattern-insert-btn', $.proxy(this, 'onInsertClick'));
            this.$grid.on('click', '.site7-pattern-preview-btn', $.proxy(this, 'onPreviewClick'));
        },

        onTabSelect: function(e) {
            e.preventDefault();
            this.activeTab = $(e.currentTarget).data('tab');
            this.activeCategory = 'all'; // Reset category on tab change
            
            // Update tab UI
            this.$tabs.find('a').css({ 'color': '#8f98a3', 'border-bottom': 'none', 'font-weight': 'normal' });
            $(e.currentTarget).css({ 'color': '#5b32d5', 'border-bottom': '2px solid #5b32d5', 'font-weight': 'bold' });
            
            this.renderCategories();
            this.renderGrid();
        },

        loadData: function() {
            Craft.postActionRequest('site7-studio/package-action/get-browser-data', { type: 'all' }, $.proxy(function(response, textStatus) {
                if (textStatus === 'success' && response.success) {
                    this.packages = response.packages || [];
                    this.renderCategories();
                    this.renderGrid();
                } else {
                    this.$grid.html('<p class="error">Failed to load content.</p>');
                }
            }, this));
        },

        renderCategories: function() {
            this.$categoryList.empty();
            
            // Add Recently Used category at the top
            this.$categoryList.append(
                `<li><a href="#" data-category="recently-used" style="display: block; padding: 8px 12px; border-radius: 4px; color: #3f4d5a; text-decoration: none; ${this.activeCategory === 'recently-used' ? 'background: #f3f5f8; font-weight: bold;' : ''}">Recently Used</a></li>`
            );
            
            const categories = new Set();
            this.packages.forEach(p => {
                if (p.type.toLowerCase() === this.activeTab && p.category) {
                    categories.add(p.category);
                }
            });
            
            const cats = Array.from(categories).sort();
            
            this.$categoryList.append(
                `<li><a href="#" data-category="all" style="display: block; padding: 8px 12px; border-radius: 4px; color: #3f4d5a; text-decoration: none; ${this.activeCategory === 'all' ? 'background: #f3f5f8; font-weight: bold;' : ''}">All ${this.activeTab === 'section' ? 'Sections' : 'Patterns'}</a></li>`
            );
            
            cats.forEach(c => {
                this.$categoryList.append(
                    `<li><a href="#" data-category="${c}" style="display: block; padding: 8px 12px; border-radius: 4px; color: #3f4d5a; text-decoration: none; ${this.activeCategory === c ? 'background: #f3f5f8; font-weight: bold;' : ''}">${c}</a></li>`
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
                            
                            <div style="display: flex; gap: 8px; margin-top: auto;">
                                <button type="button" class="btn site7-pattern-preview-btn" data-url="${p.renderUrl}" style="flex: 1; justify-content: center;">Preview</button>
                                <button type="button" class="btn submit site7-pattern-insert-btn" data-handle="${p.handle}" data-type="${p.type.toLowerCase()}" data-block-type-handle="${p.blockTypeHandle || ''}" style="flex: 1; justify-content: center;">Insert</button>
                            </div>
                        </div>
                    </div>
                `);
                
                this.$grid.append($card);
            });
        },

        onSearch: function(e) {
            this.searchQuery = $(e.currentTarget).val().toLowerCase();
            this.renderGrid();
        },

        onCategorySelect: function(e) {
            e.preventDefault();
            this.activeCategory = $(e.currentTarget).data('category');
            this.renderCategories(); // update active state
            this.renderGrid();
        },

        onPreviewClick: function(e) {
            e.preventDefault();
            const url = $(e.currentTarget).data('url');
            
            // Open a nested modal or takeover for preview
            const $previewContainer = $('<div class="modal site7-pattern-preview-modal" style="width: 95vw; height: 95vh; max-width: 1400px; padding: 0; display: flex; flex-direction: column; background: #fff;"></div>').appendTo($(document.body));
            
            const $header = $('<div class="header" style="padding: 16px 24px; border-bottom: 1px solid #e1e5ea; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;"></div>').appendTo($previewContainer);
            $header.append('<h2 style="margin: 0; font-size: 16px;">Pattern Preview</h2>');
            const $closeBtn = $('<button type="button" class="btn">Close Preview</button>').appendTo($header);
            
            const $iframeContainer = $('<div style="flex: 1; overflow: hidden; position: relative;"></div>').appendTo($previewContainer);
            $iframeContainer.append(`<iframe src="${url}" style="width: 100%; height: 100%; border: none;"></iframe>`);
            
            const previewModal = new Garnish.Modal($previewContainer, {
                resizable: true,
                autoShow: true
            });
            
            $closeBtn.on('click', function() {
                previewModal.hide();
                setTimeout(() => {
                    previewModal.destroy();
                    $previewContainer.remove();
                }, 300);
            });
        },

        onInsertClick: function(e) {
            e.preventDefault();
            const handle = $(e.currentTarget).data('handle');
            const type = $(e.currentTarget).data('type');
            const blockTypeHandle = $(e.currentTarget).data('block-type-handle');
            
            // Save to recently used in localStorage
            let recentlyUsed = [];
            try {
                recentlyUsed = JSON.parse(localStorage.getItem('site7-recently-used') || '[]');
            } catch(err) {}
            
            recentlyUsed = recentlyUsed.filter(item => item.handle !== handle);
            recentlyUsed.unshift({ handle: handle, type: type, timestamp: Date.now() });
            recentlyUsed = recentlyUsed.slice(0, 10);
            localStorage.setItem('site7-recently-used', JSON.stringify(recentlyUsed));
            
            this.hide();
            
            if (this.onSelectCallback) {
                this.onSelectCallback(handle, type, blockTypeHandle);
            }
            
            setTimeout($.proxy(function() {
                this.destroy();
                this.$container.remove();
            }, this), 300);
        }
    });

    // Expose globally for pattern-matrix.js
    window.Site7PatternBrowser = Site7PatternBrowser;

})(jQuery);
