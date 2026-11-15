let revealObserver = null;
const activeRevealAnimations = new WeakMap();

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
        // Exclude UI controls inside complex apps to prevent layout jumping
        if (element.hidden || element.closest('.modal-overlay, .modal-overlay-full, .modal, .piano-workspace, .game-menu, .piano-actions')) {
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
        const previousAnimation = activeRevealAnimations.get(element);
        if (previousAnimation) {
            previousAnimation.cancel();
            activeRevealAnimations.delete(element);
        }

        if (!element.dataset.reveal) {
            element.dataset.reveal = element.matches('.card, .stat-card, .btn, .text-button, button, .site-footer') ? 'jump' : 'rise';
            element.dataset.revealDelay = String((index % 5) * 60);
        }

        element.style.setProperty('--reveal-delay', `${element.dataset.revealDelay || 0}ms`);
        element.classList.add('reveal-pending');
        element.classList.remove('is-visible');
    });

    const showElement = (element) => {
        if (element.classList.contains('is-visible') || activeRevealAnimations.has(element)) {
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !element.animate) {
            element.classList.add('is-visible');
            return;
        }

        const isJump = element.dataset.reveal === 'jump';
        const animation = element.animate([
            {
                opacity: 0,
                transform: isJump ? 'translateY(30px) scale(0.94)' : 'translateY(32px)'
            },
            {
                opacity: 1,
                transform: 'translateY(0) scale(1)'
            }
        ], {
            duration: 650,
            delay: Number(element.dataset.revealDelay || 0),
            easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
            fill: 'both'
        });

        activeRevealAnimations.set(element, animation);
        animation.onfinish = () => {
            element.classList.add('is-visible');
            activeRevealAnimations.delete(element);
        };
    };

    if (!revealElements.length || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revealElements.forEach(showElement);
        return;
    }

    // Force reflow to ensure 'reveal-pending' is applied before observer adds 'is-visible'
    void document.body.offsetHeight;

    revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            showElement(entry.target);
            revealObserver.unobserve(entry.target);
        });
    }, {
        rootMargin: '0px 0px -5% 0px',
        threshold: 0
    });

    revealElements.forEach((element) => revealObserver.observe(element));

    // Guarantee that elements currently in viewport animate immediately without waiting for IntersectionObserver
    // This fixes the issue on heavy pages where observer callbacks are delayed or dropped
    window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
            revealElements.forEach((element) => {
                const rect = element.getBoundingClientRect();
                if (rect.top < window.innerHeight && rect.bottom > 0) {
                    showElement(element);
                    revealObserver.unobserve(element);
                }
            });
        });
    });

    // Fallback for off-screen elements if observer completely fails
    window.setTimeout(() => {
        revealElements.forEach((element) => {
            const isNearViewport = element.getBoundingClientRect().top < window.innerHeight * 1.5;
            if (isNearViewport) {
                showElement(element);
                revealObserver.unobserve(element);
            }
        });
    }, 800);
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

function initializeLogoutConfirmation() {
    const dialog = document.getElementById('logoutConfirmDialog');

    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    document.querySelectorAll('[data-logout-confirm]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            if (!dialog.open) {
                dialog.showModal();
            }
        });
    });

    dialog.querySelector('[data-logout-cancel]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLogoutConfirmation, { once: true });
} else {
    initializeLogoutConfirmation();
}

function initializeScrollTop() {
    const scrollTopBtn = document.getElementById('scroll-top-btn');
    if (!scrollTopBtn) return;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const toggleScrollTop = () => scrollTopBtn.classList.toggle('visible', window.scrollY > 400);
    window.addEventListener('scroll', toggleScrollTop, { passive: true });
    toggleScrollTop();
    scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeScrollTop, { once: true });
} else {
    initializeScrollTop();
}


/* Shared light/dark theme preference for Discover, Manage and Studio. */
function initializeGlobalTheme() {
    const root = document.documentElement;
    const toggle = document.getElementById('themeToggle');
    const storedTheme = (() => {
        try { return localStorage.getItem('psm-theme'); } catch (error) { return null; }
    })();
    const initialTheme = storedTheme === 'dark' ? 'dark' : 'light';

    const applyTheme = (theme, persist = false) => {
        const nextTheme = theme === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-theme', nextTheme);
        if (toggle) {
            const dark = nextTheme === 'dark';
            const icon = toggle.querySelector('.theme-toggle-icon');
            const label = toggle.querySelector('.theme-toggle-label');
            if (icon) icon.textContent = dark ? '☾' : '☀';
            if (label) label.textContent = dark ? 'Light mode' : 'Dark mode';
            toggle.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
            toggle.setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');
            toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
        }
        if (persist) {
            try { localStorage.setItem('psm-theme', nextTheme); } catch (error) {}
        }
    };

    applyTheme(root.getAttribute('data-theme') || initialTheme);
    if (toggle) {
        toggle.addEventListener('click', () => {
            applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeGlobalTheme, { once: true });
} else {
    initializeGlobalTheme();
}
