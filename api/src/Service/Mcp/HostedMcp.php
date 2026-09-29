<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\TenantUrlResolver;

final class HostedMcp
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantUrlResolver $urls,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function enabled(): bool
    {
        if ($this->managed->isManaged()) return false;
        $override = $this->envOverride();
        if ($override !== null) return $override;
        return (int) $this->db->pdo()->query('SELECT enabled FROM mcp_server_settings WHERE id = 1')->fetchColumn() === 1;
    }

    public function setEnabled(bool $enabled): void
    {
        if ($this->managed->isManaged()) {
            throw new \LogicException('Serverový MCP není ve spravované instalaci dostupný.');
        }
        if ($this->envOverride() !== null) {
            throw new \LogicException('Nastavení MCP řídí proměnná prostředí.');
        }
        $stmt = $this->db->pdo()->prepare('UPDATE mcp_server_settings SET enabled = ? WHERE id = 1');
        $stmt->execute([$enabled ? 1 : 0]);
    }

    public function managedByEnvironment(): bool
    {
        return $this->envOverride() !== null;
    }

    public function managedInstallation(): bool
    {
        return $this->managed->isManaged();
    }

    public function endpoint(): string
    {
        return $this->urls->canonicalBaseUrl() . '/mcp';
    }

    public function nodeBinary(): string
    {
        $configured = trim((string) (getenv('MYINVOICE_MCP_NODE_BINARY') ?: ''));
        return $configured !== '' ? $configured : 'node';
    }

    public function phpBinary(): string
    {
        $configured = trim((string) (getenv('MYINVOICE_MCP_PHP_BINARY') ?: ''));
        if ($configured !== '') return $configured;
        $name = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $nearby = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $name;
        return is_file($nearby) ? $nearby : PHP_BINDIR . DIRECTORY_SEPARATOR . $name;
    }

    public function nodeAvailable(): bool
    {
        if (!function_exists('proc_open')) return false;
        if (!is_file($this->phpBinary()) || !is_executable($this->phpBinary())) return false;
        $binary = $this->nodeBinary();
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary);
        }

        $path = (string) getenv('PATH');
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', '.bat', ''] : [''];
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary . $suffix;
                if (is_file($candidate) && is_executable($candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function envOverride(): ?bool
    {
        $raw = getenv('MYINVOICE_MCP_ENABLED');
        $value = strtolower(trim($raw === false ? '' : $raw));
        if (in_array($value, ['1', 'true', 'yes'], true)) return true;
        if (in_array($value, ['0', 'false', 'no'], true)) return false;
        return null;
    }
}
