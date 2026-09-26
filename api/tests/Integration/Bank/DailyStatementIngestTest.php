<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankApiMonthlyStatements;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailAttachment;
use MyInvoice\Service\Bank\EmailNotice\EmailPdfStatementIngestor;
use MyInvoice\Tests\Support\KbDailyStatementPdfFactory;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Celý řetěz „denní PDF výpis v příloze e-mailu → měsíční výpis účtu".
 *
 * Banky bez API (KB, ČSOB, RB) posílají výpis e-mailem po každém pohybu. Test hlídá
 * tři věci, které se v tomhle řetězu lámou:
 *   1. denní výpis se v přehledu NEobjeví samostatně — skládá se do měsíčního výpisu
 *      účtu (jinak by měsíc s dvaceti pohyby znamenal dvacet výpisů v seznamu),
 *   2. měsíc si drží POČÁTEČNÍ zůstatek prvního dne a KONEČNÝ posledního — obojí je
 *      údaj banky, takže přehled stavů na účtech měsíc nepřeskočí,
 *   3. výpis k účtu, který firmě nepatří, se NEIMPORTUJE — stačilo by jinak poslat
 *      do schránky cizí výpis a stal by se dokladem naší firmy.
 *
 * Všechna data jsou syntetická (repozitář je veřejný) a PDF vzniká za běhu, aby se
 * testovala i textová vrstva — právě v ní se e-mailové přílohy lámou nejčastěji.
 */
#[Group('integration')]
final class DailyStatementIngestTest extends TestCase
{
    private PDO $pdo;
    private EmailPdfStatementIngestor $ingestor;
    private int $supplierId;
    private int $currencyId;
    private string $accountNumber;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->ingestor = $container->get(EmailPdfStatementIngestor::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $refs = $this->pdo->query(
            'SELECT (SELECT id FROM countries ORDER BY id LIMIT 1) AS country_id,
                    (SELECT id FROM currencies ORDER BY id LIMIT 1) AS currency_id,
                    (SELECT id FROM vat_rates ORDER BY id LIMIT 1) AS vat_rate_id'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($refs) || (int) ($refs['country_id'] ?? 0) <= 0) {
            $this->markTestSkipped('Missing supplier codebook prerequisites');
        }

        $ic = '99' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO supplier (company_name, ic, dic, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'Synthetic Daily Statement ' . bin2hex(random_bytes(4)),
            $ic, 'CZ' . $ic, 'Testovací 1', 'Praha', '11000',
            (int) $refs['country_id'],
            'stmt-' . bin2hex(random_bytes(8)) . '@example.invalid',
            (int) $refs['currency_id'], (int) $refs['vat_rate_id'],
        ]);
        $this->supplierId = (int) $this->pdo->lastInsertId();

        // Syntetické číslo účtu — nesmí kolidovat s ničím, co v DB už je.
        $this->accountNumber = '55' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals,
                 is_active, is_default, account_number, bank_code, bank_name)
             VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1, ?, '0100', 'Komerční banka')"
        )->execute([$this->supplierId, $this->accountNumber]);
        $this->currencyId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->supplierId)) {
            return;
        }
        $ids = $this->pdo->prepare('SELECT id FROM bank_statements WHERE supplier_id = ?');
        $ids->execute([$this->supplierId]);
        $statementIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);

        // Pořadí je dané cizími klíči: měsíční výpis odkazuje na pohyby DENNÍCH výpisů,
        // takže se nejdřív musí uvolnit všechny vazby a teprve pak mazat pohyby.
        $this->pdo->prepare('DELETE FROM bank_email_attachment_ingests WHERE supplier_id = ?')->execute([$this->supplierId]);
        foreach ($statementIds as $id) {
            $this->pdo->prepare('DELETE FROM bank_transaction_imports WHERE statement_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? OR monthly_statement_id = ?')->execute([$id, $id]);
            $this->pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
        }
        foreach ($statementIds as $id) {
            $this->pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM bank_statements WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->pdo->prepare('DELETE FROM supplier_bank_accounts WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
    }

    public function testDailyStatementsOfOneMonthMergeIntoSingleMonthlyStatement(): void
    {
        $first = $this->ingest($this->dailyPdf($this->accountNumber, '18.09.2026', '196', '10 000,00', '13 000,00', '3 000,00', '0,00', [
            ['description' => 'PŘÍCHOZÍ ÚHRADA', 'counterparty' => 'Druha Firma, s.r.o.', 'account' => '1111111111/0300', 'vs' => '5550045', 'ks' => '308', 'amount' => '   3 000,00'],
        ]));
        self::assertSame(1, $first['imported'], (string) json_encode($first['details'], JSON_UNESCAPED_UNICODE));

        $second = $this->ingest($this->dailyPdf($this->accountNumber, '19.09.2026', '197', '13 000,00', '12 500,00', '0,00', '500,00', [
            ['description' => 'OKAMŽITÁ ODCHOZÍ ÚHRADA', 'account' => '2222222222/0800', 'vs' => '5550099', 'amount' => '   -500,00'],
        ]));
        self::assertSame(1, $second['imported'], (string) json_encode($second['details'], JSON_UNESCAPED_UNICODE));

        // Denní výpisy existují jako doklad, ale v seznamu je vidět jen měsíc.
        $daily = $this->statements("period_kind = 'day'");
        self::assertCount(2, $daily);

        $visible = $this->statements(BankApiMonthlyStatements::visibleSql());
        self::assertCount(1, $visible, 'V přehledu musí zůstat jediný — měsíční — výpis.');
        $month = $visible[0];
        self::assertSame('PDF-2026-09', (string) $month['file_name']);
        self::assertSame('period', (string) $month['period_kind']);
        self::assertSame(2, (int) $month['transaction_count']);
        self::assertSame('10000.00', (string) $month['prev_balance'], 'Počáteční zůstatek měsíce = počáteční zůstatek prvního dne.');
        self::assertSame('12500.00', (string) $month['curr_balance'], 'Konečný zůstatek měsíce = konečný zůstatek posledního dne.');

        // Oba denní výpisy jsou podklady téhož měsíce a jejich PDF zůstávají ke stažení.
        $monthly = new BankApiMonthlyStatements($this->pdo);
        $evidence = $monthly->evidenceStatements((int) $month['id'], $this->supplierId);
        self::assertCount(2, $evidence);
        self::assertCount(2, $monthly->evidencePdfs((int) $month['id'], $this->supplierId));
        self::assertNull($month['pdf_hash'], 'Měsíc složený z víc dnů nesmí vydávat PDF jednoho dne za celý měsíc.');
    }

    public function testStatementOfAccountOutsideCompanyIsNotImported(): void
    {
        $foreign = '66' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $summary = $this->ingest($this->dailyPdf($foreign, '18.09.2026', '196', '10 000,00', '13 000,00', '3 000,00', '0,00', [
            ['description' => 'PŘÍCHOZÍ ÚHRADA', 'counterparty' => 'Cizí Firma, s.r.o.', 'account' => '1111111111/0300', 'vs' => '5550045', 'amount' => '   3 000,00'],
        ]));

        self::assertSame(0, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
        self::assertSame([], $this->statements('1 = 1'), 'Cizí výpis nesmí založit doklad naší firmy.');

        $log = $this->pdo->prepare('SELECT status, reason FROM bank_email_attachment_ingests WHERE supplier_id = ?');
        $log->execute([$this->supplierId]);
        $logged = $log->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($logged, 'Zamítnutí musí zůstat dohledatelné v logu.');
        self::assertSame('skipped_not_statement', (string) $logged['status']);
    }

    public function testDisabledAccountIngestsNothing(): void
    {
        $summary = $this->ingest(
            $this->dailyPdf($this->accountNumber, '18.09.2026', '196', '0,00', '0,00', '0,00', '0,00', []),
            ingestEnabled: false,
        );

        self::assertFalse($summary['enabled']);
        self::assertSame(0, $summary['considered']);
        self::assertSame([], $this->statements('1 = 1'));
    }

    public function testSameStatementTwiceDoesNotDuplicateMovements(): void
    {
        $pdf = $this->dailyPdf($this->accountNumber, '18.09.2026', '196', '10 000,00', '13 000,00', '3 000,00', '0,00', [
            ['description' => 'PŘÍCHOZÍ ÚHRADA', 'counterparty' => 'Druha Firma, s.r.o.', 'account' => '1111111111/0300', 'vs' => '5550045', 'amount' => '   3 000,00'],
        ]);
        self::assertSame(1, $this->ingest($pdf)['imported']);
        $repeat = $this->ingest($pdf);

        self::assertSame(0, $repeat['imported']);
        self::assertSame(1, $repeat['skipped']);
        $visible = $this->statements(BankApiMonthlyStatements::visibleSql());
        self::assertCount(1, $visible);
        self::assertSame(1, (int) $visible[0]['transaction_count']);
    }

    /**
     * Výpis, který si PŘED zavedením načítání výpisů vzala fronta příchozích
     * dokladů (nese jméno i adresu naší firmy, takže ho rozpoznávač dokladů
     * poslal do fronty a AI extrakce nad ním spadla na „chybí items"), se musí
     * dát načíst znovu jako výpis. Tentýž soubor má tentýž SHA, takže znovu
     * poslaný e-mail by jinak narazil na hotové rozhodnutí a zůstal navždy
     * ležet ve frontě dokladů.
     */
    public function testStatementAlreadyTakenByInvoiceQueueIsReassessed(): void
    {
        $pdf = $this->dailyPdf($this->accountNumber, '18.09.2026', '196', '10 000,00', '13 000,00', '3 000,00', '0,00', [
            ['description' => 'PŘÍCHOZÍ ÚHRADA', 'counterparty' => 'Treti Firma, s.r.o.', 'account' => '1111111111/0300', 'vs' => '5550045', 'amount' => '   3 000,00'],
        ]);
        $this->pdo->prepare(
            'INSERT INTO bank_email_attachment_ingests
                (supplier_id, filename, sha256, size_bytes, status, reason)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, 'vypis.pdf', hash('sha256', $pdf), strlen($pdf),
            'imported', 'Rozpoznán doklad naší firmy - přidáno do příchozích dokladů.',
        ]);

        $summary = $this->ingest($pdf);

        self::assertSame(1, $summary['imported'], 'Výpis zablokovaný starým rozhodnutím se musí naimportovat.');
        $log = $this->pdo->prepare(
            'SELECT status, bank_statement_id FROM bank_email_attachment_ingests WHERE supplier_id = ?'
        );
        $log->execute([$this->supplierId]);
        $rows = $log->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'Řádek logu se přepisuje, nezakládá se druhý.');
        self::assertSame('imported_statement', (string) $rows[0]['status']);
        self::assertNotNull($rows[0]['bank_statement_id']);
    }

    /**
     * @param list<array<string,string>> $rows
     */
    private function dailyPdf(
        string $account,
        string $date,
        string $number,
        string $prev,
        string $curr,
        string $credit,
        string $debit,
        array $rows,
    ): string {
        return KbDailyStatementPdfFactory::build($account, '0100', 'CZK', $date, $number, $prev, $curr, $credit, $debit, $rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function ingest(string $pdf, bool $ingestEnabled = true): array
    {
        $message = new BankEmailNoticeMessage(
            uid: random_int(1, 100000),
            messageId: '<' . bin2hex(random_bytes(8)) . '@example.invalid>',
            date: new \DateTimeImmutable('2026-09-18 20:00:00'),
            sender: 'vypisy@example.invalid',
            subject: 'Výpis z účtu',
            text: 'V příloze zasíláme výpis z účtu.',
            raw: '',
            attachments: [new EmailAttachment('vypis.pdf', 'application/pdf', $pdf)],
        );

        return $this->ingestor->ingestFromMessage(
            $this->supplierId,
            ['id' => null, 'ingest_pdf_statements' => $ingestEnabled],
            $message,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function statements(string $condition): array
    {
        $query = $this->pdo->prepare(
            'SELECT bs.id, bs.source, bs.period_kind, bs.file_name, bs.statement_date,
                    bs.prev_balance, bs.curr_balance, bs.transaction_count, bs.pdf_hash
               FROM bank_statements bs
              WHERE bs.supplier_id = ? AND ' . $condition . ' ORDER BY bs.id'
        );
        $query->execute([$this->supplierId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
