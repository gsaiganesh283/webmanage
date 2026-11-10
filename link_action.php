<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
verify_csrf();

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'delete') {
    $link = find_link($user['id'], $id);
    if ($link) {
        delete_link($id);
        flash('Link deleted.');
        redirect('website.php?id=' . $link['website_id']);
    }
    redirect('index.php');
}

if ($action === 'save') {
    $website = find_website($user['id'], (int)($_POST['website_id'] ?? 0));
    if (!$website || ($id && !find_link($user['id'], $id))) {
        http_response_code(404);
        exit('Not found.');
    }
    [$data, $errors] = validate_link($_POST);
    if ($errors) {
        flash(implode(' ', $errors));
    } elseif ($id) {
        update_link($id, $data);
        flash('Link updated.');
    } else {
        create_link($website['id'], $data);
        flash('Link added.');
    }
    redirect('website.php?id=' . $website['id']);
}

redirect('index.php');
