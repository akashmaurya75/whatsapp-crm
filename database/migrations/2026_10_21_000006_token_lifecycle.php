<?php

declare(strict_types=1);

/* Track the Meta access token's lifetime so a dead token is noticed (and the tenant told) before sends fail silently. */
return [
    'ALTER TABLE whatsapp_accounts ADD token_status VARCHAR(16) NULL',
    'ALTER TABLE whatsapp_accounts ADD token_expires_at DATETIME NULL',
    'ALTER TABLE whatsapp_accounts ADD token_checked_at DATETIME NULL',
    'ALTER TABLE whatsapp_accounts ADD reconnect_notified_at DATETIME NULL',
];
