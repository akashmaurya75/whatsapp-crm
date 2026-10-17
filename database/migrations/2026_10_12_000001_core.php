<?php

declare(strict_types=1);

/*
 * Platform core: tenants, plans, RBAC, users, security & infrastructure tables.
 */
return [
    'CREATE TABLE subscription_plans (
        id {pk},
        code VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        price_cents INT NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT \'USD\',
        billing_interval VARCHAR(16) NOT NULL DEFAULT \'month\',
        max_agents INT NULL,
        max_whatsapp_accounts INT NULL,
        max_contacts INT NULL,
        features JSON NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',

    'CREATE TABLE tenants (
        id {pk},
        name VARCHAR(160) NOT NULL,
        slug VARCHAR(80) NOT NULL UNIQUE,
        status VARCHAR(16) NOT NULL DEFAULT \'active\',
        plan_id BIGINT UNSIGNED NULL REFERENCES subscription_plans(id) ON DELETE SET NULL,
        timezone VARCHAR(64) NOT NULL DEFAULT \'UTC\',
        suspended_at DATETIME NULL,
        suspended_reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',

    'CREATE TABLE subscriptions (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        plan_id BIGINT UNSIGNED NOT NULL REFERENCES subscription_plans(id),
        status VARCHAR(16) NOT NULL,
        trial_ends_at DATETIME NULL,
        current_period_end DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX subscriptions_tenant_idx ON subscriptions (tenant_id, status)',

    'CREATE TABLE permissions (
        id {pk},
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NOT NULL
    ){engine}',

    'CREATE TABLE roles (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (tenant_id, slug)
    ){engine}',

    'CREATE TABLE role_permissions (
        role_id BIGINT UNSIGNED NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
        permission_id BIGINT UNSIGNED NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
        PRIMARY KEY (role_id, permission_id)
    ){engine}',

    'CREATE TABLE users (
        id {pk},
        tenant_id BIGINT UNSIGNED NULL REFERENCES tenants(id) ON DELETE CASCADE,
        role_id BIGINT UNSIGNED NULL REFERENCES roles(id) ON DELETE SET NULL,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NULL,
        is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT \'active\',
        email_verified_at DATETIME NULL,
        totp_secret_enc TEXT NULL,
        totp_enabled_at DATETIME NULL,
        recovery_codes JSON NULL,
        session_version INT NOT NULL DEFAULT 1,
        invite_token_hash CHAR(64) NULL,
        invite_expires_at DATETIME NULL,
        reset_token_hash CHAR(64) NULL,
        reset_expires_at DATETIME NULL,
        last_login_at DATETIME NULL,
        last_login_ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX users_tenant_idx ON users (tenant_id, status)',

    'CREATE TABLE teams (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        name VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (tenant_id, name)
    ){engine}',

    'CREATE TABLE team_members (
        team_id BIGINT UNSIGNED NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
        user_id BIGINT UNSIGNED NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        PRIMARY KEY (team_id, user_id)
    ){engine}',

    'CREATE TABLE login_attempts (
        id {pk},
        email VARCHAR(191) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX login_attempts_email_idx ON login_attempts (email, created_at)',

    'CREATE TABLE audit_logs (
        id {pk},
        tenant_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(60) NULL,
        entity_id BIGINT UNSIGNED NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        meta JSON NULL,
        created_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX audit_logs_tenant_idx ON audit_logs (tenant_id, created_at)',

    'CREATE TABLE rate_limits (
        rl_key VARCHAR(191) NOT NULL PRIMARY KEY,
        hits INT NOT NULL,
        reset_at BIGINT NOT NULL
    ){engine}',

    'CREATE TABLE jobs (
        id {pk},
        queue VARCHAR(60) NOT NULL,
        payload TEXT NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        available_at BIGINT NOT NULL,
        reserved_at BIGINT NULL,
        reserved_by VARCHAR(64) NULL,
        created_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX jobs_queue_idx ON jobs (queue, reserved_at, available_at)',

    'CREATE TABLE failed_jobs (
        id {pk},
        queue VARCHAR(60) NOT NULL,
        payload TEXT NOT NULL,
        exception TEXT NOT NULL,
        failed_at DATETIME NOT NULL
    ){engine}',

    'CREATE TABLE api_tokens (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        user_id BIGINT UNSIGNED NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name VARCHAR(100) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        last_used_at DATETIME NULL,
        expires_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL
    ){engine}',

    'CREATE TABLE idempotency_keys (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        idem_key VARCHAR(191) NOT NULL,
        request_hash CHAR(64) NOT NULL,
        response_code INT NOT NULL,
        response_body TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE (tenant_id, idem_key)
    ){engine}',
];
