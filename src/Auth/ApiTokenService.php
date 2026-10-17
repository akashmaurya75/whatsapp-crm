<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * API tokens: "wcrm_<random>". Only the SHA-256 hash is stored; the plaintext is shown once.
 */
final class ApiTokenService
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{id:int,token:string} */
    public function create(int $tenantId, int $userId, string $name, ?int $ttlDays = null): array
    {
        $plain = 'wcrm_' . random_token(32);
        $id = $this->db->insert('api_tokens', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 100),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $ttlDays ? gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400) : null,
            'created_at' => now(),
        ]);
        return ['id' => $id, 'token' => $plain];
    }

    /** @return array{0:array,1:array}|null [token, user] */
    public function resolve(string $plain): ?array
    {
        if (!str_starts_with($plain, 'wcrm_') || strlen($plain) > 100) {
            return null;
        }
        $token = $this->db->first(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)',
            [hash('sha256', $plain), now()]
        );
        if ($token === null) {
            return null;
        }
        $user = $this->db->first(
            'SELECT u.* FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.id = ? AND u.tenant_id = ? AND u.status = ? AND t.status = ?',
            [$token['user_id'], $token['tenant_id'], 'active', 'active']
        );
        if ($user === null) {
            return null;
        }
        if ($token['last_used_at'] === null || strtotime((string) $token['last_used_at']) < time() - 60) {
            $this->db->update('api_tokens', ['last_used_at' => now()], ['id' => $token['id']]);
        }
        return [$token, $user];
    }

    public function revoke(int $tenantId, int $tokenId): bool
    {
        return $this->db->run(
            'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL',
            [now(), $tokenId, $tenantId]
        )->rowCount() === 1;
    }
}
