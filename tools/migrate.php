<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Automated Legacy Data Migration & Bootstrap Engine
 * 
 * Target: https://github.com/khaledtaha-tech/PolymerOps_Master_Vault
 * Location: tools/migrate.php
 * 
 * Capabilities:
 * 1. Dual-Input Legacy Migration:
 *    - Mode A: Direct PDO connection to a secondary legacy MySQL/MariaDB database.
 *    - Mode B: Importing from exported legacy SQL dump or structured JSON payload.
 * 2. Cryptographic Re-Keying:
 *    - Transparently re-encrypts plaintext or legacy-keyed secrets to current AES-256-GCM.
 * 3. Initial Administrator & Master PIN Bootstrap:
 *    - Creates or elevates super-admin user with active session credentials and Master PIN.
 * 4. Automated Known Repository Metadata Seeder:
 *    - Populates tech stacks, detected DBs, AI tools, and notes for core repos.
 * 5. Execution Guard:
 *    - Enforces PHP CLI mode or authenticated HTTP token via POLYMER_MIGRATION_KEY.
 */

// Disable direct error output to keep output stream clean (CLI or JSON)
error_reporting(E_ALL);
ini_set('display_errors', '0');

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
}

require_once __DIR__ . '/../db.php';

// =============================================================================
// Security Guard: Validate Execution Context & Access Token
// =============================================================================

function verify_migration_authorization(bool $isCli): void {
    if ($isCli) {
        return; // CLI execution is explicitly authorized on host
    }

    $configuredKey = (string) cfg('migration_key', 'POLYMER_MIGRATION_KEY', '');
    if ($configuredKey === '' || $configuredKey === 'CHANGE_TO_A_SECURE_MIGRATION_SECRET_KEY') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Migration endpoint is disabled. Set a secure POLYMER_MIGRATION_KEY in config.php or environment.',
            'code' => 'MIGRATION_KEY_UNCONFIGURED'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $providedKey = (string) (
        $_SERVER['HTTP_X_MIGRATION_KEY']
        ?? $_GET['key']
        ?? $_POST['key']
        ?? ''
    );

    if ($providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Access denied: Invalid or missing migration key.',
            'code' => 'UNAUTHORIZED_MIGRATION_ACCESS'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

verify_migration_authorization($isCli);

// =============================================================================
// Logging & Output Helpers
// =============================================================================

class MigrationLogger {
    private bool $isCli;
    private array $logs = [];

    public function __construct(bool $isCli) {
        $this->isCli = $isCli;
    }

    public function info(string $msg): void {
        $this->log('INFO', $msg);
    }

    public function success(string $msg): void {
        $this->log('OK', $msg);
    }

    public function warn(string $msg): void {
        $this->log('WARN', $msg);
    }

    public function error(string $msg): void {
        $this->log('ERROR', $msg);
    }

    private function log(string $level, string $msg): void {
        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $msg
        ];
        $this->logs[] = $entry;

        if ($this->isCli) {
            $prefix = match ($level) {
                'OK' => "\033[32m[OK]\033[0m",
                'INFO' => "\033[34m[INFO]\033[0m",
                'WARN' => "\033[33m[WARN]\033[0m",
                'ERROR' => "\033[31m[ERROR]\033[0m",
                default => "[$level]"
            };
            echo "{$prefix} {$msg}\n";
        }
    }

    public function getLogs(): array {
        return $this->logs;
    }
}

$logger = new MigrationLogger($isCli);

// =============================================================================
// Cryptographic Re-Keying Utility
// =============================================================================

/**
 * Safely re-encrypt plaintext or legacy-encrypted credentials into current AES-256-GCM
 */
function reencrypt_secret(?string $val, ?string $legacyKey = null): ?string {
    if ($val === null || trim($val) === '') {
        return null;
    }
    $val = trim($val);

    // 1. Check if already encrypted with current key ('v1:' prefix verified by decrypt)
    if (str_starts_with($val, 'v1:')) {
        try {
            $decrypted = vault_decrypt($val);
            if ($decrypted !== null && $decrypted !== '') {
                return $val; // Valid current ciphertext, preserve intact
            }
        } catch (Throwable $_) {}
    }

    $plain = null;

    // 2. Attempt legacy decryption if a legacy secret key is provided
    if (!empty($legacyKey)) {
        $legacyBinKey = hash('sha256', $legacyKey, true);

        // A. Legacy AES-256-GCM with v1: prefix
        if (str_starts_with($val, 'v1:')) {
            $raw = base64_decode(substr($val, 3), true);
            if ($raw !== false && strlen($raw) >= 29) {
                $iv = substr($raw, 0, 12);
                $tag = substr($raw, 12, 16);
                $cipher = substr($raw, 28);
                $dec = openssl_decrypt($cipher, 'aes-256-gcm', $legacyBinKey, OPENSSL_RAW_DATA, $iv, $tag);
                if ($dec !== false && $dec !== '') {
                    $plain = $dec;
                }
            }
        }

        // B. Legacy AES-256-CBC (standard base64: IV 16 bytes + ciphertext)
        if ($plain === null) {
            $raw = base64_decode($val, true);
            if ($raw !== false && strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $cipher = substr($raw, 16);
                $dec = openssl_decrypt($cipher, 'aes-256-cbc', $legacyBinKey, OPENSSL_RAW_DATA, $iv);
                if ($dec !== false && $dec !== '') {
                    $plain = $dec;
                }
            }
        }
    }

    // 3. Fallback: treat as raw plaintext
    if ($plain === null) {
        $plain = $val;
    }

    // 4. Re-encrypt with system current AES-256-GCM key
    return vault_encrypt($plain);
}

// =============================================================================
// Module 1: Administrator Bootstrap & Master PIN Setup
// =============================================================================

function bootstrap_admin(PDO $pdo, string $email, string $password, string $pin, string $role = 'ADMIN'): array {
    $email = clean_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Invalid administrator email address: {$email}");
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException("Administrator password must have at least 8 characters.");
    }
    if (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
        throw new InvalidArgumentException("Master PIN must be 4 to 8 numeric digits.");
    }

    $passHash = password_hash($password, PASSWORD_DEFAULT);
    $pinHash = password_hash($pin, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('SELECT id, email, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        $userId = (int) $existing['id'];
        $up = $pdo->prepare('UPDATE users SET role = ?, is_active = 1, password_hash = ?, pin_hash = ? WHERE id = ?');
        $up->execute([$role, $passHash, $pinHash, $userId]);
        $action = 'updated';
    } else {
        $ins = $pdo->prepare('INSERT INTO users (email, role, is_active, password_hash, pin_hash) VALUES (?, ?, 1, ?, ?)');
        $ins->execute([$email, $role, $passHash, $pinHash]);
        $userId = (int) $pdo->lastInsertId();
        $action = 'created';
    }

    return [
        'userId' => $userId,
        'email' => $email,
        'role' => $role,
        'action' => $action,
        'pinConfigured' => true,
    ];
}

// =============================================================================
// Module 2: Automated Known Repository Metadata Seeder
// =============================================================================

function get_known_repositories_seed(): array {
    return [
        [
            'id' => 862900101,
            'name' => 'PolymerOps_Master_Vault',
            'full_name' => 'khaledtaha-tech/PolymerOps_Master_Vault',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Unified DevOps Hub & Credential Platform integrating GitHub Reconnaissance and OpenSSL AES-256-GCM Vault.',
            'html_url' => 'https://github.com/khaledtaha-tech/PolymerOps_Master_Vault',
            'homepage' => 'https://master.polymerops.com',
            'primary_language' => 'PHP',
            'detected_db' => 'MySQL 8.0',
            'detected_auth' => 'Session / Google SSO / Master PIN',
            'assigned_ai_tool' => 'Claude / Antigravity',
            'assigned_prompt_tool' => 'Lead Systems Architect',
            'work_notes_completed' => 'Phase 1 & 2 Completed: Full UI parity, OpenSSL AES-256-GCM encryption, and Smart Link Synergy.',
            'work_notes_next_steps' => 'Operationalize legacy migration utility and verify production admin bootstrapping.',
        ],
        [
            'id' => 862900102,
            'name' => 'PolymerOps',
            'full_name' => 'khaledtaha-tech/PolymerOps',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Primary Operations Management Platform and Core Web Services.',
            'html_url' => 'https://github.com/khaledtaha-tech/PolymerOps',
            'homepage' => 'https://polymerops.com',
            'primary_language' => 'TypeScript',
            'detected_db' => 'PostgreSQL / Supabase',
            'detected_auth' => 'Supabase Auth / JWT',
            'assigned_ai_tool' => 'Cursor / v0',
            'assigned_prompt_tool' => 'DevOps Systems Engineer',
            'work_notes_completed' => 'Core service routing and initial dashboard established.',
            'work_notes_next_steps' => 'Link production DB credentials and configure automated uptime monitoring.',
        ],
        [
            'id' => 862900103,
            'name' => 'smart-vault-manager',
            'full_name' => 'khaledtaha-tech/smart-vault-manager',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Automated Secret and Credential Management Prototype.',
            'html_url' => 'https://github.com/khaledtaha-tech/smart-vault-manager',
            'homepage' => 'https://vault.polymerops.internal',
            'primary_language' => 'JavaScript',
            'detected_db' => 'SQLite / MySQL',
            'detected_auth' => 'Master Password / PIN Elevation',
            'assigned_ai_tool' => 'GitHub Copilot',
            'assigned_prompt_tool' => 'Security Auditor',
            'work_notes_completed' => 'Initial cryptographic engine and multi-sector prototype.',
            'work_notes_next_steps' => 'Consolidate legacy records into PolymerOps Master Vault.',
        ],
        [
            'id' => 862900104,
            'name' => 'Compounding_Process_Control_New',
            'full_name' => 'khaledtaha-tech/Compounding_Process_Control_New',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Industrial Polymer Compounding and Process Control Automation Platform.',
            'html_url' => 'https://github.com/khaledtaha-tech/Compounding_Process_Control_New',
            'homepage' => 'https://process.polymerops.com',
            'primary_language' => 'Python',
            'detected_db' => 'MariaDB / Redis',
            'detected_auth' => 'Role-Based ACL / LDAP',
            'assigned_ai_tool' => 'Antigravity',
            'assigned_prompt_tool' => 'Process Control Specialist',
            'work_notes_completed' => 'Formulation algorithms and process parameter calculation models verified.',
            'work_notes_next_steps' => 'Integrate telemetry endpoints and connect to centralized vault.',
        ],
        [
            'id' => 862900105,
            'name' => 'pvc_pipe_production',
            'full_name' => 'khaledtaha-tech/pvc_pipe_production',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'PVC Pipe Production Line Monitoring, Formulas, and Optimization Suite.',
            'html_url' => 'https://github.com/khaledtaha-tech/pvc_pipe_production',
            'homepage' => 'https://pipe1.polymerops.com',
            'primary_language' => 'PHP',
            'detected_db' => 'MySQL',
            'detected_auth' => 'Standard Credentials / Session',
            'assigned_ai_tool' => 'Antigravity',
            'assigned_prompt_tool' => 'Production Line Architect',
            'work_notes_completed' => 'Real-time extrusion speed calculation models implemented.',
            'work_notes_next_steps' => 'Deploy IoT sensor bridge for production lines.',
        ],
        [
            'id' => 862900106,
            'name' => 'PWP_Manpower_Planner',
            'full_name' => 'khaledtaha-tech/PWP_Manpower_Planner',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Plant Workforce & Manpower Shift Planning and Allocation Platform.',
            'html_url' => 'https://github.com/khaledtaha-tech/PWP_Manpower_Planner',
            'homepage' => 'https://pwp.polymerops.com',
            'primary_language' => 'PHP / JavaScript',
            'detected_db' => 'MySQL (u976858450_pwp)',
            'detected_auth' => 'Google OAuth 2.0 / Session',
            'assigned_ai_tool' => 'Cursor',
            'assigned_prompt_tool' => 'Operations Analyst',
            'work_notes_completed' => 'Shift scheduling and rotation calendar fully functional.',
            'work_notes_next_steps' => 'Synchronize attendance telemetry via webhook.',
        ],
        [
            'id' => 862900107,
            'name' => 'Blown_Film_Pro',
            'full_name' => 'khaledtaha-tech/Blown_Film_Pro',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Blown Film Extrusion Calculation and Thickness Optimization Platform.',
            'html_url' => 'https://github.com/khaledtaha-tech/Blown_Film_Pro',
            'homepage' => 'https://filmcalc.polymerops.com',
            'primary_language' => 'PHP / Python',
            'detected_db' => 'MySQL (u976858450_Film)',
            'detected_auth' => 'Google OAuth 2.0 / Hostinger SMTP',
            'assigned_ai_tool' => 'Antigravity',
            'assigned_prompt_tool' => 'Plastics Extrusion Specialist',
            'work_notes_completed' => 'Extrusion die output and frost line height algorithms verified.',
            'work_notes_next_steps' => 'Connect to live plant historian database.',
        ],
        [
            'id' => 862900108,
            'name' => 'Mintle_Visit_Planner',
            'full_name' => 'khaledtaha-tech/Mintle_Visit_Planner',
            'owner_login' => 'khaledtaha-tech',
            'description' => 'Customer Site Visit, Route Optimization and Field Planning Manager.',
            'html_url' => 'https://github.com/khaledtaha-tech/Mintle_Visit_Planner',
            'homepage' => 'https://planning.polymerops.com',
            'primary_language' => 'JavaScript / Node.js',
            'detected_db' => 'MySQL (Planning)',
            'detected_auth' => 'Standard Auth / Admin Role',
            'assigned_ai_tool' => 'v0',
            'assigned_prompt_tool' => 'Logistics Planner',
            'work_notes_completed' => 'Route mapping and milestone scheduling operational.',
            'work_notes_next_steps' => 'Integrate SMS dispatch for field engineers.',
        ],
    ];
}

function seed_repositories_metadata(PDO $pdo, int $userId, MigrationLogger $logger): array {
    $seedData = get_known_repositories_seed();
    $seededCount = 0;

    $insRepo = $pdo->prepare('INSERT INTO repositories 
        (id, user_id, name, full_name, owner_login, description, html_url, homepage, clone_url, ssh_url, default_branch, primary_language, last_synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "main", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        name = VALUES(name),
        full_name = VALUES(full_name),
        description = VALUES(description),
        homepage = VALUES(homepage),
        primary_language = VALUES(primary_language)');

    $upsertMeta = $pdo->prepare('INSERT INTO repository_metadata 
        (repository_id, user_id, detected_db, detected_auth, assigned_ai_tool, assigned_prompt_tool, work_notes_completed, work_notes_next_steps, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        detected_db = COALESCE(VALUES(detected_db), detected_db),
        detected_auth = COALESCE(VALUES(detected_auth), detected_auth),
        assigned_ai_tool = COALESCE(VALUES(assigned_ai_tool), assigned_ai_tool),
        assigned_prompt_tool = COALESCE(VALUES(assigned_prompt_tool), assigned_prompt_tool),
        work_notes_completed = COALESCE(VALUES(work_notes_completed), work_notes_completed),
        work_notes_next_steps = COALESCE(VALUES(work_notes_next_steps), work_notes_next_steps),
        updated_at = CURRENT_TIMESTAMP');

    foreach ($seedData as $repo) {
        $repoId = (int) $repo['id'];
        $rName = (string) $repo['name'];
        $rFullName = (string) $repo['full_name'];
        $rOwner = (string) $repo['owner_login'];
        $rDesc = (string) ($repo['description'] ?? '');
        $rHurl = (string) $repo['html_url'];
        $rHpage = (string) ($repo['homepage'] ?? '');
        $rLang = (string) ($repo['primary_language'] ?? 'Other');

        $cloneUrl = $rHurl . '.git';
        $sshUrl = 'git@github.com:' . $rFullName . '.git';

        $insRepo->execute([$repoId, $userId, $rName, $rFullName, $rOwner, $rDesc, $rHurl, $rHpage, $cloneUrl, $sshUrl, $rLang]);

        $upsertMeta->execute([
            $repoId,
            $userId,
            $repo['detected_db'] ?? null,
            $repo['detected_auth'] ?? null,
            $repo['assigned_ai_tool'] ?? null,
            $repo['assigned_prompt_tool'] ?? null,
            $repo['work_notes_completed'] ?? null,
            $repo['work_notes_next_steps'] ?? null,
        ]);

        $seededCount++;
        $logger->info("Seeded metadata for repository: {$rName} ({$repo['detected_db']} / {$repo['detected_auth']})");
    }

    return ['seededRepositories' => $seededCount];
}

// =============================================================================
// Module 3: Synergy Correlation Engine (Bidirectional Vault & Repo Binding)
// =============================================================================

function run_synergy_correlation(PDO $pdo, int $userId, MigrationLogger $logger): array {
    $aStmt = $pdo->prepare('SELECT id, title, url, notes FROM accounts WHERE user_id = ?');
    $aStmt->execute([$userId]);
    $accounts = $aStmt->fetchAll();

    $rStmt = $pdo->prepare('SELECT id, name, html_url, homepage FROM repositories WHERE user_id = ?');
    $rStmt->execute([$userId]);
    $repositories = $rStmt->fetchAll();

    $upsertLink = $pdo->prepare('INSERT INTO repo_vault_links 
        (id, user_id, repository_id, account_id, link_nature, created_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        repository_id = VALUES(repository_id),
        account_id = VALUES(account_id),
        link_nature = VALUES(link_nature)');

    $linkCount = 0;
    foreach ($repositories as $repo) {
        $repoId = (int) $repo['id'];
        $repoName = (string) $repo['name'];
        $rNameNorm = strtolower(str_replace(['_', '-'], '', $repoName));
        $rHome = strtolower(trim((string) ($repo['homepage'] ?? '')));
        $rDomain = '';
        if ($rHome !== '') {
            $parsed = parse_url($rHome);
            $rDomain = strtolower($parsed['host'] ?? $rHome);
        }

        foreach ($accounts as $acc) {
            $accountId = (string) $acc['id'];
            $aTitle = (string) ($acc['title'] ?? '');
            $aUrl = (string) ($acc['url'] ?? '');
            $aNotes = (string) ($acc['notes'] ?? '');

            $aTitleLow = strtolower($aTitle);
            $aUrlLow = strtolower($aUrl);
            $aNotesLow = strtolower($aNotes);
            $aTitleNorm = str_replace(['_', '-'], '', $aTitleLow);

            $matched = false;
            $nature = 'PRIMARY_HOSTING';

            // 1. Domain match
            if ($rDomain !== '' && (str_contains($aUrlLow, $rDomain) || str_contains($aTitleLow, $rDomain) || str_contains($aNotesLow, $rDomain))) {
                $matched = true;
            }

            // 2. Normalized name match
            if (!$matched && (str_contains($aTitleNorm, $rNameNorm) || str_contains($rNameNorm, $aTitleNorm))) {
                $matched = true;
            }

            // 3. Domain & app aliases
            if (!$matched) {
                if ($repoName === 'Blown_Film_Pro' && ($aTitleLow === 'film' || str_contains($aNotesLow, 'blown_film_pro') || str_contains($aUrlLow, 'filmcalc.polymerops.com') || str_contains($aNotesLow, 'filmcalc'))) {
                    $matched = true;
                }
                if ($repoName === 'Mintle_Visit_Planner' && (str_contains($aTitleLow, 'mintle') || str_contains($aTitleLow, 'planning') || str_contains($aUrlLow, 'planning.polymerops.com'))) {
                    $matched = true;
                }
                if ($repoName === 'Compounding_Process_Control_New' && (str_contains($aTitleLow, 'cpc') || str_contains($aUrlLow, 'cpc.polymerops.com'))) {
                    $matched = true;
                }
                if ($repoName === 'pvc_pipe_production' && (str_contains($aTitleLow, 'pipe') || str_contains($aUrlLow, 'pipe1.polymerops.com'))) {
                    $matched = true;
                }
                if ($repoName === 'PolymerOps' && (str_contains($aTitleLow, 'dailyproduction') || str_contains($aTitleLow, 'repos.polymerops.com') || str_contains($aUrlLow, 'polymerops.com'))) {
                    $matched = true;
                }
            }

            if ($matched) {
                if (str_contains($aNotesLow, 'data base') || str_contains($aNotesLow, 'database') || str_contains($aNotesLow, 'db_host') || str_contains($aNotesLow, 'db_name')) {
                    $nature = 'PRODUCTION_DB';
                }
                $linkId = uuid_v4();
                $upsertLink->execute([$linkId, $userId, $repoId, $accountId, $nature]);
                $linkCount++;
                $logger->info("Synergy Bound: {$repoName} <-> '{$aTitle}' ({$nature})");
            }
        }
    }

    $logger->success("Synergy correlation pass completed: {$linkCount} repository-vault bindings secured.");
    return ['linkedCount' => $linkCount];
}

// =============================================================================
// Module 4: Legacy SQL Dump Bundle Importer
// =============================================================================

function import_legacy_dump_bundle(PDO $pdo, string $dumpPath, MigrationLogger $logger): array {
    if (!file_exists($dumpPath) || !is_readable($dumpPath)) {
        throw new RuntimeException("Dump file not found or not readable: {$dumpPath}");
    }

    $logger->info("Executing legacy SQL dump migration bundle from: {$dumpPath}");
    $sql = file_get_contents($dumpPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Dump file is empty: {$dumpPath}");
    }

    // Split into individual SQL statements safely
    $stmts = [];
    $len = strlen($sql);
    $inString = false;
    $stringChar = '';
    $escaped = false;
    $current = '';

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if (!$inString && $ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($inString) {
            $current .= $ch;
            if ($escaped) {
                $escaped = false;
            } elseif ($ch === '\\') {
                $escaped = true;
            } elseif ($ch === $stringChar) {
                if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                    $current .= $sql[++$i];
                } else {
                    $inString = false;
                }
            }
        } else {
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
            } elseif ($ch === ';') {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $stmts[] = $stmt;
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
    }

    $executed = 0;
    foreach ($stmts as $stmt) {
        $upper = strtoupper(trim($stmt));
        if ($upper === 'START TRANSACTION' || $upper === 'COMMIT') {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'Duplicate key') || str_contains($e->getMessage(), 'Duplicate entry')) {
                $logger->warn("Non-fatal DDL notice: " . $e->getMessage());
                continue;
            }
            throw $e;
        }
    }

    // Ensure khaled.taha.pro@gmail.com is established with primary ADMIN role
    $stmtUser = $pdo->prepare('SELECT id, email, role FROM users WHERE email = ? LIMIT 1');
    $stmtUser->execute(['khaled.taha.pro@gmail.com']);
    $userRow = $stmtUser->fetch();
    $adminId = 1;
    if ($userRow) {
        $adminId = (int) $userRow['id'];
        $up = $pdo->prepare('UPDATE users SET role = "ADMIN", is_active = 1 WHERE id = ?');
        $up->execute([$adminId]);
    }

    // Seed repositories and metadata
    seed_repositories_metadata($pdo, $adminId, $logger);

    // Run Synergy correlation
    $synergyRes = run_synergy_correlation($pdo, $adminId, $logger);

    // Query exact counts
    $accCount = (int) $pdo->query('SELECT COUNT(*) FROM accounts WHERE user_id = ' . $adminId)->fetchColumn();
    $secCount = (int) $pdo->query('SELECT COUNT(*) FROM sectors WHERE user_id = ' . $adminId)->fetchColumn();
    $poolCount = (int) $pdo->query('SELECT COUNT(*) FROM credential_pool_items WHERE user_id = ' . $adminId)->fetchColumn();
    $linkCount = (int) $pdo->query('SELECT COUNT(*) FROM repo_vault_links WHERE user_id = ' . $adminId)->fetchColumn();

    $logger->success("Migration summary: {$accCount} accounts, {$secCount} sectors, {$poolCount} pool items, {$linkCount} synergy links active.");

    return [
        'adminId' => $adminId,
        'adminEmail' => 'khaled.taha.pro@gmail.com',
        'accountsMigrated' => $accCount,
        'sectorsMigrated' => $secCount,
        'poolItemsMigrated' => $poolCount,
        'synergyLinksBound' => $linkCount,
        'statementsExecuted' => $executed,
    ];
}

// =============================================================================
// Module 3: Data Ingestion & Upsert Engine (Dual Modes)
// =============================================================================

function import_sectors_data(PDO $pdo, int $userId, array $sectors, ?string $legacyKey, MigrationLogger $logger): array {
    $count = 0;
    $upsert = $pdo->prepare('INSERT INTO sectors 
        (id, user_id, name, default_username, default_password, is_shared_vault, created_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        name = VALUES(name),
        default_username = VALUES(default_username),
        default_password = VALUES(default_password),
        is_shared_vault = VALUES(is_shared_vault)');

    foreach ($sectors as $s) {
        $id = !empty($s['id']) ? (string) $s['id'] : uuid_v4();
        $name = trim((string) ($s['name'] ?? $s['group_name'] ?? ''));
        if ($name === '') continue;

        $defUser = !empty($s['default_username']) ? trim((string) $s['default_username']) : (!empty($s['username']) ? trim((string) $s['username']) : null);
        $rawPass = $s['default_password'] ?? $s['password'] ?? null;
        $encPass = reencrypt_secret($rawPass !== null ? (string) $rawPass : null, $legacyKey);
        $isShared = !empty($s['is_shared_vault']) || !empty($s['is_shared']) ? 1 : 0;

        $upsert->execute([$id, $userId, $name, $defUser, $encPass, $isShared]);
        $count++;
    }

    $logger->info("Imported / Upserted {$count} sectors / access groups.");
    return ['count' => $count];
}

function import_accounts_data(PDO $pdo, int $userId, array $accounts, ?string $legacyKey, MigrationLogger $logger): array {
    $count = 0;
    $upsert = $pdo->prepare('INSERT INTO accounts 
        (id, user_id, serial_no, sector_id, title, url, auth_type, logic_rule, custom_username, custom_password, category, mobile_number, shared_with_team, db_info, api_key, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        sector_id = VALUES(sector_id),
        title = VALUES(title),
        url = VALUES(url),
        auth_type = VALUES(auth_type),
        logic_rule = VALUES(logic_rule),
        custom_username = VALUES(custom_username),
        custom_password = VALUES(custom_password),
        category = VALUES(category),
        mobile_number = VALUES(mobile_number),
        shared_with_team = VALUES(shared_with_team),
        db_info = VALUES(db_info),
        api_key = VALUES(api_key),
        notes = VALUES(notes),
        updated_at = CURRENT_TIMESTAMP');

    foreach ($accounts as $a) {
        $id = !empty($a['id']) ? (string) $a['id'] : uuid_v4();
        $title = trim((string) ($a['title'] ?? $a['account_name'] ?? ''));
        if ($title === '') continue;

        $serial = !empty($a['serial_no']) ? (int) $a['serial_no'] : null;
        $sectorId = !empty($a['sector_id']) ? (string) $a['sector_id'] : (!empty($a['group_id']) ? (string) $a['group_id'] : null);
        $url = !empty($a['url']) ? trim((string) $a['url']) : null;
        
        $authType = strtoupper(trim((string) ($a['auth_type'] ?? 'CREDENTIALS')));
        $validAuths = ['CREDENTIALS', 'GOOGLE_AUTH', 'NAFAZ', 'OTP_SMS', 'GITHUB_AUTH', 'OTHER'];
        if (!in_array($authType, $validAuths, true)) {
            $authType = 'CREDENTIALS';
        }

        $logicRule = strtoupper(trim((string) ($a['logic_rule'] ?? 'CUSTOM')));
        $validRules = ['FULL_SECTOR', 'SECTOR_PASS_ONLY', 'SHARED_PASS', 'MEMORIZED_PASS', 'CUSTOM'];
        if (!in_array($logicRule, $validRules, true)) {
            $logicRule = 'CUSTOM';
        }

        $customUser = !empty($a['custom_username']) ? trim((string) $a['custom_username']) : (!empty($a['username']) ? trim((string) $a['username']) : null);
        $rawPass = $a['custom_password'] ?? $a['password'] ?? null;
        $customPass = reencrypt_secret($rawPass !== null ? (string) $rawPass : null, $legacyKey);

        $category = strtoupper(trim((string) ($a['category'] ?? 'GENERAL')));
        $validCats = ['GENERAL', 'WORK', 'CRITICAL_DRIVE', 'OFFICIAL_GOV'];
        if (!in_array($category, $validCats, true)) {
            $category = 'GENERAL';
        }

        $mobile = !empty($a['mobile_number']) ? trim((string) $a['mobile_number']) : null;
        $shared = (!empty($a['shared_with_team']) || !empty($a['shared'])) ? 1 : 0;
        
        $dbInfo = reencrypt_secret(!empty($a['db_info']) ? (string) $a['db_info'] : null, $legacyKey);
        $apiKey = reencrypt_secret(!empty($a['api_key']) ? (string) $a['api_key'] : null, $legacyKey);
        $notes = !empty($a['notes']) ? trim((string) $a['notes']) : null;

        $upsert->execute([
            $id, $userId, $serial, $sectorId, $title, $url, $authType, $logicRule,
            $customUser, $customPass, $category, $mobile, $shared, $dbInfo, $apiKey, $notes
        ]);
        $count++;
    }

    $logger->info("Imported / Upserted {$count} vault accounts.");
    return ['count' => $count];
}

function import_repo_metadata_data(PDO $pdo, int $userId, array $metadataItems, MigrationLogger $logger): array {
    $count = 0;
    $upsert = $pdo->prepare('INSERT INTO repository_metadata 
        (repository_id, user_id, detected_db, detected_auth, assigned_ai_tool, assigned_prompt_tool, inspection_cache, work_notes_completed, work_notes_next_steps, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        detected_db = VALUES(detected_db),
        detected_auth = VALUES(detected_auth),
        assigned_ai_tool = VALUES(assigned_ai_tool),
        assigned_prompt_tool = VALUES(assigned_prompt_tool),
        inspection_cache = VALUES(inspection_cache),
        work_notes_completed = VALUES(work_notes_completed),
        work_notes_next_steps = VALUES(work_notes_next_steps),
        updated_at = CURRENT_TIMESTAMP');

    foreach ($metadataItems as $m) {
        $repoId = (int) ($m['repository_id'] ?? $m['repo_id'] ?? 0);
        if ($repoId <= 0) continue;

        $detectedDb = !empty($m['detected_db']) ? trim((string) $m['detected_db']) : null;
        $detectedAuth = !empty($m['detected_auth']) ? trim((string) $m['detected_auth']) : null;
        $aiTool = !empty($m['assigned_ai_tool']) ? trim((string) $m['assigned_ai_tool']) : null;
        $promptTool = !empty($m['assigned_prompt_tool']) ? trim((string) $m['assigned_prompt_tool']) : null;
        
        $inspectionCache = null;
        if (!empty($m['inspection_cache'])) {
            $inspectionCache = is_string($m['inspection_cache']) ? $m['inspection_cache'] : json_encode($m['inspection_cache']);
        }

        $completedNotes = !empty($m['work_notes_completed']) ? (string) $m['work_notes_completed'] : null;
        $nextNotes = !empty($m['work_notes_next_steps']) ? (string) $m['work_notes_next_steps'] : null;

        $upsert->execute([
            $repoId, $userId, $detectedDb, $detectedAuth, $aiTool, $promptTool,
            $inspectionCache, $completedNotes, $nextNotes
        ]);
        $count++;
    }

    $logger->info("Imported / Upserted {$count} repository metadata entries.");
    return ['count' => $count];
}

function import_repo_vault_links_data(PDO $pdo, int $userId, array $links, MigrationLogger $logger): array {
    $count = 0;
    $upsert = $pdo->prepare('INSERT INTO repo_vault_links 
        (id, user_id, repository_id, account_id, link_nature, created_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        link_nature = VALUES(link_nature)');

    foreach ($links as $l) {
        $id = !empty($l['id']) ? (string) $l['id'] : uuid_v4();
        $repoId = (int) ($l['repository_id'] ?? $l['repo_id'] ?? 0);
        $accountId = trim((string) ($l['account_id'] ?? ''));
        if ($repoId <= 0 || $accountId === '') continue;

        $nature = (string) ($l['link_nature'] ?? 'PRIMARY_HOSTING');
        $validNatures = ['PRIMARY_HOSTING', 'STAGING', 'PRODUCTION_DB', 'API_GATEWAY', 'SERVICE_ACCOUNT'];
        if (!in_array($nature, $validNatures, true)) {
            $nature = 'PRIMARY_HOSTING';
        }

        $upsert->execute([$id, $userId, $repoId, $accountId, $nature]);
        $count++;
    }

    $logger->info("Imported / Upserted {$count} Smart Links.");
    return ['count' => $count];
}

// =============================================================================
// Mode A: Direct Secondary Legacy Database Reader
// =============================================================================

function connect_legacy_db(array $opts): PDO {
    $host = (string) ($opts['host'] ?? cfg('legacy_db_host', 'POLYMER_LEGACY_DB_HOST', 'localhost'));
    $name = (string) ($opts['name'] ?? cfg('legacy_db_name', 'POLYMER_LEGACY_DB_NAME', ''));
    $user = (string) ($opts['user'] ?? cfg('legacy_db_user', 'POLYMER_LEGACY_DB_USER', ''));
    $pass = (string) ($opts['pass'] ?? cfg('legacy_db_pass', 'POLYMER_LEGACY_DB_PASS', ''));
    $port = (int) ($opts['port'] ?? cfg('legacy_db_port', 'POLYMER_LEGACY_DB_PORT', 3306));

    if ($name === '') {
        throw new InvalidArgumentException("Legacy database name is empty. Specify --legacy-name or set POLYMER_LEGACY_DB_NAME.");
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function query_legacy_table_if_exists(PDO $legacyPdo, string ...$tableNames): array {
    foreach ($tableNames as $t) {
        try {
            $stmt = $legacyPdo->query("SELECT * FROM `{$t}`");
            return $stmt->fetchAll();
        } catch (Throwable $_) {
            continue;
        }
    }
    return [];
}

// =============================================================================
// Mode B: File-based Parser (JSON or SQL Dump)
// =============================================================================

function parse_import_file(string $filePath): array {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        throw new RuntimeException("Cannot read payload file: {$filePath}");
    }

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        throw new RuntimeException("Failed reading contents from: {$filePath}");
    }

    // Attempt JSON parse
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return normalize_payload_structure($decoded);
    }

    // Fallback: Parse SQL insert statements
    return parse_sql_dump_payload($raw);
}

function normalize_payload_structure(array $data): array {
    return [
        'sectors' => $data['sectors'] ?? $data['access_groups'] ?? [],
        'accounts' => $data['accounts'] ?? $data['vault_accounts'] ?? [],
        'metadata' => $data['metadata'] ?? $data['repository_metadata'] ?? $data['repo_metadata'] ?? [],
        'links' => $data['links'] ?? $data['repo_vault_links'] ?? $data['smart_links'] ?? [],
    ];
}

function parse_sql_dump_payload(string $sql): array {
    $result = [
        'sectors' => [],
        'accounts' => [],
        'metadata' => [],
        'links' => [],
    ];

    // Regex to match INSERT INTO statements
    $pattern = '/INSERT\s+(?:IGNORE\s+)?INTO\s+[`"]?(\w+)[`"]?\s*(?:\(([^)]+)\))?\s+VALUES\s*([^;]+);/i';
    if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $table = strtolower($match[1]);
            $rawCols = $match[2];
            $rawValuesBlock = $match[3];

            $cols = [];
            if ($rawCols) {
                $cols = array_map(fn($c) => trim($c, " `\"\t\n\r"), explode(',', $rawCols));
            }

            // Simple row extractor
            if (preg_match_all('/\(([^)]+)\)/', $rawValuesBlock, $rowMatches)) {
                foreach ($rowMatches[1] as $rowStr) {
                    $vals = str_getcsv($rowStr, ',', "'");
                    $vals = array_map(fn($v) => ($v === 'NULL' || $v === 'null') ? null : trim($v), $vals);

                    $row = [];
                    if (!empty($cols) && count($cols) === count($vals)) {
                        $row = array_combine($cols, $vals);
                    } else {
                        $row = $vals;
                    }

                    if (in_array($table, ['sectors', 'access_groups'], true)) {
                        $result['sectors'][] = $row;
                    } elseif (in_array($table, ['accounts', 'vault_accounts'], true)) {
                        $result['accounts'][] = $row;
                    } elseif (in_array($table, ['repository_metadata', 'repo_metadata'], true)) {
                        $result['metadata'][] = $row;
                    } elseif (in_array($table, ['repo_vault_links', 'smart_links'], true)) {
                        $result['links'][] = $row;
                    }
                }
            }
        }
    }

    return $result;
}

// =============================================================================
// CLI Argument Parsing & Command Dispatch
// =============================================================================

function parse_cli_options(): array {
    $longopts = [
        'help',
        'create-admin',
        'email:',
        'password:',
        'pin:',
        'role:',
        'seed-metadata',
        'legacy-db',
        'legacy-host:',
        'legacy-name:',
        'legacy-user:',
        'legacy-pass:',
        'legacy-port:',
        'legacy-key:',
        'file:',
        'import-dump::',
        'synergy-sync',
        'json',
    ];
    return getopt('h', $longopts);
}

function print_cli_help(): void {
    echo <<<HELP
PolymerOps Master Vault - Migration & Bootstrap CLI
===================================================

Usage:
  php tools/migrate.php [options]

Core Options:
  --help, -h               Show this documentation.
  --create-admin           Bootstrap or reset super-admin credentials & Master PIN.
  --email=<email>          Admin email (default: admin@polymerops.com).
  --password=<pass>        Admin password (default: autogenerated 20 chars).
  --pin=<pin>              Master PIN for Critical Vault elevation (default: 1234).
  --role=<role>            Admin role (default: ADMIN).
  --seed-metadata          Populate default tech stacks, DBs, and notes for core repos.
  --import-dump[=<file>]   Import all records from legacy SQL dump (default: u976858450_pass.sql), bootstrap khaled.taha.pro@gmail.com, and run Synergy correlation.
  --synergy-sync           Re-run bidirectional Synergy correlation across all repositories and accounts.

Mode A: Legacy Database Direct Migration:
  --legacy-db              Connect directly to a legacy database.
  --legacy-host=<host>     Legacy DB host (default: localhost).
  --legacy-name=<db>       Legacy DB name.
  --legacy-user=<user>     Legacy DB user.
  --legacy-pass=<pass>     Legacy DB password.
  --legacy-port=<port>     Legacy DB port (default: 3306).
  --legacy-key=<key>       Legacy AES key for re-keying into current AES-256-GCM.

Mode B: File-based Import (JSON or SQL dump):
  --file=<path>            Path to legacy export (.json or .sql).
  --legacy-key=<key>       Legacy AES key to decrypt older payloads.

Output Options:
  --json                   Output machine-readable JSON summary.

Examples:
  # 1. Full Legacy Dump Migration (31 Accounts, 3 Sectors, Admin + Synergy Binding)
  php tools/migrate.php --import-dump

  # 2. Full Fresh Bootstrap (Admin + Master PIN + Core Repo Metadata)
  php tools/migrate.php --create-admin --seed-metadata

  # 3. Custom Admin Credentials Setup
  php tools/migrate.php --create-admin --email=khaled@polymerops.com --pin=9876 --password=MySecureVaultPass2026!

  # 4. Import from JSON Payload with Cryptographic Re-Keying
  php tools/migrate.php --file=legacy_vault_export.json --legacy-key=OldSecretKey32CharsLong...

  # 5. Migrate Directly from Legacy MySQL DB
  php tools/migrate.php --legacy-db --legacy-name=polymerops_v1 --legacy-user=root --legacy-pass=secret

HELP;
}

// =============================================================================
// Main Execution Controller
// =============================================================================

try {
    if ($isCli) {
        $opts = parse_cli_options();

        if (isset($opts['help']) || isset($opts['h'])) {
            print_cli_help();
            exit(0);
        }
    }

    $targetPdo = db(false);
    if (!$targetPdo) {
        if ($isCli) {
            fwrite(STDERR, "\033[31m[ERROR]\033[0m Target database is unreachable or unconfigured. Verify db_host, db_name, db_user, db_pass in config.php.\n");
            exit(1);
        } else {
            json_response([
                'ok' => false,
                'error' => 'Target database is unreachable or unconfigured.',
                'code' => 'DB_OFFLINE'
            ], 500);
        }
    }

    $adminUserId = null;

    // Discover or bootstrap admin user id
    $stmtAdmin = $targetPdo->query('SELECT id, email FROM users WHERE role = "ADMIN" ORDER BY id ASC LIMIT 1');
    $adminRow = $stmtAdmin->fetch();
    if ($adminRow) {
        $adminUserId = (int) $adminRow['id'];
    }

    if ($isCli) {
        $isCreateAdmin = isset($opts['create-admin']);
        $isSeedMetadata = isset($opts['seed-metadata']);
        $isLegacyDb = isset($opts['legacy-db']);
        $isImportDump = isset($opts['import-dump']);
        $isSynergySync = isset($opts['synergy-sync']);
        $dumpPath = $opts['import-dump'] ?? $opts['file'] ?? null;
        $inputFile = $opts['file'] ?? null;
        $legacyKey = (string) ($opts['legacy-key'] ?? cfg('legacy_app_key', 'POLYMER_LEGACY_APP_KEY', ''));

        // If no flags are provided, show help
        if (!$isCreateAdmin && !$isSeedMetadata && !$isLegacyDb && !$inputFile && !$isImportDump && !$isSynergySync) {
            $logger->info("No action flags supplied. Running default legacy dump migration & Synergy correlation.");
            $isImportDump = true;
        }

        $targetPdo->beginTransaction();

        $summary = [
            'admin' => null,
            'metadata' => null,
            'sectors' => null,
            'accounts' => null,
            'links' => null,
            'dump_migration' => null,
        ];

        // 1. Direct Legacy Dump Import
        if ($isImportDump || ($inputFile && str_ends_with(strtolower((string)$inputFile), '.sql'))) {
            $dumpCandidate = is_string($dumpPath) && $dumpPath !== '' ? $dumpPath : (is_string($inputFile) ? $inputFile : '');
            $resolvedDump = null;
            $searchPaths = [
                $dumpCandidate,
                __DIR__ . '/../data/migrate_u976858450_master_vault.sql',
                __DIR__ . '/../u976858450_pass.sql',
                'data/migrate_u976858450_master_vault.sql',
                'u976858450_pass.sql',
            ];
            foreach ($searchPaths as $p) {
                if (!empty($p) && file_exists($p) && is_readable($p)) {
                    $resolvedDump = $p;
                    break;
                }
            }
            if (!$resolvedDump) {
                throw new RuntimeException("Could not locate legacy migration SQL dump file (checked u976858450_pass.sql and data/migrate_u976858450_master_vault.sql).");
            }
            $summary['dump_migration'] = import_legacy_dump_bundle($targetPdo, $resolvedDump, $logger);
            $adminUserId = $summary['dump_migration']['adminId'];
        }

        // 2. Admin Bootstrap
        if ($isCreateAdmin) {
            $email = (string) ($opts['email'] ?? 'admin@polymerops.com');
            $pin = (string) ($opts['pin'] ?? '1234');
            $role = (string) ($opts['role'] ?? 'ADMIN');
            $password = (string) ($opts['password'] ?? '');

            if ($password === '') {
                $password = bin2hex(random_bytes(10)) . '!Aa9';
                $logger->info("Autogenerated administrator password: \033[33m{$password}\033[0m");
            }

            $adminRes = bootstrap_admin($targetPdo, $email, $password, $pin, $role);
            $adminUserId = $adminRes['userId'];
            $summary['admin'] = $adminRes;
            $logger->success("Administrator account '{$email}' {$adminRes['action']} (User ID: {$adminUserId}, Master PIN: Active).");
        }

        // Guarantee we have an admin user ID for relational integrity
        if ($adminUserId === null) {
            $adminRes = bootstrap_admin($targetPdo, 'khaled.taha.pro@gmail.com', 'PolymerOpsMaster2026!', '1234', 'ADMIN');
            $adminUserId = $adminRes['userId'];
            $summary['admin'] = $adminRes;
            $logger->info("Initialized baseline administrator (User ID: {$adminUserId}).");
        }

        // 3. Seed Repository Metadata
        if ($isSeedMetadata) {
            $seedRes = seed_repositories_metadata($targetPdo, $adminUserId, $logger);
            $summary['metadata'] = $seedRes;
            $logger->success("Repository metadata seeding completed ({$seedRes['seededRepositories']} repos configured).");
        }

        // 4. Mode A: Direct Legacy DB Migration
        if ($isLegacyDb) {
            $legacyOpts = [
                'host' => $opts['legacy-host'] ?? null,
                'name' => $opts['legacy-name'] ?? null,
                'user' => $opts['legacy-user'] ?? null,
                'pass' => $opts['legacy-pass'] ?? null,
                'port' => isset($opts['legacy-port']) ? (int) $opts['legacy-port'] : null,
            ];

            $logger->info("Connecting to secondary legacy database...");
            $legacyPdo = connect_legacy_db($legacyOpts);
            $logger->success("Connected to legacy database successfully.");

            $legacySectors = query_legacy_table_if_exists($legacyPdo, 'sectors', 'access_groups');
            $legacyAccounts = query_legacy_table_if_exists($legacyPdo, 'accounts', 'vault_accounts');
            $legacyMeta = query_legacy_table_if_exists($legacyPdo, 'repository_metadata', 'repo_metadata');
            $legacyLinks = query_legacy_table_if_exists($legacyPdo, 'repo_vault_links', 'smart_links');

            if (!empty($legacySectors)) {
                $summary['sectors'] = import_sectors_data($targetPdo, $adminUserId, $legacySectors, $legacyKey, $logger);
            }
            if (!empty($legacyAccounts)) {
                $summary['accounts'] = import_accounts_data($targetPdo, $adminUserId, $legacyAccounts, $legacyKey, $logger);
            }
            if (!empty($legacyMeta)) {
                $summary['metadata_import'] = import_repo_metadata_data($targetPdo, $adminUserId, $legacyMeta, $logger);
            }
            if (!empty($legacyLinks)) {
                $summary['links'] = import_repo_vault_links_data($targetPdo, $adminUserId, $legacyLinks, $logger);
            }
        }

        // 5. Mode B: File-based JSON Import
        if ($inputFile && !str_ends_with(strtolower((string)$inputFile), '.sql')) {
            $logger->info("Parsing payload file: {$inputFile}...");
            $payload = parse_import_file($inputFile);

            if (!empty($payload['sectors'])) {
                $summary['sectors'] = import_sectors_data($targetPdo, $adminUserId, $payload['sectors'], $legacyKey, $logger);
            }
            if (!empty($payload['accounts'])) {
                $summary['accounts'] = import_accounts_data($targetPdo, $adminUserId, $payload['accounts'], $legacyKey, $logger);
            }
            if (!empty($payload['metadata'])) {
                $summary['metadata_import'] = import_repo_metadata_data($targetPdo, $adminUserId, $payload['metadata'], $logger);
            }
            if (!empty($payload['links'])) {
                $summary['links'] = import_repo_vault_links_data($targetPdo, $adminUserId, $payload['links'], $logger);
            }
        }

        // 6. Synergy Correlation Sync
        if ($isSynergySync || $isImportDump) {
            $summary['synergy_sync'] = run_synergy_correlation($targetPdo, $adminUserId, $logger);
        }

        $targetPdo->commit();
        $logger->success("All operations committed to target database successfully.");

        if (isset($opts['json'])) {
            echo json_encode(['ok' => true, 'summary' => $summary, 'logs' => $logger->getLogs()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        exit(0);
    } else {
        // =========================================================================
        // HTTP Web API Execution Mode
        // =========================================================================
        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'all');
        $body = read_json_body();

        $targetPdo->beginTransaction();
        $summary = [];

        // 1. Direct Legacy Dump Import via HTTP
        if ($action === 'import_dump' || $action === 'all') {
            $dumpCandidate = (string) ($body['dump_file'] ?? $_GET['dump_file'] ?? '');
            $resolvedDump = null;
            $searchPaths = [
                $dumpCandidate,
                __DIR__ . '/../data/migrate_u976858450_master_vault.sql',
                __DIR__ . '/../u976858450_pass.sql',
                'data/migrate_u976858450_master_vault.sql',
                'u976858450_pass.sql',
            ];
            foreach ($searchPaths as $p) {
                if (!empty($p) && file_exists($p) && is_readable($p)) {
                    $resolvedDump = $p;
                    break;
                }
            }
            if ($resolvedDump) {
                $summary['dump_migration'] = import_legacy_dump_bundle($targetPdo, $resolvedDump, $logger);
                $adminUserId = $summary['dump_migration']['adminId'];
            }
        }

        // Guarantee or bootstrap admin user
        if ($adminUserId === null || $action === 'create_admin') {
            $email = (string) ($body['email'] ?? $_POST['email'] ?? $_GET['email'] ?? 'khaled.taha.pro@gmail.com');
            $password = (string) ($body['password'] ?? $_POST['password'] ?? $_GET['password'] ?? 'PolymerOpsMaster2026!');
            $pin = (string) ($body['pin'] ?? $_POST['pin'] ?? $_GET['pin'] ?? '1234');
            $role = (string) ($body['role'] ?? $_POST['role'] ?? $_GET['role'] ?? 'ADMIN');

            $adminRes = bootstrap_admin($targetPdo, $email, $password, $pin, $role);
            $adminUserId = $adminRes['userId'];
            $summary['admin'] = $adminRes;
        }

        if ($action === 'seed_metadata' || $action === 'all') {
            $summary['metadata'] = seed_repositories_metadata($targetPdo, $adminUserId, $logger);
        }

        if ($action === 'synergy_sync' || $action === 'all') {
            $summary['synergy_sync'] = run_synergy_correlation($targetPdo, $adminUserId, $logger);
        }

        // Direct JSON payload import via HTTP POST
        if ($action === 'import_json' || (!empty($body['accounts']) || !empty($body['sectors']))) {
            $legacyKey = (string) ($body['legacyKey'] ?? $_GET['legacy_key'] ?? cfg('legacy_app_key', 'POLYMER_LEGACY_APP_KEY', ''));
            $payload = normalize_payload_structure($body);

            if (!empty($payload['sectors'])) {
                $summary['sectors'] = import_sectors_data($targetPdo, $adminUserId, $payload['sectors'], $legacyKey, $logger);
            }
            if (!empty($payload['accounts'])) {
                $summary['accounts'] = import_accounts_data($targetPdo, $adminUserId, $payload['accounts'], $legacyKey, $logger);
            }
            if (!empty($payload['metadata'])) {
                $summary['metadata_import'] = import_repo_metadata_data($targetPdo, $adminUserId, $payload['metadata'], $logger);
            }
            if (!empty($payload['links'])) {
                $summary['links'] = import_repo_vault_links_data($targetPdo, $adminUserId, $payload['links'], $logger);
            }
        }

        // Direct Secondary Legacy DB via HTTP
        if ($action === 'migrate_db') {
            $legacyOpts = [
                'host' => $body['legacyHost'] ?? $_GET['legacy_host'] ?? null,
                'name' => $body['legacyName'] ?? $_GET['legacy_name'] ?? null,
                'user' => $body['legacyUser'] ?? $_GET['legacy_user'] ?? null,
                'pass' => $body['legacyPass'] ?? $_GET['legacy_pass'] ?? null,
                'port' => isset($body['legacyPort']) ? (int) $body['legacyPort'] : null,
            ];
            $legacyKey = (string) ($body['legacyKey'] ?? $_GET['legacy_key'] ?? cfg('legacy_app_key', 'POLYMER_LEGACY_APP_KEY', ''));

            $legacyPdo = connect_legacy_db($legacyOpts);
            $legacySectors = query_legacy_table_if_exists($legacyPdo, 'sectors', 'access_groups');
            $legacyAccounts = query_legacy_table_if_exists($legacyPdo, 'accounts', 'vault_accounts');
            $legacyMeta = query_legacy_table_if_exists($legacyPdo, 'repository_metadata', 'repo_metadata');
            $legacyLinks = query_legacy_table_if_exists($legacyPdo, 'repo_vault_links', 'smart_links');

            if (!empty($legacySectors)) {
                $summary['sectors'] = import_sectors_data($targetPdo, $adminUserId, $legacySectors, $legacyKey, $logger);
            }
            if (!empty($legacyAccounts)) {
                $summary['accounts'] = import_accounts_data($targetPdo, $adminUserId, $legacyAccounts, $legacyKey, $logger);
            }
            if (!empty($legacyMeta)) {
                $summary['metadata_import'] = import_repo_metadata_data($targetPdo, $adminUserId, $legacyMeta, $logger);
            }
            if (!empty($legacyLinks)) {
                $summary['links'] = import_repo_vault_links_data($targetPdo, $adminUserId, $legacyLinks, $logger);
            }
        }

        $targetPdo->commit();

        json_response([
            'ok' => true,
            'action' => $action,
            'summary' => $summary,
            'logs' => $logger->getLogs(),
        ]);
    }
} catch (Throwable $e) {
    if (isset($targetPdo) && $targetPdo instanceof PDO && $targetPdo->inTransaction()) {
        $targetPdo->rollBack();
    }

    $logger->error("Migration failed with rollback: " . $e->getMessage());

    if ($isCli) {
        fwrite(STDERR, "\n\033[31m[FATAL ERROR]\033[0m " . $e->getMessage() . "\n");
        exit(1);
    } else {
        json_response([
            'ok' => false,
            'error' => $e->getMessage(),
            'code' => 'MIGRATION_EXECUTION_FAILURE',
            'logs' => $logger->getLogs(),
        ], 500);
    }
}
