<?php

declare(strict_types=1);

namespace App\Core;

use App\Audit\AuditLogger;
use App\Auth\AuthService;
use App\Http\Middleware\Middleware;
use App\Rbac\Gate;
use App\Tenancy\TenantContext;
use App\WhatsApp\ProviderFactory;
use Closure;
use Throwable;

/** Application kernel: service container + HTTP dispatcher. One instance per request (or job). */
final class App
{
    public readonly TenantContext $tenant;
    public readonly AuthService $auth;
    public readonly AuditLogger $audit;
    public readonly RateLimiter $limiter;
    public readonly Queue $queue;
    public readonly View $view;
    public readonly Mailer $mailer;
    public readonly ProviderFactory $providers;
    public readonly string $requestId;
    private ?Gate $gate = null;
    private ?array $apiUser = null;
    public ?Request $request = null;

    public function __construct(
        public readonly Database $db,
        public readonly Session $session,
        public readonly HttpClient $http = new CurlHttpClient(),
        public readonly ?Router $router = null,
    ) {
        $this->tenant = new TenantContext();
        $this->audit = new AuditLogger($db);
        $this->limiter = new RateLimiter($db);
        $this->auth = new AuthService($db, $session, $this->limiter, $this->audit);
        $this->queue = new Queue($db);
        $this->view = new View(base_path('views'), ['app' => $this]);
        $this->mailer = new Mailer();
        $this->providers = new ProviderFactory($this->http);
        $this->requestId = bin2hex(random_bytes(8));
        $GLOBALS['__request_id'] = $this->requestId;
    }

    /** The authenticated user: session user for web, token user for API. */
    public function user(): ?array
    {
        return $this->apiUser ?? $this->auth->user();
    }

    public function setApiUser(array $user): void
    {
        $this->apiUser = $user;
        $this->gate = null;
    }

    public function userId(): ?int
    {
        $u = $this->user();
        return $u !== null ? (int) $u['id'] : null;
    }

    public function gate(): Gate
    {
        return $this->gate ??= new Gate($this->db, $this->user());
    }

    public function can(string $permission): bool
    {
        return $this->gate()->can($permission);
    }

    public function csrfToken(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = random_token(32);
            $this->session->put('_csrf', $token);
        }
        return $token;
    }

    /** Audit helper bound to the current actor/tenant/request. */
    public function auditLog(string $action, ?string $entityType = null, ?int $entityId = null, array $meta = []): void
    {
        $this->audit->record(
            $action,
            $this->tenant->has() ? $this->tenant->id() : null,
            $this->userId(),
            $entityType,
            $entityId,
            $meta,
            $this->request?->ip(),
            $this->request?->userAgent(),
        );
    }

    /** @var array{errors:array,old:array,toast:?array}|null flash data, pulled once per request */
    private ?array $flashData = null;

    public function render(string $view, array $data = [], int $status = 200): Response
    {
        $this->flashData ??= [
            'errors' => (array) ($this->session->getFlash('errors') ?? []),
            'old' => (array) ($this->session->getFlash('old') ?? []),
            'toast' => $this->session->getFlash('toast'),
        ];
        return Response::html($this->view->render($view, $data + $this->flashData), $status);
    }

    public function back(Request $request, string $fallback = '/'): Response
    {
        $ref = $request->header('referer');
        $path = $ref !== '' ? (parse_url($ref, PHP_URL_PATH) ?: $fallback) : $fallback;
        $query = $ref !== '' ? parse_url($ref, PHP_URL_QUERY) : null;
        return Response::redirect($path . ($query ? '?' . $query : ''));
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        try {
            if ($this->router === null) {
                throw new \LogicException('No router configured');
            }
            $match = $this->router->match($request->method, $request->path);
            if ($match === null) {
                throw HttpException::notFound();
            }
            if ($match === 'method') {
                throw new HttpException(405, 'Method not allowed.', 'method_not_allowed');
            }
            $request->params = $match['params'];
            $route = $match['route'];
            $core = function (Request $req) use ($route): Response {
                [$class, $method] = $route['handler'];
                return (new $class($this))->{$method}($req);
            };
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                function (Closure $next, string|array $mw): Closure {
                    [$class, $args] = is_array($mw) ? [$mw[0], array_slice($mw, 1)] : [$mw, []];
                    return function (Request $req) use ($class, $args, $next): Response {
                        /** @var Middleware $instance */
                        $instance = new $class();
                        return $instance->handle($this, $req, $next, ...$args);
                    };
                },
                $core
            );
            $response = $pipeline($request);
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $e->status, $e->getMessage(), $e->errorCode);
        } catch (Throwable $e) {
            Logger::error('Unhandled exception', [
                'exception' => $e::class, 'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(), 'path' => $request->path,
            ]);
            $message = (bool) env('APP_DEBUG', false) ? $e->getMessage() : 'Something went wrong. The error has been logged.';
            $response = $this->errorResponse($request, 500, $message, 'server_error');
        }
        return $this->securityHeaders($request, $response);
    }

    private function errorResponse(Request $request, int $status, string $message, string $code): Response
    {
        if ($request->wantsJson()) {
            return Response::apiError($code, $message, $status);
        }
        if ($status === 401) {
            return Response::redirect('/login');
        }
        try {
            return $this->render('errors/error', ['status' => $status, 'message' => $message], $status);
        } catch (Throwable) {
            return Response::text($message, $status);
        }
    }

    private function securityHeaders(Request $request, Response $response): Response
    {
        $response->withHeader('X-Request-Id', $this->requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (!isset($response->headers['Cross-Origin-Opener-Policy'])) {
            $response->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        }
        if (!isset($response->headers['Content-Security-Policy'])) {
            $response->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; "
                . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
            );
        }
        if ((bool) env('APP_FORCE_HTTPS', false)) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (str_starts_with($response->headers['Content-Type'] ?? '', 'text/html')) {
            $response->withHeader('Cache-Control', 'no-store, private');
        }
        return $response;
    }
}
