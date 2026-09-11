(function () {
    "use strict";

    const START_NOTE = 21;
    const END_NOTE = 108;
    const NOTE_NAMES = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"];
    const PERFECT_TIMING_WINDOW_MS = 1000;
    const IS_TOUCH = "ontouchstart" in window || navigator.maxTouchPoints > 0;
    const DEFAULT_KEY_MAP = {
        c: 60, f: 61, v: 62, g: 63, b: 64, n: 65,
        j: 66, m: 67, k: 68, ",": 69, l: 70, ".": 71
    };

    let keyWidthWhite = 40;
    let keyWidthBlack = 24;
    let synth = null;
    let audioReady = false;
    let currentKeyMap = loadKeyMap();

    function $(id) {
        return document.getElementById(id);
    }

    function show(el, visible) {
        if (el) el.hidden = !visible;
    }

    function syncActivityToDatabase(payload) {
        if (!window.PSM_CONFIG?.isLoggedIn || !window.PSM_CONFIG?.apiBase || !payload) return;
        
        // Log activity (Original)
        fetch(window.PSM_CONFIG.apiBase + "/save_activity.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        }).catch(() => {});
        
        // If it's a game, also submit to the new XP system
        if (payload.activity_type === "GAME" && payload.mode_key) {
            let gameKey = "recognition";
            if (payload.mode_key.includes("identify")) gameKey = "identify";
            
            const scorePayload = {
                game_key: gameKey,
                score: payload.score || 0,
                accuracy: payload.accuracy || 0,
                duration: payload.duration_seconds || 0
            };
            
            fetch(window.PSM_CONFIG.apiBase + "/save_game_score.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(scorePayload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showGameXpToast(data.xp_gained, data.level_up, data.level, data.new_high_score);
                }
            })
            .catch(() => {});
        }
    }

    function showGameXpToast(xp, levelUp, newLevel, newHighScore) {
        let toast = document.getElementById('gameXpToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'gameXpToast';
            toast.className = 'xp-toast';
            document.body.appendChild(toast);
        }
        
        let html = `+${xp} XP Earned!`;
        if (newHighScore) html += `<br><span style="font-size:13px; color:#ffd700;">🌟 New High Score!</span>`;
        if (levelUp) {
            toast.classList.add('level-up');
            html += `<br><span style="font-size:20px;">🎉 Level Up! You are now Level ${newLevel}</span>`;
        } else {
            toast.classList.remove('level-up');
        }
        
        toast.innerHTML = html;
        toast.classList.add('show');
        
        setTimeout(() => toast.classList.remove('show'), 5000);
    }

    function midiName(midi) {
        return NOTE_NAMES[midi % 12] + (Math.floor(midi / 12) - 1);
    }

    function isBlackKey(midi) {
        return NOTE_NAMES[midi % 12].includes("#");
    }

    function getVexFlow() {
        return window.VexFlow || (window.Vex && window.Vex.Flow) || null;
    }

    function getMidiCtor() {
        return window.Midi || (window.TonejsMidi && window.TonejsMidi.Midi) || null;
    }

    function formatTime(seconds) {
        if (!Number.isFinite(seconds)) return "0:00";
        const min = Math.floor(seconds / 60);
        const sec = Math.floor(seconds % 60).toString().padStart(2, "0");
        return min + ":" + sec;
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

    function finiteNumber(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function numericOrNull(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function getMidiTimingInfo(midi) {
        const timeSignature = midi?.header?.timeSignatures?.[0]?.timeSignature || [4, 4];
        const numerator = Math.max(1, finiteNumber(timeSignature[0], 4));
        const denominator = Math.max(1, finiteNumber(timeSignature[1], 4));
        return {
            ppq: Math.max(1, finiteNumber(midi?.header?.ppq || midi?.header?.PPQ, 480)),
            bpm: Math.max(1, finiteNumber(midi?.header?.tempos?.[0]?.bpm, 120)),
            numerator,
            denominator,
            measureBeats: numerator * (4 / denominator)
        };
    }

    function noteStartBeat(note, timing) {
        const ticks = numericOrNull(note.ticks);
        if (ticks !== null) return ticks / timing.ppq;
        return finiteNumber(note.time, 0) * timing.bpm / 60;
    }

    function noteLengthBeats(note, timing) {
        const durationTicks = numericOrNull(note.durationTicks);
        if (durationTicks !== null) return Math.max(0.25, durationTicks / timing.ppq);
        return Math.max(0.25, finiteNumber(note.duration, 0.12) * timing.bpm / 60);
    }

    function quantizeBeats(beats) {
        return Math.max(0.25, Math.round(finiteNumber(beats, 0.25) * 4) / 4);
    }

    function quantizeBeatPosition(beats) {
        return Math.max(0, Math.round(finiteNumber(beats, 0) * 4) / 4);
    }

    function durationPiecesForBeats(beats, maxBeats) {
        const pieces = [
            { beats: 4, duration: "w" },
            { beats: 2, duration: "h" },
            { beats: 1, duration: "q" },
            { beats: 0.5, duration: "8" },
            { beats: 0.25, duration: "16" }
        ];
        const limit = Math.max(0.25, maxBeats || beats);
        return pieces.find(piece => piece.beats <= beats + 0.001 && piece.beats <= limit + 0.001) || pieces[pieces.length - 1];
    }

    function buildRhythmicGroups(notes) {
        const groups = [];
        notes.slice().sort((a, b) => (a.beat ?? a.time ?? 0) - (b.beat ?? b.time ?? 0) || a.midi - b.midi).forEach((note, index) => {
            const beat = finiteNumber(note.beat, Number.isFinite(note.time) ? note.time : index);
            const last = groups[groups.length - 1];
            if (last && Math.abs(beat - last.beat) < 0.05) {
                const vf = vfKeyForMidi(note.midi);
                if (!last.keys.includes(vf.key)) {
                    last.keys.push(vf.key);
                    if (vf.sharp) last.accidentals.push(last.keys.length - 1);
                }
                last.notes.push(note);
                last.durationBeats = Math.max(last.durationBeats, quantizeBeats(note.durationBeats || 1));
                last.label = last.notes.map(item => item.name || midiName(item.midi)).join(" ");
            } else {
                const vf = vfKeyForMidi(note.midi);
                groups.push({
                    beat,
                    time: finiteNumber(note.time, beat),
                    durationBeats: quantizeBeats(note.durationBeats || 1),
                    keys: [vf.key],
                    accidentals: vf.sharp ? [0] : [],
                    notes: [note],
                    label: note.name || midiName(note.midi)
                });
            }
        });
        return groups;
    }

    function pushRhythmToken(measures, beat, durationBeats, sourceEvent, measureBeats) {
        let cursor = quantizeBeatPosition(beat);
        let remaining = quantizeBeats(durationBeats);
        let firstPiece = true;
        while (remaining > 0.001) {
            const measureIndex = Math.max(0, Math.floor(cursor / measureBeats));
            const beatInMeasure = cursor - measureIndex * measureBeats;
            const available = measureBeats - beatInMeasure;
            const piece = durationPiecesForBeats(remaining, available);
            while (measures.length <= measureIndex) measures.push([]);
            measures[measureIndex].push({
                duration: piece.duration + (sourceEvent ? "" : "r"),
                beats: piece.beats,
                rest: !sourceEvent,
                event: firstPiece ? sourceEvent : null
            });
            cursor += piece.beats;
            remaining = Math.max(0, remaining - piece.beats);
            firstPiece = false;
        }
        return cursor;
    }

    function buildRhythmicMeasures(notes, options = {}) {
        const measureBeats = options.measureBeats || 4;
        const groups = buildRhythmicGroups(notes);
        const measures = [];
        let cursor = 0;
        groups.forEach(group => {
            const startBeat = quantizeBeatPosition(group.beat);
            if (startBeat > cursor + 0.001) {
                cursor = pushRhythmToken(measures, cursor, startBeat - cursor, null, measureBeats);
            }
            cursor = pushRhythmToken(measures, Math.max(cursor, startBeat), group.durationBeats, group, measureBeats);
        });
        const minimumMeasures = options.minimumMeasures || 3;
        while (measures.length < minimumMeasures) measures.push([]);
        measures.forEach((measure, index) => {
            const total = measure.reduce((sum, token) => sum + token.beats, 0);
            if (total < measureBeats - 0.001) {
                pushRhythmToken(measures, index * measureBeats + total, measureBeats - total, null, measureBeats);
            }
        });
        return measures;
    }

    async function initAudio() {
        if (!window.Tone) return false;
        if (audioReady) {
            if (Tone.context && Tone.context.state === "suspended") {
                try { await Tone.context.resume(); } catch (e) {}
            }
            return true;
        }
        await Tone.start();
        synth = new Tone.PolySynth(Tone.Synth, {
            oscillator: { type: "amtriangle" },
            envelope: { attack: 0.01, decay: 0.1, sustain: 0.55, release: 1.2 }
        }).toDestination();
        audioReady = true;
        return true;
    }

    function loadKeyMap() {
        try {
            const stored = localStorage.getItem("pianoKeyBinds");
            return stored ? JSON.parse(stored) : { ...DEFAULT_KEY_MAP };
        } catch (e) {
            return { ...DEFAULT_KEY_MAP };
        }
    }

    function saveKeyMap() {
        try { localStorage.setItem("pianoKeyBinds", JSON.stringify(currentKeyMap)); } catch (e) {}
    }

    function syncKeySizing() {
        keyWidthWhite = IS_TOUCH ? 44 : 40;
        keyWidthBlack = IS_TOUCH ? 26 : 24;
        document.documentElement.style.setProperty("--key-w-white", keyWidthWhite + "px");
        document.documentElement.style.setProperty("--key-w-black", keyWidthBlack + "px");
        const vh = window.innerHeight;
        const vw = window.innerWidth;
        const compact = Math.min(vh, vw) < 620;
        const pianoH = compact ? Math.max(168, Math.min(220, Math.round(vh * 0.28))) : 245;
        document.documentElement.style.setProperty("--piano-h", pianoH + "px");
    }

    function setElementText(el, value) {
        if (el) el.textContent = value;
    }

    function createPianoKeys(container, options) {
        if (!container) return;
        const start = options.start;
        const end = options.end;
        const onDown = options.onDown;
        const onUp = options.onUp;
        const showLabels = !!options.showLabels;
        const centered = !!options.centered;
        const activeTouches = new Map();
        const activeMouseNotes = new Set();

        container.innerHTML = "";
        const wrapper = document.createElement("div");
        wrapper.style.width = Array.from({ length: end - start + 1 }, (_, i) => start + i)
            .filter(n => !isBlackKey(n)).length * keyWidthWhite + "px";
        wrapper.style.margin = centered ? "0 auto" : "0";

        let whiteX = 0;
        for (let midi = start; midi <= end; midi++) {
            const key = document.createElement("div");
            const black = isBlackKey(midi);
            key.className = "key " + (black ? "key-black" : "key-white");
            key.dataset.midi = String(midi);
            key.title = midiName(midi);
            key.style.left = black ? (whiteX - keyWidthBlack / 2) + "px" : whiteX + "px";
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

        if (IS_TOUCH && !container.dataset.touchBound) {
            container.dataset.touchBound = "1";
            container.addEventListener("contextmenu", event => event.preventDefault());
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
            container.addEventListener("touchmove", event => {
                event.preventDefault();
                for (const touch of event.changedTouches) {
                    const key = document.elementFromPoint(touch.clientX, touch.clientY)?.closest(".key");
                    const previous = activeTouches.get(touch.identifier);
                    if (key && container.contains(key)) {
                        const midi = Number(key.dataset.midi);
                        if (midi !== previous) {
                            if (previous !== undefined) onUp(previous, "touch");
                            activeTouches.set(touch.identifier, midi);
                            onDown(midi, "touch");
                        }
                    } else if (previous !== undefined) {
                        onUp(previous, "touch");
                        activeTouches.delete(touch.identifier);
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
            container.addEventListener("touchcancel", () => {
                activeTouches.forEach(midi => onUp(midi, "touch"));
                activeTouches.clear();
            }, { passive: false });
        }
    }

    function renderSimpleStaff(container, notes, sheetMap, options = {}) {
        if (!container) return;
        const VF = getVexFlow();
        container.innerHTML = "";
        if (!VF) {
            container.textContent = notes.map(n => n.name || midiName(n.midi)).join(" ");
            return;
        }

        const measureBeats = options.measureBeats || 4;
        const timeSignatureNumerator = options.timeSignatureNumerator || 4;
        const timeSignatureDenominator = options.timeSignatureDenominator || 4;
        const staveWidth = 220;
        const measures = buildRhythmicMeasures(notes.slice(0, options.limit || 800), {
            measureBeats,
            minimumMeasures: 3
        });

        const measureWidths = measures.map((measure, index) => Math.max(220, measure.length * 40 + (index === 0 ? 95 : 34)));
        const width = measureWidths.reduce((sum, w) => sum + w, 0) + 60;
        const height = options.height || 150;
        const renderer = new VF.Renderer(container, VF.Renderer.Backends.SVG);
        renderer.resize(width, height);
        const context = renderer.getContext();
        let currentX = 10;
        if (sheetMap) sheetMap.length = 0;

        measures.forEach((measure, index) => {
            const staveWidth = measureWidths[index];
            const stave = new VF.Stave(currentX, 24, staveWidth);
            if (index === 0) stave.addClef("treble").addTimeSignature(timeSignatureNumerator + "/" + timeSignatureDenominator);
            stave.setContext(context).draw();
            const tickables = [];

            measure.forEach(token => {
                try {
                    const note = new VF.StaveNote({
                        clef: "treble",
                        keys: token.rest ? ["b/4"] : token.event.keys,
                        duration: token.duration
                    });
                    if (!token.rest) token.event.accidentals.forEach(accIndex => note.addModifier(new VF.Accidental("#"), accIndex));
                    note._sourceEvent = token.event;
                    tickables.push(note);
                } catch (e) {}
            });

            if (tickables.length) {
                try {
                    const voice = new VF.Voice({ num_beats: timeSignatureNumerator, beat_value: timeSignatureDenominator }).setStrict(false);
                    voice.addTickables(tickables);
                    new VF.Formatter().joinVoices([voice]).format([voice], staveWidth - (index === 0 ? 95 : 34));
                    voice.draw(context, stave);
                    tickables.forEach(tick => {
                        if (tick._sourceEvent && sheetMap) {
                            const x = tick.getAbsoluteX();
                            sheetMap.push({ x, time: tick._sourceEvent.time, notes: tick._sourceEvent.notes });
                        }
                    });
                } catch (e) {}
            }

            currentX += staveWidth;
        });
    }

    function initPianoPage() {
        const root = $("piano-app");
        if (!root) return;

        syncKeySizing();
        const ui = {
            keyboard: $("piano-container"),
            dispNote: $("disp-note"),
            dispMode: $("disp-mode"),
            midiStatus: $("midi-status-text"),
            midiStatusDot: document.querySelector("#piano-app .status-dot"),
            btnMidi: $("btn-midi"),
            modalSession: $("modal-session"),
            historyList: $("history-list"),
            trainControls: $("train-controls"),
            songInfoPanel: $("song-info-panel"),
            songTitle: $("song-title"),
            songTrackCount: $("song-track-count"),
            songNoteCount: $("song-note-count"),
            songDuration: $("song-duration"),
            livePanel: $("play-live-panel"),
            liveScore: $("live-score"),
            liveAccuracy: $("live-accuracy"),
            liveNotes: $("live-notes"),
            liveTime: $("live-time"),
            globalMidiUpload: $("global-midi-upload"),
            btnExportMidi: $("btn-export-midi"),
            midiPlayerControls: $("midi-player-controls"),
            btnPlayPause: $("btn-play-pause"),
            midiProgress: $("midi-progress"),
            midiTime: $("midi-time"),
            playhead: $("playhead"),
            sheetScrollArea: $("sheet-scroll-area"),
            staffContainer: $("staff-container"),
            scrollZoneLeft: $("scroll-zone-left"),
            scrollZoneRight: $("scroll-zone-right"),
            trackListContainer: $("track-list-container"),
            modalMultitrack: $("modal-multitrack"),
            btnMultitrackContinue: $("btn-multitrack-continue"),
            btnMultitrackCancel: $("btn-multitrack-cancel"),
            labelMuteOthers: $("label-mute-others"),
            muteOtherTracks: $("mute-other-tracks"),
            trackBatchControls: $("track-batch-controls"),
            btnTracksEnableAll: $("btn-tracks-enable-all"),
            btnTracksDisableAll: $("btn-tracks-disable-all"),
            focusTrackSection: $("focus-track-section"),
            focusTrackList: $("focus-track-list"),
            toggleKeyHighlight: $("toggle-key-highlight"),
            toggleStaffLabels: $("toggle-staff-labels"),
            historyReplayPanel: $("history-replay-panel"),
            historyReplayTitle: $("history-replay-title"),
            btnHistoryReplayBack: $("btn-history-replay-back"),
            btnHistoryReplayPlay: $("btn-history-replay-play"),
            historyReplayProgress: $("history-replay-progress"),
            historyReplayTime: $("history-replay-time"),
            historyReplaySheet: $("history-replay-sheet"),
            historyReplaySheetScroll: $("history-replay-sheet-scroll"),
            historyReplayStaff: $("history-replay-staff"),
            historyReplayPlayhead: $("history-replay-playhead"),
            historyReplayMistakes: $("history-replay-mistakes"),
            modalPlaySession: $("modal-play-session"),
            btnPlaySessionStart: $("btn-play-session-start"),
            btnPlaySessionCancel: $("btn-play-session-cancel")
        };

        const state = {
            mode: "practice",
            octaveFold: false,
            keyLightsOn: ui.toggleKeyHighlight ? ui.toggleKeyHighlight.checked : true,
            showLabels: false,
            staffNoteLabels: ui.toggleStaffLabels ? ui.toggleStaffLabels.checked : true,
            baseOctave: 4,
            displayNotes: [],
            sheetMap: [],
            customTrackLoaded: false,
            songName: "No MIDI loaded",
            songFileName: "",
            loadedTracks: [],
            playbackNotes: [],
            timeSignatureNumerator: 4,
            timeSignatureDenominator: 4,
            measureBeats: 4,
            selectedTracks: new Set(),
            trainIndex: 0,
            trainPressed: new Set(),
            analysisEvents: [],
            playExpectedNotes: [],
            playExpectedIndex: 0,
            playChordPressed: new Map(),
            playPromptStartedAt: 0,
            isRecording: false,
            currentSession: null,
            activeNotes: new Map(),
            activeManualNotes: new Set(),
            physicalNoteMap: new Map(),
            sheetZoom: 1,
            playSessionActive: false,
            playSessionComplete: false,
            playSessionPromptOpen: false,
            playSessionFinishing: false,
            analysisContext: null
        };

        const playback = {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            nextIndex: 0,
            activeNotes: new Set(),
            lastFrame: 0,
            timers: new Set()
        };

        const historyReplay = {
            isPlaying: false,
            currentTime: 0,
            duration: 0,
            nextIndex: 0,
            session: null,
            activeNotes: new Set(),
            timers: new Set(),
            lastFrame: 0,
            sheetMap: []
        };

        function isPracticeMode() {
            return state.mode === "practice";
        }

        function isPlayMode() {
            return state.mode === "play";
        }

        function modeTitle(mode) {
            return mode === "practice" ? "Practice" : mode.charAt(0).toUpperCase() + mode.slice(1);
        }

        function escapeHtml(value) {
            return String(value ?? "").replace(/[&<>"']/g, char => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;"
            }[char]));
        }

        function displaySongName(file) {
            if (!file?.name) return "Imported MIDI";
            return file.name.replace(/\.(mid|midi)$/i, "");
        }

        function cleanSongName(value) {
            const name = String(value || "").trim().replace(/\.(mid|midi)$/i, "");
            if (!name || /^untitled/i.test(name) || name === "No MIDI loaded") return "";
            return name;
        }

        function sessionSongName(session, fallback = "Imported MIDI") {
            const summary = session?.summary || {};
            return cleanSongName(session?.song_name)
                || cleanSongName(summary.song_name)
                || cleanSongName(session?.song_file_name)
                || cleanSongName(summary.song_file_name)
                || cleanSongName(session?.songFileName)
                || cleanSongName(session?.file_name)
                || cleanSongName(session?.fileName)
                || cleanSongName(fallback)
                || "Imported MIDI";
        }

        function getVisibleExpectedNotes() {
            return getVisiblePlaybackNotes().slice().sort((a, b) => a.time - b.time || a.midi - b.midi);
        }

        function renderStaff() {
            if (state.customTrackLoaded) {
                renderPlaybackStaff();
            } else {
                renderSimpleStaff(ui.staffContainer, state.displayNotes.map((name, index) => ({
                    name,
                    midi: nameToMidi(name),
                    time: index
                })), state.sheetMap, { limit: 64, height: 150 });
            }
            applySheetZoom();
        }

        function renderPlaybackStaff() {
            if (!ui.staffContainer) return;
            const selectedTracks = getSelectedTrackIndices();
            const VF = getVexFlow();
            ui.staffContainer.innerHTML = "";
            state.sheetMap = [];
            if (!selectedTracks.length) {
                if (ui.playhead) ui.playhead.hidden = true;
                return;
            }

            if (!VF) {
                ui.staffContainer.textContent = getVisiblePlaybackNotes().map(note => note.name || midiName(note.midi)).join(" ");
                return;
            }

            const rowHeight = 138;
            const staveWidth = 230;
            const firstExtra = 92;
            const measureBeats = state.measureBeats || 4;
            const trackEvents = selectedTracks.map(trackIndex => {
                const notes = state.playbackNotes.filter(note => note.trackIndex === trackIndex);
                return {
                    trackIndex,
                    name: state.loadedTracks[trackIndex]?.name || "Track " + (trackIndex + 1),
                    measures: buildRhythmicMeasures(notes, {
                        measureBeats,
                        minimumMeasures: 3
                    })
                };
            });
            const measureCount = Math.max(3, ...trackEvents.map(track => track.measures.length));
            const measureWidths = [];
            for (let i = 0; i < measureCount; i++) {
                let maxTokens = 0;
                trackEvents.forEach(track => {
                    const tokens = track.measures[i];
                    if (tokens && tokens.length > maxTokens) maxTokens = tokens.length;
                });
                measureWidths.push(Math.max(230, maxTokens * 40 + (i === 0 ? firstExtra + 12 : 20)));
            }
            const totalWidth = measureWidths.reduce((sum, w) => sum + w, 0) + 50;
            const totalHeight = trackEvents.length * rowHeight + 12;
            const renderer = new VF.Renderer(ui.staffContainer, VF.Renderer.Backends.SVG);
            renderer.resize(totalWidth, totalHeight);
            const context = renderer.getContext();
            const svg = ui.staffContainer.querySelector("svg");
            const focusTrack = getFocusTrackIndex();

            trackEvents.forEach((track, rowIndex) => {
                const rowY = rowIndex * rowHeight + 8;
                let x = 10;
                for (let measureIndex = 0; measureIndex < measureCount; measureIndex++) {
                    const measureWidth = measureWidths[measureIndex];
                    const stave = new VF.Stave(x, rowY, measureWidth);
                    if (measureIndex === 0) stave.addClef("treble").addTimeSignature(state.timeSignatureNumerator + "/" + state.timeSignatureDenominator);
                    stave.setContext(context).draw();

                    const tokens = track.measures[measureIndex] || buildRhythmicMeasures([], { measureBeats, minimumMeasures: 1 })[0];
                    const tickables = [];
                    tokens.forEach(token => {
                        try {
                            const note = new VF.StaveNote({
                                clef: "treble",
                                keys: token.rest ? ["b/4"] : token.event.keys,
                                duration: token.duration
                            });
                            if (!token.rest) token.event.accidentals.forEach(index => note.addModifier(new VF.Accidental("#"), index));
                            note._sourceEvent = token.event;
                            tickables.push(note);
                        } catch (e) {}
                    });
                    try {
                        const voice = new VF.Voice({ num_beats: state.timeSignatureNumerator, beat_value: state.timeSignatureDenominator }).setStrict(false);
                        voice.addTickables(tickables);
                        new VF.Formatter().joinVoices([voice]).format([voice], measureWidth - (measureIndex === 0 ? firstExtra + 12 : 20));
                        voice.draw(context, stave);
                        tickables.forEach(tick => {
                            if (!tick._sourceEvent) return;
                            const noteX = tick.getAbsoluteX();
                            if (focusTrack === null || focusTrack === track.trackIndex) {
                                state.sheetMap.push({ x: noteX, time: tick._sourceEvent.time, trackIndex: track.trackIndex });
                            }
                            if (state.staffNoteLabels) {
                                addSvgText(svg, noteX, rowY + rowHeight - 16, tick._sourceEvent.label, "sheet-note-label");
                            }
                        });
                    } catch (e) {}
                    x += measureWidth;
                }
                addSvgText(svg, 14, rowY + rowHeight - 4, track.name, "sheet-track-label", "start");
            });

            if (!state.sheetMap.length) {
                trackEvents.forEach(track => {
                    track.measures.flat().forEach(token => {
                        if (token.event) state.sheetMap.push({ x: 0, time: token.event.time, trackIndex: track.trackIndex });
                    });
                });
            }
            state.sheetMap.sort((a, b) => a.time - b.time);
        }

        function groupNotesByTime(notes) {
            const events = [];
            notes.slice().sort((a, b) => a.time - b.time).forEach(note => {
                const last = events[events.length - 1];
                if (last && Math.abs(note.time - last.time) < 0.05) {
                    const vf = vfKeyForMidi(note.midi);
                    if (!last.keys.includes(vf.key)) {
                        last.keys.push(vf.key);
                        if (vf.sharp) last.accidentals.push(last.keys.length - 1);
                    }
                    last.notes.push(note);
                    last.label = last.notes.map(item => item.name || midiName(item.midi)).join(" ");
                } else {
                    const vf = vfKeyForMidi(note.midi);
                    events.push({
                        time: note.time,
                        keys: [vf.key],
                        accidentals: vf.sharp ? [0] : [],
                        notes: [note],
                        label: note.name || midiName(note.midi)
                    });
                }
            });
            return events;
        }

        function getFocusTrackIndex() {
            const checked = ui.focusTrackList?.querySelector("input[type=radio]:checked");
            return checked ? Number(checked.dataset.trackIndex) : null;
        }

        function addSvgText(svg, x, y, text, className, anchor) {
            if (!svg || !text) return;
            const label = document.createElementNS("http://www.w3.org/2000/svg", "text");
            label.setAttribute("x", String(Math.round(x)));
            label.setAttribute("y", String(Math.round(y)));
            label.setAttribute("class", className);
            label.setAttribute("text-anchor", anchor || "middle");
            label.textContent = text;
            svg.appendChild(label);
        }

        function nameToMidi(name) {
            const match = /^([A-G]#?)(-?\d+)$/.exec(name);
            if (!match) return 60;
            return (Number(match[2]) + 1) * 12 + NOTE_NAMES.indexOf(match[1]);
        }

        function applySheetZoom() {
            if (ui.staffContainer) ui.staffContainer.style.zoom = state.sheetZoom;
            const label = $("sheet-zoom-level");
            if (label) label.textContent = Math.round(state.sheetZoom * 100) + "%";
            requestAnimationFrame(updateTransport);
        }

        function showZoomControls() {
            const controls = document.querySelector(".sheet-zoom-controls");
            if (!controls) return;
            controls.classList.add("visible");
            clearTimeout(showZoomControls.timer);
            showZoomControls.timer = setTimeout(() => controls.classList.remove("visible"), 1000);
        }

        function buildMainPiano() {
            createPianoKeys(ui.keyboard, {
                start: state.octaveFold ? 60 : START_NOTE,
                end: state.octaveFold ? 71 : END_NOTE,
                showLabels: state.showLabels,
                centered: state.octaveFold,
                onDown: triggerNoteOn,
                onUp: triggerNoteOff
            });
            if (!state.octaveFold) {
                setTimeout(() => {
                    const middleC = ui.keyboard?.querySelector('.key[data-midi="60"]');
                    if (middleC) ui.keyboard.scrollLeft = Math.max(0, middleC.offsetLeft - ui.keyboard.clientWidth / 2);
                }, 80);
            }
            updateScrollZoneLimits();
        }

        function uiMidiFor(actualMidi, source) {
            if (state.octaveFold && (source === "mouse" || source === "keyboard" || source === "touch")) {
                return 60 + actualMidi % 12;
            }
            return actualMidi;
        }

        function resolveActualMidi(midi, source) {
            if (!state.octaveFold || (source !== "mouse" && source !== "keyboard" && source !== "touch")) return midi;
            const chord = getCurrentTrainChord();
            const match = chord.find(note => note.midi % 12 === midi % 12);
            if (isPracticeMode() && match) return match.midi;
            return midi + (state.baseOctave - 4) * 12;
        }

        function noteMapKey(midi, source) {
            return (source || "mouse") + ":" + midi;
        }

        function triggerNoteOn(midi, source, velocity) {
            const actualMidi = resolveActualMidi(midi, source || "mouse");
            state.physicalNoteMap.set(noteMapKey(midi, source), actualMidi);
            const velocityValue = velocity || 92;
            if (synth) {
                try { synth.triggerAttack(midiName(actualMidi), Tone.now(), Math.min(velocityValue / 127, 1)); } catch (e) {}
            }
            setElementText(ui.dispNote, midiName(actualMidi));
            if (state.keyLightsOn) {
                const key = ui.keyboard?.querySelector('.key[data-midi="' + uiMidiFor(actualMidi, source) + '"]');
                if (key) key.classList.add("active-key");
            }

            if (source !== "playback" && source !== "replay") {
                if (!state.customTrackLoaded) {
                    state.displayNotes.push(midiName(actualMidi));
                    if (state.displayNotes.length > 40) state.displayNotes.shift();
                    renderStaff();
                }
                if (isPracticeMode()) handlePracticeAttempt(actualMidi);
                if (isPlayMode()) handlePlayAttempt(actualMidi);
            }

            if (state.isRecording && !state.activeNotes.has(actualMidi)) {
                state.activeNotes.set(actualMidi, {
                    started: performance.now(),
                    velocity: velocityValue,
                    source: source || "mouse"
                });
            }
        }

        function appendRecordedNote(actualMidi, note, endedAt) {
            if (!state.currentSession || !note) return;
            const duration = Math.max(80, Math.round(endedAt - note.started));
            state.currentSession.events.push({
                timestamp_ms: Math.round(note.started - state.currentSession.startedAt),
                midi: actualMidi,
                note: midiName(actualMidi),
                duration_ms: duration,
                velocity: note.velocity,
                input_method: note.source
            });
        }

        function flushRecordingNotes() {
            if (!state.currentSession) {
                state.activeNotes.clear();
                return;
            }
            const endedAt = performance.now();
            state.activeNotes.forEach((note, actualMidi) => appendRecordedNote(actualMidi, note, endedAt));
            state.activeNotes.clear();
        }

        function triggerNoteOff(midi, source) {
            const mapKey = noteMapKey(midi, source);
            const actualMidi = state.physicalNoteMap.has(mapKey) ? state.physicalNoteMap.get(mapKey) : resolveActualMidi(midi, source || "mouse");
            state.physicalNoteMap.delete(mapKey);
            if (synth) {
                try { synth.triggerRelease(midiName(actualMidi), Tone.now()); } catch (e) {}
            }
            const key = ui.keyboard?.querySelector('.key[data-midi="' + uiMidiFor(actualMidi, source) + '"]');
            if (key) key.classList.remove("active-key");

            if (state.isRecording && state.activeNotes.has(actualMidi)) {
                const note = state.activeNotes.get(actualMidi);
                appendRecordedNote(actualMidi, note, performance.now());
                state.activeNotes.delete(actualMidi);
            }
        }

        function releaseAllManualNotes() {
            state.activeManualNotes.forEach(midi => triggerNoteOff(midi, "keyboard"));
            state.activeManualNotes.clear();
        }

        function getSelectedTrackIndices() {
            if (!state.loadedTracks.length) return [];
            const boxes = Array.from(ui.trackListContainer?.querySelectorAll("input[type=checkbox]") || []);
            if (!boxes.length) return Array.from(state.loadedTracks.keys());
            return boxes.filter(input => input.checked).map(input => Number(input.dataset.trackIndex));
        }

        function getVisiblePlaybackNotes() {
            const selected = new Set(getSelectedTrackIndices());
            return state.playbackNotes.filter(note => selected.has(note.trackIndex));
        }

        function rebuildTrackLists() {
            if (!ui.trackListContainer) return;
            ui.trackListContainer.innerHTML = "";
            state.loadedTracks.forEach((track, index) => {
                const label = document.createElement("label");
                const checkbox = document.createElement("input");
                checkbox.type = "checkbox";
                checkbox.checked = true;
                checkbox.dataset.trackIndex = String(index);
                checkbox.addEventListener("change", () => {
                    renderStaff();
                    rebuildFocusList();
                    updateSongInfo();
                    resetTrainPosition();
                });
                label.append(checkbox, " " + track.name);
                ui.trackListContainer.appendChild(label);
            });
            const multi = state.loadedTracks.length > 1;
            show(ui.trackListContainer, multi);
            show(ui.trackBatchControls, multi);
            show(ui.focusTrackSection, multi);
            show(ui.labelMuteOthers, multi);
            rebuildFocusList();
        }

        function rebuildFocusList() {
            if (!ui.focusTrackList) return;
            ui.focusTrackList.innerHTML = "";
            getSelectedTrackIndices().forEach((trackIndex, position) => {
                const label = document.createElement("label");
                const radio = document.createElement("input");
                radio.type = "radio";
                radio.name = "focus-track-radio";
                radio.dataset.trackIndex = String(trackIndex);
                radio.checked = position === 0;
                radio.addEventListener("change", renderStaff);
                label.append(radio, " " + state.loadedTracks[trackIndex].name);
                ui.focusTrackList.appendChild(label);
            });
        }

        function updateSongInfo() {
            const visibleNotes = getVisibleExpectedNotes();
            show(ui.songInfoPanel, state.customTrackLoaded);
            setElementText(ui.songTitle, state.songName);
            setElementText(ui.songTrackCount, String(state.loadedTracks.length));
            setElementText(ui.songNoteCount, String(visibleNotes.length));
            setElementText(ui.songDuration, formatTime(playback.duration));
        }

        function loadMidiFile(file) {
            const MidiCtor = getMidiCtor();
            if (!file || !MidiCtor) return;
            file.arrayBuffer().then(buffer => {
                const midi = new MidiCtor(buffer);
                const timing = getMidiTimingInfo(midi);
                const tracks = midi.tracks
                    .map((track, originalIndex) => ({ track, originalIndex }))
                    .filter(item => item.track.notes && item.track.notes.length)
                    .map((item, index) => ({
                        name: item.track.instrument?.name || item.track.name || "Track " + (index + 1),
                        notes: item.track.notes,
                        originalIndex: item.originalIndex
                    }));
                if (!tracks.length) return;

                const finishLoad = () => {
                    stopPlayback();
                    state.customTrackLoaded = true;
                    state.songName = displaySongName(file);
                    state.songFileName = file.name || state.songName;
                    state.loadedTracks = tracks;
                    state.playbackNotes = [];
                    state.timeSignatureNumerator = timing.numerator;
                    state.timeSignatureDenominator = timing.denominator;
                    state.measureBeats = timing.measureBeats;
                    tracks.forEach((track, trackIndex) => {
                        track.notes.forEach(note => {
                            const beat = noteStartBeat(note, timing);
                            const durationBeats = noteLengthBeats(note, timing);
                            state.playbackNotes.push({
                                name: note.name,
                                midi: note.midi,
                                time: note.time,
                                beat,
                                duration: Math.max(note.duration || 0.12, 0.08),
                                durationBeats,
                                ticks: numericOrNull(note.ticks),
                                durationTicks: numericOrNull(note.durationTicks),
                                velocity: Math.max(20, Math.round((note.velocity || 0.7) * 127)),
                                trackIndex
                            });
                        });
                    });
                    state.playbackNotes.sort((a, b) => a.time - b.time);
                    playback.duration = state.playbackNotes.length
                        ? Math.max(...state.playbackNotes.map(note => note.time + note.duration))
                        : 0;
                    playback.currentTime = 0;
                    playback.nextIndex = 0;
                    show(ui.midiPlayerControls, true);
                    rebuildTrackLists();
                    renderStaff();
                    updateSongInfo();
                    resetTrainPosition();
                    setElementText(ui.dispMode, modeTitle(state.mode));
                    setElementText(ui.midiTime, "0:00 / " + formatTime(playback.duration));
                    if (ui.midiProgress) ui.midiProgress.value = 0;
                    updateTrainSeekLock();
                    updateLivePlayStats();
                    if (isPracticeMode()) highlightNextTrainNote({ snapToNote: true });
                    if (isPlayMode()) showPlaySessionPrompt();
                };

                if (tracks.length > 1 && ui.modalMultitrack) {
                    ui.modalMultitrack.classList.add("open");
                    const cleanup = () => {
                        ui.btnMultitrackCancel.removeEventListener("click", cancel);
                        ui.btnMultitrackContinue.removeEventListener("click", accept);
                    };
                    const cancel = () => {
                        ui.modalMultitrack.classList.remove("open");
                        cleanup();
                    };
                    const accept = () => {
                        ui.modalMultitrack.classList.remove("open");
                        cleanup();
                        finishLoad();
                    };
                    ui.btnMultitrackCancel.addEventListener("click", cancel);
                    ui.btnMultitrackContinue.addEventListener("click", accept);
                } else {
                    finishLoad();
                }
            });
        }

        function startPlayback() {
            if (!state.playbackNotes.length) return;
            if (isPlayMode()) return;
            if (playback.currentTime >= playback.duration) {
                playback.currentTime = 0;
                playback.nextIndex = 0;
            }
            playback.isPlaying = true;
            playback.lastFrame = performance.now();
            setElementText(ui.btnPlayPause, "Pause");
            requestAnimationFrame(playbackLoop);
        }

        function stopPlayback() {
            playback.isPlaying = false;
            playback.timers.forEach(timer => clearTimeout(timer));
            playback.timers.clear();
            releaseAllSoundingNotes();
            setElementText(ui.btnPlayPause, "Play");
        }

        function releaseAllSoundingNotes() {
            playback.activeNotes.forEach(midi => triggerNoteOff(midi, "playback"));
            playback.activeNotes.clear();
            Array.from(state.physicalNoteMap.keys()).forEach(key => {
                if (key.startsWith("playback:") || key.startsWith("replay:")) state.physicalNoteMap.delete(key);
            });
            document.querySelectorAll(".active-key").forEach(key => key.classList.remove("active-key"));
            if (synth && synth.releaseAll) {
                try { synth.releaseAll(); } catch (e) {}
            }
        }

        function playbackLoop(timestamp) {
            if (!playback.isPlaying) return;
            const delta = (timestamp - playback.lastFrame) / 1000;
            playback.lastFrame = timestamp;
            playback.currentTime += delta;

            const visible = new Set(getSelectedTrackIndices());
            while (playback.nextIndex < state.playbackNotes.length && state.playbackNotes[playback.nextIndex].time <= playback.currentTime) {
                const note = state.playbackNotes[playback.nextIndex];
                const muted = ui.muteOtherTracks?.checked && !visible.has(note.trackIndex);
                if (visible.has(note.trackIndex) && !muted) {
                    triggerNoteOn(note.midi, "playback", note.velocity);
                    playback.activeNotes.add(note.midi);
                    const timer = setTimeout(() => {
                        triggerNoteOff(note.midi, "playback");
                        playback.activeNotes.delete(note.midi);
                        playback.timers.delete(timer);
                    }, note.duration * 1000);
                    playback.timers.add(timer);
                }
                playback.nextIndex++;
            }

            if (isPracticeMode()) {
                syncPracticeIndexToTime();
                highlightNextTrainNote({ snapToNote: false });
            }
            if (state.playSessionActive) {
                markOverduePlayNotes();
            }
            updateTransport();
            if (playback.currentTime >= playback.duration) {
                stopPlayback();
                if (isPracticeMode()) {
                    rewindPracticeToStart();
                } else {
                    playback.currentTime = playback.duration;
                    playback.nextIndex = state.playbackNotes.length;
                }
                updateTransport();
                if (state.playSessionActive) finishPlaySession();
                return;
            }
            requestAnimationFrame(playbackLoop);
        }

        function updateTransport() {
            if (ui.midiProgress && playback.duration > 0) {
                ui.midiProgress.value = String((playback.currentTime / playback.duration) * 100);
            }
            setElementText(ui.midiTime, formatTime(playback.currentTime) + " / " + formatTime(playback.duration));
            updatePlayhead();
            if (isPlayMode()) updateLivePlayStats();
        }

        function updateTrainSeekLock() {
            if (!ui.midiProgress) return;
            ui.midiProgress.disabled = isPlayMode();
            ui.midiProgress.title = isPlayMode() ? "Progress is locked during play sessions" : "";
        }

        function updatePlayhead() {
            if (!ui.playhead || !state.sheetMap.length) return;
            let closest = state.sheetMap[0];
            for (const item of state.sheetMap) {
                if (item.time <= playback.currentTime) closest = item;
                else break;
            }
            const containerLeft = ui.staffContainer ? ui.staffContainer.offsetLeft : 0;
            const x = containerLeft + closest.x * state.sheetZoom;
            ui.playhead.hidden = false;
            ui.playhead.style.left = Math.max(0, x - 2) + "px";
            if (ui.sheetScrollArea && x > ui.sheetScrollArea.scrollLeft + ui.sheetScrollArea.clientWidth * 0.72) {
                ui.sheetScrollArea.scrollTo({ left: Math.max(0, x - ui.sheetScrollArea.clientWidth * 0.35), behavior: "smooth" });
            }
        }

        function syncPracticeIndexToTime() {
            if (!isPracticeMode()) return;
            const notes = getTrainNotes();
            if (!notes.length) {
                state.trainIndex = 0;
                state.trainPressed.clear();
                return;
            }
            const nextIndex = notes.findIndex(note => note.time >= playback.currentTime - 0.04);
            const resolvedIndex = nextIndex < 0 ? notes.length : nextIndex;
            if (resolvedIndex !== state.trainIndex) {
                state.trainIndex = resolvedIndex;
                state.trainPressed.clear();
            }
        }

        function resetTrainPosition() {
            state.trainIndex = 0;
            state.trainPressed.clear();
            state.analysisEvents = [];
            if (isPracticeMode()) {
                syncPracticeIndexToTime();
                highlightNextTrainNote({ snapToNote: false });
            }
        }

        function retryPracticeSession() {
            stopPlayback();
            releaseAllManualNotes();
            resetTrainPosition();
            playback.currentTime = 0;
            playback.nextIndex = 0;
            updateTransport();
            if (!isPracticeMode()) switchTab("practice");
            else highlightNextTrainNote({ snapToNote: true });
        }

        function getTrainNotes() {
            return getVisiblePlaybackNotes();
        }

        function getCurrentTrainChord() {
            const notes = getTrainNotes();
            if (!notes.length || state.trainIndex >= notes.length) return [];
            const first = notes[state.trainIndex];
            return notes.filter(note => Math.abs(note.time - first.time) < 0.05);
        }

        function rewindPracticeToStart() {
            if (!isPracticeMode()) return;
            playback.currentTime = 0;
            playback.nextIndex = 0;
            state.trainIndex = 0;
            state.trainPressed.clear();
            updateTransport();
            setElementText(ui.dispMode, "Practice");
            highlightNextTrainNote({ snapToNote: false });
        }

        function highlightNextTrainNote(options = {}) {
            document.querySelectorAll(".train-hint").forEach(key => key.classList.remove("train-hint"));
            if (!isPracticeMode()) return;
            const notes = getTrainNotes();
            if (!notes.length) return;
            if (state.trainIndex >= notes.length) {
                setElementText(ui.dispMode, "Practice Complete");
                releaseAllManualNotes();
                releaseAllSoundingNotes();
                setTimeout(rewindPracticeToStart, 350);
                return;
            }
            const chord = getCurrentTrainChord();
            if (options.snapToNote) {
                playback.currentTime = chord[0]?.time || 0;
                playback.nextIndex = state.playbackNotes.findIndex(note => note.time >= playback.currentTime);
                if (playback.nextIndex < 0) playback.nextIndex = state.playbackNotes.length;
                updateTransport();
            }
            if (!state.keyLightsOn) return;
            chord.forEach(note => {
                const uiMidi = state.octaveFold ? 60 + note.midi % 12 : note.midi;
                const key = ui.keyboard?.querySelector('.key[data-midi="' + uiMidi + '"]');
                if (key) key.classList.add("train-hint");
            });
        }

        function highlightPlayExpectedNote() {
            document.querySelectorAll(".train-hint").forEach(key => key.classList.remove("train-hint"));
            if (!isPlayMode() || !state.playSessionActive || !state.keyLightsOn) return;
            getCurrentPlayChord().forEach(expected => {
                const uiMidi = state.octaveFold ? 60 + expected.midi % 12 : expected.midi;
                const key = ui.keyboard?.querySelector('.key[data-midi="' + uiMidi + '"]');
                if (key) key.classList.add("train-hint");
            });
        }

        function getCurrentPlayChord() {
            const notes = state.playExpectedNotes;
            if (!notes.length || state.playExpectedIndex >= notes.length) return [];
            const first = notes[state.playExpectedIndex];
            const chord = [];
            for (let index = state.playExpectedIndex; index < notes.length; index++) {
                if (Math.abs(notes[index].time - first.time) >= 0.05) break;
                chord.push(notes[index]);
            }
            return chord;
        }

        function playNoteKey(noteOrMidi) {
            const midi = typeof noteOrMidi === "number" ? noteOrMidi : noteOrMidi.midi;
            return state.octaveFold ? midi % 12 : midi;
        }

        function advancePlaySessionChord(chord) {
            if (!chord.length) return;
            const time = chord[0].time;
            while (
                state.playExpectedIndex < state.playExpectedNotes.length
                && Math.abs(state.playExpectedNotes[state.playExpectedIndex].time - time) < 0.05
            ) {
                state.playExpectedIndex++;
            }
            state.playChordPressed.clear();
        }

        function movePlaySessionToExpectedNote() {
            if (!state.playSessionActive) return;
            const chord = getCurrentPlayChord();
            if (!chord.length) {
                scheduleFinishPlaySession();
                return;
            }
            if (playback.isPlaying || playback.timers.size) stopPlayback();
            playback.currentTime = chord[0].time || 0;
            playback.nextIndex = state.playbackNotes.findIndex(note => note.time >= playback.currentTime);
            if (playback.nextIndex < 0) playback.nextIndex = state.playbackNotes.length;
            state.playChordPressed.clear();
            state.playPromptStartedAt = performance.now();
            setElementText(ui.dispMode, "Play Session");
            updateTransport();
            updateLivePlayStats();
            highlightPlayExpectedNote();
        }

        function scheduleFinishPlaySession() {
            if (state.playSessionFinishing) return;
            state.playSessionFinishing = true;
            setTimeout(finishPlaySession, 0);
        }

        function handlePracticeAttempt(actualMidi) {
            if (!isPracticeMode() || !state.customTrackLoaded) return;
            const chord = getCurrentTrainChord();
            if (!chord.length) return;
            const exact = chord.find(note => note.midi === actualMidi);
            const pitchMatch = chord.find(note => note.midi % 12 === actualMidi % 12);
            const expected = exact || pitchMatch || chord[0];
            const correct = !!(exact || (state.octaveFold && pitchMatch));
            if (correct) {
                state.trainPressed.add(state.octaveFold ? expected.midi % 12 : expected.midi);
            }
            const needed = new Set(chord.map(note => state.octaveFold ? note.midi % 12 : note.midi));
            const done = correct && Array.from(needed).every(note => state.trainPressed.has(note));
            if (done) {
                const notes = getTrainNotes();
                const time = chord[0].time;
                while (state.trainIndex < notes.length && Math.abs(notes[state.trainIndex].time - time) < 0.05) state.trainIndex++;
                state.trainPressed.clear();
                setTimeout(() => highlightNextTrainNote({ snapToNote: !playback.isPlaying }), 90);
            }
        }

        function handlePlayAttempt(actualMidi) {
            if (!isPlayMode() || !state.playSessionActive || !state.customTrackLoaded || state.playSessionFinishing) return;
            const chord = getCurrentPlayChord();
            if (!chord.length) return;
            const responseSeconds = state.playPromptStartedAt
                ? Math.max(0, (performance.now() - state.playPromptStartedAt) / 1000)
                : 0;
            const actualKey = playNoteKey(actualMidi);
            const needed = new Set(chord.map(playNoteKey));
            const eventTime = (chord[0].time || 0) + responseSeconds;
            const timestampMs = state.currentSession
                ? Math.round(performance.now() - state.currentSession.startedAt)
                : Math.round(eventTime * 1000);

            if (!needed.has(actualKey)) {
                chord.forEach((expected, index) => {
                    addPlayScoreEvent(expected, index === 0 ? actualMidi : null, false, eventTime, index > 0, { timestampMs });
                });
                advancePlaySessionChord(chord);
            } else {
                if (state.playChordPressed.has(actualKey)) return;
                state.playChordPressed.set(actualKey, { midi: actualMidi, eventTime, timestampMs });
                const done = Array.from(needed).every(key => state.playChordPressed.has(key));
                if (!done) return;
                chord.forEach(expected => {
                    const pressed = state.playChordPressed.get(playNoteKey(expected));
                    addPlayScoreEvent(expected, pressed?.midi ?? expected.midi, true, pressed?.eventTime ?? eventTime, false, {
                        timestampMs: pressed?.timestampMs ?? timestampMs
                    });
                });
                advancePlaySessionChord(chord);
            }

            updateLivePlayStats();
            if (state.playExpectedIndex >= state.playExpectedNotes.length) {
                scheduleFinishPlaySession();
            } else {
                movePlaySessionToExpectedNote();
            }
        }

        function timingScoreFromDeltaMs(deltaMs) {
            const seconds = Math.abs(deltaMs || 0) / 1000;
            if (seconds <= PERFECT_TIMING_WINDOW_MS / 1000) return 100;
            if (seconds >= 5) return 0;
            return Math.round(((5 - seconds) / 4) * 100);
        }

        function addPlayScoreEvent(expected, pressedMidi, correct, eventTime, missed = false, details = {}) {
            const timingDeltaMs = Math.round((eventTime - expected.time) * 1000);
            state.analysisEvents.push({
                expectedMidi: expected.midi,
                pressedMidi,
                correct,
                missed,
                timingDeltaMs,
                timeScore: missed ? 0 : timingScoreFromDeltaMs(timingDeltaMs),
                expectedTimeMs: Math.round((expected.time || 0) * 1000),
                timestamp_ms: details.timestampMs ?? (state.currentSession
                    ? Math.round(performance.now() - state.currentSession.startedAt)
                    : Math.round(eventTime * 1000))
            });
        }

        function markOverduePlayNotes(forceAll = false) {
            if (!state.playSessionActive && !forceAll) return;
            while (state.playExpectedIndex < state.playExpectedNotes.length) {
                const expected = state.playExpectedNotes[state.playExpectedIndex];
                if (!forceAll && playback.currentTime - expected.time <= 5) break;
                addPlayScoreEvent(expected, null, false, forceAll ? expected.time + 5 : playback.currentTime, true);
                state.playExpectedIndex++;
            }
        }

        function calculatePerformance(events, expectedCount, durationMs, options = {}) {
            const scored = events.filter(event => !event.extra);
            const denominator = options.useExpectedTotal
                ? Math.max(expectedCount || 0, scored.length)
                : scored.length;
            const correct = scored.filter(event => event.correct).length;
            const accuracy = denominator ? Math.round((correct / denominator) * 100) : 0;
            const timeScoreTotal = scored.reduce((sum, event) => sum + (event.timeScore ?? timingScoreFromDeltaMs(event.timingDeltaMs)), 0);
            const timeScore = denominator ? Math.round(timeScoreTotal / denominator) : 0;
            const score = denominator ? Math.round(accuracy * 0.8 + timeScore * 0.2) : 0;
            return {
                score,
                accuracy,
                timeScore,
                correct,
                total: denominator,
                consumed: scored.length,
                duration_ms: durationMs || 0
            };
        }

        function updateLivePlayStats() {
            const expectedCount = state.playExpectedNotes.length || getVisibleExpectedNotes().length;
            const stats = calculatePerformance(state.analysisEvents, expectedCount, Math.round(playback.currentTime * 1000), { useExpectedTotal: false });
            const consumed = Math.min(state.analysisEvents.length, expectedCount);
            show(ui.livePanel, isPlayMode() && state.customTrackLoaded);
            setElementText(ui.liveScore, String(stats.score));
            setElementText(ui.liveAccuracy, stats.consumed ? stats.accuracy + "%" : "0%");
            setElementText(ui.liveNotes, consumed + " / " + expectedCount);
            setElementText(ui.liveTime, formatTime(playback.currentTime));
        }

        function showPlaySessionPrompt() {
            if (!isPlayMode() || !state.customTrackLoaded || state.playSessionActive) return;
            stopPlayback();
            state.playSessionPromptOpen = true;
            state.playSessionComplete = false;
            setElementText(ui.dispMode, "Ready to Play");
            ui.modalPlaySession?.classList.add("open");
        }

        function hidePlaySessionPrompt() {
            state.playSessionPromptOpen = false;
            ui.modalPlaySession?.classList.remove("open");
        }

        async function beginPlaySession() {
            if (!state.customTrackLoaded || !isPlayMode()) return;
            await initAudio();
            hidePlaySessionPrompt();
            stopPlayback();
            releaseAllManualNotes();
            state.analysisEvents = [];
            state.analysisContext = null;
            state.activeNotes.clear();
            state.playExpectedNotes = getVisibleExpectedNotes();
            state.playExpectedIndex = 0;
            state.playChordPressed.clear();
            state.playPromptStartedAt = 0;
            state.playSessionActive = true;
            state.playSessionComplete = false;
            state.playSessionFinishing = false;
            state.isRecording = true;
            if (ui.btnPlayPause) {
                ui.btnPlayPause.disabled = true;
                ui.btnPlayPause.style.opacity = "0.55";
            }
            const songName = cleanSongName(state.songName) || cleanSongName(state.songFileName) || "Imported MIDI";
            state.currentSession = {
                session_id: "session_" + Date.now(),
                mode: "play",
                song_name: songName,
                song_file_name: state.songFileName || songName,
                startedAt: performance.now(),
                created_at: new Date().toISOString(),
                duration_ms: 0,
                events: [],
                summary: { total_notes: 0 }
            };
            playback.currentTime = 0;
            playback.nextIndex = 0;
            updateTransport();
            updateTrainSeekLock();
            updateLivePlayStats();
            setElementText(ui.dispMode, "Play Session");
            movePlaySessionToExpectedNote();
        }

        function cancelPlaySession() {
            hidePlaySessionPrompt();
            stopPlayback();
            releaseAllManualNotes();
            state.playSessionActive = false;
            state.playSessionComplete = false;
            state.isRecording = false;
            state.currentSession = null;
            state.activeNotes.clear();
            state.playExpectedIndex = 0;
            state.playChordPressed.clear();
            state.playPromptStartedAt = 0;
            state.playSessionFinishing = false;
            if (ui.btnPlayPause && isPlayMode()) {
                ui.btnPlayPause.disabled = true;
                ui.btnPlayPause.style.opacity = "0.55";
            }
            document.querySelectorAll(".train-hint").forEach(key => key.classList.remove("train-hint"));
            updateLivePlayStats();
        }

        function finishPlaySession() {
            const session = state.currentSession;
            if (!session && !state.playSessionActive) return;
            stopPlayback();
            releaseAllManualNotes();
            markOverduePlayNotes(true);
            flushRecordingNotes();
            hidePlaySessionPrompt();
            state.playSessionActive = false;
            state.playSessionComplete = true;
            state.playSessionFinishing = false;
            if (state.isRecording && session) {
                session.duration_ms = Math.round(performance.now() - session.startedAt);
                session.song_name = sessionSongName(session, state.songName);
                session.song_file_name = state.songFileName || session.song_file_name || session.song_name;
                session.expected_notes = state.playExpectedNotes.length;
                session.analysis = state.analysisEvents.slice();
                session.summary = {
                    ...calculatePerformance(state.analysisEvents, state.playExpectedNotes.length, session.duration_ms, { useExpectedTotal: true }),
                    total_notes: session.events.length,
                    expected_notes: state.playExpectedNotes.length,
                    song_name: session.song_name,
                    song_file_name: session.song_file_name
                };
                state.analysisContext = {
                    songName: session.song_name,
                    expectedCount: session.expected_notes,
                    durationMs: session.duration_ms,
                    createdAt: session.created_at
                };
                saveSession(session, { showModal: false });
            }
            state.isRecording = false;
            state.currentSession = null;
            state.playChordPressed.clear();
            state.playPromptStartedAt = 0;
            playback.currentTime = playback.duration;
            playback.nextIndex = state.playbackNotes.length;
            updateTransport();
            updateLivePlayStats();
            document.querySelectorAll(".train-hint").forEach(key => key.classList.remove("train-hint"));
            setElementText(ui.dispMode, "Play Complete");
            switchTab("analysis");
        }

        function buildAnalysis() {
            const events = state.analysisEvents;
            const context = state.analysisContext || {};
            const expectedCount = context.expectedCount || state.playExpectedNotes.length || events.length;
            const durationMs = context.durationMs ?? Math.round(playback.currentTime * 1000);
            const summary = calculatePerformance(events, expectedCount, durationMs, { useExpectedTotal: true });
            const total = events.length;
            const avgTiming = total
                ? Math.round(events.reduce((sum, event) => sum + Math.abs(event.timingDeltaMs || 0), 0) / total)
                : 0;
            let bestStreak = 0;
            let streak = 0;
            events.forEach(event => {
                if (event.correct) {
                    streak++;
                    bestStreak = Math.max(bestStreak, streak);
                } else {
                    streak = 0;
                }
            });

            setElementText($("an-total-notes"), String(total));
            setElementText($("an-score"), summary.score + "/100");
            setElementText($("an-accuracy"), summary.accuracy + "%");
            setElementText($("an-avg-timing"), avgTiming + "ms");
            setElementText($("an-avg-tip"), "100% timing at " + PERFECT_TIMING_WINDOW_MS + "ms average or faster");
            setElementText($("an-best-streak"), String(bestStreak));
            show($("analysis-grid"), total > 0);
            show($("analysis-empty"), total === 0);
            show($("btn-analysis-csv"), total > 0);
            show($("btn-analysis-print"), total > 0);
            const meta = [];
            const songName = cleanSongName(context.songName) || cleanSongName(state.songName);
            if (songName) meta.push(songName);
            if (context.createdAt) meta.push(new Date(context.createdAt).toLocaleString());
            if (expectedCount) meta.push(expectedCount + " expected notes");
            setElementText($("analysis-meta"), total > 0 ? meta.join(" - ") : "Complete a play session to see accuracy and timing here.");
            renderPitchChart(events);
            renderTimingChart(events);
            renderStreakHeatmap(events);
        }

        function renderPitchChart(events) {
            const svg = $("chart-pitch");
            if (!svg) return;
            svg.innerHTML = "";
            const grouped = new Map();
            events.forEach(event => {
                const key = midiName(event.expectedMidi);
                if (!grouped.has(key)) grouped.set(key, { total: 0, correct: 0 });
                const item = grouped.get(key);
                item.total++;
                if (event.correct) item.correct++;
            });
            const rows = Array.from(grouped.entries());
            const width = Math.max(380, rows.length * 34 + 48);
            const height = 160;
            svg.setAttribute("width", String(width));
            const maxH = 110;
            rows.forEach(([name, item], index) => {
                const pct = item.total ? item.correct / item.total : 0;
                const barH = Math.max(3, Math.round(pct * maxH));
                const x = 24 + index * 34;
                const y = height - 30 - barH;
                const rect = document.createElementNS("http://www.w3.org/2000/svg", "rect");
                rect.setAttribute("x", String(x));
                rect.setAttribute("y", String(y));
                rect.setAttribute("width", "22");
                rect.setAttribute("height", String(barH));
                rect.setAttribute("rx", "3");
                rect.setAttribute("fill", pct >= 0.8 ? "#58d68d" : pct >= 0.5 ? "#f6c85f" : "#ef6b6b");
                svg.appendChild(rect);
                const label = document.createElementNS("http://www.w3.org/2000/svg", "text");
                label.setAttribute("x", String(x + 11));
                label.setAttribute("y", String(height - 8));
                label.setAttribute("text-anchor", "middle");
                label.setAttribute("font-size", "9");
                label.setAttribute("fill", "#9aa8b7");
                label.textContent = name;
                svg.appendChild(label);
            });
        }

        function renderTimingChart(events) {
            const svg = $("chart-timing");
            if (!svg) return;
            svg.innerHTML = "";
            const values = events.map(event => Math.abs(event.timingDeltaMs || 0));
            const bins = new Array(8).fill(0);
            values.forEach(value => {
                bins[Math.min(7, Math.floor(value / 80))]++;
            });
            const max = Math.max(1, ...bins);
            bins.forEach((value, index) => {
                const h = Math.max(3, Math.round((value / max) * 108));
                const rect = document.createElementNS("http://www.w3.org/2000/svg", "rect");
                rect.setAttribute("x", String(28 + index * 40));
                rect.setAttribute("y", String(130 - h));
                rect.setAttribute("width", "26");
                rect.setAttribute("height", String(h));
                rect.setAttribute("rx", "3");
                rect.setAttribute("fill", "#36c2b4");
                svg.appendChild(rect);
            });
        }

        function renderStreakHeatmap(events) {
            const holder = $("streak-heatmap");
            if (!holder) return;
            holder.innerHTML = "";
            events.forEach(event => {
                const cell = document.createElement("span");
                cell.className = "streak-cell " + (event.correct ? "correct" : "wrong");
                cell.title = (event.correct ? "Correct" : "Wrong") + ": " + midiName(event.expectedMidi);
                holder.appendChild(cell);
            });
        }

        function switchTab(mode) {
            if (mode === "play" && state.mode === "play" && state.playSessionActive) return;
            if (mode !== "history") {
                stopHistoryReplay(true);
            }
            if (state.playSessionActive && mode !== "play") {
                cancelPlaySession();
            } else if (mode !== "play") {
                hidePlaySessionPrompt();
            }
            stopPlayback();
            releaseAllManualNotes();
            state.mode = mode;
            document.querySelectorAll("#piano-app .tab").forEach(tab => tab.classList.toggle("active", tab.dataset.mode === mode));
            document.querySelectorAll("#piano-app .view-panel").forEach(panel => panel.classList.remove("active-view"));
            if (mode === "analysis") {
                $("view-analysis")?.classList.add("active-view");
                buildAnalysis();
            } else if (mode === "history") {
                $("view-history")?.classList.add("active-view");
                loadHistory();
            } else {
                $("view-active")?.classList.add("active-view");
            }
            setElementText(ui.dispMode, modeTitle(mode));
            show(ui.trainControls, isPracticeMode());
            show(ui.livePanel, isPlayMode() && state.customTrackLoaded);
            if (ui.btnPlayPause) {
                const canUseTransport = !!state.playbackNotes.length && !isPlayMode();
                ui.btnPlayPause.disabled = !canUseTransport;
                ui.btnPlayPause.style.opacity = canUseTransport ? "1" : "0.55";
            }
            updateTrainSeekLock();
            updateLivePlayStats();
            if (isPracticeMode()) {
                syncPracticeIndexToTime();
                highlightNextTrainNote({ snapToNote: false });
            } else if (isPlayMode() && state.playSessionActive) {
                highlightPlayExpectedNote();
            } else {
                document.querySelectorAll(".train-hint").forEach(key => key.classList.remove("train-hint"));
            }
            if (isPlayMode()) showPlaySessionPrompt();
        }

        function getSessionDurationSeconds(session) {
            const eventEnd = Math.max(0, ...(session.events || []).map(event => (event.timestamp_ms || 0) + (event.duration_ms || 80)));
            const analysisEnd = Math.max(0, ...(session.analysis || []).map((event, index, list) => {
                const durationMs = session.duration_ms || eventEnd || Math.max(1, list.length * 500);
                return analysisEventTimeMs(event, index, list.length, durationMs) + 80;
            }));
            return Math.max((session.duration_ms || 0), eventEnd, analysisEnd) / 1000;
        }

        function releaseHistoryReplayNotes() {
            historyReplay.timers.forEach(timer => clearTimeout(timer));
            historyReplay.timers.clear();
            historyReplay.activeNotes.forEach(midi => triggerNoteOff(midi, "replay"));
            historyReplay.activeNotes.clear();
        }

        function updateHistoryReplayTransport() {
            const duration = historyReplay.duration || 0;
            if (ui.historyReplayProgress) {
                ui.historyReplayProgress.value = duration ? String((historyReplay.currentTime / duration) * 100) : "0";
            }
            setElementText(ui.historyReplayTime, formatTime(historyReplay.currentTime) + " / " + formatTime(duration));
            setElementText(ui.btnHistoryReplayPlay, historyReplay.isPlaying ? "Pause" : "Play");
            updateHistoryReplayPlayhead();
        }

        function stopHistoryReplay(hidePanel = false) {
            historyReplay.isPlaying = false;
            releaseHistoryReplayNotes();
            updateHistoryReplayTransport();
            if (hidePanel) {
                show(ui.historyReplayPanel, false);
                historyReplay.session = null;
                historyReplay.currentTime = 0;
                historyReplay.duration = 0;
                historyReplay.nextIndex = 0;
                historyReplay.sheetMap = [];
                if (ui.historyReplayStaff) ui.historyReplayStaff.innerHTML = "";
                if (ui.historyReplayMistakes) ui.historyReplayMistakes.innerHTML = "";
                show(ui.historyReplaySheet, false);
            }
        }

        function setHistoryReplayPosition(seconds) {
            releaseHistoryReplayNotes();
            historyReplay.currentTime = Math.max(0, Math.min(seconds, historyReplay.duration || 0));
            const events = historyReplay.session?.events || [];
            historyReplay.nextIndex = events.findIndex(event => (event.timestamp_ms || 0) / 1000 >= historyReplay.currentTime);
            if (historyReplay.nextIndex < 0) historyReplay.nextIndex = events.length;
            updateHistoryReplayTransport();
        }

        function analysisEventTimeMs(event, index, total, durationMs) {
            if (Number.isFinite(event.timestamp_ms)) return event.timestamp_ms;
            if (Number.isFinite(event.eventTimeMs)) return event.eventTimeMs;
            if (Number.isFinite(event.expectedTimeMs)) return event.expectedTimeMs;
            if (Number.isFinite(event.expectedTime)) return event.expectedTime * 1000;
            return total > 1 ? (index / (total - 1)) * durationMs : 0;
        }

        function getHistoryReplaySheetNotes(session) {
            const played = (session.events || [])
                .filter(event => Number.isFinite(event.midi))
                .map(event => ({
                    midi: event.midi,
                    name: event.note || midiName(event.midi),
                    time: (event.timestamp_ms || 0) / 1000,
                    duration: Math.max((event.duration_ms || 120) / 1000, 0.08)
                }))
                .sort((a, b) => a.time - b.time);
            if (played.length) return played;

            const analysis = session.analysis || [];
            const durationMs = Math.max(1, getSessionDurationSeconds(session) * 1000);
            return analysis.map((event, index) => {
                const midi = Number.isFinite(event.pressedMidi) ? event.pressedMidi : event.expectedMidi;
                if (!Number.isFinite(midi)) return null;
                return {
                    midi,
                    name: midiName(midi),
                    time: analysisEventTimeMs(event, index, analysis.length, durationMs) / 1000,
                    duration: 0.12
                };
            }).filter(Boolean);
        }

        function renderHistoryReplaySheet(session) {
            historyReplay.sheetMap = [];
            const notes = getHistoryReplaySheetNotes(session);
            show(ui.historyReplaySheet, notes.length > 0);
            if (!notes.length) {
                if (ui.historyReplayStaff) ui.historyReplayStaff.innerHTML = "";
                return;
            }
            renderSimpleStaff(ui.historyReplayStaff, notes, historyReplay.sheetMap, { height: 150, limit: 500 });
            updateHistoryReplayPlayhead();
        }

        function renderHistoryReplayMistakes(session) {
            if (!ui.historyReplayMistakes) return;
            ui.historyReplayMistakes.innerHTML = "";
            const analysis = session.analysis || [];
            const mistakes = analysis.filter(event => !event.correct || event.missed);
            const durationMs = Math.max(1, getSessionDurationSeconds(session) * 1000);
            const markersByTime = new Map();
            mistakes.forEach((event, mistakeIndex) => {
                const sourceIndex = analysis.indexOf(event);
                const timeMs = analysisEventTimeMs(event, sourceIndex < 0 ? mistakeIndex : sourceIndex, analysis.length, durationMs);
                const markerKey = String(Math.round(timeMs));
                const markerData = markersByTime.get(markerKey) || { timeMs, labels: [] };
                if (Number.isFinite(event.expectedMidi)) markerData.labels.push(midiName(event.expectedMidi));
                markersByTime.set(markerKey, markerData);
            });
            markersByTime.forEach(markerData => {
                const timeMs = markerData.timeMs;
                const percent = Math.max(0, Math.min(100, (timeMs / durationMs) * 100));
                const marker = document.createElement("span");
                marker.className = "history-replay-mistake";
                marker.style.left = percent + "%";
                marker.textContent = "x";
                marker.title = "Mistake at " + formatTime(timeMs / 1000)
                    + (markerData.labels.length ? " - expected " + markerData.labels.join(", ") : "");
                ui.historyReplayMistakes.appendChild(marker);
            });
        }

        function updateHistoryReplayPlayhead() {
            if (!ui.historyReplayPlayhead || !historyReplay.sheetMap.length) {
                if (ui.historyReplayPlayhead) ui.historyReplayPlayhead.hidden = true;
                return;
            }
            let closest = historyReplay.sheetMap[0];
            for (const item of historyReplay.sheetMap) {
                if (item.time <= historyReplay.currentTime) closest = item;
                else break;
            }
            const x = closest.x || 0;
            ui.historyReplayPlayhead.hidden = false;
            ui.historyReplayPlayhead.style.left = Math.max(0, x - 2) + "px";
            if (ui.historyReplaySheetScroll && x > ui.historyReplaySheetScroll.scrollLeft + ui.historyReplaySheetScroll.clientWidth * 0.74) {
                ui.historyReplaySheetScroll.scrollLeft = Math.max(0, x - ui.historyReplaySheetScroll.clientWidth * 0.35);
            }
        }

        function historyReplayLoop(timestamp) {
            if (!historyReplay.isPlaying || !historyReplay.session) return;
            const delta = (timestamp - historyReplay.lastFrame) / 1000;
            historyReplay.lastFrame = timestamp;
            historyReplay.currentTime += delta;
            const events = historyReplay.session.events || [];
            while (historyReplay.nextIndex < events.length && (events[historyReplay.nextIndex].timestamp_ms || 0) / 1000 <= historyReplay.currentTime) {
                const event = events[historyReplay.nextIndex];
                triggerNoteOn(event.midi, "replay", event.velocity || 80);
                historyReplay.activeNotes.add(event.midi);
                const timer = setTimeout(() => {
                    triggerNoteOff(event.midi, "replay");
                    historyReplay.activeNotes.delete(event.midi);
                    historyReplay.timers.delete(timer);
                }, Math.max(event.duration_ms || 80, 80));
                historyReplay.timers.add(timer);
                historyReplay.nextIndex++;
            }
            if (historyReplay.currentTime >= historyReplay.duration) {
                historyReplay.currentTime = historyReplay.duration;
                historyReplay.isPlaying = false;
                releaseHistoryReplayNotes();
                updateHistoryReplayTransport();
                return;
            }
            updateHistoryReplayTransport();
            requestAnimationFrame(historyReplayLoop);
        }

        async function startHistoryReplay() {
            if (!historyReplay.session) return;
            await initAudio();
            if (historyReplay.currentTime >= historyReplay.duration) setHistoryReplayPosition(0);
            historyReplay.isPlaying = true;
            historyReplay.lastFrame = performance.now();
            updateHistoryReplayTransport();
            requestAnimationFrame(historyReplayLoop);
        }

        function openHistoryReplay(session) {
            stopHistoryReplay(false);
            historyReplay.session = session;
            historyReplay.duration = getSessionDurationSeconds(session);
            historyReplay.currentTime = 0;
            historyReplay.nextIndex = 0;
            setElementText(ui.historyReplayTitle, sessionSongName(session, "Session replay"));
            show(ui.historyReplayPanel, true);
            renderHistoryReplaySheet(session);
            renderHistoryReplayMistakes(session);
            updateHistoryReplayTransport();
        }

        function openHistoryAnalysis(session) {
            const summary = session.summary || {};
            state.analysisEvents = Array.isArray(session.analysis) ? session.analysis.slice() : [];
            state.analysisContext = {
                songName: sessionSongName(session),
                expectedCount: summary.expected_notes ?? session.expected_notes ?? state.analysisEvents.length,
                durationMs: summary.duration_ms ?? session.duration_ms ?? 0,
                createdAt: session.created_at
            };
            switchTab("analysis");
        }

        function getSessions() {
            try { return JSON.parse(localStorage.getItem("pianoSessionsV033") || "[]"); } catch (e) { return []; }
        }

        function syncSessionToDatabase(session) {
            if (!window.PSM_CONFIG?.isLoggedIn || !window.PSM_CONFIG?.apiBase || !session) return;
            fetch(window.PSM_CONFIG.apiBase + "/save_performance.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(session)
            }).catch(() => {});
        }

        function saveSession(session, options = {}) {
            session.song_name = sessionSongName(session, state.songName);
            session.song_file_name = session.song_file_name || state.songFileName || session.song_name;
            session.summary = session.summary || {};
            session.summary.song_name = session.song_name;
            session.summary.song_file_name = session.song_file_name;
            const sessions = getSessions();
            sessions.unshift(session);
            try { localStorage.setItem("pianoSessionsV033", JSON.stringify(sessions.slice(0, 50))); } catch (e) {}
            setElementText($("stat-total"), String(session.summary.total_notes));
            setElementText($("stat-duration"), (session.duration_ms / 1000).toFixed(1) + "s");
            syncSessionToDatabase(session);
            if (options.showModal !== false) ui.modalSession?.classList.add("open");
            loadHistory();
        }

        function loadHistory() {
            if (!ui.historyList) return;
            const sessions = getSessions();
            ui.historyList.innerHTML = "";
            if (!sessions.length) {
                ui.historyList.innerHTML = '<p class="empty-state">No sessions saved yet.</p>';
                return;
            }
            sessions.forEach(session => {
                const item = document.createElement("div");
                item.className = "session-item";
                const summary = session.summary || {};
                const songName = sessionSongName(session);
                const score = summary.score ?? 0;
                const accuracy = summary.accuracy ?? 0;
                const duration = formatTime((summary.duration_ms || session.duration_ms || 0) / 1000);
                const expected = summary.expected_notes ?? session.expected_notes ?? summary.total_notes ?? 0;
                const createdAt = session.created_at ? new Date(session.created_at).toLocaleString() : "Saved session";
                item.innerHTML = '<div><strong class="session-title">' + escapeHtml(songName) + '</strong><div class="session-metrics"><span>' + escapeHtml(createdAt) + '</span><span>Score <b>' + score + '</b></span><span>Accuracy <b>' + accuracy + '%</b></span><span>Time <b>' + duration + '</b></span><span>Notes <b>' + expected + '</b></span></div></div><div class="piano-actions"><button class="btn secondary" data-action="analysis" data-id="' + session.session_id + '">Analysis</button><button class="btn secondary" data-action="replay" data-id="' + session.session_id + '">Replay</button><button class="btn secondary" data-action="export" data-id="' + session.session_id + '">Export</button></div>';
                ui.historyList.appendChild(item);
            });
            ui.historyList.querySelectorAll("button").forEach(button => {
                button.addEventListener("click", () => {
                    const session = sessions.find(item => item.session_id === button.dataset.id);
                    if (!session) return;
                    if (button.dataset.action === "analysis") openHistoryAnalysis(session);
                    if (button.dataset.action === "replay") openHistoryReplay(session);
                    if (button.dataset.action === "export") exportSessionMidi(session);
                });
            });
        }

        function exportSessionMidi(session) {
            const MidiCtor = getMidiCtor();
            if (!MidiCtor) return;
            const midi = new MidiCtor();
            const track = midi.addTrack();
            session.events.forEach(event => {
                track.addNote({
                    midi: event.midi,
                    time: event.timestamp_ms / 1000,
                    duration: Math.max(event.duration_ms / 1000, 0.08),
                    velocity: Math.min(1, (event.velocity || 80) / 127)
                });
            });
            const blob = new Blob([midi.toArray()], { type: "audio/midi" });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = session.session_id + ".mid";
            anchor.click();
            URL.revokeObjectURL(url);
        }

        async function connectMidi() {
            if (!navigator.requestMIDIAccess) {
                setElementText(ui.midiStatus, "MIDI Not Available");
                return;
            }
            try {
                const access = await navigator.requestMIDIAccess({ sysex: false });
                if (access.inputs.size === 0) {
                    setElementText(ui.midiStatus, "No MIDI Devices");
                    if (ui.midiStatusDot) ui.midiStatusDot.className = "status-dot";
                    return;
                }
                access.inputs.forEach(input => {
                    input.onmidimessage = event => {
                        const [status, note, velocity] = event.data;
                        const command = status & 0xf0;
                        if (command === 0x90 && velocity > 0) initAudio().then(() => triggerNoteOn(note, "midi", velocity));
                        if (command === 0x80 || (command === 0x90 && velocity === 0)) triggerNoteOff(note, "midi");
                    };
                });
                const first = Array.from(access.inputs.values())[0];
                setElementText(ui.midiStatus, "Connected: " + first.name);
                if (ui.midiStatusDot) ui.midiStatusDot.className = "status-dot connected";
            } catch (e) {
                setElementText(ui.midiStatus, "MIDI Not Available");
                if (ui.midiStatusDot) ui.midiStatusDot.className = "status-dot";
            }
        }

        function openKeybindsModal() {
            const modal = $("modal-keybinds");
            const grid = $("keybinds-grid");
            if (!modal || !grid) return;
            grid.innerHTML = "";
            const mappedByMidi = {};
            Object.entries(currentKeyMap).forEach(([key, midi]) => { mappedByMidi[midi] = key; });
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
            modal.classList.add("open");
        }

        function resetPiano() {
            cancelPlaySession();
            stopHistoryReplay(true);
            stopPlayback();
            if (synth && synth.releaseAll) synth.releaseAll();
            state.displayNotes = [];
            state.customTrackLoaded = false;
            state.songName = "No MIDI loaded";
            state.songFileName = "";
            state.loadedTracks = [];
            state.playbackNotes = [];
            state.sheetMap = [];
            state.timeSignatureNumerator = 4;
            state.timeSignatureDenominator = 4;
            state.measureBeats = 4;
            playback.currentTime = 0;
            playback.duration = 0;
            playback.nextIndex = 0;
            state.analysisEvents = [];
            state.playExpectedNotes = [];
            state.playExpectedIndex = 0;
            state.playChordPressed.clear();
            state.playPromptStartedAt = 0;
            state.playSessionFinishing = false;
            state.analysisContext = null;
            state.trainIndex = 0;
            state.trainPressed.clear();
            setElementText(ui.dispNote, "--");
            setElementText(ui.dispMode, modeTitle(state.mode));
            setElementText(ui.midiTime, "0:00 / 0:00");
            if (ui.midiProgress) ui.midiProgress.value = 0;
            show(ui.midiPlayerControls, false);
            show(ui.songInfoPanel, false);
            show(ui.livePanel, false);
            show($("analysis-grid"), false);
            show($("analysis-empty"), true);
            if (ui.globalMidiUpload) ui.globalMidiUpload.value = "";
            document.querySelectorAll(".active-key,.train-hint").forEach(key => key.classList.remove("active-key", "train-hint"));
            renderStaff();
            updateTrainSeekLock();
            updateLivePlayStats();
        }

        function updateScrollZoneLimits() {
            if (!ui.keyboard || !ui.scrollZoneLeft || !ui.scrollZoneRight) return;
            ui.scrollZoneLeft.classList.toggle("at-limit", ui.keyboard.scrollLeft <= 2);
            ui.scrollZoneRight.classList.toggle("at-limit", ui.keyboard.scrollLeft >= ui.keyboard.scrollWidth - ui.keyboard.clientWidth - 2);
        }

        document.querySelectorAll("#piano-app .tab").forEach(tab => tab.addEventListener("click", () => switchTab(tab.dataset.mode)));
        ui.btnMidi?.addEventListener("click", connectMidi);
        ui.globalMidiUpload?.addEventListener("change", event => loadMidiFile(event.target.files[0]));
        ui.btnPlayPause?.addEventListener("click", () => {
            if (isPlayMode() && !state.playSessionActive) {
                showPlaySessionPrompt();
                return;
            }
            if (isPlayMode()) return;
            initAudio().then(() => playback.isPlaying ? stopPlayback() : startPlayback());
        });
        ui.midiProgress?.addEventListener("input", event => {
            if (isPlayMode()) {
                updateTransport();
                return;
            }
            playback.currentTime = (Number(event.target.value) / 100) * playback.duration;
            playback.nextIndex = state.playbackNotes.findIndex(note => note.time >= playback.currentTime);
            if (playback.nextIndex < 0) playback.nextIndex = state.playbackNotes.length;
            if (isPracticeMode()) {
                syncPracticeIndexToTime();
                highlightNextTrainNote({ snapToNote: false });
            }
            updateTransport();
        });
        ui.btnTracksEnableAll?.addEventListener("click", () => {
            ui.trackListContainer?.querySelectorAll("input[type=checkbox]").forEach(input => input.checked = true);
            renderStaff();
            rebuildFocusList();
            updateSongInfo();
            resetTrainPosition();
        });
        ui.btnTracksDisableAll?.addEventListener("click", () => {
            ui.trackListContainer?.querySelectorAll("input[type=checkbox]").forEach(input => input.checked = false);
            renderStaff();
            rebuildFocusList();
            updateSongInfo();
            resetTrainPosition();
        });
        $("btn-train-retry")?.addEventListener("click", retryPracticeSession);
        ui.btnPlaySessionStart?.addEventListener("click", beginPlaySession);
        ui.btnPlaySessionCancel?.addEventListener("click", hidePlaySessionPrompt);
        ui.btnHistoryReplayPlay?.addEventListener("click", () => {
            if (historyReplay.isPlaying) stopHistoryReplay(false);
            else startHistoryReplay();
        });
        ui.btnHistoryReplayBack?.addEventListener("click", () => stopHistoryReplay(true));
        ui.historyReplayProgress?.addEventListener("input", event => {
            const wasPlaying = historyReplay.isPlaying;
            historyReplay.isPlaying = false;
            setHistoryReplayPosition((Number(event.target.value) / 100) * (historyReplay.duration || 0));
            if (wasPlaying) startHistoryReplay();
        });
        $("btn-reset")?.addEventListener("click", resetPiano);
        $("btn-modal-close")?.addEventListener("click", () => ui.modalSession?.classList.remove("open"));
        ui.btnExportMidi?.addEventListener("click", () => {
            const sessions = getSessions();
            if (sessions.length) exportSessionMidi(sessions[0]);
        });
        $("btn-zoom-in")?.addEventListener("click", () => {
            state.sheetZoom = Math.min(2.5, Number((state.sheetZoom + 0.15).toFixed(2)));
            applySheetZoom();
            showZoomControls();
        });
        $("btn-zoom-out")?.addEventListener("click", () => {
            state.sheetZoom = Math.max(0.45, Number((state.sheetZoom - 0.15).toFixed(2)));
            applySheetZoom();
            showZoomControls();
        });
        ui.sheetScrollArea?.addEventListener("mouseenter", showZoomControls);
        ui.sheetScrollArea?.addEventListener("wheel", showZoomControls, { passive: true });
        ui.scrollZoneLeft?.addEventListener("click", () => ui.keyboard?.scrollBy({ left: -7 * keyWidthWhite, behavior: "smooth" }));
        ui.scrollZoneRight?.addEventListener("click", () => ui.keyboard?.scrollBy({ left: 7 * keyWidthWhite, behavior: "smooth" }));
        ui.keyboard?.addEventListener("scroll", updateScrollZoneLimits, { passive: true });
        $("show-labels")?.addEventListener("change", event => {
            state.showLabels = event.target.checked;
            buildMainPiano();
        });
        ui.toggleKeyHighlight?.addEventListener("change", event => {
            state.keyLightsOn = event.target.checked;
            if (!state.keyLightsOn) document.querySelectorAll(".active-key,.train-hint").forEach(key => key.classList.remove("active-key", "train-hint"));
            else if (isPracticeMode()) highlightNextTrainNote({ snapToNote: false });
        });
        ui.toggleStaffLabels?.addEventListener("change", event => {
            state.staffNoteLabels = event.target.checked;
            renderStaff();
            updateTransport();
        });
        $("btn-mode-full")?.addEventListener("click", () => {
            state.octaveFold = false;
            $("btn-mode-full")?.classList.add("active");
            $("btn-mode-fold")?.classList.remove("active");
            buildMainPiano();
            if (isPracticeMode()) highlightNextTrainNote({ snapToNote: false });
        });
        $("btn-mode-fold")?.addEventListener("click", () => {
            state.octaveFold = true;
            $("btn-mode-fold")?.classList.add("active");
            $("btn-mode-full")?.classList.remove("active");
            buildMainPiano();
            if (isPracticeMode()) highlightNextTrainNote({ snapToNote: false });
        });
        $("btn-octave-down")?.addEventListener("click", () => {
            state.baseOctave = Math.max(1, state.baseOctave - 1);
            setElementText($("disp-octave"), String(state.baseOctave));
            buildMainPiano();
        });
        $("btn-octave-up")?.addEventListener("click", () => {
            state.baseOctave = Math.min(7, state.baseOctave + 1);
            setElementText($("disp-octave"), String(state.baseOctave));
            buildMainPiano();
        });
        $("btn-keybinds")?.addEventListener("click", openKeybindsModal);
        $("btn-keybinds-save")?.addEventListener("click", () => {
            currentKeyMap = {};
            $("keybinds-grid")?.querySelectorAll("input").forEach(input => {
                if (input.value) currentKeyMap[input.value.toLowerCase()] = Number(input.dataset.midi);
            });
            saveKeyMap();
            $("modal-keybinds")?.classList.remove("open");
        });
        $("btn-keybinds-reset")?.addEventListener("click", () => {
            currentKeyMap = { ...DEFAULT_KEY_MAP };
            saveKeyMap();
            openKeybindsModal();
        });
        $("btn-analysis-csv")?.addEventListener("click", () => {
            const rows = [["Expected", "Pressed", "Correct", "TimingDeltaMs", "TimeScore"]].concat(
                state.analysisEvents.map(event => [
                    event.expectedMidi == null ? "" : midiName(event.expectedMidi),
                    event.pressedMidi == null ? "Missed" : midiName(event.pressedMidi),
                    event.correct,
                    event.timingDeltaMs,
                    event.timeScore ?? ""
                ])
            );
            const blob = new Blob([rows.map(row => row.join(",")).join("\n")], { type: "text/csv" });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = "analysis_" + Date.now() + ".csv";
            anchor.click();
            URL.revokeObjectURL(url);
        });
        $("btn-analysis-print")?.addEventListener("click", () => window.print());

        window.addEventListener("keydown", async event => {
            if (event.repeat || document.activeElement?.tagName === "INPUT") return;
            const midi = currentKeyMap[event.key.toLowerCase()];
            if (midi === undefined || state.activeManualNotes.has(midi)) return;
            state.activeManualNotes.add(midi);
            await initAudio();
            triggerNoteOn(midi, "keyboard");
        });
        window.addEventListener("keyup", event => {
            const midi = currentKeyMap[event.key.toLowerCase()];
            if (midi === undefined) return;
            state.activeManualNotes.delete(midi);
            triggerNoteOff(midi, "keyboard");
        });
        window.addEventListener("resize", () => {
            syncKeySizing();
            buildMainPiano();
        });

        buildMainPiano();
        renderStaff();
        loadHistory();
        const requestedMode = new URLSearchParams(window.location.search).get("mode");
        switchTab(["practice", "play", "analysis", "history"].includes(requestedMode) ? requestedMode : "practice");

        // ── Auto-load a song piece from the database ──────────────────────────
        const pieceId = Number(root?.dataset?.pieceId || 0);
        if (pieceId > 0) {
            const banner     = document.getElementById("song-load-banner");
            const bannerText = document.getElementById("song-load-banner-text");

            if (banner) banner.style.display = "flex";
            if (bannerText) bannerText.textContent = "⏳ Loading song piece…";

            const apiBase = window.PSM_CONFIG?.apiBase || "api";
            fetch(apiBase + "/get_song_piece.php?piece_id=" + pieceId)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || "Failed to load piece.");

                    const label = data.song_name + " – " + data.piece_name;

                    if (data.type === "midi_file" && data.file_url) {
                        // Fetch the physical MIDI file and pipe it through loadMidiFile
                        return fetch(data.file_url)
                            .then(r => r.blob())
                            .then(blob => {
                                const file = new File([blob], label + ".mid", { type: "audio/midi" });
                                loadMidiFile(file);
                                if (bannerText) bannerText.textContent = "🎵 Loaded: " + label;
                            });
                    }

                    if (data.type === "json_notes" && Array.isArray(data.notes)) {
                        // Convert JSON note array into the internal playback format
                        const BPM = 120;
                        const SEC_PER_BEAT = 60 / BPM;

                        stopPlayback();
                        state.customTrackLoaded = true;
                        state.songName     = label;
                        state.songFileName = label;
                        state.loadedTracks = [{ name: data.piece_name, notes: data.notes }];
                        state.timeSignatureNumerator   = 4;
                        state.timeSignatureDenominator = 4;
                        state.measureBeats = 4;
                        state.playbackNotes = [];

                        data.notes.forEach(note => {
                            const beat         = Number(note.beat ?? 0);
                            const durBeats     = Number(note.durationBeats ?? 1);
                            const timeSeconds  = beat * SEC_PER_BEAT;
                            const durSeconds   = durBeats * SEC_PER_BEAT;
                            const midi         = Number(note.midi ?? 60);

                            state.playbackNotes.push({
                                name:          note.name || midiName(midi),
                                midi,
                                time:          timeSeconds,
                                beat,
                                duration:      durSeconds,
                                durationBeats: durBeats,
                                ticks:         null,
                                durationTicks: null,
                                velocity:      80,
                                trackIndex:    0
                            });
                        });

                        state.playbackNotes.sort((a, b) => a.time - b.time);
                        playback.duration    = state.playbackNotes.length
                            ? Math.max(...state.playbackNotes.map(n => n.time + n.duration))
                            : 0;
                        playback.currentTime = 0;
                        playback.nextIndex   = 0;

                        show(ui.midiPlayerControls, true);
                        rebuildTrackLists();
                        renderStaff();
                        updateSongInfo();
                        resetTrainPosition();
                        setElementText(ui.dispMode, "Practice");
                        setElementText(ui.midiTime, "0:00 / " + formatTime(playback.duration));
                        if (ui.midiProgress) ui.midiProgress.value = 0;
                        updateTrainSeekLock();
                        updateLivePlayStats();
                        if (isPracticeMode()) highlightNextTrainNote({ snapToNote: true });

                        if (bannerText) bannerText.textContent = "🎵 Loaded: " + label;
                        return;
                    }

                    throw new Error("Unknown piece type.");
                })
                .catch(err => {
                    if (bannerText) bannerText.textContent = "❌ Could not load piece: " + err.message;
                    console.error("[Piano] Song load error:", err);
                });
        }
    }

    function initGamePage() {
        const root = $("game-app");
        if (!root) return;

        syncKeySizing();
        const menu = $("game-menu");
        const menuBack = $("gm-menu-back");
        const tabGroup = $("gm-tab-group");

        function selectGame(mode) {
            document.dispatchEvent(new CustomEvent("psm:game-menu"));
            show(menu, false);
            show(menuBack, true);
            show(tabGroup, true);
            document.querySelectorAll(".gm-tab").forEach(item => item.classList.toggle("active", item.dataset.gm === mode));
            document.querySelectorAll(".gm-section").forEach(section => section.classList.remove("active-gm"));
            $("gm-" + mode)?.classList.add("active-gm");
            show($("gr-settings"), mode === "recognition");
            show($("gr-active"), false);
            show($("gr-end"), false);
            show($("gi-settings"), mode === "identify");
            show($("gi-active"), false);
            show($("gi-end"), false);
        }

        function showGameMenu() {
            document.dispatchEvent(new CustomEvent("psm:game-menu"));
            show(menu, true);
            show(menuBack, false);
            show(tabGroup, false);
            document.querySelectorAll(".gm-section").forEach(section => section.classList.remove("active-gm"));
            document.querySelectorAll(".gm-tab").forEach(item => item.classList.remove("active"));
        }

        document.querySelectorAll(".gm-tab").forEach(tab => {
            tab.addEventListener("click", () => {
                selectGame(tab.dataset.gm);
            });
        });
        document.querySelectorAll(".game-menu-card").forEach(card => {
            card.addEventListener("click", () => selectGame(card.dataset.gm));
        });
        menuBack?.addEventListener("click", showGameMenu);

        initNoteRecognitionGame();
        initIdentifyGame();

        const requestedGame = new URLSearchParams(window.location.search).get("mode");
        if (["recognition", "identify"].includes(requestedGame)) {
            selectGame(requestedGame);
        } else {
            showGameMenu();
        }
    }

    function initNoteRecognitionGame() {
        if (!$("gm-recognition")) return;
        let difficulty = "easy";
        let cardTotal = 10;
        let notesPerCard = 1;
        let cards = [];
        let index = 0;
        let solved = 0;
        let startTime = 0;
        let cardStart = 0;
        let totalMs = 0;
        let timer = null;
        let active = false;
        let current = [];
        let pressed = new Set();
        let mistakes = [];
        const positions = [];
        const piano = $("gr-piano");
        const staff = $("gr-staff-el");
        const focus = $("gr-focus-zone");
        const sheetWrap = $("gr-sheet-wrap");

        $("gr-diff-easy")?.addEventListener("click", () => {
            difficulty = "easy";
            $("gr-diff-easy")?.classList.add("active");
            $("gr-diff-hard")?.classList.remove("active");
        });
        $("gr-diff-hard")?.addEventListener("click", () => {
            difficulty = "hard";
            $("gr-diff-hard")?.classList.add("active");
            $("gr-diff-easy")?.classList.remove("active");
        });
        $("gr-cards-down")?.addEventListener("click", () => {
            cardTotal = Math.max(3, cardTotal <= 5 ? 3 : cardTotal - 5);
            setElementText($("gr-cards-val"), String(cardTotal));
        });
        $("gr-cards-up")?.addEventListener("click", () => {
            cardTotal = Math.min(50, cardTotal < 5 ? 5 : cardTotal + 5);
            setElementText($("gr-cards-val"), String(cardTotal));
        });
        $("gr-conc-down")?.addEventListener("click", () => {
            notesPerCard = Math.max(1, notesPerCard - 1);
            setElementText($("gr-conc-val"), String(notesPerCard));
        });
        $("gr-conc-up")?.addEventListener("click", () => {
            notesPerCard = Math.min(6, notesPerCard + 1);
            setElementText($("gr-conc-val"), String(notesPerCard));
        });

        function makeCards() {
            const start = difficulty === "easy" ? 60 : 48;
            const length = difficulty === "easy" ? 12 : 36;
            const pool = Array.from({ length }, (_, i) => start + i);
            return Array.from({ length: cardTotal }, () => pick(pool, notesPerCard));
        }

        function pick(pool, count) {
            const copy = pool.slice();
            const chosen = [];
            while (chosen.length < count && copy.length) {
                chosen.push(copy.splice(Math.floor(Math.random() * copy.length), 1)[0]);
            }
            return chosen;
        }

        function renderCards() {
            if (!staff) return;
            const VF = getVexFlow();
            staff.innerHTML = "";
            positions.length = 0;
            if (!VF) return;
            const perMeasure = 4;
            const staveW = 205;
            const firstExtra = 80;
            const measures = [];
            for (let i = 0; i < cards.length; i += perMeasure) measures.push(cards.slice(i, i + perMeasure));
            const renderer = new VF.Renderer(staff, VF.Renderer.Backends.SVG);
            renderer.resize(firstExtra + measures.length * staveW + 40, 126);
            const context = renderer.getContext();
            let x = 10;
            measures.forEach((measure, measureIndex) => {
                const width = measureIndex === 0 ? staveW + firstExtra : staveW;
                const stave = new VF.Stave(x, 12, width);
                if (measureIndex === 0) stave.addClef("treble").addTimeSignature("4/4");
                stave.setContext(context).draw();
                const tickables = [];
                measure.forEach(chord => {
                    try {
                        const keys = chord.map(midi => vfKeyForMidi(midi).key);
                        const note = new VF.StaveNote({ clef: "treble", keys, duration: "q" });
                        chord.forEach((midi, idx) => {
                            if (vfKeyForMidi(midi).sharp) note.addModifier(new VF.Accidental("#"), idx);
                        });
                        note._card = chord;
                        tickables.push(note);
                    } catch (e) {}
                });
                for (let i = tickables.length; i < perMeasure; i++) {
                    tickables.push(new VF.StaveNote({ clef: "treble", keys: ["b/4"], duration: "qr" }));
                }
                const voice = new VF.Voice({ num_beats: 4, beat_value: 4 }).setStrict(false);
                voice.addTickables(tickables);
                try {
                    new VF.Formatter().joinVoices([voice]).format([voice], width - (measureIndex === 0 ? firstExtra + 10 : 18));
                    voice.draw(context, stave);
                    tickables.forEach(tick => {
                        if (tick._card) positions.push({ x: tick.getAbsoluteX(), card: tick._card });
                    });
                } catch (e) {}
                x += width;
            });
        }

        function buildGamePiano() {
            createPianoKeys(piano, {
                start: difficulty === "easy" ? 60 : 48,
                end: difficulty === "easy" ? 71 : 83,
                centered: true,
                onDown: noteOn,
                onUp: noteOff
            });
        }

        function noteOn(midi) {
            if (synth) {
                try { synth.triggerAttack(midiName(midi), Tone.now(), 0.74); } catch (e) {}
            }
            piano?.querySelector('.key[data-midi="' + midi + '"]')?.classList.add("active-key");
            if (!active) return;
            const matched = difficulty === "easy" ? current.some(note => note % 12 === midi % 12) : current.includes(midi);
            if (!matched) {
                mistakes.push({
                    card: index + 1,
                    pressed: midiName(midi),
                    expected: current.map(midiName),
                    elapsed_ms: Date.now() - startTime
                });
                return;
            }
            pressed.add(difficulty === "easy" ? midi % 12 : midi);
            const needed = new Set(current.map(note => difficulty === "easy" ? note % 12 : note));
            if (Array.from(needed).every(note => pressed.has(note))) setTimeout(nextCard, 160);
        }

        function noteOff(midi) {
            if (synth) {
                try { synth.triggerRelease(midiName(midi), Tone.now()); } catch (e) {}
            }
            piano?.querySelector('.key[data-midi="' + midi + '"]')?.classList.remove("active-key");
        }

        function focusCard() {
            if (!focus || !positions[index]) return;
            focus.hidden = false;
            focus.style.left = Math.max(0, positions[index].x - 26) + "px";
            sheetWrap?.scrollTo({ left: Math.max(0, positions[index].x - sheetWrap.clientWidth * 0.4), behavior: "smooth" });
        }

        function setCard() {
            current = cards[index];
            pressed.clear();
            cardStart = Date.now();
            setElementText($("gr-solved"), String(solved));
            setElementText($("gr-remain"), String(cardTotal - solved));
            focusCard();
        }

        function nextCard() {
            totalMs += Date.now() - cardStart;
            solved++;
            if (solved >= cardTotal) {
                endGame();
                return;
            }
            index++;
            setCard();
        }

        function updateTimer() {
            if (!active) return;
            const elapsed = Math.round((Date.now() - startTime) / 1000);
            setElementText($("gr-time"), formatTime(elapsed));
            if (solved > 0) setElementText($("gr-avg"), (Math.round(totalMs / solved / 100) / 10).toFixed(1) + "s");
        }

        function startGame() {
            active = true;
            index = 0;
            solved = 0;
            totalMs = 0;
            mistakes = [];
            startTime = Date.now();
            cards = makeCards();
            show($("gr-settings"), false);
            show($("gr-end"), false);
            show($("gr-active"), true);
            renderCards();
            buildGamePiano();
            clearInterval(timer);
            timer = setInterval(updateTimer, 500);
            setCard();
        }

        function endGame() {
            active = false;
            clearInterval(timer);
            const elapsed = Math.round((Date.now() - startTime) / 1000);
            const accuracy = Math.round((solved / Math.max(1, solved + mistakes.length)) * 100);
            const avgSeconds = solved ? (Math.round(totalMs / solved / 100) / 10).toFixed(1) : "--";
            setElementText($("gre-time"), formatTime(elapsed));
            setElementText($("gre-avg"), avgSeconds + (avgSeconds === "--" ? "" : "s"));
            setElementText($("gre-solved"), solved + " / " + cardTotal);
            show($("gr-active"), false);
            show($("gr-end"), true);
            syncActivityToDatabase({
                activity_type: "GAME",
                title: "Game: Note Recognition",
                mode_key: "game:recognition",
                shortcut_url: "game.php?mode=recognition",
                score: accuracy,
                accuracy,
                duration_seconds: elapsed,
                summary: {
                    game: "Note Recognition",
                    difficulty,
                    cards_solved: solved,
                    total_cards: cardTotal,
                    notes_per_card: notesPerCard,
                    mistakes: mistakes.length,
                    average_seconds_per_card: avgSeconds
                },
                details: {
                    cards: cards.map(card => card.map(midiName)),
                    mistakes
                }
            });
        }

        $("gr-start")?.addEventListener("click", () => initAudio().then(startGame));
        $("gr-replay")?.addEventListener("click", () => initAudio().then(startGame));
        $("gr-back")?.addEventListener("click", () => {
            active = false;
            clearInterval(timer);
            show($("gr-active"), false);
            show($("gr-end"), false);
            show($("gr-settings"), true);
            if (focus) focus.hidden = true;
        });
        document.addEventListener("psm:game-menu", () => {
            active = false;
            clearInterval(timer);
            show($("gr-active"), false);
            show($("gr-end"), false);
            show($("gr-settings"), true);
            if (focus) focus.hidden = true;
        });

        window.addEventListener("keydown", async event => {
            if (!active || document.activeElement?.tagName === "INPUT") return;
            if (!$("gm-recognition")?.classList.contains("active-gm")) return;
            const midi = currentKeyMap[event.key.toLowerCase()];
            if (midi === undefined || event.repeat) return;
            await initAudio();
            noteOn(midi);
        });
        window.addEventListener("keyup", event => {
            const midi = currentKeyMap[event.key.toLowerCase()];
            if (midi !== undefined) noteOff(midi);
        });
    }

    function initIdentifyGame() {
        if (!$("gm-identify")) return;
        let mode = "key";
        let rounds = 10;
        let active = false;
        let round = 0;
        let correct = 0;
        let streak = 0;
        let bestStreak = 0;
        let currentNote = 60;
        let answered = false;
        let startTime = 0;
        let timer = null;
        let attempts = [];

        function setIdentifyMode(nextMode) {
            mode = nextMode === "note" ? "note" : "key";
            $("gi-tab-key")?.classList.toggle("active", mode === "key");
            $("gi-tab-note")?.classList.toggle("active", mode === "note");
        }

        $("gi-tab-key")?.addEventListener("click", () => setIdentifyMode("key"));
        $("gi-tab-note")?.addEventListener("click", () => setIdentifyMode("note"));
        if (new URLSearchParams(window.location.search).get("variant") === "note") {
            setIdentifyMode("note");
        }
        $("gi-rounds-down")?.addEventListener("click", () => {
            rounds = Math.max(3, rounds <= 5 ? 3 : rounds - 5);
            setElementText($("gi-rounds-val"), String(rounds));
        });
        $("gi-rounds-up")?.addEventListener("click", () => {
            rounds = Math.min(50, rounds < 5 ? 5 : rounds + 5);
            setElementText($("gi-rounds-val"), String(rounds));
        });

        function buildRoulette() {
            const wheel = $("gi-roulette");
            if (!wheel) return;
            wheel.innerHTML = "";
            const radius = 104;
            const center = 135;
            NOTE_NAMES.forEach((name, index) => {
                const angle = index / 12 * Math.PI * 2 - Math.PI / 2;
                const button = document.createElement("button");
                button.className = "roulette-btn";
                button.dataset.noteIdx = String(index);
                button.textContent = name;
                button.style.left = center + radius * Math.cos(angle) + "px";
                button.style.top = center + radius * Math.sin(angle) + "px";
                button.addEventListener("click", () => answer(index));
                wheel.appendChild(button);
            });
        }

        function renderStaffNote(midi) {
            const holder = $("gi-staff-el");
            if (!holder) return;
            const note = { midi, time: 0, name: midiName(midi) };
            renderSimpleStaff(holder, [note], null, { height: 112, limit: 1 });
        }

        function renderPromptPiano(midi) {
            const holder = $("gi-piano-keys");
            if (!holder) return;
            createPianoKeys(holder, {
                start: 48,
                end: 83,
                centered: true,
                onDown: () => {},
                onUp: () => {}
            });
            holder.querySelector('.key[data-midi="' + midi + '"]')?.classList.add("active-key");
        }

        function showPrompt() {
            currentNote = 48 + Math.floor(Math.random() * 36);
            answered = false;
            const hint = $("gi-hint");
            if (hint) {
                hint.textContent = "";
                hint.className = "gi-hint";
            }
            $("gi-roulette")?.querySelectorAll(".roulette-btn").forEach(button => button.classList.remove("correct", "wrong"));
            setElementText($("gi-prompt-label"), mode === "key" ? "Which note is highlighted?" : "Which note is shown on the staff?");
            if (mode === "key") {
                show($("gi-staff-el"), false);
                show($("gi-piano-prompt"), true);
                renderPromptPiano(currentNote);
                initAudio().then(() => {
                    if (synth) {
                        try { synth.triggerAttackRelease(midiName(currentNote), "2n", Tone.now(), 0.72); } catch (e) {}
                    }
                });
            } else {
                show($("gi-piano-prompt"), false);
                show($("gi-staff-el"), true);
                renderStaffNote(currentNote);
            }
        }

        function answer(noteIndex) {
            if (!active || answered) return;
            answered = true;
            const isCorrect = noteIndex === currentNote % 12;
            const chosen = $("gi-roulette")?.querySelector('.roulette-btn[data-note-idx="' + noteIndex + '"]');
            chosen?.classList.add(isCorrect ? "correct" : "wrong");
            if (isCorrect) {
                correct++;
                streak++;
                bestStreak = Math.max(bestStreak, streak);
                const hint = $("gi-hint");
                if (hint) {
                    hint.textContent = "Correct: " + NOTE_NAMES[noteIndex];
                    hint.className = "gi-hint correct";
                }
            } else {
                streak = 0;
                $("gi-roulette")?.querySelector('.roulette-btn[data-note-idx="' + (currentNote % 12) + '"]')?.classList.add("correct");
                const hint = $("gi-hint");
                if (hint) {
                    hint.textContent = "Wrong - answer: " + NOTE_NAMES[currentNote % 12];
                    hint.className = "gi-hint wrong";
                }
            }
            attempts.push({
                round: round + 1,
                expected: midiName(currentNote),
                chosen: NOTE_NAMES[noteIndex],
                correct: isCorrect
            });
            setElementText($("gi-score"), String(correct));
            setElementText($("gi-streak"), String(streak));
            setTimeout(() => {
                round++;
                if (round >= rounds) {
                    endIdentify();
                    return;
                }
                setElementText($("gi-remain"), String(rounds - round));
                showPrompt();
            }, 850);
        }

        function updateTimer() {
            if (!active) return;
            setElementText($("gi-time"), formatTime(Math.round((Date.now() - startTime) / 1000)));
        }

        function startIdentify() {
            active = true;
            round = 0;
            correct = 0;
            streak = 0;
            bestStreak = 0;
            attempts = [];
            startTime = Date.now();
            setElementText($("gi-score"), "0");
            setElementText($("gi-streak"), "0");
            setElementText($("gi-remain"), String(rounds));
            show($("gi-settings"), false);
            show($("gi-end"), false);
            show($("gi-active"), true);
            buildRoulette();
            clearInterval(timer);
            timer = setInterval(updateTimer, 500);
            showPrompt();
        }

        function endIdentify() {
            active = false;
            clearInterval(timer);
            const elapsed = Math.round((Date.now() - startTime) / 1000);
            const accuracy = Math.round((correct / rounds) * 100);
            setElementText($("gie-time"), formatTime(elapsed));
            setElementText($("gie-score"), correct + " / " + rounds);
            setElementText($("gie-acc"), accuracy + "%");
            show($("gi-active"), false);
            show($("gi-end"), true);
            syncActivityToDatabase({
                activity_type: "GAME",
                title: "Game: " + (mode === "key" ? "Key Mode" : "Note Mode"),
                mode_key: "game:identify:" + mode,
                shortcut_url: "game.php?mode=identify&variant=" + mode,
                score: accuracy,
                accuracy,
                duration_seconds: elapsed,
                summary: {
                    game: "Key and Note",
                    mode,
                    correct,
                    total_rounds: rounds,
                    best_streak: bestStreak
                },
                details: {
                    attempts
                }
            });
        }

        $("gi-start")?.addEventListener("click", () => initAudio().then(startIdentify));
        $("gi-replay")?.addEventListener("click", () => initAudio().then(startIdentify));
        $("gi-back")?.addEventListener("click", () => {
            active = false;
            clearInterval(timer);
            show($("gi-active"), false);
            show($("gi-end"), false);
            show($("gi-settings"), true);
        });
        document.addEventListener("psm:game-menu", () => {
            active = false;
            clearInterval(timer);
            show($("gi-active"), false);
            show($("gi-end"), false);
            show($("gi-settings"), true);
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        initPianoPage();
        initGamePage();
    });
})();
