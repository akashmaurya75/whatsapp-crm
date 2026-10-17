<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;
use App\Core\Logger;

final class AuditLogger
{
    public function __construct(private Database $db)
    {
    }

    public function record(
        string $action,
        ?int $tenantId,
        ?int $userId,
        ?string $entityType = null,
        ?int $entityId = null,
        array $meta = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $this->db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'meta' => $meta === [] ? null : json_encode(Logger::redact($meta), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
