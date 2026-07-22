/**
 * Site7 Studio – Library Search, Filter, and Status Navigation
 *
 * Unlike Craft's own element index, this page has no AJAX layer - every
 * search/filter/status/page change is a full navigation carrying the current
 * query string forward. This matches the rest of the plugin (Library, Setup,
 * package actions are all plain GET/POST + redirect), so no client-side
 * duplicate-data-source problem is introduced.
 */
(function() {
    var searchInput = document.getElementById('site7-search-input');
    var filterBtn = document.getElementById('site7-filter-btn');
    var filterMenu = document.getElementById('site7-filter-menu');
    var clearBtn = document.getElementById('site7-clear-filters-btn');
    var statusBtn = document.getElementById('site7-status-menubtn');
    var statusMenu = document.getElementById('site7-status-menu');

    function navigate(mutator) {
        var params = new URLSearchParams(window.location.search);
        mutator(params);
        var query = params.toString();
        window.location.href = window.location.pathname + (query ? '?' + query : '');
    }

    function setOrDelete(params, name, value) {
        if (value) {
            params.set(name, value);
        } else {
            params.delete(name);
        }
        params.delete('page');
    }

    // Craft's own dropdowns (Garnish.MenuBtn) reparent the menu to <body> and
    // position it with JS-computed coordinates, which is what lets them float
    // above everything regardless of any ancestor's stacking context or
    // overflow. Ours has no MenuBtn instance, so it needs the same treatment -
    // toggling display in place isn't enough once the menu is nested inside a
    // container that creates its own stacking context (as the toolbar is here).
    function toggleFloatingMenu(btn, menu, alignRight) {
        var isHidden = menu.style.display !== 'block';
        if (isHidden) {
            if (menu.parentElement !== document.body) {
                document.body.appendChild(menu);
            }
            var rect = btn.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = rect.bottom + 4 + 'px';
            if (alignRight) {
                menu.style.right = (window.innerWidth - rect.right) + 'px';
                menu.style.left = 'auto';
            } else {
                menu.style.left = rect.left + 'px';
                menu.style.right = 'auto';
            }
            menu.style.display = 'block';
        } else {
            menu.style.display = 'none';
        }
    }

    // Status menu
    if (statusBtn && statusMenu) {
        statusBtn.addEventListener('click', function(e) {
            toggleFloatingMenu(statusBtn, statusMenu, false);
            e.stopPropagation();
        });
        statusMenu.querySelectorAll('a[data-status]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                navigate(function(params) {
                    setOrDelete(params, 'status', link.getAttribute('data-status'));
                });
            });
        });
        document.addEventListener('click', function(e) {
            if (statusMenu.style.display === 'block' && !statusMenu.contains(e.target) && e.target !== statusBtn) {
                statusMenu.style.display = 'none';
            }
        });
    }

    // Search (debounced navigation)
    if (searchInput) {
        var searchTimer = null;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            var value = searchInput.value;
            searchTimer = setTimeout(function() {
                navigate(function(params) {
                    setOrDelete(params, 'q', value.trim());
                });
            }, 500);
        });

        var clearIcon = searchInput.parentElement.querySelector('.clear-btn');
        if (clearIcon) {
            if (searchInput.value) {
                clearIcon.classList.remove('hidden');
            }
            clearIcon.addEventListener('click', function() {
                navigate(function(params) {
                    params.delete('q');
                });
            });
        }
    }

    // Filter menu (category/tag/author checkboxes)
    if (filterBtn && filterMenu) {
        filterBtn.addEventListener('click', function(e) {
            toggleFloatingMenu(filterBtn, filterMenu, true);
            e.stopPropagation();
        });
        document.addEventListener('click', function(e) {
            if (filterMenu.style.display === 'block' && !filterMenu.contains(e.target) && e.target !== filterBtn) {
                filterMenu.style.display = 'none';
            }
        });
        filterMenu.addEventListener('change', function() {
            navigate(function(params) {
                ['category', 'tag', 'author'].forEach(function(name) {
                    params.delete(name);
                    filterMenu.querySelectorAll('input[name="' + name + '"]:checked').forEach(function(chk) {
                        params.append(name, chk.value);
                    });
                });
                params.delete('page');
            });
        });
    }

    // Clear filters (search + status + category/tag/author, keeps type/view)
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            navigate(function(params) {
                params.delete('q');
                params.delete('status');
                params.delete('category');
                params.delete('tag');
                params.delete('author');
                params.delete('page');
            });
        });
    }
})();
