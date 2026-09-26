<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Unified API Gateway & Service Router
 * 
 * Routes:
 * - ?resource=status       System health, database availability, and GitHub token readiness
 * - ?resource=repos        GitHub repository explorer, complete pagination, and AST tree inspector
 * - ?resource=vault        Credential sectors, encrypted accounts, and high-entropy generator
 * - ?resource=links        Smart Link Synergy (repository to vault account bindings)
 * - ?resource=notes        Persistent Dev Notes & Progress engine
 */

require_once __DIR__ . '/db.php';

$resource = (string) ($_GET['resource'] ?? 'status');
$action = (string) ($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// =============================================================================
// Helper Functions for GitHub API Communication & AST Inspection
// =============================================================================

function make_github_request(string $endpoint, string $token, array $config): array {
    $url = (str_starts_with($endpoint, 'http')) ? $endpoint : rtrim($config['api_base_url'] ?? 'https://api.github.com', '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init();
    $responseHeaders = [];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($config['timeout'] ?? 30),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => $config['user_agent'] ?? 'PolymerOps-Master-Vault/1.0',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) use (&$responseHeaders) {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $key = strtolower(trim($parts[0]));
            $val = trim($parts[1]);
            if (isset($responseHeaders[$key])) {
                $responseHeaders[$key] .= ', ' . $val;
            } else {
                $responseHeaders[$key] = $val;
            }
        }
        return $len;
    });

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $statusCode,
        'body' => (string) $body,
        'headers' => $responseHeaders,
        'error' => $curlError,
    ];
}

function parse_rate_limits(array $headers): array {
    $limit = isset($headers['x-ratelimit-limit']) ? (int) $headers['x-ratelimit-limit'] : null;
    $remaining = isset($headers['x-ratelimit-remaining']) ? (int) $headers['x-ratelimit-remaining'] : null;
    $reset = isset($headers['x-ratelimit-reset']) ? (int) $headers['x-ratelimit-reset'] : null;
    $used = isset($headers['x-ratelimit-used']) ? (int) $headers['x-ratelimit-used'] : null;

    $resetFormatted = null;
    $secondsUntilReset = null;
    if ($reset) {
        $resetFormatted = gmdate('Y-m-d H:i:s \U\T\C', $reset);
        $secondsUntilReset = max(0, $reset - time());
    }

    return [
        'limit' => $limit,
        'remaining' => $remaining,
        'reset' => $reset,
        'reset_formatted' => $resetFormatted,
        'seconds_until_reset' => $secondsUntilReset,
        'used' => $used,
    ];
}

function inspect_repository_ast(string $owner, string $repoName, string $branch, string $pushedAt, string $token, array $config, bool $forceRefresh = false): array {
    $inspectionsDir = __DIR__ . '/cache/inspections';
    if (!is_dir($inspectionsDir)) {
        @mkdir($inspectionsDir, 0755, true);
    }

    $cleanRepoName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $repoName);
    $cacheFile = $inspectionsDir . '/' . $cleanRepoName . '.json';

    if (!$forceRefresh && file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) && isset($decoded['database'])) {
            if (empty($pushedAt) || empty($decoded['pushed_at']) || $decoded['pushed_at'] === $pushedAt) {
                $decoded['cached'] = true;
                return $decoded;
            }
        }
    }

    $targetBranch = !empty($branch) ? $branch : 'main';
    $treeEndpoint = sprintf('/repos/%s/%s/git/trees/%s?recursive=1', urlencode($owner), urlencode($repoName), urlencode($targetBranch));
    $treeRes = make_github_request($treeEndpoint, $token, $config);

    if ($treeRes['status'] === 404 && $targetBranch === 'main') {
        $targetBranch = 'master';
        $treeEndpoint = sprintf('/repos/%s/%s/git/trees/%s?recursive=1', urlencode($owner), urlencode($repoName), urlencode($targetBranch));
        $treeRes = make_github_request($treeEndpoint, $token, $config);
    }

    if ($treeRes['status'] !== 200) {
        return [
            'repo' => $repoName,
            'pushed_at' => $pushedAt,
            'inspected_at' => date('c'),
            'cached' => false,
            'database' => ['detected' => false, 'type' => 'No', 'evidence' => ''],
            'auth' => ['detected' => false, 'evidence' => ''],
            'tech_stack' => ['name' => 'None', 'framework' => 'None', 'runtime' => 'None', 'evidence' => 'Tree inaccessible or empty'],
        ];
    }

    $treeData = json_decode($treeRes['body'], true);
    $treeList = is_array($treeData) && isset($treeData['tree']) && is_array($treeData['tree']) ? $treeData['tree'] : [];

    $filePaths = [];
    $hasPackageJson = false;
    $hasComposerJson = false;

    foreach ($treeList as $item) {
        $p = $item['path'] ?? '';
        if ($p) {
            $filePaths[] = $p;
            if ($p === 'package.json') $hasPackageJson = true;
            if ($p === 'composer.json') $hasComposerJson = true;
        }
    }

    $packageDeps = [];
    if ($hasPackageJson) {
        $pkgRes = make_github_request(sprintf('/repos/%s/%s/contents/package.json', urlencode($owner), urlencode($repoName)), $token, $config);
        if ($pkgRes['status'] === 200) {
            $pkgData = json_decode($pkgRes['body'], true);
            if (!empty($pkgData['content'])) {
                $pkgJson = json_decode(base64_decode($pkgData['content']), true);
                if (is_array($pkgJson)) {
                    $packageDeps = array_merge(array_keys($pkgJson['dependencies'] ?? []), array_keys($pkgJson['devDependencies'] ?? []));
                }
            }
        }
    }

    $composerDeps = [];
    if ($hasComposerJson) {
        $compRes = make_github_request(sprintf('/repos/%s/%s/contents/composer.json', urlencode($owner), urlencode($repoName)), $token, $config);
        if ($compRes['status'] === 200) {
            $compData = json_decode($compRes['body'], true);
            if (!empty($compData['content'])) {
                $compJson = json_decode(base64_decode($compData['content']), true);
                if (is_array($compJson)) {
                    $composerDeps = array_merge(array_keys($compJson['require'] ?? []), array_keys($compJson['require-dev'] ?? []));
                }
            }
        }
    }

    $allDeps = array_map('strtolower', array_merge($packageDeps, $composerDeps));

    // Database Detection
    $dbDetected = false;
    $dbType = 'No';
    $dbEvidence = '';

    if (in_array('@prisma/client', $allDeps) || in_array('prisma', $allDeps)) {
        $dbDetected = true; $dbType = 'Prisma'; $dbEvidence = 'Prisma ORM dependency';
    } elseif (in_array('drizzle-orm', $allDeps)) {
        $dbDetected = true; $dbType = 'Drizzle'; $dbEvidence = 'Drizzle ORM dependency';
    } elseif (in_array('better-sqlite3', $allDeps) || in_array('sqlite3', $allDeps)) {
        $dbDetected = true; $dbType = 'SQLite'; $dbEvidence = 'SQLite driver dependency';
    } elseif (in_array('@supabase/supabase-js', $allDeps) || in_array('supabase', $allDeps)) {
        $dbDetected = true; $dbType = 'Supabase'; $dbEvidence = 'Supabase client library';
    } elseif (in_array('firebase', $allDeps) || in_array('firebase-admin', $allDeps)) {
        $dbDetected = true; $dbType = 'Firebase'; $dbEvidence = 'Firebase / Firestore dependency';
    } elseif (in_array('mongoose', $allDeps) || in_array('mongodb', $allDeps)) {
        $dbDetected = true; $dbType = 'MongoDB'; $dbEvidence = 'MongoDB / Mongoose driver';
    } elseif (in_array('pg', $allDeps) || in_array('postgres', $allDeps)) {
        $dbDetected = true; $dbType = 'PostgreSQL'; $dbEvidence = 'PostgreSQL client driver';
    } elseif (in_array('mysql', $allDeps) || in_array('mysql2', $allDeps)) {
        $dbDetected = true; $dbType = 'MySQL'; $dbEvidence = 'MySQL client driver';
    }

    if (!$dbDetected) {
        foreach ($filePaths as $path) {
            $lower = strtolower($path);
            if (str_ends_with($lower, '.sql')) {
                $dbDetected = true; $dbType = 'SQL Script'; $dbEvidence = 'File: ' . $path; break;
            } elseif (str_contains($lower, 'sqlite')) {
                $dbDetected = true; $dbType = 'SQLite'; $dbEvidence = 'File: ' . $path; break;
            } elseif (str_contains($lower, 'schema.prisma')) {
                $dbDetected = true; $dbType = 'Prisma'; $dbEvidence = 'Prisma Schema file'; break;
            }
        }
    }

    // Auth Detection
    $authDetected = false;
    $authEvidence = '';

    if (in_array('next-auth', $allDeps) || in_array('@auth/core', $allDeps)) {
        $authDetected = true; $authEvidence = 'NextAuth.js dependency';
    } elseif (in_array('@clerk/nextjs', $allDeps) || in_array('@clerk/clerk-sdk-node', $allDeps)) {
        $authDetected = true; $authEvidence = 'Clerk Authentication';
    } elseif (in_array('passport', $allDeps)) {
        $authDetected = true; $authEvidence = 'Passport.js';
    } elseif (in_array('jsonwebtoken', $allDeps) || in_array('jose', $allDeps)) {
        $authDetected = true; $authEvidence = 'JWT Token library';
    } else {
        foreach ($filePaths as $path) {
            $lower = strtolower($path);
            if (preg_match('/(^|\/)(auth|login|signin|register)\.(js|ts|php|jsx|tsx)$/i', $lower)) {
                $authDetected = true; $authEvidence = 'Auth file: ' . $path; break;
            }
        }
    }

    // Tech Stack / Framework Detection
    $stackName = 'Generic';
    $stackFramework = 'None';
    $stackRuntime = 'Unknown';
    $stackEvidence = '';

    if (in_array('next', $allDeps)) {
        $stackName = 'Next.js'; $stackFramework = 'Next.js'; $stackRuntime = 'Node.js'; $stackEvidence = 'Next.js dependency';
    } elseif (in_array('react', $allDeps)) {
        $stackName = 'React'; $stackFramework = 'React SPA'; $stackRuntime = 'Node.js'; $stackEvidence = 'React library';
    } elseif (in_array('express', $allDeps)) {
        $stackName = 'Express'; $stackFramework = 'Express.js'; $stackRuntime = 'Node.js'; $stackEvidence = 'Express server';
    } elseif ($hasComposerJson) {
        $stackName = 'PHP / Composer'; $stackFramework = 'PHP Backend'; $stackRuntime = 'PHP'; $stackEvidence = 'Composer manifest';
    } elseif ($hasPackageJson) {
        $stackName = 'Node.js'; $stackFramework = 'Vanilla Node'; $stackRuntime = 'Node.js'; $stackEvidence = 'package.json';
    }

    $result = [
        'repo' => $repoName,
        'pushed_at' => $pushedAt,
        'inspected_at' => date('c'),
        'cached' => false,
        'database' => ['detected' => $dbDetected, 'type' => $dbType, 'evidence' => $dbEvidence],
        'auth' => ['detected' => $authDetected, 'evidence' => $authEvidence],
        'tech_stack' => ['name' => $stackName, 'framework' => $stackFramework, 'runtime' => $stackRuntime, 'evidence' => $stackEvidence],
    ];

    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_SLASHES));
    return $result;
}

// =============================================================================
// Resource Router Execution
// =============================================================================

try {
    // -------------------------------------------------------------------------
    // Resource 1: STATUS
    // -------------------------------------------------------------------------
    if ($resource === 'status') {
        require_method('GET');
        global $config;
        $token = trim((string) ($config['github_token'] ?? ''));
        $hasToken = !empty($token) && $token !== 'YOUR_GITHUB_PERSONAL_ACCESS_TOKEN_HERE';
        $userId = current_user_id();

        json_response([
            'ok' => true,
            'system' => 'PolymerOps Master Vault',
            'version' => '1.0.0-phase1',
            'dbAvailable' => db_available(),
            'githubConfigured' => $hasToken,
            'authenticated' => ($userId !== null),
            'userId' => $userId,
            'csrfToken' => csrf_token(),
            'serverTime' => gmdate('c'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Resource 2: REPOS (GitHub Explorer)
    // -------------------------------------------------------------------------
    if ($resource === 'repos') {
        // Action: Update Repository Metadata (Database, Auth, AI Dev Tool, Prompt Tool)
        if ($action === 'metadata') {
            require_method('POST', 'PATCH');
            $userId = current_user_id();
            $data = read_json_body();
            $repoId = (int) ($data['repositoryId'] ?? 0);
            $detectedDb = isset($data['detectedDb']) ? trim((string) $data['detectedDb']) : null;
            $detectedAuth = isset($data['detectedAuth']) ? trim((string) $data['detectedAuth']) : null;
            $assignedAiTool = isset($data['assignedAiTool']) ? trim((string) $data['assignedAiTool']) : null;
            $assignedPromptTool = isset($data['assignedPromptTool']) ? trim((string) $data['assignedPromptTool']) : null;
            $repoName = trim((string) ($data['name'] ?? ''));

            if ($repoId <= 0) {
                json_response(['ok' => false, 'error' => 'Valid repository ID required.', 'code' => 'PARAM_MISSING'], 422);
            }

            if ($userId !== null && db_available()) {
                require_csrf();
                $pdo = db();
                $rName = $repoName !== '' ? $repoName : 'repo-' . $repoId;
                $insRepo = $pdo->prepare('INSERT INTO repositories (id, user_id, name, full_name, owner_login, html_url, clone_url, ssh_url, last_synced_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) 
                    ON DUPLICATE KEY UPDATE name = VALUES(name)');
                $insRepo->execute([
                    $repoId, 
                    $userId, 
                    $rName, 
                    $rName, 
                    'owner', 
                    'https://github.com/' . $rName, 
                    'https://github.com/' . $rName . '.git', 
                    'git@github.com:' . $rName . '.git'
                ]);

                $upsert = $pdo->prepare('INSERT INTO repository_metadata (repository_id, user_id, detected_db, detected_auth, assigned_ai_tool, assigned_prompt_tool, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) 
                    ON DUPLICATE KEY UPDATE 
                    detected_db = COALESCE(VALUES(detected_db), detected_db),
                    detected_auth = COALESCE(VALUES(detected_auth), detected_auth),
                    assigned_ai_tool = COALESCE(VALUES(assigned_ai_tool), assigned_ai_tool),
                    assigned_prompt_tool = COALESCE(VALUES(assigned_prompt_tool), assigned_prompt_tool),
                    updated_at = CURRENT_TIMESTAMP');
                $upsert->execute([$repoId, $userId, $detectedDb, $detectedAuth, $assignedAiTool, $assignedPromptTool]);

                json_response(['ok' => true, 'persisted' => true]);
            }

            json_response(['ok' => true, 'persisted' => false, 'notice' => 'Persisted locally in browser.']);
        }

        global $config;
        $token = trim((string) ($config['github_token'] ?? ''));
        if (empty($token) || $token === 'YOUR_GITHUB_PERSONAL_ACCESS_TOKEN_HERE') {
            json_response([
                'ok' => false,
                'error' => 'GitHub Personal Access Token is not configured. Add your token to config.php or set GITHUB_TOKEN.',
                'code' => 'TOKEN_REQUIRED'
            ], 401);
        }

        // Action: Single Repo AST Tree Inspection
        if ($action === 'inspect') {
            require_method('GET');
            $repoName = trim((string) ($_GET['repo'] ?? ''));
            if ($repoName === '') {
                json_response(['ok' => false, 'error' => 'Missing repo parameter.', 'code' => 'PARAM_MISSING'], 400);
            }
            $owner = trim((string) ($_GET['owner'] ?? ''));
            $branch = trim((string) ($_GET['branch'] ?? 'main'));
            $pushedAt = trim((string) ($_GET['pushed_at'] ?? ''));
            $forceRefresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');

            if ($owner === '') {
                $userRes = make_github_request('/user', $token, $config);
                $userData = json_decode($userRes['body'], true);
                $owner = $userData['login'] ?? '';
            }

            $inspection = inspect_repository_ast($owner, $repoName, $branch, $pushedAt, $token, $config, $forceRefresh);
            json_response(['ok' => true, 'inspection' => $inspection]);
        }

        // Action: Fetch All Repositories with Pagination, Caching and Metadata Merge
        require_method('GET');
        $userId = current_user_id();
        $forceRefresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');
        $cacheDir = __DIR__ . '/cache';
        $cacheFile = $cacheDir . '/repos_cache.json';
        $cacheEnabled = !empty($config['cache_enabled']);
        $cacheTtl = (int) ($config['cache_ttl'] ?? 300);

        // Helper to attach metadata to repositories
        $attachMetadata = function(array $repos) use ($userId): array {
            if ($userId === null || !db_available()) {
                return $repos;
            }
            try {
                $pdo = db();
                $mStmt = $pdo->prepare('SELECT repository_id, detected_db, detected_auth, assigned_ai_tool, assigned_prompt_tool FROM repository_metadata WHERE user_id = ?');
                $mStmt->execute([$userId]);
                $map = [];
                while ($row = $mStmt->fetch()) {
                    $map[(int) $row['repository_id']] = [
                        'detected_db' => $row['detected_db'] ?? null,
                        'detected_auth' => $row['detected_auth'] ?? null,
                        'assigned_ai_tool' => $row['assigned_ai_tool'] ?? null,
                        'assigned_prompt_tool' => $row['assigned_prompt_tool'] ?? null,
                    ];
                }
                foreach ($repos as &$r) {
                    $r['metadata'] = $map[(int) $r['id']] ?? null;
                }
            } catch (Throwable $_) {}
            return $repos;
        };

        if ($cacheEnabled && !$forceRefresh && file_exists($cacheFile)) {
            $mtime = filemtime($cacheFile);
            if ((time() - $mtime) < $cacheTtl) {
                $cachedPayload = json_decode((string) file_get_contents($cacheFile), true);
                if (is_array($cachedPayload) && isset($cachedPayload['repositories'])) {
                    $cachedPayload['cached'] = true;
                    $cachedPayload['cached_age_seconds'] = time() - $mtime;
                    $cachedPayload['last_sync_timestamp'] = gmdate('c', $mtime);
                    $cachedPayload['repositories'] = $attachMetadata($cachedPayload['repositories']);
                    json_response($cachedPayload);
                }
            }
        }

        // 1. Fetch User Profile
        $userRes = make_github_request('/user', $token, $config);
        if ($userRes['status'] !== 200) {
            json_response(['ok' => false, 'error' => 'GitHub API authentication failed.', 'rateLimit' => parse_rate_limits($userRes['headers'])], $userRes['status']);
        }
        $userData = json_decode($userRes['body'], true);
        $userProfile = [
            'login' => $userData['login'] ?? 'Unknown',
            'name' => $userData['name'] ?? $userData['login'] ?? 'GitHub User',
            'avatar_url' => $userData['avatar_url'] ?? '',
            'html_url' => $userData['html_url'] ?? '',
            'public_repos' => $userData['public_repos'] ?? 0,
            'total_private_repos' => $userData['total_private_repos'] ?? 0,
        ];

        // 2. Cascade Fetch All Pages
        $perPage = min(100, max(1, (int) ($config['per_page'] ?? 100)));
        $affiliation = (string) ($config['affiliation'] ?? 'owner');
        $sort = (string) ($config['sort'] ?? 'updated');
        $direction = (string) ($config['direction'] ?? 'desc');

        $allRepos = [];
        $page = 1;
        $maxPages = 50;
        $lastHeaders = [];

        while ($page <= $maxPages) {
            $endpoint = sprintf('/user/repos?per_page=%d&affiliation=%s&sort=%s&direction=%s&page=%d', $perPage, urlencode($affiliation), urlencode($sort), urlencode($direction), $page);
            $res = make_github_request($endpoint, $token, $config);
            $lastHeaders = $res['headers'];

            if ($res['status'] !== 200) break;
            $items = json_decode($res['body'], true);
            if (!is_array($items) || empty($items)) break;

            foreach ($items as $r) {
                $allRepos[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'full_name' => $r['full_name'],
                    'description' => $r['description'] ?? '',
                    'private' => (bool) ($r['private'] ?? false),
                    'visibility' => $r['visibility'] ?? ($r['private'] ? 'private' : 'public'),
                    'fork' => (bool) ($r['fork'] ?? false),
                    'html_url' => $r['html_url'],
                    'homepage' => !empty($r['homepage']) ? trim($r['homepage']) : null,
                    'clone_url' => $r['clone_url'],
                    'ssh_url' => $r['ssh_url'],
                    'default_branch' => $r['default_branch'] ?? 'main',
                    'language' => $r['language'] ?? null,
                    'stargazers_count' => (int) ($r['stargazers_count'] ?? 0),
                    'forks_count' => (int) ($r['forks_count'] ?? 0),
                    'open_issues_count' => (int) ($r['open_issues_count'] ?? 0),
                    'size' => (int) ($r['size'] ?? 0),
                    'archived' => (bool) ($r['archived'] ?? false),
                    'created_at' => $r['created_at'] ?? '',
                    'updated_at' => $r['updated_at'] ?? '',
                    'pushed_at' => $r['pushed_at'] ?? '',
                ];
            }

            $linkHeader = $res['headers']['link'] ?? '';
            if (!str_contains($linkHeader, 'rel="next"') || count($items) < $perPage) break;
            $page++;
        }

        $nowIso = gmdate('c');
        $responsePayload = [
            'ok' => true,
            'user' => $userProfile,
            'stats' => [
                'total' => count($allRepos),
                'public' => count(array_filter($allRepos, fn($x) => !$x['private'])),
                'private' => count(array_filter($allRepos, fn($x) => $x['private'])),
                'forks' => count(array_filter($allRepos, fn($x) => $x['fork'])),
            ],
            'rate_limit' => parse_rate_limits($lastHeaders),
            'cached' => false,
            'cached_at' => $nowIso,
            'last_sync_timestamp' => $nowIso,
            'repositories' => $allRepos,
        ];

        if ($cacheEnabled) {
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            @file_put_contents($cacheFile, json_encode($responsePayload, JSON_UNESCAPED_SLASHES));
        }

        $responsePayload['repositories'] = $attachMetadata($responsePayload['repositories']);
        json_response($responsePayload);
    }

    // -------------------------------------------------------------------------
    // Resource 3: VAULT (Credential Management Engine)
    // -------------------------------------------------------------------------
    if ($resource === 'vault') {
        $userId = require_auth();
        $pdo = db(true);
        $sub = (string) ($_GET['sub'] ?? 'all');
        $criticalUnlocked = (int) ($_SESSION['critical_unlocked_until'] ?? 0) > time();

        // 3.1 Create or Update Account
        if ($action === 'account') {
            if ($method === 'POST') {
                require_csrf();
                $data = read_json_body();
                $accountId = !empty($data['id']) ? trim((string) $data['id']) : uuid_v4();
                $sectorId = !empty($data['sectorId']) ? trim((string) $data['sectorId']) : null;
                $title = trim((string) ($data['title'] ?? ''));
                $url = !empty($data['url']) ? trim((string) $data['url']) : null;
                $authType = (string) ($data['authType'] ?? 'CREDENTIALS');
                $logicRule = (string) ($data['logicRule'] ?? 'CUSTOM');
                $customUser = !empty($data['customUsername']) ? trim((string) $data['customUsername']) : null;
                $customPass = vault_encrypt(!empty($data['customPassword']) ? (string) $data['customPassword'] : null);
                $category = (string) ($data['category'] ?? 'GENERAL');
                $mobileNumber = !empty($data['mobileNumber']) ? trim((string) $data['mobileNumber']) : null;
                $sharedWithTeam = !empty($data['sharedWithTeam']) ? 1 : 0;
                $dbInfo = vault_encrypt(!empty($data['dbInfo']) ? (string) $data['dbInfo'] : null);
                $apiKey = vault_encrypt(!empty($data['apiKey']) ? (string) $data['apiKey'] : null);
                $notes = !empty($data['notes']) ? trim((string) $data['notes']) : null;
                $linkedRepoId = !empty($data['linkedRepoId']) ? (int) $data['linkedRepoId'] : 0;

                if ($title === '') {
                    json_response(['ok' => false, 'error' => 'Account title is required.'], 422);
                }

                $upsertAccount = $pdo->prepare('INSERT INTO accounts 
                    (id, user_id, sector_id, title, url, auth_type, logic_rule, custom_username, custom_password, category, mobile_number, shared_with_team, db_info, api_key, notes, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) 
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
                $upsertAccount->execute([
                    $accountId, $userId, $sectorId, $title, $url, $authType, $logicRule, $customUser, $customPass,
                    $category, $mobileNumber, $sharedWithTeam, $dbInfo, $apiKey, $notes
                ]);

                // Manage repository link if requested
                if ($linkedRepoId > 0) {
                    $linkId = uuid_v4();
                    $linkStmt = $pdo->prepare('INSERT INTO repo_vault_links (id, user_id, repository_id, account_id, link_nature) 
                        VALUES (?, ?, ?, ?, "PRIMARY_HOSTING") 
                        ON DUPLICATE KEY UPDATE repository_id = VALUES(repository_id), link_nature = VALUES(link_nature)');
                    $linkStmt->execute([$linkId, $userId, $linkedRepoId, $accountId]);
                } elseif (isset($data['linkedRepoId']) && (int) $data['linkedRepoId'] === 0) {
                    $delLink = $pdo->prepare('DELETE FROM repo_vault_links WHERE user_id = ? AND account_id = ?');
                    $delLink->execute([$userId, $accountId]);
                }

                json_response(['ok' => true, 'accountId' => $accountId], 200);
            }

            if ($method === 'DELETE') {
                require_csrf();
                $id = trim((string) ($_GET['id'] ?? ''));
                if ($id === '') {
                    json_response(['ok' => false, 'error' => 'Account ID is required.'], 422);
                }
                $del = $pdo->prepare('DELETE FROM accounts WHERE id = ? AND user_id = ?');
                $del->execute([$id, $userId]);
                json_response(['ok' => true]);
            }
        }

        // 3.2 Create or Update Access Group / Sector
        if ($action === 'sector') {
            if ($method === 'POST') {
                require_csrf();
                $data = read_json_body();
                $sectorId = !empty($data['id']) ? trim((string) $data['id']) : uuid_v4();
                $name = trim((string) ($data['name'] ?? ''));
                $defaultUser = !empty($data['defaultUsername']) ? trim((string) $data['defaultUsername']) : null;
                $defaultPass = vault_encrypt(!empty($data['defaultPassword']) ? (string) $data['defaultPassword'] : null);
                $isSharedVault = !empty($data['isSharedVault']) ? 1 : 0;

                if ($name === '') {
                    json_response(['ok' => false, 'error' => 'Access Group name is required.'], 422);
                }

                $upsertSector = $pdo->prepare('INSERT INTO sectors 
                    (id, user_id, name, default_username, default_password, is_shared_vault) 
                    VALUES (?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    default_username = VALUES(default_username),
                    default_password = VALUES(default_password),
                    is_shared_vault = VALUES(is_shared_vault)');
                $upsertSector->execute([$sectorId, $userId, $name, $defaultUser, $defaultPass, $isSharedVault]);

                json_response(['ok' => true, 'sectorId' => $sectorId], 200);
            }

            if ($method === 'DELETE') {
                require_csrf();
                $id = trim((string) ($_GET['id'] ?? ''));
                if ($id === '') {
                    json_response(['ok' => false, 'error' => 'Sector ID is required.'], 422);
                }
                $del = $pdo->prepare('DELETE FROM sectors WHERE id = ? AND user_id = ?');
                $del->execute([$id, $userId]);
                json_response(['ok' => true]);
            }
        }

        // 3.3 Credential Pool Item Operations
        if ($action === 'pool') {
            if ($method === 'GET') {
                $stmt = $pdo->prepare('SELECT id, item_type, value_enc, source, use_count, last_used_at, created_at FROM credential_pool_items WHERE user_id = ? ORDER BY use_count DESC, created_at DESC');
                $stmt->execute([$userId]);
                $items = array_map(function($r) {
                    return [
                        'id' => $r['id'],
                        'itemType' => $r['item_type'],
                        'value' => vault_decrypt($r['value_enc'] ?? null) ?? '',
                        'source' => $r['source'],
                        'useCount' => (int) $r['use_count'],
                        'lastUsedAt' => $r['last_used_at'],
                        'createdAt' => $r['created_at'],
                    ];
                }, $stmt->fetchAll());
                json_response(['ok' => true, 'pool' => $items]);
            }

            if ($method === 'POST') {
                require_csrf();
                $data = read_json_body();
                $val = trim((string) ($data['value'] ?? ''));
                $itemType = strtoupper(trim((string) ($data['itemType'] ?? 'PASSWORD')));
                $source = (string) ($data['source'] ?? 'MANUAL');

                if ($val === '') {
                    json_response(['ok' => false, 'error' => 'Item value cannot be empty.'], 422);
                }

                $id = uuid_v4();
                $valHash = hash('sha256', $val);
                $valEnc = vault_encrypt($val);

                $ins = $pdo->prepare('INSERT INTO credential_pool_items 
                    (id, user_id, item_type, value_enc, value_hash, source, use_count, last_used_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP) 
                    ON DUPLICATE KEY UPDATE 
                    use_count = use_count + 1,
                    last_used_at = CURRENT_TIMESTAMP');
                $ins->execute([$id, $userId, $itemType, $valEnc, $valHash, $source]);

                json_response(['ok' => true, 'itemId' => $id]);
            }

            if ($method === 'DELETE') {
                require_csrf();
                $id = trim((string) ($_GET['id'] ?? ''));
                $del = $pdo->prepare('DELETE FROM credential_pool_items WHERE id = ? AND user_id = ?');
                $del->execute([$id, $userId]);
                json_response(['ok' => true]);
            }
        }

        // 3.4 Rotate Shared Passwords
        if ($action === 'rotate_shared') {
            require_method('POST');
            require_csrf();
            $data = read_json_body();
            $newPassword = (string) ($data['newPassword'] ?? '');
            $sectorId = !empty($data['sectorId']) ? trim((string) $data['sectorId']) : null;

            if ($newPassword === '') {
                json_response(['ok' => false, 'error' => 'New password cannot be empty.'], 422);
            }

            $enc = vault_encrypt($newPassword);

            if ($sectorId !== null) {
                $upSector = $pdo->prepare('UPDATE sectors SET default_password = ? WHERE id = ? AND user_id = ?');
                $upSector->execute([$enc, $sectorId, $userId]);
            }

            // Update user shared password
            $upUser = $pdo->prepare('UPDATE users SET shared_pass = ? WHERE id = ?');
            $upUser->execute([$enc, $userId]);

            // Update all accounts set to SHARED_PASS logic rule or shared with team
            $upAcc = $pdo->prepare('UPDATE accounts SET custom_password = ? WHERE user_id = ? AND (logic_rule = "SHARED_PASS" OR shared_with_team = 1)');
            $upAcc->execute([$enc, $userId]);

            json_response(['ok' => true, 'message' => 'Shared passwords updated successfully.']);
        }

        // 3.5 Full Vault State Query (default sub=all)
        if ($sub === 'all') {
            require_method('GET');

            // Fetch sectors
            $sStmt = $pdo->prepare('SELECT id, name, default_username, default_password, is_shared_vault, created_at FROM sectors WHERE user_id = ? ORDER BY name ASC');
            $sStmt->execute([$userId]);
            $sectors = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'defaultUsername' => $r['default_username'] ?? '',
                    'defaultPassword' => vault_decrypt($r['default_password'] ?? null) ?? '',
                    'isSharedVault' => (bool) $r['is_shared_vault'],
                    'createdAt' => $r['created_at'] ?? null,
                ];
            }, $sStmt->fetchAll());

            // Fetch accounts
            $sql = 'SELECT * FROM accounts WHERE user_id = ?';
            if (!$criticalUnlocked) {
                $sql .= " AND category <> 'CRITICAL_DRIVE'";
            }
            $sql .= ' ORDER BY created_at DESC, title ASC';
            $aStmt = $pdo->prepare($sql);
            $aStmt->execute([$userId]);
            $accounts = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'serialNo' => (int) ($r['serial_no'] ?? 0),
                    'sectorId' => $r['sector_id'],
                    'title' => $r['title'],
                    'url' => $r['url'] ?? '',
                    'authType' => $r['auth_type'],
                    'logicRule' => $r['logic_rule'],
                    'customUsername' => $r['custom_username'] ?? '',
                    'customPassword' => vault_decrypt($r['custom_password'] ?? null) ?? '',
                    'category' => $r['category'],
                    'mobileNumber' => $r['mobile_number'] ?? '',
                    'sharedWithTeam' => (bool) $r['shared_with_team'],
                    'dbInfo' => vault_decrypt($r['db_info'] ?? null) ?? '',
                    'apiKey' => vault_decrypt($r['api_key'] ?? null) ?? '',
                    'notes' => $r['notes'] ?? '',
                    'createdAt' => $r['created_at'] ?? null,
                ];
            }, $aStmt->fetchAll());

            // Fetch credential pool
            $pStmt = $pdo->prepare('SELECT id, item_type, value_enc, source, use_count, last_used_at, created_at FROM credential_pool_items WHERE user_id = ? ORDER BY use_count DESC, created_at DESC LIMIT 50');
            $pStmt->execute([$userId]);
            $pool = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'itemType' => $r['item_type'],
                    'value' => vault_decrypt($r['value_enc'] ?? null) ?? '',
                    'source' => $r['source'],
                    'useCount' => (int) $r['use_count'],
                    'lastUsedAt' => $r['last_used_at'],
                ];
            }, $pStmt->fetchAll());

            // Fetch Smart Links
            $lStmt = $pdo->prepare('SELECT l.id, l.repository_id, l.account_id, l.link_nature, a.title AS account_title, a.url AS account_url, r.name AS repo_name 
                FROM repo_vault_links l 
                INNER JOIN accounts a ON a.id = l.account_id 
                LEFT JOIN repositories r ON r.id = l.repository_id 
                WHERE l.user_id = ?');
            $lStmt->execute([$userId]);
            $links = $lStmt->fetchAll();

            json_response([
                'ok' => true,
                'vault' => [
                    'sectors' => $sectors,
                    'accounts' => $accounts,
                    'credentialPool' => $pool,
                    'links' => $links,
                    'criticalUnlocked' => $criticalUnlocked,
                ],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Resource 4: LINKS (Smart Link Synergy)
    // -------------------------------------------------------------------------
    if ($resource === 'links') {
        if (!db_available()) {
            json_response(['ok' => true, 'links' => [], 'notice' => 'Database offline: Smart Links disabled.']);
        }
        $userId = require_auth();
        $pdo = db();

        if ($method === 'GET') {
            $stmt = $pdo->prepare('SELECT l.id, l.repository_id, l.account_id, l.link_nature, a.title AS account_title, a.url AS account_url FROM repo_vault_links l INNER JOIN accounts a ON a.id = l.account_id WHERE l.user_id = ?');
            $stmt->execute([$userId]);
            json_response(['ok' => true, 'links' => $stmt->fetchAll()]);
        }

        if ($method === 'POST') {
            require_csrf();
            $data = read_json_body();
            $repoId = (int) ($data['repositoryId'] ?? 0);
            $accountId = trim((string) ($data['accountId'] ?? ''));
            $nature = (string) ($data['linkNature'] ?? 'PRIMARY_HOSTING');

            if ($repoId <= 0 || $accountId === '') {
                json_response(['ok' => false, 'error' => 'Repository ID and Account ID are required.'], 422);
            }

            $id = uuid_v4();
            $ins = $pdo->prepare('INSERT INTO repo_vault_links (id, user_id, repository_id, account_id, link_nature) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE link_nature = VALUES(link_nature)');
            $ins->execute([$id, $userId, $repoId, $accountId, $nature]);

            json_response(['ok' => true, 'linkId' => $id], 201);
        }

        if ($method === 'DELETE') {
            require_csrf();
            $id = (string) ($_GET['id'] ?? '');
            $del = $pdo->prepare('DELETE FROM repo_vault_links WHERE id = ? AND user_id = ?');
            $del->execute([$id, $userId]);
            json_response(['ok' => true]);
        }
    }

    // -------------------------------------------------------------------------
    // Resource 5: NOTES (Work Notes & Progress Engine)
    // -------------------------------------------------------------------------
    if ($resource === 'notes') {
        $userId = current_user_id();
        $repoId = (int) ($_GET['repo_id'] ?? 0);

        if ($method === 'GET') {
            if ($userId !== null && db_available() && $repoId > 0) {
                $stmt = db()->prepare('SELECT work_notes_completed, work_notes_next_steps FROM repository_metadata WHERE repository_id = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$repoId, $userId]);
                $row = $stmt->fetch();
                if ($row) {
                    json_response([
                        'ok' => true,
                        'completed' => (string) ($row['work_notes_completed'] ?? ''),
                        'nextSteps' => (string) ($row['work_notes_next_steps'] ?? ''),
                        'source' => 'database',
                    ]);
                }
            }
            json_response(['ok' => true, 'completed' => '', 'nextSteps' => '', 'source' => 'client']);
        }

        if ($method === 'POST' || $method === 'PATCH') {
            $data = read_json_body();
            $completed = (string) ($data['completed'] ?? '');
            $nextSteps = (string) ($data['nextSteps'] ?? '');

            if ($userId !== null && db_available() && $repoId > 0) {
                require_csrf();
                $upsert = db()->prepare('INSERT INTO repository_metadata (repository_id, user_id, work_notes_completed, work_notes_next_steps) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE work_notes_completed = VALUES(work_notes_completed), work_notes_next_steps = VALUES(work_notes_next_steps), updated_at = CURRENT_TIMESTAMP');
                $upsert->execute([$repoId, $userId, $completed, $nextSteps]);
                json_response(['ok' => true, 'persisted' => true]);
            }
            json_response(['ok' => true, 'persisted' => false, 'notice' => 'Persisted locally in browser.']);
        }
    }

    json_response(['ok' => false, 'error' => 'Unrecognized API resource.', 'code' => 'INVALID_RESOURCE'], 404);
} catch (Throwable $e) {
    error_log('PolymerOps Master API Exception: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'An unexpected server error occurred: ' . $e->getMessage()], 500);
}
