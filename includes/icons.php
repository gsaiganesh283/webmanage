<?php
// Website logo discovery, caching and display.
//
// websites.icon column:  NULL = not looked up yet,  '' = site has no logo,  '<file>' = cached in /icons

const ICON_DIR = __DIR__ . '/../icons';
const ICON_MAX_BYTES = 512000;

/** Absolute URL from a (possibly relative) href found on the page at $base. */
function resolve_url(string $base, string $href): string
{
    $href = trim($href);
    if ($href === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
        return $href; // already absolute (http:, https:, data:, ...)
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($href, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        $path = $href;
    } else {
        $dir = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/');
        $path = $dir . $href;
    }
    // Collapse ./ and ../ segments (keep any query string as-is)
    [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($out);
        } elseif ($seg !== '.') {
            $out[] = $seg;
        }
    }
    $path = '/' . ltrim(implode('/', $out), '/');
    return $origin . $path . ($query !== null ? '?' . $query : '');
}

/** File extension for image bytes, or null if it isn't an image we can show. */
function image_ext(string $bytes): ?string
{
    if (str_starts_with($bytes, "\x89PNG")) {
        return 'png';
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'jpg';
    }
    if (str_starts_with($bytes, 'GIF8')) {
        return 'gif';
    }
    if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (str_starts_with($bytes, "\x00\x00\x01\x00")) {
        return 'ico';
    }
    if (preg_match('~<svg[\s>]~i', substr($bytes, 0, 2000))) {
        return 'svg';
    }
    return null;
}

/** Candidate logo URLs for a website, best first. */
function icon_candidates(string $siteUrl): array
{
    $page = http_get($siteUrl, 400000);
    $base = $page['url'] ?? $siteUrl;
    $apple = $png = $svg = $ico = [];

    if ($page && $page['body'] !== '') {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $page['body']);
        libxml_clear_errors();

        foreach ($doc->getElementsByTagName('base') as $b) {
            if ($b->getAttribute('href') !== '') {
                $base = resolve_url($base, $b->getAttribute('href'));
                break;
            }
        }
        foreach ($doc->getElementsByTagName('link') as $link) {
            $rel = ' ' . strtolower($link->getAttribute('rel')) . ' ';
            $href = $link->getAttribute('href');
            if ($href === '' || !str_contains($rel, 'icon') || str_contains($rel, 'mask-icon')) {
                continue;
            }
            $url = resolve_url($base, $href);
            $size = (int)$link->getAttribute('sizes'); // "192x192" -> 192
            $type = strtolower($link->getAttribute('type'));
            if (str_contains($rel, 'apple-touch-icon')) {
                $apple[$url] = $size ?: 180;
            } elseif (str_contains($type, 'svg') || preg_match('~\.svg(\?|$)~i', $url)) {
                $svg[$url] = $size;
            } elseif (str_contains($type, 'icon') || preg_match('~\.ico(\?|$)~i', $url)) {
                $ico[$url] = $size;
            } else {
                $png[$url] = $size;
            }
        }
    }
    arsort($apple);
    arsort($png);

    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');

    // PNG-type images first (they also display in the mobile app), then SVG/ICO.
    return array_values(array_unique(array_merge(
        array_keys($apple),
        array_keys($png),
        [$origin . '/apple-touch-icon.png'],
        ['google:' . $origin],
        array_keys($svg),
        array_keys($ico),
        [$origin . '/favicon.ico'],
    )));
}

/** Downloads one candidate. Returns [bytes, ext] or null. */
function download_icon(string $url): ?array
{
    if (preg_match('~^data:image/[^;,]+(;base64)?,(.*)$~is', $url, $m)) {
        $bytes = $m[1] ? base64_decode($m[2], true) : rawurldecode($m[2]);
    } else {
        $google = str_starts_with($url, 'google:');
        if ($google) {
            // Google answers 404 (with a generic globe) when it has no icon for the site.
            $url = 'https://www.google.com/s2/favicons?sz=128&domain_url=' . urlencode(substr($url, 7));
        }
        $res = http_get($url, ICON_MAX_BYTES);
        if (!$res || $res['status'] !== 200 || $res['truncated']) {
            return null;
        }
        $bytes = $res['body'];
    }
    if (!$bytes || strlen($bytes) < 50 || strlen($bytes) > ICON_MAX_BYTES) {
        return null;
    }
    $ext = image_ext($bytes);
    return $ext ? [$bytes, $ext] : null;
}

/** Finds the website's logo, caches it, and stores the result. Returns the new icon value. */
function discover_icon(array $website): string
{
    $icon = '';
    foreach (icon_candidates($website['url']) as $url) {
        $found = download_icon($url);
        if ($found) {
            [$bytes, $ext] = $found;
            if (!is_dir(ICON_DIR)) {
                mkdir(ICON_DIR, 0775, true);
            }
            $icon = $website['id'] . '-' . time() . '.' . $ext;
            file_put_contents(ICON_DIR . '/' . $icon, $bytes);
            break;
        }
    }
    db()->prepare('UPDATE websites SET icon = ? WHERE id = ?')->execute([$icon, $website['id']]);
    return $icon;
}

/** Deletes the cached logo and marks it to be looked up again. */
function reset_icon(int $websiteId): void
{
    $st = db()->prepare('SELECT icon FROM websites WHERE id = ?');
    $st->execute([$websiteId]);
    $old = (string)$st->fetchColumn();
    if ($old !== '' && is_file(ICON_DIR . '/' . basename($old))) {
        unlink(ICON_DIR . '/' . basename($old));
    }
    db()->prepare('UPDATE websites SET icon = NULL WHERE id = ?')->execute([$websiteId]);
}

/** First letter/digit of the name, for the fallback badge. */
function icon_letter(string $name): string
{
    return preg_match('/[\p{L}\p{N}]/u', $name, $m) ? mb_strtoupper($m[0]) : '?';
}

function icon_hue(string $name): int
{
    return crc32(mb_strtolower($name)) % 360;
}

/** <img> of the logo that falls back to the letter badge, or just the badge if the site has none. */
function site_icon_html(array $w, string $class = ''): string
{
    $badge = '<span class="avatar ' . $class . '" style="--hue:' . $w['icon_hue'] . '">' . e($w['icon_letter']) . '</span>';
    if (!$w['icon_url']) {
        return $badge;
    }
    return '<span class="icon-wrap">'
        . '<img class="favicon ' . $class . '" src="' . e($w['icon_url']) . '" alt="" loading="lazy"'
        . ' onerror="this.parentNode.innerHTML=this.nextElementSibling.innerHTML">'
        . '<template>' . $badge . '</template></span>';
}
