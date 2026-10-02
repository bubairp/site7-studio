export default function smoothModal(modalId) {
    return {
        modalId: modalId,
        isOpen: false,
        modalLenis: null,
        rafId: null,
        
        init() {
            // Watch the global store for this specific modal's state
            this.$watch('$store.modal.active', (value) => {
                const isActive = (value === this.modalId);
                if (isActive !== this.isOpen) {
                    this.isOpen = isActive;
                    this.toggle(isActive);
                }
            });
            
            // Check initial state
            if (this.$store.modal.active === this.modalId) {
                this.isOpen = true;
                this.toggle(true);
            }
        },
        
        toggle(state) {
            if (state) {
                document.body.classList.add('overflow-hidden');
                
                // Pause main page scrolling
                if (window.lenis) window.lenis.stop();
                
                this.$nextTick(() => {
                    const scrollWrapper = this.$refs.scrollWrapper;
                    if (window.Lenis && scrollWrapper) {
                        // Create scoped smooth scroller for the modal
                        this.modalLenis = new window.Lenis({
                            wrapper: scrollWrapper,
                            content: scrollWrapper.firstElementChild,
                            duration: 1.2
                        });
                        
                        const raf = (time) => {
                            if (this.modalLenis) {
                                this.modalLenis.raf(time);
                                this.rafId = requestAnimationFrame(raf);
                            }
                        };
                        this.rafId = requestAnimationFrame(raf);
                    }
                });
            } else {
                document.body.classList.remove('overflow-hidden');
                
                // Resume main page scrolling
                if (window.lenis) window.lenis.start();
                
                // Clean up modal scroller
                if (this.modalLenis) {
                    this.modalLenis.destroy();
                    this.modalLenis = null;
                }
                if (this.rafId) {
                    cancelAnimationFrame(this.rafId);
                }
            }
        }
    }
}
