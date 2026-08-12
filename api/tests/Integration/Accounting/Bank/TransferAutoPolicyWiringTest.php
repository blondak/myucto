<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface;
use MyInvoice\Service\Accounting\Bank\TransferPairService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezi vlastními účty se musí řídit NASTAVENÍM TENANTA, ne konstantou.
 *
 * V repu dlouho žila třída `SuggestOnlyTransferPolicy`, jejíž `level()` vracel
 * natvrdo 'suggest'. Do kontejneru zapojená nebyla (bind míří na
 * {@see AutoPostingPolicyService}), ale vypadala jako aktivní implementace a
 * svedla diagnostiku nezaúčtovaných převodů na špatnou stopu. Byla smazána a
 * tenhle test drží bránu: kdyby ji někdo vrátil a zapojil, spadne to tady.
 */
#[Group('integration')]
final class TransferAutoPolicyWiringTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
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

    private function setPolicy(string $operationType, string $level): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO auto_posting_policy (supplier_id, operation_type, level)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level)'
        )->execute([$this->supplierId, $operationType, $level]);
    }

    public function testContainerBindsTheTenantAwarePolicy(): void
    {
        $policy = $this->container->get(TransferAutoPolicyInterface::class);

        self::assertInstanceOf(
            AutoPostingPolicyService::class,
            $policy,
            'Politika převodů musí číst nastavení tenanta, ne vracet konstantu.',
        );
    }

    public function testTransferPairServiceGetsTheSamePolicy(): void
    {
        $service = $this->container->get(TransferPairService::class);
        $injected = (new \ReflectionProperty($service, 'policy'))->getValue($service);

        self::assertInstanceOf(AutoPostingPolicyService::class, $injected);
    }

    /** `bank.transfer.own = auto` z UI se musí propsat až do level(). */
    public function testTenantAutoLevelIsHonoured(): void
    {
        $this->setPolicy(OperationType::BANK_TRANSFER_OWN, 'auto');
        $this->setPolicy('detector.own_transfer', 'auto');

        $policy = $this->container->get(TransferAutoPolicyInterface::class);

        self::assertSame('auto', $policy->level($this->supplierId));
    }

    /** Detektor je STROP: `detector.own_transfer = suggest` srazí i 'auto' operace. */
    public function testDetectorLevelCapsTheOperationLevel(): void
    {
        $this->setPolicy(OperationType::BANK_TRANSFER_OWN, 'auto');
        $this->setPolicy('detector.own_transfer', 'suggest');

        self::assertSame('suggest', $this->container->get(TransferAutoPolicyInterface::class)->level($this->supplierId));
    }

    public function testTenantOffLevelIsHonoured(): void
    {
        $this->setPolicy(OperationType::BANK_TRANSFER_OWN, 'off');

        self::assertSame('off', $this->container->get(TransferAutoPolicyInterface::class)->level($this->supplierId));
    }

    /** Kdo si nic nenastavil, nesmí dostat jiné chování než dřív. */
    public function testUnconfiguredTenantDefaultsToSuggest(): void
    {
        $this->db->pdo()->prepare('DELETE FROM auto_posting_policy WHERE supplier_id = ?')->execute([$this->supplierId]);

        self::assertSame(
            'suggest',
            $this->container->get(TransferAutoPolicyInterface::class)->level($this->supplierId),
            'Bez nastavení zůstává konzervativní suggest.',
        );
    }

    /** Mrtvá třída se nesmí vrátit — vypadala jako zapojená a mátla diagnostiku. */
    public function testHardcodedSuggestOnlyPolicyIsGone(): void
    {
        self::assertFalse(
            class_exists('MyInvoice\\Service\\Accounting\\Bank\\SuggestOnlyTransferPolicy'),
            'Politika s natvrdo vráceným "suggest" nesmí v repu existovat.',
        );
    }
}
