<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Database & Cryptographic Engine
 * 
 * Provides:
 * - Graceful Database Connection (PDO MySQL with degradation fallback)
 * - Authenticated OpenSSL AES-256-GCM Encryption / Decryption primitives
 * - Hardened PHP Session Management
 * - CSRF Token lifecycle & validation
 * - Standardized JSON API responses
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// Load Configuration with Graceful Fallback
$configFile = __DIR__ . '/config.php';
$exampleConfigFile = __DIR__ . '/config.example.php';

if (file_exists($configFile)) {
    $config = require $configFile;
} elseif (file_exists($exampleConfigFile)) {
    $config = require $exampleConfigFile;
} else {
    $config = [];
}

/**
 * Retrieve configuration value with environment fallback
 */
function cfg(string $key, ?string $env = null, mixed $default = null): mixed {
    global $config;
    if (is_array($config) && array_key_exists($key, $config)) {
        return $config[$key];
    }
    $envName = $env ?? strtoupper($key);
    $value = getenv($envName);
    return ($value !== false && $value !== null) ? $value : $default;
}

/**
 * Check if the active connection is HTTPS
 */
function request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwarded !== '') {
        return str_contains(',' . str_replace(' ', '', $forwarded) . ',', ',https,');
    }
    return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

// Hardened Session Setup
$sessionName = (string) cfg('session_name', 'POLYMER_SESSION_NAME', 'polymerops_master_session');
session_name($sessionName);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => request_is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Terminate execution with JSON payload
 */
function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Safely parse incoming JSON request body
 */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Malformed JSON request body.'], 400);
    }
    return $data;
}

/**
 * Check if relational database is reachable without throwing fatal errors
 */
function db_available(): bool {
    return db(false) instanceof PDO;
}

/**
 * Obtain PDO Database instance with graceful degradation
 *
 * @param bool $throwOnError If true, returns 500 JSON on connection failure. If false, returns null.
 */
function db(bool $throwOnError = false): ?PDO {
    static $pdo = null;
    static $connectionFailed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($connectionFailed && !$throwOnError) {
        return null;
    }

    $host = (string) cfg('db_host', 'POLYMER_DB_HOST', 'localhost');
    $name = (string) cfg('db_name', 'POLYMER_DB_NAME', '');
    $user = (string) cfg('db_user', 'POLYMER_DB_USER', '');
    $pass = (string) cfg('db_pass', 'POLYMER_DB_PASS', '');
    $port = (int) cfg('db_port', 'POLYMER_DB_PORT', 3306);
    $charset = (string) cfg('db_charset', 'POLYMER_DB_CHARSET', 'utf8mb4');

    if ($name === '' || $name === 'YOUR_DATABASE_NAME') {
        $connectionFailed = true;
        if ($throwOnError) {
            json_response(['ok' => false, 'error' => 'Database configuration is incomplete.', 'code' => 'DB_UNCONFIGURED'], 500);
        }
        return null;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        $connectionFailed = true;
        error_log('PolymerOps Database connection error: ' . $e->getMessage());
        if ($throwOnError) {
            json_response(['ok' => false, 'error' => 'Database connection failed.', 'code' => 'DB_OFFLINE'], 500);
        }
        return null;
    }
}

/**
 * Derive 256-bit binary cryptographic key from application configuration
 */
function app_key(): string {
    $raw = (string) cfg('app_key', 'POLYMER_APP_KEY', '');
    if (strlen($raw) < 32 || str_starts_with($raw, 'CHANGE_TO_')) {
        json_response([
            'ok' => false,
            'error' => 'Application encryption key is missing or insecure. Minimum 32 characters required in config.php.',
            'code' => 'INSECURE_APP_KEY'
        ], 500);
    }
    return hash('sha256', $raw, true);
}

/**
 * Encrypt plaintext using Authenticated OpenSSL AES-256-GCM
 *
 * @param string|null $plain Plaintext string to encrypt
 * @return string|null 'v1:' prefixed base64-encoded binary payload (IV + Tag + Ciphertext)
 */
function vault_encrypt(?string $plain): ?string {
    if ($plain === null || $plain === '') {
        return $plain;
    }
    $iv = random_bytes(12); // Standard 96-bit GCM IV
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('AES-256-GCM encryption failed.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt authenticated AES-256-GCM payload
 *
 * @param string|null $payload Encrypted string with 'v1:' prefix
 * @return string|null Decrypted plaintext or null/empty
 */
function vault_decrypt(?string $payload): ?string {
    if ($payload === null || $payload === '') {
        return $payload;
    }
    if (!str_starts_with($payload, 'v1:')) {
        return $payload; // Unencrypted legacy fallback
    }
    $raw = base64_decode(substr($payload, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return ($plain === false) ? '' : $plain;
}

/**
 * Retrieve active user ID from session or null
 */
function current_user_id(): ?int {
    $id = $_SESSION['user_id'] ?? null;
    if ($id !== null && (is_int($id) || ctype_digit((string) $id))) {
        $intId = (int) $id;
        return $intId > 0 ? $intId : null;
    }
    return null;
}

/**
 * Enforce active session authentication or send 401 JSON
 */
function require_auth(): int {
    $id = current_user_id();
    if ($id === null) {
        json_response(['ok' => false, 'error' => 'Authentication required.', 'code' => 'AUTH_REQUIRED'], 401);
    }
    return $id;
}

/**
 * Retrieve or generate CSRF token for the active session
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/**
 * Require valid CSRF token header for mutating HTTP verbs
 */
function require_csrf(): void {
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || $provided === '' || !hash_equals((string) $expected, (string) $provided)) {
        json_response(['ok' => false, 'error' => 'Invalid or missing CSRF token.', 'code' => 'INVALID_CSRF'], 403);
    }
}

/**
 * Generate RFC 4122 compliant UUID v4 string
 */
function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Restrict request to specified HTTP methods
 */
function require_method(string ...$allowed): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_response(['ok' => false, 'error' => 'HTTP method not allowed.', 'code' => 'METHOD_NOT_ALLOWED'], 405);
    }
    return $method;
}

/**
 * Normalize and sanitize email address
 */
function clean_email(string $email): string {
    $email = trim($email);
    return function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
}
