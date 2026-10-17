<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Usage in routes: [Can::class, 'contacts.view'] */
final class Can implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        foreach ($args as $permission) {
            $app->gate()->authorize($permission);
        }
        return $next($request);
    }
}
