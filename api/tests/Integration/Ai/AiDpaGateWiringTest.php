<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ai;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Import\OllamaClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DPA výjimka lokální Ollamy musí fungovat v produkčním kontejneru, ne jen v testu
 * s ručně předaným klientem. PHP-DI autowiring volitelný parametr (`= null`) nevyplní,
 * takže s `?OllamaClient $ollama = null` byla výjimka v aplikaci vždy vypnutá.
 */
#[Group('integration')]
final class AiDpaGateWiringTest extends TestCase
{
    private Connection $db;
    private AiDpaGate $gate;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DI kontejner.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->gate = $container->get(AiDpaGate::class);
            $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if ($this->supplierId <= 0) {
            self::markTestSkipped('Chybí testovací firma.');
        }
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testContainerWiresOllamaClientIntoGate(): void
    {
        $prop = new \ReflectionProperty(AiDpaGate::class, 'ollama');
        self::assertInstanceOf(OllamaClient::class, $prop->getValue($this->gate));
    }

    public function testLoopbackOllamaIsExemptThroughContainer(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET ollama_base_url = 'http://127.0.0.1:11434', ollama_default_model = 'vision-model:7b',
                                 ai_dpa_confirmations = NULL WHERE id = ?"
        )->execute([$this->supplierId]);

        self::assertTrue($this->gate->isExempt($this->supplierId, 'ollama'));
        self::assertTrue($this->gate->isConfirmed($this->supplierId, 'ollama'));
        self::assertFalse($this->gate->isExempt($this->supplierId, 'openai'));
    }
}
