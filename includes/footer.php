
</div>

<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-brand">
            <a href="<?= e(url_path('index.php')); ?>" class="site-footer-logo">Piano Course</a>
            <p>Interactive lessons, songs, and practice tools for every stage of learning.</p>
        </div>
        <nav class="site-footer-links" aria-label="Footer navigation">
            <a href="<?= e(url_path('tutorial.php')); ?>">Tutorials</a>
            <a href="<?= e(url_path('songs.php')); ?>">Songs</a>
            <a href="<?= e(url_path('piano.php')); ?>">Piano</a>
            <a href="<?= e(url_path('account.php')); ?>">Account</a>
        </nav>
        <p class="site-footer-copyright">&copy; <?= date('Y'); ?> Piano Course</p>
    </div>
</footer>

<button id="scroll-top-btn" type="button" aria-label="Back to top" title="Back to top">↑</button>

<script src="<?php echo e(url_path('assets/js/main.js')); ?>"></script>
<script src="<?php echo e(url_path('assets/js/midi-manager.js')); ?>"></script>
<script src="<?php echo e(url_path('assets/js/piano-core.js')); ?>"></script>
<script src="<?php echo e(url_path('assets/js/piano.js')); ?>"></script>
<script src="<?php echo e(url_path('assets/js/user-widget.js')); ?>"></script>

</body>
</html>
