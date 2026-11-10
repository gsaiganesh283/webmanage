<?php
function page_header(string $title, ?array $user = null): void
{
    $msg = flash();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/logo.svg">
    <link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    <meta name="theme-color" content="#4263eb">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="index.php"><img src="assets/logo.svg" alt="" class="brand-logo"><?= e(APP_NAME) ?></a>
        <?php if ($user): ?>
            <nav>
                <a href="import.php" title="Import links from an Excel or CSV file">Import</a>
                <a class="btn btn-primary" href="website_form.php">+ Add website</a>
                <span class="muted hide-sm"><?= e($user['name']) ?></span>
                <a href="logout.php">Logout</a>
            </nav>
        <?php endif; ?>
    </div>
</header>
<main class="container">
<?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
<?php
}

function page_footer(): void
{
    ?>
</main>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}

function show_errors(array $errors): void
{
    foreach ($errors as $err) {
        echo '<div class="error">' . e($err) . '</div>';
    }
}
