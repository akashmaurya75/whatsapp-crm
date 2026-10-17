<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Crypto;
use App\Tenancy\TenantRepository;

final class AccountRepository extends TenantRepository
{
    protected string $table = 'whatsapp_accounts';

    /** @return list<array> */
    public function allForTenant(): array
    {
        return $this->db->all('SELECT * FROM whatsapp_accounts WHERE tenant_id = ? ORDER BY name', [$this->tid()]);
    }

    public static function credentials(array $account): array
    {
        $c = Crypto::decryptArray((string) $account['credentials_enc']);
        if (!empty($c['managed'])) {
            // Connected with "Continue with Facebook": app id/secret belong to the platform's Meta app.
            $p = MetaPlatform::config();
            $c['app_id'] = $p['app_id'];
            $c['app_secret'] = $p['app_secret'];
        }
        return $c;
    }

    public static function isManaged(array $account): bool
    {
        return !empty(Crypto::decryptArray((string) $account['credentials_enc'])['managed']);
    }

    /** @return list<string> */
    public static function capabilities(array $account): array
    {
        return json_decode((string) $account['capabilities'], true) ?: [];
    }

    public static function supports(array $account, Capability $cap): bool
    {
        return in_array($cap->value, self::capabilities($account), true);
    }
}
