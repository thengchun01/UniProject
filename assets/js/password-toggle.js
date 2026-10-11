/* Show/hide password toggle (eye button).
 *
 * Auto-attaches to every input[type="password"]: an eye button is overlaid
 * inside the field that flips it between password and text so users can
 * check what they typed. Fields always start hidden. Only the field type
 * changes — values, names, validation and submission are untouched.
 */
(function () {
    'use strict';

    var EYE = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"'
        + ' stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">'
        + '<path d="M1.5 8S3.8 3.8 8 3.8 14.5 8 14.5 8 12.2 12.2 8 12.2 1.5 8 1.5 8z"/>'
        + '<circle cx="8" cy="8" r="1.8"/></svg>';
    var EYE_OFF = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"'
        + ' stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">'
        + '<path d="M1.5 8S3.8 3.8 8 3.8 14.5 8 14.5 8 12.2 12.2 8 12.2 1.5 8 1.5 8z"/>'
        + '<path d="M2.5 2.5l11 11"/></svg>';

    function attach(input) {
        if (input._pwAttached) return;
        input._pwAttached = true;

        var wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        // Flex row lives inline so the layout holds even if page stylesheets
        // are stale or override the component classes: the button can never
        // escape the box because it is in normal flow, pulled over the
        // input's reserved right padding with a negative margin.
        wrap.style.cssText = 'display:flex;align-items:center;width:100%;';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        // Reserve room for the overlaid eye button. Inline style beats any
        // page-level input padding, so typed text never slides underneath it.
        input.style.paddingRight = '36px';
        input.style.flex = '1';
        input.style.minWidth = '0';
        input.style.width = 'auto';
        // Fields always start hidden, no matter what markup or plugin state
        // the page was loaded with.
        input.type = 'password';

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'pw-toggle';
        toggle.tabIndex = 0;
        // Fixed geometry lives inline so page-level button rules (e.g. the
        // full-width form buttons on account.php) can never stretch this
        // overlay; the stylesheet keeps theming only.
        toggle.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;'
            + 'width:30px;height:30px;padding:0;margin:0 6px 0 -36px;flex:none;';
        wrap.appendChild(toggle);

        function paint() {
            // Status-based icons: open eye means the text is visible,
            // crossed eye means it is hidden.
            var shown = input.type === 'text';
            toggle.innerHTML = shown ? EYE : EYE_OFF;
            toggle.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', shown ? 'true' : 'false');
            toggle.title = shown ? 'Hide password' : 'Show password';
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            input.type = input.type === 'password' ? 'text' : 'password';
            paint();
            input.focus();
        });

        paint();
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('input[type="password"]'),
            attach
        );
    });
})();
