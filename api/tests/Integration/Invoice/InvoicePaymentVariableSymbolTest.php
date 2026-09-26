<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\MoneyS3XmlExporter;
use MyInvoice\Service\Export\StereoXmlExporter;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Samostatný platební VS vydané faktury (#249): uložení a zachování při úpravě,
 * efektivní VS v detailu, e-mailu a exportech — číslo dokladu zůstává beze změny.
 */
#[Group('integration')]
final class InvoicePaymentVariableSymbolTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private InvoiceEmailVarsBuilder $mailVars;
    private IsdocExporter $isdoc;
    private MoneyS3XmlExporter $money;
    private StereoXmlExporter $stereo;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $supplierId = 0;

    /** @var int[] */
    private array $ids = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(InvoiceRepository::class);
            $this->mailVars = $c->get(InvoiceEmailVarsBuilder::class);
            $this->isdoc = $c->get(IsdocExporter::class);
            $this->money = $c->get(MoneyS3XmlExporter::class);
            $this->stereo = $c->get(StereoXmlExporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id FROM currencies
              WHERE code = 'CZK' AND is_active = 1 AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s účtem.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí klient nebo uživatel.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->ids as $id) {
            $this->db->pdo()->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
    }

    private function create(?string $paymentVs): int
    {
        $id = $this->repo->createDraft([
            'invoice_type' => 'invoice',
            'client_id' => $this->clientId,
            'issue_date' => '2099-03-01',
            'tax_date' => '2099-03-01',
            'due_date' => '2099-03-15',
            'currency_id' => $this->currencyId,
            'language' => 'cs',
            'varsymbol' => '2099-249-' . random_int(1000, 9999),
            'payment_variable_symbol' => $paymentVs,
        ], $this->userId);
        $this->ids[] = $id;
        $this->db->pdo()->prepare(
            "UPDATE invoices SET status = 'issued', total_without_vat = 1000, total_vat = 210, total_with_vat = 1210 WHERE id = ?"
        )->execute([$id]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function updatePayload(array $invoice): array
    {
        return [
            'client_id' => $invoice['client_id'], 'issue_date' => $invoice['issue_date'],
            'tax_date' => $invoice['tax_date'], 'due_date' => $invoice['due_date'],
            'currency_id' => $invoice['currency_id'], 'language' => 'cs',
        ];
    }

    public function testPaymentSymbolIsStoredAndSurvivesUpdateWithoutKey(): void
    {
        $id = $this->create('16');
        $invoice = $this->repo->find($id);
        self::assertSame('16', $invoice['payment_variable_symbol']);
        self::assertSame('16', $invoice['payment_varsymbol']);
        self::assertStringStartsWith('2099-249-', (string) $invoice['varsymbol'], 'Číslo dokladu zůstává nezávislé na platebním VS.');

        $this->repo->updateDraft($id, $this->updatePayload($invoice));
        self::assertSame('16', $this->repo->find($id)['payment_variable_symbol'], 'Úprava bez klíče nesmí platební VS smazat.');

        $this->repo->updateDraft($id, $this->updatePayload($invoice) + ['payment_variable_symbol' => null]);
        $cleared = $this->repo->find($id);
        self::assertNull($cleared['payment_variable_symbol']);
        self::assertSame(substr((string) preg_replace('/\D/', '', (string) $cleared['varsymbol']), 0, 10), $cleared['payment_varsymbol']);
    }

    public function testPaymentSymbolIsNotUnique(): void
    {
        $a = $this->create('12345');
        $b = $this->create('12345');

        self::assertSame('12345', $this->repo->find($a)['payment_varsymbol']);
        self::assertSame('12345', $this->repo->find($b)['payment_varsymbol']);
    }

    public function testMailAndExportsUsePaymentSymbol(): void
    {
        $invoice = $this->repo->find($this->create('16'));

        self::assertSame('16', $this->mailVars->build($invoice, true, 'cs')['payment_varsymbol']);
        self::assertStringContainsString('<VariableSymbol>16</VariableSymbol>', $this->isdoc->buildXml($invoice));
        self::assertStringContainsString('<VarSymbol>16</VarSymbol>', $this->money->buildXml([$invoice]));
        self::assertStringContainsString('<VariableSymbol>16</VariableSymbol>', $this->stereo->buildXml([$invoice]));
    }
}
