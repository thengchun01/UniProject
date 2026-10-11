/* Themed date-picker popup (English-only, locale-independent).
 *
 * Auto-attaches to text inputs carrying [data-date-picker]. An optional
 * data-disable-sunday="1" attribute greys out Sundays (lessons are closed).
 * Picking a date writes YYYY-MM-DD into the field and dispatches input +
 * change events, so existing form wiring (weekday badge, week jump,
 * clash tables, filters) keeps working unchanged.
 */
(function () {
    'use strict';

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    var RE = /^(\d{4})-(\d{2})-(\d{2})$/;
    var ICON = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"'
        + ' stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">'
        + '<rect x="1.5" y="2.5" width="13" height="12" rx="2"/>'
        + '<path d="M1.5 6h13M5 1v3M11 1v3"/></svg>';

    var openInst = null;

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function parse(value) {
        var m = RE.exec(value || '');
        if (!m) return null;
        var y = parseInt(m[1], 10), mo = parseInt(m[2], 10), d = parseInt(m[3], 10);
        if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
        var dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
        return { y: y, m: mo, d: d };
    }

    function closeOpen() {
        if (!openInst) return;
        openInst.popup.hidden = true;
        openInst.toggle.setAttribute('aria-expanded', 'false');
        openInst = null;
    }

    function pick(inst, value) {
        inst.input.value = value;
        inst.input.dispatchEvent(new Event('input', { bubbles: true }));
        inst.input.dispatchEvent(new Event('change', { bubbles: true }));
        closeOpen();
        inst.input.focus();
    }

    function render(inst) {
        inst.title.textContent = MONTHS[inst.viewM - 1] + ' ' + inst.viewY;
        inst.days.textContent = '';
        var lead = (new Date(inst.viewY, inst.viewM - 1, 1).getDay() + 6) % 7;
        var dim = new Date(inst.viewY, inst.viewM, 0).getDate();
        var sel = parse(inst.input.value);
        var today = new Date();
        var i, d;
        for (i = 0; i < lead; i++) {
            var blank = document.createElement('span');
            blank.className = 'dp-blank';
            blank.setAttribute('aria-hidden', 'true');
            inst.days.appendChild(blank);
        }
        for (d = 1; d <= dim; d++) {
            (function (day) {
                var dt = new Date(inst.viewY, inst.viewM - 1, day);
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'dp-day';
                b.textContent = String(day);
                b.setAttribute('aria-label', day + ' ' + MONTHS[inst.viewM - 1] + ' ' + inst.viewY);
                if (dt.toDateString() === today.toDateString()) b.classList.add('is-today');
                if (sel && sel.y === inst.viewY && sel.m === inst.viewM && sel.d === day) {
                    b.classList.add('is-selected');
                }
                if (dt.getDay() === 0) {
                    b.classList.add('is-sun');
                    b.title = 'Closed on Sundays';
                    if (inst.disableSunday) b.disabled = true;
                }
                b.addEventListener('click', function () {
                    pick(inst, inst.viewY + '-' + pad(inst.viewM) + '-' + pad(day));
                });
                inst.days.appendChild(b);
            })(d);
        }
    }

    function open(inst) {
        closeOpen();
        var sel = parse(inst.input.value);
        var now = new Date();
        inst.viewY = sel ? sel.y : now.getFullYear();
        inst.viewM = sel ? sel.m : now.getMonth() + 1;
        render(inst);
        inst.popup.hidden = false;
        inst.toggle.setAttribute('aria-expanded', 'true');
        openInst = inst;
    }

    function attach(input) {
        if (input._dpAttached) return;
        input._dpAttached = true;

        var wrap = document.createElement('span');
        wrap.className = 'dp-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'dp-toggle';
        toggle.setAttribute('aria-label', 'Choose date from calendar');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = ICON;

        var popup = document.createElement('div');
        popup.className = 'dp-cal';
        popup.hidden = true;
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-label', 'Choose date');

        var head = document.createElement('div');
        head.className = 'dp-head';
        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'dp-nav';
        prev.textContent = '‹';
        prev.setAttribute('aria-label', 'Previous month');
        var title = document.createElement('div');
        title.className = 'dp-title';
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'dp-nav';
        next.textContent = '›';
        next.setAttribute('aria-label', 'Next month');
        head.appendChild(prev);
        head.appendChild(title);
        head.appendChild(next);

        var dow = document.createElement('div');
        dow.className = 'dp-dow';
        dow.setAttribute('aria-hidden', 'true');
        DOW.forEach(function (name) {
            var s = document.createElement('span');
            s.textContent = name;
            dow.appendChild(s);
        });

        var days = document.createElement('div');
        days.className = 'dp-days';

        popup.appendChild(head);
        popup.appendChild(dow);
        popup.appendChild(days);
        wrap.appendChild(toggle);
        wrap.appendChild(popup);

        var inst = {
            input: input,
            wrap: wrap,
            toggle: toggle,
            popup: popup,
            title: title,
            days: days,
            viewY: 0,
            viewM: 1,
            disableSunday: input.getAttribute('data-disable-sunday') === '1'
        };

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (openInst === inst) {
                closeOpen();
            } else {
                open(inst);
            }
        });
        prev.addEventListener('click', function () {
            inst.viewM--;
            if (inst.viewM < 1) { inst.viewM = 12; inst.viewY--; }
            render(inst);
        });
        next.addEventListener('click', function () {
            inst.viewM++;
            if (inst.viewM > 12) { inst.viewM = 1; inst.viewY++; }
            render(inst);
        });
    }

    document.addEventListener('pointerdown', function (e) {
        if (openInst && !openInst.popup.contains(e.target) && !openInst.wrap.contains(e.target)) {
            closeOpen();
        }
    });
    // Click fallback for browsers without pointer events (see dropdown.js).
    document.addEventListener('click', function (e) {
        if (openInst && !openInst.popup.contains(e.target) && !openInst.wrap.contains(e.target)) {
            closeOpen();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openInst) {
            var t = openInst.toggle;
            closeOpen();
            t.focus();
        }
    });
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('input[data-date-picker]'),
            attach
        );
    });
})();
