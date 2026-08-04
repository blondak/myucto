<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank\Detect;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Payroll\PayrollPaymentIdentifierResolver;
use PDO;

final class TaxRemittanceDetector implements BankTransactionDetector
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $postingRules,
        private readonly PayrollPaymentIdentifierResolver $payrollIdentifiers,
    ) {}

    public function key(): string
    {
        return 'tax_remittance';
    }

    public function tier(): int
    {
        return 10;
    }

    public function detect(int $supplierId, array $tx): ?DetectionResult
    {
        if ((float) ($tx['amount'] ?? 0) >= 0) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode(
            isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
            isset($tx['counterparty_account']) ? (string) $tx['counterparty_account'] : null,
        );
        if ($bank !== '0710' || strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK')) !== 'CZK') {
            return null;
        }

        $vs = VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? ''));
        if ($vs !== '') {
            $schedule = $this->schedule($supplierId, $vs, (string) $tx['posted_at']);
            if ($schedule !== null) {
                $ruleKey = match ((string) $schedule['advance_kind']) {
                    'tax' => 'tax.income.advance.paid',
                    'social' => 'insurance.social.paid',
                    'health' => 'insurance.health.paid',
                    default => null,
                };
                $operation = match ((string) $schedule['advance_kind']) {
                    'tax' => OperationType::REMITTANCE_INCOME,
                    'social' => OperationType::REMITTANCE_SOCIAL,
                    'health' => OperationType::REMITTANCE_HEALTH,
                    default => null,
                };
                if ($ruleKey !== null && $operation !== null) {
                    $difference = abs(abs((float) $tx['amount']) - (float) $schedule['amount']);
                    return $this->fromRule(
                        $supplierId,
                        $operation,
                        'schedule',
                        $difference <= 100.00001 ? 0.95 : 0.70,
                        $ruleKey,
                        'Platba předpisu zálohy ' . (string) $schedule['due_date'],
                        (int) $schedule['id'],
                        $difference <= 100.00001 ? null : 'schedule_amount_differs',
                        $difference <= 100.00001,
                    );
                }
            }
        }

        $supplier = $this->supplierIdentifiers($supplierId);
        if ($supplier === null) {
            return null;
        }
        $account = (string) ($tx['counterparty_account'] ?? '');
        $employerIdentifier = $this->payrollIdentifiers->matchEmployerRemittance(
            $supplierId,
            $vs,
            (new DateTimeImmutable((string) $tx['posted_at']))->format('Y-m-d'),
            $account,
            isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
        );
        if (($employerIdentifier['ambiguous'] ?? false) === true) {
            return $this->fromRule(
                $supplierId,
                OperationType::REMITTANCE_OTHER,
                'detector',
                0.40,
                'insurance.social.paid',
                'Nejednoznačný identifikátor odvodu zaměstnavatele',
                null,
                'remittance_unclassified',
                false,
            );
        }
        $vsType = $employerIdentifier === null
            ? $this->vsType($vs, $supplier)
            : match ($employerIdentifier['operation_type']) {
                OperationType::REMITTANCE_SOCIAL_EMPLOYER => 'cssz_vsdp',
                OperationType::REMITTANCE_HEALTH_EMPLOYER => 'health_insurance_number',
                default => 'other',
            };
        $mapTaxpayerType = $employerIdentifier === null
            ? (string) ($supplier['taxpayer_type'] ?? 'fo')
            : 'po';
        $prefix = AccountNumberNormalizer::czechAccountPrefix($account);
        $base = AccountNumberNormalizer::czechAccountBase($account);
        $map = $this->map($vsType, $mapTaxpayerType, $prefix, $base);
        if ($map === null) {
            return null;
        }
        if ($employerIdentifier !== null
            && !$employerIdentifier['legacy_fallback']
            && (string) $map['operation_type'] !== $employerIdentifier['operation_type']
        ) {
            return $this->fromRule(
                $supplierId,
                OperationType::REMITTANCE_OTHER,
                'detector',
                0.40,
                'insurance.social.paid',
                'Neshoda účtu a identifikátoru odvodu zaměstnavatele',
                null,
                'remittance_unclassified',
                false,
            );
        }
        $specificVs = $vsType !== 'other' && (string) $map['vs_type'] === $vsType;
        $specificPrefix = $prefix !== null && $map['account_prefix'] !== null;
        // Zdravotní pojišťovny nemají předčíslí — jejich účet pojistného je celé číslo
        // (VZP 1111006311/0710). Konkrétní účet je proto stejně silný identifikátor jako
        // předčíslí u FÚ: platí se z něj jediná věc a nejde splést s jiným příjemcem.
        // Navíc drží i tehdy, když banka do VS pošle DIČ místo čísla pojištěnce.
        $specificAccount = $base !== null && $map['account_number'] !== null;
        $fallback = (string) $map['vs_type'] === 'other'
            && $map['account_prefix'] === null && $map['account_number'] === null;
        $institutionAccountMatch = $employerIdentifier['account_match'] ?? false;
        $legacyFallback = $employerIdentifier['legacy_fallback'] ?? false;
        $confidence = $legacyFallback
            ? 0.70
            : ($fallback
            ? 0.40
            : ($institutionAccountMatch
                || (($specificVs || $specificAccount) && ($specificPrefix || $specificAccount))
                ? 0.90
                : 0.70));
        return $this->fromRule(
            $supplierId,
            (string) $map['operation_type'],
            'detector',
            $confidence,
            (string) $map['rule_key'],
            (string) $map['label_cs'],
            null,
            $legacyFallback
                ? 'remittance_unclassified'
                : ($fallback ? 'remittance_unclassified' : null),
            !$legacyFallback && (bool) $map['auto_allowed'],
        );
    }

    /** @return array<string,mixed>|null */
    private function schedule(int $supplierId, string $vs, string $postedAt): ?array
    {
        $date = (new DateTimeImmutable($postedAt))->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, advance_kind, amount, due_date
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND status = 'planned' AND variable_symbol = ?
                AND due_date BETWEEN DATE_SUB(?, INTERVAL 31 DAY) AND DATE_ADD(?, INTERVAL 31 DAY)
              ORDER BY ABS(DATEDIFF(due_date, ?)), id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $vs, $date, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function supplierIdentifiers(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT dic, cssz_vsdp, health_insurance_number, taxpayer_type FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $supplier */
    private function vsType(string $vs, array $supplier): string
    {
        if ($vs === '') {
            return 'other';
        }
        $dic = preg_replace('/\D/', '', strtoupper((string) ($supplier['dic'] ?? ''))) ?? '';
        $isNaturalPerson = (string) ($supplier['taxpayer_type'] ?? 'fo') === 'fo';
        $cssz = $isNaturalPerson
            ? VariableSymbolNormalizer::forMatching((string) ($supplier['cssz_vsdp'] ?? ''))
            : '';
        $health = $isNaturalPerson
            ? VariableSymbolNormalizer::forMatching(
                (string) ($supplier['health_insurance_number'] ?? '')
            )
            : '';
        return match (true) {
            $dic !== '' && $vs === $dic => 'dic_kmen',
            $cssz !== '' && $vs === $cssz => 'cssz_vsdp',
            $health !== '' && $vs === $health => 'health_insurance_number',
            default => 'other',
        };
    }

    /** @return array<string,mixed>|null */
    private function map(string $vsType, string $taxpayerType, ?string $prefix, ?string $base): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT vs_type, taxpayer_type, account_prefix, account_number, operation_type, rule_key, auto_allowed, label_cs
               FROM remittance_map
              WHERE bank_code = '0710'
                AND (account_number = ? OR account_number IS NULL)
                AND (account_prefix = ? OR account_prefix IS NULL)
                AND (vs_type = ? OR vs_type = 'other' OR account_prefix = ? OR account_number = ?)
                AND (taxpayer_type = ? OR taxpayer_type = 'any')
              ORDER BY (account_number IS NOT NULL) DESC,
                       (account_prefix IS NOT NULL) DESC,
                       (vs_type <> 'other') DESC,
                       (taxpayer_type <> 'any') DESC,
                       id
              LIMIT 1"
        );
        $stmt->execute([$base, $prefix, $vsType, $prefix, $base, in_array($taxpayerType, ['fo', 'po'], true) ? $taxpayerType : 'fo']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function fromRule(
        int $supplierId,
        string $operation,
        string $source,
        float $confidence,
        string $ruleKey,
        ?string $description,
        ?int $scheduleId,
        ?string $note,
        bool $autoAllowed,
    ): ?DetectionResult {
        $rule = $this->postingRules->resolve($supplierId, $ruleKey);
        // Očekávaná MD strana per operace — pojistka proti překlepu v posting_rules (níže se jí
        // kontace ověří a případně přebije). Zaměstnavatelské pojistné jde na 336 stejně jako
        // OSVČ: liší se předpis, který úhradu kryje (524/336 + 331/336 vs. 526/336), ne účet úhrady.
        $expectedDebit = match ($operation) {
            OperationType::REMITTANCE_SOCIAL, OperationType::REMITTANCE_HEALTH,
            OperationType::REMITTANCE_SOCIAL_EMPLOYER, OperationType::REMITTANCE_HEALTH_EMPLOYER => '336',
            OperationType::REMITTANCE_INCOME, OperationType::REMITTANCE_FLAT => '341',
            OperationType::REMITTANCE_WITHHOLDING, OperationType::REMITTANCE_PAYROLL => '342',
            OperationType::REMITTANCE_VAT => '343',
            OperationType::REMITTANCE_PROPERTY, OperationType::REMITTANCE_ROAD => '345',
            OperationType::REMITTANCE_OTHER => '336',
            default => null,
        };
        if ($expectedDebit === null) {
            return null;
        }
        $debit = (string) ($rule['debit_account_code'] ?? '');
        $credit = (string) ($rule['credit_account_code'] ?? '');
        if (!str_starts_with($debit, $expectedDebit) || !str_starts_with($credit, '221')) {
            $debit = $expectedDebit;
            $credit = '221';
        }
        return new DetectionResult(
            $operation,
            $source,
            $confidence,
            $debit,
            $credit,
            $description,
            $scheduleId,
            $note,
            $autoAllowed,
            $this->key(),
        );
    }
}
