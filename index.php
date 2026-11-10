<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$filters = [
    'q'        => $_GET['q'] ?? '',
    'category' => $_GET['category'] ?? '',
    'favorite' => !empty($_GET['favorite']),
];
$websites = list_websites($user['id'], $filters);
$categories = list_categories($user['id']);

page_header('My websites', $user);
?>
<form class="filters" method="get">
    <input type="search" name="q" placeholder="Search websites and links…" value="<?= e($filters['q']) ?>">
    <select name="category" onchange="this.form.submit()">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
            <option <?= $c === $filters['category'] ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" name="favorite" value="1" <?= $filters['favorite'] ? 'checked' : '' ?> onchange="this.form.submit()"> ★ Favorites</label>
    <button class="btn" type="submit">Search</button>
</form>

<?php if (!$websites): ?>
    <div class="card empty">
        <?php if ($filters['q'] || $filters['category'] || $filters['favorite']): ?>
            No websites match your filters. <a href="index.php">Clear filters</a>
        <?php else: ?>
            You haven't saved any websites yet. <a href="website_form.php">Add your first website</a>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid">
<?php foreach ($websites as $w): ?>
    <div class="card site">
        <div class="site-head">
            <?= site_icon_html($w) ?>
            <div class="site-title">
                <a href="website.php?id=<?= $w['id'] ?>"><strong><?= e($w['name']) ?></strong></a>
                <a class="muted small" href="<?= e($w['url']) ?>" target="_blank" rel="noopener"><?= e(url_host($w['url'])) ?> ↗</a>
            </div>
            <form method="post" action="website_action.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $w['id'] ?>">
                <input type="hidden" name="action" value="favorite">
                <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                <button class="star <?= $w['is_favorite'] ? 'on' : '' ?>" title="Favorite">★</button>
            </form>
        </div>
        <?php if ($w['category']): ?><span class="tag"><?= e($w['category']) ?></span><?php endif; ?>
        <?php if ($w['notes']): ?><p class="notes"><?= e(mb_strimwidth($w['notes'], 0, 140, '…')) ?></p><?php endif; ?>
        <a class="small" href="website.php?id=<?= $w['id'] ?>"><?= $w['link_count'] ?> important link<?= $w['link_count'] === 1 ? '' : 's' ?> →</a>
    </div>
<?php endforeach; ?>
</div>
<?php page_footer();
