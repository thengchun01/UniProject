# Global MIDI Device Manager — Feature Notes

**Version:** 0.4.1  
**Date:** 2026-09-22  
**Status:** Implemented

---

## Purpose

Previously, MIDI device connection was handled locally inside `piano.js` with its own
`requestMIDIAccess` call. This meant:

- Navigating away from `piano.php` dropped the connection.
- The tutorial and game pages had no MIDI support at all.
- The user had to click "Connect MIDI" again on every page visit.

The global MIDI manager centralises all MIDI access into a single module loaded on
every page so devices stay connected across navigation.

---

## Files Involved

| File | Change |
|---|---|
| `assets/js/midi-manager.js` | **NEW** — Global MIDI singleton |
| `assets/js/piano-core.js` | Listens to `globalMidiMessage` in `createPianoKeys` |
| `assets/js/piano.js` | `connectMidi()` delegates to `MidiManager`; also listens to `globalMidiMessage` for the full piano engine |
| `assets/css/style.css` | MIDI connect toast styles added |
| `includes/footer.php` | Loads `midi-manager.js` on every page |

---

## How It Works

### 1. Startup (every page)

`midi-manager.js` is loaded by `footer.php` on every page. After `DOMContentLoaded`
it calls `navigator.requestMIDIAccess({ sysex: false })` once.

### 2. Device detection

The manager registers `midiAccess.onstatechange`. When a device is plugged in, a
`scanInputs()` call runs 300ms later to let the device initialise.

### 3. Prompt toast

If a device is found and the user has not already accepted or declined it this session,
a non-blocking toast appears in the bottom-right corner:

```
🎹  MIDI Device Detected
    [device name]
    [Connect]  [Not now]
```

- **Connect** — binds the device immediately.
- **Not now** — records the device name in `sessionStorage` so it will not prompt again this session.

### 4. Session memory

- Accepted device name → `sessionStorage: psm_midi_connected_device`
- Declined device names → `sessionStorage: psm_midi_declined_devices` (JSON array)

When the user navigates to a new page, `scanInputs()` runs again and automatically
re-binds the previously accepted device without showing a toast.

### 5. Note routing (`globalMidiMessage` event)

When a bound device sends a MIDI message:

```
input.onmidimessage → MidiManager → window.dispatchEvent('globalMidiMessage', { type, note, velocity })
```

Every piano widget on the page that uses `createPianoKeys` (piano-core.js) or
`piano.js` listens to this event and feeds the notes into the existing
`triggerNoteOn` / `triggerNoteOff` / `onDown` / `onUp` handlers.

---

## Public API (`window.MidiManager`)

| Method | Returns | Description |
|---|---|---|
| `isConnected()` | `boolean` | Whether a device is currently bound |
| `getConnectedInput()` | `MIDIInput \| null` | The active MIDIInput object |
| `connect()` | `Promise<void>` | Programmatically connect first available device |
| `disconnect()` | `void` | Unbind and decline current device |
| `scan()` | `void` | Re-scan inputs and prompt if a new device is available |

---

## Pages that benefit

| Page | How MIDI input arrives |
|---|---|
| `piano.php` | `globalMidiMessage` → `triggerNoteOn/Off` (full piano engine) |
| `tutorials/lesson.php` | `globalMidiMessage` → `piano-core.js createPianoKeys onDown/Up` |
| `game.php` (note recognition, key & note) | `globalMidiMessage` → `piano-core.js createPianoKeys onDown/Up` |
| All other pages | Toast prompt appears if a device is plugged in; no piano widget means no note handling needed |

---

## Browser Support

Requires the **Web MIDI API** (`navigator.requestMIDIAccess`).

- Supported: Chrome, Edge, Opera (desktop).
- Not supported: Firefox, Safari (no native Web MIDI).

If the browser does not support Web MIDI, `midi-manager.js` exits silently. All pages
remain fully functional using mouse, touch, and keyboard input.

---

## Known Limitations

- `sessionStorage` is cleared when the browser tab is closed. The user will be
  prompted again on the next visit, which is the correct behaviour (avoids stale
  permission grants across separate sessions).
- Only the **first available input** is offered automatically. If multiple devices are
  connected simultaneously, only the first non-declined one is offered by the toast.
  Users can explicitly bind a specific device from `piano.php` using the
  "Connect MIDI" button, which calls `MidiManager.connect()`. When no device
  is found, `connect()` shows the same non-blocking toast as a notice instead
  of a browser alert.
- The `sysex: false` flag is used for `requestMIDIAccess` — SysEx messages are not
  forwarded.
