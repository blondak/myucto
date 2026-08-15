<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRulesetRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetAdminService;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetGovernanceException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOverrideHash;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MZ-02-W07 — administrace legislativních rulesetů nad override modelem
 * (default v kódu + DB override, stejně jako roční daňové konstanty).
 *
 * Data jsou GLOBÁLNÍ (národní legislativa), takže testy pracují výhradně nad
 * vlastními `test.mz02w07.*` identitami s účinností mimo rok 2026 a po sobě
 * uklízejí — vestavěnou sadu 2026 nesmí ovlivnit žádný jiný běh.
 */
#[Group('integration')]
final class PayrollRulesetAdminServiceTest extends TestCase
{
    private const CALC_ID = 'test.mz02w07.income-tax.2027';
    private const GAP_ID = 'test.mz02w07.codebooks.2029';
    private const OVERLAP_ID = 'test.mz02w07.income-tax.overlap';

    private const EDITOR = 900001;
    private const APPROVER = 900002;

    private Connection $db;
    private PayrollRulesetAdminService $service;
    private PayrollRulesetRegistry $registry;
    private PayrollRulesetRepository $repository;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        if (!$db->hasTable('payroll_rulesets')) {
            $this->markTestSkipped('Migrace 1306 neproběhla.');
        }
        $this->db = $db;
        $this->repository = new PayrollRulesetRepository($db);
        $this->registry = new PayrollRulesetRegistry($this->repository);
        $this->service = new PayrollRulesetAdminService($this->repository, $this->registry);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testOverrideIsGlobalAndNotTenantScoped(): void
    {
        $columns = $this->db->pdo()
            ->query("SHOW COLUMNS FROM payroll_rulesets LIKE 'supplier_id'")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(
            [],
            $columns,
            'Legislativní rulesety jsou národní — override nesmí být per-supplier.',
        );
    }

    public function testOverrideMergesPerKeyAndResetReturnsToTheCodeDefault(): void
    {
        $rulesetId = 'cz-payroll-2026.income-tax.v1';
        $default = $this->registry->defaultVersion($rulesetId);
        self::assertNotNull($default);
        $defaultCount = count($default->parameters);

        $saved = $this->service->save(
            $rulesetId,
            ['parameters' => ['advance.low_rate' => ['type' => 'decimal_rate', 'value' => '0.16']]],
            'Test merge per klíč.',
            0,
            self::EDITOR,
        );

        self::assertTrue($saved['is_override']);
        self::assertCount(
            $defaultCount,
            $saved['parameters'],
            'Override jednoho klíče nesmí zahodit ostatní parametry defaultu.',
        );
        self::assertSame('0.16', $this->parameter($saved, 'advance.low_rate'));
        self::assertSame(
            $default->parameter('advance.high_rate')->value,
            $this->parameter($saved, 'advance.high_rate'),
            'Neupravený parametr se musí dál brát z ověřeného defaultu.',
        );

        $diff = $this->service->diff($rulesetId, 'default');
        self::assertNotNull($diff);
        self::assertCount(1, $diff['parameters']['changed']);
        self::assertSame('advance.low_rate', $diff['parameters']['changed'][0]['key']);
        self::assertSame('0.15', $diff['parameters']['changed'][0]['before']['value']);
        self::assertSame('0.16', $diff['parameters']['changed'][0]['after']['value']);

        $after = $this->service->reset($rulesetId, 'Reset na default.', self::EDITOR);
        self::assertNotNull($after);
        self::assertFalse($after['is_override']);
        self::assertSame('0.15', $this->parameter($after, 'advance.low_rate'));
    }

    public function testAdminEditAloneUnblocksProductionCalculation(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');

        $this->registry->forget();
        try {
            $this->registry->provider()->forCalculation(PayrollRulesetDomain::IncomeTax, '2027-06-15');
            self::fail('Nová verze nesmí být použitelná dřív, než ji admin aktivuje.');
        } catch (PayrollRulesetException $e) {
            self::assertStringContainsString('not active', $e->getMessage());
        }

        $entry = $this->apply(self::CALC_ID, 'review', 'Technická kontrola.', self::EDITOR);
        $entry = $this->apply(self::CALC_ID, 'approve', 'Odborné schválení.', self::APPROVER, $entry);
        $entry = $this->apply(self::CALC_ID, 'activate', 'Nasazení do ostrého výpočtu.', self::APPROVER, $entry);

        self::assertSame('active', $entry['lifecycle']);
        self::assertTrue($entry['calculation_ready']);

        $this->registry->forget();
        $version = $this->registry->provider()
            ->forCalculation(PayrollRulesetDomain::IncomeTax, '2027-06-15');

        self::assertSame(self::CALC_ID, $version->id);
        self::assertSame('0.14', $version->parameter('advance.low_rate')->value);
    }

    public function testGapInEffectivityBlocksApprovalAndActivation(): void
    {
        // Vestavěná sada končí 2026-12-31, tahle verze začíná až 2029 → díra.
        $this->createCustomRuleset(self::GAP_ID, 'codebooks', '2029-01-01', '2029-12-31');
        $entry = $this->apply(self::GAP_ID, 'review', 'Technická kontrola.', self::EDITOR);

        $blockers = $this->service->blockers(
            $this->registry->entry(self::GAP_ID) ?? [],
            'activate',
        );
        self::assertContains('effective_gap', array_column($blockers, 'code'));

        $this->expectException(PayrollRulesetGovernanceException::class);
        $this->expectExceptionMessage('chybí účinnost');
        $this->service->command(
            self::GAP_ID,
            'approve',
            'Pokus o schválení s dírou v účinnosti.',
            (int) $entry['row_version'],
            self::APPROVER,
        );
    }

    public function testOverlappingEffectivityIsRejectedOnSaveAndBlocksActivation(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');

        try {
            $this->service->save(
                self::OVERLAP_ID,
                [
                    'domain' => 'income_tax',
                    'version' => '2027.9.9',
                    'effective_from' => '2027-06-01',
                    'effective_to' => '2028-05-31',
                    'parameters' => ['advance.low_rate' => ['type' => 'decimal_rate', 'value' => '0.13']],
                    'sources' => [self::source()],
                ],
                'Pokus o překrytou účinnost.',
                0,
                self::EDITOR,
            );
            self::fail('Překryv účinností se nesmí dát uložit.');
        } catch (PayrollRulesetGovernanceException $e) {
            self::assertSame('effective_overlap', $e->reasonCode);
        }

        // Kdyby se překryv do DB dostal mimo aplikaci, musí ho zastavit aktivace.
        $this->insertRaw(self::OVERLAP_ID, 'income_tax', '2027-06-01', '2028-05-31', 'approved');
        $this->registry->forget();

        $blockers = $this->service->blockers(
            $this->registry->entry(self::OVERLAP_ID) ?? [],
            'activate',
        );
        self::assertContains('effective_overlap', array_column($blockers, 'code'));
    }

    public function testTamperedOverrideFailsChecksumAndBlocksActivation(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');
        $entry = $this->apply(self::CALC_ID, 'review', 'Technická kontrola.', self::EDITOR);
        self::assertTrue($entry['checksum_valid']);

        // Zásah do DB mimo aplikaci — hodnota se změní, kontrolní součet ne.
        $this->db->pdo()->prepare(
            'UPDATE payroll_rulesets
                SET data = ?, row_version = row_version + 1
              WHERE ruleset_id = ?',
        )->execute([
            CanonicalJson::encode([
                'parameters' => [
                    'advance.low_rate' => [
                        'capability' => 'supported',
                        'note' => null,
                        'type' => 'decimal_rate',
                        'value' => '0.99',
                    ],
                ],
                'sources' => [self::source()],
            ]),
            self::CALC_ID,
        ]);
        $this->registry->forget();

        $tampered = $this->service->detail(self::CALC_ID);
        self::assertNotNull($tampered);
        self::assertFalse($tampered['checksum_valid']);

        $this->expectException(PayrollRulesetGovernanceException::class);
        $this->expectExceptionMessage('Kontrolní součet');
        $this->service->command(
            self::CALC_ID,
            'approve',
            'Pokus o schválení podvržených dat.',
            (int) $tampered['row_version'],
            self::APPROVER,
        );
    }

    public function testRepeatedCommandIsIdempotentAndWritesNoSecondAuditRow(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');
        $entry = $this->apply(self::CALC_ID, 'review', 'Technická kontrola.', self::EDITOR);

        // Audit je append-only, takže po starších bězích v tabulce záznamy zůstávají —
        // měříme přírůstek, ne absolutní počet.
        $before = count($this->repository->auditTrail(self::CALC_ID, 500));

        $repeated = $this->service->command(
            self::CALC_ID,
            'review',
            'Znovu totéž.',
            (int) $entry['row_version'],
            self::EDITOR,
        );

        self::assertFalse($repeated['changed']);
        self::assertSame('reviewed', $repeated['ruleset']['lifecycle']);
        self::assertSame(
            $before,
            count($this->repository->auditTrail(self::CALC_ID, 500)),
            'Opakovaný příkaz nesmí duplikovat auditní stopu.',
        );
        self::assertSame(
            (int) $entry['row_version'],
            (int) $repeated['ruleset']['row_version'],
            'Opakovaný příkaz nesmí posunout optimistický zámek.',
        );
    }

    public function testFourEyesIsAWarningNotABlocker(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');
        $entry = $this->apply(self::CALC_ID, 'review', 'Technická kontrola.', self::EDITOR);
        // Schvaluje týž člověk, který verzi založil — smí to, ale musí to být vidět.
        $entry = $this->apply(self::CALC_ID, 'approve', 'Schvaluji sám.', self::EDITOR, $entry);

        self::assertSame('approved', $entry['lifecycle']);
        self::assertContains('four_eyes_not_met', array_column($entry['warnings'], 'code'));

        $entry = $this->apply(self::CALC_ID, 'activate', 'Aktivuji sám.', self::EDITOR, $entry);
        self::assertSame('active', $entry['lifecycle']);
    }

    /**
     * Uživatel má v editaci vidět český název parametru, ne jen kanonický klíč —
     * ten zůstává jako identifikátor v auditní stopě a v rulesetu.
     */
    public function testDetailShipsCzechParameterNamesAndDecodedEnumValues(): void
    {
        $detail = $this->service->detail('cz-payroll-2026.health-insurance.v1')
            ?? self::fail('Vestavěný ruleset zdravotního pojištění chybí.');
        /** @var list<array<string, mixed>> $parameters */
        $parameters = $detail['parameters'];
        $byKey = array_column($parameters, null, 'key');

        self::assertSame('Celková sazba pojistného (zaměstnanec i zaměstnavatel)', $byKey['total.rate']['label']);
        self::assertSame('Zaokrouhlení celkového pojistného', $byKey['rounding.total']['label']);
        self::assertSame('zaokrouhlit nahoru na celé koruny', $byKey['rounding.total']['value_label']);
        self::assertSame('ceil-to-1-czk', $byKey['rounding.total']['value'], 'Klíčová hodnota se popiskem nesmí přepsat.');

        foreach ($parameters as $parameter) {
            self::assertIsString($parameter['label'], "Parametr {$parameter['key']} nemá český název.");
        }
    }

    /**
     * „Výpočet blokován" u domény s ručním posouzením není fronta ke schválení.
     * Přehled proto musí nést, kolika parametrů se to týká a proč.
     */
    public function testManualReviewDomainReportsHowManyParametersAndWhy(): void
    {
        $overview = $this->service->overview();
        /** @var list<array<string, mixed>> $domains */
        $domains = $overview['domains'];
        $byDomain = array_column($domains, null, 'domain');

        $deadlines = $byDomain['deadlines'];
        self::assertSame('manual_review', $deadlines['status']);
        self::assertTrue($deadlines['manual_review_by_design']);
        self::assertSame(1, $deadlines['manual_review_parameter_count']);
        self::assertSame(1, $deadlines['parameter_count']);
        self::assertIsString($deadlines['manual_review_explanation']);

        // Sociální pojištění ruční posouzení MÁ, ale jen u části parametrů —
        // doména jako celek zůstává použitelná.
        $social = $byDomain['social_insurance'];
        self::assertFalse($social['manual_review_by_design']);
        self::assertSame(3, $social['manual_review_parameter_count']);
        self::assertSame(10, $social['parameter_count']);
        self::assertNotSame('manual_review', $social['status']);
    }

    public function testManualReviewParameterExplainsWhatTheUserShouldDo(): void
    {
        $detail = $this->service->detail('cz-payroll-2026.deadlines.v1')
            ?? self::fail('Vestavěný ruleset lhůt chybí.');
        /** @var list<array<string, mixed>> $parameters */
        $parameters = $detail['parameters'];
        $calendar = array_column($parameters, null, 'key')['submission_calendar'];

        self::assertSame('manual_review', $calendar['capability']);
        self::assertIsString($calendar['manual_review_why']);
        self::assertIsString($calendar['manual_review_action']);
        self::assertStringContainsString('neschvalujete', $calendar['manual_review_action']);

        /** @var list<array<string, mixed>> $warnings */
        $warnings = $detail['warnings'];
        $codes = array_column($warnings, 'code');
        self::assertContains('manual_review_capability', $codes);
        self::assertContains('manual_review_parameters', $codes);
        foreach ($warnings as $warning) {
            if ($warning['code'] === 'manual_review_parameters') {
                self::assertSame(1, $warning['context']['manual_review_count']);
                self::assertSame(1, $warning['context']['parameter_count']);
            }
        }
    }

    public function testAuditTrailRecordsWhoChangedWhatAndWhy(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');
        $this->apply(self::CALC_ID, 'review', 'Technická kontrola sazeb.', self::EDITOR);

        $trail = $this->repository->auditTrail(self::CALC_ID);
        $actions = array_column($trail, 'action');

        self::assertContains('created', $actions);
        self::assertContains('review', $actions);
        foreach ($trail as $row) {
            self::assertNotSame('', $row['reason']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['snapshot_hash']);
        }
    }

    public function testAuditTrailIsAppendOnly(): void
    {
        $this->createCustomRuleset(self::CALC_ID, 'income_tax', '2027-01-01', '2027-12-31');
        $trail = $this->repository->auditTrail(self::CALC_ID);
        self::assertNotSame([], $trail);

        $this->expectException(\PDOException::class);
        $this->db->pdo()
            ->prepare('DELETE FROM payroll_ruleset_audit WHERE id = ?')
            ->execute([$trail[0]['id']]);
    }

    /**
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>
     */
    private function apply(
        string $rulesetId,
        string $command,
        string $reason,
        int $actor,
        ?array $entry = null,
    ): array {
        $rowVersion = $entry === null
            ? (int) (($this->service->detail($rulesetId) ?? [])['row_version'] ?? 0)
            : (int) $entry['row_version'];
        $result = $this->service->command($rulesetId, $command, $reason, $rowVersion, $actor);

        /** @var array<string, mixed> $ruleset */
        $ruleset = $result['ruleset'];

        return $ruleset;
    }

    private function createCustomRuleset(
        string $rulesetId,
        string $domain,
        string $from,
        string $to,
    ): void {
        $this->service->save(
            $rulesetId,
            [
                'domain' => $domain,
                'version' => '2027.1.0',
                'effective_from' => $from,
                'effective_to' => $to,
                'capability' => 'supported',
                'parameters' => [
                    'advance.low_rate' => ['type' => 'decimal_rate', 'value' => '0.14'],
                    'credit.taxpayer.monthly' => ['type' => 'money_minor', 'value' => 257000],
                ],
                'sources' => [self::source()],
            ],
            'Nová legislativní sada zadaná v administraci.',
            0,
            self::EDITOR,
        );
        $this->registry->forget();
    }

    private function insertRaw(
        string $rulesetId,
        string $domain,
        string $from,
        string $to,
        string $lifecycle,
    ): void {
        $row = [
            'ruleset_id' => $rulesetId,
            'version' => '2027.9.9',
            'effective_from' => $from,
            'effective_to' => $to,
            'lifecycle' => $lifecycle,
            'capability' => 'supported',
            'data' => CanonicalJson::encode([
                'parameters' => [
                    'advance.low_rate' => [
                        'capability' => 'supported',
                        'note' => null,
                        'type' => 'decimal_rate',
                        'value' => '0.13',
                    ],
                ],
                'sources' => [self::source()],
            ]),
        ];

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_rulesets
                (ruleset_id, domain, version, effective_from, effective_to,
                 lifecycle, capability, data, content_hash, reason, approved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $rulesetId,
            $domain,
            $row['version'],
            $from,
            $to,
            $lifecycle,
            'supported',
            $row['data'],
            PayrollRulesetOverrideHash::hash($row),
            'Řádek vložený mimo aplikaci.',
            self::APPROVER,
        ]);
    }

    /** @return array<string, string> */
    private static function source(): array
    {
        return [
            'id' => 'test-mz02w07-source',
            'title' => 'Syntetický právní zdroj testu',
            'url' => 'https://example.invalid/mz02w07',
            'retrieved_on' => '2026-08-01',
        ];
    }

    /** @param array<string, mixed> $detail */
    private function parameter(array $detail, string $key): mixed
    {
        /** @var list<array<string, mixed>> $parameters */
        $parameters = $detail['parameters'] ?? [];
        foreach ($parameters as $parameter) {
            if (($parameter['key'] ?? null) === $key) {
                return $parameter['value'] ?? null;
            }
        }

        throw new \RuntimeException("Parametr {$key} nebyl v detailu nalezen.");
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("DELETE FROM payroll_rulesets WHERE ruleset_id LIKE 'test.mz02w07.%'");
        $pdo->exec("DELETE FROM payroll_rulesets WHERE ruleset_id LIKE 'cz-payroll-2026.%'");
        $this->registry->forget();
    }
}
