<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

final class PayrollSheetSnapshotBuilder
{
    public const SCHEMA_VERSION = 'payroll-sheet-document.v1';
    public const PURPOSE = 'payroll_sheet';
    public const MAPPING_VERSION = 'payroll-sheet-mapping.v1';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualDocumentRepository $annualRevisions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @return array{revision:array<string,mixed>,document:PayrollSheetDocumentData}
     */
    public function build(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?int $actorUserId,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Roční mzdový snapshot vyžaduje aktivní transakci.');
        }
        if ($supplierId <= 0 || $employeeId <= 0 || $taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Identita ročního mzdového listu není platná.');
        }

        $sources = $this->annualRevisions->lockApprovedYearSources(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        if ($sources === []) {
            throw new \DomainException(
                'Mzdový list nelze vytvořit bez schváleného výsledku zaměstnance v daném roce.',
            );
        }
        $profile = $this->profileSnapshot($supplierId, $employeeId, $taxYear);
        $employer = $this->employerSnapshot($supplierId);
        [$months, $manifestSources] = $this->months($sources, $employeeId);

        $profileHash = $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($profile),
            'annual-payroll-profile-v1',
            $supplierId,
        );
        $employerHash = $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($employer),
            'annual-payroll-employer-v1',
            $supplierId,
        );
        $manifest = [
            'schema_version' => 'payroll-annual-source-manifest.v1',
            'document_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => self::PURPOSE,
            'tax_year' => $taxYear,
            'employee_id' => $employeeId,
            'profile_snapshot_hash' => $profileHash,
            'employer_snapshot_hash' => $employerHash,
            'sources' => $manifestSources,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'tax_year' => $taxYear,
            'employer' => $employer,
            'employee' => $profile,
            'months' => array_map(
                static fn (PayrollSheetMonth $month): array => $month->toTemplateData(),
                $months,
            ),
            'annual_settlement_status' => 'not_performed',
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->snapshotFingerprint($snapshotJson, $supplierId);

        $existing = $this->annualRevisions->findBySourceManifest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
            $manifestHash,
        );
        if ($existing !== null) {
            if (!hash_equals((string) $existing['snapshot_hash'], $snapshotHash)) {
                throw new \DomainException(
                    'Stejný roční manifest odkazuje na jiný kanonický mzdový snapshot.',
                );
            }
            return [
                'revision' => $existing,
                'document' => $this->hydrate(
                    $this->decryptSnapshot($existing),
                    (string) $existing['snapshot_hash'],
                ),
            ];
        }
        $previous = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
        );
        $ciphertext = $this->encryption->encryptFor(
            $snapshotJson,
            $this->encryptionContext(
                $supplierId,
                $employeeId,
                $taxYear,
                $manifestHash,
            ),
        );
        $revision = $this->annualRevisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'tax_year' => $taxYear,
            'purpose' => self::PURPOSE,
            'revision_no' => $previous === null ? 1 : (int) $previous['revision_no'] + 1,
            'previous_revision_id' => $previous['id'] ?? null,
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ], $sources);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash),
        ];
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return array{0:list<PayrollSheetMonth>,1:list<array<string,mixed>>}
     */
    private function months(array $sources, int $employeeId): array
    {
        $months = [];
        $manifest = [];
        foreach ($sources as $source) {
            $inputJson = $this->text($source, 'input_snapshot_json');
            $resultJson = $this->text($source, 'result_snapshot_json');
            $personJson = $this->text($source, 'person_result_json');
            $inputHash = $this->hash($source, 'input_snapshot_hash');
            $resultHash = $this->hash($source, 'result_snapshot_hash');
            $personHash = $this->hash($source, 'person_result_hash');
            $this->assertJsonHash($inputJson, $inputHash, 'vstupní revize');
            $this->assertJsonHash($resultJson, $resultHash, 'výsledné revize');
            $this->assertJsonHash($personJson, $personHash, 'výsledku osoby');

            $root = $this->decodeObject($resultJson, 'Výsledek schválené revize');
            $storedPerson = $this->decodeObject($personJson, 'Výsledek zaměstnance');
            $rootPerson = null;
            foreach ($this->list($root['people'] ?? null, 'result.people') as $candidate) {
                $candidate = $this->object($candidate, 'result.people[]');
                if ($this->positiveInt($candidate, 'employee_id') === $employeeId) {
                    if ($rootPerson !== null) {
                        throw new \DomainException('Schválená revize obsahuje zaměstnance vícekrát.');
                    }
                    $rootPerson = $candidate;
                }
            }
            if ($rootPerson === null
                || !hash_equals($personHash, hash('sha256', CanonicalJson::encode($rootPerson)))
                || !hash_equals(
                    hash('sha256', CanonicalJson::encode($storedPerson)),
                    $personHash,
                )
            ) {
                throw new \DomainException('Výsledek zaměstnance nesouhlasí se schválenou revizí.');
            }
            $periodStart = $this->text($source, 'period_start');
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $periodStart) !== 1) {
                throw new \DomainException('Zdroj mzdového listu má neplatné období.');
            }
            $monthNumber = (int) substr($periodStart, 5, 2);
            $amounts = $this->personAmounts($storedPerson);
            if (!isset($months[$monthNumber])) {
                $months[$monthNumber] = [
                    'source_revision_count' => 0,
                    ...array_fill_keys(array_keys($amounts), 0),
                ];
            }
            $months[$monthNumber]['source_revision_count']++;
            foreach ($amounts as $key => $amount) {
                $months[$monthNumber][$key] = $this->add(
                    $months[$monthNumber][$key],
                    $amount,
                );
            }
            $manifest[] = [
                'period_start' => $periodStart,
                'run_id' => $this->positiveInt($source, 'run_id'),
                'revision_id' => $this->positiveInt($source, 'revision_id'),
                'input_snapshot_hash' => $inputHash,
                'result_snapshot_hash' => $resultHash,
                'person_result_hash' => $personHash,
            ];
        }
        ksort($months, SORT_NUMERIC);
        $result = [];
        foreach ($months as $month => $amounts) {
            $result[] = new PayrollSheetMonth(
                month: $month,
                sourceRevisionCount: $amounts['source_revision_count'],
                grossMinorUnits: $amounts['gross_minor_units'],
                cashIncomeMinorUnits: $amounts['cash_income_minor_units'],
                nonCashIncomeMinorUnits: $amounts['non_cash_income_minor_units'],
                socialAssessmentBaseMinorUnits: $amounts['social_assessment_base_minor_units'],
                employeeSocialMinorUnits: $amounts['employee_social_minor_units'],
                employerSocialMinorUnits: $amounts['employer_social_minor_units'],
                healthAssessmentBaseMinorUnits: $amounts['health_assessment_base_minor_units'],
                employeeHealthMinorUnits: $amounts['employee_health_minor_units'],
                employerHealthMinorUnits: $amounts['employer_health_minor_units'],
                healthMinimumTopUpMinorUnits: $amounts['health_minimum_top_up_minor_units'],
                advanceTaxBaseMinorUnits: $amounts['advance_tax_base_minor_units'],
                advanceTaxBeforeCreditsMinorUnits: $amounts['advance_tax_before_credits_minor_units'],
                nonRefundableCreditsMinorUnits: $amounts['non_refundable_credits_minor_units'],
                childCreditMinorUnits: $amounts['child_credit_minor_units'],
                advanceTaxMinorUnits: $amounts['advance_tax_minor_units'],
                taxBonusMinorUnits: $amounts['tax_bonus_minor_units'],
                withholdingTaxMinorUnits: $amounts['withholding_tax_minor_units'],
                otherDeductionsMinorUnits: $amounts['other_deductions_minor_units'],
                netPayableMinorUnits: $amounts['net_payable_minor_units'],
            );
        }
        return [$result, $manifest];
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,int>
     */
    private function personAmounts(array $person): array
    {
        $payslip = $this->object($person['payslip_document'] ?? null, 'payslip_document');
        $statutory = $this->object($person['statutory'] ?? null, 'statutory');
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException('Mzdový list vyžaduje uzavřený zákonný výpočet.');
        }
        $social = $this->object($statutory['social_insurance'] ?? null, 'social_insurance');
        $health = $this->object($statutory['health_insurance'] ?? null, 'health_insurance');
        $tax = $this->object($statutory['income_tax'] ?? null, 'income_tax');
        $net = $this->object($statutory['net_pay'] ?? null, 'net_pay');
        $advance = ($tax['advance_tax'] ?? null) === null
            ? []
            : $this->object($tax['advance_tax'], 'advance_tax');
        $enforcement = $this->object($person['enforcement'] ?? null, 'enforcement');
        $enforcementResult = $this->object($enforcement['result'] ?? null, 'enforcement.result');
        if (($enforcementResult['status'] ?? null) !== 'supported') {
            throw new \DomainException('Mzdový list vyžaduje uzavřený výsledek srážek.');
        }
        $employeeHealthTotal = $this->nonNegativeInt($health, 'employee_contribution_minor_units');
        $healthTopUp = $this->nonNegativeInt($health, 'employee_minimum_top_up_minor_units');

        return [
            'gross_minor_units' => $this->nonNegativeInt($payslip, 'gross_minor_units'),
            'cash_income_minor_units' => $this->nonNegativeInt($net, 'cash_income_minor_units'),
            'non_cash_income_minor_units' => $this->nonNegativeInt($net, 'non_cash_income_minor_units'),
            'social_assessment_base_minor_units' =>
                $this->nonNegativeInt($social, 'capped_assessment_base_minor_units'),
            'employee_social_minor_units' =>
                $this->nonNegativeInt($social, 'employee_contribution_minor_units'),
            'employer_social_minor_units' =>
                $this->nonNegativeInt($payslip, 'employer_social_minor_units'),
            'health_assessment_base_minor_units' =>
                $this->nonNegativeInt($health, 'assessment_base_minor_units'),
            'employee_health_minor_units' => $employeeHealthTotal - $healthTopUp,
            'employer_health_minor_units' =>
                $this->nonNegativeInt($health, 'employer_contribution_minor_units'),
            'health_minimum_top_up_minor_units' => $healthTopUp,
            'advance_tax_base_minor_units' =>
                $advance === [] ? 0 : $this->nonNegativeInt($advance, 'rounded_tax_base_minor_units'),
            'advance_tax_before_credits_minor_units' =>
                $advance === [] ? 0 : $this->nonNegativeInt($advance, 'tax_before_credits_minor_units'),
            'non_refundable_credits_minor_units' =>
                $advance === [] ? 0 : $this->nonNegativeInt($advance, 'non_refundable_credits_minor_units'),
            'child_credit_minor_units' =>
                $advance === [] ? 0 : $this->nonNegativeInt($advance, 'child_credit_minor_units'),
            'advance_tax_minor_units' => $this->nonNegativeInt($net, 'advance_tax_minor_units'),
            'tax_bonus_minor_units' => $this->nonNegativeInt($net, 'tax_bonus_minor_units'),
            'withholding_tax_minor_units' =>
                $this->nonNegativeInt($net, 'withholding_tax_minor_units'),
            'other_deductions_minor_units' => $this->add(
                $this->nonNegativeInt($net, 'deducted_minor_units'),
                $this->nonNegativeInt($enforcementResult, 'total_withheld_minor_units'),
            ),
            'net_payable_minor_units' =>
                $this->nonNegativeInt($person, 'payable_after_enforcement_minor'),
        ];
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(int $supplierId, int $employeeId, int $taxYear): array
    {
        $from = sprintf('%04d-01-01', $taxYear);
        $to = sprintf('%04d-12-31', $taxYear);
        $employee = $this->db->pdo()->prepare(
            'SELECT birth_date FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $employee->execute([$supplierId, $employeeId]);
        $employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employeeRow)) {
            throw new \DomainException('Zaměstnanec mzdového listu neexistuje.');
        }
        $identities = $this->db->pdo()->prepare(
            'SELECT id, full_name, birth_surname, effective_from, effective_to
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id
              FOR UPDATE'
        );
        $identities->execute([$supplierId, $employeeId, $to, $from]);
        $identityRows = $identities->fetchAll(PDO::FETCH_ASSOC);
        if ($identityRows === []) {
            throw new \DomainException('Pro mzdový list chybí účinná historie jména.');
        }
        $currentIdentity = $identityRows[array_key_last($identityRows)];
        $names = [];
        foreach ($identityRows as $identity) {
            foreach ([$identity['full_name'] ?? null, $identity['birth_surname'] ?? null] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[trim($name)] = true;
                }
            }
        }
        unset($names[(string) $currentIdentity['full_name']]);

        $address = $this->db->pdo()->prepare(
            'SELECT id, street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
                AND address_type = "residence"
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $address->execute([$supplierId, $employeeId, $to, $from]);
        $addressRow = $address->fetch(PDO::FETCH_ASSOC);
        if (!is_array($addressRow)) {
            throw new \DomainException('Pro mzdový list chybí účinná adresa bydliště.');
        }
        if (($addressRow['country_code'] ?? null) !== 'CZ') {
            throw new \DomainException(
                'Mzdový list nerezidenta vyžaduje rozšířenou identitu dokladu a státu.',
            );
        }
        $identifier = $this->db->pdo()->prepare(
            'SELECT id, identifier_type, value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
                AND identifier_type = "birth_number"
              LIMIT 1
              FOR UPDATE'
        );
        $identifier->execute([$supplierId, $employeeId]);
        $identifierRow = $identifier->fetch(PDO::FETCH_ASSOC);
        if (is_array($identifierRow)) {
            $identifierLabel = 'Rodné číslo';
            $identifierValue = $this->sensitiveData->reveal(
                (string) $identifierRow['value_ciphertext'],
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                (int) $identifierRow['id'],
            );
        } elseif (is_string($employeeRow['birth_date'] ?? null)) {
            $identifierLabel = 'Datum narození';
            $identifierValue = (new \DateTimeImmutable(
                (string) $employeeRow['birth_date'],
            ))->format('d.m.Y');
        } else {
            throw new \DomainException('Pro mzdový list chybí rodné číslo i datum narození.');
        }

        return [
            'name' => (string) $currentIdentity['full_name'],
            'previous_names' => array_keys($names),
            'identifier_label' => $identifierLabel,
            'identifier_value' => $identifierValue,
            'address' => implode(', ', [
                trim((string) $addressRow['street_line']),
                trim((string) $addressRow['postal_code'] . ' ' . (string) $addressRow['city']),
                (string) $addressRow['country_code'],
            ]),
        ];
    }

    /** @return array<string,string> */
    private function employerSnapshot(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name,
                    ic, street, zip, city
               FROM supplier
              WHERE id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Zaměstnavatel mzdového listu neexistuje.');
        }
        foreach (['name', 'ic', 'street', 'zip', 'city'] as $field) {
            if (!is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                throw new \DomainException("Zaměstnavatel nemá pro mzdový list vyplněné pole {$field}.");
            }
        }
        return [
            'name' => trim((string) $row['name']),
            'identification_number' => trim((string) $row['ic']),
            'address' => trim((string) $row['street'])
                . ', '
                . trim((string) $row['zip'] . ' ' . (string) $row['city']),
        ];
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private function decryptSnapshot(array $revision): array
    {
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            $this->encryptionContext(
                (int) $revision['supplier_id'],
                (int) $revision['employee_id'],
                (int) $revision['tax_year'],
                $this->hash($revision, 'source_manifest_hash'),
            ),
        );
        $hash = $this->hash($revision, 'snapshot_hash');
        $expected = $this->snapshotFingerprint($json, (int) $revision['supplier_id']);
        if (!hash_equals($hash, $expected)) {
            throw new \DomainException('Otisk ročního mzdového snapshotu nesouhlasí.');
        }
        return $this->decodeObject($json, 'Roční mzdový snapshot');
    }

    /** @param array<string,mixed> $snapshot */
    private function hydrate(array $snapshot, string $snapshotHash): PayrollSheetDocumentData
    {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \DomainException('Roční mzdový snapshot má nepodporované schéma.');
        }
        $employer = $this->object($snapshot['employer'] ?? null, 'employer');
        $employee = $this->object($snapshot['employee'] ?? null, 'employee');
        $months = [];
        foreach ($this->list($snapshot['months'] ?? null, 'months') as $row) {
            $row = $this->object($row, 'months[]');
            $months[] = new PayrollSheetMonth(
                $this->positiveInt($row, 'month'),
                $this->positiveInt($row, 'source_revision_count'),
                $this->nonNegativeInt($row, 'gross_minor_units'),
                $this->nonNegativeInt($row, 'cash_income_minor_units'),
                $this->nonNegativeInt($row, 'non_cash_income_minor_units'),
                $this->nonNegativeInt($row, 'social_assessment_base_minor_units'),
                $this->nonNegativeInt($row, 'employee_social_minor_units'),
                $this->nonNegativeInt($row, 'employer_social_minor_units'),
                $this->nonNegativeInt($row, 'health_assessment_base_minor_units'),
                $this->nonNegativeInt($row, 'employee_health_minor_units'),
                $this->nonNegativeInt($row, 'employer_health_minor_units'),
                $this->nonNegativeInt($row, 'health_minimum_top_up_minor_units'),
                $this->nonNegativeInt($row, 'advance_tax_base_minor_units'),
                $this->nonNegativeInt($row, 'advance_tax_before_credits_minor_units'),
                $this->nonNegativeInt($row, 'non_refundable_credits_minor_units'),
                $this->nonNegativeInt($row, 'child_credit_minor_units'),
                $this->nonNegativeInt($row, 'advance_tax_minor_units'),
                $this->nonNegativeInt($row, 'tax_bonus_minor_units'),
                $this->nonNegativeInt($row, 'withholding_tax_minor_units'),
                $this->nonNegativeInt($row, 'other_deductions_minor_units'),
                $this->nonNegativeInt($row, 'net_payable_minor_units'),
            );
        }
        $previousNames = $this->list($employee['previous_names'] ?? null, 'previous_names');
        foreach ($previousNames as $name) {
            if (!is_string($name)) {
                throw new \DomainException('Dřívější jméno v ročním snapshotu není text.');
            }
        }
        return new PayrollSheetDocumentData(
            $snapshotHash,
            $this->positiveInt($snapshot, 'tax_year'),
            $this->text($employer, 'name'),
            $this->text($employer, 'identification_number'),
            $this->text($employer, 'address'),
            $this->text($employee, 'name'),
            $previousNames,
            $this->text($employee, 'identifier_label'),
            $this->text($employee, 'identifier_value'),
            $this->text($employee, 'address'),
            $months,
            $this->text($snapshot, 'annual_settlement_status'),
        );
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-annual-document',
            (string) $supplierId,
            (string) $employeeId,
            (string) $taxYear,
            self::PURPOSE,
            $manifestHash,
        ]);
    }

    private function snapshotFingerprint(string $snapshotJson, int $supplierId): string
    {
        return $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'annual-payroll-snapshot-v1',
            $supplierId,
        );
    }

    private function assertJsonHash(string $json, string $hash, string $context): void
    {
        if (!hash_equals($hash, hash('sha256', $json))) {
            throw new \DomainException("Otisk {$context} nesouhlasí.");
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json, string $context): array
    {
        return $this->object(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            $context,
        );
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $this->text($row, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \DomainException("Pole {$field} není platný SHA-256.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Chybí textové pole {$field}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int) $value > 0
        ) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není kladné celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int) $value >= 0
        ) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není nezáporné celé číslo.");
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} není objekt.");
        }
        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} není seznam.");
        }
        return $value;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException('Agregace mzdového listu přetekla.');
        }
        return $left + $right;
    }
}
