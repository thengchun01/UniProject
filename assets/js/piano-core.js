(function () {
    "use strict";

    const START_NOTE = 48; // C3
    const END_NOTE = 83; // B5
    const NOTE_NAMES = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"];
    const DEFAULT_KEY_MAP = {
        c: 60, f: 61, v: 62, g: 63, b: 64, n: 65,
        j: 66, m: 67, k: 68, ",": 69, l: 70, ".": 71
    };
    const IS_TOUCH = "ontouchstart" in window || navigator.maxTouchPoints > 0;

    let keyWidthWhite = 40;
    let keyWidthBlack = 24;
    let synth = null;
    let audioReady = false;

    function midiName(midi) {
        return NOTE_NAMES[midi % 12] + (Math.floor(midi / 12) - 1);
    }

    function isBlackKey(midi) {
        return NOTE_NAMES[midi % 12].includes("#");
    }

    function getVexFlow() {
        return window.VexFlow || (window.Vex && window.Vex.Flow) || null;
    }

    function loadKeyMap() {
        try {
            return JSON.parse(localStorage.getItem("pianoKeyBinds") || "null") || { ...DEFAULT_KEY_MAP };
        } catch (e) {
            return { ...DEFAULT_KEY_MAP };
        }
    }

    function saveKeyMap(map) {
        try { localStorage.setItem("pianoKeyBinds", JSON.stringify(map)); } catch (e) {}
    }

    function syncKeySizing(options) {
        keyWidthWhite = IS_TOUCH ? 44 : 40;
        keyWidthBlack = IS_TOUCH ? 26 : 24;
        const root = options?.root || document.documentElement;
        root.style.setProperty("--key-w-white", keyWidthWhite + "px");
        root.style.setProperty("--key-w-black", keyWidthBlack + "px");
    }

    function vfKeyForMidi(midi) {
        const name = NOTE_NAMES[midi % 12].toLowerCase();
        let octave = Math.floor(midi / 12) - 1;
        if (octave >= 6) octave = 5;
        if (octave <= 3) octave = 4;
        return {
            key: name.replace("#", "") + "/" + octave,
            sharp: name.includes("#")
        };
    }

    async function initAudio() {
        if (!window.Tone) return null;
        if (audioReady) {
            if (Tone.context?.state === "suspended") {
                try { await Tone.context.resume(); } catch (e) {}
            }
            return synth;
        }
        await Tone.start();
        synth = new Tone.PolySynth(Tone.Synth, {
            oscillator: { type: "amtriangle" },
            envelope: { attack: 0.01, decay: 0.1, sustain: 0.55, release: 1.2 }
        }).toDestination();
        audioReady = true;
        return synth;
    }

    function createPianoKeys(container, options) {
        if (!container) return;
        const start = options.start;
        const end = options.end;
        const onDown = options.onDown || function () {};
        const onUp = options.onUp || function () {};
        const showLabels = !!options.showLabels;
        const activeTouches = new Map();
        const activeMouseNotes = new Set();
        container.innerHTML = "";

        // Read the container's actual pixel height — no CSS chain dependency
        const containerH = container.clientHeight || container.offsetHeight || 200;

        const wrapper = document.createElement("div");
        const whiteCount = Array.from({ length: end - start + 1 }, (_, i) => start + i).filter(n => !isBlackKey(n)).length;
        wrapper.style.cssText = "position:relative;height:" + containerH + "px;width:" + (whiteCount * keyWidthWhite) + "px;margin:" + (options.centered ? "0 auto" : "0") + ";";
        let whiteX = 0;

        for (let midi = start; midi <= end; midi++) {
            const black = isBlackKey(midi);
            const key = document.createElement("div");
            key.className = "key " + (black ? "key-black" : "key-white");
            key.dataset.midi = String(midi);
            key.title = midiName(midi);
            key.style.left = black ? (whiteX - keyWidthBlack / 2) + "px" : whiteX + "px";
            // Set explicit pixel heights inline — no dependency on CSS height:100% chain
            key.style.height = black ? Math.round(containerH * 0.63) + "px" : containerH + "px";
            if (!black) whiteX += keyWidthWhite;
            if (showLabels) {
                const label = document.createElement("span");
                label.className = "key-label";
                label.textContent = midi % 12 === 0 ? midiName(midi).toUpperCase() : NOTE_NAMES[midi % 12];
                label.style.cssText = "position:absolute;left:50%;bottom:" + (black ? "10px" : "6px") + ";transform:translateX(-50%);font-size:11px;color:" + (black ? "rgba(255,255,255,.68)" : "#607080") + ";pointer-events:none";
                key.appendChild(label);
            }
            key.addEventListener("mousedown", async event => {
                if (event.button !== 0) return;
                event.preventDefault();
                await initAudio();
                activeMouseNotes.add(midi);
                onDown(midi, "mouse");
            });
            key.addEventListener("mouseenter", async event => {
                if ((event.buttons & 1) === 0) return;
                await initAudio();
                if (activeMouseNotes.has(midi)) return;
                activeMouseNotes.add(midi);
                onDown(midi, "mouse");
            });
            key.addEventListener("mouseup", event => {
                if (event.button !== 0 || !activeMouseNotes.has(midi)) return;
                activeMouseNotes.delete(midi);
                onUp(midi, "mouse");
            });
            key.addEventListener("mouseleave", () => {
                if (!activeMouseNotes.has(midi)) return;
                activeMouseNotes.delete(midi);
                onUp(midi, "mouse");
            });
            wrapper.appendChild(key);
        }

        container.appendChild(wrapper);
        if (IS_TOUCH && !container.dataset.coreTouchBound) {
            container.dataset.coreTouchBound = "1";
            container.addEventListener("touchstart", async event => {
                event.preventDefault();
                await initAudio();
                for (const touch of event.changedTouches) {
                    const key = document.elementFromPoint(touch.clientX, touch.clientY)?.closest(".key");
                    if (key && container.contains(key)) {
                        const midi = Number(key.dataset.midi);
                        activeTouches.set(touch.identifier, midi);
                        onDown(midi, "touch");
                    }
                }
            }, { passive: false });
            container.addEventListener("touchend", event => {
                event.preventDefault();
                for (const touch of event.changedTouches) {
                    const midi = activeTouches.get(touch.identifier);
                    if (midi !== undefined) {
                        onUp(midi, "touch");
                        activeTouches.delete(touch.identifier);
                    }
                }
            }, { passive: false });
        }

        if (!container.dataset.coreKeyBound) {
            container.dataset.coreKeyBound = "1";
            // Shared preset map (tutorial default "double") so the chosen
            // preset plays the same on every page.
            const keybindMap = getKeybindMap("double");
            document.addEventListener("keydown", async event => {
                if (!document.body.contains(container)) return;
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;

                const key = event.key.toLowerCase();
                const midi = keybindMap[key];
                if (midi !== undefined && midi >= start && midi <= end) {
                    event.preventDefault();
                    if (!activeMouseNotes.has(midi)) {
                        activeMouseNotes.add(midi);
                        await initAudio();
                        onDown(midi, "keyboard");
                    }
                }
            });
            document.addEventListener("keyup", event => {
                if (!document.body.contains(container)) return;
                const key = event.key.toLowerCase();
                const midi = keybindMap[key];
                if (midi !== undefined && midi >= start && midi <= end) {
                    event.preventDefault();
                    if (activeMouseNotes.has(midi)) {
                        activeMouseNotes.delete(midi);
                        onUp(midi, "keyboard");
                    }
                }
            });
        }

        if (!container.dataset.coreMidiBound) {
            container.dataset.coreMidiBound = "1";
            window.addEventListener('globalMidiMessage', async event => {
                if (!document.body.contains(container)) return;
                const { type, note } = event.detail;
                if (note >= start && note <= end) {
                    if (type === 'noteon') {
                        if (!activeMouseNotes.has(note)) {
                            activeMouseNotes.add(note);
                            await initAudio();
                            onDown(note, "midi");
                        }
                    } else if (type === 'noteoff') {
                        if (activeMouseNotes.has(note)) {
                            activeMouseNotes.delete(note);
                            onUp(note, "midi");
                        }
                    }
                }
            });
        }
    }

    const KEYBOARD_MAP = {
        'tab': 48, '1': 49, 'q': 50, '2': 51, 'w': 52, 'e': 53, '4': 54, 'r': 55, '5': 56, 't': 57, '6': 58, 'y': 59,
        'u': 60, '8': 61, 'i': 62, '9': 63, 'o': 64, 'p': 65, '-': 66, '[': 67, '=': 68, ']': 69, 'backspace': 70, '\\': 71
    };

    /* ── Shared keybind presets (one map for every page) ────────────── */
    // single: one-hand home-row map (C4–B4), the piano page default.
    // double: two-hand chromatic map (C3–B4), the tutorial default.
    // custom: the user's own map from synced user settings.
    const KEYBIND_PRESETS = {
        single: {
            c: 60, f: 61, v: 62, g: 63, b: 64, n: 65,
            j: 66, m: 67, k: 68, ",": 69, l: 70, ".": 71
        },
        double: { ...KEYBOARD_MAP }
    };
    const KEYBIND_PRESET_KEY = "pianoKeybindPreset";
    const KEYBIND_PENDING_KEY = "pianoKeybindsPending";

    function cleanKeybindMap(source) {
        const cleaned = {};
        if (!source || typeof source !== "object") return cleaned;
        Object.entries(source).forEach(([key, midi]) => {
            const note = Number(midi);
            if (typeof key === "string" && key.length >= 1 && Number.isInteger(note) && note >= 21 && note <= 108) {
                cleaned[key] = note;
            }
        });
        return cleaned;
    }

    // Explicit user choice on this browser, else the synced server choice.
    function getKeybindPresetId() {
        try {
            const id = localStorage.getItem(KEYBIND_PRESET_KEY);
            if (id === "single" || id === "double" || id === "custom") return id;
        } catch (e) {}
        try {
            const serverId = window.PSM_CONFIG?.userSettings?.keybindPreset;
            if (window.PSM_CONFIG?.isLoggedIn && (serverId === "single" || serverId === "double" || serverId === "custom")) {
                return serverId;
            }
        } catch (e) {}
        return null;
    }

    function setKeybindPresetId(id) {
        try {
            if (id === "single" || id === "double" || id === "custom") localStorage.setItem(KEYBIND_PRESET_KEY, id);
            else localStorage.removeItem(KEYBIND_PRESET_KEY);
        } catch (e) {}
        return getKeybindPresetId();
    }

    function markKeybindsPending() {
        try { localStorage.setItem(KEYBIND_PENDING_KEY, "1"); } catch (e) {}
    }

    function clearKeybindsPending() {
        try { localStorage.removeItem(KEYBIND_PENDING_KEY); } catch (e) {}
    }

    function getCustomKeybinds() {
        // A locally saved map whose push never confirmed (e.g. saved then
        // navigated away before the request finished) is fresher than
        // anything the server has — prefer it until a push succeeds.
        try {
            if (localStorage.getItem(KEYBIND_PENDING_KEY) === "1") {
                const stored = localStorage.getItem("pianoKeyBinds");
                if (stored) {
                    const parsed = JSON.parse(stored);
                    if (parsed && typeof parsed === "object" && Object.keys(parsed).length) return parsed;
                }
            }
        } catch (e) {}
        // Logged-in users take the server copy when one exists (server wins).
        try {
            if (window.PSM_CONFIG?.isLoggedIn) {
                const cleaned = cleanKeybindMap(window.PSM_CONFIG?.userSettings?.keybinds);
                if (Object.keys(cleaned).length) {
                    try { localStorage.setItem("pianoKeyBinds", JSON.stringify(cleaned)); } catch (e) {}
                    return cleaned;
                }
            }
        } catch (e) {}
        try {
            const stored = localStorage.getItem("pianoKeyBinds");
            if (stored) {
                const parsed = JSON.parse(stored);
                if (parsed && typeof parsed === "object") return parsed;
            }
        } catch (e) {}
        return null;
    }

    // Resolve the computer-keyboard map every page plays with. pageDefault
    // ("single" on the piano page, "double" in tutorials) applies until the
    // user explicitly picks a preset, which then wins on every page.
    function getKeybindMap(pageDefault) {
        const fallback = pageDefault === "double" ? "double" : "single";
        const id = getKeybindPresetId() || fallback;
        if (id === "custom") {
            const custom = getCustomKeybinds();
            if (custom && Object.keys(custom).length) return custom;
            return { ...KEYBIND_PRESETS.single };
        }
        return { ...(KEYBIND_PRESETS[id] || KEYBIND_PRESETS[fallback]) };
    }

    function renderStaff(container, notes, options) {
        if (!container) return [];
        const VF = getVexFlow();
        const noteMap = [];
        container.innerHTML = "";
        if (!VF) {
            container.textContent = notes.map(note => note.name || midiName(note.midi)).join(" ");
            return noteMap;
        }

        const events = groupNotes(notes || []);
        const DURATION_BEATS = { "w": 4, "h": 2, "q": 1, "8": 0.5, "16": 0.25 };
        const measures = [];
        let currentMeasure = [];
        let currentBeats = 0;
        
        events.forEach(event => {
            const firstNote = event.notes[0] || {};
            const dur = firstNote.duration || "q";
            const beats = DURATION_BEATS[dur] || 1;
            
            if (currentBeats + beats > 4 && currentMeasure.length > 0) {
                measures.push(currentMeasure);
                currentMeasure = [];
                currentBeats = 0;
            }
            
            currentMeasure.push(event);
            currentBeats += beats;
            
            if (currentBeats >= 4) {
                measures.push(currentMeasure);
                currentMeasure = [];
                currentBeats = 0;
            }
        });
        if (currentMeasure.length > 0) measures.push(currentMeasure);

        const staveWidth = options?.staveWidth || 220;
        const height = options?.height || 150;
        
        while (measures.length < 3) measures.push([]);

        const renderer = new VF.Renderer(container, VF.Renderer.Backends.SVG);
        renderer.resize(measures.length * staveWidth + 60, height);
        const context = renderer.getContext();
        let x = 10;
        measures.forEach((measure, measureIndex) => {
            const stave = new VF.Stave(x, 24, staveWidth);
            if (measureIndex === 0) stave.addClef("treble").addTimeSignature("4/4");
            stave.setContext(context).draw();
            const tickables = measure.map(event => {
                const firstNote = event.notes[0] || {};
                const isRest = event.isRest || firstNote.isRest;
                const durStr = firstNote.duration || "q";
                const vfDuration = isRest ? durStr + "r" : durStr;
                
                const note = new VF.StaveNote({ clef: "treble", keys: event.keys, duration: vfDuration });
                
                if (!isRest) {
                    event.accidentals.forEach(index => note.addModifier(new VF.Accidental("#"), index));
                }
                note._sourceEvent = event;
                
                // Focus zone styling (only color if it's not a rest!)
                if (!isRest) {
                    if (options?.activeIndex !== undefined && event.time === options.activeIndex) {
                        note.setStyle({fillStyle: "#7c3aed", strokeStyle: "#7c3aed"});
                    } else if (options?.activeIndex !== undefined && event.time < options.activeIndex) {
                        note.setStyle({fillStyle: "#16a34a", strokeStyle: "#16a34a"}); // green for completed
                    }
                }
                
                return note;
            });
            
            try {
                if (tickables.length > 0) {
                    const voice = new VF.Voice({ num_beats: 4, beat_value: 4 }).setStrict(false);
                    voice.addTickables(tickables);
                    new VF.Formatter().joinVoices([voice]).format([voice], staveWidth - (measureIndex === 0 ? 95 : 34));
                    voice.draw(context, stave);
                    tickables.forEach(tick => {
                        if (tick._sourceEvent) noteMap.push({ x: tick.getAbsoluteX(), time: tick._sourceEvent.time, notes: tick._sourceEvent.notes });
                    });
                }
            } catch (e) {}
            x += staveWidth;
        });
        return noteMap;
    }

    function groupNotes(notes) {
        const events = [];
        notes.slice().sort((a, b) => (a.time || 0) - (b.time || 0) || (a.midi || 0) - (b.midi || 0)).forEach((note, index) => {
            const time = Number.isFinite(note.time) ? note.time : index;
            const last = events[events.length - 1];
            if (last && Math.abs(time - last.time) < 0.05) {
                if (!note.isRest) {
                    const vf = vfKeyForMidi(note.midi);
                    if (!last.keys.includes(vf.key)) {
                        last.keys.push(vf.key);
                        if (vf.sharp) last.accidentals.push(last.keys.length - 1);
                    }
                }
                last.notes.push(note);
            } else {
                if (note.isRest) {
                    events.push({ time, keys: ["b/4"], accidentals: [], notes: [note], isRest: true });
                } else {
                    const vf = vfKeyForMidi(note.midi);
                    events.push({ time, keys: [vf.key], accidentals: vf.sharp ? [0] : [], notes: [note], isRest: false });
                }
            }
        });
        return events;
    }

    function create(options) {
        const root = typeof options.root === "string" ? document.querySelector(options.root) : options.root;
        if (!root) return null;
        const sheet = typeof options.sheet === "string" ? root.querySelector(options.sheet) : options.sheet;
        const keyboard = typeof options.keyboard === "string" ? root.querySelector(options.keyboard) : options.keyboard;
        const state = {
            fold: false,
            octave: 4,
            showLabels: false,
            keyMap: loadKeyMap(),
            noteMap: [],
            activeKeys: new Map()
        };

        function actualMidi(midi) {
            return state.fold ? midi + (state.octave - 4) * 12 : midi;
        }

        function mapKey(midi, source) {
            return (source || "mouse") + ":" + midi;
        }

        async function pressMidi(midi, source) {
            const resolved = actualMidi(midi);
            state.activeKeys.set(mapKey(midi, source), resolved);
            await initAudio();
            if (synth) synth.triggerAttack(midiName(resolved), Tone.now(), 0.72);
            const uiMidi = state.fold ? 60 + (resolved % 12) : midi;
            keyboard?.querySelector('.key[data-midi="' + uiMidi + '"]')?.classList.add("active-key");
            options.onNoteOn?.(resolved, midiName(resolved));
        }

        function releaseMidi(midi, source) {
            const key = mapKey(midi, source);
            const resolved = state.activeKeys.has(key) ? state.activeKeys.get(key) : actualMidi(midi);
            state.activeKeys.delete(key);
            if (synth) synth.triggerRelease(midiName(resolved), Tone.now());
            const uiMidi = state.fold ? 60 + (resolved % 12) : midi;
            keyboard?.querySelector('.key[data-midi="' + uiMidi + '"]')?.classList.remove("active-key");
            options.onNoteOff?.(resolved, midiName(resolved));
        }

        function renderKeyboard() {
            syncKeySizing();
            createPianoKeys(keyboard, {
                start: state.fold ? 60 : START_NOTE,
                end: state.fold ? 71 : END_NOTE,
                centered: state.fold,
                showLabels: state.showLabels,
                onDown: pressMidi,
                onUp: releaseMidi
            });
        }

        function setNotes(notes) {
            state.noteMap = renderStaff(sheet, notes, options.sheetOptions || {});
        }

        function bindControls(controls) {
            controls?.foldButton?.addEventListener("click", () => { state.fold = true; renderKeyboard(); });
            controls?.fullButton?.addEventListener("click", () => { state.fold = false; renderKeyboard(); });
            controls?.labelsCheckbox?.addEventListener("change", event => { state.showLabels = event.target.checked; renderKeyboard(); });
            controls?.octaveDown?.addEventListener("click", () => { state.octave = Math.max(1, state.octave - 1); renderKeyboard(); });
            controls?.octaveUp?.addEventListener("click", () => { state.octave = Math.min(7, state.octave + 1); renderKeyboard(); });
            controls?.keybindButton?.addEventListener("click", () => openKeybindsModal(controls));
            controls?.keybindSave?.addEventListener("click", () => {
                if (!controls.keybindGrid) return;
                state.keyMap = {};
                controls.keybindGrid.querySelectorAll("input").forEach(input => {
                    if (input.value) state.keyMap[input.value.toLowerCase()] = Number(input.dataset.midi);
                });
                saveKeyMap(state.keyMap);
                controls.keybindModal?.classList.remove("open");
            });
            controls?.keybindReset?.addEventListener("click", () => {
                state.keyMap = { ...DEFAULT_KEY_MAP };
                saveKeyMap(state.keyMap);
                openKeybindsModal(controls);
            });
        }

        function openKeybindsModal(controls) {
            const grid = controls?.keybindGrid;
            if (!grid) return;
            grid.innerHTML = "";
            const mappedByMidi = {};
            Object.entries(state.keyMap).forEach(([key, midi]) => { mappedByMidi[midi] = key; });
            for (let midi = START_NOTE; midi <= END_NOTE; midi++) {
                const row = document.createElement("label");
                row.className = "keybind-row";
                const note = document.createElement("span");
                note.textContent = midiName(midi).toUpperCase();
                const input = document.createElement("input");
                input.type = "text";
                input.maxLength = 1;
                input.dataset.midi = String(midi);
                input.value = mappedByMidi[midi] || "";
                input.addEventListener("keydown", event => {
                    if (event.key === "Tab") return;
                    event.preventDefault();
                    input.value = event.key === "Backspace" || event.key === "Delete" ? "" : (event.key.length === 1 ? event.key.toLowerCase() : input.value);
                });
                row.append(note, input);
                grid.appendChild(row);
            }
            controls?.keybindModal?.classList.add("open");
        }

        const pressedPhysical = new Set();
        function handleKeyDown(event) {
            if (options.enableKeyboard === false || event.repeat || document.activeElement?.tagName === "INPUT") return;
            const physicalKey = event.key.toLowerCase();
            const midi = state.keyMap[physicalKey];
            if (midi === undefined || pressedPhysical.has(physicalKey)) return;
            pressedPhysical.add(physicalKey);
            pressMidi(midi, "keyboard");
        }

        function handleKeyUp(event) {
            const physicalKey = event.key.toLowerCase();
            const midi = state.keyMap[physicalKey];
            if (midi === undefined) return;
            pressedPhysical.delete(physicalKey);
            releaseMidi(midi, "keyboard");
        }

        renderKeyboard();
        if (options.notes) setNotes(options.notes);
        window.addEventListener("keydown", handleKeyDown);
        window.addEventListener("keyup", handleKeyUp);
        return {
            state,
            renderKeyboard,
            setNotes,
            bindControls,
            openKeybindsModal,
            pressMidi,
            releaseMidi,
            midiName,
            saveKeyMap: () => saveKeyMap(state.keyMap),
            destroy: () => {
                window.removeEventListener("keydown", handleKeyDown);
                window.removeEventListener("keyup", handleKeyUp);
            }
        };
    }

    window.PianoCore = {
        START_NOTE,
        END_NOTE,
        NOTE_NAMES,
        DEFAULT_KEY_MAP,
        KEYBIND_PRESETS,
        create,
        createPianoKeys,
        renderStaff,
        midiName,
        isBlackKey,
        vfKeyForMidi,
        initAudio,
        loadKeyMap,
        saveKeyMap,
        syncKeySizing,
        cleanKeybindMap,
        markKeybindsPending,
        clearKeybindsPending,
        getKeybindPresetId,
        setKeybindPresetId,
        getCustomKeybinds,
        getKeybindMap,
        getSynth: () => synth
    };
})();
