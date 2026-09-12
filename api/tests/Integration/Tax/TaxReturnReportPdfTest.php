<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Tax\Return\TaxReturnAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Pdf\TaxReturnReportPdfRenderer;
use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\TaxReturnReportService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Pracovní PDF sestava přiznání (DPPO i DPFO): sestava se čte ze STEJNÉHO XML jako
 * export pro EPO, takže každá částka v sestavě musí sedět na XML přečtené nezávislým
 * parserem. Dál: PDF vznikne, nic se nearchivuje, bez `reports.export` je 403 a firma
 * jiného typu (cizí scope) nic nedostane.
 *
 * Syntetické firmy v transakci s rollbackem, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class TaxReturnReportPdfTest extends TestCase
{
    private const YEAR = 2047;

    private Connection $db;
    private TaxReturnAction $action;
    private TaxReturnService $returns;
    private TaxReturnReportService $reports;
    private TaxReturnReportPdfRenderer $renderer;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private int $userId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(TaxReturnAction::class);
            $this->returns = $container->get(TaxReturnService::class);
            $this->reports = $container->get(TaxReturnReportService::class);
            $this->renderer = $container->get(TaxReturnReportPdfRenderer::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
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

    public function testRouteIsRegisteredForPdfAction(): void
    {
        $found = false;
        foreach (Bootstrap::buildApp()->getRouteCollector()->getRoutes() as $route) {
            if ($route->getPattern() === '/api/tax-return/{type}/{year:[0-9]+}/pdf' && in_array('GET', $route->getMethods(), true)) {
                self::assertSame([TaxReturnAction::class, 'pdf'], $route->getCallable());
                $found = true;
            }
        }
        self::assertTrue($found, 'Routa GET /api/tax-return/{type}/{year}/pdf musí existovat.');
    }

    public function testPoReportReadsEveryAmountFromTheExportXml(): void
    {
        $supplierId = $this->createPoSupplier();
        $args = ['type' => 'po', 'year' => (string) self::YEAR];

        [$req, $res] = $this->req('PUT', 'po', $supplierId, ['row_version' => 0, 'inputs' => [
            'donations' => 40000,
            'tax_paid_advances' => 10000,
            'manual_increase_items' => [['text' => 'Syntetická pokuta', 'amount' => 5000]],
        ]]);
        self::assertSame(200, $this->action->putInputs($req, $res, $args)->getStatusCode());

        $report = $this->reports->data($supplierId, self::YEAR, 'po');
        $xml = $this->returns->buildXml($supplierId, self::YEAR, 'po')['xml'];
        $parsed = (new DppoEpoXmlParser())->parse($xml);

        // Každý řádek II. oddílu v XML je v sestavě se stejnou hodnotou a obráceně.
        $reportLines = [];
        foreach ($report['lines']['rows'] as $row) {
            $reportLines[(int) $row['code']] = (float) $row['value'];
        }
        foreach ($parsed['lines'] as $line => $value) {
            self::assertArrayHasKey($line, $reportLines, "Řádek {$line} z XML chybí v sestavě.");
            self::assertSame((float) $value, $reportLines[$line], "Řádek {$line}: sestava se rozchází s XML.");
        }
        foreach ($reportLines as $line => $value) {
            if (isset($parsed['lines'][$line])) {
                continue;
            }
            // Řádky mimo LINE_ATTR čte parser zvlášť: ř. 280 jako sazbu, ř. 220 a 330 do `extra`.
            if ($line === 280) {
                self::assertSame((float) $parsed['rate_pct'], $value, 'Sazba daně (ř. 280) se rozchází s XML.');
                continue;
            }
            if ($line === 220 || $line === 330) {
                $attr = $line === 220 ? 'kc_ii_220' : 'kc_ii320_330';
                self::assertSame((float) $parsed['extra'][$attr]['value'], $value, "Řádek {$line} se rozchází s XML.");
                continue;
            }
            self::assertSame(0.0, $value, "Řádek {$line} není v XML, v sestavě smí být jen jako klíčová nula.");
        }

        // VH 650 000 + ř.40 (513 + ruční 5 000) = 705 000; dary 40 000 → 665 000; daň 21 %.
        self::assertSame(705000.0, $reportLines[200]);
        self::assertSame(665000.0, $reportLines[270]);
        self::assertSame(139650.0, $reportLines[340]);
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $vetaD = $dom->getElementsByTagName('VetaD')->item(0);
        self::assertInstanceOf(\DOMElement::class, $vetaD);
        self::assertSame('due', $report['summary']['result']['tone']);
        self::assertSame(-(float) $vetaD->getAttribute('kc_v_4'), $report['summary']['result']['value']);
        self::assertSame(129650.0, $report['summary']['result']['value']);
        self::assertSame(hash('sha256', $xml), $report['header']['xml_sha256']);
        self::assertSame('12345678', $report['header']['ic']);

        // Příloha účetní závěrky: řádky i hodnoty přesně jako věty UA/UD/UB v XML.
        $expected = [];
        foreach (['VetaUA' => 'assets', 'VetaUD' => 'liabilities', 'VetaUB' => 'income_statement'] as $tag => $key) {
            foreach ($dom->getElementsByTagName($tag) as $el) {
                /** @var \DOMElement $el */
                $values = $tag === 'VetaUA'
                    ? [(int) $el->getAttribute('kc_brutto'), (int) $el->getAttribute('kc_korekce'), (int) $el->getAttribute('kc_netto'), (int) $el->getAttribute('kc_netto_min')]
                    : [(int) $el->getAttribute('kc_sled'), (int) $el->getAttribute('kc_min')];
                $expected[$key][(int) $el->getAttribute('c_radku')] = $values;
            }
        }
        $actual = [];
        foreach ($report['statements'] as $statement) {
            foreach ($statement['rows'] as $row) {
                $actual[$statement['key']][$row['number']] = $row['values'];
            }
        }
        foreach ($expected as $key => $rows) {
            ksort($rows);
            ksort($actual[$key]);
            self::assertSame($rows, $actual[$key], "Příloha {$key} se rozchází s XML.");
        }
        self::assertSame(array_keys($expected), array_column($report['statements'], 'key'), 'Sestava nese přesně ty výkazy, které nese XML.');
        if ($expected === []) {
            // Přílohu nešlo sestavit (důvod je v upozorněních XML); sestava to musí říct, ne mlčet.
            self::assertStringContainsString('neobsahuje přílohu účetní závěrky', implode(' ', $report['notes']));
        }

        // HTML sestavy nese tytéž částky, zřetelnou výhradu a ruční vstup.
        $html = $this->renderer->html($report);
        self::assertStringContainsString("139\u{00A0}650", $html);
        self::assertStringContainsString("129\u{00A0}650", $html);
        self::assertStringContainsString('není podáním pro finanční úřad', $html);
        self::assertStringContainsString('Syntetická pokuta', $html);

        // PDF přes akci: vznikne, a nic se nearchivuje.
        [$req, $res] = $this->req('GET', 'po', $supplierId);
        $response = $this->action->pdf($req, $res, $args);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('dppdp9-2047-sestava.pdf', $response->getHeaderLine('Content-Disposition'));
        $response->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $response->getBody());
        $archived = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM tax_submissions WHERE supplier_id = {$supplierId}")->fetchColumn();
        self::assertSame(0, $archived, 'PDF sestava není podání, nesmí se archivovat.');
    }

    /**
     * Každé c_radku, které builder XML může do přílohy napsat (číselník
     * {@see DppoXmlBuilder::appendixRowNumbers()} + dopočtený ř. 24 pasiv), dostane
     * v sestavě označení a popisek řádku výkazu, pod kterým ho builder píše.
     */
    public function testEveryAppendixRowHasItsOfficialCodeAndLabel(): void
    {
        $repo = Bootstrap::buildApp()->getContainer()->get(StatementDefinitionRepository::class);
        $labels = [];
        foreach (['balance_sheet', 'income_statement'] as $statementType) {
            $version = $repo->findVersion($statementType, self::YEAR . '-12-31');
            self::assertNotNull($version, "Chybí verze výkazu {$statementType}.");
            foreach ($repo->rows((int) $version['id']) as $row) {
                $labels[$statementType][(string) $row['row_code']] = [
                    'label' => (string) $row['label'], 'level' => (int) $row['level'], 'row_type' => (string) $row['row_type'],
                ];
            }
        }

        $unmarked = ['AKTIVA', 'PASIVA', 'PVH', 'FVH', 'VHPZ', 'VHPO', 'VH', 'OBRAT'];
        $displayCode = static function (string $rowCode) use ($unmarked): string {
            if (in_array($rowCode, $unmarked, true)) {
                return '';
            }
            if (str_starts_with($rowCode, 'P.')) {
                return substr($rowCode, 2);
            }
            return $rowCode === 'I.n' ? 'I.' : $rowCode;
        };

        $sections = [
            'assets' => ['VetaUA', 'balance_sheet'],
            'liabilities' => ['VetaUD', 'balance_sheet'],
            'income_statement' => ['VetaUB', 'income_statement'],
        ];
        $expected = [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost><DPPDP9 verzePis="09.01">'
            . '<VetaD dokument="DP9" dapdpp_forma="B" zdobd_od="01.01.2047" zdobd_do="31.12.2047" uv_rozsah_rozv="P"/>'
            . '<VetaP zkrobchjm="Syntetická s.r.o."/><VetaO kc_ii10_10="0"/>';
        foreach (DppoXmlBuilder::appendixRowNumbers() as $section => $rows) {
            [$tag, $set] = $sections[$section];
            foreach ($rows as $rowCode => $numbers) {
                self::assertArrayHasKey((string) $rowCode, $labels[$set], "Řádek {$rowCode} přílohy chybí ve statement_rows.");
                foreach ($numbers as $cRadku) {
                    self::assertArrayNotHasKey($cRadku, $expected[$section] ?? [], "c_radku {$cRadku} ({$section}) patří dvěma řádkům výkazu.");
                    $expected[$section][$cRadku] = [$displayCode((string) $rowCode), $labels[$set][(string) $rowCode]['label']];
                    $xml .= $tag === 'VetaUA'
                        ? "<VetaUA c_radku=\"{$cRadku}\" kc_brutto=\"1\" kc_korekce=\"0\" kc_netto=\"1\" kc_netto_min=\"0\"/>"
                        : "<{$tag} c_radku=\"{$cRadku}\" kc_sled=\"1\" kc_min=\"0\"/>";
                }
            }
        }
        // Ř. 24 pasiv (B.+C. Cizí zdroje) builder dopočítává, ve výkazu řádek nemá.
        $expected['liabilities'][24] = ['B.+C.', 'Cizí zdroje'];
        $xml .= '<VetaUD c_radku="24" kc_sled="1" kc_min="0"/></DPPDP9></Pisemnost>';

        $report = (new \MyInvoice\Service\Tax\Return\TaxReturnReportBuilder())->build([
            'type' => 'po', 'year' => self::YEAR, 'form_code' => 'dppdp9', 'xml' => $xml, 'status' => 'draft',
            'computation' => ['result' => []], 'tax_losses' => [], 'inputs' => [], 'supplier' => [],
        ], $labels);

        $actual = [];
        foreach ($report['statements'] as $statement) {
            foreach ($statement['rows'] as $row) {
                self::assertDoesNotMatchRegularExpression('/^Řádek \d+$/u', $row['label']);
                $actual[$statement['key']][$row['number']] = [$row['code'], $row['label']];
            }
        }
        foreach ($expected as $section => $rows) {
            ksort($rows);
            self::assertSame($rows, $actual[$section] ?? [], "Příloha {$section}: označení nebo popisek nesedí na číselník.");
        }

        // Kotvy podle tiskopisu MF (tabulky 23810/24810/25810).
        self::assertSame(['I.', 'Tržby z prodeje výrobků a služeb'], $actual['income_statement'][1]);
        self::assertSame(['I.', 'Úpravy hodnot a rezervy ve finanční oblasti'], $actual['income_statement'][42]);
        self::assertSame(['J.', 'Nákladové úroky a podobné náklady'], $actual['income_statement'][43]);
        self::assertSame('C.IV.', $actual['assets'][71][0]);
        self::assertStringStartsWith('Peněžní prostředky', $actual['assets'][71][1]);
        self::assertSame('A.V.', $actual['liabilities'][22][0]);
        self::assertStringStartsWith('Výsledek hospodaření běžného účetního období', $actual['liabilities'][22][1]);
    }

    public function testFoReportMatchesWorkingXmlAndFinalSnapshot(): void
    {
        $supplierId = $this->createFoSupplier();
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];

        [$req, $res] = $this->req('PUT', 'fo', $supplierId, ['row_version' => 0, 'inputs' => [
            's6_employment' => ['income' => 600000, 'withholding' => 0],
            'tax_paid_advances' => 20000,
        ]]);
        self::assertSame(200, $this->action->putInputs($req, $res, $args)->getStatusCode());

        $draft = $this->reports->data($supplierId, self::YEAR, 'fo');
        $this->assertFoReportMatchesXml($draft, $this->returns->previewXml($supplierId, self::YEAR, 'fo')['xml']);
        self::assertSame('rozpracované', $draft['header']['status_label']);
        // 600 000 × 15 % = 90 000 − sleva 30 840 = 59 160 − zálohy 20 000.
        self::assertSame(39160.0, $draft['summary']['result']['value']);
        self::assertSame('due', $draft['summary']['result']['tone']);
        self::assertContains('Příloha č. 1: příjmy ze samostatné činnosti', array_column($draft['tables'], 'title'));
        self::assertSame('87654321', $draft['header']['ic'], 'U FO se tiskne IČO, nikdy rodné číslo z věty P.');

        [$req, $res] = $this->req('POST', 'fo', $supplierId, ['row_version' => 1]);
        self::assertSame(200, $this->action->finalize($req, $res, $args)->getStatusCode());

        $final = $this->reports->data($supplierId, self::YEAR, 'fo');
        $finalXml = $this->returns->buildXml($supplierId, self::YEAR, 'fo')['xml'];
        $this->assertFoReportMatchesXml($final, $finalXml);
        self::assertSame(hash('sha256', $finalXml), $final['header']['xml_sha256']);
        self::assertSame('uzamčené (finální)', $final['header']['status_label']);

        [$req, $res] = $this->req('GET', 'fo', $supplierId);
        $response = $this->action->pdf($req, $res, $args);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $response->getBody());
    }

    public function testPdfRequiresReportsExportPermission(): void
    {
        $supplierId = $this->createPoSupplier();
        [$req, $res] = $this->req('GET', 'po', $supplierId, [], 'client');
        $response = $this->action->pdf($req, $res, ['type' => 'po', 'year' => (string) self::YEAR]);
        self::assertSame(403, $response->getStatusCode());
        $response->getBody()->rewind();
        self::assertStringNotContainsString('%PDF', (string) $response->getBody());
    }

    public function testOtherSupplierScopeGetsNothingFromThisReturn(): void
    {
        $poSupplier = $this->createPoSupplier();
        $foSupplier = $this->createFoSupplier();

        // Scope na cizí firmu (jiného typu) s PO routou: žádné PDF, žádná data PO firmy.
        [$req, $res] = $this->req('GET', 'po', $foSupplier);
        $response = $this->action->pdf($req, $res, ['type' => 'po', 'year' => (string) self::YEAR]);
        self::assertSame(422, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('%PDF', $body);
        self::assertStringNotContainsString('Syntetická sestava s.r.o.', $body);

        // A sestava FO firmy nenese nic z PO firmy.
        $report = $this->reports->data($foSupplier, self::YEAR, 'fo');
        $html = $this->renderer->html($report);
        self::assertStringNotContainsString('Syntetická sestava s.r.o.', $html);
        self::assertStringNotContainsString('12345678', $html);
        self::assertGreaterThan(0, $poSupplier);
    }

    /** @param array<string,mixed> $report */
    private function assertFoReportMatchesXml(array $report, string $xml): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $attrs = [];
        foreach (['VetaD', 'VetaO', 'VetaS'] as $tag) {
            $el = $dom->getElementsByTagName($tag)->item(0);
            self::assertInstanceOf(\DOMElement::class, $el);
            foreach ($el->attributes as $attr) {
                $attrs[$attr->nodeName] = (string) $attr->nodeValue;
            }
        }
        $map = ['42' => 'kc_zakldan23', '45' => 'kc_zakldan', '56' => 'kc_zdzaokr', '57' => 'da_dan16', '74' => 'da_slevy35c', '85' => 'kc_zalpred', '91' => 'kc_zbyvpred', '31' => 'kc_prij6'];
        $lines = [];
        foreach ($report['lines']['rows'] as $row) {
            $lines[(string) $row['code']] = (float) $row['value'];
        }
        foreach ($map as $code => $attr) {
            self::assertArrayHasKey($code, $lines, "Řádek {$code} chybí v sestavě.");
            self::assertSame((float) ($attrs[$attr] ?? 0), $lines[$code], "Řádek {$code} ({$attr}) se rozchází s XML.");
        }
        self::assertSame(abs((float) $attrs['kc_zbyvpred']), $report['summary']['result']['value']);
        self::assertSame(hash('sha256', $xml), $report['header']['xml_sha256']);
    }

    private function createPoSupplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, ic, dic, financial_office_code, cz_nace_code, opr_jmeno, opr_prijmeni, opr_postaveni)
             VALUES (?, "Zkušební 123/4", "Vzorov", "10000", ?, "pdf-po@example.com", ?, ?,
                     "po", "12345678", "CZ12345678", "451", "62020", "Jan", "Novák", "jednatel")'
        )->execute(['Syntetická sestava s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($supplierId);
        $this->periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $periodId = (int) $this->periods->findByYear($supplierId, self::YEAR)['id'];
        $accounts = [];
        foreach ($pdo->query("SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = {$supplierId}")->fetchAll(\PDO::FETCH_KEY_PAIR) as $code => $id) {
            $accounts[(string) $code] = (int) $id;
        }
        $post = function (string $date, array $lines) use ($pdo, $supplierId, $periodId, $accounts): void {
            $pdo->prepare('INSERT INTO journal_entries (supplier_id, period_id, entry_date, posted_at, source_type) VALUES (?, ?, ?, NOW(), ?)')
                ->execute([$supplierId, $periodId, $date, 'manual']);
            $entryId = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($lines as [$code, $side, $amount]) {
                $ins->execute([$entryId, $supplierId, $accounts[$code], $side, $amount]);
            }
        };
        // Výnos 1 000 000 (602), náklad 300 000 (518), reprezentace 50 000 (513, nedaňové).
        $post(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
        $post(self::YEAR . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
        $post(self::YEAR . '-05-20', [['513', 'debit', 50000], ['321', 'credit', 50000]]);
        return $supplierId;
    }

    private function createFoSupplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, accounting_mode, ic, dic, financial_office_code, cz_nace_code)
             VALUES (?, "Krátká 12/3", "Praha", "11000", ?, "pdf-fo@example.com", ?, ?,
                     "fo", "tax_evidence", "87654321", "CZ7801011234", "451", "62020")'
        )->execute(['Jan Syntetický', $this->czId, $this->currencyId, $this->vatRateId]);
        $supplierId = (int) $pdo->lastInsertId();

        $snapshot = json_encode(['year' => self::YEAR, 'journal' => ['totals' => [], 'rows' => []]], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            "INSERT INTO tax_evidence_closings
                (supplier_id, year, status, checklist, source_snapshot, source_hash, row_version, finalized_at, finalized_by)
             VALUES (?, ?, 'final', '{}', ?, ?, 1, NOW(), ?)"
        )->execute([$supplierId, self::YEAR, $snapshot, hash('sha256', $snapshot), $this->userId]);
        return $supplierId;
    }

    /** @return array{0:\Psr\Http\Message\ServerRequestInterface,1:Psr7Response} */
    private function req(string $method, string $type, int $supplierId, array $body = [], string $role = 'accountant'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/tax-return/' . $type . '/' . self::YEAR)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return [$req, new Psr7Response()];
    }
}
