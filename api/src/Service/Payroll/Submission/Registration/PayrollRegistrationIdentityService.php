<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

final readonly class PayrollRegistrationIdentityService
{
    private const ENVIRONMENTS = ['production', 'test'];
    private const IDENTIFIER_TYPES = [
        'birth_number',
        'ecp',
        'vcp',
        'foreign_tax_identifier',
    ];
    private const TASK_KINDS = [
        'person_identity',
        'employment_external_id',
    ];
    private const SOURCE_KINDS = [
        'trusted_receipt',
        'verified_manual_import',
    ];

    public function __construct(
        private PayrollRegistrationIdentityRepository $repository,
        private PayrollSensitiveData $sensitiveData,
    ) {}

    /**
     * Interní citlivý snapshot; nesmí být vrácen běžným listovacím endpointem.
     *
     * @return array{
     *   identity:array<string,mixed>,
     *   identifiers:array{
     *     birth_number:?string,ecp:?string,vcp:?string,
     *     foreign_tax_identifier:?string
     *   }
     * }
     */
    public function sensitiveIdentityAt(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->date($onDate, 'Rozhodné datum');
        $identity = $this->repository->identityAt(
            $supplierId,
            $employeeId,
            $onDate,
        );
        if ($identity === null) {
            throw new \DomainException(
                'K rozhodnému datu chybí historická identita osoby.',
            );
        }
        if ($this->nullableText($identity['first_name'] ?? null) === null
            || $this->nullableText($identity['last_name'] ?? null) === null
        ) {
            throw new \DomainException(
                'Historická identita nemá explicitní jméno a příjmení.',
            );
        }

        $identifiers = array_fill_keys(self::IDENTIFIER_TYPES, null);
        foreach ($this->repository->identifiers(
            $supplierId,
            $employeeId,
        ) as $stored) {
            $type = $stored['identifier_type'];
            if (!array_key_exists($type, $identifiers)) {
                throw new \UnexpectedValueException(
                    'Osoba obsahuje nepodporovaný typ identifikátoru.',
                );
            }
            $field = $type === 'foreign_tax_identifier'
                ? PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER
                : PayrollSensitiveField::PERSONAL_IDENTIFIER;
            $plaintext = $this->sensitiveData->reveal(
                $stored['value_ciphertext'],
                $field,
                $supplierId,
                $stored['id'],
            );
            $hash = $this->sensitiveData->lookupHash(
                $plaintext,
                $field,
                $supplierId,
            );
            if (!hash_equals($stored['value_hash'], $hash)) {
                throw new \RuntimeException(
                    'Otisk osobního identifikátoru neodpovídá ciphertextu.',
                );
            }
            $identifiers[$type] = $plaintext;
        }

        return [
            'identity' => $identity,
            'identifiers' => $identifiers,
        ];
    }

    /**
     * @param array{
     *   title_prefix?:?string,title_suffix?:?string,birth_date?:?string,
     *   birth_place?:?string,birth_country_code?:?string,
     *   citizenship_country_code?:?string,sex?:?string
     * } $facts
     */
    public function saveIdentityFacts(
        int $supplierId,
        int $employeeId,
        int $identityId,
        int $expectedRowVersion,
        array $facts,
    ): int {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->positive($identityId, 'Historická identita');
        $this->positive($expectedRowVersion, 'Verze identity');
        $normalized = [
            'title_prefix' => $this->optionalText(
                $facts,
                'title_prefix',
                64,
            ),
            'title_suffix' => $this->optionalText(
                $facts,
                'title_suffix',
                64,
            ),
            'birth_date' => $this->optionalDate($facts, 'birth_date'),
            'birth_place' => $this->optionalText(
                $facts,
                'birth_place',
                128,
            ),
            'birth_country_code' => $this->optionalCountry(
                $facts,
                'birth_country_code',
            ),
            'citizenship_country_code' => $this->optionalCountry(
                $facts,
                'citizenship_country_code',
            ),
            'sex' => $this->optionalEnum(
                $facts,
                'sex',
                ['female', 'male', 'unspecified'],
            ),
        ];

        return $this->repository->updateIdentityFacts(
            $supplierId,
            $employeeId,
            $identityId,
            $expectedRowVersion,
            $normalized,
        );
    }

    /**
     * @return array{
     *   id:int,employment_id:int,employee_id:int,environment:string,
     *   identifier_type:string,value_masked:string,valid_from:string,
     *   row_version:int,created:bool
     * }
     */
    public function assignEmploymentExternalId(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $value,
        string $validFrom,
        string $sourceKind,
        string $sourceReference,
        ?int $sourceReceiptId,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($validFrom, 'Platnost externího ID');
        $this->allowed($sourceKind, self::SOURCE_KINDS, 'Zdroj externího ID');
        $this->optionalPositive($sourceReceiptId, 'Protokol');
        $this->optionalPositive($createdBy, 'Uživatel');
        if (($sourceKind === 'trusted_receipt')
            !== ($sourceReceiptId !== null)
        ) {
            throw new \InvalidArgumentException(
                'Trusted externí ID musí odkazovat na ověřený protokol.',
            );
        }
        $sourceHash = $this->sensitiveData->keyedFingerprint(
            $this->evidenceReference($sourceReference),
            'employment-external-id-source',
            $supplierId,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $environment,
            $value,
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceHash,
            $createdBy,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \DomainException(
                    'Pracovní vztah nebyl nalezen ve stejné firmě.',
                );
            }
            if ($validFrom < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $validFrom > $employment['end_date'])
            ) {
                throw new \DomainException(
                    'Platnost externího ID neleží v období pracovního vztahu.',
                );
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(
                    'Zdrojový protokol není důvěryhodný nebo patří jinému prostředí.',
                );
            }
            $existing = $this->repository->activeExternalId(
                $supplierId,
                $employmentId,
                $environment,
                'id_ppv',
            );
            if ($existing !== null) {
                $plaintext = $this->sensitiveData->reveal(
                    $existing['value_ciphertext'],
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                    $existing['id'],
                );
                $storedHash = $this->sensitiveData->lookupHash(
                    $plaintext,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($existing['value_hash'], $storedHash)) {
                    throw new \RuntimeException(
                        'Otisk externího ID neodpovídá ciphertextu.',
                    );
                }
                $inputHash = $this->sensitiveData->lookupHash(
                    $value,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($existing['value_hash'], $inputHash)) {
                    throw new \DomainException(
                        'Pracovní vztah už má jiné aktivní ID PPV.',
                    );
                }

                return [
                    'id' => $existing['id'],
                    'employment_id' => $existing['employment_id'],
                    'employee_id' => $existing['employee_id'],
                    'environment' => $existing['environment'],
                    'identifier_type' => $existing['identifier_type'],
                    'value_masked' => $existing['value_masked'],
                    'valid_from' => $existing['valid_from'],
                    'row_version' => $existing['row_version'],
                    'created' => false,
                ];
            }

            $id = $this->repository->insertExternalIdPlaceholder(
                $supplierId,
                $employment['employee_id'],
                $employmentId,
                $environment,
                'id_ppv',
                $validFrom,
                $sourceKind,
                $sourceReceiptId,
                $sourceHash,
                $createdBy,
            );
            $sealed = $this->sensitiveData->seal(
                $value,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
                $id,
            );
            $this->repository->sealExternalId(
                $supplierId,
                $id,
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
            );

            return [
                'id' => $id,
                'employment_id' => $employmentId,
                'employee_id' => $employment['employee_id'],
                'environment' => $environment,
                'identifier_type' => 'id_ppv',
                'value_masked' => $sealed->masked,
                'valid_from' => $validFrom,
                'row_version' => 1,
                'created' => true,
            ];
        });
    }

    /**
     * @return array{id:int,status:string,row_version:int,created:bool}
     */
    public function openResolutionTask(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $taskKind,
        string $reasonCode,
        ?int $candidateCount,
        ?int $sourceReceiptId,
        ?int $assignedTo,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->allowed($taskKind, self::TASK_KINDS, 'Druh úkolu');
        $this->code($reasonCode, 'Důvod úkolu');
        if ($candidateCount !== null
            && ($candidateCount < 0 || $candidateCount > 1500)
        ) {
            throw new \InvalidArgumentException(
                'Počet kandidátů identity není platný.',
            );
        }
        $this->optionalPositive($sourceReceiptId, 'Protokol');
        $this->optionalPositive($assignedTo, 'Řešitel');
        $this->optionalPositive($createdBy, 'Uživatel');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $environment,
            $taskKind,
            $reasonCode,
            $candidateCount,
            $sourceReceiptId,
            $assignedTo,
            $createdBy,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \DomainException(
                    'Pracovní vztah nebyl nalezen ve stejné firmě.',
                );
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(
                    'Zdrojový protokol není důvěryhodný nebo patří jinému prostředí.',
                );
            }

            return $this->repository->openResolutionTask(
                $supplierId,
                $employment['employee_id'],
                $employmentId,
                $environment,
                $taskKind,
                $reasonCode,
                $candidateCount,
                $sourceReceiptId,
                $assignedTo,
                $createdBy,
            );
        });
    }

    public function resolveTask(
        int $supplierId,
        int $taskId,
        int $expectedRowVersion,
        string $environment,
        ?int $externalId,
        string $evidenceReference,
        int $resolvedBy,
    ): int {
        $this->positive($supplierId, 'Firma');
        $this->positive($taskId, 'Resolution task');
        $this->positive($expectedRowVersion, 'Verze úkolu');
        $this->environment($environment);
        $this->optionalPositive($externalId, 'Externí ID');
        $this->positive($resolvedBy, 'Řešitel');
        $evidenceHash = $this->sensitiveData->keyedFingerprint(
            $this->evidenceReference($evidenceReference),
            'identity-resolution-evidence',
            $supplierId,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $taskId,
            $expectedRowVersion,
            $environment,
            $externalId,
            $evidenceHash,
            $resolvedBy,
        ): int {
            $task = $this->repository->lockResolutionTask(
                $supplierId,
                $taskId,
                $environment,
            );
            if ($task === null) {
                throw new \DomainException(
                    'Resolution task nebyl nalezen ve stejné firmě a prostředí.',
                );
            }
            if ($task['row_version'] !== $expectedRowVersion) {
                throw new \DomainException('Resolution task se mezitím změnil.');
            }
            if ($task['task_kind'] === 'employment_external_id') {
                if ($externalId === null) {
                    throw new \InvalidArgumentException(
                        'Úkol externího ID vyžaduje vyřešené ID PPV.',
                    );
                }
                $resolved = $this->repository->externalIdById(
                    $supplierId,
                    $externalId,
                    $environment,
                );
                if ($resolved === null
                    || $resolved['employment_id']
                        !== $task['employment_id']
                    || $resolved['employee_id']
                        !== $task['employee_id']
                ) {
                    throw new \DomainException(
                        'Externí ID nepatří řešenému vztahu.',
                    );
                }
            } elseif ($externalId !== null) {
                throw new \InvalidArgumentException(
                    'Úkol identity osoby nesmí být vyřešen ID pracovního vztahu.',
                );
            }

            return $this->repository->resolveTask(
                $supplierId,
                $taskId,
                $expectedRowVersion,
                $externalId,
                $evidenceHash,
                $resolvedBy,
            );
        });
    }

    /** @param array<string,mixed> $source */
    private function optionalText(
        array $source,
        string $key,
        int $maxLength,
    ): ?string {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new \InvalidArgumentException("{$key} musí být text.");
        }
        $value = trim($source[$key]);
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException("{$key} není platné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function optionalDate(array $source, string $key): ?string
    {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new \InvalidArgumentException("{$key} musí být datum.");
        }
        $this->date($source[$key], $key);

        return $source[$key];
    }

    /** @param array<string,mixed> $source */
    private function optionalCountry(array $source, string $key): ?string
    {
        $value = $this->optionalText($source, $key, 2);
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$key} není platný kód státu.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $allowed
     */
    private function optionalEnum(
        array $source,
        string $key,
        array $allowed,
    ): ?string {
        $value = $this->optionalText($source, $key, 32);
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$key} není podporované.");
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                'Historická identita obsahuje neplatný text.',
            );
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function evidenceReference(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 500) {
            throw new \InvalidArgumentException(
                'Reference důkazu není platná.',
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException(
                'Reference důkazu obsahuje řídicí znak.',
            );
        }

        return $value;
    }

    private function date(string $value, string $label): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$label} není platné datum.");
        }
    }

    private function environment(string $environment): void
    {
        $this->allowed($environment, self::ENVIRONMENTS, 'Prostředí');
    }

    /** @param list<string> $allowed */
    private function allowed(
        string $value,
        array $allowed,
        string $label,
    ): void {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$label} není podporované.");
        }
    }

    private function code(string $value, string $label): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$label} není platný kód.");
        }
    }

    private function positive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$label} musí být kladné ID.");
        }
    }

    private function optionalPositive(?int $value, string $label): void
    {
        if ($value !== null) {
            $this->positive($value, $label);
        }
    }
}
