<?php

declare(strict_types=1);

/* Remember the last webhook each WhatsApp account received (or why it was rejected), for troubleshooting. */
return [
    'ALTER TABLE whatsapp_accounts ADD last_webhook_at DATETIME NULL',
    'ALTER TABLE whatsapp_accounts ADD last_webhook_result VARCHAR(255) NULL',
    'ALTER TABLE whatsapp_accounts ADD last_message_in_at DATETIME NULL',
];
