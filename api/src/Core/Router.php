<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    public function put(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('PUT', $path, $handler, $options);
    }

    public function patch(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('PATCH', $path, $handler, $options);
    }

    public function delete(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('DELETE', $path, $handler, $options);
    }

    private function add(string $method, string $path, callable|array $handler, array $options): void
    {
        $paramNames = [];
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $matches) use (&$paramNames): string {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, rtrim($path, '/') ?: '/');

        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'params' => $paramNames,
            'handler' => $handler,
            'options' => $options,
        ];
    }

    public function dispatch(Request $request): never
    {
        if ($request->method() === 'OPTIONS') {
            Response::json(['success' => true], 204);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            if (!preg_match($route['pattern'], $request->path(), $matches)) {
                continue;
            }

            array_shift($matches);
            $request->setRouteParams(array_combine($route['params'], $matches) ?: []);

            $options = $route['options'];
            if (($options['auth'] ?? false) === true) {
                App::auth()->requireUser();
            }
            if (!empty($options['roles'])) {
                App::auth()->requireRoles($options['roles']);
            }
            if (($options['csrf'] ?? false) === true) {
                App::auth()->requireCsrf($request);
            }

            $result = call_user_func($route['handler'], $request);
            if ($result !== null) {
                Response::success('Request completed.', $result);
            }

            Response::success('Request completed.');
        }

        throw new HttpException(404, 'API route not found.');
    }
}
