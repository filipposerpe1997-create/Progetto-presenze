<?php
declare(strict_types=1);

function asset(string $path): string
{
    $file = APP_ROOT . '/' . $path;
    $v = is_file($file) ? (string)filemtime($file) : APP_VERSION;
    return e($path . '?v=' . $v);
}

function icon(string $name): string
{
    $paths = [
        'oggi' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'mese' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'impostazioni' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'left' => '<path d="M15 18l-6-6 6-6"/>',
        'right' => '<path d="M9 18l6-6-6-6"/>',
        'xlsx' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13l4 5M12 13l-4 5"/>',
        'pdf' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 15h6M9 18h4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'back' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function page_header(string $title, string $active = '', ?string $back = null): void
{
    $user = current_user();
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
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<script src="<?= asset('assets/app.js') ?>" defer></script>
</head>
<body>
<header class="topbar">
  <?php if ($back): ?>
    <a class="topbar-back" href="<?= e($back) ?>" aria-label="Indietro"><?= icon('back') ?></a>
  <?php endif; ?>
  <h1><?= e($title) ?></h1>
  <?php if ($user): ?><span class="topbar-user"><?= e($user['nome'] ?: $user['username']) ?></span><?php endif; ?>
</header>
<main class="container">
<?php if ($f = flash()): ?>
  <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
<?php endif;
}

function page_footer(string $active = ''): void
{
    ?>
</main>
<?php if (current_user()): ?>
<nav class="tabbar">
  <a href="index.php" class="<?= $active === 'oggi' ? 'active' : '' ?>"><?= icon('oggi') ?><span>Oggi</span></a>
  <a href="mese.php" class="<?= $active === 'mese' ? 'active' : '' ?>"><?= icon('mese') ?><span>Mese</span></a>
  <a href="impostazioni.php" class="<?= $active === 'impostazioni' ? 'active' : '' ?>"><?= icon('impostazioni') ?><span>Impostazioni</span></a>
</nav>
<?php endif; ?>
</body>
</html>
<?php
}

function badge_tipo(?string $tipo): string
{
    if ($tipo === null) {
        return '';
    }
    return '<span class="badge badge-' . e($tipo) . '">' . e(TIPI[$tipo] ?? $tipo) . '</span>';
}
