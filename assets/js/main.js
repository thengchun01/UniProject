
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.site-content > *, .site-footer').forEach((element) => {
        if (element.matches('script, style, .modal-overlay-full') || element.dataset.reveal || element.classList.contains('hero-entrance')) {
            return;
        }

        element.dataset.reveal = 'rise';
    });

    const revealElements = document.querySelectorAll('[data-reveal]');

    if (!revealElements.length) {
        return;
    }

    revealElements.forEach((element) => {
        element.style.setProperty('--reveal-delay', `${element.dataset.revealDelay || 0}ms`);
    });

    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revealElements.forEach((element) => element.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.01 });

    revealElements.forEach((element) => observer.observe(element));
});
