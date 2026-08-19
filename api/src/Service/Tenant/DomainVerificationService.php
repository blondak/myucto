<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundUrlGuard;

final class DomainVerificationService
{
    public function __construct(private readonly OutboundUrlGuard $http) {}

    /** @param array<string,mixed> $domain @return array{verified:bool,dns:bool,https:bool,error:?string} */
    public function verify(array $domain): array
    {
        $hostname = (string) ($domain['hostname'] ?? '');
        $token = (string) ($domain['verification_token'] ?? '');
        if ($hostname === '' || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Doména nemá platnou ověřovací challenge.');
        }

        $expected = 'myucto-verification=' . $token;
        $dnsOk = in_array($expected, $this->txtRecords('_myucto-challenge.' . $hostname), true);
        if (!$dnsOk) {
            return [
                'verified' => false,
                'dns' => false,
                'https' => false,
                'error' => 'DNS TXT challenge nebyla nalezena.',
            ];
        }

        try {
            $result = $this->http->request(
                'GET',
                'https://' . $hostname . '/api/public/domain-verification/' . $token,
                ['Accept' => 'text/plain'],
                null,
                [$hostname],
                8,
                256,
            );
            $httpsOk = $result->status === 200 && hash_equals($expected, trim($result->body));
        } catch (OutboundRequestException $e) {
            return [
                'verified' => false,
                'dns' => true,
                'https' => false,
                'error' => 'HTTPS kontrola selhala: ' . $e->getMessage(),
            ];
        }

        return [
            'verified' => $httpsOk,
            'dns' => true,
            'https' => $httpsOk,
            'error' => $httpsOk ? null : 'HTTPS endpoint nevrátil očekávanou challenge.',
        ];
    }

    /** @return list<string> */
    protected function txtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);
        if (!is_array($records)) return [];
        $values = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = trim($record['txt']);
                continue;
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                $value = implode('', array_filter($record['entries'], 'is_string'));
                if ($value !== '') $values[] = trim($value);
            }
        }
        return array_values(array_unique($values));
    }
}
