<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?php echo e(url_path('assets/css/homepage.css')); ?>">

<section class="hero-section">
    <div class="hero-background" style="background-image: url('https://images.unsplash.com/photo-1552422535-c45813c61732?auto=format&fit=crop&q=80&w=1600');"></div>
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <h1>Piano Learning System</h1>
        <p>
            Learn piano through interactive tutorials, virtual piano practice,
            guided songs, and educational games designed for beginners.
        </p>
        <div class="hero-actions">
            <a href="tutorial.php" class="btn primary-btn">Start Learning</a>
            <a href="piano.php" class="btn secondary-btn">Open Virtual Piano</a>
        </div>
    </div>
</section>

<div class="container">
    <section class="data-section">
        <h2 class="section-title">Learning Journey</h2>
        <div class="card-grid">
            <div class="card step-card">
                <div class="step-number">1</div>
                <h3>Learn Piano Basics</h3>
                <p>Understand piano keys, note names, octaves, and keyboard layout.</p>
            </div>
            <div class="card step-card">
                <div class="step-number">2</div>
                <h3>Read Music Notes</h3>
                <p>Learn how notes are displayed on musical staffs and improve recognition.</p>
            </div>
            <div class="card step-card">
                <div class="step-number">3</div>
                <h3>Practice Songs</h3>
                <p>Apply your knowledge through beginner-friendly songs.</p>
            </div>
            <div class="card step-card">
                <div class="step-number">4</div>
                <h3>Challenge Yourself</h3>
                <p>Improve speed and accuracy through interactive piano games.</p>
            </div>
        </div>
    </section>

    <section class="data-section">
        <h2 class="section-title">Explore Features</h2>
        <div class="card-grid">
            <div class="card feature-card">
                <div class="placeholder">
                    <img src="https://images.unsplash.com/photo-1520523839897-bd0b52f945a0?auto=format&fit=crop&q=80&w=500" alt="Tutorials">
                </div>
                <h3>Tutorials</h3>
                <p>Follow structured lessons that teach piano fundamentals step by step.</p>
                <a href="tutorial.php" class="text-button">Open Tutorials</a>
            </div>

            <div class="card feature-card">
                <div class="placeholder">
                    <img src="https://images.unsplash.com/photo-1507838153428-9d983cbc9f41?auto=format&fit=crop&q=80&w=500" alt="Song Library">
                </div>
                <h3>Song Library</h3>
                <p>Practice piano pieces and learn through guided song exercises.</p>
                <a href="songs.php" class="text-button">Browse Songs</a>
            </div>

            <div class="card feature-card">
                <div class="placeholder">
                    <img src="https://images.unsplash.com/photo-1550684848-fac1c5b4e853?auto=format&fit=crop&q=80&w=500" alt="Virtual Piano">
                </div>
                <h3>Virtual Piano</h3>
                <p>Play directly in your browser using your keyboard, mouse, or MIDI device.</p>
                <a href="piano.php" class="text-button">Start Playing</a>
            </div>

            <div class="card feature-card">
                <div class="placeholder">
                    <img src="https://images.unsplash.com/photo-1611339555312-e607c8352fd7?auto=format&fit=crop&q=80&w=500" alt="Learning Games">
                </div>
                <h3>Learning Games</h3>
                <p>Test note recognition and strengthen your understanding through challenges.</p>
                <a href="game.php" class="text-button">Play Games</a>
            </div>
        </div>
    </section>

    <section class="data-section bg-light">
        <h2 class="section-title">Why Use This Platform?</h2>
        <div class="card-grid">
            <div class="card">
                <h3>Interactive Learning</h3>
                <p>Learn through hands-on practice rather than only reading theory.</p>
            </div>
            <div class="card">
                <h3>Browser Based</h3>
                <p>Practice anywhere without installing additional software.</p>
            </div>
            <div class="card">
                <h3>MIDI Support</h3>
                <p>Import MIDI files and interact with songs directly in the system.</p>
            </div>
            <div class="card">
                <h3>Progress Tracking</h3>
                <p>Save learning activities and monitor your improvement over time.</p>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="cta-content">
            <h2>Create an Account</h2>
            <p>Register an account to track tutorial progress, save activities, and monitor your piano learning journey.</p>
            <div class="hero-actions">
                <a href="account.php?mode=register" class="btn primary-btn">Register</a>
                <a href="account.php?mode=login" class="btn secondary-btn">Login</a>
            </div>
        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
