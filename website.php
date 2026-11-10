<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$website = find_website($user['id'], (int)($_GET['id'] ?? 0));
if (!$website) {
    http_response_code(404);
    exit('Website not found.');
}
$links = list_links($website['id']);
$editId = (int)($_GET['edit'] ?? 0);

page_header($website['name'], $user);
?>
<p><a href="index.php">← All websites</a></p>

<div class="card">
    <div class="site-head">
        <?= site_icon_html($website, 'lg') ?>
        <div class="site-title">
            <h1><?= $website['is_favorite'] ? '<span class="star on">★</span> ' : '' ?><?= e($website['name']) ?></h1>
            <a href="<?= e($website['url']) ?>" target="_blank" rel="noopener"><?= e($website['url']) ?> ↗</a>
        </div>
    </div>
    <?php if ($website['category']): ?><span class="tag"><?= e($website['category']) ?></span><?php endif; ?>
    <?php if ($website['notes']): ?><p class="notes"><?= nl2br(e($website['notes'])) ?></p><?php endif; ?>
    <div class="row">
        <a class="btn" href="website_form.php?id=<?= $website['id'] ?>">Edit</a>
        <form method="post" action="website_action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $website['id'] ?>">
            <input type="hidden" name="action" value="favorite">
            <input type="hidden" name="back" value="website.php?id=<?= $website['id'] ?>">
            <button class="btn"><?= $website['is_favorite'] ? 'Unfavorite' : '★ Favorite' ?></button>
        </form>
        <form method="post" action="website_action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $website['id'] ?>">
            <input type="hidden" name="action" value="refresh_icon">
            <button class="btn" title="Download the website's logo again">↻ Refresh logo</button>
        </form>
        <form method="post" action="website_action.php" onsubmit="return confirm('Delete this website and all its links?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $website['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn btn-danger">Delete</button>
        </form>
    </div>
</div>

<h2>Important links (<?= count($links) ?>)</h2>

<?php if (!$links): ?>
    <p class="muted">No important links yet. Add pages you use often, like login, dashboard or docs.</p>
<?php endif; ?>

<ul class="links">
<?php foreach ($links as $l): ?>
    <li class="card">
        <?php if ($l['id'] === $editId): ?>
            <form method="post" action="link_action.php" class="link-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                <input type="hidden" name="website_id" value="<?= $website['id'] ?>">
                <input name="url" id="lurl<?= $l['id'] ?>" required value="<?= e($l['url']) ?>">
                <input name="title" placeholder="Title" value="<?= e($l['title']) ?>">
                <input name="username" placeholder="Username / ID (optional)" autocomplete="off" value="<?= e($l['username'] ?? '') ?>">
                <input name="password" placeholder="Password (optional)" autocomplete="off" value="<?= e($l['password'] ?? '') ?>">
                <input name="notes" placeholder="Notes (optional)" value="<?= e($l['notes']) ?>">
                <div class="row">
                    <button class="btn btn-primary">Save</button>
                    <a class="btn" href="website.php?id=<?= $website['id'] ?>">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <div class="link-row">
                <div class="link-main">
                    <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><strong><?= e($l['title']) ?></strong></a>
                    <div class="muted small ellipsis"><?= e($l['url']) ?></div>
                    <?php if (!empty($l['username'])): ?>
                        <div class="small cred">
                            <span class="muted">User:</span> <span class="mono"><?= e($l['username']) ?></span>
                            <button type="button" class="btn btn-xs" data-copy="<?= e($l['username']) ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($l['password'])): ?>
                        <div class="small cred">
                            <span class="muted">Pass:</span>
                            <span class="mono secret" data-secret="<?= e($l['password']) ?>">••••••••</span>
                            <button type="button" class="btn btn-xs" data-reveal>Show</button>
                            <button type="button" class="btn btn-xs" data-copy="<?= e($l['password']) ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                    <?php if ($l['notes']): ?><div class="small"><?= e($l['notes']) ?></div><?php endif; ?>
                </div>
                <div class="row nowrap">
                    <button class="btn btn-sm" data-copy="<?= e($l['url']) ?>">Copy</button>
                    <a class="btn btn-sm" href="website.php?id=<?= $website['id'] ?>&edit=<?= $l['id'] ?>">Edit</a>
                    <form method="post" action="link_action.php" onsubmit="return confirm('Delete this link?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $l['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>

<div class="card">
    <h3>Add important link</h3>
    <form method="post" action="link_action.php" class="link-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="website_id" value="<?= $website['id'] ?>">
        <input name="url" id="newurl" required placeholder="https://example.com/login">
        <span class="row">
            <input name="title" id="newtitle" placeholder="Title (empty = page title)">
            <button type="button" class="btn" data-fetch-title="#newurl" data-target="#newtitle">Fetch title</button>
        </span>
        <input name="username" placeholder="Username / ID (optional)" autocomplete="off">
        <input name="password" placeholder="Password (optional)" autocomplete="off">
        <input name="notes" placeholder="Notes (optional)">
        <button class="btn btn-primary">Add link</button>
    </form>
    <p class="small">Have a list? <a href="import.php?website_id=<?= $website['id'] ?>">Import links from Excel</a></p>
</div>
<?php page_footer();
