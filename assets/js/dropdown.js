/* Themed drop-down replacement for native <select> (English-only UI).
 *
 * Auto-attaches to single-choice selects (skips [multiple], [size>1] and
 * [data-native]). The native control stays in the form (hidden but rendered,
 * so required-validation and submission work unchanged) while a themed
 * button + popup listbox handles all interaction. The native value is the
 * single source of truth: picking writes select.value and dispatches
 * input + change, so inline handlers (form submit), page listeners and
 * direct .value reads keep working. Options are re-read on every open, so
 * dynamically repopulated lists never go stale.
 */
(function () {
    'use strict';

    var CHEV = '<svg width="12" height="8" viewBox="0 0 12 8" fill="none"'
        + ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
        + ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
        + '<path d="M1 1l5 5 5-5"/></svg>';

    var uid = 0;
    var openInst = null;
    var lastPointer = 0;

    function fieldLabel(select) {
        var labelled = select.getAttribute('aria-label');
        if (labelled) return labelled;
        if (select.id) {
            var forLabel = document.querySelector('label[for="' + select.id + '"]');
            if (forLabel) return forLabel.textContent.replace(/\s+/g, ' ').trim();
        }
        var p = select.parentNode;
        while (p && p !== document.body) {
            if (p.tagName === 'LABEL') {
                var t = '';
                Array.prototype.forEach.call(p.childNodes, function (n) {
                    if (n === select) return;
                    if (n.nodeType === 1) {
                        if (n.tagName === 'SELECT' || n.tagName === 'BUTTON') return;
                        if (n.classList && n.classList.contains('dd-wrap')) return;
                        t += n.textContent;
                        return;
                    }
                    t += n.nodeValue;
                });
                t = t.replace(/\s+/g, ' ').trim();
                if (t) return t.length > 60 ? t.slice(0, 60) + '…' : t;
                return select.name || 'Choose an option';
            }
            p = p.parentNode;
        }
        return select.name || 'Choose an option';
    }

    function closeOpen() {
        if (!openInst) return;
        openInst.list.hidden = true;
        openInst.button.setAttribute('aria-expanded', 'false');
        openInst.active = -1;
        openInst = null;
    }

    function optionsOf(select) {
        var out = [];
        Array.prototype.forEach.call(select.options, function (o) {
            out.push({ value: o.value, text: o.text, disabled: !!o.disabled, group: null });
        });
        return out;
    }

    function paint(inst) {
        var sel = inst.select.options[inst.select.selectedIndex];
        inst.label.textContent = sel ? sel.text : '';
        inst.button.disabled = !!inst.select.disabled;
    }

    function syncFromNative(inst) {
        if (openInst === inst) render(inst);
        else paint(inst);
    }

    function pick(inst, index) {
        var opts = optionsOf(inst.select);
        if (!opts[index] || opts[index].disabled) return;
        inst.select.selectedIndex = index;
        inst.select.dispatchEvent(new Event('input', { bubbles: true }));
        inst.select.dispatchEvent(new Event('change', { bubbles: true }));
        paint(inst);
        closeOpen();
        inst.button.focus();
    }

    function render(inst) {
        paint(inst);
        inst.items = [];
        inst.list.textContent = '';
        var opts = optionsOf(inst.select);
        if (!opts.length) {
            var empty = document.createElement('li');
            empty.className = 'dd-item is-empty';
            empty.textContent = 'No options';
            empty.setAttribute('aria-disabled', 'true');
            inst.list.appendChild(empty);
            return;
        }
        opts.forEach(function (o, i) {
            var li = document.createElement('li');
            li.className = 'dd-item';
            li.id = inst.baseId + '-i-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', i === inst.select.selectedIndex ? 'true' : 'false');
            li.textContent = o.text;
            if (o.disabled) {
                li.classList.add('is-disabled');
                li.setAttribute('aria-disabled', 'true');
            } else {
                if (i === inst.select.selectedIndex) li.classList.add('is-selected');
                li.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    pick(inst, i);
                });
            }
            inst.list.appendChild(li);
            inst.items.push(li);
        });
        inst.active = inst.select.selectedIndex;
        markActive(inst);
    }

    function markActive(inst) {
        inst.items.forEach(function (li, i) {
            if (i === inst.active) {
                inst.button.setAttribute('aria-activedescendant', li.id);
                li.classList.add('is-active');
                if (openInst === inst && li.scrollIntoView) {
                    try { li.scrollIntoView({ block: 'nearest' }); } catch (e) {}
                }
            } else {
                li.classList.remove('is-active');
            }
        });
    }

    function step(inst, dir) {
        if (!inst.items.length) return;
        var i = inst.active;
        for (var n = 0; n < inst.items.length; n++) {
            i = (i + dir + inst.items.length) % inst.items.length;
            if (!inst.items[i].classList.contains('is-disabled')) break;
        }
        inst.active = i;
        markActive(inst);
    }

    function typeAhead(inst, ch) {
        if (!inst.items.length) return;
        inst.search = (inst.search || '') + ch.toLowerCase();
        clearTimeout(inst.searchTimer);
        inst.searchTimer = setTimeout(function () { inst.search = ''; }, 600);
        var opts = optionsOf(inst.select);
        for (var k = 0; k < opts.length; k++) {
            var idx = (inst.active + 1 + k) % opts.length;
            if (!opts[idx].disabled && opts[idx].text.toLowerCase().indexOf(inst.search) === 0) {
                inst.active = idx;
                markActive(inst);
                return;
            }
        }
    }

    function open(inst) {
        closeOpen();
        render(inst);
        inst.list.hidden = false;
        inst.button.setAttribute('aria-expanded', 'true');
        openInst = inst;
    }

    function attach(select) {
        if (select._ddAttached) return;
        if (select.multiple) return;
        if (select.hasAttribute('data-native')) return;
        var size = parseInt(select.getAttribute('size') || '1', 10);
        if (size > 1) return;
        select._ddAttached = true;
        uid++;

        var wrap = document.createElement('span');
        wrap.className = 'dd-wrap';
        var inRow = false;
        try {
            var cs = window.getComputedStyle(select.parentNode);
            inRow = (cs.display === 'flex' || cs.display === 'inline-flex')
                && cs.flexDirection !== 'column' && cs.flexDirection !== 'column-reverse';
        } catch (e) {}
        // Layout lives inline so page rules cannot break it: fill grid cells
        // and labels, but stay compact inside flex-row toolbars.
        wrap.style.cssText = 'position:relative;display:' + (inRow ? 'inline-flex' : 'flex')
            + ';align-items:center;' + (inRow ? '' : 'width:100%;');
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        // Visually hidden but still rendered: required-validation and form
        // submission keep working on the native control itself.
        select.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;'
            + 'opacity:0;margin:0;pointer-events:none;';
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'dd-btn';
        button.style.cssText = 'display:inline-flex;align-items:center;justify-content:space-between;'
            + 'gap:8px;margin:0;flex:none;' + (inRow ? '' : 'width:100%;');
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        var labelled = select.getAttribute('aria-label');
        if (labelled) button.setAttribute('aria-label', labelled);
        else {
            // Unassociated labels (e.g. admin dialogs use bare <label> text):
            // fall back to the field name so the button is never nameless.
            var hasLabel = false;
            try { hasLabel = !!(select.labels && select.labels.length); } catch (e) {}
            if (!hasLabel) button.setAttribute('aria-label', fieldLabel(select));
        }

        var label = document.createElement('span');
        label.className = 'dd-label';
        var chev = document.createElement('span');
        chev.className = 'dd-chev';
        chev.setAttribute('aria-hidden', 'true');
        chev.innerHTML = CHEV;
        button.appendChild(label);
        button.appendChild(chev);

        var list = document.createElement('ul');
        list.className = 'dd-list';
        list.hidden = true;
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', fieldLabel(select));

        wrap.appendChild(button);
        wrap.appendChild(list);

        var inst = {
            select: select, wrap: wrap, button: button, label: label, list: list,
            items: [], active: -1, baseId: 'dd-' + uid, search: '', searchTimer: null
        };
        list.id = inst.baseId + '-list';
        button.setAttribute('aria-controls', list.id);

        paint(inst);

        button.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (openInst === inst) closeOpen();
            else open(inst);
        });
        button.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (openInst !== inst) open(inst);
                step(inst, e.key === 'ArrowDown' ? 1 : -1);
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (openInst === inst && inst.active > -1) pick(inst, inst.active);
                else open(inst);
            } else if (e.key === 'Escape') {
                if (openInst === inst) { e.preventDefault(); closeOpen(); }
            } else if (e.key.length === 1) {
                if (openInst !== inst) open(inst);
                typeAhead(inst, e.key);
            }
        });
        button.addEventListener('focusout', function () {
            setTimeout(function () {
                // A mouse/touch press moves focus before its tap lands: never
                // dismiss for those (the tap handler or outside-closer decides).
                // Only keyboard tabbing away dismisses here.
                if (openInst === inst
                    && Date.now() - lastPointer > 500
                    && (!document.activeElement || !wrap.contains(document.activeElement))) {
                    closeOpen();
                }
            }, 0);
        });
        select.addEventListener('input', function () { syncFromNative(inst); });
        select.addEventListener('change', function () { syncFromNative(inst); });
        var form = select.form;
        if (form) {
            form.addEventListener('reset', function () {
                setTimeout(function () { paint(inst); }, 0);
            });
        }
    }

    document.addEventListener('pointerdown', function (e) {
        lastPointer = Date.now();
        if (openInst && !openInst.wrap.contains(e.target)) closeOpen();
    });
    // Click fallback: some older browsers never fire pointerdown, which left
    // popups open over the page (covering buttons like Apply). Click fires
    // everywhere, so outside-clicks always dismiss.
    document.addEventListener('click', function (e) {
        if (openInst && !openInst.wrap.contains(e.target)) closeOpen();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openInst) {
            var b = openInst.button;
            closeOpen();
            b.focus();
        }
    });
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('select:not([multiple]):not([data-native])'),
            function (sel) {
                // One exotic select must never break the rest: on failure
                // the native control simply stays (and keeps working).
                try { attach(sel); } catch (e) {}
            }
        );
    });
})();
