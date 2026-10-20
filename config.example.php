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
     * Google OAuth 2.0 Client Secret (optional for server-side code exchange)
     */
    'google_client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',

    /**
     * Google OAuth 2.0 Authorized Redirect URI
     */
    'google_redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: 'https://master.polymerops.com/auth.php?action=google_callback',

    /**
     * Allowed Google SSO emails (comma-separated or empty to allow any registered vault account)
     */
    'allowed_emails' => getenv('ALLOWED_EMAILS') ?: 'khaled.taha.pro@gmail.com',

    /**
     * Enable new user registration (Keep false for solo / private operations)
     */
    'allow_registration' => filter_var(getenv('ALLOW_REGISTRATION') ?: false, FILTER_VALIDATE_BOOLEAN),

    /**
     * Application Public Base URL
     */
    'app_url' => getenv('APP_URL') ?: 'https://master.polymerops.com',

    /**
     * Hostinger SMTP Outbound Mail Configuration
     */
    'smtp_enabled' => filter_var(getenv('SMTP_ENABLED') ?: true, FILTER_VALIDATE_BOOLEAN),
    'smtp_host' => getenv('SMTP_HOST') ?: 'smtp.hostinger.com',
    'smtp_port' => (int)(getenv('SMTP_PORT') ?: 465),
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'ssl', // 'ssl' (port 465) or 'tls' (port 587)
    'smtp_user' => getenv('SMTP_USER') ?: '',
    'smtp_pass' => getenv('SMTP_PASS') ?: '',
    'smtp_from' => getenv('SMTP_FROM') ?: 'noreply@polymerops.com',
    'smtp_from_name' => getenv('SMTP_FROM_NAME') ?: 'PolymerOps Master Vault',

    /**
     * Outbound email address for password reset dispatches (legacy fallback)
     */
    'reset_email_from' => getenv('RESET_EMAIL_FROM') ?: 'noreply@polymerops.com',

    /**
     * Dedicated security key to guard HTTP execution of tools/migrate.php
     * (Not required when running via CLI).
     */
    'migration_key' => getenv('POLYMER_MIGRATION_KEY') ?: 'CHANGE_TO_A_SECURE_MIGRATION_SECRET_KEY',

    // =========================================================================
    // 3. Optional Legacy Database Migration Source (tools/migrate.php)
    // =========================================================================
    'legacy_db_host' => getenv('POLYMER_LEGACY_DB_HOST') ?: 'localhost',
    'legacy_db_name' => getenv('POLYMER_LEGACY_DB_NAME') ?: '',
    'legacy_db_user' => getenv('POLYMER_LEGACY_DB_USER') ?: '',
    'legacy_db_pass' => getenv('POLYMER_LEGACY_DB_PASS') ?: '',
    'legacy_db_port' => (int)(getenv('POLYMER_LEGACY_DB_PORT') ?: 3306),
    'legacy_app_key' => getenv('POLYMER_LEGACY_APP_KEY') ?: '',

    // =========================================================================
    // 4. GitHub Explorer & Reconnaissance API
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
