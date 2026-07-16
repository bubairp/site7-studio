/**
 * Site7 Studio – Settings
 * Tab switching logic for the plugin settings page.
 */
(function() {
    var container = document.getElementById('content');
    if (!container) return;

    var tabs = document.querySelectorAll('#tabs a');
    var sections = container.querySelectorAll('.site7-settings-section');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var target = this.getAttribute('href');

            tabs.forEach(function(t) { t.classList.remove('sel'); });
            this.classList.add('sel');

            sections.forEach(function(section) {
                section.classList.add('hidden');
            });

            var targetSection = document.querySelector(target);
            if (targetSection) {
                targetSection.classList.remove('hidden');
            }
        });
    });
})();
