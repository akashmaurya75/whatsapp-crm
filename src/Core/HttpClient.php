<?php

declare(strict_types=1);

namespace App\Core;

/** Outbound HTTP abstraction so provider adapters can be tested with a fake. */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array;
}
