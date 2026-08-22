<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\License;

use MyInvoice\Action\License\LicenseBillingAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Service\License\BillingSnapshot;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\ManagedModeGuard;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /api/license/billing — „z aplikace musí jít doplatit".
 *
 * Zbytek licenčního API je superadmin-only a zůstane. Tenhle jediný endpoint
 * je výjimka a test hlídá přesně tu hranici, kvůli které vznikl:
 *
 *  1. Běžný admin (účetní) dunning stav DOSTANE — bez toho se o nezdařené
 *     platbě dozví až tím, že aplikace přestane fungovat.
 *  2. Ven jde JEN dunning výřez. Licenční klíč, počty míst ani fakturační
 *     údaje se do odpovědi nesmí dostat ani omylem.
 *  3. Klientský účet (portál odběratele) nedostane nic.
 *  4. Self-hosted vrací `null` — tam se nic neplatí.
 */
final class LicenseBillingActionTest extends TestCase
{
    /** Co smí ven. Cokoli navíc je únik za superadmin bránu. */
    private const ALLOWED_KEYS = [
        'unpaid', 'license_state', 'subscription_state', 'phase',
        'attempt', 'max_attempts', 'next_attempt_at', 'suspend_at',
        'access_until', 'data_until', 'amount_due', 'currency', 'pay_url',
    ];

    public function testAccountantSeesTheDunningState(): void
    {
        $billing = $this->billing(role: 'accountant', subscription: [
            'state'           => 'past_due',
            'phase'           => 'past_due',
            'attempt'         => 2,
            'max_attempts'    => 4,
            'next_attempt_at' => 1_800_100_000,
            'suspend_at'      => 1_800_500_000,
            'data_until'      => 1_802_000_000,
        ]);

        self::assertTrue($billing['unpaid']);
        self::assertSame('past_due', $billing['subscription_state']);
        self::assertSame(2, $billing['attempt']);
        self::assertSame(4, $billing['max_attempts']);
        self::assertSame(1_800_100_000, $billing['next_attempt_at']);
        self::assertSame(1_800_500_000, $billing['suspend_at']);
        self::assertSame(1_802_000_000, $billing['data_until']);
    }

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: nejsnáz se nový endpoint napíše
     * tak, že vrátí celý `billing` blok ze statusu. Ten ale nese i `valid_until`
     * a poslední kontrolu — a hlavně by se do něj kdykoli později dalo přilepit
     * cokoli dalšího, aniž by si toho někdo všiml.
     */
    public function testNothingBeyondTheDunningSliceLeaksOut(): void
    {
        $billing = $this->billing(role: 'accountant');

        self::assertSame(self::ALLOWED_KEYS, array_keys($billing));
    }

    public function testClientAccountGetsNothing(): void
    {
        $response = $this->invoke(role: 'client', managed: true);

        self::assertSame(403, $response->getStatusCode());
    }

    /** Self-hosted: `null`, ne prázdný objekt a ne chyba. */
    public function testSelfHostedHasNoBillingAtAll(): void
    {
        $payload = $this->payload(role: 'admin', managed: false);

        self::assertNull($payload['billing']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Odkaz na platbu
    // ─────────────────────────────────────────────────────────────────────────

    public function testSignedPayUrlFromTheServerWins(): void
    {
        $billing = $this->billing(role: 'admin', subscription: [
            'state'      => 'past_due',
            'pay_url'    => 'https://test.example/platba/abc123',
            'amount_due' => 1490.5,
            'currency'   => 'czk',
        ]);

        self::assertSame('https://test.example/platba/abc123', $billing['pay_url']);
        self::assertSame(1490.5, $billing['amount_due']);
        self::assertSame('CZK', $billing['currency']);
    }

    /** Bez odkazu ze serveru zůstává dnešní cesta — tlačítko musí někam vést. */
    public function testMissingPayUrlFallsBackToSubscriptionManagement(): void
    {
        $billing = $this->billing(role: 'admin', subscription: ['state' => 'past_due']);

        self::assertSame('https://test.example/predplatne', $billing['pay_url']);
        self::assertNull($billing['amount_due']);
        self::assertNull($billing['currency']);
    }

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: `pay_url` jde rovnou do `href`
     * v aplikaci. Kdyby se přebíralo cokoli, co server pošle, stačí podvržený
     * `javascript:` odkaz a máme skript v kontextu přihlášeného uživatele.
     */
    public function testNonWebPayUrlIsRefused(): void
    {
        $billing = $this->billing(role: 'admin', subscription: [
            'state'   => 'past_due',
            'pay_url' => 'javascript:alert(1)',
        ]);

        self::assertSame('https://test.example/predplatne', $billing['pay_url']);
    }

    /** Záporný dluh je nesmysl, který obrazovka nemá jak vyložit. */
    public function testNegativeAmountIsNotPassedOn(): void
    {
        $billing = $this->billing(role: 'admin', subscription: [
            'state'      => 'past_due',
            'amount_due' => -250,
        ]);

        self::assertNull($billing['amount_due']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Pomocné
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|null $subscription
     * @return array<string,mixed>
     */
    private function billing(string $role, ?array $subscription = null): array
    {
        $payload = $this->payload($role, managed: true, subscription: $subscription);

        self::assertIsArray($payload['billing']);

        return $payload['billing'];
    }

    /**
     * @param array<string,mixed>|null $subscription
     * @return array<string,mixed>
     */
    private function payload(string $role, bool $managed, ?array $subscription = null): array
    {
        $response = $this->invoke($role, $managed, $subscription);
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed>|null $subscription */
    private function invoke(
        string $role,
        bool $managed,
        ?array $subscription = null,
    ): \Psr\Http\Message\ResponseInterface {
        $config = new Config([
            'app'     => ['managed' => $managed],
            'license' => ['server_url' => 'https://test.example/'],
        ]);
        $db = new Connection($config);

        $action = new LicenseBillingAction(
            new LicenseService($db, $config, new LicenseTokenVerifier(), new LicenseClient($config)),
            new ManagedModeGuard($config),
            new BillingSnapshot($config),
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/license/billing')
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $this->state($subscription))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => $role, 'email' => 'ucetni@example.test']);

        return $action($request, (new ResponseFactory())->createResponse());
    }

    /** @param array<string,mixed>|null $subscription */
    private function state(?array $subscription): LicenseState
    {
        return new LicenseState(
            LicenseState::ACTIVE,
            'inst-test',
            'multi10',
            10,
            5,
            3,
            2,
            1_800_000_000,
            null,
            null,
            'MYU-TEST-0000-0001',
            '2026-08-21T09:00:00+00:00',
            true,
            false,
            $subscription,
        );
    }
}
