-- PolymerOps Master Vault - Unified Relational Schema (MySQL 8.0+)
-- Character Set: utf8mb4 / Collation: utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =============================================================================
-- 1. Identity, Users & Session Security
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    role ENUM('ADMIN','USER') NOT NULL DEFAULT 'USER',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    password_hash VARCHAR(255) NOT NULL,
    google_sub VARCHAR(255) NULL,
    pin_hash VARCHAR(255) NULL,
    memorized_pass TEXT NULL COMMENT 'AES-256-GCM encrypted',
    shared_pass TEXT NULL COMMENT 'AES-256-GCM encrypted',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vault_default_passwords (
    user_id BIGINT UNSIGNED NOT NULL,
    password_1 TEXT NULL COMMENT 'AES-256-GCM encrypted',
    password_2 TEXT NULL COMMENT 'AES-256-GCM encrypted',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_default_pass_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. Credential Sectors & Accounts Hierarchy
-- =============================================================================
CREATE TABLE IF NOT EXISTS sectors (
    id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    default_username VARCHAR(254) NULL,
    default_password TEXT NULL COMMENT 'AES-256-GCM encrypted',
    is_shared_vault TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sectors_user_id (user_id),
    KEY idx_sectors_user_name (user_id, name),
    CONSTRAINT fk_sectors_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
    id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    serial_no INT UNSIGNED NULL,
    sector_id CHAR(36) NULL,
    title VARCHAR(200) NOT NULL,
    url VARCHAR(2048) NULL,
    auth_type ENUM('CREDENTIALS','GOOGLE_AUTH','NAFAZ','OTP_SMS','GITHUB_AUTH','OTHER') NOT NULL DEFAULT 'CREDENTIALS',
    logic_rule ENUM('FULL_SECTOR','SECTOR_PASS_ONLY','SHARED_PASS','MEMORIZED_PASS','CUSTOM') NOT NULL DEFAULT 'CUSTOM',
    custom_username VARCHAR(254) NULL,
    custom_password TEXT NULL COMMENT 'AES-256-GCM encrypted',
    category ENUM('GENERAL','WORK','CRITICAL_DRIVE','OFFICIAL_GOV') NOT NULL DEFAULT 'GENERAL',
    mobile_number VARCHAR(50) NULL,
    shared_with_team TINYINT(1) NOT NULL DEFAULT 0,
    last_shared_date DATETIME NULL,
    db_info TEXT NULL COMMENT 'AES-256-GCM encrypted',
    api_key TEXT NULL COMMENT 'AES-256-GCM encrypted',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_accounts_user_id (user_id),
    UNIQUE KEY uq_accounts_user_serial (user_id, serial_no),
    KEY idx_accounts_sector_id (sector_id),
    KEY idx_accounts_user_category (user_id, category),
    KEY idx_accounts_user_shared (user_id, shared_with_team),
    KEY idx_accounts_user_auth (user_id, auth_type),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_accounts_sector FOREIGN KEY (sector_id) REFERENCES sectors(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. Access Model V3: Extended Graph Topology & Credentials Pool
-- =============================================================================
CREATE TABLE IF NOT EXISTS account_profiles (
    account_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(160) NULL,
    provider VARCHAR(60) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    is_identity_account TINYINT(1) NOT NULL DEFAULT 0,
    is_recovery_account TINYINT(1) NOT NULL DEFAULT 0,
    is_group_identity TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    KEY idx_account_profiles_user (user_id),
    KEY idx_account_profiles_status (user_id, status),
    CONSTRAINT fk_account_profiles_account FOREIGN KEY (account_id) REFERENCES accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_account_profiles_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_login_methods (
    id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id CHAR(36) NOT NULL,
    method_type VARCHAR(40) NOT NULL,
    identifier_type VARCHAR(30) NULL,
    identifier_value VARCHAR(254) NULL,
    secret_source VARCHAR(40) NOT NULL DEFAULT 'NONE',
    secret_value TEXT NULL COMMENT 'AES-256-GCM encrypted',
    via_account_id CHAR(36) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_methods_user (user_id),
    KEY idx_login_methods_account (account_id, is_primary, sort_order),
    KEY idx_login_methods_via (via_account_id),
    CONSTRAINT fk_login_methods_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_login_methods_account FOREIGN KEY (account_id) REFERENCES accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_login_methods_via FOREIGN KEY (via_account_id) REFERENCES accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_relationships (
    id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    source_account_id CHAR(36) NOT NULL,
    target_account_id CHAR(36) NOT NULL,
    relation_type VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_relationship (user_id, source_account_id, target_account_id, relation_type),
    KEY idx_relationship_source (user_id, source_account_id, relation_type),
    KEY idx_relationship_target (user_id, target_account_id, relation_type),
    CONSTRAINT fk_relationship_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_relationship_source FOREIGN KEY (source_account_id) REFERENCES accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_relationship_target FOREIGN KEY (target_account_id) REFERENCES accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_group_profiles (
    sector_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    default_identity_account_id CHAR(36) NULL,
    purpose VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sector_id),
    KEY idx_access_group_profiles_user (user_id),
    KEY idx_access_group_identity (default_identity_account_id),
    CONSTRAINT fk_access_group_profile_sector FOREIGN KEY (sector_id) REFERENCES sectors(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_access_group_profile_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_access_group_profile_identity FOREIGN KEY (default_identity_account_id) REFERENCES accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credential_pool_items (
    id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(20) NOT NULL,
    value_enc TEXT NOT NULL COMMENT 'AES-256-GCM encrypted',
    value_hash CHAR(64) NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pool_value (user_id, item_type, value_hash),
    KEY idx_pool_user_type (user_id, item_type, created_at),
    CONSTRAINT fk_pool_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. GitHub Master Explorer & Persistent Metadata Engine
-- =============================================================================
CREATE TABLE IF NOT EXISTS repositories (
    id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    owner_login VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 0,
    visibility VARCHAR(30) NOT NULL DEFAULT 'public',
    is_fork TINYINT(1) NOT NULL DEFAULT 0,
    html_url VARCHAR(2048) NOT NULL,
    homepage VARCHAR(2048) NULL,
    clone_url VARCHAR(2048) NOT NULL,
    ssh_url VARCHAR(2048) NOT NULL,
    default_branch VARCHAR(100) NOT NULL DEFAULT 'main',
    primary_language VARCHAR(80) NULL,
    stargazers_count INT UNSIGNED NOT NULL DEFAULT 0,
    forks_count INT UNSIGNED NOT NULL DEFAULT 0,
    open_issues_count INT UNSIGNED NOT NULL DEFAULT 0,
    size_kb INT UNSIGNED NOT NULL DEFAULT 0,
    created_at_github DATETIME NULL,
    updated_at_github DATETIME NULL,
    pushed_at_github DATETIME NULL,
    last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_repos_user (user_id),
    KEY idx_repos_name (user_id, name),
    CONSTRAINT fk_repos_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repository_metadata (
    repository_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    detected_db VARCHAR(50) NULL,
    detected_auth VARCHAR(50) NULL,
    assigned_ai_tool VARCHAR(50) NULL,
    assigned_prompt_tool VARCHAR(50) NULL,
    inspection_cache JSON NULL,
    inspected_pushed_at DATETIME NULL,
    work_notes_completed MEDIUMTEXT NULL,
    work_notes_next_steps MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (repository_id),
    KEY idx_repo_meta_user (user_id),
    CONSTRAINT fk_repo_meta_repo FOREIGN KEY (repository_id) REFERENCES repositories(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_repo_meta_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. Smart Link Synergy: Cross-Relational Vault & Repository Binding
-- =============================================================================
CREATE TABLE IF NOT EXISTS repo_vault_links (
    id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    account_id CHAR(36) NOT NULL,
    link_nature ENUM('PRIMARY_HOSTING','STAGING','PRODUCTION_DB','API_GATEWAY','SERVICE_ACCOUNT') NOT NULL DEFAULT 'PRIMARY_HOSTING',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_repo_account_link (repository_id, account_id),
    KEY idx_links_user (user_id),
    KEY idx_links_account (account_id),
    CONSTRAINT fk_rvl_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rvl_repo FOREIGN KEY (repository_id) REFERENCES repositories(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rvl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
