<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\ReminderService;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * #60 — ruční příjemci upomínky z modalu. Obě cesty končí před renderem PDF,
 * takže bez podpory ručních příjemců by služba adresy ignorovala a pokusila se
 * odeslat na resolver (mock Maileru to zakazuje).
 */
#[Group('integration')]
final class ReminderManualRecipientsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $invoiceId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje nakonfigurovanou testovací DB.');
        }
        $this->container = Bootstrap::buildApp()->getContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $sourceId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $sourceId, 'Spusť ci-seed.php pro syntetické testovací číselníky.');
        $supplierId = $this->createIsolatedSupplier($this->pdo, $sourceId);
        $currencyId = (int) $this->pdo->query("SELECT MIN(id) FROM currencies WHERE code = 'CZK'")->fetchColumn();
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $countryId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, main_email,
                country_id, currency_default_id, is_customer)
             VALUES (?, 'Test ručních příjemců', 'Testovací 1', 'Praha', '11000', 'fakturace@example.test', ?, ?, 1)"
        )->execute([$supplierId, $countryId, $currencyId]);
        $clientId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date,
                currency_id, total_without_vat, total_with_vat, status, payment_method, created_by)
             VALUES (?, 'REM-60', ?, CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 5 DAY, ?, 100, 100, 'issued', 'bank_transfer', ?)"
        )->execute([$supplierId, $clientId, $currencyId, $userId]);
        $this->invoiceId = (int) $this->pdo->lastInsertId();

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplate');
        $mailer->expects(self::never())->method('sendTemplateDetailed');
        $this->container->set(Mailer::class, $mailer);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testInvalidManualCcIsRejectedBeforeSending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Neplatný email: stavbyvedouci');
        $this->container->get(ReminderService::class)->send($this->invoiceId, cc: ['stavbyvedouci']);
    }

    public function testExplicitEmptyToReplacesResolvedRecipients(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(ReminderService::NO_RECIPIENT_MESSAGE);
        $this->container->get(ReminderService::class)->send($this->invoiceId, to: [], cc: ['nakupci@example.test']);
    }
}
