<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ValidationException;

abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    protected function user(): array
    {
        return (array) $this->app->user();
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->session->flash('toast', ['type' => $type, 'message' => $message]);
    }

    /** Redirect back with field errors + old input (passwords never kept). */
    protected function backWithErrors(Request $request, array $errors, string $fallback = '/'): Response
    {
        $old = $request->post;
        foreach (array_keys($old) as $k) {
            if (str_contains((string) $k, 'password') || $k === '_token' || $k === 'code') {
                unset($old[$k]);
            }
        }
        $this->app->session->flash('errors', $errors);
        $this->app->session->flash('old', $old);
        return $this->app->back($request, $fallback);
    }

    protected function handleValidation(Request $request, callable $fn, string $fallback = '/'): Response
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e->errors, $fallback);
        }
    }

    protected function page(Request $request): int
    {
        $p = (int) ($request->query['page'] ?? 1);
        return max(1, min($p, 100000));
    }
}
