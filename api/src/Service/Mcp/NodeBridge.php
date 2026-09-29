<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;

final class NodeBridge
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly Config $config,
    ) {}

    public function execute(array $input): array
    {
        $script = dirname(__DIR__, 4) . '/MCP/src/hosted-bridge.mjs';
        if (!is_file($script) || !$this->hosted->nodeAvailable()) {
            throw new \RuntimeException('Serverový MCP vyžaduje dostupný Node.js a soubory mcp/src.');
        }

        $input['apiUrl'] = rtrim((string) $this->config->get('app.url', ''), '/') . '/api/v1';
        $input['phpBinary'] = $this->hosted->phpBinary();
        $input['apiScript'] = dirname(__DIR__, 3) . '/bin/mcp-internal-api.php';
        $serverParams = (array) ($input['serverParams'] ?? []);
        $forwardedHeader = 'HTTP_' . str_replace('-', '_', strtoupper(
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For')
        ));
        $input['serverParams'] = array_intersect_key($serverParams, array_flip([
            'REMOTE_ADDR', IpMatcher::TRUSTED_CLIENT_IP_PARAM, $forwardedHeader,
        ]));
        $command = [$this->hosted->nodeBinary(), $script];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname($script));
        if (!is_resource($process)) {
            throw new \RuntimeException('Node.js MCP proces se nepodařilo spustit.');
        }

        try {
            fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1], 4 * 1024 * 1024 + 1);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2], 8192);
            fclose($pipes[2]);
            $exit = proc_close($process);
        } catch (\Throwable $e) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
            throw $e;
        }

        if ($exit !== 0 || $output === false || strlen($output) > 4 * 1024 * 1024) {
            throw new \RuntimeException('Node.js MCP proces selhal: ' . substr((string) $error, 0, 500));
        }
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Node.js MCP proces vrátil neplatnou odpověď.');
        }
        return $decoded;
    }
}
