<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Unified Authentication & Identity Gateway
 * 
 * Handles:
 * - Session status discovery & fast anonymous bootstrap
 * - Native password authentication (bcrypt)
 * - Google Identity Services Web SSO & OAuth 2.0 callback flow
 * - Forgot password & cryptographically secure 1-hour reset token dispatch
 * - Hostinger SMTP transactional email integration
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

function mask_email(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $visible = substr($name, 0, min(2, strlen($name)));
    return $visible . '***@' . $domain;
}

function is_email_allowed(string $email): bool {
    $allowed = cfg('allowed_emails', 'ALLOWED_EMAILS', '');
    if (empty($allowed)) {
        return true;
    }
    $list = is_array($allowed) ? $allowed : array_filter(array_map('trim', explode(',', (string) $allowed)));
    if (empty($list)) {
        return true;
    }
    $clean = clean_email($email);
    foreach ($list as $pattern) {
        $p = clean_email($pattern);
        if ($p === $clean) return true;
        if (str_starts_with($p, '@') && str_ends_with($clean, $p)) return true;
    }
    return false;
}

function start_user_session(array $user, ?string $redirectUrl = null): never {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['critical_unlocked_until']);

    if ($redirectUrl !== null) {
        header('Location: ' . $redirectUrl);
        exit;
    }

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
    $payload = json_decode((string) $raw, true);
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

function exchange_google_auth_code(string $code): ?array {
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $clientId = trim((string) cfg('google_client_id', 'GOOGLE_CLIENT_ID', ''));
    $clientSecret = trim((string) cfg('google_client_secret', 'GOOGLE_CLIENT_SECRET', ''));
    $redirectUri = trim((string) cfg('google_redirect_uri', 'GOOGLE_REDIRECT_URI', 'https://master.polymerops.com/auth.php?action=google_callback'));

    if (empty($clientId)) return null;

    $postData = [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ];

    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if (!$raw) return null;
    $res = json_decode((string) $raw, true);
    if (!is_array($res) || empty($res['id_token'])) return null;

    return verify_google_id_token((string) $res['id_token']);
}

function send_smtp_email(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    $enabled = filter_var(cfg('smtp_enabled', 'SMTP_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    $host = (string) cfg('smtp_host', 'SMTP_HOST', 'smtp.hostinger.com');
    $port = (int) cfg('smtp_port', 'SMTP_PORT', 465);
    $secure = strtolower((string) cfg('smtp_secure', 'SMTP_SECURE', 'ssl'));
    $user = (string) cfg('smtp_user', 'SMTP_USER', '');
    $pass = (string) cfg('smtp_pass', 'SMTP_PASS', '');
    $from = (string) cfg('smtp_from', 'SMTP_FROM', 'noreply@polymerops.com');
    $fromName = (string) cfg('smtp_from_name', 'SMTP_FROM_NAME', 'PolymerOps Master Vault');

    if (!$enabled || empty($user) || empty($pass)) {
        error_log("PolymerOps SMTP Notice: SMTP credentials not set. Email dispatch to <{$to}> skipped.");
        return false;
    }

    try {
        $protocol = ($secure === 'ssl' || $port === 465) ? 'ssl://' : 'tcp://';
        $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            error_log("PolymerOps SMTP Connection Failed: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($socket, 15);

        $readResponse = function() use ($socket): string {
            $data = '';
            while ($line = fgets($socket, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };

        $sendCommand = function(string $cmd) use ($socket): void {
            fputs($socket, $cmd . "\r\n");
        };

        $greeting = $readResponse();
        if (!str_starts_with($greeting, '220')) {
            fclose($socket);
            return false;
        }

        $sendCommand("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $ehlo = $readResponse();

        if ($secure === 'tls' || ($port === 587 && str_contains($ehlo, 'STARTTLS'))) {
            $sendCommand("STARTTLS");
            $starttls = $readResponse();
            if (str_starts_with($starttls, '220')) {
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $sendCommand("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
                $readResponse();
            }
        }

        $sendCommand("AUTH LOGIN");
        $authResp = $readResponse();
        if (!str_starts_with($authResp, '334')) {
            fclose($socket);
            return false;
        }

        $sendCommand(base64_encode($user));
        $uResp = $readResponse();
        if (!str_starts_with($uResp, '334')) {
            fclose($socket);
            return false;
        }

        $sendCommand(base64_encode($pass));
        $pResp = $readResponse();
        if (!str_starts_with($pResp, '235')) {
            fclose($socket);
            return false;
        }

        $sendCommand("MAIL FROM: <{$from}>");
        $fromResp = $readResponse();
        if (!str_starts_with($fromResp, '250')) {
            fclose($socket);
            return false;
        }

        $sendCommand("RCPT TO: <{$to}>");
        $toResp = $readResponse();
        if (!str_starts_with($toResp, '250')) {
            fclose($socket);
            return false;
        }

        $sendCommand("DATA");
        $dataResp = $readResponse();
        if (!str_starts_with($dataResp, '354')) {
            fclose($socket);
            return false;
        }

        $boundary = 'b1_' . bin2hex(random_bytes(16));
        $headers = [
            "Date: " . date('r'),
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>",
            "To: <{$to}>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            "X-Mailer: PolymerOps Vault Gateway",
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n";
        
        // Plain text part
        if ($textBody !== '') {
            $payload .= "--{$boundary}\r\n";
            $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $payload .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $payload .= chunk_split(base64_encode($textBody)) . "\r\n";
        }

        // HTML part
        $payload .= "--{$boundary}\r\n";
        $payload .= "Content-Type: text/html; charset=UTF-8\r\n";
        $payload .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $payload .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $payload .= "--{$boundary}--\r\n.\r\n";

        fputs($socket, $payload);
        $finalResp = $readResponse();

        $sendCommand("QUIT");
        fclose($socket);

        return str_starts_with($finalResp, '250');
    } catch (Throwable $e) {
        error_log("PolymerOps SMTP Exception: " . $e->getMessage());
        return false;
    }
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

    // 5. Google OAuth 2.0 Direct Browser Redirect Initiator
    if ($action === 'google_redirect') {
        require_method('GET');
        $clientId = trim((string) cfg('google_client_id', 'GOOGLE_CLIENT_ID', ''));
        $redirectUri = trim((string) cfg('google_redirect_uri', 'GOOGLE_REDIRECT_URI', 'https://master.polymerops.com/auth.php?action=google_callback'));

        if (empty($clientId)) {
            header('Location: /index.php?auth_error=google_client_id_missing');
            exit;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2_state'] = $state;

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        header('Location: ' . $authUrl);
        exit;
    }

    // 6. Google Identity Services Web SSO & OAuth 2.0 Callback Flow
    if ($action === 'google_login' || $action === 'google_callback') {
        require_method('POST', 'GET');
        $isBrowser = ($method === 'GET' || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html'));
        $errorParam = (string) ($_GET['error'] ?? '');

        if ($errorParam !== '') {
            if ($isBrowser) {
                header('Location: /index.php?auth_error=' . urlencode($errorParam));
                exit;
            }
            json_response(['ok' => false, 'error' => 'Google authentication was cancelled or rejected.'], 400);
        }

        $googleUser = null;
        $code = (string) ($_GET['code'] ?? '');
        $data = ($method === 'POST') ? read_json_body() : [];
        $credential = (string) ($data['credential'] ?? ($_POST['credential'] ?? ($_GET['credential'] ?? '')));

        if (!empty($credential)) {
            $googleUser = verify_google_id_token($credential);
        } elseif (!empty($code)) {
            $googleUser = exchange_google_auth_code($code);
        } elseif (!empty($data['code'])) {
            $googleUser = exchange_google_auth_code((string) $data['code']);
        }

        if (!$googleUser) {
            if ($isBrowser) {
                header('Location: /index.php?auth_error=verification_failed');
                exit;
            }
            json_response(['ok' => false, 'error' => 'Google authentication verification failed.'], 401);
        }

        $email = clean_email((string) $googleUser['email']);
        $sub = (string) $googleUser['sub'];

        if (!is_email_allowed($email)) {
            if ($isBrowser) {
                header('Location: /index.php?auth_error=unauthorized_email');
                exit;
            }
            json_response(['ok' => false, 'error' => 'This Google identity is not authorized to access PolymerOps Master Vault.'], 403);
        }

        $pdo = db(true);
        $stmt = $pdo->prepare('SELECT id, email, role, is_active, pin_hash, google_sub, created_at FROM users WHERE google_sub = ? OR email = ? LIMIT 1');
        $stmt->execute([$sub, $email]);
        $user = $stmt->fetch();

        if (!$user) {
            if (!registration_open()) {
                if ($isBrowser) {
                    header('Location: /index.php?auth_error=user_not_found');
                    exit;
                }
                json_response(['ok' => false, 'error' => 'No vault account exists for this Google identity.'], 403);
            }
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $role = ($count === 0) ? 'ADMIN' : 'USER';
            $randomPass = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (email, role, is_active, password_hash, google_sub) VALUES (?, ?, 1, ?, ?)');
            $ins->execute([$email, $role, $randomPass, $sub]);
            $stmt->execute([$sub, $email]);
            $user = $stmt->fetch();
        } else {
            if (empty($user['google_sub'])) {
                $up = $pdo->prepare('UPDATE users SET google_sub = ? WHERE id = ?');
                $up->execute([$sub, $user['id']]);
            }
        }

        if (!(bool) ($user['is_active'] ?? 1)) {
            if ($isBrowser) {
                header('Location: /index.php?auth_error=deactivated');
                exit;
            }
            json_response(['ok' => false, 'error' => 'Account access has been deactivated.'], 403);
        }

        if ($isBrowser) {
            start_user_session($user, '/index.php');
        } else {
            start_user_session($user);
        }
    }

    // 7. Logout
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

    // 8. Critical Vault PIN Authorization (Session Elevation)
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

    // 9. Lock Critical Vault Immediately
    if ($action === 'lock_critical') {
        require_method('POST');
        require_csrf();
        require_auth();
        unset($_SESSION['critical_unlocked_until']);
        json_response(['ok' => true, 'criticalUnlocked' => false]);
    }

    // 10. Master PIN Setup / Mutation
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

    // 11. Forgot Password Request (Dispatches 1-Hour Recovery Email)
    if ($action === 'forgot_password') {
        require_method('POST');
        $pdo = db(true);
        $data = read_json_body();
        $email = clean_email((string) ($data['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Please provide a valid email address.'], 422);
        }

        $stmt = $pdo->prepare('SELECT id, email, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && (bool) ($user['is_active'] ?? 1)) {
            // Invalidate any existing unused reset tokens for this user
            $inv = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
            $inv->execute([$user['id']]);

            // Generate cryptographically secure 64-character token
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())');
            $ins->execute([$user['id'], $tokenHash]);

            $baseUrl = rtrim((string) cfg('app_url', 'APP_URL', 'https://master.polymerops.com'), '/');
            $resetUrl = "{$baseUrl}/reset.php?token={$rawToken}";

            $subject = 'PolymerOps Vault - Master Password Reset';
            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reset Vault Password</title>
</head>
<body style="background-color: #0d1117; color: #e6edf3; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; padding: 30px; margin: 0;">
  <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 540px; margin: 0 auto; background-color: #161b22; border: 1px solid #30363d; border-radius: 10px; overflow: hidden;">
    <tr>
      <td style="padding: 24px 30px; background-color: #0d1117; border-bottom: 1px solid #30363d;">
        <h2 style="margin: 0; color: #58a6ff; font-size: 18px; font-weight: 700; letter-spacing: -0.02em;">PolymerOps Master Vault</h2>
        <p style="margin: 4px 0 0; color: #8b949e; font-size: 12px;">Enterprise DevOps & Credential Platform</p>
      </td>
    </tr>
    <tr>
      <td style="padding: 30px;">
        <h3 style="margin-top: 0; color: #e6edf3; font-size: 16px;">Password Reset Request</h3>
        <p style="color: #8b949e; font-size: 13px; line-height: 1.6;">
          We received a request to reset the password for your account (<strong>{$email}</strong>).
        </p>
        <p style="color: #8b949e; font-size: 13px; line-height: 1.6;">
          Click the button below to establish a new password. This link is cryptographically secured and will expire in <strong>1 hour</strong>.
        </p>
        <div style="text-align: center; margin: 28px 0;">
          <a href="{$resetUrl}" style="background-color: #238636; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 13px; display: inline-block;">Reset Master Password</a>
        </div>
        <p style="color: #8b949e; font-size: 12px; line-height: 1.5; margin-bottom: 0;">
          If the button does not open, copy and paste the following URL into your browser:<br>
          <a href="{$resetUrl}" style="color: #58a6ff; word-break: break-all;">{$resetUrl}</a>
        </p>
      </td>
    </tr>
    <tr>
      <td style="padding: 16px 30px; background-color: #0d1117; border-top: 1px solid #30363d; font-size: 11px; color: #8b949e; text-align: center;">
        If you did not initiate this request, you can safely ignore this email. Your current credentials remain active.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
            $textBody = "PolymerOps Master Vault Password Reset\n\nReset your password within 1 hour using the link below:\n{$resetUrl}\n\nIf you did not request this, please ignore.";

            $mailSent = send_smtp_email($email, $subject, $htmlBody, $textBody);
            if (!$mailSent) {
                error_log("PolymerOps Dev Password Reset URL for {$email}: {$resetUrl}");
            }
        }

        // Defensive uniform response to prevent email enumeration
        json_response([
            'ok' => true,
            'message' => 'If this email address is registered, a password recovery link has been dispatched.'
        ]);
    }

    // 12. Verify Password Reset Token Validity
    if ($action === 'verify_reset_token') {
        require_method('GET');
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '' || strlen($token) !== 64) {
            json_response(['ok' => false, 'valid' => false, 'error' => 'Malformed or missing reset token.'], 400);
        }

        $tokenHash = hash('sha256', $token);
        $pdo = db(true);
        $stmt = $pdo->prepare('
            SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email, u.is_active 
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = ?
            LIMIT 1
        ');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            json_response(['ok' => false, 'valid' => false, 'error' => 'Reset token not found or invalid.'], 404);
        }
        if ($row['used_at'] !== null) {
            json_response(['ok' => false, 'valid' => false, 'error' => 'This reset token has already been used.'], 410);
        }
        if (strtotime((string) $row['expires_at']) <= time()) {
            json_response(['ok' => false, 'valid' => false, 'error' => 'This reset token has expired.'], 410);
        }
        if (!(bool) ($row['is_active'] ?? 1)) {
            json_response(['ok' => false, 'valid' => false, 'error' => 'Account access has been deactivated.'], 403);
        }

        json_response([
            'ok' => true,
            'valid' => true,
            'maskedEmail' => mask_email((string) $row['email']),
        ]);
    }

    // 13. Execute Password Reset Mutation
    if ($action === 'reset_password') {
        require_method('POST');
        $pdo = db(true);
        $data = read_json_body();
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($token === '' || strlen($token) !== 64) {
            json_response(['ok' => false, 'error' => 'Invalid or missing reset token.'], 400);
        }
        if (strlen($password) < 10) {
            json_response(['ok' => false, 'error' => 'Password must contain at least 10 characters.'], 422);
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare('
            SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.is_active 
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = ?
            LIMIT 1
        ');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        if (!$row || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) {
            json_response(['ok' => false, 'error' => 'Reset token is invalid, expired, or has already been used.'], 400);
        }
        if (!(bool) ($row['is_active'] ?? 1)) {
            json_response(['ok' => false, 'error' => 'Account access has been deactivated.'], 403);
        }

        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $pdo->beginTransaction();
        try {
            $upUser = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upUser->execute([$newHash, $row['user_id']]);

            $upToken = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
            $upToken->execute([$row['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        json_response([
            'ok' => true,
            'message' => 'Vault password has been successfully updated. You may now sign in with your new credentials.'
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unrecognized authentication action.', 'code' => 'INVALID_ACTION'], 404);
} catch (Throwable $e) {
    error_log('PolymerOps Auth Gateway Error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'An unexpected server error occurred.'], 500);
}
