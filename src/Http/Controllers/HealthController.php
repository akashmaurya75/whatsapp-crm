<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;

/** Public liveness probe for load balancers/uptime monitors. Reveals no details. */
final class HealthController extends Controller
{
    public function check(Request $request): Response
    {
        try {
            $this->app->db->scalar('SELECT 1');
            return Response::json(['status' => 'ok']);
        } catch (\Throwable) {
            return Response::json(['status' => 'unavailable'], 503);
        }
    }
}
