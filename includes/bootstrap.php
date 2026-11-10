<?php
// Works on PHP 7.4+ (common on shared hosting). PHP 8 string helpers for older versions:
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/icons.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Upgrade databases created before logos were added
        if (!$pdo->query("SHOW COLUMNS FROM websites LIKE 'icon'")->fetch()) {
            $pdo->exec('ALTER TABLE websites ADD COLUMN icon VARCHAR(100) NULL AFTER is_favorite');
        }
    }
    return $pdo;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

// ---------- Session / web auth ----------

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
    }
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return find_user((int)$_SESSION['user_id']);
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    start_session();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        // Usually an expired session or a page left open too long: show the page again with a fresh form.
        flash('Your session expired. Please try again.');
        redirect($_SERVER['REQUEST_METHOD'] === 'POST' ? $_SERVER['REQUEST_URI'] : 'index.php');
    }
}

function flash(?string $msg = null): ?string
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

// ---------- URL helpers ----------

function normalize_url(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function is_valid_url(string $url): bool
{
    return (bool)filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url);
}

function url_host(string $url): string
{
    return preg_replace('~^www\.~', '', (string)parse_url($url, PHP_URL_HOST));
}

const HTTP_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

/**
 * GET an http(s) URL, reading at most $maxBytes.
 * Returns ['status', 'body', 'url' (after redirects), 'truncated'] or null on failure.
 */
function http_get(string $url, int $maxBytes = 300000): ?array
{
    if (!preg_match('~^https?://~i', $url)) {
        return null;
    }
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => HTTP_USER_AGENT, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($body === false) {
            return null;
        }
        preg_match('~^HTTP/\S+\s+(\d+)~', end($http_response_header) ?: '', $m);
        return ['status' => (int)($m[1] ?? 0), 'body' => substr($body, 0, $maxBytes), 'url' => $url, 'truncated' => strlen($body) > $maxBytes];
    }

    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_TIMEOUT         => 10,
        CURLOPT_USERAGENT       => HTTP_USER_AGENT,
        CURLOPT_ENCODING        => '',
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;
            return strlen($body) > $maxBytes ? 0 : strlen($chunk); // returning 0 stops the download
        },
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if ($status === 0) {
        return null;
    }
    return ['status' => $status, 'body' => substr($body, 0, $maxBytes), 'url' => $finalUrl ?: $url, 'truncated' => strlen($body) > $maxBytes];
}

/** Token from an "Authorization: Bearer <token>" header (mobile app), or ''. */
function bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = $v;
            }
        }
    }
    return preg_match('/Bearer\s+(\S+)/i', $header, $m) ? $m[1] : '';
}

/** Download the start of a page and return its <title>, or '' on failure. */
function fetch_page_title(string $url): string
{
    $url = normalize_url($url);
    if (!is_valid_url($url)) {
        return '';
    }
    $html = http_get($url, 200000)['body'] ?? '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        return mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 200);
    }
    return '';
}
