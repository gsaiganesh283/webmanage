<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
if (!find_website($user['id'], $id)) {
    http_response_code(404);
    exit('Website not found.');
}

switch ($_POST['action'] ?? '') {
    case 'favorite':
        toggle_favorite($user['id'], $id);
        $back = $_POST['back'] ?? 'index.php';
        // Only allow local redirects
        if (str_contains($back, '://') || str_starts_with($back, '//')) {
            $back = 'index.php';
        }
        redirect($back);
    case 'refresh_icon':
        reset_icon($id);
        $icon = discover_icon(['id' => $id, 'url' => find_website($user['id'], $id)['url']]);
        flash($icon === '' ? 'No logo found on this website, so a letter badge is shown.' : 'Logo updated.');
        redirect('website.php?id=' . $id);
    case 'delete':
        delete_website($user['id'], $id);
        flash('Website deleted.');
        redirect('index.php');
}
redirect('index.php');
