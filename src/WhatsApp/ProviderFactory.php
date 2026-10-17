<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\HttpClient;

final class ProviderFactory
{
    /** @var array<string,Provider> */
    private array $providers = [];

    public function __construct(HttpClient $http)
    {
        $this->providers['meta_cloud'] = new MetaCloudProvider($http);
        if ((bool) env('OPENWA_ENABLED', true)) {
            $this->providers['openwa'] = new OpenWaProvider($http);
        }
    }

    public function register(Provider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): Provider
    {
        return $this->providers[$key] ?? throw new \InvalidArgumentException("Unknown or disabled provider: $key");
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /** @return array<string,Provider> */
    public function all(): array
    {
        return $this->providers;
    }
}
