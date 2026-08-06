<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

use MyInvoice\Repository\Payroll\PayrollEmploymentDimensionRepository;

final class PayrollEmploymentDimensionService
{
    public function __construct(
        private readonly PayrollEmploymentDimensionRepository $repository,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        int $employmentId,
        array $input,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma a pracovní vztah musí být určeny.');
        }
        $dimensionId = $this->positiveInt($input, 'dimension_id');
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }

        return $this->repository->create(
            $supplierId,
            $employmentId,
            $dimensionId,
            $validFrom,
            $validTo,
            $actorUserId,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(
        int $supplierId,
        int $employmentId,
        int $id,
        array $input,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0 || $id <= 0) {
            throw new \InvalidArgumentException('Firma, pracovní vztah a přiřazení musí být určeny.');
        }
        $dimensionId = $this->positiveInt($input, 'dimension_id');
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }
        $expectedVersion = $this->positiveInt($input, 'row_version');

        return $this->repository->update(
            $supplierId,
            $id,
            $employmentId,
            $dimensionId,
            $validFrom,
            $validTo,
            $expectedVersion,
            $actorUserId,
        ) ?? throw new \RuntimeException('Přiřazení dimenze nebylo nalezeno.');
    }

    /** @param array<string,mixed> $input */
    private function positiveInt(array $input, string $field): int
    {
        $value = filter_var(
            $input[$field] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($value === false) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné celé číslo.");
        }

        return $value;
    }

    private function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $field);
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum YYYY-MM-DD.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum YYYY-MM-DD.");
        }

        return $value;
    }
}
