<?php
// Serves a website's logo. Looks it up and caches it the first time it is requested.
// Works with the web session or the mobile app's Bearer token. 404 = no logo (show letter badge).
require __DIR__ . '/includes/bootstrap.php';

$user = current_user() ?? (bearer_token() ? user_for_token(bearer_token()) : null);
session_write_close(); // don't block the page's other requests while fetching
if (!$user) {
    http_response_code(401);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT id, url, icon FROM websites WHERE id = ? AND user_id = ?');
$st->execute([$id, $user['id']]);
$website = $st->fetch();
if (!$website) {
    http_response_code(404);
    exit;
}

$icon = $website['icon'] ?? discover_icon($website);
$file = ICON_DIR . '/' . basename($icon);
if ($icon === '' || !is_file($file)) {
    header('Cache-Control: private, max-age=3600');
    http_response_code(404);
    exit;
}

$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=31536000, immutable'); // URL changes when the logo changes
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'"); // SVGs can't run scripts
readfile($file);
