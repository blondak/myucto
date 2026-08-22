<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * LicenseService::current() — přechody stavů podle uloženého tokenu a řádku
 * `license`. Doplňuje LicenseStateTest (ten testuje čistou LicenseState logiku);
 * tady se ověřuje DB-driven výpočet: trial dle trial_started_at, verifikace
 * podpisu, iid match, expiraci valid_until a status overage.
 */
#[Group('integration')]
final class LicenseServiceStateTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
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

        $this->service = new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new LicenseTokenVerifier(),
            $this->createStub(LicenseClient::class),
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

    public function testNoKeyRecentTrialIsTrial(): void
    {
        $this->setRow(null, null, date('Y-m-d H:i:s'));
        $state = $this->service->current();

        self::assertSame(LicenseState::TRIAL, $state->state);
        self::assertTrue($state->hasCommercialFeatures());
        self::assertNotNull($state->trialEndsAt);
    }

    public function testNoKeyExpiredTrialIsTrialExpired(): void
    {
        $this->setRow(null, null, date('Y-m-d H:i:s', time() - 90 * 86400));
        $state = $this->service->current();

        self::assertSame(LicenseState::TRIAL_EXPIRED, $state->state);
        self::assertFalse($state->hasCommercialFeatures());
    }

    public function testValidTokenIsActiveWithLimits(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token());
        $state = $this->service->current();

        self::assertSame(LicenseState::ACTIVE, $state->state);
        self::assertSame('single', $state->tier);
        self::assertSame(5, $state->maxCompanies);
        self::assertSame(3, $state->usersLicensed);
        self::assertNotNull($state->validUntil);
        self::assertTrue($state->hasCommercialFeatures());
    }

    /**
     * ⚠️ Bezplatný tarif si místa nekupuje — jeho cena je nula.
     *
     * Klíč dostává kvůli kvótě, stavu předplatného a telemetrii, ale kdyby
     * v tokenu přišlo `users` větší než jedna, dostal by zákazník druhé
     * a další licenční místo zadarmo. Strop se proto uplatní při skládání
     * stavu, ne až u rozhodování — jinak by aplikace jinde ukazovala jiné
     * číslo, než jaké vymáhá.
     */
    public function testFreeTierNeverGrantsMoreThanOneSeat(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['commercial' => false, 'users' => 5]));
        $state = $this->service->current();

        self::assertSame(LicenseState::ACTIVE, $state->state);
        self::assertFalse($state->hasCommercialFeatures());
        self::assertSame(LicenseState::FREE_SEATS, $state->usersLicensed, 'Bezplatný tarif má jedno místo.');
    }

    /** Nula v tokenu znamená „neomezeně" — bezplatnému tarifu nesmí projít ani ta. */
    public function testFreeTierWithUnlimitedSeatsInTokenIsStillCapped(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['commercial' => false, 'users' => 0]));

        self::assertSame(LicenseState::FREE_SEATS, $this->service->current()->usersLicensed);
    }

    /** Placený tarif si počet míst z tokenu nese beze změny. */
    public function testPaidTierKeepsItsLicensedSeats(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['commercial' => true, 'users' => 5]));

        self::assertSame(5, $this->service->current()->usersLicensed);
    }

    public function testOverageStatusIsOverage(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['status' => 'overage']));
        $state = $this->service->current();

        self::assertSame(LicenseState::OVERAGE, $state->state);
        self::assertTrue($state->hasCommercialFeatures());
    }

    public function testExpiredValidUntilIsDegraded(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['valid_until' => time() - 10]));
        $state = $this->service->current();

        self::assertSame(LicenseState::DEGRADED, $state->state);
        self::assertFalse($state->hasCommercialFeatures());
    }

    public function testInvalidSignatureIsDegraded(): void
    {
        // Token podepsaný cizím klíčem — verifikace selže → degraded.
        $token = $this->signLicenseToken(
            ['iid' => $this->instanceId, 'tier' => 'single', 'valid_until' => time() + 86400, 'status' => 'ok'],
            $this->foreignSecretKey(),
        );
        $this->setRow('MYU-TEST-0001-AAAA', $token);

        self::assertSame(LicenseState::DEGRADED, $this->service->current()->state);
    }

    public function testForeignInstanceIdIsDegraded(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['iid' => 'not-this-instance']));

        self::assertSame(LicenseState::DEGRADED, $this->service->current()->state);
    }

    public function testKeyWithoutTokenIsDegraded(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', null);

        self::assertSame(LicenseState::DEGRADED, $this->service->current()->state);
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-1',
        ], $overrides));
    }

    private function setRow(?string $key, ?string $token, ?string $trialStartedAt = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = NULL,
                    trial_started_at = COALESCE(?, trial_started_at)
              WHERE id = 1'
        )->execute([$key, $token, $trialStartedAt]);
    }
}
