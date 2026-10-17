<?php

declare(strict_types=1);

namespace App\Domain\Templates;

use App\Tenancy\TenantRepository;

final class TemplateRepository extends TenantRepository
{
    protected string $table = 'message_templates';
}
