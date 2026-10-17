<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Requires any logged-in user (tenant user or super admin); sets tenant context when applicable. */
final class AuthenticateAny implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        $user = $app->auth->user();
        if ($user === null) {
            throw new HttpException(401);
        }
        if ($user['tenant_id'] !== null) {
            $app->tenant->set((int) $user['tenant_id']);
        }
        return $next($request);
    }
}
