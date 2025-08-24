document.addEventListener('DOMContentLoaded', () => {
    initSimpleAccordion();

    function initSimpleAccordion() {
        const accordionHeaders = document.querySelectorAll('.simple-accordion .accordion-header');
        
        accordionHeaders.forEach(header => {
            header.addEventListener('click', () => {
                toggleAccordion(header);
            });
            
            // Accesibilidad: navegación por teclado
            header.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleAccordion(header);
                }
            });
        });
    }

    function toggleAccordion(header) {
        const content = header.nextElementSibling;
        const accordion = header.closest('.simple-accordion');
        const isActive = header.classList.contains('active');
        
        // Cerrar otros acordeones si no es la versión de múltiples abiertos
        if (!accordion.classList.contains('allow-multiple-open')) {
            accordion.querySelectorAll('.accordion-header').forEach(h => {
                if (h !== header && h.classList.contains('active')) {
                    h.classList.remove('active');
                    h.setAttribute('aria-expanded', 'false');
                    h.nextElementSibling.classList.remove('active');
                }
            });
        }
        
        // Toggle del acordeón actual
        header.classList.toggle('active');
        content.classList.toggle('active');
        header.setAttribute('aria-expanded', !isActive);

        // Scroll suave al acordeón abierto
        if (!isActive) {
            content.addEventListener('transitionend', () => {
                header.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }, { once: true });
        }
    }
});
