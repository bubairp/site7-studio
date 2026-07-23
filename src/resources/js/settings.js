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

    var testBtn = document.getElementById('site7-test-connection-btn');
    var resultEl = document.getElementById('site7-test-connection-result');
    if (testBtn && resultEl) {
        testBtn.addEventListener('click', function() {
            testBtn.disabled = true;
            resultEl.textContent = 'Testing…';

            var body = new FormData();
            body.append(testBtn.dataset.csrfName, testBtn.dataset.csrfValue);

            fetch(testBtn.dataset.url, {
                method: 'POST',
                headers: {'Accept': 'application/json'},
                body: body,
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    resultEl.textContent = data.message;
                    resultEl.style.color = data.success ? 'var(--teal-600)' : 'var(--red-600)';
                })
                .catch(function() {
                    resultEl.textContent = 'Could not reach the server.';
                    resultEl.style.color = 'var(--red-600)';
                })
                .finally(function() {
                    testBtn.disabled = false;
                });
        });
    }
})();
