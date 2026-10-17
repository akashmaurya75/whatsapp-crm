<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\BackgroundRunner;
use App\Core\Request;
use App\Core\Response;

/**
 * Web cron for hosts whose control panel can only call a URL (or for external services like cron-job.org):
 *   GET /cron?key=CRON_KEY   every minute.
 * Disabled (404) unless CRON_KEY is set. The key is compared in constant time.
 */
final class CronController extends Controller
{
    public function run(Request $request): Response
    {
        $expected = (string) env('CRON_KEY', '');
        $given = (string) ($request->query['key'] ?? '');
        if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        ignore_user_abort(true);
        $r = BackgroundRunner::run(max(5, min(55, (int) env('WEB_CRON_SECONDS', 25))), 'webcron', $this->app->db);
        return Response::json(['status' => $r['locked'] ? 'busy' : 'ok', 'jobs' => $r['jobs'], 'seconds' => $r['seconds']]);
    }
}
