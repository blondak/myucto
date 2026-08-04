<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use JsonSerializable;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class GarnishableIncomeResult implements JsonSerializable
{
    /**
     * @param list<string> $issues
     * @param list<array{id:string,kind:string,amount_minor_units:int,payer_id:string,treatment:string}> $trace
     */
    public function __construct(
        public GarnishmentStatus $status,
        public int $garnishableMinorUnits,
        public int $excludedMinorUnits,
        public array $issues,
        public array $trace,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'excluded_minor_units' => $this->excludedMinorUnits,
            'garnishable_minor_units' => $this->garnishableMinorUnits,
            'issues' => $this->issues,
            'status' => $this->status->value,
            'trace' => $this->trace,
        ];
    }

    public function toCanonicalJson(): string
    {
        return CanonicalJson::encode($this->jsonSerialize());
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        $issues = $data['issues'] ?? null;
        $trace = $data['trace'] ?? null;
        if (!is_array($issues) || !array_is_list($issues)
            || !is_array($trace) || !array_is_list($trace)
        ) {
            throw new \InvalidArgumentException('Garnishable income snapshot is invalid.');
        }
        foreach ($issues as $issue) {
            if (!is_string($issue)) {
                throw new \InvalidArgumentException(
                    'Garnishable income issue must be a string.',
                );
            }
        }
        $validatedTrace = [];
        foreach ($trace as $row) {
            $row = self::row($row, 'trace');
            $validatedTrace[] = [
                'id' => self::string($row, 'id'),
                'kind' => self::string($row, 'kind'),
                'amount_minor_units' => self::int(
                    $row,
                    'amount_minor_units',
                ),
                'payer_id' => self::string($row, 'payer_id'),
                'treatment' => self::string($row, 'treatment'),
            ];
        }

        return new self(
            GarnishmentStatus::from(self::string($data, 'status')),
            self::int($data, 'garnishable_minor_units'),
            self::int($data, 'excluded_minor_units'),
            $issues,
            $validatedTrace,
        );
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("{$field} must be an object.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    "{$field} must use string keys.",
                );
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$key} must be a string.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException("{$key} must be an integer.");
        }
        return $value;
    }
}
