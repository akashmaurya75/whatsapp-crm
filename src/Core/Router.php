<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},middleware:list<string|array>}> */
    private array $routes = [];

    /** @var list<string|array> */
    private array $groupStack = [];

    /** @param list<string|array> $middleware */
    public function group(array $middleware, Closure $routes): void
    {
        $previous = $this->groupStack;
        $this->groupStack = array_merge($this->groupStack, $middleware);
        $routes($this);
        $this->groupStack = $previous;
    }

    /**
     * @param array{0:class-string,1:string} $handler
     * @param list<string|array> $middleware middleware class names or [class, arg]
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => array_merge($this->groupStack, $middleware),
        ];
    }

    public function get(string $p, array $h, array $m = []): void { $this->add('GET', $p, $h, $m); }
    public function post(string $p, array $h, array $m = []): void { $this->add('POST', $p, $h, $m); }
    public function patch(string $p, array $h, array $m = []): void { $this->add('PATCH', $p, $h, $m); }
    public function delete(string $p, array $h, array $m = []): void { $this->add('DELETE', $p, $h, $m); }

    /** @return array{route:array,params:array<string,string>}|null|string null = not found, 'method' = 405 */
    public function match(string $method, string $path): array|string|null
    {
        $methodMismatch = false;
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $lookup) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['route' => $route, 'params' => $params];
        }
        return $methodMismatch ? 'method' : null;
    }
}
