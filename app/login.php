<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    redirect('install.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        redirect('index.php');
    }
    usleep(800000); // rallenta i tentativi a forza bruta
    $error = 'Username o password errati.';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1e3a8a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<title>Accedi · <?= e(APP_NAME) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<script src="<?= asset('assets/app.js') ?>" defer></script>
</head>
<body class="auth">
<main class="auth-box">
  <img src="assets/icons/icon-192.png" alt="" class="auth-logo" width="72" height="72">
  <h1><?= e(APP_NAME) ?></h1>
  <p class="muted">Gestione presenze e straordinari</p>
  <?php if (isset($_GET['installed'])): ?><div class="flash flash-ok">Account creato. Ora accedi.</div><?php endif; ?>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Username <input name="username" required autocapitalize="none" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block">Accedi</button>
  </form>
</main>
</body>
</html>
