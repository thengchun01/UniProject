class TutorialPiano {
    constructor(container, options = {}) {
        this.container = container;
        this.highlightKeys = options.highlightKeys || [];
        this.disableUnrelated = options.disableUnrelated || false;
        this.octaves = options.octaves || 4;

        this.synth = new Tone.PolySynth().toDestination();

        this.notes = [
            'C', 'C#', 'D', 'D#', 'E',
            'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'
        ];

        this.render();
    }

    render() {

        const piano = document.createElement('div');
        piano.className = 'tutorial-piano-ui';

        let octaveStart = 2;

        for (let octave = octaveStart; octave < octaveStart + this.octaves; octave++) {

            this.notes.forEach(note => {

                const key = document.createElement('div');

                const fullNote = note + octave;

                key.className = note.includes('#')
                    ? 'key black-key'
                    : 'key white-key';

                key.dataset.note = fullNote;
                key.innerHTML = `<span>${fullNote}</span>`;

                if (this.highlightKeys.includes(fullNote)) {
                    key.classList.add('highlight-key');
                }
                else if (this.disableUnrelated) {
                    key.classList.add('dim-key');
                }

                key.addEventListener('click', async () => {
                    await Tone.start();
                    this.playNote(fullNote);
                });

                piano.appendChild(key);
            });
        }

        this.container.appendChild(piano);
    }

    playNote(note) {
        this.synth.triggerAttackRelease(note, '8n');
    }
}


window.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('.tutorial-piano').forEach(container => {

        const highlight = container.dataset.highlight.split(',');

        new TutorialPiano(container, {
            highlightKeys: highlight,
            disableUnrelated: container.dataset.disableUnrelated === 'true',
            octaves: parseInt(container.dataset.octaves)
        });
    });
});