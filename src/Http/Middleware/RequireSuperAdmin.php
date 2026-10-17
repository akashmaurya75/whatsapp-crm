<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class RequireSuperAdmin implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        $user = $app->auth->user();
        if ($user === null) {
            throw new HttpException(401);
        }
        if ((int) $user['is_super_admin'] !== 1 || $user['tenant_id'] !== null) {
            throw HttpException::notFound();
        }
        return $next($request);
    }
}
