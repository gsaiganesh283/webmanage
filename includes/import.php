<?php
// Importing important links from an Excel (.xlsx) or CSV file.
// .xlsx files are zip archives of XML; they are read here without needing PHP's zip extension.

const XLSX_NS     = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const PKG_REL_NS  = 'http://schemas.openxmlformats.org/package/2006/relationships';

// Header words → field. Checked in this order, so "Website URL" is a website and "Link title" a title.
const IMPORT_HEADERS = [
    ['category', ['categor', 'group', 'folder', 'tag', 'type']],
    ['notes',    ['note', 'descr', 'comment', 'remark', 'detail']],
    ['website',  ['website', 'site', 'domain']],
    ['url',      ['url', 'href', 'address']],
    ['title',    ['title', 'name', 'label']],
    ['url',      ['link', 'page']],
];

// ---------- Reading the file ----------

/**
 * Reads the first sheet. Returns [row number => [column index => ['text' => …, 'link' => hyperlink or '']]].
 * Throws RuntimeException with a message for the user.
 */
function read_spreadsheet(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xlsm') {
        return read_xlsx($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return read_csv($path);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('Old .xls files are not supported. In Excel, use File → Save As → "Excel Workbook (*.xlsx)" or "CSV".');
    }
    throw new RuntimeException('Please choose an Excel (.xlsx) or CSV file.');
}

function read_csv(string $path): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($path));
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252'); // CSV saved by Excel on Windows
    }
    // Excel uses ";" instead of "," in some countries
    $first = explode("\n", $text, 2)[0];
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    $delimiter = array_search(max($counts), $counts);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rows[count($rows) + 1] = array_map(fn($v) => ['text' => trim((string)$v), 'link' => ''], $r);
    }
    fclose($fh);
    return $rows;
}

function read_xlsx(string $path): array
{
    $zip = (string)file_get_contents($path);
    $files = xlsx_zip_index($zip);
    $xml = function (string $name) use ($zip, $files): ?SimpleXMLElement {
        $data = isset($files[$name]) ? xlsx_zip_file($zip, $files[$name]) : null;
        return $data === null ? null : (simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NONET) ?: null);
    };
    libxml_use_internal_errors(true);

    $workbook = $xml('xl/workbook.xml');
    if (!$workbook) {
        throw new RuntimeException('This file could not be read as an Excel workbook.');
    }

    // The first sheet's file, found through the workbook's relationships
    $sheet = $workbook->children(XLSX_NS)->sheets->sheet[0];
    $rid = $sheet ? (string)$sheet->attributes(XLSX_REL_NS)['id'] : '';
    $sheetPath = rel_targets($xml('xl/_rels/workbook.xml.rels'), 'xl/')[$rid] ?? 'xl/worksheets/sheet1.xml';
    $ws = $xml($sheetPath);
    if (!$ws) {
        throw new RuntimeException('The first sheet of this workbook could not be read.');
    }
    $ws = $ws->children(XLSX_NS);

    $strings = [];
    $sst = $xml('xl/sharedStrings.xml');
    foreach ($sst ? $sst->children(XLSX_NS)->si : [] as $si) {
        $strings[] = xlsx_text($si);
    }

    // Hyperlinks (Insert → Link) are stored apart from the cell values
    $links = [];
    $sheetRels = rel_targets($xml(dirname($sheetPath) . '/_rels/' . basename($sheetPath) . '.rels'), dirname($sheetPath) . '/');
    foreach ($ws->hyperlinks->hyperlink as $h) {
        $target = $sheetRels[(string)$h->attributes(XLSX_REL_NS)['id']] ?? '';
        if ($target !== '') {
            $links[explode(':', (string)$h->attributes()['ref'])[0]] = $target;
        }
    }

    $rows = [];
    foreach ($ws->sheetData->row as $row) {
        $cells = [];
        $col = 0;
        foreach ($row->c as $c) {
            $ref = (string)$c->attributes()['r'];
            $col = $ref !== '' ? column_index($ref) : $col;
            $v = (string)$c->v;
            switch ((string)$c->attributes()['t']) {
                case 's':         $text = $strings[(int)$v] ?? ''; break;
                case 'inlineStr': $text = xlsx_text($c->is); break;
                case 'b':         $text = $v ? 'TRUE' : 'FALSE'; break;
                default:          $text = $v;
            }
            $link = $links[$ref] ?? '';
            if ($link === '' && preg_match('/HYPERLINK\(\s*"([^"]+)"/i', (string)$c->f, $m)) {
                $link = $m[1]; // =HYPERLINK("url", "text")
            }
            $cells[$col++] = ['text' => trim($text), 'link' => $link];
        }
        $rows[(int)$row->attributes()['r'] ?: count($rows) + 1] = $cells;
    }
    return $rows;
}

/** Text of a shared or inline string (plain, or rich text made of runs). */
function xlsx_text(SimpleXMLElement $el): string
{
    $el = $el->children(XLSX_NS);
    if (isset($el->t)) {
        return (string)$el->t;
    }
    $text = '';
    foreach ($el->r as $run) {
        $text .= (string)$run->children(XLSX_NS)->t;
    }
    return $text;
}

/** Relationship id => target (a path inside the zip, or an external URL). */
function rel_targets(?SimpleXMLElement $rels, string $dir): array
{
    $out = [];
    foreach ($rels ? $rels->children(PKG_REL_NS)->Relationship : [] as $r) {
        $target = (string)$r->attributes()['Target'];
        if ((string)$r->attributes()['TargetMode'] !== 'External') {
            $target = str_starts_with($target, '/') ? substr($target, 1) : $dir . $target;
        }
        $out[(string)$r->attributes()['Id']] = $target;
    }
    return $out;
}

/** "AB12" → 27 (zero-based column). */
function column_index(string $ref): int
{
    $n = 0;
    foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper($ref))) as $ch) {
        $n = $n * 26 + ord($ch) - 64;
    }
    return max(0, $n - 1);
}

/** A zip archive's files: name => [method, csize, offset of the local header]. */
function xlsx_zip_index(string $zip): array
{
    $end = strrpos($zip, "PK\x05\x06");
    if ($end === false) {
        return [];
    }
    $e = unpack('vcount/Vsize/Voffset', substr($zip, $end + 10, 10));
    $files = [];
    $pos = $e['offset'];
    for ($i = 0; $i < $e['count'] && substr($zip, $pos, 4) === "PK\x01\x02"; $i++) {
        $h = unpack('vmethod/x8/Vcsize/x4/vnlen/vxlen/vclen/x8/Voffset', substr($zip, $pos + 10, 36));
        $files[str_replace('\\', '/', substr($zip, $pos + 46, $h['nlen']))] = $h;
        $pos += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
    }
    return $files;
}

function xlsx_zip_file(string $zip, array $entry): ?string
{
    $local = unpack('vnlen/vxlen', substr($zip, $entry['offset'] + 26, 4));
    $data = substr($zip, $entry['offset'] + 30 + $local['nlen'] + $local['xlen'], $entry['csize']);
    if ($entry['method'] === 0) {
        return $data;
    }
    if ($entry['method'] === 8) {
        $out = @gzinflate($data);
        return $out === false ? null : $out;
    }
    return null;
}

// ---------- Rows → links ----------

function header_field(string $header): ?string
{
    $h = preg_replace('/[^a-z]/', '', strtolower($header));
    foreach ($h === '' ? [] : IMPORT_HEADERS as [$field, $words]) {
        foreach ($words as $w) {
            if (str_contains($h, $w)) {
                return $field;
            }
        }
    }
    return null;
}

function looks_like_url(string $s): bool
{
    return (bool)preg_match('~^(https?://\S+|www\.\S+|([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?(/\S*)?)$~i', $s);
}

/**
 * Turns sheet rows into links: [['row', 'url', 'title', 'notes', 'website', 'category']].
 * With a header row the columns are found by name; without one, the link is the first cell
 * holding a hyperlink or web address and the other cells become the title and notes.
 */
function rows_to_items(array $rows): array
{
    $columns = [];
    $headerHasUrl = false;
    foreach (reset($rows) ?: [] as $col => $cell) {
        $headerHasUrl = $headerHasUrl || $cell['link'] !== '' || looks_like_url($cell['text']);
        $field = header_field($cell['text']);
        if ($field && !isset($columns[$field])) {
            $columns[$field] = $col;
        }
    }
    if (!$headerHasUrl && array_intersect_key($columns, ['url' => 1, 'title' => 1, 'website' => 1])) {
        unset($rows[array_key_first($rows)]);
    } else {
        $columns = [];
    }

    $items = [];
    foreach ($rows as $n => $cells) {
        $cells = array_filter($cells, fn($c) => $c['text'] !== '' || $c['link'] !== '');
        if (!$cells) {
            continue;
        }
        $get = fn(string $field) => isset($columns[$field]) ? ($cells[$columns[$field]]['text'] ?? '') : '';

        $urlCol = $columns['url'] ?? null;
        if ($urlCol === null) {
            foreach ($cells as $col => $c) {
                if ($c['link'] !== '' || looks_like_url($c['text'])) {
                    $urlCol = $col;
                    break;
                }
            }
        }
        $cell = $cells[$urlCol] ?? ['text' => '', 'link' => ''];
        $item = [
            'row'      => $n,
            'url'      => $cell['link'] !== '' ? $cell['link'] : $cell['text'],
            'title'    => $get('title'),
            'notes'    => $get('notes'),
            'website'  => $get('website'),
            'category' => $get('category'),
        ];
        // A hyperlink's display text ("Login") makes a good title
        if ($item['title'] === '' && $cell['link'] !== '' && $cell['text'] !== $cell['link']) {
            $item['title'] = $cell['text'];
        }
        if (!$columns) {
            $others = array_values(array_filter(array_column(array_diff_key($cells, [$urlCol => 1]), 'text'), fn($t) => !looks_like_url($t)));
            $item['title'] = $item['title'] !== '' ? $item['title'] : (string)array_shift($others);
            $item['notes'] = implode(' · ', $others);
        }
        $items[] = $item;
    }
    return $items;
}

// ---------- Saving ----------

/** "https://example.com/" for any URL on that site. */
function site_root(string $url): string
{
    $p = parse_url($url);
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/';
}

/**
 * Saves the links. With $website, all of them go into that website. Otherwise each goes into the
 * website named in its row (by name or URL), or else the one with the link's domain; missing
 * websites are created. Links the website already has are skipped. Page titles are not fetched
 * (that would take minutes for a long list), so links without a title are named after their URL.
 */
function import_links(int $userId, array $items, ?array $website): array
{
    $result = ['added' => 0, 'duplicates' => 0, 'invalid' => [], 'websites' => [], 'new_websites' => 0];
    $byHost = $byName = $saved = [];
    foreach ($website ? [] : list_websites($userId) as $s) {
        $byHost[url_host($s['url'])] ??= $s['id'];
        $byName[mb_strtolower($s['name'])] ??= $s['id'];
    }

    db()->beginTransaction();
    try {
        foreach ($items as $it) {
            $url = normalize_url($it['url']);
            if (!looks_like_url($it['url']) || !is_valid_url($url) || strlen($url) > 2048) {
                $result['invalid'][] = $it['row'];
                continue;
            }

            if ($website) {
                $siteId = $website['id'];
            } else {
                $label = $it['website'];
                $siteUrl = looks_like_url($label) ? normalize_url($label) : '';
                if ($label === '' || is_valid_url($siteUrl)) {
                    $siteUrl = $siteUrl ?: site_root($url);
                    $name = url_host($siteUrl);
                    $siteId = $byHost[$name] ?? null;
                } else {
                    $siteUrl = site_root($url);
                    $name = $label;
                    $siteId = $byName[mb_strtolower($label)] ?? null;
                }
                if (!$siteId) {
                    $siteId = create_website($userId, [
                        'name'        => mb_substr($name, 0, 200),
                        'url'         => $siteUrl,
                        'category'    => mb_substr($it['category'], 0, 100),
                        'notes'       => '',
                        'is_favorite' => 0,
                    ]);
                    $byHost[url_host($siteUrl)] ??= $siteId;
                    $byName[mb_strtolower($name)] ??= $siteId;
                    $result['new_websites']++;
                }
            }

            if (!isset($saved[$siteId])) {
                $saved[$siteId] = array_fill_keys(array_map(fn($l) => rtrim($l['url'], '/'), list_links($siteId)), true);
            }
            if (isset($saved[$siteId][rtrim($url, '/')])) {
                $result['duplicates']++;
                continue;
            }
            $saved[$siteId][rtrim($url, '/')] = true;

            $title = $it['title'] !== '' ? $it['title'] : preg_replace('~^https?://(www\.)?~i', '', rtrim($url, '/'));
            create_link($siteId, ['title' => mb_substr($title, 0, 200), 'url' => $url, 'notes' => $it['notes']]);
            $result['added']++;
            $result['websites'][$siteId] = true;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $result;
}

function import_summary(array $r): string
{
    $plural = fn(int $n, string $word) => $n . ' ' . $word . ($n === 1 ? '' : 's');
    $msg = 'Imported ' . $plural($r['added'], 'link');
    if (count($r['websites']) > 1 || $r['new_websites']) {
        $msg .= ' into ' . $plural(count($r['websites']), 'website') . ($r['new_websites'] ? " ({$r['new_websites']} new)" : '');
    }
    $skipped = [];
    if ($r['duplicates']) {
        $skipped[] = $plural($r['duplicates'], 'link') . ' already saved';
    }
    if ($r['invalid']) {
        $rows = array_slice($r['invalid'], 0, 10);
        $skipped[] = $plural(count($r['invalid']), 'row') . ' without a valid link (row ' . implode(', ', $rows) . (count($r['invalid']) > 10 ? ', …' : '') . ')';
    }
    return $msg . '.' . ($skipped ? ' Skipped ' . implode(' and ', $skipped) . '.' : '');
}
