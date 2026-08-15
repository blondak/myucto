<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Roční zúčtování nad skutečnou databází.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se tu nevolá `settle()`
 * ─────────────────────────────────────────────────────────────────────────────
 * `settle()` si transakci OTEVÍRÁ SÁM (a musí — zápis dokladu, rejstříku
 * i souboru je jeden celek). Test by proto nemohl běžet ve vlastní transakci
 * a musel by po sobě uklízet; jenže roční revize i mzdové kumulace mají
 * `BEFORE DELETE` triggery, které mazání zakazují, takže by po každém běhu
 * zůstávaly v testovací DB neodstranitelné řádky.
 *
 * Testují se proto ty tři části, na kterých `settle()` stojí, a každá právě
 * tam, kde se může pokazit:
 *   - `preview()` — posouzení podmínek a výpočet nad reálnou evidencí,
 *   - `AnnualSettlementSnapshotBuilder` — zmrazení do neměnné roční revize,
 *   - `insertOutcome()` — jediný výsledek na rok, i při druhém spuštění.
 */
#[Group('integration')]
final class AnnualSettlementIntegrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;

    /** Hrubá mzda 42 137,00 Kč měsíčně — záměrně nekulatá. */
    private const MONTHLY_GROSS_MINOR = 4_213_700;

    /**
     * Zaměstnanec s jedním zaměstnavatelem a podepsaným prohlášením: přeplatek
     * vyjde, doklad vznikne, druhé spuštění nevytvoří druhý výsledek, a měsíční
     * data zůstanou nedotčená.
     */
    public function testCompleteYearProducesOneSettlementAndLeavesMonthsUntouched(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $snapshots = $container->get(AnnualSettlementSnapshotBuilder::class);
        $settlements = $container->get(PayrollAnnualSettlementRepository::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(AnnualSettlementSnapshotBuilder::class, $snapshots);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        self::assertInstanceOf(
            PayrollStatutoryAccumulatorRepository::class,
            $accumulators,
        );
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId, $advanceTaxTotal] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
            );
            $monthsFingerprint = $this->monthsFingerprint($pdo, $supplierId, $employeeId);

            // Roční kumulace vidí VŠECH dvanáct měsíců včetně prosince —
            // běhové čtení jich smí mít nejvýš jedenáct.
            $state = $accumulators->stateForYear(
                $supplierId,
                $employeeId,
                self::YEAR,
                'income_tax',
            );
            self::assertSame('whole_year', $state['scope']);
            self::assertSame(12, $state['totals']['completed_months']);
            self::assertSame(
                self::MONTHLY_GROSS_MINOR * 12,
                $state['totals']['advance_base_minor_units'],
            );

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1)),
            );
            $result = $preview['result'];

            self::assertSame([], $result->blockerCodes());
            self::assertTrue($result->performed);
            self::assertSame(AnnualSettlementOutcome::Overpayment, $result->outcome);

            // Přeplatek na haléř: úhrn sražených záloh minus roční daň po slevách.
            self::assertSame(
                $advanceTaxTotal - $result->taxAfterAllCreditsMinorUnits,
                $result->taxDifferenceMinorUnits,
            );
            self::assertSame(
                $result->taxDifferenceMinorUnits,
                $result->settlementDifferenceMinorUnits,
            );
            self::assertSame(
                $result->settlementDifferenceMinorUnits,
                $result->payableMinorUnits,
            );
            self::assertCount(1, $preview['credit_rows']);

            // Zmrazení do neměnné roční revize.
            $prepared = $snapshots->build(
                $supplierId,
                $employeeId,
                self::YEAR,
                $result,
                sprintf('%04d-03-10', self::YEAR + 1),
                $preview['credit_rows'],
                $preview['child_rows'],
                null,
            );
            self::assertTrue($prepared['created']);
            self::assertSame(
                AnnualSettlementSnapshotBuilder::PURPOSE,
                $prepared['revision']['purpose'],
            );
            $revisionId = (int) $prepared['revision']['id'];

            // Druhé sestavení nad týmiž měsíci a týmž výsledkem vrátí tutéž
            // revizi — manifest zdrojů je shodný, takže není co zakládat.
            $again = $snapshots->build(
                $supplierId,
                $employeeId,
                self::YEAR,
                $result,
                sprintf('%04d-03-10', self::YEAR + 1),
                $preview['credit_rows'],
                $preview['child_rows'],
                null,
            );
            self::assertFalse($again['created']);
            self::assertSame($revisionId, (int) $again['revision']['id']);

            $stored = $settlements->insertOutcome(
                $supplierId,
                $employeeId,
                self::YEAR,
                [
                    'annual_revision_id' => $revisionId,
                    'outcome' => $result->outcome?->value,
                    'tax_difference_minor' => $result->taxDifferenceMinorUnits,
                    'bonus_difference_minor' => $result->bonusDifferenceMinorUnits,
                    'settlement_difference_minor' =>
                        $result->settlementDifferenceMinorUnits,
                    'payable_minor' => $result->payableMinorUnits,
                    'payout_threshold_minor' =>
                        AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                    'settled_on' => sprintf('%04d-03-10', self::YEAR + 1),
                ],
                null,
            );
            self::assertTrue($stored['created']);

            // „Opakované spuštění nevytvoří druhý výsledek" — druhý zápis narazí
            // na unikátní klíč a vrátí ten původní řádek.
            $repeat = $settlements->insertOutcome(
                $supplierId,
                $employeeId,
                self::YEAR,
                [
                    'annual_revision_id' => $revisionId,
                    'outcome' => $result->outcome?->value,
                    'tax_difference_minor' => $result->taxDifferenceMinorUnits,
                    'bonus_difference_minor' => $result->bonusDifferenceMinorUnits,
                    'settlement_difference_minor' =>
                        $result->settlementDifferenceMinorUnits,
                    'payable_minor' => $result->payableMinorUnits,
                    'payout_threshold_minor' =>
                        AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                    'settled_on' => sprintf('%04d-03-11', self::YEAR + 1),
                ],
                null,
            );
            self::assertFalse($repeat['created']);
            self::assertSame(
                $stored['outcome']['id'],
                $repeat['outcome']['id'],
            );
            self::assertSame(
                sprintf('%04d-03-10', self::YEAR + 1),
                $repeat['outcome']['settled_on'],
            );
            self::assertSame(
                1,
                (int) $pdo->query(sprintf(
                    'SELECT COUNT(*) FROM payroll_annual_settlement_outcomes
                      WHERE supplier_id = %d AND employee_id = %d',
                    $supplierId,
                    $employeeId,
                ))->fetchColumn(),
            );

            // Roční zúčtování je JEN součet — historické měsíce se nesmí hnout.
            self::assertSame(
                $monthsFingerprint,
                $this->monthsFingerprint($pdo, $supplierId, $employeeId),
            );

            // A hotové zúčtování zablokuje další pokus.
            $second = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-11', self::YEAR + 1)),
            );
            self::assertFalse($second['result']->performed);
            self::assertContains(
                AnnualSettlementBlocker::AlreadySettled->value,
                $second['result']->blockerCodes(),
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /** Zaměstnanec bez podepsaného prohlášení — zúčtování se neprovede a řekne proč. */
    public function testUnsignedDeclarationRefusesWithAReason(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                declarationStatus: 'not-signed',
            );

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1)),
            );

            self::assertFalse($preview['result']->performed);
            self::assertSame(
                [AnnualSettlementBlocker::DeclarationNotSigned->value],
                $preview['result']->blockerCodes(),
            );
            // Nedopočítalo se „aspoň částečně".
            self::assertSame(0, $preview['result']->taxBeforeCreditsMinorUnits);
            self::assertSame(0, $preview['result']->payableMinorUnits);
            self::assertNull($preview['result']->outcome);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Zaměstnanec, kterému zúčtování provést nelze (§ 38ch odst. 1 věta druhá):
     * odmítne se s vysvětlením a nic se nespočítá.
     */
    public function testEmployeeWhoMustFileTaxReturnIsRefused(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                filingObligation: 'required',
                filingReason: 'Příjmy podle § 7 nad 20 000 Kč.',
            );

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1)),
            );

            self::assertFalse($preview['result']->performed);
            self::assertSame(
                [AnnualSettlementBlocker::MustFileTaxReturn->value],
                $preview['result']->blockerCodes(),
            );
            self::assertSame(0, $preview['result']->settlementDifferenceMinorUnits);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Prázdný stav není selhání: rok bez jediné žádosti vrátí seznam
     * zaměstnanců s nevyplněnou evidencí, ne chybu.
     */
    public function testYearWithoutRequestsListsEmployeesWithUnknownState(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $settlements = $container->get(PayrollAnnualSettlementRepository::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                withRequest: false,
            );

            $items = $settlements->listForYear($supplierId, self::YEAR);
            self::assertCount(1, $items);
            self::assertSame($employeeId, $items[0]['employee_id']);
            self::assertNull($items[0]['request_status']);
            self::assertNull($items[0]['outcome']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Otisk všech měsíčních dat zaměstnance. Změní-li se cokoli v mzdových
     * revizích, výsledcích osob nebo kumulacích, otisk se rozejde.
     */
    private function monthsFingerprint(PDO $pdo, int $supplierId, int $employeeId): string
    {
        $statement = $pdo->prepare(
            'SELECT entry.period_start, entry.values_json, entry.record_hash,
                    person.result_hash, person.status, revision.status AS revision_status
               FROM payroll_statutory_accumulator_entries entry
               JOIN payroll_run_persons person
                 ON person.supplier_id = entry.supplier_id
                AND person.revision_id = entry.revision_id
                AND person.employee_id = entry.employee_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = entry.supplier_id
                AND revision.id = entry.revision_id
              WHERE entry.supplier_id = ? AND entry.employee_id = ?
              ORDER BY entry.period_start, entry.id'
        );
        $statement->execute([$supplierId, $employeeId]);

        return hash('sha256', json_encode(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Zaměstnanec s dvanácti schválenými měsíci, podepsaným prohlášením
     * a nárokem na základní slevu na poplatníka.
     *
     * @return array{0:int,1:int,2:int} supplier, employee, úhrn sražených záloh
     */
    private function fixture(
        PDO $pdo,
        int $sourceSupplierId,
        PayrollSensitiveData $sensitive,
        string $declarationStatus = 'signed',
        string $filingObligation = 'none',
        ?string $filingReason = null,
        bool $withRequest = true,
    ): array {
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická společnost",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "12345678",
                    street = "Testovací 12",
                    city = "Testov",
                    zip = "10000"
              WHERE id = ?',
        )->execute([$supplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická roční osoba", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Syntetická roční osoba", "Syntetická", "Osoba", ?)',
        )->execute([$supplierId, $employeeId, sprintf('%04d-01-01', self::YEAR)]);

        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext,
                 value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:synthetic", ?, "••••0009")',
        )->execute([$supplierId, $employeeId, random_bytes(32)]);
        $identifierId = (int) $pdo->lastInsertId();
        $sealed = $sensitive->seal(
            '0001010009',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
            $identifierId,
        );
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $supplierId,
            $identifierId,
        ]);

        // Zákonná evidence — prohlášení, rezidentství, nárok na slevu.
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from,
                 evidence_reference)
             VALUES (?, ?, ?, ?, "synthetic-declaration")',
        )->execute([
            $supplierId,
            $employeeId,
            $declarationStatus,
            sprintf('%04d-01-01', self::YEAR),
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", ?, "synthetic-residence")',
        )->execute([$supplierId, $employeeId, sprintf('%04d-01-01', self::YEAR)]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status,
                 effective_from, evidence_reference)
             VALUES (?, ?, "taxpayer", "verified", ?, "synthetic-credit")',
        )->execute([$supplierId, $employeeId, sprintf('%04d-01-01', self::YEAR)]);

        if ($withRequest) {
            $pdo->prepare(
                'INSERT INTO payroll_annual_settlement_requests
                    (supplier_id, employee_id, tax_year, request_status,
                     requested_on, request_evidence_reference, prior_employers,
                     filing_obligation, filing_obligation_reason, annual_claims)
                 VALUES (?, ?, ?, "requested", ?, "synthetic-request", "none",
                         ?, ?, "none")',
            )->execute([
                $supplierId,
                $employeeId,
                self::YEAR,
                sprintf('%04d-02-05', self::YEAR + 1),
                $filingObligation,
                $filingReason,
            ]);
        }

        // Opening balance ročních kumulací — bez něj se kumulace nečte vůbec.
        $openingValues = json_encode([
            'advance_base_minor_units' => 0,
            'advance_tax_minor_units' => 0,
            'applied_child_credit_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 0,
            'completed_months' => 0,
            'tax_bonus_minor_units' => 0,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_statutory_accumulator_openings
                (supplier_id, employee_id, tax_year, calculation_kind,
                 values_json, source_reference, evidence_json,
                 idempotency_key_hash, record_hash)
             VALUES (?, ?, ?, "income_tax", ?, "synthetic-opening", "[]", ?, ?)',
        )->execute([
            $supplierId,
            $employeeId,
            self::YEAR,
            $openingValues,
            random_bytes(32),
            hash('sha256', "synthetic-opening-{$supplierId}-{$employeeId}"),
        ]);

        // Dvanáct schválených měsíců. Měsíční daň se počítá stejným pravidlem
        // jako v produkci (§ 38h odst. 1 až 4), aby roční přeplatek vycházel
        // z reálného, ne vymyšleného úhrnu záloh.
        $taxpayerCreditMonthly = 257_000;
        $advanceTaxTotal = 0;
        for ($month = 1; $month <= 12; $month++) {
            $periodStart = sprintf('%04d-%02d-01', self::YEAR, $month);
            $pdo->prepare(
                'INSERT INTO payroll_runs
                    (supplier_id, period_start, payment_date, status,
                     current_revision_no)
                 VALUES (?, ?, ?, "approved", 1)',
            )->execute([
                $supplierId,
                $periodStart,
                sprintf('%04d-%02d-15', self::YEAR, $month),
            ]);
            $runId = (int) $pdo->lastInsertId();

            $resultSnapshot = json_encode(
                ['schema_version' => 'payroll-run-result.v2', 'people' => []],
                JSON_THROW_ON_ERROR,
            );
            $inputSnapshot = json_encode(
                ['schema_version' => 'payroll-run-input.v2'],
                JSON_THROW_ON_ERROR,
            );
            $pdo->prepare(
                'INSERT INTO payroll_run_revisions
                    (supplier_id, run_id, revision_no, status, schema_version,
                     ruleset_manifest_hash, input_snapshot_json,
                     input_snapshot_hash, result_snapshot_json,
                     result_snapshot_hash, idempotency_key_hash, approved_at)
                 VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                         ?, ?, ?, ?, ?, ?, NOW())',
            )->execute([
                $supplierId,
                $runId,
                str_repeat('b', 64),
                $inputSnapshot,
                hash('sha256', $inputSnapshot),
                $resultSnapshot,
                hash('sha256', $resultSnapshot),
                random_bytes(32),
            ]);
            $revisionId = (int) $pdo->lastInsertId();

            $personResult = json_encode(
                ['employee_id' => $employeeId, 'period_start' => $periodStart],
                JSON_THROW_ON_ERROR,
            );
            $pdo->prepare(
                'INSERT INTO payroll_run_persons
                    (supplier_id, revision_id, employee_id, result_json,
                     result_hash, status)
                 VALUES (?, ?, ?, ?, ?, "calculated")',
            )->execute([
                $supplierId,
                $revisionId,
                $employeeId,
                $personResult,
                hash('sha256', $personResult),
            ]);

            // § 38h odst. 1: základ nad 100 Kč se zaokrouhluje na celé stokoruny
            // nahoru; § 38h odst. 2 a 3: 15 % a zaokrouhlení daně na koruny nahoru.
            $roundedBase = (int) (ceil(self::MONTHLY_GROSS_MINOR / 10_000) * 10_000);
            $taxBeforeCredits = (int) (ceil($roundedBase * 15 / 100 / 100) * 100);
            $advanceTax = max(0, $taxBeforeCredits - $taxpayerCreditMonthly);
            $advanceTaxTotal += $advanceTax;

            $entryValues = json_encode([
                'advance_base_minor_units' => self::MONTHLY_GROSS_MINOR,
                'advance_tax_minor_units' => $advanceTax,
                'applied_child_credit_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' =>
                    min($taxBeforeCredits, $taxpayerCreditMonthly),
                'bonus_qualifying_income_minor_units' => self::MONTHLY_GROSS_MINOR,
                'completed_months' => 1,
                'tax_bonus_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
            ], JSON_THROW_ON_ERROR);
            $pdo->prepare(
                'INSERT INTO payroll_statutory_accumulator_entries
                    (supplier_id, employee_id, tax_year, period_start,
                     revision_id, calculation_kind, values_json,
                     source_result_hash, record_hash)
                 VALUES (?, ?, ?, ?, ?, "income_tax", ?, ?, ?)',
            )->execute([
                $supplierId,
                $employeeId,
                self::YEAR,
                $periodStart,
                $revisionId,
                $entryValues,
                hash('sha256', $personResult),
                hash('sha256', "synthetic-entry-{$revisionId}"),
            ]);
        }

        return [$supplierId, $employeeId, $advanceTaxTotal];
    }
}
