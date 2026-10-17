<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use Closure;

interface Middleware
{
    /** @param Closure(Request):Response $next */
    public function handle(App $app, Request $request, Closure $next, string ...$args): Response;
}
