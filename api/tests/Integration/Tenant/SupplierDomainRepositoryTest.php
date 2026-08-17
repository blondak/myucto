<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tenant;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SupplierDomainRepositoryTest extends TestCase
{
    private Connection $db;
    private SupplierDomainRepository $domains;
    private TenantUrlResolver $urls;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $domainIds = [];
    private string $suffix = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->domains = $container->get(SupplierDomainRepository::class);
            $this->urls = $container->get(TenantUrlResolver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nebo DB nejsou dostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query(
            'SELECT MIN(id) FROM supplier'
        )->fetchColumn() ?: 0);
        if ($this->supplierId < 1 || $this->urls->canonicalBaseUrl() === '') {
            $this->markTestSkipped('Chybí supplier nebo app.url.');
        }
        $this->suffix = bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        if ($this->domainIds !== []) {
            $marks = implode(',', array_fill(0, count($this->domainIds), '?'));
            $this->db->pdo()->prepare("DELETE FROM supplier_domains WHERE id IN ({$marks})")
                ->execute($this->domainIds);
        }
        $this->db->close();
    }

    public function testPerPurposePrimariesAliasesAndImmediateCanonicalFallback(): void
    {
        $canonical = $this->urls->canonicalBaseUrl();
        self::assertSame($canonical, $this->urls->portalBaseUrl($this->supplierId));
        self::assertSame($canonical, $this->urls->publicBaseUrl($this->supplierId));

        $portal = $this->createVerified('portal', 'portal');
        $this->domains->activate($this->supplierId, $portal, true, 0);
        self::assertSame('https://portal-' . $this->suffix . '.example.test', $this->urls->portalBaseUrl($this->supplierId));
        self::assertSame($canonical, $this->urls->publicBaseUrl($this->supplierId));

        $public = $this->createVerified('links', 'public_links');
        $this->domains->activate($this->supplierId, $public, true, 0);
        self::assertSame('https://portal-' . $this->suffix . '.example.test', $this->urls->portalBaseUrl($this->supplierId));
        self::assertSame('https://links-' . $this->suffix . '.example.test', $this->urls->publicBaseUrl($this->supplierId));

        $both = $this->createVerified('all', 'all');
        $activeBoth = $this->domains->activate($this->supplierId, $both, true, 0);
        self::assertTrue($activeBoth['is_primary_portal']);
        self::assertTrue($activeBoth['is_primary_public']);
        self::assertSame('https://all-' . $this->suffix . '.example.test', $this->urls->portalBaseUrl($this->supplierId));
        self::assertSame('https://all-' . $this->suffix . '.example.test', $this->urls->publicBaseUrl($this->supplierId));

        try {
            $this->db->pdo()->prepare(
                'UPDATE supplier_domains SET is_primary_portal = 1 WHERE id = ?'
            )->execute([$portal]);
            self::fail('DB musí zabránit dvěma aktivním primárním portálovým doménám.');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        // Vypnutí primární domény přepne resolver na aktivní alias. Teprve po
        // vypnutí všech odpovídajících aliasů se odkazy okamžitě vrátí na app.url.
        $this->domains->disable($this->supplierId, $both, 0);
        self::assertSame('https://portal-' . $this->suffix . '.example.test', $this->urls->portalBaseUrl($this->supplierId));
        self::assertSame('https://links-' . $this->suffix . '.example.test', $this->urls->publicBaseUrl($this->supplierId));
        $this->domains->disable($this->supplierId, $portal, 0);
        $this->domains->disable($this->supplierId, $public, 0);
        self::assertSame($canonical, $this->urls->portalBaseUrl($this->supplierId));
        self::assertSame($canonical, $this->urls->publicBaseUrl($this->supplierId));
    }

    private function createVerified(string $prefix, string $purpose): int
    {
        $domain = $this->domains->create(
            $this->supplierId,
            $prefix . '-' . $this->suffix . '.example.test',
            $purpose,
            0,
        );
        $id = (int) $domain['id'];
        $this->domainIds[] = $id;
        $this->domains->recordVerification($this->supplierId, $id, true, null, 0);
        return $id;
    }
}
