<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

abstract class ApiController extends Controller
{
    protected function paginated(array $rows, int $total, int $page, int $perPage, callable $map): Response
    {
        return Response::json([
            'data' => array_map($map, $rows),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'request_id' => $this->app->requestId,
        ]);
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) ($request->query['per_page'] ?? 25)));
    }

    /**
     * Idempotency-Key support for POST: replays the stored response for the same key + body,
     * and rejects reuse of a key with a different body.
     * @param callable():Response $fn
     */
    protected function idempotent(Request $request, callable $fn): Response
    {
        $key = trim($request->header('idempotency-key'));
        if ($key === '') {
            return $fn();
        }
        if (strlen($key) > 191 || !preg_match('/^[\x21-\x7E]+$/', $key)) {
            return Response::apiError('invalid_idempotency_key', 'Idempotency-Key must be 1–191 printable ASCII characters.', 400);
        }
        $tid = $this->app->tenant->id();
        $hash = hash('sha256', $request->method . ' ' . $request->path . "\n" . $request->body);
        $prior = $this->app->db->first('SELECT * FROM idempotency_keys WHERE tenant_id = ? AND idem_key = ?', [$tid, $key]);
        if ($prior !== null) {
            if (!hash_equals((string) $prior['request_hash'], $hash)) {
                return Response::apiError('idempotency_conflict', 'This Idempotency-Key was used with a different request.', 409);
            }
            return (new Response((string) $prior['response_body'], (int) $prior['response_code'], ['Content-Type' => 'application/json']))
                ->withHeader('Idempotent-Replayed', 'true');
        }
        $response = $fn();
        if ($response->status < 500) {
            try {
                $this->app->db->insert('idempotency_keys', [
                    'tenant_id' => $tid, 'idem_key' => $key, 'request_hash' => $hash,
                    'response_code' => $response->status, 'response_body' => $response->body, 'created_at' => now(),
                ]);
            } catch (\PDOException $e) {
                if (!$this->app->db->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }
        return $response;
    }
}
