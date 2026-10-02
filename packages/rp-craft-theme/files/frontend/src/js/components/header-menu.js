/**
 * Header Menu — Modern Vanilla JS (simpleHeader & common)
 *
 * Features:
 *  Desktop  : hover mega-dropdown reveal (clip-path), crossfade switching, backdrop overlay
 *  Mobile   : animated hamburger → X (CSS-driven), slide-in nav panel, accordion submenus
 *  A11y     : aria-expanded, aria-controls, ESC key, focus management
 *  UX       : body scroll-lock, outside-click close, resize guard
 */

export function initHeaderMenu() {

    // =============================================
    // CONFIG
    // =============================================

    const DESKTOP_BP = 1024;  // px — matches Tailwind lg breakpoint
    const HOVER_DELAY = 150;   // ms — hover bridge before closing desktop dropdown
    const SWITCH_DELAY = 350;   // ms — cleanup switching class after crossfade

    // =============================================
    // ELEMENT REFS
    // =============================================

    const header = document.querySelector('.headerMain');
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const mobileNav = document.getElementById('menuNav'); // changed from navbarNav

    // =============================================
    // UTILITIES
    // =============================================

    /** Check if current viewport is desktop */
    const isDesktop = () => window.innerWidth >= DESKTOP_BP;

    /** Lock / unlock body scroll */
    const scrollLock = (lock) => {
        document.body.style.overflow = lock ? 'hidden' : '';
    };



    /** Get or lazily create the mobile nav backdrop */
    const getMobileNavBackdrop = () => {
        let el = document.querySelector('.mobile-nav-backdrop');
        if (!el) {
            el = document.createElement('div');
            el.className = 'mobile-nav-backdrop';
            el.setAttribute('aria-hidden', 'true');
            header?.parentNode?.insertBefore(el, header.nextSibling);
        }
        return el;
    };

    /** Get or lazily create the desktop nav backdrop */
    const getDesktopNavBackdrop = () => {
        let el = document.querySelector('.dropdown-overlay');
        if (!el) {
            el = document.createElement('div');
            el.className = 'dropdown-overlay';
            el.setAttribute('aria-hidden', 'true');
            header?.parentNode?.insertBefore(el, header.nextSibling);
        }
        return el;
    };


    // =============================================
    // DESKTOP DROPDOWN LOGIC
    // =============================================

    let hoverTimer = null;
    let switchTimer = null;
    let currentHoverTarget = null;

    document.addEventListener('mouseover', (e) => {
        currentHoverTarget = e.target;
    });

    /**
     * Open a desktop dropdown.
     * If another UNRELATED dropdown is open, close it (crossfade switching).
     * Ancestor dropdowns are preserved so nested dropdowns don't kill their parent.
     */
    const openDropdown = (dropdown) => {
        if (!isDesktop()) return;

        // Cancel any scheduled close
        clearTimeout(hoverTimer);
        hoverTimer = null;

        // Already open — nothing to do
        if (dropdown.classList.contains('is-open')) return;

        dropdown.classList.add('is-open');
        header?.classList.add('menu-dropdown-open');
        updateTriggerAria(dropdown, true);

        getDesktopNavBackdrop().classList.add('is-open');

    };

    /**
     * Schedule close of all desktop dropdowns after HOVER_DELAY ms.
     * Cancelled if cursor re-enters a dropdown or panel within that time.
     */
    const scheduleDesktopClose = () => {
        if (!isDesktop()) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(closeAllDesktopDropdowns, HOVER_DELAY);
    };

    /** Close desktop dropdowns. If force is false, keeps ancestors of current hover target open. */
    const closeAllDesktopDropdowns = (force = false) => {
        clearTimeout(hoverTimer);
        hoverTimer = null;

        const openOthers = document.querySelectorAll('.simple-rp-menu .dropdown.is-open');
        const siblingsToClose = Array.from(openOthers).filter(
            d => force || !d.contains(currentHoverTarget)
        );

        siblingsToClose.forEach(d => {
            d.classList.remove('is-open');
            updateTriggerAria(d, false);
        });

        if (document.querySelectorAll('.simple-rp-menu .dropdown.is-open').length === 0) {
            header?.classList.remove('menu-dropdown-open');
            document.querySelector('.dropdown-overlay')?.classList.remove('is-open');

            // Cleanup switching class after transition
            clearTimeout(switchTimer);
            switchTimer = setTimeout(() => {
                header?.classList.remove('switching');
            }, SWITCH_DELAY);


        }
    };

    // Desktop: hover open / leave close
    document.addEventListener('mouseover', (e) => {
        if (!isDesktop()) return;

        // When hovering ANY menu item (dropdown or normal link)
        const menuItem = e.target.closest('.simple-rp-menu .menu-item');
        if (menuItem) {
            // Close any open dropdowns that do not contain this hovered menu item
            const openOthers = document.querySelectorAll('.simple-rp-menu .dropdown.is-open');
            const siblingsToClose = Array.from(openOthers).filter(
                d => !d.contains(menuItem)
            );

            if (siblingsToClose.length > 0) {
                siblingsToClose.forEach(d => {
                    d.classList.remove('is-open');
                    updateTriggerAria(d, false);
                });

                if (document.querySelectorAll('.simple-rp-menu .dropdown.is-open').length === 0) {
                    header?.classList.remove('menu-dropdown-open');
                    const overlay = document.querySelector('.dropdown-overlay');
                    overlay?.classList.remove('is-open');
                }
            }

            // If it's a dropdown, open it
            if (menuItem.classList.contains('dropdown')) {
                openDropdown(menuItem);
            }
        }
    });

    document.addEventListener('mouseout', (e) => {
        if (!isDesktop()) return;
        const dropdown = e.target.closest('.simple-rp-menu .dropdown');
        // Only schedule close when truly leaving the dropdown
        if (dropdown && !dropdown.contains(e.relatedTarget)) {
            scheduleDesktopClose();
        }
    });

    // Overlay: close on click
    document.addEventListener('click', (e) => {
        if (e.target.closest('.dropdown-overlay')) {
            clearTimeout(hoverTimer);
            closeAllDesktopDropdowns(true);
            document.querySelector('.dropdown-overlay')?.classList.remove('is-open');
        }
    });

    // =============================================
    // MOBILE NAV (Hamburger)
    // =============================================

    const openMobileNav = () => {
        mobileNav?.classList.add('is-nav-open');
        mobileToggle?.setAttribute('aria-expanded', 'true');
        header?.classList.add('menu-open');
        getMobileNavBackdrop().classList.add('is-open');
        scrollLock(true);
    };

    const closeMobileNav = () => {
        mobileNav?.classList.remove('is-nav-open');
        mobileToggle?.setAttribute('aria-expanded', 'false');
        header?.classList.remove('menu-open');
        document.querySelector('.mobile-nav-backdrop')?.classList.remove('is-open');
        // Clean up mobile accordion state
        document.querySelectorAll('.simple-rp-menu .dropdown.is-open').forEach(d => {
            d.classList.remove('is-open');
            updateTriggerAria(d, false);
        });
        scrollLock(false);
    };

    mobileToggle?.addEventListener('click', (e) => {
        e.preventDefault();
        const isOpen = mobileToggle.getAttribute('aria-expanded') === 'true';
        if (isOpen) { closeMobileNav(); } else { openMobileNav(); }
    });

    // =============================================
    // MOBILE SUBMENU ACCORDION
    // =============================================

    /**
     * Toggle a mobile submenu open/closed.
     * Closes sibling dropdowns at the same level (accordion behaviour).
     */
    const toggleMobileSubmenu = (dropdown) => {
        const isOpen = dropdown.classList.contains('is-open');

        // Close siblings at the same level
        const parentList = dropdown.parentElement?.closest('ul');
        parentList?.querySelectorAll(':scope > li.dropdown.is-open').forEach(sibling => {
            if (sibling !== dropdown) {
                sibling.classList.remove('is-open');
                updateTriggerAria(sibling, false);
            }
        });

        if (isOpen) {
            // Closing: recursively close all nested open submenus
            dropdown.querySelectorAll('.dropdown.is-open').forEach(child => {
                child.classList.remove('is-open');
                updateTriggerAria(child, false);
            });
        }

        dropdown.classList.toggle('is-open', !isOpen);
        updateTriggerAria(dropdown, !isOpen);
    };

    // Tap the .dropdown-toggle button (chevron)
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('.simple-rp-menu .menu-toggle-icon, .simple-rp-menu .dropdown-toggle, [data-menu-toggle="dropdown"]');
        if (!toggle) return;

        if (!isDesktop()) {
            e.preventDefault();
            e.stopPropagation();
            const dropdown = toggle.closest('.dropdown');
            if (dropdown) toggleMobileSubmenu(dropdown);
        }
    });

    // Tap the parent link/span of a dropdown (placeholder hrefs only)
    document.addEventListener('click', (e) => {
        // Exclude toggle icon to prevent double-triggering when tapping the chevron toggle
        const link = e.target.closest('.simple-rp-menu .dropdown .menu-group > a:not(.menu-toggle-icon):not(.dropdown-toggle), .simple-rp-menu .dropdown .menu-group > span, .simple-rp-menu .dropdown > a:not(.menu-toggle-icon):not(.dropdown-toggle), .simple-rp-menu .dropdown > span');
        if (!link) return;

        if (!isDesktop()) {
            const href = link.getAttribute('href');
            const isSpan = link.tagName.toLowerCase() === 'span';
            const isPlaceholder = !href || href === '#' || href.trim() === '';

            if (isSpan || isPlaceholder) {
                e.preventDefault();
                e.stopPropagation();
                const dropdown = link.closest('.dropdown');
                if (dropdown) toggleMobileSubmenu(dropdown);
            }
        }
    });

    // =============================================
    // OUTSIDE-CLICK CLOSE
    // =============================================

    document.addEventListener('click', (e) => {
        if (isDesktop()) {
            // Close desktop dropdowns when clicking outside the menu
            if (!e.target.closest('.simple-rp-menu') &&
                !e.target.closest('.dropdown-overlay')) {
                closeAllDesktopDropdowns(true);
            }
        } else {
            // Close open mobile submenus when clicking outside
            if (!e.target.closest('.simple-rp-menu .dropdown')) {
                document.querySelectorAll('.simple-rp-menu .dropdown.is-open').forEach(d => {
                    d.classList.remove('is-open');
                    updateTriggerAria(d, false);
                });
            }

            // Close mobile nav when clicking outside or on mobile nav backdrop
            if (mobileNav?.classList.contains('is-nav-open') &&
                (e.target.closest('.mobile-nav-backdrop') ||
                    (!e.target.closest('#menuNav') && !e.target.closest('#mobileMenuToggle')))) {
                closeMobileNav();
            }
        }
    });

    // Close mobile nav when clicking a regular navigation link inside it
    document.addEventListener('click', (e) => {
        if (isDesktop()) return;
        const link = e.target.closest('#menuNav a:not(.menu-toggle-icon):not(.dropdown-toggle)');
        if (link) {
            closeMobileNav();
        }
    });

    // =============================================
    // ESC KEY
    // =============================================

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;

        if (isDesktop()) {
            clearTimeout(hoverTimer);
            closeAllDesktopDropdowns(true);
        }

        // Close mobile nav and return focus
        if (mobileNav?.classList.contains('is-nav-open')) {
            closeMobileNav();
            mobileToggle?.focus();
        }
    });

    // =============================================
    // RESIZE GUARD
    // =============================================

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (isDesktop()) {
                // Moved to desktop: clean up mobile state
                closeMobileNav();
                scrollLock(false);
            } else {
                // Moved to mobile: clean up desktop state
                clearTimeout(hoverTimer);
                closeAllDesktopDropdowns(true);
            }
        }, 150);
    });

    // =============================================
    // ARIA HELPERS
    // =============================================

    /**
     * Update aria-expanded on the primary trigger link/span of a dropdown.
     * @param {Element} dropdown  — the .dropdown li element
     * @param {boolean} expanded  — desired aria-expanded state
     */
    function updateTriggerAria(dropdown, expanded) {
        // Primary trigger: direct > a or > span (not inside sub-ul), checking inside menu-group as well
        const trigger = dropdown.querySelector(':scope > .menu-group > a, :scope > .menu-group > span, :scope > a, :scope > span');
        trigger?.setAttribute('aria-expanded', String(expanded));
    }

    // Set initial aria-expanded="false" on all dropdown triggers
    document.querySelectorAll('.simple-rp-menu .dropdown').forEach(d => {
        updateTriggerAria(d, false);
        // Mark the trigger as having a popup
        const trigger = d.querySelector(':scope > .menu-group > a, :scope > .menu-group > span, :scope > a, :scope > span');
        if (trigger) {
            trigger.setAttribute('aria-haspopup', 'true');
        }
    });

    // Set dynamic animation delays for mobile stagger animation to handle arbitrary items
    document.querySelectorAll('.simple-rp-menu .menu-ul > li').forEach((item, index) => {
        item.style.setProperty('--delay', `${(index + 1) * 0.04}s`);
    });

    // =============================================
    // DYNAMIC OFFSET CALCULATION
    // =============================================
    const updateMenuOffsets = () => {
        if (!isDesktop()) return;
        const headerEl = document.querySelector('.headerMain');
        const firstItem = document.querySelector('.simple-rp-menu > .menu-ul > li');
        if (!headerEl || !firstItem) return;

        const headerRect = headerEl.getBoundingClientRect();
        const itemRect = firstItem.getBoundingClientRect();

        // Exact pixel difference between header bottom and menu item bottom
        const offset = headerRect.bottom - itemRect.bottom;

        // Update the CSS variable globally so mega menu aligns perfectly with simple menus
        document.documentElement.style.setProperty('--mega-menu-top-offset', `-${offset}px`);
    };

    updateMenuOffsets();
    window.addEventListener('load', updateMenuOffsets);

    if (header) {
        const headerObserver = new ResizeObserver(() => updateMenuOffsets());
        headerObserver.observe(header);
    }

}
