<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Usage: [Throttle::class, 'name', '10', '60'] → 10 requests / 60 s per IP for that route group. */
final class Throttle implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        [$name, $max, $decay] = $args + ['web', '60', '60'];
        if ($request->method !== 'GET' || $name === 'api') {
            if (!$app->limiter->hit("throttle:$name:" . $request->ip(), (int) $max, (int) $decay)) {
                throw new HttpException(429, '', 'rate_limited');
            }
        }
        return $next($request);
    }
}
