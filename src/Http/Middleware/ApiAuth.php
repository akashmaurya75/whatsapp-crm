<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\ApiTokenService;
use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Bearer-token auth for /api/v1. Tenant comes from the token, never from the request. Per-token rate limit. */
final class ApiAuth implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        $header = $request->header('authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new HttpException(401, 'Missing bearer token.', 'unauthenticated');
        }
        $resolved = (new ApiTokenService($app->db))->resolve($m[1]);
        if ($resolved === null) {
            throw new HttpException(401, 'Invalid or expired token.', 'unauthenticated');
        }
        [$token, $user] = $resolved;
        $limit = (int) env('API_RATE_LIMIT_PER_MINUTE', 120);
        if (!$app->limiter->hit('api:' . $token['id'], $limit, 60)) {
            return Response::apiError('rate_limited', 'Rate limit exceeded.', 429)
                ->withHeader('Retry-After', (string) $app->limiter->availableIn('api:' . $token['id']));
        }
        $app->setApiUser($user);
        $app->tenant->set((int) $user['tenant_id']);
        return $next($request);
    }
}
