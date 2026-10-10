/**
 * psm-modal.js
 * Universal modern modal dialogs and toast notifications for PSM web app.
 * Replaces window.prompt, window.confirm, and alert with accessible, styled pop-up dialogs.
 */
(function (root) {
    'use strict';

    var container = null;
    var toastContainer = null;

    function getContainer() {
        if (!container) {
            container = document.createElement('div');
            container.id = 'psmModalContainer';
            container.className = 'psm-modal-container';
            document.body.appendChild(container);
        }
        return container;
    }

    function getToastContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'psmToastContainer';
            toastContainer.className = 'psm-toast-container';
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }

    function createOverlay() {
        var overlay = document.createElement('div');
        overlay.className = 'psm-modal-overlay';
        getContainer().appendChild(overlay);
        return overlay;
    }

    function removeOverlay(overlay) {
        overlay.classList.remove('is-open');
        setTimeout(function () {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }, 220);
    }

    var PSMModal = {
        /**
         * Styled prompt dialog replacing window.prompt.
         * @param {Object} opts - { title, label, value, type, placeholder, confirmText, cancelText }
         * @returns {Promise<string|null>} Resolves with entered value or null if cancelled.
         */
        prompt: function (opts) {
            opts = opts || {};
            return new Promise(function (resolve) {
                var overlay = createOverlay();
                var title = opts.title || 'Enter Value';
                var label = opts.label || '';
                var initialVal = opts.value !== undefined ? String(opts.value) : '';
                var inputType = opts.type || 'text';
                var placeholder = opts.placeholder || '';
                var confirmText = opts.confirmText || 'Save';
                var cancelText = opts.cancelText || 'Cancel';

                overlay.innerHTML =
                    '<div class="psm-modal-card" role="dialog" aria-modal="true" aria-labelledby="psmPromptTitle">' +
                        '<div class="psm-modal-header">' +
                            '<h3 id="psmPromptTitle" class="psm-modal-title">' + escapeHtml(title) + '</h3>' +
                            '<button type="button" class="psm-modal-close" aria-label="Close">&times;</button>' +
                        '</div>' +
                        '<div class="psm-modal-body">' +
                            (label ? '<label class="psm-modal-label" for="psmPromptInput">' + escapeHtml(label) + '</label>' : '') +
                            '<div class="psm-modal-input-wrap">' +
                                (inputType === 'number' ? '<span class="psm-modal-prefix">RM</span>' : '') +
                                '<input class="psm-modal-input" id="psmPromptInput" type="' + escapeHtml(inputType) + '" ' +
                                    (inputType === 'number' ? 'step="0.01" min="0"' : '') +
                                    ' value="' + escapeHtml(initialVal) + '" placeholder="' + escapeHtml(placeholder) + '" />' +
                            '</div>' +
                        '</div>' +
                        '<div class="psm-modal-actions">' +
                            '<button type="button" class="psm-modal-btn psm-modal-cancel">' + escapeHtml(cancelText) + '</button>' +
                            '<button type="button" class="psm-modal-btn psm-modal-confirm primary-btn">' + escapeHtml(confirmText) + '</button>' +
                        '</div>' +
                    '</div>';

                var card = overlay.querySelector('.psm-modal-card');
                var input = overlay.querySelector('#psmPromptInput');
                var btnCancel = overlay.querySelector('.psm-modal-cancel');
                var btnConfirm = overlay.querySelector('.psm-modal-confirm');
                var btnClose = overlay.querySelector('.psm-modal-close');

                function finish(val) {
                    removeOverlay(overlay);
                    resolve(val);
                }

                btnCancel.addEventListener('click', function () { finish(null); });
                btnClose.addEventListener('click', function () { finish(null); });
                overlay.addEventListener('click', function (ev) {
                    if (ev.target === overlay) finish(null);
                });

                btnConfirm.addEventListener('click', function () {
                    finish(input.value.trim());
                });

                input.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        finish(input.value.trim());
                    } else if (ev.key === 'Escape') {
                        ev.preventDefault();
                        finish(null);
                    }
                });

                requestAnimationFrame(function () {
                    overlay.classList.add('is-open');
                    input.focus();
                    if (input.select) input.select();
                });
            });
        },

        /**
         * Option select modal replacing text prompt for status choices.
         * @param {Object} opts - { title, label, current, options: [{value, label, desc, color}], confirmText, cancelText }
         * @returns {Promise<string|null>}
         */
        select: function (opts) {
            opts = opts || {};
            return new Promise(function (resolve) {
                var overlay = createOverlay();
                var title = opts.title || 'Select Option';
                var label = opts.label || '';
                var current = opts.current || '';
                var options = opts.options || [];
                var confirmText = opts.confirmText || 'Select';
                var cancelText = opts.cancelText || 'Cancel';

                var optionsHtml = '';
                options.forEach(function (opt, idx) {
                    var val = typeof opt === 'object' ? opt.value : opt;
                    var lbl = typeof opt === 'object' ? opt.label : opt;
                    var desc = typeof opt === 'object' ? opt.desc : '';
                    var color = typeof opt === 'object' ? (opt.color || '') : '';
                    var isSelected = String(val).toUpperCase() === String(current).toUpperCase();

                    optionsHtml +=
                        '<label class="psm-modal-choice ' + (isSelected ? 'is-selected ' : '') + (color ? 'is-' + color : '') + '">' +
                            '<input type="radio" name="psmModalChoice" value="' + escapeHtml(val) + '" ' + (isSelected ? 'checked' : '') + ' />' +
                            '<div class="psm-modal-choice-content">' +
                                '<div class="psm-modal-choice-title">' +
                                    '<span class="psm-choice-badge ' + (color ? 'is-' + color : '') + '">' + escapeHtml(lbl) + '</span>' +
                                '</div>' +
                                (desc ? '<div class="psm-modal-choice-desc">' + escapeHtml(desc) + '</div>' : '') +
                            '</div>' +
                        '</label>';
                });

                overlay.innerHTML =
                    '<div class="psm-modal-card" role="dialog" aria-modal="true">' +
                        '<div class="psm-modal-header">' +
                            '<h3 class="psm-modal-title">' + escapeHtml(title) + '</h3>' +
                            '<button type="button" class="psm-modal-close" aria-label="Close">&times;</button>' +
                        '</div>' +
                        '<div class="psm-modal-body">' +
                            (label ? '<p class="psm-modal-label">' + escapeHtml(label) + '</p>' : '') +
                            '<div class="psm-modal-choices">' + optionsHtml + '</div>' +
                        '</div>' +
                        '<div class="psm-modal-actions">' +
                            '<button type="button" class="psm-modal-btn psm-modal-cancel">' + escapeHtml(cancelText) + '</button>' +
                            '<button type="button" class="psm-modal-btn psm-modal-confirm primary-btn">' + escapeHtml(confirmText) + '</button>' +
                        '</div>' +
                    '</div>';

                var btnCancel = overlay.querySelector('.psm-modal-cancel');
                var btnConfirm = overlay.querySelector('.psm-modal-confirm');
                var btnClose = overlay.querySelector('.psm-modal-close');

                function onEsc(ev) {
                    if (ev.key === 'Escape') {
                        finish(null);
                    }
                }
                document.addEventListener('keydown', onEsc);

                function finish(val) {
                    document.removeEventListener('keydown', onEsc);
                    removeOverlay(overlay);
                    resolve(val);
                }

                overlay.querySelectorAll('.psm-modal-choice').forEach(function (choice) {
                    choice.addEventListener('click', function () {
                        overlay.querySelectorAll('.psm-modal-choice').forEach(function (c) { c.classList.remove('is-selected'); });
                        choice.classList.add('is-selected');
                    });
                });

                btnCancel.addEventListener('click', function () { finish(null); });
                btnClose.addEventListener('click', function () { finish(null); });
                overlay.addEventListener('click', function (ev) {
                    if (ev.target === overlay) finish(null);
                });

                btnConfirm.addEventListener('click', function () {
                    var selected = overlay.querySelector('input[name="psmModalChoice"]:checked');
                    finish(selected ? selected.value : null);
                });

                requestAnimationFrame(function () {
                    overlay.classList.add('is-open');
                });
            });
        },

        /**
         * Confirmation dialog replacing window.confirm.
         * @param {Object} opts - { title, message, danger, confirmText, cancelText }
         * @returns {Promise<boolean>} Resolves true if confirmed, false otherwise.
         */
        confirm: function (opts) {
            opts = opts || {};
            return new Promise(function (resolve) {
                var overlay = createOverlay();
                var title = opts.title || 'Confirm Action';
                var message = opts.message || 'Are you sure you want to proceed?';
                var isDanger = !!opts.danger;
                var confirmText = opts.confirmText || (isDanger ? 'Delete' : 'Confirm');
                var cancelText = opts.cancelText || 'Cancel';

                overlay.innerHTML =
                    '<div class="psm-modal-card ' + (isDanger ? 'is-danger-modal' : '') + '" role="dialog" aria-modal="true">' +
                        '<div class="psm-modal-header">' +
                            '<h3 class="psm-modal-title">' + (isDanger ? '⚠️ ' : '') + escapeHtml(title) + '</h3>' +
                            '<button type="button" class="psm-modal-close" aria-label="Close">&times;</button>' +
                        '</div>' +
                        '<div class="psm-modal-body">' +
                            '<p class="psm-modal-text">' + escapeHtml(message) + '</p>' +
                        '</div>' +
                        '<div class="psm-modal-actions">' +
                            '<button type="button" class="psm-modal-btn psm-modal-cancel">' + escapeHtml(cancelText) + '</button>' +
                            '<button type="button" class="psm-modal-btn psm-modal-confirm ' + (isDanger ? 'btn-outline-danger is-danger-btn' : 'primary-btn') + '">' + escapeHtml(confirmText) + '</button>' +
                        '</div>' +
                    '</div>';

                var btnCancel = overlay.querySelector('.psm-modal-cancel');
                var btnConfirm = overlay.querySelector('.psm-modal-confirm');
                var btnClose = overlay.querySelector('.psm-modal-close');

                function onEsc(ev) {
                    if (ev.key === 'Escape') {
                        finish(false);
                    }
                }
                document.addEventListener('keydown', onEsc);

                function finish(confirmed) {
                    document.removeEventListener('keydown', onEsc);
                    removeOverlay(overlay);
                    resolve(confirmed);
                }

                btnCancel.addEventListener('click', function () { finish(false); });
                btnClose.addEventListener('click', function () { finish(false); });
                overlay.addEventListener('click', function (ev) {
                    if (ev.target === overlay) finish(false);
                });

                btnConfirm.addEventListener('click', function () { finish(true); });

                requestAnimationFrame(function () {
                    overlay.classList.add('is-open');
                    btnConfirm.focus();
                });
            });
        },

        /**
         * Alert dialog replacing window.alert.
         * @param {string|Object} opts - Message string or { title, message, type }
         * @returns {Promise<void>}
         */
        alert: function (opts) {
            if (typeof opts === 'string') {
                opts = { message: opts };
            }
            opts = opts || {};
            return new Promise(function (resolve) {
                var overlay = createOverlay();
                var title = opts.title || 'Notice';
                var message = opts.message || '';

                overlay.innerHTML =
                    '<div class="psm-modal-card" role="dialog" aria-modal="true">' +
                        '<div class="psm-modal-header">' +
                            '<h3 class="psm-modal-title">' + escapeHtml(title) + '</h3>' +
                            '<button type="button" class="psm-modal-close" aria-label="Close">&times;</button>' +
                        '</div>' +
                        '<div class="psm-modal-body">' +
                            '<p class="psm-modal-text">' + escapeHtml(message) + '</p>' +
                        '</div>' +
                        '<div class="psm-modal-actions">' +
                            '<button type="button" class="psm-modal-btn psm-modal-confirm primary-btn">OK</button>' +
                        '</div>' +
                    '</div>';

                var btnConfirm = overlay.querySelector('.psm-modal-confirm');
                var btnClose = overlay.querySelector('.psm-modal-close');

                function onEsc(ev) {
                    if (ev.key === 'Escape') {
                        finish();
                    }
                }
                document.addEventListener('keydown', onEsc);

                function finish() {
                    document.removeEventListener('keydown', onEsc);
                    removeOverlay(overlay);
                    resolve();
                }

                btnConfirm.addEventListener('click', finish);
                btnClose.addEventListener('click', finish);
                overlay.addEventListener('click', function (ev) {
                    if (ev.target === overlay) finish();
                });

                requestAnimationFrame(function () {
                    overlay.classList.add('is-open');
                    btnConfirm.focus();
                });
            });
        },

        /**
         * Non-blocking Toast notification.
         * @param {string} message
         * @param {string} type - 'success' | 'error' | 'warn' | 'info'
         * @param {number} duration - Milliseconds
         */
        toast: function (message, type, duration) {
            type = type || 'info';
            duration = duration || 3500;

            var toast = document.createElement('div');
            toast.className = 'psm-toast is-' + type;

            var icon = 'ℹ️';
            if (type === 'success') icon = '✓';
            else if (type === 'error') icon = '⚠️';
            else if (type === 'warn') icon = '⚡';

            toast.innerHTML =
                '<span class="psm-toast-icon">' + icon + '</span>' +
                '<span class="psm-toast-msg">' + escapeHtml(message) + '</span>' +
                '<button type="button" class="psm-toast-close" aria-label="Dismiss">&times;</button>';

            getToastContainer().appendChild(toast);

            var closeBtn = toast.querySelector('.psm-toast-close');
            function dismiss() {
                toast.classList.add('is-leaving');
                setTimeout(function () {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 250);
            }

            closeBtn.addEventListener('click', dismiss);
            setTimeout(dismiss, duration);
        }
    };

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    root.PSMModal = PSMModal;
})(window);
