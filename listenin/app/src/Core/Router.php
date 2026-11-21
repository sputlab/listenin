<?php
declare(strict_types=1);

namespace ListenIn\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): void
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed[] = $method;
                continue;
            }
            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            $handler($request, $params);
            return;
        }
        if ($allowed) {
            throw new ApiException(405, 'method_not_allowed', 'Metodo non consentito.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw ApiException::notFound('Endpoint inesistente.');
    }
}
