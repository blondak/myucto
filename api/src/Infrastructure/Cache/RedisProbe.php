<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Cache;

use MyInvoice\Infrastructure\Config\Config;
use Predis\Client as RedisClient;

final class RedisProbe
{
    public function __construct(private readonly Config $config) {}

    public function isAvailable(): bool
    {
        if (!$this->config->get('redis.enabled', false)) {
            return false;
        }

        $params = [
            'scheme'   => 'tcp',
            'host'     => $this->config->get('redis.host', '127.0.0.1'),
            'port'     => (int) $this->config->get('redis.port', 6379),
            'database' => (int) $this->config->get('redis.db', 0),
            'timeout'  => 1.5,
        ];

        $auth = $this->config->get('redis.auth');
        if (is_string($auth) && $auth !== '') {
            $params['password'] = $auth;
        }

        try {
            $client = new RedisClient($params);
            $client->ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
