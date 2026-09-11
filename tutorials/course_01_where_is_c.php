<?php include ('../includes/header.php'); ?>
<link rel="stylesheet" href="../assets/css/tutorial.css">

<div class="lesson-container">

    <div class="lesson-sidebar">
        <h2>Course 1</h2>

        <ul>
            <li><a href="#section-1">12 Keys</a></li>
            <li><a href="#section-2">Find C</a></li>
            <li><a href="#section-3">Middle C</a></li>
            <li><a href="#section-4">Staff</a></li>
            <li><a href="#section-5">Octaves</a></li>
            <li><a href="#section-6">Practice</a></li>
        </ul>
    </div>

    <div class="lesson-content">

        <section id="section-1" class="lesson-section">
            <h1>12 Keys</h1>

            <p>
                Piano keys repeat in groups of 12 notes.
                There are 7 white keys and 5 black keys.
            </p>

            <?php
                $sectionTitle = '12 Piano Keys';
                $sectionDescription = 'Notice the repeating pattern of white and black keys.';
                $highlightKeys = 'C4,D4,E4,F4,G4,A4,B4,C#4,D#4,F#4,G#4,A#4';
                $disableUnrelated = 'false';
                include '../includes/tutorials/piano-frame.php';
            ?>
        </section>


        <section id="section-2" class="lesson-section">
            <h1>Where is C?</h1>

            <p>
                C is always the white key immediately to the left of two black keys.
            </p>

            <?php
                $sectionTitle = 'Find all C Keys';
                $sectionDescription = 'All C keys are highlighted.';
                $highlightKeys = 'C2,C3,C4,C5';
                $disableUnrelated = 'true';
                include '../includes/tutorials/piano-frame.php';
            ?>
        </section>


        <section id="section-3" class="lesson-section">
            <h1>Middle C</h1>

            <p>
                Middle C is the central reference note on the piano.
            </p>

            <?php
                $sectionTitle = 'Middle C';
                $sectionDescription = 'This is Middle C (C4).';
                $highlightKeys = 'C4';
                $disableUnrelated = 'true';
                include '../includes/tutorials/piano-frame.php';
            ?>
        </section>

        <section id="section-4" class="lesson-section">
            <h1>Staff</h1>

            <p>
                Music is written on lines and spaces called the staff.
            </p>

            <div class="staff-demo">
                <img src="../assets/images/staff-middle-c.png">
            </div>

            <?php
                $sectionTitle = 'Staff and Piano';
                $sectionDescription = 'Middle C on the staff and piano.';
                $highlightKeys = 'C4';
                $disableUnrelated = 'true';
                include '../includes/tutorials/piano-frame.php';
            ?>
        </section>


        <section id="section-5" class="lesson-section">
            <h1>What is an Octave?</h1>

            <p>
                An octave is the distance between one note and the next note with the same name.
            </p>

            <?php
                $sectionTitle = 'Octaves';
                $sectionDescription = 'All highlighted notes are C notes in different octaves.';
                $highlightKeys = 'C2,C3,C4,C5';
                $disableUnrelated = 'true';
                include '../includes/tutorials/piano-frame.php';
            ?>
        </section>


        <section id="section-6" class="lesson-section">
            <h1>Interactive Practice</h1>

            <div class="practice-box">
                <p>Press all C keys on the piano.</p>
                <button id="start-c-practice">Start Practice</button>
                <div id="practice-feedback"></div>
            </div>

            <div id="practice-piano"></div>
        </section>

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js"></script>
<script src="../assets/js/tutorials/tutorial-engine.js"></script>
<script src="../assets/js/tutorials/tutorial-course-01.js"></script>

<?php include '../includes/footer.php'; ?>