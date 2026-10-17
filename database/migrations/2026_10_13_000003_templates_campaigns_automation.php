<?php

declare(strict_types=1);

/*
 * Message templates (Meta approval), campaigns (broadcasts) and automation rules.
 */
return [
    'CREATE TABLE message_templates (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        whatsapp_account_id BIGINT UNSIGNED NOT NULL REFERENCES whatsapp_accounts(id) ON DELETE CASCADE,
        name VARCHAR(191) NOT NULL,
        language VARCHAR(16) NOT NULL,
        category VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'draft\',
        provider_template_id VARCHAR(64) NULL,
        header_type VARCHAR(10) NOT NULL DEFAULT \'none\',
        header_text VARCHAR(60) NULL,
        header_sample VARCHAR(200) NULL,
        header_media_handle TEXT NULL,
        body TEXT NOT NULL,
        body_samples TEXT NULL,
        footer VARCHAR(60) NULL,
        buttons TEXT NULL,
        rejected_reason VARCHAR(500) NULL,
        quality_score VARCHAR(20) NULL,
        submitted_at DATETIME NULL,
        last_synced_at DATETIME NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (whatsapp_account_id, name, language)
    ){engine}',
    'CREATE INDEX templates_tenant_idx ON message_templates (tenant_id, status)',

    'CREATE TABLE campaigns (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        whatsapp_account_id BIGINT UNSIGNED NOT NULL REFERENCES whatsapp_accounts(id) ON DELETE CASCADE,
        template_id BIGINT UNSIGNED NOT NULL REFERENCES message_templates(id) ON DELETE CASCADE,
        name VARCHAR(160) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'draft\',
        audience TEXT NULL,
        variable_map TEXT NULL,
        header_media_url VARCHAR(1000) NULL,
        header_media_id VARCHAR(100) NULL,
        require_consent TINYINT(1) NOT NULL DEFAULT 1,
        consent_attested_by BIGINT UNSIGNED NULL,
        frequency_cap_hours INT NOT NULL DEFAULT 24,
        rate_per_minute INT NOT NULL DEFAULT 300,
        scheduled_at DATETIME NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        last_batch_at DATETIME NULL,
        total_recipients INT NOT NULL DEFAULT 0,
        skipped_count INT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX campaigns_tenant_idx ON campaigns (tenant_id, status)',
    'CREATE INDEX campaigns_due_idx ON campaigns (status, scheduled_at)',

    'CREATE TABLE campaign_recipients (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        campaign_id BIGINT UNSIGNED NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        contact_id BIGINT UNSIGNED NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        message_id BIGINT UNSIGNED NULL,
        status VARCHAR(16) NOT NULL,
        skip_reason VARCHAR(40) NULL,
        error VARCHAR(500) NULL,
        replied_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE (campaign_id, contact_id)
    ){engine}',
    'CREATE INDEX recipients_status_idx ON campaign_recipients (campaign_id, status)',
    'CREATE INDEX recipients_message_idx ON campaign_recipients (message_id)',
    'CREATE INDEX recipients_contact_idx ON campaign_recipients (tenant_id, contact_id, updated_at)',

    'ALTER TABLE messages ADD COLUMN template_id BIGINT UNSIGNED NULL',
    'ALTER TABLE messages ADD COLUMN campaign_id BIGINT UNSIGNED NULL',
    'ALTER TABLE messages ADD COLUMN template_params TEXT NULL',
    'CREATE INDEX messages_campaign_idx ON messages (campaign_id)',

    'CREATE TABLE automation_rules (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        whatsapp_account_id BIGINT UNSIGNED NULL REFERENCES whatsapp_accounts(id) ON DELETE CASCADE,
        type VARCHAR(16) NOT NULL,
        name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        priority INT NOT NULL DEFAULT 100,
        config TEXT NOT NULL,
        cooldown_minutes INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX automation_rules_tenant_idx ON automation_rules (tenant_id, type, is_active)',

    'CREATE TABLE automation_runs (
        id {pk},
        tenant_id BIGINT UNSIGNED NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
        rule_id BIGINT UNSIGNED NOT NULL REFERENCES automation_rules(id) ON DELETE CASCADE,
        contact_id BIGINT UNSIGNED NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        conversation_id BIGINT UNSIGNED NULL,
        trigger_message_id BIGINT UNSIGNED NULL,
        status VARCHAR(16) NOT NULL,
        detail VARCHAR(500) NULL,
        created_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX automation_runs_rule_idx ON automation_runs (rule_id, contact_id, created_at)',
    'CREATE INDEX automation_runs_tenant_idx ON automation_runs (tenant_id, created_at)',
];
