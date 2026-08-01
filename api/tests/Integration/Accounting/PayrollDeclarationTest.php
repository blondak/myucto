<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 38h odst. 4 a § 38k odst. 4 ZDP — měsíční sleva na poplatníka jen s PODEPSANÝM
 * prohlášením.
 *
 * ── Odkud se to vzalo ───────────────────────────────────────────────────────
 * Reálný případ, ověřený proti dokladům účetní za 06/2026 (hromadný příkaz k úhradě
 * a JMHZ): aplikace uplatnila slevu i tam, kde prohlášení podepsané NENÍ. `tax_credit_taxpayer`
 * se četlo, `tax_declaration_signed` se ignorovalo, takže:
 *
 *     aplikace:  záloha na FÚ 0 Kč,   čistá mzda 1 561 Kč, k úhradě 4 460 Kč
 *     doklady:   záloha na FÚ 675 Kč, čistá mzda   886 Kč, k úhradě 5 135 Kč
 *
 * Za nesraženou zálohu ručí PLÁTCE (§ 38s ZDP), takže chyba jde přímo proti němu.
 * Opačným směrem je následek mírný — přeplatek se vrátí v ročním zúčtování — a proto
 * je bezpečný default „prohlášení NENÍ podepsané", ne naopak.
 *
 * Čísla v testu jsou ta z dokladů, ne dopočtená z kódu: kdyby se výpočet rozešel se
 * skutečností, test to musí ohlásit.
 */
#[Group('integration')]
final class PayrollDeclarationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;
    private const MONTH = 6;
    private const GROSS = 4500.0;

    private Connection $db;
    private PayrollPostingService $payroll;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->payroll = $c->get(PayrollPostingService::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
            $seeder        = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('payroll_employees', 'tax_declaration_signed')) {
            $this->markTestSkipped('Migrace 1156 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * REGRESE: bez podepsaného prohlášení se sleva neuplatní ani tehdy, když má
     * zaměstnanec na kartě `tax_credit_taxpayer = 1`. Nárok na slevu a možnost uplatnit
     * ji U TOHOTO PLÁTCE jsou dvě různé věci.
     */
    public function testUnsignedDeclarationWithholdsFullAdvance(): void
    {
        $employeeId = $this->employee(creditClaimed: true, declarationSigned: false);

        $res = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, self::GROSS, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );
        $b = $res['breakdown'];

        self::assertSame(675, (int) $b['advance_tax_withheld'], 'Na FÚ se odvede celá záloha.');
        self::assertSame(886, (int) $b['net'], 'Čistá mzda podle mzdového listu účetní.');
        self::assertSame(5135, (int) $b['remittance_total'], 'Celkem k úhradě podle příkazu účetní.');
    }

    /** S podepsaným prohlášením se sleva uplatní a záloha klesne na nulu. */
    public function testSignedDeclarationAppliesCredit(): void
    {
        $employeeId = $this->employee(creditClaimed: true, declarationSigned: true);

        $res = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, self::GROSS, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );
        $b = $res['breakdown'];

        self::assertSame(0, (int) $b['advance_tax_withheld'], 'Sleva 2 570 > záloha 675 → sráží se nula.');
        self::assertSame(1561, (int) $b['net']);
        self::assertSame(4460, (int) $b['remittance_total']);
    }

    /**
     * Obě podmínky musí platit současně: kdo slevu neuplatňuje, nedostane ji ani
     * s podepsaným prohlášením.
     */
    public function testCreditNotClaimedMeansNoCreditEvenWhenSigned(): void
    {
        $employeeId = $this->employee(creditClaimed: false, declarationSigned: true);

        $res = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, self::GROSS, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );

        self::assertSame(675, (int) $res['breakdown']['advance_tax_withheld']);
    }

    /**
     * Bez zvoleného zaměstnance a bez výslovného příznaku se sleva NEUPLATNÍ. Dřív tu
     * byl opačný default, který právě tímhle způsobem vyrobil chybu v odvodu.
     */
    public function testAdHocPreviewDoesNotAssumeSignedDeclaration(): void
    {
        $preview = $this->payroll->preview(self::YEAR, self::MONTH, self::GROSS, 'employee');

        self::assertFalse($preview['taxpayer_credit']);
        self::assertSame(675, (int) $preview['breakdown']['advance_tax_withheld']);
    }

    /**
     * NÁHLED se zadaným zaměstnancem musí dát TOTÉŽ co zaúčtování. Dřív `preview()`
     * kartu vůbec nečetl — počítal z hodnot v požadavku a `post()` je pak přebil kartou.
     * Rozdíl byl tichý a po zavedení vazby na prohlášení narostl: náhled mohl slevu
     * uplatnit a zaúčtování ne.
     */
    public function testPreviewWithEmployeeMatchesPosting(): void
    {
        $employeeId = $this->employee(creditClaimed: true, declarationSigned: false);

        // Požadavek slevu VÝSLOVNĚ žádá — karta ji ale musí přebít, protože prohlášení
        // podepsané není.
        $preview = $this->payroll->preview(
            self::YEAR, self::MONTH, self::GROSS, 'employee',
            taxpayerCredit: true, childCount: 0, ytdSocialBase: null,
            supplierId: $this->supplierId, employeeId: $employeeId,
        );
        $posted = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, self::GROSS, 'employee',
            ['user_id' => $this->userId], $employeeId, true,
        );

        self::assertFalse($preview['taxpayer_credit'], 'Karta přebije požadavek.');
        self::assertSame(675, (int) $preview['breakdown']['advance_tax_withheld']);
        self::assertSame(
            $posted['breakdown']['advance_tax_withheld'],
            $preview['breakdown']['advance_tax_withheld'],
            'Náhled a zaúčtování se nesmí rozejít.',
        );
        self::assertSame($posted['breakdown']['net'], $preview['breakdown']['net']);
    }

    /**
     * REGRESE: `tax_declaration_signed` musí z repository chodit jako BOOLEAN.
     * TINYINT bez castu leze z PDO jako `1`, přes JSON dorazí jako číslo a checkbox
     * ve frontendu (`v-model` porovnává s `true`) ho vykreslil jako NEzaškrtnutý —
     * uživatel viděl „prohlášení nepodepsané", zatímco server slevu uplatnil a
     * srazil nulu. Cast v `PayrollEmployeeRepository::cast()` se při migraci 1156
     * nedoplnil spolu se sloupcem.
     */
    public function testDeclarationFlagIsExposedAsBoolean(): void
    {
        $c = Bootstrap::buildApp()->getContainer();
        $repo = $c->get(\MyInvoice\Repository\PayrollEmployeeRepository::class);

        $signedId = $this->employee(creditClaimed: true, declarationSigned: true);
        $unsignedId = $this->employee(creditClaimed: true, declarationSigned: false);

        self::assertTrue($repo->find($this->supplierId, $signedId)['tax_declaration_signed']);
        self::assertFalse($repo->find($this->supplierId, $unsignedId)['tax_declaration_signed']);

        foreach ($repo->listForTenant($this->supplierId) as $row) {
            self::assertIsBool($row['tax_declaration_signed'], 'Seznam musí vracet boolean, ne TINYINT.');
            self::assertIsBool($row['tax_credit_taxpayer']);
        }
    }

    /**
     * REGRESE: typ poplatníka se bere z KARTY, ne z požadavku.
     *
     * Slevy a počet dětí kartu respektovaly už dřív, typ poplatníka ne — formulář tak
     * mohl mít „zaměstnanec" (521/331), zatímco karta říká „jednatel-společník"
     * (522/366), a zaúčtovalo se na jiné účty, než co ukazoval náhled. Typ přitom
     * nerozhoduje o ničem jiném NEŽ o kontaci, takže rozpor byl přímo v tom jediném,
     * co ten údaj dělá.
     */
    public function testTaxpayerTypeComesFromEmployeeCardNotFromRequest(): void
    {
        $employeeId = $this->employee(
            creditClaimed: true, declarationSigned: true, taxpayerType: 'managing_partner',
        );

        $preview = $this->payroll->preview(
            self::YEAR, self::MONTH, self::GROSS, 'employee',
            taxpayerCredit: true, childCount: 0, ytdSocialBase: null,
            supplierId: $this->supplierId, employeeId: $employeeId,
        );
        $accounts = array_column($preview['lines'], 'account_code');

        self::assertSame('managing_partner', $preview['taxpayer_type'], 'Karta přebije požadavek.');
        self::assertContains('522', $accounts);
        self::assertContains('366', $accounts);
        self::assertNotContains('521', $accounts);
        self::assertNotContains('331', $accounts);
    }

    /** Zaúčtování musí sáhnout na tytéž účty jako náhled — jinak je náhled k ničemu. */
    public function testPostingUsesTaxpayerTypeFromCard(): void
    {
        $employeeId = $this->employee(
            creditClaimed: true, declarationSigned: true, taxpayerType: 'managing_partner',
        );

        $res = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, self::GROSS, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );

        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?'
        );
        $stmt->execute([$res['journal_entry_id']]);
        $accounts = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('522', $accounts);
        self::assertNotContains('521', $accounts);
    }

    /**
     * REGRESE: nové sloupce z migrace 1175 musí z repository chodit ve správných PHP
     * typech. `tax_declaration_signed` na tohle doplatilo (TINYINT místo bool rozešel
     * UI se serverem), takže `auto_post` musí být bool a `monthly_gross` rozlišitelně
     * `null` — 0 Kč je jiný stav než „pravidelná mzda nesjednaná".
     */
    public function testAutoPostColumnsAreExposedInCorrectPhpTypes(): void
    {
        $repo = Bootstrap::buildApp()->getContainer()
            ->get(\MyInvoice\Repository\PayrollEmployeeRepository::class);

        $withGross = $this->employee(
            creditClaimed: true, declarationSigned: true, monthlyGross: 42_000, autoPost: true,
        );
        $withoutGross = $this->employee(creditClaimed: true, declarationSigned: true);

        $a = $repo->find($this->supplierId, $withGross);
        self::assertSame(42_000, $a['monthly_gross']);
        self::assertTrue($a['auto_post']);

        $b = $repo->find($this->supplierId, $withoutGross);
        self::assertNull($b['monthly_gross'], 'Nevyplněná mzda nesmí spadnout na 0.');
        self::assertFalse($b['auto_post']);

        foreach ($repo->listForTenant($this->supplierId) as $row) {
            self::assertIsBool($row['auto_post'], 'Seznam musí vracet boolean, ne TINYINT.');
        }
    }

    /** Do výběru pro cron patří jen aktivní zaměstnanec s automatem A s částkou. */
    public function testAutoPostCandidatesRequireBothFlagAndAmount(): void
    {
        $repo = Bootstrap::buildApp()->getContainer()
            ->get(\MyInvoice\Repository\PayrollEmployeeRepository::class);

        $ok = $this->employee(creditClaimed: true, declarationSigned: true, monthlyGross: 30_000, autoPost: true);
        $this->employee(creditClaimed: true, declarationSigned: true, monthlyGross: 30_000, autoPost: false);
        $this->employee(creditClaimed: true, declarationSigned: true, monthlyGross: null, autoPost: true);
        $this->employee(creditClaimed: true, declarationSigned: true, monthlyGross: 0, autoPost: true);

        $candidates = $repo->autoPostCandidates($this->supplierId);

        self::assertCount(1, $candidates);
        self::assertSame($ok, (int) $candidates[0]['id']);
    }

    // ── § 6 odst. 4 ZDP: srážková daň z DPP ──────────────────────────────────
    //
    // DPP do limitu u jednoho zaměstnavatele a BEZ podepsaného prohlášení tvoří
    // SAMOSTATNÝ ZÁKLAD DANĚ zdaněný srážkou. WithholdingTaxCalculator to uměl už dřív,
    // ale nikdo ho nevolal — firma s dohodáři musela mzdy počítat mimo systém.

    /**
     * DPP pod limitem bez prohlášení → srážková daň 15 %, ŽÁDNÉ pojistné.
     * Do limitu se z DPP sociální ani zdravotní neodvádí (od 1. 1. 2024).
     */
    public function testDppBelowLimitUsesWithholdingTaxAndNoInsurance(): void
    {
        $employeeId = $this->employee(creditClaimed: true, declarationSigned: false, employmentType: 'dpp');

        $r = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, 8_000.0, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );
        $b = $r['breakdown'];

        self::assertSame(1_200, (int) $b['advance_tax_withheld'], '15 % z 8 000.');
        self::assertSame(6_800, (int) $b['net']);
        self::assertSame(0, (int) $b['employee_deductions'], 'Z DPP do limitu se pojistné neodvádí.');
        self::assertSame(0, (int) $b['employer_total']);
    }

    /**
     * PODEPSANÉ prohlášení srážku VYLUČUJE — příjem jde do zálohové daně i pod limitem,
     * jinak by zaměstnanec přišel o slevy, na které má nárok.
     */
    public function testDppWithSignedDeclarationUsesAdvanceTax(): void
    {
        $employeeId = $this->employee(creditClaimed: true, declarationSigned: true, employmentType: 'dpp');

        $r = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, 8_000.0, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );

        // Sleva 2 570 > záloha 1 200 → sráží se nula. To u srážkové daně nastat nemůže.
        self::assertSame(0, (int) $r['breakdown']['advance_tax_withheld']);
    }

    /**
     * Nad limitem se CELÁ odměna daní běžným režimem, ne jen část nad limitem —
     * proto se nad limitem musí objevit i pojistné.
     *
     * Částka se ODVOZUJE z limitu roku, ne píše natvrdo: limit se od 2025 mění
     * s průměrnou mzdou (§ 6/4 ZDP → § 7a z. 187/2006) a test s pevnými 12 000 Kč
     * přestal platit hned, jak limit vyrostl na 12 000.
     */
    public function testDppOverLimitFallsBackToAdvanceRegime(): void
    {
        $employeeId = $this->employee(creditClaimed: false, declarationSigned: false, employmentType: 'dpp');
        $overLimit = (float) \MyInvoice\Service\Tax\TaxConstants::forYear(self::YEAR)['dpp_withholding_limit'] + 1_000.0;

        $r = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, $overLimit, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );

        self::assertGreaterThan(0, (int) $r['breakdown']['employee_deductions'],
            'Nad limitem se odvádí pojistné jako u běžné mzdy.');
    }

    /** Hlavní pracovní poměr srážkovou daň nepoužívá ani pod limitem. */
    public function testHppIsUnaffectedByWithholding(): void
    {
        $employeeId = $this->employee(creditClaimed: false, declarationSigned: false, employmentType: 'hpp');

        $r = $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, 8_000.0, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );

        self::assertGreaterThan(0, (int) $r['breakdown']['employee_deductions']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function employee(
        bool $creditClaimed,
        bool $declarationSigned,
        string $employmentType = 'hpp',
        string $taxpayerType = 'employee',
        ?int $monthlyGross = null,
        bool $autoPost = false,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 1)'
        )->execute([
            $this->supplierId,
            'Testovací zaměstnanec',
            $taxpayerType,
            $employmentType,
            $declarationSigned ? 1 : 0,
            $creditClaimed ? 1 : 0,
            $monthlyGross,
            $autoPost ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }
    /**
     * Zaúčtování měsíce, který už zaevidovaný JE, částku NAHRADÍ — nepřičte.
     *
     * U dohody o provedení práce na tom záleží věcně: § 6 odst. 4 ZDP testuje ÚHRN
     * odměn od téhož plátce za kalendářní měsíc. Dvě dohody po 8 000 Kč jsou 16 000 Kč
     * a srážková daň se neuplatní, ačkoli ani jedna sama limit nepřekročí. Systém drží
     * jeden mzdový záznam na měsíc, takže úhrn musí zadat uživatel — a musí se o tom
     * dozvědět dřív, než první částku přepíše.
     */
    public function testPostingAnAlreadyRecordedMonthWarnsThatItReplaces(): void
    {
        $employeeId = $this->employee(creditClaimed: false, declarationSigned: false, employmentType: 'dpp');
        $this->payroll->post(
            $this->supplierId, self::YEAR, self::MONTH, 8_000.0, 'employee',
            ['user_id' => $this->userId], $employeeId,
        );

        $same = $this->payroll->preview(
            self::YEAR, self::MONTH, 8_000.0, 'employee', false, 0, null, $this->supplierId, $employeeId,
        );

        self::assertEqualsWithDelta(8_000.0, (float) $same['replaces_gross'], 0.01);
        // Varování o limitu DPP tu je vždy; jde o to, že se NEobjeví upozornění na přepis.
        self::assertStringNotContainsString('NAHRADÍ', implode("
", $same['warnings']),
            'Táž částka není přepis, na který je třeba upozornit.');

        // Druhá dohoda zadaná zvlášť by první NAHRADILA — na to se musí upozornit.
        $second = $this->payroll->preview(
            self::YEAR, self::MONTH, 9_000.0, 'employee', false, 0, null, $this->supplierId, $employeeId,
        );

        self::assertNotSame([], $second['warnings']);
        self::assertStringContainsString('NAHRADÍ', implode("\n", $second['warnings']));
    }
}
