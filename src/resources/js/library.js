/**
 * Site7 Studio – Library Filter and Search Controller
 */
(function() {
    var searchInput = document.getElementById('site7-search-input');
    var filterBtn = document.getElementById('site7-filter-btn');
    var filterMenu = document.getElementById('site7-filter-menu');
    var clearBtn = document.getElementById('site7-clear-filters-btn');
    
    if (!searchInput || !filterBtn || !filterMenu) return;

    var cards = document.querySelectorAll('.site7-card');
    
    // Capitalize helper
    function capitalize(str) {
        if (!str) return '';
        return str.split(' ').map(function(word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }

    // 1. Gather dynamic categories, tags, and authors
    var categories = new Set();
    var tags = new Set();
    var authors = new Set();

    cards.forEach(function(card) {
        var cat = card.getAttribute('data-category');
        if (cat && cat !== 'uncategorized') {
            categories.add(cat);
        }
        var author = card.getAttribute('data-author');
        if (author && author !== 'anonymous') {
            authors.add(author);
        }
        var cardTags = card.getAttribute('data-tags');
        if (cardTags) {
            cardTags.split(',').forEach(function(t) {
                var trimmed = t.trim();
                if (trimmed) {
                    tags.add(trimmed);
                }
            });
        }
    });

    // Sort alphabetically
    var sortedCategories = Array.from(categories).sort();
    var sortedTags = Array.from(tags).sort();
    var sortedAuthors = Array.from(authors).sort();

    // Render category checkboxes
    var catContainer = document.getElementById('filter-categories');
    if (catContainer) {
        catContainer.innerHTML = '';
        sortedCategories.forEach(function(cat) {
            var label = document.createElement('label');
            label.style = "display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;";
            label.innerHTML = '<input type="checkbox" name="category" value="' + cat.toLowerCase() + '"> ' + capitalize(cat);
            catContainer.appendChild(label);
        });
    }

    // Render tag checkboxes
    var tagsContainer = document.getElementById('filter-tags');
    if (tagsContainer) {
        tagsContainer.innerHTML = '';
        sortedTags.forEach(function(tag) {
            var label = document.createElement('label');
            label.style = "display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;";
            label.innerHTML = '<input type="checkbox" name="tag" value="' + tag.toLowerCase() + '"> ' + capitalize(tag);
            tagsContainer.appendChild(label);
        });
    }

    // Render author checkboxes
    var authorsContainer = document.getElementById('filter-authors');
    if (authorsContainer) {
        authorsContainer.innerHTML = '';
        sortedAuthors.forEach(function(auth) {
            var label = document.createElement('label');
            label.style = "display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;";
            label.innerHTML = '<input type="checkbox" name="author" value="' + auth.toLowerCase() + '"> ' + capitalize(auth);
            authorsContainer.appendChild(label);
        });
    }

    // 2. Load filters from URL
    function loadFiltersFromUrl() {
        var params = new URLSearchParams(window.location.search);
        
        // Search query
        var q = params.get('q');
        if (q) {
            searchInput.value = q;
            var clearIcon = searchInput.nextElementSibling;
            if (clearIcon && clearIcon.classList.contains('clear')) {
                clearIcon.classList.remove('hidden');
            }
        }

        // Checkboxes
        var paramNames = ['status', 'category', 'tag', 'author'];
        paramNames.forEach(function(name) {
            var vals = params.getAll(name);
            vals.forEach(function(val) {
                var chk = filterMenu.querySelector('input[name="' + name + '"][value="' + val + '"]');
                if (chk) {
                    chk.checked = true;
                }
            });
        });
    }

    // 3. Apply search and filters
    function applyFilters() {
        var query = searchInput.value.toLowerCase().trim();
        
        // Get all checked values
        var checkedStatus = Array.from(filterMenu.querySelectorAll('input[name="status"]:checked')).map(function(c) { return c.value; });
        var checkedCategories = Array.from(filterMenu.querySelectorAll('input[name="category"]:checked')).map(function(c) { return c.value; });
        var checkedTags = Array.from(filterMenu.querySelectorAll('input[name="tag"]:checked')).map(function(c) { return c.value; });
        var checkedAuthors = Array.from(filterMenu.querySelectorAll('input[name="author"]:checked')).map(function(c) { return c.value; });

        var visibleCount = 0;
        var totalCount = cards.length;

        cards.forEach(function(card) {
            // Search query match
            var titleText = card.querySelector('.site7-card-title') ? card.querySelector('.site7-card-title').textContent.toLowerCase() : '';
            var descText = card.querySelector('.site7-card-description') ? card.querySelector('.site7-card-description').textContent.toLowerCase() : '';
            var matchesSearch = !query || titleText.indexOf(query) !== -1 || descText.indexOf(query) !== -1;

            // Status match
            var status = card.getAttribute('data-status');
            var matchesStatus = checkedStatus.length === 0 || checkedStatus.indexOf(status) !== -1;

            // Category match
            var category = card.getAttribute('data-category');
            var matchesCategory = checkedCategories.length === 0 || checkedCategories.indexOf(category) !== -1;

            // Author match
            var author = card.getAttribute('data-author');
            var matchesAuthor = checkedAuthors.length === 0 || checkedAuthors.indexOf(author) !== -1;

            // Tags match
            var cardTags = card.getAttribute('data-tags') ? card.getAttribute('data-tags').split(',') : [];
            var matchesTags = checkedTags.length === 0 || checkedTags.some(function(t) { return cardTags.indexOf(t) !== -1; });

            if (matchesSearch && matchesStatus && matchesCategory && matchesAuthor && matchesTags) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // Toggle no-results container
        var noResults = document.getElementById('site7-no-results');
        if (noResults) {
            noResults.style.display = (visibleCount === 0 && totalCount > 0) ? 'block' : 'none';
        }

        // Update dynamic package counts
        var visibleElem = document.getElementById('site7-count-visible');
        var totalElem = document.getElementById('site7-count-total');
        if (visibleElem) visibleElem.textContent = visibleCount;
        if (totalElem) totalElem.textContent = totalCount;

        // Update URL parameters
        var params = new URLSearchParams();
        if (query) params.set('q', query);
        checkedStatus.forEach(function(val) { params.append('status', val); });
        checkedCategories.forEach(function(val) { params.append('category', val); });
        checkedTags.forEach(function(val) { params.append('tag', val); });
        checkedAuthors.forEach(function(val) { params.append('author', val); });

        var newQueryString = params.toString();
        var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + (newQueryString ? '?' + newQueryString : '');
        window.history.replaceState({path: newUrl}, '', newUrl);

        // Update active filter count label on Filter button
        var activeFilterCount = checkedStatus.length + checkedCategories.length + checkedTags.length + checkedAuthors.length;
        if (activeFilterCount > 0) {
            filterBtn.textContent = 'Filter (' + activeFilterCount + ')';
            filterBtn.classList.add('active');
        } else {
            filterBtn.textContent = 'Filter';
            filterBtn.classList.remove('active');
        }
    }

    // 4. Bind event listeners
    searchInput.addEventListener('input', applyFilters);
    filterMenu.addEventListener('change', applyFilters);

    // Clear icon interaction
    var clearIcon = searchInput.nextElementSibling;
    if (clearIcon && clearIcon.classList.contains('clear')) {
        clearIcon.addEventListener('click', function() {
            searchInput.value = '';
            clearIcon.classList.add('hidden');
            applyFilters();
        });
        
        searchInput.addEventListener('keyup', function() {
            if (this.value) {
                clearIcon.classList.remove('hidden');
            } else {
                clearIcon.classList.add('hidden');
            }
        });
    }

    // Clear filters button
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            // Uncheck all checkboxes
            filterMenu.querySelectorAll('input[type="checkbox"]').forEach(function(chk) {
                chk.checked = false;
            });
            // Clear search
            searchInput.value = '';
            if (clearIcon && clearIcon.classList.contains('clear')) {
                clearIcon.classList.add('hidden');
            }
            applyFilters();
        });
    }

    // Fallback dropdown logic in case Garnish isn't loaded or doesn't bind automatically
    filterBtn.addEventListener('click', function(e) {
        if (!window.Garnish || !filterBtn.classList.contains('active')) {
            var isHidden = filterMenu.style.display === 'none';
            filterMenu.style.display = isHidden ? 'block' : 'none';
            e.stopPropagation();
        }
    });

    document.addEventListener('click', function(e) {
        if (filterMenu.style.display === 'block' && !filterMenu.contains(e.target) && e.target !== filterBtn) {
            filterMenu.style.display = 'none';
        }
    });

    // Initialize
    loadFiltersFromUrl();
    applyFilters();
})();
