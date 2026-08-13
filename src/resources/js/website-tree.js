/**
 * Site7 Studio – Website Tree (Phase 9.2)
 *
 * A reusable, standalone component that mirrors Craft's own native hierarchy
 * (Singles/Channels/Structures/Categories) - no dependency on
 * resource-import-wizard.js internals, so it can be reused as-is by future
 * screens (Import Existing Website, Starter Kit Details, Synchronization/
 * Installation Preview - see Phase 9.2's "Website Tree Component" scope).
 * Only jQuery + the global `Craft` object (escapeHtml/getCpUrl/t) are
 * assumed, the same baseline every other bespoke JS file in this plugin
 * already relies on.
 *
 * Usage: Site7WebsiteTree.render($container, treeData, options)
 *   treeData: {singles, channels, structures, categories} - the exact shape
 *     WebsiteTreeService::buildTree() / `resource-import/get-website-tree`
 *     returns.
 *   options: {
 *     selectionMode: 'single'|'multiple'|'grouped-multiple' (default 'single'):
 *       'grouped-multiple' (Phase 9.3, "Import Existing Website") adds a
 *       top-level "Website" checkbox plus one per group heading (Singles/
 *       each Channel/each Structure), each cascading to every (non-locked)
 *       descendant entry and reflecting an indeterminate state on partial
 *       selection - Categories stay browse-only (never selectable) in every
 *       mode, per this phase's "Categories are for context, not import"
 *       scoping.
 *     showImportStatus: bool (default false) - render Imported/Update
 *       Available badges + disable already-imported rows + an Open
 *       Package/Review Update link, using item.importStatus/
 *       existingPackageHandle (set by WebsiteTreeService),
 *     onChange: function(selectedIds: number[]) - called whenever the
 *       selection changes (single-select still passes a 1-length array).
 *   }
 */
(function(global) {
    function escapeHtml(value) {
        return Craft.escapeHtml(String(value == null ? '' : value));
    }

    function importBadgeHtml(item) {
        if (item.importStatus === 'imported') {
            return ' <span class="status-label gray" style="flex-shrink:0;">Imported</span>';
        }
        if (item.importStatus === 'update-available') {
            return ' <span class="status-label amber" style="flex-shrink:0;">Update Available</span>';
        }
        return '';
    }

    function openPackageLinkHtml(item) {
        if (!item.existingPackageHandle) {
            return '';
        }
        var url = Craft.getCpUrl('site7-studio/packages/' + item.existingPackageHandle + '/edit');
        var label = item.importStatus === 'update-available' ? 'Review Update' : 'Open Package';
        return ' <a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" style="flex-shrink:0;">' + label + ' &rarr;</a>';
    }

    function Tree($container, treeData, options) {
        this.$container = $container;
        this.treeData = treeData;
        this.options = $.extend({
            selectionMode: 'single',
            showImportStatus: false,
            readOnly: false,
            name: 'site7-tree-select-' + Math.random().toString(36).slice(2),
            onChange: null,
        }, options || {});
        this.selectedIds = [];
        this.groups = [];
        this.entryDisabledById = {};
        this.render();
    }

    Tree.prototype.isGrouped = function() {
        return this.options.selectionMode === 'grouped-multiple';
    };

    Tree.prototype.render = function() {
        var self = this;
        this.$container.empty().addClass('site7-website-tree-root');
        this.groups = [];
        this.entryDisabledById = {};

        if (this.isGrouped()) {
            var $websiteRow = $('<div class="site7-tree-row" style="display:flex; align-items:center; gap:6px; padding:3px 4px; font-weight:600;"></div>').appendTo(this.$container);
            this.$websiteCheckbox = $('<input type="checkbox">').appendTo($websiteRow);
            $websiteRow.append('<span>Website</span>');
            this.$websiteCheckbox.on('change', function() {
                self.setGroupChecked(self.allEntryIds(), $(this).is(':checked'));
            });
        }

        this.$search = $('<input type="text" class="text fullwidth" placeholder="Search pages...">').appendTo(this.$container);
        this.$scroll = $('<div class="site7-website-tree" style="margin-top:8px; max-height:420px; overflow-y:auto; border:1px solid var(--hairline-color,#e1e5ea); border-radius:6px; padding:8px;"></div>').appendTo(this.$container);

        this.renderEntryGroup('Singles', this.treeData.singles || [], false);

        (this.treeData.channels || []).forEach(function(section) {
            self.renderEntryGroup(section.name, section.entries || [], false);
        });

        (this.treeData.structures || []).forEach(function(section) {
            self.renderEntryGroup(section.name, section.entries || [], true);
        });

        (this.treeData.categories || []).forEach(function(group) {
            self.renderCategoryGroup(group.name, group.terms || []);
        });

        this.$search.on('input', function() {
            self.applySearch($(this).val().trim().toLowerCase());
        });

        this.recomputeGroupStates();
    };

    Tree.prototype.renderGroupHeading = function(label, count, entryIds) {
        var $heading = $('<div class="site7-tree-group-heading" style="display:flex; align-items:center; gap:6px; font-weight:600; margin:10px 0 4px;"></div>').appendTo(this.$scroll);
        var self = this;

        if (this.isGrouped() && entryIds) {
            var $checkbox = $('<input type="checkbox">').appendTo($heading);
            $checkbox.on('change', function() {
                self.setGroupChecked(entryIds, $(this).is(':checked'));
            });
            this.groups.push({ checkbox: $checkbox, ids: entryIds });
        }

        $heading.append(escapeHtml(label) + ' <span class="light">&middot; ' + count + '</span>');
        return $heading;
    };

    /**
     * @return {number[]} every entry id (recursively) under this group,
     *   including locked ones - used for the group/Website checkbox's
     *   cascade target list; locked ids are simply skipped when actually
     *   toggling (see setGroupChecked).
     */
    Tree.prototype.collectEntryIds = function(entries) {
        var self = this;
        var ids = [];
        entries.forEach(function(entry) {
            ids.push(entry.id);
            if (entry.children && entry.children.length) {
                ids = ids.concat(self.collectEntryIds(entry.children));
            }
        });
        return ids;
    };

    Tree.prototype.allEntryIds = function() {
        var ids = [];
        this.groups.forEach(function(g) {
            ids = ids.concat(g.ids);
        });
        return ids;
    };

    Tree.prototype.renderEntryGroup = function(label, entries, nested) {
        if (!entries.length) {
            return;
        }
        var entryIds = this.isGrouped() ? this.collectEntryIds(entries) : null;
        this.renderGroupHeading(label, entries.length, entryIds);
        var $ul = $('<ul class="site7-tree-list" style="list-style:none; margin:0; padding-left:0;"></ul>').appendTo(this.$scroll);
        var self = this;
        entries.forEach(function(entry) {
            $ul.append(self.renderEntryNode(entry, 0, nested));
        });
    };

    Tree.prototype.renderEntryNode = function(entry, depth, nested) {
        var self = this;
        var isLocked = entry.importStatus === 'imported' || entry.importStatus === 'update-available';
        var hasChildren = nested && entry.children && entry.children.length;
        var inputType = this.options.selectionMode === 'single' ? 'radio' : 'checkbox';

        var $li = $('<li class="site7-tree-node" data-search="' + escapeHtml((entry.title + ' ' + (entry.slug || '')).toLowerCase()) + '" style="margin:2px 0;"></li>');
        var $row = $('<div class="site7-tree-row" style="display:flex; align-items:center; gap:6px; padding:3px 4px; border-radius:4px;"></div>').appendTo($li);
        $row.css('padding-left', (depth * 18) + 'px');

        if (hasChildren) {
            var $caret = $('<a class="site7-tree-caret" href="#" style="flex-shrink:0; width:14px; text-align:center;">&#9656;</a>').appendTo($row);
            $caret.on('click', function(e) {
                e.preventDefault();
                var $children = $li.children('ul');
                var expanded = $children.is(':visible');
                $children.toggle(!expanded);
                $caret.html(expanded ? '&#9656;' : '&#9662;');
            });
        } else {
            $('<span style="display:inline-block; width:14px; flex-shrink:0;"></span>').appendTo($row);
        }

        if (this.options.readOnly) {
            // Phase 9.3: Starter Kit Details' browse-only mode - no
            // selection at all, just a static checkmark for membership
            // (entry.included, set by WebsiteTreeService::markIncluded()).
            var mark = entry.included ? '&#10003;' : '<span class="light">&mdash;</span>';
            $('<span style="display:inline-block; width:16px; flex-shrink:0; text-align:center;">' + mark + '</span>').appendTo($row);
            $row.append('<span class="site7-tree-title' + (entry.included ? '' : ' light') + '">' + escapeHtml(entry.title) + '</span>');
        } else {
            var $input = $('<input type="' + inputType + '" name="' + this.options.name + '" value="' + entry.id + '"' + (isLocked ? ' disabled' : '') + '>').appendTo($row);
            this.entryDisabledById[entry.id] = isLocked;
            $row.append(
                '<span class="site7-tree-title">' + escapeHtml(entry.title) + '</span>' +
                (this.options.showImportStatus ? importBadgeHtml(entry) : '') +
                (this.options.showImportStatus ? openPackageLinkHtml(entry) : '')
            );

            $input.on('change', function() {
                self.updateSelection($(this).val(), $(this).is(':checked'));
                if (self.isGrouped()) {
                    self.recomputeGroupStates();
                }
            });
        }

        if (hasChildren) {
            var $childUl = $('<ul class="site7-tree-list" style="list-style:none; margin:0; padding-left:0;"></ul>').appendTo($li);
            entry.children.forEach(function(child) {
                $childUl.append(self.renderEntryNode(child, depth + 1, true));
            });
        }

        return $li;
    };

    Tree.prototype.renderCategoryGroup = function(label, terms) {
        if (!terms.length) {
            return;
        }
        this.renderGroupHeading(label, terms.length);
        var $ul = $('<ul class="site7-tree-list" style="list-style:none; margin:0; padding-left:0;"></ul>').appendTo(this.$scroll);
        var self = this;
        terms.forEach(function(term) {
            $ul.append(self.renderCategoryNode(term, 0));
        });
    };

    Tree.prototype.renderCategoryNode = function(term, depth) {
        var self = this;
        var $li = $('<li class="site7-tree-node" data-search="' + escapeHtml((term.title + ' ' + (term.slug || '')).toLowerCase()) + '" style="margin:2px 0;"></li>');
        var $row = $('<div class="site7-tree-row light" style="display:flex; align-items:center; gap:6px; padding:3px 4px;"></div>').appendTo($li);
        $row.css('padding-left', (depth * 18 + 20) + 'px');
        $row.append('<span>' + escapeHtml(term.title) + '</span>');

        if (term.children && term.children.length) {
            var $childUl = $('<ul class="site7-tree-list" style="list-style:none; margin:0; padding-left:0;"></ul>').appendTo($li);
            term.children.forEach(function(child) {
                $childUl.append(self.renderCategoryNode(child, depth + 1));
            });
        }

        return $li;
    };

    Tree.prototype.updateSelection = function(id, checked) {
        id = parseInt(id, 10);
        if (this.options.selectionMode === 'single') {
            this.selectedIds = checked ? [id] : [];
        } else {
            var idx = this.selectedIds.indexOf(id);
            if (checked && idx === -1) {
                this.selectedIds.push(id);
            } else if (!checked && idx !== -1) {
                this.selectedIds.splice(idx, 1);
            }
        }
        if (typeof this.options.onChange === 'function') {
            this.options.onChange(this.selectedIds.slice());
        }
    };

    /**
     * Cascades a group/Website checkbox toggle onto every (non-locked) entry
     * checkbox among `ids`, updating this.selectedIds and firing onChange
     * once - locked (already-imported) rows are silently skipped, exactly as
     * they behave for a direct click.
     */
    Tree.prototype.setGroupChecked = function(ids, checked) {
        var self = this;
        ids.forEach(function(id) {
            if (self.entryDisabledById[id]) {
                return;
            }
            var $input = self.$scroll.find('input[name="' + self.options.name + '"][value="' + id + '"]');
            $input.prop('checked', checked);
            var idx = self.selectedIds.indexOf(id);
            if (checked && idx === -1) {
                self.selectedIds.push(id);
            } else if (!checked && idx !== -1) {
                self.selectedIds.splice(idx, 1);
            }
        });
        if (typeof this.options.onChange === 'function') {
            this.options.onChange(this.selectedIds.slice());
        }
        this.recomputeGroupStates();
    };

    /**
     * Recomputes every group heading checkbox's checked/indeterminate state
     * (and the Website checkbox's) from the current selection - called after
     * any individual entry checkbox change or group/Website toggle, so all
     * three levels always agree.
     */
    Tree.prototype.recomputeGroupStates = function() {
        if (!this.isGrouped()) {
            return;
        }
        var self = this;

        this.groups.forEach(function(group) {
            var selectable = group.ids.filter(function(id) { return !self.entryDisabledById[id]; });
            var selectedCount = selectable.filter(function(id) { return self.selectedIds.indexOf(id) !== -1; }).length;

            group.checkbox.prop('disabled', selectable.length === 0);
            group.checkbox.prop('checked', selectable.length > 0 && selectedCount === selectable.length);
            group.checkbox.prop('indeterminate', selectedCount > 0 && selectedCount < selectable.length);
        });

        if (this.$websiteCheckbox) {
            var allIds = this.allEntryIds();
            var allSelectable = allIds.filter(function(id) { return !self.entryDisabledById[id]; });
            var allSelectedCount = allSelectable.filter(function(id) { return self.selectedIds.indexOf(id) !== -1; }).length;

            this.$websiteCheckbox.prop('disabled', allSelectable.length === 0);
            this.$websiteCheckbox.prop('checked', allSelectable.length > 0 && allSelectedCount === allSelectable.length);
            this.$websiteCheckbox.prop('indeterminate', allSelectedCount > 0 && allSelectedCount < allSelectable.length);
        }
    };

    Tree.prototype.applySearch = function(term) {
        this.$scroll.find('.site7-tree-node').each(function() {
            var $node = $(this);
            var matches = !term || $node.data('search').toString().indexOf(term) !== -1;
            var childMatches = !!$node.find('.site7-tree-node').filter(function() {
                return !term || $(this).data('search').toString().indexOf(term) !== -1;
            }).length;
            var visible = matches || childMatches;
            $node.toggle(visible);
            if (visible && childMatches && term) {
                $node.children('ul').show();
                $node.find('.site7-tree-caret').first().html('&#9662;');
            }
        });
        this.$scroll.find('.site7-tree-group-heading').each(function() {
            var $heading = $(this);
            var $list = $heading.next('.site7-tree-list');
            $heading.toggle($list.children(':visible').length > 0);
        });
    };

    global.Site7WebsiteTree = {
        /**
         * @returns {Tree} instance - keep the return value if you need to
         *   read `.selectedIds` later (e.g. on a Save button click) rather
         *   than only reacting via options.onChange.
         */
        render: function($container, treeData, options) {
            return new Tree($container, treeData, options);
        },
    };
})(window);
