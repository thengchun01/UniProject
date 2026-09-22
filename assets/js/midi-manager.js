/**
 * midi-manager.js
 * Global MIDI device manager — loaded on every page via footer.php.
 *
 * Responsibilities:
 *  - Request Web MIDI access once per page-session (respects browser permission).
 *  - Watch for devices being plugged/unplugged at any time.
 *  - When a new MIDI device is detected, show a non-blocking toast prompting the
 *    user to connect it (unless they already said "yes" this session, or "no" for
 *    this device).
 *  - When the user accepts, route all incoming MIDI note-on/off messages from that
 *    device to a custom window event (`globalMidiMessage`) so every piano widget
 *    on the page (piano.js, piano-core.js, tutorial, games) can respond without
 *    managing their own requestMIDIAccess call.
 *  - Expose window.MidiManager for other scripts to query status / force-connect.
 */
(function () {
    "use strict";

    /* ── State ─────────────────────────────────────────── */
    const SESSION_KEY  = "psm_midi_connected_device";   // sessionStorage: name of accepted device
    const DECLINED_KEY = "psm_midi_declined_devices";   // sessionStorage: JSON array of declined names

    let midiAccess   = null;   // MIDIAccess object
    let connectedInput = null; // currently active MIDIInput
    let toastEl      = null;   // shared toast DOM node
    let promptDevice = null;   // MIDIInput currently being offered to the user

    /* ── Helpers ────────────────────────────────────────── */
    function getDeclined() {
        try { return JSON.parse(sessionStorage.getItem(DECLINED_KEY) || "[]"); } catch { return []; }
    }
    function addDeclined(name) {
        const list = getDeclined();
        if (!list.includes(name)) list.push(name);
        try { sessionStorage.setItem(DECLINED_KEY, JSON.stringify(list)); } catch {}
    }
    function getAcceptedDevice() {
        try { return sessionStorage.getItem(SESSION_KEY) || null; } catch { return null; }
    }
    function setAcceptedDevice(name) {
        try { sessionStorage.setItem(SESSION_KEY, name); } catch {}
    }
    function clearAcceptedDevice() {
        try { sessionStorage.removeItem(SESSION_KEY); } catch {}
    }

    /* ── Dispatch globalMidiMessage ─────────────────────── */
    function dispatchMidi(type, note, velocity) {
        window.dispatchEvent(new CustomEvent("globalMidiMessage", {
            detail: { type, note, velocity }
        }));
    }

    /* ── Bind a MIDIInput ───────────────────────────────── */
    function bindInput(input) {
        if (connectedInput === input) return;

        // Unbind previous
        if (connectedInput) {
            connectedInput.onmidimessage = null;
        }

        connectedInput = input;
        input.onmidimessage = function (event) {
            const [status, note, velocity] = event.data;
            const command = status & 0xf0;
            if (command === 0x90 && velocity > 0) {
                dispatchMidi("noteon",  note, velocity);
            } else if (command === 0x80 || (command === 0x90 && velocity === 0)) {
                dispatchMidi("noteoff", note, velocity);
            }
        };

        setAcceptedDevice(input.name);
        updatePianoPageStatus(input.name, true);
        dismissToast();
    }

    /* ── Unbind (device disconnected / user declined) ───── */
    function unbindCurrent() {
        if (connectedInput) {
            connectedInput.onmidimessage = null;
            connectedInput = null;
        }
        clearAcceptedDevice();
        updatePianoPageStatus("", false);
    }

    /* ── Update piano.php status indicators if present ──── */
    function updatePianoPageStatus(name, connected) {
        const statusText = document.getElementById("midi-status-text");
        const statusDot  = document.querySelector("#piano-app .status-dot");
        if (statusText) {
            statusText.textContent = connected ? "Connected: " + name : "No MIDI Device";
        }
        if (statusDot) {
            statusDot.className = "status-dot" + (connected ? " connected" : "");
        }
    }

    /* ── Toast UI ───────────────────────────────────────── */
    function buildToast() {
        if (toastEl) return;

        toastEl = document.createElement("div");
        toastEl.id = "midi-connect-toast";
        toastEl.className = "midi-toast";
        toastEl.setAttribute("role", "dialog");
        toastEl.setAttribute("aria-modal", "false");
        toastEl.setAttribute("aria-label", "MIDI device detected");

        toastEl.innerHTML = `
            <div class="midi-toast-icon">🎹</div>
            <div class="midi-toast-body">
                <p class="midi-toast-title">MIDI Device Detected</p>
                <p class="midi-toast-name" id="midi-toast-device-name"></p>
            </div>
            <div class="midi-toast-actions">
                <button class="midi-toast-btn midi-toast-accept" id="midi-toast-accept" type="button">Connect</button>
                <button class="midi-toast-btn midi-toast-dismiss" id="midi-toast-dismiss" type="button">Not now</button>
            </div>`;

        document.body.appendChild(toastEl);

        document.getElementById("midi-toast-accept").addEventListener("click", () => {
            if (promptDevice) {
                bindInput(promptDevice);
            }
            promptDevice = null;
        });

        document.getElementById("midi-toast-dismiss").addEventListener("click", () => {
            if (promptDevice) {
                addDeclined(promptDevice.name);
            }
            promptDevice = null;
            dismissToast();
        });
    }

    function showToast(deviceName) {
        buildToast();
        const titleEl = toastEl.querySelector(".midi-toast-title");
        const nameEl = document.getElementById("midi-toast-device-name");
        const acceptBtn = document.getElementById("midi-toast-accept");
        if (titleEl) titleEl.textContent = "MIDI Device Detected";
        if (nameEl) nameEl.textContent = deviceName;
        if (acceptBtn) acceptBtn.style.display = "";
        // Force reflow before adding the visible class for CSS transition
        toastEl.classList.remove("midi-toast-visible");
        void toastEl.offsetWidth;
        toastEl.classList.add("midi-toast-visible");
    }

    function showNotice(title, message) {
        buildToast();
        const titleEl = toastEl.querySelector(".midi-toast-title");
        const nameEl = document.getElementById("midi-toast-device-name");
        const acceptBtn = document.getElementById("midi-toast-accept");
        if (titleEl) titleEl.textContent = title;
        if (nameEl) nameEl.textContent = message;
        // Notice mode has no device to connect, so hide the Connect button.
        if (acceptBtn) acceptBtn.style.display = "none";
        promptDevice = null;
        toastEl.classList.remove("midi-toast-visible");
        void toastEl.offsetWidth;
        toastEl.classList.add("midi-toast-visible");
    }

    function dismissToast() {
        if (toastEl) {
            toastEl.classList.remove("midi-toast-visible");
        }
    }

    /* ── Offer a device to the user ─────────────────────── */
    function offerDevice(input) {
        if (getDeclined().includes(input.name)) return; // user said "not now" before
        promptDevice = input;
        showToast(input.name);
    }

    /* ── Scan all currently connected inputs ─────────────── */
    function scanInputs() {
        if (!midiAccess) return;

        const inputs = Array.from(midiAccess.inputs.values());
        if (!inputs.length) {
            unbindCurrent();
            return;
        }

        const acceptedName = getAcceptedDevice();

        // Re-bind the previously accepted device if it's still plugged in
        if (acceptedName) {
            const previous = inputs.find(i => i.name === acceptedName);
            if (previous) {
                bindInput(previous);
                return;
            }
            // Previously accepted device is gone — clear
            unbindCurrent();
        }

        // Offer the first non-declined device that isn't already connected
        const candidate = inputs.find(i => !getDeclined().includes(i.name));
        if (candidate && candidate !== connectedInput) {
            offerDevice(candidate);
        }
    }

    /* ── Handle device state changes (plug / unplug) ─────── */
    function onStateChange(event) {
        const port = event.port;
        if (port.type !== "input") return;

        if (port.state === "connected") {
            // Small delay so the device is fully initialised
            setTimeout(() => scanInputs(), 300);
        } else if (port.state === "disconnected") {
            dismissToast();
            if (connectedInput && connectedInput.id === port.id) {
                unbindCurrent();
                // Offer another available device if any
                setTimeout(() => scanInputs(), 300);
            }
        }
    }

    /* ── Public API ──────────────────────────────────────── */
    window.MidiManager = {
        /** Returns the currently connected MIDIInput, or null. */
        getConnectedInput() { return connectedInput; },

        /** Returns true if a MIDI input is currently bound. */
        isConnected() { return connectedInput !== null; },

        /** Programmatically trigger connection (e.g. from piano.php button). */
        async connect() {
            if (!midiAccess) await init();
            const inputs = midiAccess ? Array.from(midiAccess.inputs.values()) : [];
            if (!inputs.length) {
                showNotice("No MIDI Devices Found", "Plug in a MIDI device and try again.");
                return;
            }
            // If already connected, do nothing
            if (connectedInput) return;
            // Bind first available input directly (user triggered this manually)
            bindInput(inputs[0]);
        },

        /** Disconnect the current device. */
        disconnect() {
            addDeclined(connectedInput?.name || "");
            unbindCurrent();
        },

        /** Re-scan and prompt if a device is available. */
        scan() { scanInputs(); }
    };

    /* ── Initialise MIDI access ──────────────────────────── */
    async function init() {
        if (!navigator.requestMIDIAccess) return; // browser doesn't support Web MIDI

        try {
            midiAccess = await navigator.requestMIDIAccess({ sysex: false });
            midiAccess.onstatechange = onStateChange;
            scanInputs();
        } catch {
            // User denied permission or browser error — silently do nothing
        }
    }

    // Start after DOM is ready (toast needs document.body)
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init, { once: true });
    } else {
        init();
    }
})();
