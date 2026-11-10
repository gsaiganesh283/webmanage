<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$user, $err] = register_user($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($user) {
        login_user($user);
        flash('Welcome, ' . $user['name'] . '! Add your first website.');
        redirect('index.php');
    }
    $errors[] = $err;
}

page_header('Register');
?>
<div class="card narrow">
    <img src="assets/logo.svg" alt="" class="auth-logo">
    <h1>Create account</h1>
    <?php show_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Name <input name="name" required value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label>Email <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Password <input type="password" name="password" required minlength="6"></label>
        <button class="btn btn-primary" type="submit">Register</button>
    </form>
    <p class="muted">Already registered? <a href="login.php">Login</a></p>
</div>
<?php page_footer();
