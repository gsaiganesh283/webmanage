<?php
// AJAX helper for the "Fetch title" buttons.
require __DIR__ . '/includes/bootstrap.php';

require_login();
header('Content-Type: application/json');
echo json_encode(['title' => fetch_page_title($_GET['url'] ?? '')]);
