let revealObserver = null;

function getRevealElements() {
    const selector = [
        '.site-content h1',
        '.site-content h2',
        '.site-content h3',
        '.site-content .eyebrow',
        '.site-content p',
        '.site-content .btn',
        '.site-content .text-button',
        '.site-content button',
        '.site-content .card',
        '.site-content .stat-card',
        '.site-content .form-panel',
        '.site-content .account-header',
        '.site-content .data-table tr',
        '.site-content .piano-hero',
        '.site-content .view-panel',
        '.site-footer'
    ].join(', ');

    const elements = new Set(document.querySelectorAll('[data-reveal]'));

    document.querySelectorAll(selector).forEach((element) => {
        if (element.hidden || element.closest('.modal-overlay, .modal-overlay-full, .modal, .piano-keys-area')) {
            return;
        }

        elements.add(element);
    });

    return [...elements];
}

function initializeRevealAnimations() {
    if (revealObserver) {
        revealObserver.disconnect();
    }

    const revealElements = getRevealElements();

    revealElements.forEach((element, index) => {
        if (!element.dataset.reveal) {
            element.dataset.reveal = element.matches('.card, .stat-card, .btn, .text-button, button, .site-footer') ? 'jump' : 'rise';
            element.dataset.revealDelay = String((index % 5) * 60);
        }

        element.style.setProperty('--reveal-delay', `${element.dataset.revealDelay || 0}ms`);
        element.classList.remove('is-visible');
    });

    if (!revealElements.length || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revealElements.forEach((element) => element.classList.add('is-visible'));
        return;
    }

    revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            revealObserver.unobserve(entry.target);
        });
    }, {
        rootMargin: '0px 0px -5% 0px',
        threshold: 0
    });

    revealElements.forEach((element) => revealObserver.observe(element));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeRevealAnimations, { once: true });
} else {
    initializeRevealAnimations();
}

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        initializeRevealAnimations();
    }
});
