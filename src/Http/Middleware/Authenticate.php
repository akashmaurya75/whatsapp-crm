<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Requires a logged-in tenant user and establishes the tenant context. */
final class Authenticate implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        $user = $app->auth->user();
        if ($user === null) {
            if ($request->method === 'GET') {
                $app->session->put('intended', $request->path);
            }
            throw new HttpException(401);
        }
        if ($user['tenant_id'] === null) {
            // Platform super admins have no tenant workspace.
            return Response::redirect('/admin');
        }
        $app->tenant->set((int) $user['tenant_id']);
        return $next($request);
    }
}
