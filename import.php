<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/import.php';

$user = require_login();
$websiteId = (int)($_GET['website_id'] ?? 0);
$website = $websiteId ? find_website($user['id'], $websiteId) : null;
if ($websiteId && !$website) {
    http_response_code(404);
    exit('Website not found.');
}
$back = $website ? 'website.php?id=' . $website['id'] : 'index.php';

if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="links-sample.csv"');
    echo "\xEF\xBB\xBF"; // lets Excel open it as UTF-8
    $out = fopen('php://output', 'w');
    foreach ([
        ['Website', 'Category', 'URL', 'Title', 'Notes'],
        ['GitHub', 'Work', 'https://github.com/login', 'Login', ''],
        ['GitHub', 'Work', 'https://github.com/notifications', 'Notifications', ''],
        ['', '', 'https://docs.python.org/3/library/', 'Python library docs', 'Website is created from the domain'],
    ] as $row) {
        fputcsv($out, $row);
    }
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = $file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'This file is too large.' : 'Please choose a file.';
    } else {
        try {
            $items = rows_to_items(read_spreadsheet($file['tmp_name'], $file['name']));
            $result = $items ? import_links($user['id'], $items, $website) : null;
            if (!$result) {
                $errors[] = 'No links were found in this file.';
            } elseif (!$result['added']) {
                $errors[] = import_summary($result);
            } else {
                flash(import_summary($result));
                $only = count($result['websites']) === 1 ? array_key_first($result['websites']) : 0;
                redirect($only ? 'website.php?id=' . $only : $back);
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

page_header('Import links', $user);
?>
<p><a href="<?= e($back) ?>">← <?= $website ? e($website['name']) : 'All websites' ?></a></p>

<div class="card narrow">
    <h1>Import links from Excel</h1>
    <?php show_errors($errors); ?>
    <?php if ($website): ?>
        <p>All links in the file are added to <strong><?= e($website['name']) ?></strong>.</p>
    <?php else: ?>
        <p>Each link is added to the website in its <strong>Website</strong> column (name or URL), or else to the
            website with the same domain. Missing websites are created.</p>
    <?php endif; ?>
    <p class="muted small">
        The first sheet of an .xlsx or .csv file is read. Give it a header row with columns such as
        <strong>URL</strong>, <strong>Title</strong>, <strong>Notes</strong><?= $website ? '' : ', <strong>Website</strong>, <strong>Category</strong>' ?>
        in any order. Cells with hyperlinks work too, and without a header row the cell that holds a web address is used.
        Links that are already saved are skipped.
        <a href="import.php?sample=1">Download a sample file</a>
    </p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label>Excel or CSV file
            <input type="file" name="file" required accept=".xlsx,.xlsm,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
        </label>
        <div class="row">
            <button class="btn btn-primary" type="submit">Import</button>
            <a class="btn" href="<?= e($back) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php page_footer();
