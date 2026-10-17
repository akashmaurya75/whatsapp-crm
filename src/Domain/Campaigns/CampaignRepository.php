<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Tenancy\TenantRepository;

final class CampaignRepository extends TenantRepository
{
    protected string $table = 'campaigns';
}
