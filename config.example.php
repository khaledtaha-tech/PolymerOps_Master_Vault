<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Unified Configuration Template
 * 
 * Target: https://github.com/khaledtaha-tech/PolymerOps_Master_Vault
 * 
 * INSTRUCTIONS:
 * 1. Copy this file to "config.php" (do NOT commit config.php):
 *    cp config.example.php config.php
 * 2. Configure your Database credentials, Vault Encryption Key, and GitHub PAT.
 */

return [
    // =========================================================================
    // 1. Relational Database Configuration (MySQL 8.0+)
    // =========================================================================
    'db_host' => getenv('POLYMER_DB_HOST') ?: 'localhost',
    'db_name' => getenv('POLYMER_DB_NAME') ?: 'polymerops_vault',
    'db_user' => getenv('POLYMER_DB_USER') ?: 'root',
    'db_pass' => getenv('POLYMER_DB_PASS') ?: '',
    'db_port' => (int)(getenv('POLYMER_DB_PORT') ?: 3306),
    'db_charset' => 'utf8mb4',

    // =========================================================================
    // 2. Vault Security & Cryptography (AES-256-GCM)
    // =========================================================================
    /**
     * Application master encryption key.
     * Must be a random, high-entropy string of at least 32 characters.
     * Keep this key strictly safe and permanent; lost keys make stored secrets irrecoverable.
     */
    'app_key' => getenv('POLYMER_APP_KEY') ?: 'CHANGE_TO_A_SECURE_RANDOM_SECRET_KEY_AT_LEAST_32_CHARS',

    /**
     * Session cookie name
     */
    'session_name' => 'polymerops_master_session',

    /**
     * Google Identity Services Client ID for Web SSO
     * Example: 1234567890-abcdefghijklmnopqrstuvwxyz.apps.googleusercontent.com
     */
    'google_client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',

    /**
     * Enable new user registration (Keep false for solo / private operations)
     */
    'allow_registration' => filter_var(getenv('ALLOW_REGISTRATION') ?: false, FILTER_VALIDATE_BOOLEAN),

    /**
     * Outbound email address for password reset dispatches
     */
    'reset_email_from' => getenv('RESET_EMAIL_FROM') ?: 'noreply@polymerops.internal',

    // =========================================================================
    // 3. GitHub Explorer & Reconnaissance API
    // =========================================================================
    /**
     * GitHub Personal Access Token (Classic with 'repo' scope or Fine-Grained token)
     */
    'github_token' => getenv('GITHUB_TOKEN') ?: 'YOUR_GITHUB_PERSONAL_ACCESS_TOKEN_HERE',

    /**
     * GitHub REST API Base URL
     */
    'api_base_url' => 'https://api.github.com',

    /**
     * Number of repositories per API page request (max 100 on GitHub REST API)
     */
    'per_page' => 100,

    /**
     * Repository affiliation: 'owner', 'owner,collaborator', or 'all'
     */
    'affiliation' => 'owner',

    /**
     * Default sorting field: 'updated', 'pushed', 'created', 'full_name'
     */
    'sort' => 'updated',

    /**
     * Default sort direction: 'desc' or 'asc'
     */
    'direction' => 'desc',

    /**
     * Local caching toggle to conserve GitHub API rate limits
     */
    'cache_enabled' => true,

    /**
     * Cache Time-To-Live in seconds (300 = 5 minutes)
     */
    'cache_ttl' => 300,

    /**
     * cURL connection & transfer timeout in seconds
     */
    'timeout' => 30,

    /**
     * User-Agent header matching GitHub API policy
     */
    'user_agent' => 'PolymerOps-Master-Vault/1.0 (+https://github.com/khaledtaha-tech/PolymerOps_Master_Vault)',
];
