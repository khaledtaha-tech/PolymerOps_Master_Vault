<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Unified Authentication & Identity Gateway
 * 
 * Handles:
 * - Session status discovery & fast anonymous bootstrap
 * - Native password authentication (bcrypt/Argon2)
 * - Google Identity Services Web SSO verification
 * - Critical Vault PIN authorization (timed session elevation)
 * - User self-registration & administrative access control
 */

require_once __DIR__ . '/db.php';

$action = (string) ($_GET['action'] ?? 'session');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function critical_unlocked(): bool {
    return (int) ($_SESSION['critical_unlocked_until'] ?? 0) > time();
}

function registration_open(): bool {
    return filter_var(cfg('allow_registration', 'ALLOW_REGISTRATION', false), FILTER_VALIDATE_BOOLEAN);
}

function public_user(array $u): array {
    return [
        'id' => (int) $u['id'],
        'email' => (string) $u['email'],
        'role' => (string) ($u['role'] ?? 'USER'),
        'isActive' => (bool) ($u['is_active'] ?? 1),
        'hasPin' => !empty($u['pin_hash']),
        'createdAt' => $u['created_at'] ?? null,
    ];
}

function start_user_session(array $user): never {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['critical_unlocked_until']);
    json_response([
        'ok' => true,
        'authenticated' => true,
        'user' => public_user($user),
        'csrfToken' => $_SESSION['csrf_token'],
        'criticalUnlocked' => false,
    ]);
}

function verify_google_id_token(string $idToken): ?array {
    if (empty($idToken)) return null;
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => ['timeout' => 8]]);
        $raw = @file_get_contents($url, false, $context);
    }
    if (!$raw) return null;
    $payload = json_decode($raw, true);
    if (!is_array($payload)) return null;

    $expectedClientId = trim((string) cfg('google_client_id', 'GOOGLE_CLIENT_ID', ''));
    if ($expectedClientId !== '' && !hash_equals($expectedClientId, (string) ($payload['aud'] ?? ''))) {
        return null;
    }
    if (!in_array(strtolower((string) ($payload['email_verified'] ?? '')), ['true', '1'], true)) {
        return null;
    }
    if ((int) ($payload['exp'] ?? 0) <= time()) {
        return null;
    }
    return $payload;
}

try {
    // 1. Fast Public Config Discovery (Zero DB overhead)
    if ($action === 'config') {
        require_method('GET');
        json_response([
            'ok' => true,
            'dbAvailable' => db_available(),
            'googleClientId' => trim((string) cfg('google_client_id', 'GOOGLE_CLIENT_ID', '')),
            'registrationOpen' => registration_open(),
        ]);
    }

    // 2. Active Session Inspection
    if ($action === 'session') {
        require_method('GET');
        $userId = current_user_id();
        $isDbUp = db_available();

        if ($userId === null || !$isDbUp) {
            json_response([
                'ok' => true,
                'authenticated' => false,
                'user' => null,
                'csrfToken' => csrf_token(),
                'criticalUnlocked' => false,
                'dbAvailable' => $isDbUp,
            ]);
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, email, role, is_active, pin_hash, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['is_active']) {
            unset($_SESSION['user_id'], $_SESSION['critical_unlocked_until']);
            json_response([
                'ok' => true,
                'authenticated' => false,
                'user' => null,
                'csrfToken' => csrf_token(),
                'criticalUnlocked' => false,
                'dbAvailable' => true,
            ]);
        }

        json_response([
            'ok' => true,
            'authenticated' => true,
            'user' => public_user($user),
            'csrfToken' => csrf_token(),
            'criticalUnlocked' => critical_unlocked(),
            'dbAvailable' => true,
        ]);
    }

    // 3. User Self-Registration
    if ($action === 'register') {
        require_method('POST');
        if (!registration_open()) {
            json_response(['ok' => false, 'error' => 'Registration is currently disabled.'], 403);
        }
        $pdo = db(true);
        $data = read_json_body();
        $email = clean_email((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Valid email address required.'], 422);
        }
        if (strlen($password) < 10) {
            json_response(['ok' => false, 'error' => 'Password must contain at least 10 characters.'], 422);
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            json_response(['ok' => false, 'error' => 'An account with this email already exists.'], 409);
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $role = ($count === 0) ? 'ADMIN' : 'USER';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insert = $pdo->prepare('INSERT INTO users (email, role, is_active, password_hash) VALUES (?, ?, 1, ?)');
        $insert->execute([$email, $role, $hash]);
        $newId = (int) $pdo->lastInsertId();

        $fetch = $pdo->prepare('SELECT id, email, role, is_active, pin_hash, created_at FROM users WHERE id = ?');
        $fetch->execute([$newId]);
        start_user_session($fetch->fetch());
    }

    // 4. Standard Email/Password Authentication
    if ($action === 'login') {
        require_method('POST');
        $pdo = db(true);
        $data = read_json_body();
        $email = clean_email((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            json_response(['ok' => false, 'error' => 'Email and password are required.'], 422);
        }

        $stmt = $pdo->prepare('SELECT id, email, role, is_active, password_hash, pin_hash, created_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            json_response(['ok' => false, 'error' => 'Invalid email or password.'], 401);
        }
        if (!(bool) ($user['is_active'] ?? 1)) {
            json_response(['ok' => false, 'error' => 'Account access has been deactivated.'], 403);
        }

        start_user_session($user);
    }

    // 5. Google Identity Services Web SSO
    if ($action === 'google_login') {
        require_method('POST');
        $pdo = db(true);
        $data = read_json_body();
        $credential = (string) ($data['credential'] ?? '');
        $googleUser = verify_google_id_token($credential);

        if (!$googleUser) {
            json_response(['ok' => false, 'error' => 'Google authentication verification failed.'], 401);
        }

        $email = clean_email((string) $googleUser['email']);
        $sub = (string) $googleUser['sub'];

        $stmt = $pdo->prepare('SELECT id, email, role, is_active, pin_hash, google_sub, created_at FROM users WHERE google_sub = ? OR email = ? LIMIT 1');
        $stmt->execute([$sub, $email]);
        $user = $stmt->fetch();

        if (!$user) {
            if (!registration_open()) {
                json_response(['ok' => false, 'error' => 'No vault account exists for this Google identity.'], 403);
            }
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $role = ($count === 0) ? 'ADMIN' : 'USER';
            $randomPass = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (email, role, is_active, password_hash, google_sub) VALUES (?, ?, 1, ?, ?)');
            $ins->execute([$email, $role, $randomPass, $sub]);
            $newId = (int) $pdo->lastInsertId();
            $stmt->execute([$sub, $email]);
            $user = $stmt->fetch();
        } else {
            if (empty($user['google_sub'])) {
                $up = $pdo->prepare('UPDATE users SET google_sub = ? WHERE id = ?');
                $up->execute([$sub, $user['id']]);
            }
        }

        if (!(bool) ($user['is_active'] ?? 1)) {
            json_response(['ok' => false, 'error' => 'Account access has been deactivated.'], 403);
        }

        start_user_session($user);
    }

    // 6. Logout
    if ($action === 'logout') {
        require_method('POST', 'GET');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        json_response(['ok' => true, 'authenticated' => false]);
    }

    // 7. Critical Vault PIN Authorization (Session Elevation)
    if ($action === 'verify_pin') {
        require_method('POST');
        require_csrf();
        $userId = require_auth();
        $pdo = db(true);
        $data = read_json_body();
        $pin = trim((string) ($data['pin'] ?? ''));

        $stmt = $pdo->prepare('SELECT pin_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $storedHash = (string) $stmt->fetchColumn();

        if ($storedHash === '') {
            json_response(['ok' => false, 'error' => 'No Master PIN has been set for this account yet.'], 400);
        }

        if (!password_verify($pin, $storedHash)) {
            json_response(['ok' => false, 'error' => 'Incorrect Master PIN.'], 401);
        }

        // Elevate session for 15 minutes (900 seconds)
        $_SESSION['critical_unlocked_until'] = time() + 900;
        json_response([
            'ok' => true,
            'criticalUnlocked' => true,
            'expiresAt' => gmdate('c', $_SESSION['critical_unlocked_until']),
        ]);
    }

    // 8. Lock Critical Vault Immediately
    if ($action === 'lock_critical') {
        require_method('POST');
        require_csrf();
        require_auth();
        unset($_SESSION['critical_unlocked_until']);
        json_response(['ok' => true, 'criticalUnlocked' => false]);
    }

    // 9. Master PIN Setup / Mutation
    if ($action === 'security') {
        require_method('POST');
        require_csrf();
        $userId = require_auth();
        $pdo = db(true);
        $data = read_json_body();
        $newPin = trim((string) ($data['pin'] ?? ''));

        if ($newPin !== '' && (!ctype_digit($newPin) || strlen($newPin) < 4 || strlen($newPin) > 8)) {
            json_response(['ok' => false, 'error' => 'Master PIN must be 4 to 8 numeric digits.'], 422);
        }

        $hash = ($newPin !== '') ? password_hash($newPin, PASSWORD_DEFAULT) : null;
        $stmt = $pdo->prepare('UPDATE users SET pin_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $userId]);

        json_response(['ok' => true, 'hasPin' => ($hash !== null)]);
    }

    json_response(['ok' => false, 'error' => 'Unrecognized authentication action.', 'code' => 'INVALID_ACTION'], 404);
} catch (Throwable $e) {
    error_log('PolymerOps Auth Gateway Error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'An unexpected server error occurred.'], 500);
}
