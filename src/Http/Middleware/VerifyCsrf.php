<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Requires a valid CSRF token on every state-changing web request. */
final class VerifyCsrf implements Middleware
{
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $sent = (string) ($request->post['_token'] ?? $request->header('x-csrf-token'));
            $expected = (string) $app->session->get('_csrf', '');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                throw new HttpException(419, '', 'csrf_mismatch');
            }
        }
        return $next($request);
    }
}
