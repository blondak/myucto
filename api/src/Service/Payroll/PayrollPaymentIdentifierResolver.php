<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

final class PayrollPaymentIdentifierResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /**
     * @return array{value:string,source:string}|null
     */
    public function defaultForOperation(
        int $supplierId,
        string $operationType,
        ?string $effectiveOn = null,
    ): ?array {
        $date = $this->date($effectiveOn);
        if (!$this->payrollEnabled($supplierId)) {
            return null;
        }
        if (!$this->payrollSchemaAvailable()) {
            return $this->legacyDefault($supplierId, $operationType);
        }

        $resolved = match ($operationType) {
            OperationType::REMITTANCE_SOCIAL_EMPLOYER =>
                $this->defaultSocial($supplierId),
            OperationType::REMITTANCE_HEALTH_EMPLOYER =>
                $this->defaultHealth($supplierId, $date),
            default => null,
        };

        if ($resolved !== null) {
            return $resolved;
        }
        if ($this->canonicalIdentifierExists($supplierId, $operationType)) {
            return null;
        }

        return $this->legacyDefault($supplierId, $operationType);
    }

    /**
     * @return array{
     *   operation_type:string,
     *   source:string,
     *   account_match:bool,
     *   variable_symbol_match:bool,
     *   legacy_fallback:bool,
     *   ambiguous:bool
     * }|null
     */
    public function matchEmployerRemittance(
        int $supplierId,
        string $variableSymbol,
        string $effectiveOn,
        ?string $counterpartyAccount,
        ?string $counterpartyBank,
    ): ?array {
        $vs = VariableSymbolNormalizer::forMatching($variableSymbol);
        if ($vs === '') {
            return null;
        }

        $date = $this->date($effectiveOn);
        if (!$this->payrollEnabled($supplierId)) {
            return null;
        }
        if (!$this->payrollSchemaAvailable()) {
            return $this->legacyMatch($supplierId, $vs);
        }

        $accountHashes = $this->accountHashes(
            $supplierId,
            $counterpartyAccount,
            $counterpartyBank,
        );
        $institutionMatches = $this->institutionMatches(
            $supplierId,
            $vs,
            $date,
            $accountHashes,
        );
        $candidates = [];
        foreach ([
            InstitutionAccountType::HEALTH_INSURER->value =>
                OperationType::REMITTANCE_HEALTH_EMPLOYER,
            InstitutionAccountType::SOCIAL_SECURITY->value =>
                OperationType::REMITTANCE_SOCIAL_EMPLOYER,
        ] as $institutionType => $operationType) {
            $match = $institutionMatches[$institutionType] ?? null;
            if ($match !== null) {
                $candidates[$operationType] = [
                    'operation_type' => $operationType,
                    'source' => 'payroll_institution_account',
                    'account_match' => $match['account_match'],
                    'variable_symbol_match' => $match['variable_symbol_match'],
                    'legacy_fallback' => false,
                    'ambiguous' => false,
                ];
            }
        }

        $social = $this->socialSymbols($supplierId, activeOnly: true);
        if (in_array($vs, $social, true)) {
            $operationType = OperationType::REMITTANCE_SOCIAL_EMPLOYER;
            $existing = $candidates[$operationType] ?? null;
            $candidates[$operationType] = $existing ?? [
                'operation_type' => $operationType,
                'source' => 'payroll_office',
                'account_match' => false,
                'variable_symbol_match' => true,
                'legacy_fallback' => false,
                'ambiguous' => false,
            ];
        }

        if ($candidates !== []) {
            $strengths = array_map(
                static fn (array $candidate): int =>
                    (int) $candidate['account_match']
                    + (int) $candidate['variable_symbol_match'],
                $candidates,
            );
            $strongest = max($strengths);
            $best = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool =>
                    (int) $candidate['account_match']
                    + (int) $candidate['variable_symbol_match'] === $strongest,
            ));
            if (count($best) === 1) {
                return $best[0];
            }
            return [
                'operation_type' => OperationType::REMITTANCE_OTHER,
                'source' => 'payroll_identifier_ambiguous',
                'account_match' => false,
                'variable_symbol_match' => true,
                'legacy_fallback' => false,
                'ambiguous' => true,
            ];
        }

        return $this->legacyMatch($supplierId, $vs);
    }

    /** @return array{value:string,source:string}|null */
    private function defaultSocial(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT office.social_security_variable_symbol
               FROM payroll_employer_settings settings
               JOIN payroll_offices office
                 ON office.supplier_id = settings.supplier_id
                AND office.id = settings.default_office_id
              WHERE settings.supplier_id = ?
                AND office.is_active = 1
                AND office.social_security_variable_symbol IS NOT NULL
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }

        return ['value' => $value, 'source' => 'payroll_office'];
    }

    /** @return array{value:string,source:string}|null */
    private function defaultHealth(int $supplierId, string $effectiveOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account.variable_symbol
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
               JOIN payroll_employer_settings settings
                 ON settings.supplier_id = account.supplier_id
              WHERE account.supplier_id = ?
                AND institution.institution_type = ?
                AND account.currency_code = "CZK"
                AND account.variable_symbol IS NOT NULL
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)
                AND (
                    institution.institution_code = settings.default_health_insurer_code
                    OR settings.default_health_insurer_code IS NULL
                )
              ORDER BY
                    (institution.institution_code = settings.default_health_insurer_code) DESC,
                    account.valid_from DESC,
                    account.id DESC
              LIMIT 2'
        );
        $stmt->execute([
            $supplierId,
            InstitutionAccountType::HEALTH_INSURER->value,
            $effectiveOn,
            $effectiveOn,
        ]);
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }
        if (count($values) !== 1) {
            return null;
        }

        return [
            'value' => $values[0],
            'source' => 'payroll_institution_account',
        ];
    }

    /**
     * @param list<string> $accountHashes
     * @return array<string,array{account_match:bool,variable_symbol_match:bool}>
     */
    private function institutionMatches(
        int $supplierId,
        string $variableSymbol,
        string $effectiveOn,
        array $accountHashes,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT institution.institution_type, account.variable_symbol,
                    account.bank_account_hash
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ?
                AND institution.institution_type IN (?, ?)
                AND account.currency_code = "CZK"
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)'
        );
        $stmt->execute([
            $supplierId,
            InstitutionAccountType::SOCIAL_SECURITY->value,
            InstitutionAccountType::HEALTH_INSURER->value,
            $effectiveOn,
            $effectiveOn,
        ]);

        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $value) {
            $row = self::databaseRow($value);
            $type = self::nullableString($row, 'institution_type') ?? '';
            $storedVs = VariableSymbolNormalizer::forMatching(
                self::nullableString($row, 'variable_symbol') ?? ''
            );
            $vsMatch = $storedVs !== '' && hash_equals($storedVs, $variableSymbol);
            $storedHash = $row['bank_account_hash'] ?? null;
            $bankMatch = false;
            if (is_string($storedHash) && strlen($storedHash) === 32) {
                foreach ($accountHashes as $accountHash) {
                    if (hash_equals($storedHash, $accountHash)) {
                        $bankMatch = true;
                        break;
                    }
                }
            }
            // U účtu s evidovaným zaměstnavatelským VS je právě VS rozlišením
            // proti osobní platbě OSVČ. Stejný účet pojišťovny může přijímat obě
            // platby, proto samotná shoda účtu nesmí přebít odlišný osobní VS.
            if (($storedVs !== '' && !$vsMatch) || ($storedVs === '' && !$bankMatch)) {
                continue;
            }
            $candidate = [
                'account_match' => $bankMatch,
                'variable_symbol_match' => $vsMatch,
            ];
            $current = $matches[$type] ?? null;
            if ($current === null
                || self::matchStrength($candidate) > self::matchStrength($current)
                || (
                    self::matchStrength($candidate) === self::matchStrength($current)
                    && $candidate['account_match']
                    && !$current['account_match']
                )
            ) {
                $matches[$type] = $candidate;
            }
        }

        return $matches;
    }

    /** @param array{account_match:bool,variable_symbol_match:bool} $match */
    private static function matchStrength(array $match): int
    {
        return (int) $match['account_match']
            + (int) $match['variable_symbol_match'];
    }

    /** @return list<string> */
    private function socialSymbols(int $supplierId, bool $activeOnly = false): array
    {
        $activeCondition = $activeOnly ? ' AND is_active = 1' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT social_security_variable_symbol
               FROM payroll_offices
              WHERE supplier_id = ?
                AND social_security_variable_symbol IS NOT NULL'
            . $activeCondition
        );
        $stmt->execute([$supplierId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (!is_string($value)) {
                continue;
            }
            $normalized = VariableSymbolNormalizer::forMatching($value);
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }
        return array_values(array_unique($result));
    }

    /** @return array{value:string,source:string}|null */
    private function legacyDefault(int $supplierId, string $operationType): ?array
    {
        if (!in_array($operationType, [
            OperationType::REMITTANCE_SOCIAL_EMPLOYER,
            OperationType::REMITTANCE_HEALTH_EMPLOYER,
        ], true)) {
            return null;
        }
        $supplier = $this->legacySupplier($supplierId);
        if ($supplier === null || $supplier['taxpayer_type'] !== 'po') {
            return null;
        }
        $column = $operationType === OperationType::REMITTANCE_SOCIAL_EMPLOYER
            ? 'cssz_vsdp'
            : 'health_insurance_number';
        $value = VariableSymbolNormalizer::forPayment((string) $supplier[$column]);
        return $value === ''
            ? null
            : ['value' => $value, 'source' => 'legacy_supplier_migration'];
    }

    /**
     * @return array{
     *   operation_type:string,
     *   source:string,
     *   account_match:bool,
     *   variable_symbol_match:bool,
     *   legacy_fallback:bool,
     *   ambiguous:bool
     * }|null
     */
    private function legacyMatch(int $supplierId, string $variableSymbol): ?array
    {
        $supplier = $this->legacySupplier($supplierId);
        if ($supplier === null || $supplier['taxpayer_type'] !== 'po') {
            return null;
        }

        $candidates = [
            OperationType::REMITTANCE_SOCIAL_EMPLOYER =>
                VariableSymbolNormalizer::forMatching((string) $supplier['cssz_vsdp']),
            OperationType::REMITTANCE_HEALTH_EMPLOYER =>
                VariableSymbolNormalizer::forMatching(
                    (string) $supplier['health_insurance_number']
                ),
        ];
        foreach ($candidates as $operationType => $candidate) {
            if ($candidate === '' || !hash_equals($candidate, $variableSymbol)) {
                continue;
            }
            if ($this->canonicalIdentifierExists($supplierId, $operationType)) {
                return null;
            }
            return [
                'operation_type' => $operationType,
                'source' => 'legacy_supplier_migration',
                'account_match' => false,
                'variable_symbol_match' => true,
                'legacy_fallback' => true,
                'ambiguous' => false,
            ];
        }

        return null;
    }

    private function canonicalIdentifierExists(int $supplierId, string $operationType): bool
    {
        if (!$this->payrollSchemaAvailable()) {
            return false;
        }
        if ($operationType === OperationType::REMITTANCE_SOCIAL_EMPLOYER) {
            return $this->socialSymbols($supplierId) !== [];
        }
        if ($operationType !== OperationType::REMITTANCE_HEALTH_EMPLOYER) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ?
                AND institution.institution_type = ?
                AND account.variable_symbol IS NOT NULL
              LIMIT 1'
        );
        $stmt->execute([$supplierId, InstitutionAccountType::HEALTH_INSURER->value]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array{taxpayer_type:string,cssz_vsdp:?string,health_insurance_number:?string}|null */
    private function legacySupplier(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT taxpayer_type, cssz_vsdp, health_insurance_number
               FROM supplier
              WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($value === false) {
            return null;
        }
        $row = self::databaseRow($value);
        return [
            'taxpayer_type' =>
                self::nullableString($row, 'taxpayer_type') ?? 'fo',
            'cssz_vsdp' => self::nullableString($row, 'cssz_vsdp'),
            'health_insurance_number' =>
                self::nullableString($row, 'health_insurance_number'),
        ];
    }

    /** @return list<string> */
    private function accountHashes(
        int $supplierId,
        ?string $counterpartyAccount,
        ?string $counterpartyBank,
    ): array {
        $raw = trim((string) $counterpartyAccount);
        if ($raw === '') {
            return [];
        }
        $candidates = [$raw];
        $bank = AccountNumberNormalizer::canonicalBankCode($counterpartyBank, $raw);
        $base = AccountNumberNormalizer::czechAccountBase($raw);
        if ($bank !== null && $base !== null) {
            $prefix = AccountNumberNormalizer::czechAccountPrefix($raw);
            $candidates[] = ($prefix === null ? '' : $prefix . '-')
                . $base
                . '/'
                . $bank;
        }

        $result = [];
        foreach (array_values(array_unique($candidates)) as $candidate) {
            $result[] = $this->sensitiveData->lookupHash(
                $candidate,
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
            );
        }
        return $result;
    }

    private function payrollSchemaAvailable(): bool
    {
        return $this->db->hasTable('payroll_offices')
            && $this->db->hasColumn('payroll_offices', 'social_security_variable_symbol')
            && $this->db->hasTable('payroll_institutions')
            && $this->db->hasTable('payroll_institution_accounts');
    }

    private function payrollEnabled(int $supplierId): bool
    {
        if (!$this->db->hasColumn('supplier', 'payroll_enabled')) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT payroll_enabled FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }

    private function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return date('Y-m-d');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Datum účinnosti není platné.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function databaseRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný klíč řádku.'
                );
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Databázové pole {$key} není řetězec.");
        }
        return $value;
    }
}
