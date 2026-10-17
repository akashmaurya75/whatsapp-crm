<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class Guest implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        $user = $app->auth->user();
        if ($user !== null) {
            return Response::redirect($user['tenant_id'] === null ? '/admin' : '/');
        }
        return $next($request);
    }
}
