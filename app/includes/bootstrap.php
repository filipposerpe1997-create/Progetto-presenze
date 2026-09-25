<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

const APP_NAME = 'Presenze';
const APP_VERSION = '1.0.0';
define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    header('Location: install.php');
    exit;
}
$CONFIG = require $configFile;

require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/layout.php';

start_session();
send_security_headers();

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Sessioni lunghe: sul telefono l'app resta loggata per 30 giorni.
    $lifetime = 60 * 60 * 24 * 30;
    $dir = APP_ROOT . '/data/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    session_name('presenze_sid');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function base_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir === '.' ? '' : $dir;
}

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $c = $CONFIG['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Sessione scaduta: ricarica la pagina e riprova.');
    }
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, username, nome FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function default_settings(): array
{
    return [
        'ore_1' => 480, 'ore_2' => 480, 'ore_3' => 480, 'ore_4' => 480, 'ore_5' => 480,
        'ore_6' => 0, 'ore_7' => 0,
        'pausa_default' => 60,
        'soglia_straord' => 0,
        'arrotondamento' => 0,
        'paga_oraria' => 0.0,
        'magg_straord' => 25.0,
        'magg_festivo' => 50.0,
        'patrono' => '',
        'azienda' => '',
    ];
}

function get_settings(int $uid): array
{
    $st = db()->prepare('SELECT * FROM settings WHERE user_id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    $s = default_settings();
    if ($row) {
        foreach ($s as $k => $v) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $s[$k] = is_float($v) ? (float)$row[$k] : (is_int($v) ? (int)$row[$k] : (string)$row[$k]);
            }
        }
    }
    return $s;
}

function save_settings(int $uid, array $s): void
{
    $cols = array_keys(default_settings());
    $sql = 'INSERT INTO settings (user_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ') '
        . 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));
    $vals = [$uid];
    foreach ($cols as $c) {
        $vals[] = $s[$c];
    }
    db()->prepare($sql)->execute($vals);
}

function get_presenza(int $uid, string $date): ?array
{
    $st = db()->prepare('SELECT * FROM presenze WHERE user_id = ? AND data = ?');
    $st->execute([$uid, $date]);
    return $st->fetch() ?: null;
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function valid_month(string $m): bool
{
    $dt = DateTime::createFromFormat('!Y-m', $m);
    return $dt !== false && $dt->format('Y-m') === $m;
}
