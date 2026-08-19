<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

/** Normalizace hostname použitá shodně při zápisu i při každém requestu. */
final class HostnameNormalizer
{
    public function normalizeDomain(string $value): string
    {
        $hostname = trim($value);
        if ($hostname === ''
            || str_contains($hostname, '://')
            || preg_match('~[\s\\\\/@:*]~u', $hostname) === 1
        ) {
            throw new \InvalidArgumentException('Zadejte pouze hostname bez schématu, portu a cesty.');
        }

        $hostname = rtrim($hostname, '.');
        $hostname = $this->toAscii($hostname);
        $this->assertDnsName($hostname);

        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false || $hostname === 'localhost') {
            throw new \InvalidArgumentException('Vlastní doména musí být DNS jméno, ne IP adresa nebo localhost.');
        }

        return $hostname;
    }

    /**
     * Host aktuálního requestu může být při lokálním vývoji localhost/IP.
     * Vlastní doména z administrace přes normalizeDomain() tyto hodnoty nepřijme.
     */
    public function normalizeRequestHost(string $value): string
    {
        $hostname = rtrim(trim($value), '.');
        if ($hostname === '') {
            throw new \InvalidArgumentException('Request neobsahuje hostname.');
        }

        if (str_starts_with($hostname, '[') && str_ends_with($hostname, ']')) {
            $hostname = substr($hostname, 1, -1);
        }
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return strtolower($hostname);
        }

        $hostname = $this->toAscii($hostname);
        if ($hostname !== 'localhost') {
            $this->assertDnsName($hostname);
        }

        return $hostname;
    }

    private function toAscii(string $hostname): string
    {
        if (preg_match('/[^\x20-\x7e]/', $hostname) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new \InvalidArgumentException('IDN domény vyžadují PHP rozšíření intl.');
            }
            $info = [];
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($hostname, IDNA_DEFAULT, $variant, $info);
            if ($ascii === false || (($info['errors'] ?? 0) !== 0)) {
                throw new \InvalidArgumentException('Hostname není platná IDN doména.');
            }
            $hostname = $ascii;
        }

        return strtolower($hostname);
    }

    private function assertDnsName(string $hostname): void
    {
        if (strlen($hostname) > 253 || str_contains($hostname, '..')) {
            throw new \InvalidArgumentException('Hostname není platné DNS jméno.');
        }

        $labels = explode('.', $hostname);
        foreach ($labels as $label) {
            if (strlen($label) < 1
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1
            ) {
                throw new \InvalidArgumentException('Hostname není platné DNS jméno.');
            }
        }
    }
}
