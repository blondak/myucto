<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseNetworkException;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přihlášený přechod na portál podpory (`LicenseService::supportLink()` →
 * POST /api/license/support-link).
 *
 * Podstata testů: odkaz na podporu se NIKDY nesmí zvrhnout v chybu. Identita se
 * dotahuje jen u licence, která opravdu platí; trial, degradovaná licence,
 * odmítnutí serveru i výpadek sítě končí prostým veřejným odkazem.
 */
#[Group('integration')]
final class LicenseSupportLinkTest extends TestCase
{
    use LicenseTokenTrait;

    private const SERVER = 'https://license.test';
    private const FALLBACK = 'https://license.test/support';

    private Connection $db;
    private LicenseClient $client;
    private LicenseService $service;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('license')) {
            $this->markTestSkipped('Migrace 1139 (license) neproběhla / DB nedostupná.');
        }

        $this->client = $this->createMock(LicenseClient::class);
        $this->service = new LicenseService(
            $this->db,
            new Config(['license' => [
                'public_key' => $this->licensePublicKeyBase64(),
                'server_url' => self::SERVER . '/',
            ]]),
            new \MyInvoice\Service\License\LicenseTokenVerifier(),
            $this->client,
        );

        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testActiveLicenseGetsIdentifiedLink(): void
    {
        $this->primeActive();
        $signed = self::SERVER . '/support?h=one-time-token';

        $this->client->expects($this->once())->method('supportSession')
            ->with('MYU-TEST-0001-AAAA', $this->instanceId, $this->anything(), [])
            ->willReturn(['ok' => true, 'url' => $signed, 'expires_in' => 600]);

        self::assertSame(['url' => $signed], $this->service->supportLink());
    }

    public function testCompanyDetailsAreSentAsFallbackPrefill(): void
    {
        $this->primeActive();
        $supplierId = $this->primeSupplier([
            'company_name' => 'Testovací firma s.r.o.',
            'ic'           => '12345678',
            'dic'          => 'CZ12345678',
            'street'       => 'Zkušební 1',
            'city'         => 'Praha',
            'zip'          => '11000',
            'email'        => 'ucetni@example.test',
        ]);
        $signed = self::SERVER . '/support?h=one-time-token';

        $this->client->expects($this->once())->method('supportSession')
            ->with($this->anything(), $this->anything(), $this->anything(), [
                'name'    => 'Testovací firma s.r.o.',
                'ic'      => '12345678',
                'dic'     => 'CZ12345678',
                'street'  => 'Zkušební 1',
                'city'    => 'Praha',
                'zip'     => '11000',
                'country' => 'CZ',
                'email'   => 'ucetni@example.test',
            ])
            ->willReturn(['ok' => true, 'url' => $signed]);

        self::assertSame(['url' => $signed], $this->service->supportLink($supplierId));
    }

    public function testEmptyCompanyValuesAreOmittedNotSentAsEmptyStrings(): void
    {
        $this->primeActive();
        $supplierId = $this->primeSupplier([
            'company_name' => 'Testovací firma s.r.o.',
            'ic'           => '12345678',
            'dic'          => '',
            'street'       => 'Zkušební 1',
            'city'         => 'Praha',
            'zip'          => '11000',
            'email'        => '   ',
        ]);

        $sent = null;
        $this->client->expects($this->once())->method('supportSession')
            ->willReturnCallback(function (string $k, string $i, string $v, array $company) use (&$sent): array {
                $sent = $company;
                return ['ok' => true, 'url' => self::SERVER . '/support?h=t'];
            });

        $this->service->supportLink($supplierId);

        self::assertIsArray($sent);
        self::assertArrayNotHasKey('dic', $sent, 'prázdná hodnota se posílá jako chybějící klíč, ne prázdný řetězec');
        self::assertArrayNotHasKey('email', $sent, 'hodnota jen z mezer je taky prázdná');
        self::assertSame('Testovací firma s.r.o.', $sent['name']);
    }

    public function testMissingSupplierDoesNotBreakHandoff(): void
    {
        $this->primeActive();
        $signed = self::SERVER . '/support?h=one-time-token';
        // Neexistující firma (i nulové ID) → `company` se prostě nepřiloží.
        $this->client->expects($this->exactly(2))->method('supportSession')
            ->with($this->anything(), $this->anything(), $this->anything(), [])
            ->willReturn(['ok' => true, 'url' => $signed]);

        self::assertSame(['url' => $signed], $this->service->supportLink(2147483000));
        self::assertSame(['url' => $signed], $this->service->supportLink(0));
    }

    public function testTrialFallsBackToPublicLinkWithoutCallingServer(): void
    {
        $this->db->pdo()->exec(
            'UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL,
                    last_nonce = NULL, trial_started_at = NOW() WHERE id = 1'
        );
        $this->client->expects($this->never())->method('supportSession');

        self::assertSame(['url' => self::FALLBACK], $this->service->supportLink());
    }

    public function testDegradedLicenseFallsBackWithoutCallingServer(): void
    {
        // Klíč je, ale token má prošlou platnost → degraded.
        $this->primeActive(time() - 86400);
        $this->client->expects($this->never())->method('supportSession');

        self::assertSame(['url' => self::FALLBACK], $this->service->supportLink());
    }

    public function testRejectedSessionFallsBackToPublicLink(): void
    {
        $this->primeActive();
        $this->client->expects($this->once())->method('supportSession')
            ->willReturn(['ok' => false, 'error' => 'rate_limited']);

        self::assertSame(['url' => self::FALLBACK], $this->service->supportLink());
    }

    public function testNetworkErrorFallsBackToPublicLink(): void
    {
        $this->primeActive();
        $this->client->expects($this->once())->method('supportSession')
            ->willThrowException(new LicenseNetworkException('server unreachable'));

        self::assertSame(['url' => self::FALLBACK], $this->service->supportLink());
    }

    public function testNonHttpUrlFromServerIsRefused(): void
    {
        $this->primeActive();
        // Odkaz otevírá prohlížeč — schéma se ověřuje i u vlastního serveru.
        $this->client->expects($this->once())->method('supportSession')
            ->willReturn(['ok' => true, 'url' => 'javascript:alert(1)']);

        self::assertSame(['url' => self::FALLBACK], $this->service->supportLink());
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * Přepíše existující firmu syntetickými fakturačními údaji (celý test běží
     * v transakci, která se na konci vrací zpátky) a vrátí její ID. Země se pin-uje
     * na CZ přes číselník `countries`, aby test nezávisel na fixture datech.
     *
     * @param array<string,string> $values
     */
    private function primeSupplier(array $values): int
    {
        $pdo = $this->db->pdo();
        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplierId <= 0) {
            self::markTestSkipped('Testovací DB nemá žádnou firmu.');
        }
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if ($countryId <= 0) {
            self::markTestSkipped('Číselník zemí neobsahuje CZ.');
        }

        $values += ['company_name' => '', 'ic' => '', 'dic' => '', 'street' => '', 'city' => '', 'zip' => '', 'email' => ''];
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = ?, ic = ?, dic = ?, street = ?, city = ?, zip = ?, email = ?, country_id = ?
              WHERE id = ?'
        )->execute([
            $values['company_name'], $values['ic'], $values['dic'], $values['street'],
            $values['city'], $values['zip'], $values['email'], $countryId, $supplierId,
        ]);

        return $supplierId;
    }

    private function primeActive(?int $validUntil = null): void
    {
        $validUntil ??= time() + 20 * 86400;
        $this->db->pdo()->exec(
            'INSERT IGNORE INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())'
        );
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = ?, last_nonce = ?,
                    counter = 0, last_check_at = ?, last_check_ok = 1
              WHERE id = 1'
        )->execute([
            'MYU-TEST-0001-AAAA',
            $this->signLicenseToken([
                'lic'           => 1,
                'iid'           => $this->instanceId,
                'tier'          => 'single',
                'users'         => 3,
                'max_companies' => 5,
                'valid_until'   => $validUntil,
                'status'        => 'ok',
                'nonce'         => 'nonce-1',
            ]),
            json_encode(['nonce' => 'nonce-1'], JSON_UNESCAPED_UNICODE),
            'nonce-1',
            date('Y-m-d H:i:s'),
        ]);
    }
}
