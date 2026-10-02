export const sidebarMenuComponent = () => ({
    sidebarOpen: false,

    init() {
        this.$watch('sidebarOpen', value => {
            if (value) {
                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                document.documentElement.classList.add('sidebar-open');
                document.body.classList.add('sidebar-open');
                if (window.lenis) window.lenis.stop();
                if (scrollbarWidth > 0) {
                    document.body.style.paddingRight = scrollbarWidth + 'px';
                    const header = document.querySelector('.headerMain');
                    if (header) header.style.paddingRight = scrollbarWidth + 'px';
                }
            } else {
                // Wait for the slide-out transition (200ms) to complete before restoring scrollbars
                setTimeout(() => {
                    // Fail-safe: Make sure it wasn't re-opened while transitioning
                    if (this.sidebarOpen) return;

                    document.documentElement.classList.remove('sidebar-open');
                    document.body.classList.remove('sidebar-open');
                    if (window.lenis) window.lenis.start();
                    document.body.style.paddingRight = '';
                    const header = document.querySelector('.headerMain');
                    if (header) header.style.paddingRight = '';
                }, 250);
            }
        });
    }
});

export const initSidebarMenu = () => {
    const sidebarNav = document.getElementById('sidebarNav');
    if (sidebarNav) {
        sidebarNav.addEventListener('click', (event) => {
            const toggleBtn = event.target.closest('.dropdown-toggle');
            const navLink = event.target.closest('.nav-link:not(.dropdown-toggle)');

            let targetLi = null;

            if (toggleBtn) {
                event.preventDefault();
                event.stopPropagation();
                targetLi = toggleBtn.closest('.dropdown') || toggleBtn.closest('li');
            } else if (navLink && (!navLink.getAttribute('href') || navLink.getAttribute('href') === '#')) {
                event.preventDefault();
                event.stopPropagation();
                targetLi = navLink.closest('.dropdown') || navLink.closest('li');
            }

            if (targetLi) {
                const isOpen = targetLi.classList.contains('is-open');

                // Close siblings (accordion effect)
                const parentUl = targetLi.parentElement;
                if (parentUl) {
                    parentUl.querySelectorAll(':scope > .is-open').forEach(sibling => {
                        if (sibling !== targetLi) sibling.classList.remove('is-open');
                    });
                }

                if (isOpen) {
                    targetLi.classList.remove('is-open');
                    targetLi.querySelectorAll('.is-open').forEach(child => child.classList.remove('is-open'));
                } else {
                    targetLi.classList.add('is-open');
                }
            }
        });
    }
};
