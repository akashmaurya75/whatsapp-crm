<?php

declare(strict_types=1);

/* Platform-wide settings (e.g. the Meta app used for "Continue with Facebook"). Values are encrypted. */
return [
    'CREATE TABLE platform_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        value_enc TEXT NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL
    ){engine}',
    'CREATE INDEX wa_accounts_waba_idx ON whatsapp_accounts (business_account_id)',
    'CREATE INDEX wa_accounts_pnid_idx ON whatsapp_accounts (phone_number_id)',
];
