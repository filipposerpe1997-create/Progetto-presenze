<?php
declare(strict_types=1);

/**
 * Installazione guidata:
 *  1) se manca config.php chiede i dati MySQL, crea le tabelle e scrive config.php;
 *  2) se non esiste ancora un utente, crea l'account.
 * Una volta creato l'utente la pagina non fa più nulla.
 */

define('APP_ROOT', __DIR__);
$configFile = APP_ROOT . '/config.php';
$error = '';
$step = is_file($configFile) ? 'user' : 'db';

session_name('presenze_install');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function connect(array $c): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)$c['port'], $c['name']);
    return new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function create_schema(PDO $pdo): void
{
    $sql = file_get_contents(APP_ROOT . '/includes/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    $error = 'Sessione scaduta, riprova.';
    $post = false;
}

$configContent = '';
if ($step === 'db' && $post) {
    $c = [
        'host' => trim($_POST['host'] ?? 'localhost') ?: 'localhost',
        'port' => (int)($_POST['port'] ?? 3306) ?: 3306,
        'name' => trim($_POST['name'] ?? ''),
        'user' => trim($_POST['user'] ?? ''),
        'pass' => (string)($_POST['pass'] ?? ''),
    ];
    try {
        $pdo = connect($c);
        create_schema($pdo);
        $configContent = "<?php\nreturn " . var_export(['db' => $c], true) . ";\n";
        if (@file_put_contents($configFile, $configContent) !== false) {
            @chmod($configFile, 0640);
            header('Location: install.php');
            exit;
        }
        $error = 'Connessione riuscita e tabelle create, ma non posso scrivere config.php. '
            . 'Crea il file a mano con il contenuto qui sotto e ricarica la pagina.';
    } catch (PDOException $ex) {
        $error = 'Connessione al database non riuscita: ' . $ex->getMessage();
    }
}

if ($step === 'user') {
    $config = require $configFile;
    try {
        $pdo = connect($config['db']);
        create_schema($pdo);
        $hasUser = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (PDOException $ex) {
        $hasUser = false;
        $error = 'Errore database: ' . $ex->getMessage();
    }
    if ($hasUser) {
        header('Location: login.php');
        exit;
    }
    if ($post && !$error) {
        $username = trim($_POST['username'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $error = 'Username non valido (3-50 caratteri: lettere, numeri, . _ -).';
        } elseif (strlen($pass) < 8) {
            $error = 'La password deve avere almeno 8 caratteri.';
        } elseif ($pass !== ($_POST['password2'] ?? '')) {
            $error = 'Le password non coincidono.';
        } else {
            $pdo->prepare('INSERT INTO users (username, password_hash, nome) VALUES (?, ?, ?)')
                ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $nome]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO settings (user_id) VALUES (?)')->execute([$uid]);
            session_destroy();
            header('Location: login.php?installed=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1e3a8a">
<title>Installazione · Presenze</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth">
<main class="auth-box">
  <img src="assets/icons/icon-192.png" alt="" class="auth-logo" width="72" height="72">
  <h1>Installazione Presenze</h1>
  <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>

  <?php if ($step === 'db'): ?>
    <?php if ($configContent): ?>
      <pre class="code"><?= h($configContent) ?></pre>
    <?php endif; ?>
    <p class="muted">Passo 1 di 2 · Dati del database MySQL/MariaDB</p>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <label>Host <input name="host" value="<?= h($_POST['host'] ?? 'localhost') ?>" required></label>
      <label>Porta <input name="port" type="number" value="<?= h($_POST['port'] ?? '3306') ?>" required></label>
      <label>Nome database <input name="name" value="<?= h($_POST['name'] ?? 'presenze') ?>" required></label>
      <label>Utente <input name="user" value="<?= h($_POST['user'] ?? '') ?>" required autocomplete="off"></label>
      <label>Password <input name="pass" type="password" autocomplete="new-password"></label>
      <button class="btn btn-primary btn-block">Collega e crea tabelle</button>
    </form>
  <?php else: ?>
    <p class="muted">Passo 2 di 2 · Crea il tuo account</p>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <label>Nome e cognome <input name="nome" value="<?= h($_POST['nome'] ?? '') ?>" autocomplete="name"></label>
      <label>Username <input name="username" value="<?= h($_POST['username'] ?? '') ?>" required autocapitalize="none" autocomplete="username"></label>
      <label>Password (min. 8 caratteri) <input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
      <label>Ripeti password <input name="password2" type="password" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary btn-block">Crea account</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
