<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$id = (int)($_GET['id'] ?? 0);
$website = $id ? find_website($user['id'], $id) : null;
if ($id && !$website) {
    http_response_code(404);
    exit('Website not found.');
}

$errors = [];
$data = $website ?? ['name' => '', 'url' => '', 'category' => '', 'notes' => '', 'is_favorite' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$data, $errors] = validate_website($_POST);
    if (!$errors) {
        if ($website) {
            update_website($user['id'], $id, $data);
            flash('Website updated.');
        } else {
            $id = create_website($user['id'], $data);
            flash('Website saved. Now add its important links.');
        }
        redirect('website.php?id=' . $id);
    }
}

page_header($website ? 'Edit website' : 'Add website', $user);
?>
<div class="card narrow">
    <h1><?= $website ? 'Edit website' : 'Add website' ?></h1>
    <?php show_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Website URL *
            <input name="url" id="url" required placeholder="https://example.com" value="<?= e($data['url']) ?>" autofocus>
        </label>
        <label>Name <span class="muted small">(leave empty to use the page title)</span>
            <span class="row">
                <input name="name" id="name" value="<?= e($data['name']) ?>">
                <button type="button" class="btn" data-fetch-title="#url" data-target="#name">Fetch title</button>
            </span>
        </label>
        <label>Category
            <input name="category" list="cats" placeholder="e.g. Work, Banking, Learning" value="<?= e($data['category']) ?>">
            <datalist id="cats">
                <?php foreach (list_categories($user['id']) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
            </datalist>
        </label>
        <label>Notes <textarea name="notes" rows="3"><?= e($data['notes']) ?></textarea></label>
        <label class="check"><input type="checkbox" name="is_favorite" value="1" <?= $data['is_favorite'] ? 'checked' : '' ?>> ★ Mark as favorite</label>
        <div class="row">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn" href="<?= $website ? 'website.php?id=' . $id : 'index.php' ?>">Cancel</a>
        </div>
    </form>
</div>
<?php page_footer();
