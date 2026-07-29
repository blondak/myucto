<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\PostingException;

final class BankRuleTemplateValidator
{
    private const PLACEHOLDERS = [
        '{cssz_vsdp}',
        '{health_insurance_number}',
        '{dic_kmen}',
    ];

    private const OPERATION_TYPES = [
        OperationType::REMITTANCE_SOCIAL,
        OperationType::REMITTANCE_HEALTH,
        OperationType::REMITTANCE_VAT,
        OperationType::REMITTANCE_INCOME,
        OperationType::REMITTANCE_WITHHOLDING,
        OperationType::REMITTANCE_PAYROLL,
        OperationType::REMITTANCE_PROPERTY,
        OperationType::REMITTANCE_ROAD,
        OperationType::REMITTANCE_FLAT,
        OperationType::REMITTANCE_OTHER,
        OperationType::BANK_INTEREST,
        OperationType::BANK_FEE,
        OperationType::BANK_RULE_CUSTOM,
    ];

    /** @return list<string> */
    public function operationTypes(): array
    {
        return self::OPERATION_TYPES;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $templateKey = $this->required($input, 'template_key', 64);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $templateKey) !== 1) {
            throw $this->error('invalid_template_key', 'Klíč šablony smí obsahovat jen malá písmena, číslice, tečku, pomlčku a podtržítko.');
        }

        $direction = $this->required($input, 'direction', 8);
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw $this->error('invalid_direction', 'Neplatný směr šablony.');
        }

        $operationType = $this->required($input, 'operation_type', 40);
        if (!in_array($operationType, self::OPERATION_TYPES, true)) {
            throw $this->error('invalid_operation_type', 'Neplatný typ operace.');
        }

        $ruleKey = $this->required($input, 'rule_key', 64);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $ruleKey) !== 1) {
            throw $this->error('invalid_rule_key', 'Neplatný klíč předkontace.');
        }

        $bank = $this->nullable($input['counterparty_bank'] ?? null, 10);
        if ($bank !== null && preg_match('/^\d{2,10}$/', $bank) !== 1) {
            throw $this->error('invalid_bank_code', 'Kód banky musí obsahovat 2 až 10 číslic.');
        }

        $prefix = $this->nullable($input['counterparty_prefix'] ?? null, 6);
        if ($prefix !== null) {
            if (preg_match('/^\d{1,6}$/', $prefix) !== 1) {
                throw $this->error('invalid_account_prefix', 'Předčíslí účtu musí obsahovat nejvýše 6 číslic.');
            }
            $prefix = ltrim($prefix, '0') ?: '0';
        }

        $placeholder = $this->nullable($input['vs_placeholder'] ?? null, 40);
        if ($placeholder !== null && !in_array($placeholder, self::PLACEHOLDERS, true)) {
            throw $this->error('invalid_placeholder', 'Neplatný zástupný identifikátor variabilního symbolu.');
        }

        $message = $this->nullable($input['message_contains'] ?? null, 120);
        if ($message !== null) {
            $message = BankMessageNormalizer::normalize($message) ?: null;
        }
        if ($bank === null && $prefix === null && $placeholder === null && $message === null) {
            throw $this->error('template_criteria_missing', 'Šablona musí mít alespoň jedno kritérium shody.');
        }

        return [
            'template_key' => $templateKey,
            'name_cs' => $this->required($input, 'name_cs', 120),
            'name_en' => $this->required($input, 'name_en', 120),
            'direction' => $direction,
            'operation_type' => $operationType,
            'counterparty_bank' => $bank,
            'counterparty_prefix' => $prefix,
            'vs_placeholder' => $placeholder,
            'message_contains' => $message,
            'rule_key' => $ruleKey,
            'default_priority' => $this->integer($input['default_priority'] ?? 100, 0, 999, 'invalid_priority', 'Priorita musí být v rozsahu 0 až 999.'),
            'sort_order' => $this->integer($input['sort_order'] ?? 0, 0, 65535, 'invalid_sort_order', 'Pořadí musí být v rozsahu 0 až 65535.'),
            'is_active' => $this->boolean($input['is_active'] ?? true),
        ];
    }

    /** @param array<string,mixed> $input */
    private function required(array $input, string $key, int $maxLength): string
    {
        $value = $this->nullable($input[$key] ?? null, $maxLength);
        if ($value === null) {
            throw $this->error('validation_failed', "Pole {$key} je povinné.");
        }
        return $value;
    }

    private function nullable(mixed $value, int $maxLength): ?string
    {
        if ($value === null) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        if (mb_strlen($text) > $maxLength) {
            throw $this->error('validation_failed', "Text smí mít nejvýše {$maxLength} znaků.");
        }
        return $text;
    }

    private function integer(mixed $value, int $min, int $max, string $code, string $message): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) throw $this->error($code, $message);
        $number = (int) $value;
        if ($number < $min || $number > $max) throw $this->error($code, $message);
        return $number;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) throw $this->error('validation_failed', 'Neplatná hodnota aktivního stavu.');
        return $parsed;
    }

    private function error(string $code, string $message): PostingException
    {
        return new PostingException($code, $message, 422);
    }
}
