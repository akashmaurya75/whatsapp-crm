<?php

declare(strict_types=1);

namespace Tests;

use App\Core\HttpClient;

final class AssertionFailed extends \RuntimeException
{
}

/** Records requests and returns queued responses (never touches the network). */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = [];

    /** @var list<array{status:int,body:string}|\Throwable> */
    public array $responses = [];

    public function queue(int $status, array|string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => is_array($body) ? (string) json_encode($body) : $body];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next === null) {
            throw new \RuntimeException('FakeHttpClient: no response queued for ' . $url);
        }
        return ['status' => $next['status'], 'body' => $next['body'], 'headers' => []];
    }
}
