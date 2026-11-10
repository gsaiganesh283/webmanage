<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $user = authenticate($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($user) {
        login_user($user);
        redirect('index.php');
    }
    $errors[] = 'Wrong email or password.';
}

page_header('Login');
?>
<div class="card narrow">
    <img src="assets/logo.svg" alt="" class="auth-logo">
    <h1>Login</h1>
    <?php show_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Password <input type="password" name="password" required></label>
        <button class="btn btn-primary" type="submit">Login</button>
    </form>
    <p class="muted">No account? <a href="register.php">Register</a></p>
</div>
<?php page_footer();
