<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Routes;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class RoutePermissionCoverageTest extends TestCase
{
    public function testEveryRegisteredApiRouteHasAuthorizationPolicy(): void
    {
        $app = AppFactory::create();
        Routes::register($app);
        $map = new RoutePermissionMap();
        $missing = [];
        $assignments = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, '/api/') || $pattern === '/api/{path:.*}') continue;
            $path = self::examplePath($pattern);
            foreach ($route->getMethods() as $method) {
                if ($method === 'OPTIONS') continue;
                $effectiveMethod = $method === 'HEAD' ? 'GET' : $method;
                $policy = $map->match($effectiveMethod, $path);
                $routeKey = $effectiveMethod . ' ' . $pattern;
                if ($policy === null) {
                    $missing[] = $routeKey;
                    continue;
                }
                $assignments[$routeKey][] = implode(':', [
                    $policy->kind,
                    $policy->key ?? '',
                    (string) $policy->minimum->value,
                ]);
            }
        }
        self::assertSame([], array_values(array_unique($missing)), 'Každá API route musí mít explicitní permission, self-service nebo superadmin policy.');

        $ambiguous = [];
        foreach ($assignments as $route => $policies) {
            if (count($policies) !== 1) $ambiguous[$route] = $policies;
        }
        self::assertSame([], $ambiguous, 'Každá METHOD + route smí mít právě jedno výsledné autorizační mapování.');
    }

    public function testThereIsNoBlanketGetPolicy(): void
    {
        $map = new RoutePermissionMap();
        $probes = [
            '/api/__permission_probe__',
            '/api/__permission_probe__/1',
            '/api/unrelated-module/resource',
        ];

        foreach ($probes as $path) {
            self::assertNull(
                $map->match('GET', $path),
                "GET $path nesmí zachytit univerzální API pravidlo; nové routy musí být explicitně namapované.",
            );
        }
    }

    private static function examplePath(string $pattern): string
    {
        return preg_replace_callback('/\{[^}:]+(?::([^}]+))?\}/', static function (array $match): string {
            $constraint = $match[1] ?? '';
            if ($constraint === '.*') return 'x';
            if (str_contains($constraint, '0-9') || str_contains($constraint, '\\d')) return '1';
            if (str_contains($constraint, '|')) return explode('|', trim($constraint, '()'))[0];
            return 'x';
        }, $pattern) ?? $pattern;
    }
}
