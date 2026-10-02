const initHome = () => {
    // Home page-specific logic goes here.
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHome);
} else {
    initHome();
}