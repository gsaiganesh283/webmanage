<?php
// Data access shared by the web pages and the mobile API.
// Every website/link query is scoped to the owning user.

// ---------- Users ----------

function find_user(int $id): ?array
{
    $st = db()->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Returns [user|null, error|null]. */
function register_user(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [null, 'Please enter your name and a valid email.'];
    }
    if (strlen($password) < 6) {
        return [null, 'Password must be at least 6 characters.'];
    }
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) {
        return [null, 'An account with this email already exists.'];
    }
    db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    return [find_user((int)db()->lastInsertId()), null];
}

function authenticate(string $email, string $password): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([strtolower(trim($email))]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return null;
    }
    return find_user((int)$row['id']);
}

// ---------- API tokens ----------

function create_api_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO api_tokens (user_id, token_hash) VALUES (?, ?)')
        ->execute([$userId, hash('sha256', $token)]);
    return $token;
}

function user_for_token(string $token): ?array
{
    $hash = hash('sha256', $token);
    $st = db()->prepare('SELECT user_id FROM api_tokens WHERE token_hash = ?');
    $st->execute([$hash]);
    $userId = $st->fetchColumn();
    if (!$userId) {
        return null;
    }
    db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?')->execute([$hash]);
    return find_user((int)$userId);
}

function revoke_api_token(string $token): void
{
    db()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
}

// ---------- Websites ----------

function cast_website(array $w): array
{
    $w['id'] = (int)$w['id'];
    $w['is_favorite'] = (bool)$w['is_favorite'];
    if (isset($w['link_count'])) {
        $w['link_count'] = (int)$w['link_count'];
    }
    // Logo served by icon.php (null when the site is known to have none → show the letter badge)
    $w['icon_url'] = $w['icon'] === '' ? null : 'icon.php?id=' . $w['id'] . '&v=' . urlencode((string)$w['icon']);
    $w['icon_letter'] = icon_letter($w['name']);
    $w['icon_hue'] = icon_hue($w['name']);
    unset($w['user_id'], $w['icon']);
    return $w;
}

/** Filters: q (search text), category, favorite (bool). */
function list_websites(int $userId, array $filters = []): array
{
    $sql = 'SELECT w.*, (SELECT COUNT(*) FROM links l WHERE l.website_id = w.id) AS link_count
            FROM websites w WHERE w.user_id = ?';
    $params = [$userId];

    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $sql .= ' AND (w.name LIKE ? OR w.url LIKE ? OR w.notes LIKE ? OR w.category LIKE ?
                  OR EXISTS (SELECT 1 FROM links l WHERE l.website_id = w.id AND (l.title LIKE ? OR l.url LIKE ? OR l.notes LIKE ?)))';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if (($filters['category'] ?? '') !== '') {
        $sql .= ' AND w.category = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['favorite'])) {
        $sql .= ' AND w.is_favorite = 1';
    }
    $sql .= ' ORDER BY w.is_favorite DESC, w.updated_at DESC';

    $st = db()->prepare($sql);
    $st->execute($params);
    return array_map('cast_website', $st->fetchAll());
}

function find_website(int $userId, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM websites WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $w = $st->fetch();
    return $w ? cast_website($w) : null;
}

function list_categories(int $userId): array
{
    $st = db()->prepare("SELECT DISTINCT category FROM websites WHERE user_id = ? AND category <> '' ORDER BY category");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Validates input. Returns [cleanData, errors]. */
function validate_website(array $in): array
{
    $data = [
        'name'        => trim($in['name'] ?? ''),
        'url'         => normalize_url($in['url'] ?? ''),
        'category'    => trim($in['category'] ?? ''),
        'notes'       => trim($in['notes'] ?? ''),
        'is_favorite' => !empty($in['is_favorite']) ? 1 : 0,
    ];
    $errors = [];
    if (!is_valid_url($data['url'])) {
        $errors[] = 'Please enter a valid website URL.';
    }
    if ($data['name'] === '' && !$errors) {
        $data['name'] = fetch_page_title($data['url']) ?: url_host($data['url']);
    }
    $data['name'] = mb_substr($data['name'], 0, 200);
    $data['category'] = mb_substr($data['category'], 0, 100);
    return [$data, $errors];
}

function create_website(int $userId, array $data): int
{
    db()->prepare('INSERT INTO websites (user_id, name, url, category, notes, is_favorite) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $data['name'], $data['url'], $data['category'], $data['notes'], $data['is_favorite']]);
    return (int)db()->lastInsertId();
}

function update_website(int $userId, int $id, array $data): void
{
    $old = find_website($userId, $id);
    if ($old && url_host($old['url']) !== url_host($data['url'])) {
        reset_icon($id); // different site → fetch its logo again
    }
    db()->prepare('UPDATE websites SET name = ?, url = ?, category = ?, notes = ?, is_favorite = ? WHERE id = ? AND user_id = ?')
        ->execute([$data['name'], $data['url'], $data['category'], $data['notes'], $data['is_favorite'], $id, $userId]);
}

function toggle_favorite(int $userId, int $id): void
{
    db()->prepare('UPDATE websites SET is_favorite = 1 - is_favorite WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

function delete_website(int $userId, int $id): void
{
    if (find_website($userId, $id)) {
        reset_icon($id); // removes the cached logo file
    }
    db()->prepare('DELETE FROM websites WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

// ---------- Important links (belong to a website) ----------

function cast_link(array $l): array
{
    $l['id'] = (int)$l['id'];
    $l['website_id'] = (int)$l['website_id'];
    return $l;
}

function list_links(int $websiteId): array
{
    $st = db()->prepare('SELECT * FROM links WHERE website_id = ? ORDER BY created_at, id');
    $st->execute([$websiteId]);
    return array_map('cast_link', $st->fetchAll());
}

/** Finds a link only if its website belongs to the user. */
function find_link(int $userId, int $id): ?array
{
    $st = db()->prepare('SELECT l.* FROM links l JOIN websites w ON w.id = l.website_id WHERE l.id = ? AND w.user_id = ?');
    $st->execute([$id, $userId]);
    $l = $st->fetch();
    return $l ? cast_link($l) : null;
}

function validate_link(array $in): array
{
    $data = [
        'title'    => trim($in['title'] ?? ''),
        'url'      => normalize_url($in['url'] ?? ''),
        'username' => mb_substr(trim($in['username'] ?? ''), 0, 255),
        'password' => mb_substr((string)($in['password'] ?? ''), 0, 255),
        'notes'    => trim($in['notes'] ?? ''),
    ];
    $errors = [];
    if (!is_valid_url($data['url'])) {
        $errors[] = 'Please enter a valid link URL.';
    }
    if ($data['title'] === '' && !$errors) {
        $data['title'] = fetch_page_title($data['url']) ?: $data['url'];
    }
    $data['title'] = mb_substr($data['title'], 0, 200);
    return [$data, $errors];
}

function create_link(int $websiteId, array $data): int
{
    db()->prepare('INSERT INTO links (website_id, title, url, username, password, notes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$websiteId, $data['title'], $data['url'], $data['username'] ?? '', $data['password'] ?? '', $data['notes']]);
    db()->prepare('UPDATE websites SET updated_at = NOW() WHERE id = ?')->execute([$websiteId]);
    return (int)db()->lastInsertId();
}

function update_link(int $id, array $data): void
{
    db()->prepare('UPDATE links SET title = ?, url = ?, username = ?, password = ?, notes = ? WHERE id = ?')
        ->execute([$data['title'], $data['url'], $data['username'] ?? '', $data['password'] ?? '', $data['notes'], $id]);
}

function delete_link(int $id): void
{
    db()->prepare('DELETE FROM links WHERE id = ?')->execute([$id]);
}
