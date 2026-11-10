<?php
/**
 * JSON REST API used by the mobile app.
 * Routes are given after index.php, e.g. /api/index.php/websites/5
 *
 * POST   /auth/register          {name, email, password}  -> {token, user}
 * POST   /auth/login             {email, password}        -> {token, user}
 * POST   /auth/logout
 * GET    /me
 * GET    /websites?q=&category=&favorite=1
 * POST   /websites               {url, name?, category?, notes?, is_favorite?}
 * GET    /websites/{id}          -> website + links
 * PUT    /websites/{id}
 * DELETE /websites/{id}
 * POST   /websites/{id}/favorite (toggle)
 * POST   /websites/{id}/icon     re-download the website's logo
 *        (logo image: GET <web root>/{website.icon_url} with the same Bearer header; 404 = none)
 * POST   /websites/{id}/links    {url, title?, username?, password?, notes?}
 * PUT    /links/{id}
 * DELETE /links/{id}
 * GET    /categories
 * GET    /fetch-title?url=
 *
 * Authenticated routes need the header:  Authorization: Bearer <token>
 */
require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): void
{
    respond(['error' => $message], $status);
}

function body(): array
{
    $json = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($json) ? $json : $_POST;
}

function api_user(): array
{
    $token = bearer_token();
    $user = $token ? user_for_token($token) : null;
    if (!$user) {
        fail('Not logged in', 401);
    }
    return $user;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_SERVER['PATH_INFO'] ?? ($_GET['route'] ?? ''), '/');
$parts = $path === '' ? [] : explode('/', $path);

try {
    // ----- Auth -----
    if ($path === 'auth/register' && $method === 'POST') {
        $in = body();
        [$user, $err] = register_user($in['name'] ?? '', $in['email'] ?? '', $in['password'] ?? '');
        if (!$user) {
            fail($err, 422);
        }
        respond(['token' => create_api_token($user['id']), 'user' => $user], 201);
    }
    if ($path === 'auth/login' && $method === 'POST') {
        $in = body();
        $user = authenticate($in['email'] ?? '', $in['password'] ?? '');
        if (!$user) {
            fail('Wrong email or password', 401);
        }
        respond(['token' => create_api_token($user['id']), 'user' => $user]);
    }
    if ($path === 'auth/logout' && $method === 'POST') {
        revoke_api_token(bearer_token());
        respond(['ok' => true]);
    }

    $user = api_user();
    $uid = $user['id'];

    if ($path === 'me' && $method === 'GET') {
        respond(['user' => $user]);
    }
    if ($path === 'categories' && $method === 'GET') {
        respond(['categories' => list_categories($uid)]);
    }
    if ($path === 'fetch-title' && $method === 'GET') {
        respond(['title' => fetch_page_title($_GET['url'] ?? '')]);
    }

    // ----- Websites -----
    if (($parts[0] ?? '') === 'websites') {
        $id = isset($parts[1]) ? (int)$parts[1] : 0;
        $sub = $parts[2] ?? '';

        if (!$id && $method === 'GET') {
            respond(['websites' => list_websites($uid, [
                'q'        => $_GET['q'] ?? '',
                'category' => $_GET['category'] ?? '',
                'favorite' => !empty($_GET['favorite']),
            ])]);
        }
        if (!$id && $method === 'POST') {
            [$data, $errors] = validate_website(body());
            if ($errors) {
                fail(implode(' ', $errors), 422);
            }
            $newId = create_website($uid, $data);
            respond(['website' => find_website($uid, $newId) + ['links' => []]], 201);
        }

        $website = find_website($uid, $id);
        if (!$website) {
            fail('Website not found', 404);
        }

        if ($sub === '' && $method === 'GET') {
            respond(['website' => $website + ['links' => list_links($id)]]);
        }
        if ($sub === '' && $method === 'PUT') {
            [$data, $errors] = validate_website(body() + $website);
            if ($errors) {
                fail(implode(' ', $errors), 422);
            }
            update_website($uid, $id, $data);
            respond(['website' => find_website($uid, $id) + ['links' => list_links($id)]]);
        }
        if ($sub === '' && $method === 'DELETE') {
            delete_website($uid, $id);
            respond(['ok' => true]);
        }
        if ($sub === 'favorite' && $method === 'POST') {
            toggle_favorite($uid, $id);
            respond(['website' => find_website($uid, $id)]);
        }
        if ($sub === 'icon' && $method === 'POST') {
            reset_icon($id);
            discover_icon($website);
            respond(['website' => find_website($uid, $id)]);
        }
        if ($sub === 'links' && $method === 'POST') {
            [$data, $errors] = validate_link(body());
            if ($errors) {
                fail(implode(' ', $errors), 422);
            }
            $linkId = create_link($id, $data);
            respond(['link' => find_link($uid, $linkId)], 201);
        }
    }

    // ----- Links -----
    if (($parts[0] ?? '') === 'links' && isset($parts[1])) {
        $link = find_link($uid, (int)$parts[1]);
        if (!$link) {
            fail('Link not found', 404);
        }
        if ($method === 'PUT') {
            [$data, $errors] = validate_link(body() + $link);
            if ($errors) {
                fail(implode(' ', $errors), 422);
            }
            update_link($link['id'], $data);
            respond(['link' => find_link($uid, $link['id'])]);
        }
        if ($method === 'DELETE') {
            delete_link($link['id']);
            respond(['ok' => true]);
        }
    }

    fail('Not found', 404);
} catch (PDOException $e) {
    fail('Database error. Is MySQL running?', 500);
}
