document.addEventListener('DOMContentLoaded', function() {
    // Inicializar accordion
    initSimpleAccordion();
    
    function initSimpleAccordion() {
        const accordionHeaders = document.querySelectorAll('.simple-accordion .accordion-header');
        
        accordionHeaders.forEach(function(header) {
            header.addEventListener('click', function() {
                toggleAccordion(header);
            });
            
            // Accesibilidad: navegación por teclado
            header.addEventListener('keydown', function(e) {
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
        
        // Opcional: Cerrar otros accordions (comportamiento de un solo item abierto)
        // Si quieres permitir múltiples abiertos, comenta estas líneas:
        accordion.querySelectorAll('.accordion-header').forEach(h => {
            if (h !== header) {
                h.classList.remove('active');
                h.setAttribute('aria-expanded', 'false');
            }
        });
        accordion.querySelectorAll('.accordion-content').forEach(c => {
            if (c !== content) {
                c.classList.remove('active');
            }
        });
        
        // Toggle del accordion actual
        if (isActive) {
            header.classList.remove('active');
            content.classList.remove('active');
            header.setAttribute('aria-expanded', 'false');
        } else {
            header.classList.add('active');
            content.classList.add('active');
            header.setAttribute('aria-expanded', 'true');
            
            // Smooth scroll al accordion abierto (opcional)
            setTimeout(() => {
                header.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'nearest' 
                });
            }, 300);
        }
    }
});
