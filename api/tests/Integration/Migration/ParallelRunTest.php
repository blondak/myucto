<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\Shared\ParallelRun\EpoVatFilingReader;
use MyInvoice\Service\Migration\Shared\ParallelRun\MoneyS3Source;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunException;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunInput;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunReconciliation;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola souběhu nad firmou převedenou ze syntetické agendy Money S3: měsíc porovnaný
 * se zálohou (bez zápisu do MyÚčta), s předvahou a podáními starého programu, a cyklus
 * zařazení rozdílů. Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class ParallelRunTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private string $tmp = '';
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prun_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        SyntheticAgenda::writeLz($this->tmp . '/agenda.lz');

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->importedCompany();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testBackupComparedWithoutWritingMatchesEveryMonth(): void
    {
        $before = $this->footprint();
        $source = new MoneyS3Source();
        foreach ([2024 => range(1, 12), 2025 => [1, 3, 6, 12]] as $year => $months) {
            foreach ($months as $month) {
                $snapshot = $source->snapshot($year, $month, [], [], ['backup_dir' => $this->extract()]);
                $result = $this->reconciliation()->run($this->supplierId, $year, $month, $snapshot);
                $criteria = array_column($result['criteria'], null, 'key');
                self::assertSame('ok', $criteria['K1']['status'], "{$month}/{$year} K1: " . json_encode($criteria['K1']['differences'], JSON_UNESCAPED_UNICODE));
                // Doklady, které převod záměrně nepřebírá, musí kontrola ukázat jako rozdíl knihy:
                // nezaúčtovaný koncept FP24003 z uzavřeného roku a nulový pokladní doklad PP25002.
                $expected = match (sprintf('%04d-%02d', $year, $month)) {
                    '2024-12' => ['K5:purchase_invoices' => [0.0, 1.0]],
                    '2025-03' => ['K5:cash' => [1.0, 2.0]],
                    default => [],
                };
                $found = [];
                foreach ($criteria['K5']['differences'] as $d) {
                    $found[$d['id']] = [$d['values'][0]['mine'], $d['values'][0]['theirs']];
                }
                self::assertSame($expected, $found, "{$month}/{$year} K5: " . json_encode($criteria['K5'], JSON_UNESCAPED_UNICODE));
            }
        }
        self::assertSame($before, $this->footprint(), 'Kontrola nesmí do účetnictví nic zapsat.');
    }

    public function testTrialBalanceReportFindsChangedAccount(): void
    {
        $csv = SyntheticAgenda::trialBalanceCsv2024();
        $ok = $this->run2024($csv);
        self::assertSame('ok', $ok['status'], json_encode($ok['criteria'], JSON_UNESCAPED_UNICODE));

        $changed = str_replace('10 300,00', '10 400,00', (string) iconv('CP1250', 'UTF-8', $csv));
        $result = $this->run2024($changed);
        self::assertSame('differences', $result['status']);
        self::assertSame(['K1:518'], array_column($result['criteria'][0]['differences'], 'id'));
    }

    /**
     * Zdroj, který nepodal nic jiného než MyÚčto, sedí; změněný řádek přiznání a chybějící
     * doklad v kontrolním hlášení se ukážou s číslem řádku a odkazem na doklad.
     */
    public function testVatReturnAndControlStatementAgainstFiling(): void
    {
        $dph = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, 2024, 3)['xml'];
        $kh = $this->container->get(KontrolniHlaseniBuilder::class)->build($this->supplierId, 2024, 3)['xml'];
        self::assertNotEmpty((new EpoVatFilingReader())->read($dph, 'dphdp3')['values'], 'Březen 2024 má vydanou fakturu v přiznání.');

        $same = $this->runFiles(2024, 3, [ParallelRunInput::VAT_RETURN => $dph, ParallelRunInput::CONTROL_STATEMENT => $kh]);
        self::assertSame('ok', $same['status'], json_encode($same['criteria'], JSON_UNESCAPED_UNICODE));

        $changedDph = (string) preg_replace('/obrat23="(\d+)"/', 'obrat23="99"', $dph, 1);
        $khRows = (new EpoVatFilingReader())->read($kh, 'dphkh1')['rows'];
        self::assertNotEmpty($khRows);
        $withoutRows = (string) preg_replace('/<VetaA4 [^>]*\/>/', '', $kh);
        $diff = $this->runFiles(2024, 3, [ParallelRunInput::VAT_RETURN => $changedDph, ParallelRunInput::CONTROL_STATEMENT => $withoutRows]);
        $k9 = $diff['criteria'][0];
        self::assertSame('K9', $k9['key']);
        $byId = array_column($k9['differences'], null, 'id');
        self::assertSame('1', $byId['K9:dphdp3:Veta1.obrat23']['label']);
        $missing = array_values(array_filter($k9['differences'], static fn (array $d): bool => $d['note'] === 'missing_in_source'));
        self::assertNotEmpty($missing, 'Řádek A.4, který zdroj nemá.');
        self::assertSame('invoice', $missing[0]['link']['type'] ?? null, 'Rozdíl odkazuje na vydanou fakturu v MyÚčtu.');
    }

    public function testSaldoDifferencesAndCycle(): void
    {
        $service = $this->container->get(ParallelRunService::class);
        // Přijatá faktura je v MyÚčtu uhrazená; zdroj ji vede jako otevřenou pod vlastním
        // číslem dokladu (ne číslem dodavatele).
        $saldo = "Účet;Doklad;Firma;Zbývá\n321000;FP24001;Dodavatel Alfa s.r.o.;1 000,00\n";
        $check = $service->check($this->supplierId, $this->userId, '2024-12', 'money_s3', [ParallelRunInput::SALDO => $saldo], [ParallelRunInput::SALDO => 'saldo.csv']);

        self::assertSame('differences', $check['status']);
        $criteria = array_column($check['result']['criteria'], null, 'key');
        $k7 = array_column($criteria['K7']['differences'], null, 'id');
        self::assertSame('open_only_in_source', $k7['K7:321|FP24001']['note'] ?? null, json_encode($criteria, JSON_UNESCAPED_UNICODE));
        self::assertSame('differences', $criteria['K6']['status']);
        self::assertFalse($check['classification']['can_close']);

        $ids = array_merge(array_column($criteria['K6']['differences'], 'id'), array_column($criteria['K7']['differences'], 'id'));
        foreach ($ids as $id) {
            $check = $service->classify($this->supplierId, $check['id'], $id, 'migration', null, $this->userId);
        }
        try {
            $service->close($this->supplierId, $check['id'], $this->userId, null);
            self::fail('Rozdíl převodu musí uzavření cyklu zablokovat.');
        } catch (ParallelRunException $e) {
            self::assertSame('cycle_migration_differences', $e->errorCode);
        }
        foreach ($ids as $id) {
            $check = $service->classify($this->supplierId, $check['id'], $id, 'source', 'Úhrada chybí v Money.', $this->userId);
        }
        $closed = $service->close($this->supplierId, $check['id'], $this->userId, 'Měsíc odsouhlasen.');
        self::assertSame('closed', $closed['cycle_status']);
        self::assertSame(count($ids), $closed['classification']['source']);

        $again = $service->check($this->supplierId, $this->userId, '2024-12', 'money_s3', [ParallelRunInput::SALDO => $saldo], []);
        self::assertSame(0, $again['classification']['unclassified'], 'Opakovaná kontrola převezme zařazení trvajících rozdílů.');
        self::assertSame('open', $again['cycle_status']);
        self::assertCount(2, $service->history($this->supplierId));

        $csv = $service->exportCsv($this->supplierId, $again['id']);
        self::assertStringContainsString('FP24001', $csv);
    }

    /**
     * Banka, střediska a výkazy k 31. 12. 2024: zůstatky účtů a obraty středisek podle deníku
     * Money, výkaz vlastní rozvahy zpět jako zdroj; změněný řádek se ukáže.
     */
    public function testBankCostCentersAndStatements(): void
    {
        $bs = $this->container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class)
            ->balanceSheet($this->supplierId, $this->periodId(2024), '2024-12-31', 'full');
        $rows = "Označení;Strana;Běžné období\n";
        foreach ($bs['assets'] as $r) {
            $rows .= $r['row_code'] . ';A;' . number_format((float) $r['net'], 2, ',', '') . "\n";
        }
        foreach ($bs['liabilities'] as $r) {
            $rows .= $r['display_code'] . ';P;' . number_format((float) $r['amount'], 2, ',', '') . "\n";
        }
        $files = [
            // 221001 = běžný účet 3000000004/0100: PS 50 000 − 12 100 + 24 200 + 50.
            ParallelRunInput::BANK_BALANCES => "Účet;Měna;Zůstatek\n3000000004/0100;CZK;62 150,00\n",
            // Prosinec: dohadná položka ID24001 300 Kč bez střediska.
            ParallelRunInput::COST_CENTERS => "Středisko;Výnosy;Náklady\nBez střediska;0;300\n",
            ParallelRunInput::BALANCE_SHEET => $rows,
        ];
        $result = $this->runFiles(2024, 12, $files);
        $criteria = array_column($result['criteria'], null, 'key');
        foreach (['K8', 'K12', 'K13'] as $key) {
            self::assertSame('ok', $criteria[$key]['status'] ?? null, $key . ': ' . json_encode($criteria[$key] ?? null, JSON_UNESCAPED_UNICODE));
        }
        // Únor: FP24001 10 000 Kč na středisku REZIE.
        $february = $this->runFiles(2024, 2, [ParallelRunInput::COST_CENTERS => "Středisko;Výnosy;Náklady\nREZIE;0;10 000\n"]);
        self::assertSame('ok', $february['status'], json_encode($february['criteria'], JSON_UNESCAPED_UNICODE));

        // Úvěrový účet kreditní karty (dluh na 231) se s 221 nesrovnává a srovnání běžného
        // účtu nesmí shodit.
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, currency, account_canonical, bank_code_norm, kind, source)
             VALUES (?, 'Kreditní karta', '19-5000000007', '0100', 'CZK', '195000000007', '0100', 'credit_card', 'manual')"
        )->execute([$this->supplierId]);
        $withCard = array_column($this->runFiles(2024, 12, $files)['criteria'], null, 'key');
        self::assertSame('ok', $withCard['K8']['status'] ?? null, json_encode($withCard['K8'] ?? null, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $withCard['K8']['summary']['accounts_myucto'] ?? null, 'Kreditka mezi bankovní účty (221) nepatří.');

        $files[ParallelRunInput::BANK_BALANCES] = "Účet;Měna;Zůstatek\n3000000004/0100;CZK;62 000,00\n";
        $files[ParallelRunInput::COST_CENTERS] = "Středisko;Výnosy;Náklady\nREZIE;0;100\n";
        $files[ParallelRunInput::BALANCE_SHEET] = "Označení;Strana;Běžné období\nAKTIVA;A;1\n";
        $criteria = array_column($this->runFiles(2024, 12, $files)['criteria'], null, 'key');
        self::assertCount(1, $criteria['K8']['differences']);
        self::assertSame(['K12:REZIE'], array_column($criteria['K12']['differences'], 'id'));
        self::assertSame(['K13:balance_sheet:A:AKTIVA'], array_column($criteria['K13']['differences'], 'id'));
    }

    public function testMonthWithoutPeriodIsRejected(): void
    {
        $this->expectException(ParallelRunException::class);
        $this->runFiles(2019, 5, [ParallelRunInput::TRIAL_BALANCE => "311;0;0;0\n"]);
    }

    /** @return array<string,mixed> */
    private function run2024(string $csv): array
    {
        return $this->runFiles(2024, 12, [ParallelRunInput::TRIAL_BALANCE => $csv]);
    }

    /**
     * @param array<string,string> $files
     * @return array<string,mixed>
     */
    private function runFiles(int $year, int $month, array $files): array
    {
        $snapshot = (new MoneyS3Source())->snapshot($year, $month, $files, [], []);
        return $this->reconciliation()->run($this->supplierId, $year, $month, $snapshot);
    }

    private function periodId(int $year): int
    {
        return (int) $this->container->get(\MyInvoice\Repository\AccountingPeriodRepository::class)->findByYear($this->supplierId, $year)['id'];
    }

    private function reconciliation(): ParallelRunReconciliation
    {
        return $this->container->get(ParallelRunReconciliation::class);
    }

    private function extract(): string
    {
        $dir = $this->tmp . '/agenda-' . bin2hex(random_bytes(3));
        Ms3Backup::extract($this->tmp . '/agenda.lz', $dir);
        return $dir;
    }

    private function importedCompany(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "soubeh@example.invalid", ?, ?, ?, ?)'
        )->execute([SyntheticAgenda::NAME, $this->czId, SyntheticAgenda::ICO, $this->currencyId, $this->vatRateId, 'tax_evidence']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);

        $protocol = $this->container->get(MoneyS3Importer::class)->run($id, $this->userId, Ms3Backup::open($this->extract()), new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], []));
        self::assertFalse($protocol->hasErrors(), 'Převod syntetické agendy musí projít.');
        return $id;
    }

    /** @return array<string,int> */
    private function footprint(): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'invoices', 'purchase_invoices', 'cash_documents', 'bank_statements', 'accounting_periods', 'migration_parallel_checks'] as $table) {
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
            $stmt->execute([$this->supplierId]);
            $out[$table] = (int) $stmt->fetchColumn();
        }
        return $out;
    }
}
