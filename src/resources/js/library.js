/**
 * Site7 Studio – Library
 * Search filtering and card grid interactions.
 * Placeholder for future interactivity.
 */
(function() {
    var searchInput = document.getElementById('site7-search-input');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        var query = this.value.toLowerCase();
        var cards = document.querySelectorAll('.site7-card');

        cards.forEach(function(card) {
            var title = card.querySelector('.site7-card-title');
            var text = title ? title.textContent.toLowerCase() : '';
            card.style.display = text.indexOf(query) !== -1 ? '' : 'none';
        });
    });
})();
