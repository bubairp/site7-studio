import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import Lenis from 'lenis';

import { initContactForm } from './components/contact-form.js';
import { initFormValidation } from './components/form-validation.js';
import { initPriceSwitch } from './components/price-switch.js';
import { initScrollTopButton } from './components/scroll-top-button.js';
import { initSmoothScroll } from './components/smooth-scroll.js';
import smoothModal from './components/smooth-modal.js';
import { initStickyNavbar } from './components/sticky-navbar.js';
import { initHeaderMenu } from './components/header-menu.js';
import { initSidebarMenu, sidebarMenuComponent } from './components/sidebar-menu.js';

const initPreloader = () => {
    document.body.style.opacity = '1'
};

const initTabs = () => {
    const tabGroup = document.getElementById('tabGroup');
    if (!tabGroup) return;

    const tabLinks = tabGroup.querySelectorAll('.tab-link');
    tabLinks.forEach(link => {
        link.addEventListener('click', function () {
            if (tabGroup.querySelector('.collapse')) {
                const activeLink = tabGroup.querySelector('.tab-link.active');
                if (activeLink && activeLink !== this) {
                    activeLink.classList.remove('active');
                }
                this.classList.toggle('active');
                const activeCollapse = tabGroup.querySelector('.collapse.show');
                if (activeCollapse) {
                    activeCollapse.classList.remove('show');
                }
            }
        });
    });
};

const initHeaderHeight = () => {
    const setHeaderHeight = () => {
        const header = document.querySelector('.sticky');
        if (!header) return;

        // The collapsible search box lives inside the header, so header.offsetHeight
        // grows when it's open. On mobile, focusing the search input opens the
        // keyboard which fires a `resize`, capturing that inflated height; closing
        // the search fires no resize, so --header-height stayed stuck taller.
        // Since header.offsetHeight === baseHeight + searchField.offsetHeight at all
        // times, subtract the search field to always get the stable base height.
        let height = header.offsetHeight;
        const searchField = header.querySelector('.search-field');
        if (searchField) {
            height -= searchField.offsetHeight;
        }
        document.documentElement.style.setProperty('--header-height', `${height}px`);
    };

    window.addEventListener('load', setHeaderHeight);
    window.addEventListener('resize', setHeaderHeight);
    // Recompute after the search box opens/closes (its x-collapse animation runs
    // ~300ms) so the value stays correct even when no resize event fires.
    document.addEventListener('click', (e) => {
        if (e.target.closest('[aria-label="Search"], [aria-label="Close Search"]')) {
            setTimeout(setHeaderHeight, 350);
        }
    });
    // Run immediately
    setHeaderHeight();
};

const initLenisScroll = () => {
    if (window.innerWidth <= 1024) return; // Disable smooth scroll on mobile devices for performance

    const lenis = new Lenis({
        duration: 1.2,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
        orientation: 'vertical',
        gestureOrientation: 'vertical',
        smoothWheel: true,
        wheelMultiplier: 1,
        touchMultiplier: 2,
        infinite: false,
    });

    const raf = (time) => {
        lenis.raf(time);
        requestAnimationFrame(raf);
    };
    requestAnimationFrame(raf);

    window.lenis = lenis;

    // Sticky Menu Logic
    const header = document.querySelector('.headerMain');
    const stickyEls = document.querySelectorAll('.sticky');
    if (!header) return;

    const hideThreshold = 250;

    const updateStickyState = (scrollTop, direction) => {
        const menuNav = document.getElementById('menuNav');
        const offcanvasRight = document.getElementById('offcanvasRight');
        const isMenuOpen = header.classList.contains('menu-dropdown-open') ||
            header.classList.contains('menu-open') ||
            (menuNav && menuNav.classList.contains('is-nav-open')) ||
            (offcanvasRight && (offcanvasRight.classList.contains('is-open') || offcanvasRight.classList.contains('show')));

        if (scrollTop <= 0) {
            header.classList.remove('fixed', 'scrolled', 'hidden-nav');
            stickyEls.forEach(el => el.classList.remove('fixed'));
            return;
        }

        header.classList.add('scrolled');

        if (!isMenuOpen) {
            // Hide the header when scrolling down past 100px
            if (direction === 1 && scrollTop > 100) {
                header.classList.add('hidden-nav');
            } else if (direction === -1) {
                header.classList.remove('hidden-nav');
            }

            // Lock it to fixed position if we scroll past 250px, OR if we scroll up and want to reveal it
            if (scrollTop > 250 || (direction === -1 && scrollTop > 100) || header.classList.contains('fixed') && scrollTop > 100) {
                header.classList.add('fixed');
                stickyEls.forEach(el => el.classList.add('fixed'));
            } else if (scrollTop <= 0) {
                header.classList.remove('fixed');
                stickyEls.forEach(el => el.classList.remove('fixed'));
            }
        } else {
            header.classList.remove('hidden-nav');
            header.classList.add('fixed');
            stickyEls.forEach(el => el.classList.add('fixed'));
        }
    };

    updateStickyState(lenis.scroll, 0);

    lenis.on('scroll', (e) => {
        updateStickyState(e.scroll, e.direction);
    });
};

const initSliderNavItems = () => {
    const swiperEl = document.querySelector(".swiper");
    if (!swiperEl) return;
    const swiperSlides = swiperEl.querySelectorAll(".swiper-slide").length;
    let currentSlidesPerView = 1;
    const width = window.innerWidth;

    if (width >= 1200) {
        currentSlidesPerView = 4;
    } else if (width >= 860) {
        currentSlidesPerView = 3;
    } else if (width >= 500) {
        currentSlidesPerView = 2;
    }

    if (swiperSlides <= currentSlidesPerView) {
        const prevBtn = document.getElementById("popular-prev");
        const nextBtn = document.getElementById("popular-next");

        if (prevBtn) prevBtn.style.display = "none";
        if (nextBtn) nextBtn.style.display = "none";
    }
};

const initApp = () => {
    // Initialize Alpine
    window.Alpine = Alpine;
    Alpine.plugin(collapse);
    Alpine.data('smoothModal', smoothModal);
    Alpine.data('sidebarMenu', sidebarMenuComponent);

    Alpine.store('modal', {
        active: null,
        data: {},
        open(id, data = {}) {
            this.data = data;
            this.active = id;
        },
        close() {
            this.active = null;
            this.data = {};
        }
    });

    Alpine.start();

    initLenisScroll();
    initTabs();
    initHeaderHeight();
    initSliderNavItems();
    initHeaderMenu();
    initSidebarMenu();
    initContactForm();
    initFormValidation();
    initPriceSwitch();
    initScrollTopButton();
    initSmoothScroll();
    initStickyNavbar();

    // Collect all asynchronous initializations
    const promises = [];

    if (document.querySelector('.swiper')) promises.push(import('./components/carousel.js').then(m => m.initCarousel()));
    if (document.querySelector('.gallery')) promises.push(import('./components/gallery.js').then(m => m.initGallery()));
    if (document.querySelector('.masonry-grid')) promises.push(import('./components/masonry-grid.js').then(m => m.initMasonryGrid()));
    if (document.querySelector('.parallax') && window.innerWidth > 1024) promises.push(import('./components/parallax.js').then(m => m.initParallax()));
    if (document.querySelector('.jarallax') && window.innerWidth > 1024) promises.push(import('./components/jarallax.js').then(m => m.initJarallax()));

    // Reveal page only after all dynamic components are loaded and initialized
    Promise.all(promises).then(() => {
        initPreloader();
    }).catch(() => {
        // Fallback in case of module loading error
        initPreloader();
    });
};

export default initApp;