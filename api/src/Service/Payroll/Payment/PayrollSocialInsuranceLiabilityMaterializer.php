<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

final class PayrollSocialInsuranceLiabilityMaterializer
{
    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly Connection $db,
        private readonly PayrollLevyDeadlinePolicy $deadlines,
    ) {}

    /** @return array{liability_ids:list<int>,created_count:int} */
    public function materialize(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize sociálního závazku musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace sociálního závazku není platný.',
            );
        }

        return $this->liabilities->transaction(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): array {
            $revision = $this->liabilities->lockRevision(
                $supplierId,
                $revisionId,
            );
            if ($revision === null) {
                throw new \DomainException('Mzdová revize neexistuje.');
            }
            $this->assertRevision($revision);
            $input = $this->canonicalObject(
                $revision['input_snapshot_json'],
                $revision['input_snapshot_hash'],
                'vstupního snapshotu revize',
            );
            if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
                || ($input['supplier_id'] ?? null) !== $supplierId
                || ($input['period_start'] ?? null)
                    !== $revision['period_start']
            ) {
                throw new \DomainException(
                    'Zmrazený vstup neodpovídá firmě a období revize.',
                );
            }
            $officeId = $this->positiveInt(
                $input['office_id'] ?? null,
                'zmrazenou mzdovou účtárnu',
            );
            $statutory = $this->statutoryResults->find(
                $supplierId,
                $revisionId,
                'social_insurance',
            );
            if ($statutory === null) {
                throw new \DomainException(
                    'Revize nemá neměnný výsledek sociálního pojištění.',
                );
            }
            if (($statutory['schema_version'] ?? null)
                    !== 'payroll-social-result.v1'
                || ($statutory['result_status'] ?? null) !== 'calculated'
                || !hash_equals(
                    $revision['input_snapshot_hash'],
                    $this->hash($statutory, 'input_snapshot_hash'),
                )
            ) {
                throw new \DomainException(
                    'Sociální závazek vyžaduje vypočtený výsledek ze stejného zmrazeného vstupu.',
                );
            }
            $root = $this->object(
                $statutory['result_snapshot'] ?? null,
                'výsledek sociálního pojištění',
            );
            $rootHash = $this->hash($statutory, 'result_snapshot_hash');
            if (($root['status'] ?? null) !== 'calculated'
                || !hash_equals(
                    $rootHash,
                    hash('sha256', CanonicalJson::encode($root)),
                )
            ) {
                throw new \DomainException(
                    'Výsledek sociálního pojištění není úplný nebo má chybný otisk.',
                );
            }
            $calculationDate = $this->date(
                $root['calculation_date'] ?? null,
                'datum výpočtu sociálního pojištění',
            );
            if (substr($calculationDate, 0, 7)
                !== substr($revision['period_start'], 0, 7)
            ) {
                throw new \DomainException(
                    'Výpočet sociálního pojištění patří jinému období.',
                );
            }
            $employee = $this->nonNegativeInt(
                $root['employee_contribution_minor_units'] ?? null,
                'odvod zaměstnanců',
            );
            $employer = $this->nonNegativeInt(
                $root['employer_contribution_minor_units'] ?? null,
                'odvod zaměstnavatele',
            );
            $employerBeforeDiscount = $this->nonNegativeInt(
                $root[
                    'employer_contribution_before_discount_minor_units'
                ] ?? null,
                'odvod zaměstnavatele před slevou',
            );
            $partTimeDiscount = $this->nonNegativeInt(
                $root['part_time_discount_minor_units'] ?? null,
                'sleva zaměstnavatele',
            );
            if ($partTimeDiscount > $employerBeforeDiscount
                || $employer !== $employerBeforeDiscount
                    - $partTimeDiscount
            ) {
                throw new \DomainException(
                    'Kořenový odvod zaměstnavatele neodpovídá odvodu před slevou.',
                );
            }
            $personSum = 0;
            foreach ($this->rows(
                $statutory['people'] ?? null,
                'výsledky osob sociálního pojištění',
            ) as $person) {
                if (($person['result_status'] ?? null) !== 'calculated') {
                    throw new \DomainException(
                        'Sociální výsledek obsahuje osobu k ruční kontrole.',
                    );
                }
                $personResult = $this->object(
                    $person['result_snapshot'] ?? null,
                    'výsledek osoby sociálního pojištění',
                );
                $personHash = $this->hash(
                    $person,
                    'result_snapshot_hash',
                );
                if (!hash_equals(
                    $personHash,
                    hash(
                        'sha256',
                        CanonicalJson::encode($personResult),
                    ),
                )) {
                    throw new \DomainException(
                        'Otisk výsledku osoby sociálního pojištění nesouhlasí.',
                    );
                }
                $personSum = $this->add(
                    $personSum,
                    $this->nonNegativeInt(
                        $personResult[
                            'employee_contribution_minor_units'
                        ] ?? null,
                        'odvod osoby',
                    ),
                );
            }
            if ($personSum !== $employee) {
                throw new \DomainException(
                    'Kořenový odvod zaměstnanců neodpovídá součtu osob.',
                );
            }
            $targetAmount = $this->add($employee, $employer);
            $dueOn = $this->statutoryDueOn(
                $revision['period_start'],
            );
            $target = $this->target(
                $supplierId,
                $officeId,
                $dueOn,
            );
            $reference = "social-insurance:office:{$officeId}";
            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    'social_insurance',
                ),
                $reference,
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== null) {
                throw new \DomainException(
                    'Další revize sociálního závazku musí být opravná.',
                );
            }
            if ($revision['revision_kind'] === 'correction'
                && $revision['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá předchozí revizi.',
                );
            }
            if ($prior !== null
                && (
                    $prior['recipient_reference']
                        !== $target['recipient_reference']
                    || $prior['target_snapshot']
                        !== $target['target_snapshot']
                )
            ) {
                throw new \DomainException(
                    'Ověřený cíl sociálního pojištění se proti předchozímu závazku změnil.',
                );
            }
            $priorSigned = $prior['signed_minor'] ?? 0;
            $delta = $this->subtract($targetAmount, $priorSigned);
            if ($delta === 0) {
                return ['liability_ids' => [], 'created_count' => 0];
            }
            $direction = $delta > 0 ? 'outgoing' : 'incoming';
            $amount = $this->absolute($delta);
            $source = [
                'schema_reference' =>
                    'payroll-payment-social-insurance-source.v1',
                'run_id' => $revision['run_id'],
                'revision_id' => $revisionId,
                'revision_no' => $revision['revision_no'],
                'statutory_result_hash' => $rootHash,
                'logical_reference' => $reference,
                'recipient_reference' =>
                    $target['recipient_reference'],
                ...$target['target_snapshot'],
                'employee_contribution_minor' => $employee,
                'employer_contribution_minor' => $employer,
                'target_amount_minor' => $targetAmount,
                'prior_signed_minor' => $priorSigned,
                'delta_signed_minor' => $delta,
            ];
            $sourceJson = CanonicalJson::encode($source);
            $sourceHash = hash('sha256', $sourceJson);
            $idempotencyHash = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-payment-social-insurance-idempotency.v1',
                    'supplier_id' => $supplierId,
                    'revision_id' => $revisionId,
                    'logical_reference' => $reference,
                    'source_snapshot_hash' => $sourceHash,
                ]),
                true,
            );
            $previousId = $prior['latest_id'] ?? null;
            $existing = $this->liabilities->findAnyForUpdate(
                $supplierId,
                $revisionId,
                $reference,
            );
            if ($existing !== null) {
                $this->assertReplay(
                    $existing,
                    $direction,
                    $target['recipient_reference'],
                    $dueOn,
                    $amount,
                    $previousId,
                    $sourceHash,
                    $idempotencyHash,
                );

                return [
                    'liability_ids' => [$existing['id']],
                    'created_count' => 0,
                ];
            }
            $id = $this->liabilities->insertInstitutional(
                $supplierId,
                $revisionId,
                $reference,
                'social_insurance',
                $direction,
                $target['recipient_reference'],
                $dueOn,
                $amount,
                $previousId,
                $sourceJson,
                $sourceHash,
                $idempotencyHash,
                $actorUserId,
            );

            return ['liability_ids' => [$id], 'created_count' => 1];
        });
    }

    /** @param array<string,mixed> $revision */
    private function assertRevision(array $revision): void
    {
        if (($revision['revision_status'] ?? null) !== 'approved'
            || ($revision['revision_no'] ?? null)
                !== ($revision['current_revision_no'] ?? null)
        ) {
            throw new \DomainException(
                'Závazek lze vytvořit jen z aktuální schválené revize.',
            );
        }
        if (($revision['schema_version'] ?? null)
            !== 'payroll-run-input.v2'
        ) {
            throw new \DomainException(
                'Závazek vyžaduje vstup payroll-run-input.v2.',
            );
        }
        if (!in_array($revision['revision_kind'] ?? null, [
            'regular',
            'correction',
        ], true)) {
            throw new \DomainException('Typ mzdové revize není podporovaný.');
        }
    }

    /**
     * @return array{
     *   recipient_reference:string,
     *   target_snapshot:array<string,mixed>
     * }
     */
    private function target(
        int $supplierId,
        int $officeId,
        string $dueOn,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT office.id, office.code, office.name,
                    office.social_security_variable_symbol,
                    office.is_active, office.row_version AS office_row_version,
                    settings.social_security_office_code,
                    settings.row_version AS settings_row_version
               FROM payroll_offices office
               JOIN payroll_employer_settings settings
                 ON settings.supplier_id = office.supplier_id
              WHERE office.supplier_id = ? AND office.id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $officeId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            throw new \DomainException(
                'Zmrazená mzdová účtárna neexistuje.',
            );
        }
        $office = $this->databaseRow($raw, 'mzdovou účtárnu');
        if (!$this->boolean($office, 'is_active')) {
            throw new \DomainException(
                'Zmrazená mzdová účtárna není aktivní.',
            );
        }
        $variableSymbol = $this->string(
            $office,
            'social_security_variable_symbol',
        );
        if (preg_match('/^[0-9]{1,10}$/D', $variableSymbol) !== 1) {
            throw new \DomainException(
                'Mzdová účtárna nemá platný zaměstnavatelský VS.',
            );
        }
        $institutionCode = $this->string(
            $office,
            'social_security_office_code',
        );
        if (preg_match(
            '/^[A-Z0-9][A-Z0-9._-]{0,31}$/D',
            $institutionCode,
        ) !== 1) {
            throw new \DomainException(
                'Kód správy sociálního zabezpečení není platný.',
            );
        }
        $accounts = $this->institutions->lockEffectivePaymentTargets(
            $supplierId,
            'social_security',
            $institutionCode,
            'CZK',
            $dueOn,
        );
        if (count($accounts) !== 1) {
            throw new \DomainException(
                count($accounts) === 0
                    ? 'Správa sociálního zabezpečení nemá účinný ověřený účet.'
                    : 'Správa sociálního zabezpečení má nejednoznačný účet.',
            );
        }
        $account = $accounts[0];
        $this->assertVerifiedAccount($supplierId, $dueOn, $account);
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-social-institution-target-verification.v1',
                'institution_type' => 'social_security',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payroll_office_id' => $officeId,
                'payroll_office_code' => $this->string($office, 'code'),
                'payroll_office_row_version' =>
                    $this->integer($office, 'office_row_version'),
                'employer_settings_row_version' =>
                    $this->integer($office, 'settings_row_version'),
                'variable_symbol' => $variableSymbol,
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ]),
        );

        return [
            'recipient_reference' =>
                "institution:social_security:{$institutionCode}:account:"
                . $account['id'],
            'target_snapshot' => [
                'institution_type' => 'social_security',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payment_target_verification_hash' => $verificationHash,
                'payroll_office_id' => $officeId,
                'payroll_office_code' => $this->string($office, 'code'),
                'payroll_office_row_version' =>
                    $this->integer($office, 'office_row_version'),
                'employer_settings_row_version' =>
                    $this->integer($office, 'settings_row_version'),
                'variable_symbol' => $variableSymbol,
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
            ],
        ];
    }

    /**
     * @param array{
     *   id:int,
     *   institution_id:int,
     *   institution_type:string,
     *   institution_code:string,
     *   institution_name:string,
     *   bank_account_ciphertext:string,
     *   bank_account_hash:string,
     *   currency_code:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_from:string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string,
     *   verified_by:?int,
     *   row_version:int
     * } $account
     */
    private function assertVerifiedAccount(
        int $supplierId,
        string $dueOn,
        array $account,
    ): void {
        if (!in_array($account['source_kind'], [
            'official_registry',
            'official_document',
            'institution_notice',
            'user_verified',
        ], true)
            || $account['verified_by'] === null
            || $account['verified_by'] <= 0
            || $this->date(
                $account['verified_on'],
                'datum ověření účtu instituce',
            ) > $dueOn
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $account['bank_account_hash'],
            ) !== 1
        ) {
            throw new \DomainException(
                'Účet správy sociálního zabezpečení není ověřený.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $account['id'],
        );
        $actualHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $actualHash)) {
            throw new \DomainException(
                'Obsah účtu instituce neodpovídá uloženému otisku.',
            );
        }
    }

    /**
     * @param list<array{
     *   id:int,
     *   liability_reference:string,
     *   direction:string,
     *   recipient_reference:string,
     *   amount_minor:int,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * }> $rows
     * @return array{
     *   recipient_reference:string,
     *   signed_minor:int,
     *   latest_id:int,
     *   target_snapshot:array<string,mixed>
     * }|null
     */
    private function priorState(array $rows, string $reference): ?array
    {
        $state = null;
        foreach ($rows as $row) {
            if ($row['liability_reference'] !== $reference) {
                throw new \DomainException(
                    'Dřívější sociální závazek má jinou účtárnu.',
                );
            }
            $source = $this->canonicalObject(
                $row['source_snapshot_json'],
                $row['source_snapshot_hash'],
                'zdroje sociálního závazku',
            );
            if (($source['schema_reference'] ?? null)
                    !== 'payroll-payment-social-insurance-source.v1'
                || ($source['recipient_reference'] ?? null)
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Dřívější sociální závazek nemá platný zdroj.',
                );
            }
            $target = [];
            foreach ([
                'institution_type',
                'institution_code',
                'payment_target_id',
                'payment_target_hash',
                'payment_target_row_version',
                'payment_target_verification_hash',
                'payroll_office_id',
                'payroll_office_code',
                'payroll_office_row_version',
                'employer_settings_row_version',
                'variable_symbol',
                'specific_symbol',
                'constant_symbol',
            ] as $field) {
                $target[$field] = $source[$field] ?? null;
            }
            if ($state === null) {
                $state = [
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                    'target_snapshot' => $target,
                ];
            } elseif ($state['recipient_reference']
                    !== $row['recipient_reference']
                || $state['target_snapshot'] !== $target
            ) {
                throw new \DomainException(
                    'Řetězec sociálního závazku změnil zmrazený cíl.',
                );
            }
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $state['signed_minor'] = $this->add(
                $state['signed_minor'],
                $signed,
            );
            $state['latest_id'] = $row['id'];
        }

        return $state;
    }

    /** @param array<string,mixed> $existing */
    private function assertReplay(
        array $existing,
        string $direction,
        string $recipientReference,
        string $dueOn,
        int $amount,
        ?int $previousId,
        string $sourceHash,
        string $idempotencyHash,
    ): void {
        if (($existing['employee_id'] ?? null) !== null
            || ($existing['liability_kind'] ?? null) !== 'social_insurance'
            || ($existing['direction'] ?? null) !== $direction
            || ($existing['recipient_reference'] ?? null)
                !== $recipientReference
            || ($existing['due_on'] ?? null) !== $dueOn
            || ($existing['amount_minor'] ?? null) !== $amount
            || ($existing['previous_liability_id'] ?? null) !== $previousId
            || !is_string($existing['source_snapshot_hash'] ?? null)
            || !hash_equals(
                $existing['source_snapshot_hash'],
                $sourceHash,
            )
            || !is_string($existing['idempotency_key_hash'] ?? null)
            || !hash_equals(
                $existing['idempotency_key_hash'],
                $idempotencyHash,
            )
        ) {
            throw new \DomainException(
                'Idempotentní replay sociálního závazku nesouhlasí.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(
        string $json,
        string $expectedHash,
        string $context,
    ): array {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException(
                "Kanonický JSON {$context} není platný.",
                previous: $exception,
            );
        }
        $object = $this->object($decoded, $context);
        $canonical = CanonicalJson::encode($object);
        if ($canonical !== $json
            || !hash_equals($expectedHash, hash('sha256', $canonical))
        ) {
            throw new \DomainException("Otisk {$context} nesouhlasí.");
        }

        return $object;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "{$context} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} musí být seznam.");
        }

        return array_map(
            fn (mixed $row): array => $this->object($row, $context),
            $value,
        );
    }

    /** @return array<string,mixed> */
    private function databaseRow(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatnou hodnotu pro {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databáze vrátila neplatný klíč pro {$context}.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("{$field} není neprázdný text.");
        }

        return trim($value);
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $this->string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \DomainException("{$field} není SHA-256 otisk.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "{$field} není celé číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "{$field} není platné celé číslo.",
            );
        }

        return $integer;
    }

    /** @param array<string,mixed> $row */
    private function boolean(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value) && !is_bool($value)) {
            throw new \UnexpectedValueException(
                "{$field} není logická hodnota.",
            );
        }
        $normalized = filter_var(
            $value,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if ($normalized === null) {
            throw new \UnexpectedValueException(
                "{$field} není platná logická hodnota.",
            );
        }

        return $normalized;
    }

    private function positiveInt(mixed $value, string $context): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \DomainException(
                "{$context} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $context): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \DomainException(
                "{$context} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private function date(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new \DomainException("{$context} není datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \DomainException("{$context} není platné datum.");
        }

        return $value;
    }

    private function statutoryDueOn(string $periodStart): string
    {
        $period = $this->date($periodStart, 'mzdové období');
        if (substr($period, 8, 2) !== '01') {
            throw new \DomainException(
                'Mzdové období musí začínat prvním dnem měsíce.',
            );
        }

        return $this->deadlines->dueOn(
            PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
            $period,
        );
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Součet sociálního závazku přetekl.');
        }

        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Rozdíl sociálního závazku přetekl.');
        }

        return $left - $right;
    }

    private function absolute(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new \OverflowException(
                'Absolutní sociální závazek přetekl.',
            );
        }

        return abs($value);
    }
}
