<?php

define('MIZ_ROOT', dirname(__DIR__));
define('MIZ_PANEL', __DIR__);
define('MIZ_DATA', MIZ_ROOT . '/data');
define('MIZ_DB', MIZ_DATA . '/data.db');
define('MIZ_QUEUE_LOG', MIZ_DATA . '/queue.log');
define('MIZ_TPL', MIZ_ROOT . '/tpl');
define('MIZ_BOOTSTRAPPED', true);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(MIZ_DATA) && !mkdir(MIZ_DATA, 0750, true) && !is_dir(MIZ_DATA)) {
        throw new RuntimeException('Cannot create data directory: ' . MIZ_DATA);
    }

    $pdo = new PDO('sqlite:' . MIZ_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function get_domain(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM domains WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function get_domains(): array
{
    return db()->query('SELECT * FROM domains ORDER BY id')->fetchAll();
}

function has_setting(string $key): bool
{
    $stmt = db()->prepare('SELECT 1 FROM settings WHERE "key" = :key LIMIT 1');
    $stmt->execute([':key' => $key]);

    return $stmt->fetchColumn() !== false;
}

function get_setting(string $key, $default = null)
{
    $stmt = db()->prepare('SELECT "value" FROM settings WHERE "key" = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();

    if ($row === false) {
        return $default;
    }

    return $row['value'];
}

function set_setting(string $key, $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings ("key", "value") VALUES (:key, :value)
         ON CONFLICT("key") DO UPDATE SET "value" = excluded."value"'
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => $value === null ? null : (string) $value,
    ]);
}

function touch_updated(): void
{
    set_setting('date_updated', sprintf('%.6F', microtime(true)));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        exit('CSRF token không hợp lệ.');
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['miz_authenticated']);
}

function verify_password(string $password): bool
{
    $stored = (string) get_setting('password', '');

    if ($stored === '') {
        return false;
    }

    return password_verify($password, $stored);
}

function normalize_domains(string $input): array
{
    $lines = preg_split('/\r\n|\r|\n/', $input) ?: [];
    $domains = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $line = strtolower($line);
        $domains[$line] = true;
    }

    return array_keys($domains);
}

function is_valid_domain(string $domain): bool
{
    $host = $domain;

    if (str_starts_with($host, '*.')) {
        $host = substr($host, 2);
    }

    if ($host === '' || strlen($host) > 253) {
        return false;
    }

    return preg_match(
        '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/',
        $host
    ) === 1;
}

function validate_domains(string $input): array
{
    $domains = normalize_domains($input);
    $errors = [];

    if ($domains === []) {
        $errors[] = 'Vui lòng nhập ít nhất một domain.';
    }

    foreach ($domains as $domain) {
        if (!is_valid_domain($domain)) {
            $errors[] = 'Domain không hợp lệ: ' . $domain;
        }
    }

    return [$domains, $errors];
}

function parse_backend(string $input): array
{
    $input = trim($input);

    if ($input !== '' && $input[0] === '/') {
        if (str_contains($input, '..') || preg_match('#^/[A-Za-z0-9._/-]*$#', $input) !== 1) {
            throw new InvalidArgumentException('Đường dẫn tĩnh không hợp lệ.');
        }

        return [
            'scheme' => 'http',
            'host' => '127.0.0.1',
            'port' => 18080,
            'path' => $input,
        ];
    }

    if (preg_match('#^(https?)://([a-z0-9.-]+):(\d{1,5})$#i', $input, $m)) {
        $port = (int) $m[3];

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port backend không hợp lệ.');
        }

        return [
            'scheme' => strtolower($m[1]),
            'host' => $m[2],
            'port' => $port,
            'path' => null,
        ];
    }

    throw new InvalidArgumentException('URL đích phải là /duong-dan hoặc http(s)://host:port.');
}

function validate_backend(string $input): ?string
{
    try {
        parse_backend($input);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

function keepalive_timeout_value(): string
{
    $value = trim((string) get_setting('client_keepalive_timeout', '60'));

    if (preg_match('/^\d+(ms|s|m|h|d)?$/', $value) !== 1) {
        $value = '60';
    }

    if (preg_match('/^\d+$/', $value)) {
        $value .= 's';
    }

    return $value;
}

// Bootstrap database schema and defaults.
db()->exec(
    'CREATE TABLE IF NOT EXISTS domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        "from" TEXT NOT NULL DEFAULT \'\',
        "to" TEXT NOT NULL DEFAULT \'\',
        status INTEGER NOT NULL DEFAULT 1
    )'
);

db()->exec(
    'CREATE TABLE IF NOT EXISTS settings (
        "key" TEXT PRIMARY KEY,
        "value" TEXT
    )'
);

if (!has_setting('client_keepalive_timeout')) {
    set_setting('client_keepalive_timeout', '60');
}

if (!has_setting('password')) {
    set_setting('password', password_hash('!!!123456789!!!', PASSWORD_DEFAULT));
}

if (!has_setting('date_updated')) {
    touch_updated();
}

if (PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name('miz_hproxy');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $https,
        ]);
        session_start();
    }

    if (!defined('MIZ_NO_AUTH') && !is_logged_in()) {
        redirect('login.php');
    }
}
