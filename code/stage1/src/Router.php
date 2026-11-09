<?php
declare(strict_types=1);

namespace InfraLogging;

/**
 * Matches the request path against route patterns such as
 * /api/v1/runs/{runId}/logs. A path that matches with the wrong method
 * is a 405; a path that matches nothing is a 404.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:array{class-string,string}}> */
    private array $routes = [];

    public function __construct(private readonly App $app) {}

    /** @param array{class-string,string} $handler */
    public function add(string $method, string $pattern, array $handler): void
    {
        // {name} matches one path segment; the controller validates its format.
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $route['handler'];
            return (new $class($this->app))->$action($request, $params);
        }

        if ($allowed !== []) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED',
                "Method {$request->method} is not allowed on this path.",
                ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw ApiException::notFound('No API endpoint matches this path.');
    }
}
