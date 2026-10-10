<?php
// Simple standalone Hello World page.
// Not wired into the shared layout yet; add includes/header.php and
// includes/footer.php if this should match the rest of the site.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hello World</title>
</head>
<body>
    <h1><?= htmlspecialchars('Hello, World!', ENT_QUOTES, 'UTF-8') ?></h1>
</body>
</html>
