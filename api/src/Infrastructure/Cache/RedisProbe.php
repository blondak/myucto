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

        // H-08: probe sice sahá jen na PING (bez klíčů), ale klienta staví se
        // stejným prefixem jako {@see RedisFactory}. Kdyby ho postavil bez něj,
        // vznikla by druhá cesta k Redisu s jinou konvencí klíčů — a příště by
        // ji někdo rozšířil o `->get()`. Invariant „každý klient nese prefix"
        // musí platit bez výjimek, protože výjimka je přesně to místo, kde
        // vznikne klíč mimo jmenný prostor instance.
        if (RedisKeyspace::unsafeReason($this->config) !== null) {
            return false;
        }

        try {
            $client = new RedisClient($params, ['prefix' => RedisKeyspace::prefix($this->config)]);
            $client->ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
