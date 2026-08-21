<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\System;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\InstanceEntitlement;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozsah zaplacené služby: co doručil licenční server vs. co je v konfiguraci.
 *
 * Testuje se proti databázi schválně — celá pointa je v tom, ODKUD se hodnota
 * vezme, a to je právě místo, kde se čte `license.instance_info`.
 */
#[Group('integration')]
final class InstanceEntitlementTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;

    /** Původní obsah, aby test nezanechal stopu ve sdílené testovací databázi. */
    private ?string $original = null;
    private bool $restore = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php chybí');
        }

        $container = Bootstrap::buildApp()->getContainer();
        self::assertNotNull($container);
        $this->db  = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();

        if (!$this->db->hasTable('license') || !$this->db->hasColumn('license', 'instance_info')) {
            $this->markTestSkipped('migrace 1524 neproběhla');
        }

        $raw = $this->pdo->query('SELECT instance_info FROM license WHERE id = 1')?->fetchColumn();
        $this->original = is_string($raw) ? $raw : null;
        $this->restore  = true;
    }

    protected function tearDown(): void
    {
        if (!$this->restore) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE license SET instance_info = ? WHERE id = 1');
        $stmt->execute([$this->original]);
    }

    /** @param array<string,mixed>|null $info */
    private function deliver(?array $info): void
    {
        $stmt = $this->pdo->prepare('UPDATE license SET instance_info = ? WHERE id = 1');
        $stmt->execute([$info === null ? null : json_encode($info, JSON_UNESCAPED_UNICODE)]);
    }

    private function raw(string $value): void
    {
        $stmt = $this->pdo->prepare('UPDATE license SET instance_info = ? WHERE id = 1');
        $stmt->execute([$value]);
    }

    /** @param array<string,mixed> $instance */
    private function entitlement(array $instance = []): InstanceEntitlement
    {
        return new InstanceEntitlement($this->db, new Config(['instance' => $instance]));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ Jádro položky: po dokoupení místa platí server, ne konfigurace.
     *
     * Do `cfg.local.php` zapisuje kvótu zřizování při založení instance a pak
     * už nikdy — kdyby vyhrávala, instalace by napořád ukazovala původní objem
     * a vyzývala k nákupu něčeho, co si zákazník právě koupil.
     */
    public function testDeliveredQuotaBeatsConfiguration(): void
    {
        $this->deliver(['quota_gb' => 7, 'plan' => 'accounting_10']);

        $e = $this->entitlement(['quota_gb' => 2, 'plan' => 'invoicing']);

        self::assertSame(7.0, $e->quotaGb());
        self::assertSame(7 * 1024 * 1024 * 1024, $e->quotaBytes());
        self::assertSame('accounting_10', $e->plan());
        self::assertSame(InstanceEntitlement::SOURCE_LICENSE, $e->quotaSource());
    }

    /** Než doběhne první obnova, platí hodnota ze zřizování. */
    public function testConfigurationIsUsedUntilTheServerSaysOtherwise(): void
    {
        $this->deliver(null);

        $e = $this->entitlement(['quota_gb' => 2, 'plan' => 'invoicing']);

        self::assertSame(2.0, $e->quotaGb());
        self::assertSame('invoicing', $e->plan());
        self::assertSame(InstanceEntitlement::SOURCE_CONFIG, $e->quotaSource());
    }

    /** Self-hosted instalace: nikdo nic neposlal a v konfiguraci nic není. */
    public function testUnknownStaysUnknown(): void
    {
        $this->deliver(null);

        $e = $this->entitlement([]);

        self::assertNull($e->quotaGb());
        self::assertNull($e->quotaBytes());
        self::assertNull($e->plan());
        self::assertSame(InstanceEntitlement::SOURCE_NONE, $e->quotaSource());
    }

    /**
     * ⚠️ Nula NENÍ kvóta. Nulový objem znamená 100 % obsazeno a spustil by
     * režim jen pro čtení na instalaci, která nic neprovedla.
     */
    public function testZeroAndNegativeAreTreatedAsUnknown(): void
    {
        foreach ([0, -1, '0', ''] as $value) {
            $this->deliver(['quota_gb' => $value]);
            self::assertNull(
                $this->entitlement([])->quotaGb(),
                'kvóta ' . var_export($value, true) . ' se musí chovat jako neznámá',
            );
        }
    }

    /** Rozbitý JSON nesmí shodit aplikaci — spadne se na konfiguraci. */
    public function testBrokenPayloadFallsBackInsteadOfFailing(): void
    {
        $this->raw('{tohle není JSON');

        $e = $this->entitlement(['quota_gb' => 2]);

        self::assertSame(2.0, $e->quotaGb());
        self::assertSame(InstanceEntitlement::SOURCE_CONFIG, $e->quotaSource());
    }

    /**
     * Server pošle jen část — chybějící pole se doplní z konfigurace, ne aby
     * celý blok propadl. Zákazník dokupuje místo, ne tarif.
     */
    public function testPartialPayloadFallsBackFieldByField(): void
    {
        $this->deliver(['quota_gb' => 22]);

        $e = $this->entitlement(['quota_gb' => 2, 'plan' => 'accounting_10', 'managed_since' => '2026-01-01']);

        self::assertSame(22.0, $e->quotaGb());
        self::assertSame('accounting_10', $e->plan());
        self::assertSame('2026-01-01', $e->managedSince());
    }

    /** Pole, která server přidá později, se dostanou ven beze změny kódu. */
    public function testUnknownFieldsSurviveVerbatim(): void
    {
        $this->deliver(['quota_gb' => 7, 'quota_change_pending' => true, 'state' => 'active']);

        $raw = $this->entitlement()->deliveredRaw();

        self::assertTrue($raw['quota_change_pending']);
        self::assertSame('active', $raw['state']);
    }

    /** Hodnota se čte jednou za request; po zápisu se cache musí dát zahodit. */
    public function testCacheIsPerRequestAndCanBeDropped(): void
    {
        $this->deliver(['quota_gb' => 7]);
        $e = $this->entitlement();
        self::assertSame(7.0, $e->quotaGb());

        $this->deliver(['quota_gb' => 22]);
        self::assertSame(7.0, $e->quotaGb(), 'v rámci requestu se hodnota nemění');

        $e->forget();
        self::assertSame(22.0, $e->quotaGb());
    }
}
