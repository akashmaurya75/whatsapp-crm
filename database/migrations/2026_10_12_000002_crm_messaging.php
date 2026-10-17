<?php

declare(strict_types=1);

/*
 * CRM core + WhatsApp messaging tables. Every table carries tenant_id.
 */
return [
    'CREATE TABLE whatsapp_accounts (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        name VARCHAR(120) NOT NULL,
        provider VARCHAR(32) NOT NULL,
        phone_e164 VARCHAR(20) NULL,
        business_account_id VARCHAR(64) NULL,
        phone_number_id VARCHAR(64) NULL,
        base_url VARCHAR(255) NULL,
        credentials_enc TEXT NOT NULL,
        webhook_token CHAR(43) NOT NULL UNIQUE,
        capabilities JSON NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'pending\',
        last_synced_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        last_error_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX wa_accounts_tenant_idx ON whatsapp_accounts (tenant_id)',

    'CREATE TABLE contacts (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        name VARCHAR(160) NULL,
        phone_e164 VARCHAR(20) NOT NULL,
        email VARCHAR(191) NULL,
        company VARCHAR(160) NULL,
        city VARCHAR(120) NULL,
        address VARCHAR(255) NULL,
        source VARCHAR(60) NULL,
        lead_status VARCHAR(32) NOT NULL DEFAULT \'new\',
        owner_user_id BIGINT UNSIGNED NULL REFERENCES users(id) ON DELETE SET NULL,
        team_id BIGINT UNSIGNED NULL REFERENCES teams(id) ON DELETE SET NULL,
        consent_status VARCHAR(16) NOT NULL DEFAULT \'unknown\',
        consent_at DATETIME NULL,
        opted_out_at DATETIME NULL,
        last_contacted_at DATETIME NULL,
        next_follow_up_at DATETIME NULL,
        archived_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (tenant_id, phone_e164)
    ){engine}',
    'CREATE INDEX contacts_tenant_name_idx ON contacts (tenant_id, name)',
    'CREATE INDEX contacts_tenant_status_idx ON contacts (tenant_id, lead_status)',

    'CREATE TABLE tags (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        name VARCHAR(60) NOT NULL,
        UNIQUE (tenant_id, name)
    ){engine}',

    'CREATE TABLE contact_tags (
        contact_id BIGINT UNSIGNED NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        tag_id BIGINT UNSIGNED NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        PRIMARY KEY (contact_id, tag_id)
    ){engine}',

    'CREATE TABLE conversations (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        contact_id BIGINT UNSIGNED NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        whatsapp_account_id BIGINT UNSIGNED NOT NULL REFERENCES whatsapp_accounts(id) ON DELETE CASCADE,
        status VARCHAR(16) NOT NULL DEFAULT \'open\',
        priority VARCHAR(16) NOT NULL DEFAULT \'normal\',
        assigned_user_id BIGINT UNSIGNED NULL REFERENCES users(id) ON DELETE SET NULL,
        assigned_team_id BIGINT UNSIGNED NULL REFERENCES teams(id) ON DELETE SET NULL,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        unread_count INT NOT NULL DEFAULT 0,
        last_message_at DATETIME NULL,
        last_inbound_at DATETIME NULL,
        last_message_preview VARCHAR(160) NULL,
        closed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (tenant_id, contact_id, whatsapp_account_id)
    ){engine}',
    'CREATE INDEX conversations_inbox_idx ON conversations (tenant_id, status, last_message_at)',
    'CREATE INDEX conversations_assignee_idx ON conversations (tenant_id, assigned_user_id)',

    'CREATE TABLE conversation_assignments (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        conversation_id BIGINT UNSIGNED NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
        from_user_id BIGINT UNSIGNED NULL,
        to_user_id BIGINT UNSIGNED NULL,
        to_team_id BIGINT UNSIGNED NULL,
        assigned_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL
    ){engine}',

    'CREATE TABLE messages (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        conversation_id BIGINT UNSIGNED NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
        whatsapp_account_id BIGINT UNSIGNED NOT NULL REFERENCES whatsapp_accounts(id) ON DELETE CASCADE,
        direction VARCHAR(3) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT \'text\',
        body TEXT NULL,
        media_url VARCHAR(1000) NULL,
        media_mime VARCHAR(100) NULL,
        reply_to_message_id BIGINT UNSIGNED NULL,
        provider_message_id VARCHAR(191) NULL,
        status VARCHAR(16) NOT NULL,
        error_code VARCHAR(40) NULL,
        error_message VARCHAR(500) NULL,
        sent_by_user_id BIGINT UNSIGNED NULL,
        idempotency_key VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (whatsapp_account_id, provider_message_id),
        UNIQUE (tenant_id, idempotency_key)
    ){engine}',
    'CREATE INDEX messages_conversation_idx ON messages (conversation_id, id)',
    'CREATE INDEX messages_tenant_created_idx ON messages (tenant_id, created_at)',

    'CREATE TABLE message_status_events (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        message_id BIGINT UNSIGNED NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
        status VARCHAR(16) NOT NULL,
        error_code VARCHAR(40) NULL,
        error_message VARCHAR(500) NULL,
        occurred_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX msg_status_message_idx ON message_status_events (message_id)',

    'CREATE TABLE notes (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        contact_id BIGINT UNSIGNED NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        conversation_id BIGINT UNSIGNED NULL REFERENCES conversations(id) ON DELETE SET NULL,
        user_id BIGINT UNSIGNED NULL REFERENCES users(id) ON DELETE SET NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX notes_contact_idx ON notes (tenant_id, contact_id)',

    /* Webhook dedupe / replay log: only the event identity is stored, never the payload. */
    'CREATE TABLE webhook_events (
        id {pk},
        whatsapp_account_id BIGINT UNSIGNED NOT NULL REFERENCES whatsapp_accounts(id) ON DELETE CASCADE,
        provider VARCHAR(32) NOT NULL,
        event_key VARCHAR(191) NOT NULL,
        received_at DATETIME NOT NULL,
        UNIQUE (provider, event_key)
    ){engine}',
];
