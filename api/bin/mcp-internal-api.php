<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Http\RequestPath;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

try {
    $raw = stream_get_contents(STDIN, 1024 * 1024 + 1);
    if ($raw === false || strlen($raw) > 1024 * 1024) {
        throw new RuntimeException('Neplatná velikost interního požadavku.');
    }
    $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Neplatný interní požadavek.');

    $config = Config::load(Bootstrap::rootDir());
    $base = rtrim((string) $config->get('app.url', ''), '/');
    $url = (string) ($input['url'] ?? '');
    $method = strtoupper((string) ($input['method'] ?? ''));
    $path = parse_url($url, PHP_URL_PATH);
    if (!str_starts_with($url, $base . '/api/v1/')
        || !is_string($path) || !str_starts_with(RequestPath::normalize($path), '/api/v1/')
        || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        throw new RuntimeException('Nepovolená cesta interního API.');
    }

    $params = (array) ($input['serverParams'] ?? []);
    $request = (new ServerRequestFactory())->createServerRequest($method, $url, $params);
    foreach ((array) ($input['headers'] ?? []) as $name => $value) {
        if (!is_string($name) || !is_string($value) || strtolower($name) === 'host') continue;
        $request = $request->withHeader($name, $value);
    }
    $request = $request->withBody((new StreamFactory())->createStream((string) ($input['body'] ?? '')));
    $request = $request->withAttribute('mcp.internal', true);
    $response = Bootstrap::buildApp()->handle($request);
    echo json_encode([
        'status' => $response->getStatusCode(),
        'headers' => [
            'Content-Type' => $response->getHeaderLine('Content-Type') ?: 'application/json',
            'Retry-After' => $response->getHeaderLine('Retry-After'),
        ],
        'body' => (string) $response->getBody(),
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable) {
    fwrite(STDERR, 'Interní MCP API selhalo.');
    exit(1);
}
