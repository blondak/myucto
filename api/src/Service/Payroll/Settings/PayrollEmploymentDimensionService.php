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

    /**
     * Procentní rozpad vztahu (např. 70 / 30 na dvě střediska) od data.
     *
     * @param array<string,mixed> $input {dimension_type, valid_from, valid_to?,
     *        shares: list<{dimension_id, share_percent}>}
     * @return list<array<string,mixed>>
     */
    public function split(
        int $supplierId,
        int $employmentId,
        array $input,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma a pracovní vztah musí být určeny.');
        }
        $type = $input['dimension_type'] ?? null;
        if (!is_string($type) || !in_array($type, ['cost_center', 'project', 'activity'], true)) {
            throw new \InvalidArgumentException('Typ dimenze musí být cost_center, project nebo activity.');
        }
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }
        $rawShares = $input['shares'] ?? null;
        if (!is_array($rawShares) || !array_is_list($rawShares) || $rawShares === []) {
            throw new \InvalidArgumentException('Rozpad musí obsahovat aspoň jednu dimenzi s podílem.');
        }
        $shares = [];
        $total = 0;
        foreach ($rawShares as $index => $share) {
            if (!is_array($share)) {
                throw new \InvalidArgumentException("Řádek rozpadu {$index} není objekt.");
            }
            $basisPoints = self::basisPoints($share['share_percent'] ?? null);
            $total += $basisPoints;
            $shares[] = [
                'dimension_id' => $this->positiveInt($share, 'dimension_id'),
                'share_bp' => $basisPoints,
            ];
        }
        if ($total !== 10_000) {
            throw new \InvalidArgumentException(
                sprintf('Podíly rozpadu dávají %s %%, musí dát přesně 100 %%.', number_format($total / 100, 2, ',', ' ')),
            );
        }

        return $this->repository->saveSplit(
            $supplierId,
            $employmentId,
            $type,
            $validFrom,
            $validTo,
            $shares,
            $actorUserId,
        );
    }

    /** Podíl v procentech (nejvýš dvě desetinná místa) → setiny procenta. */
    private static function basisPoints(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }
        if (!is_string($value)
            || preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', trim($value), $match) !== 1
        ) {
            throw new \InvalidArgumentException('Podíl musí být procento s nejvýš dvěma desetinnými místy.');
        }
        $basisPoints = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
        if ($basisPoints <= 0 || $basisPoints > 10_000) {
            throw new \InvalidArgumentException('Podíl musí být větší než 0 % a nejvýš 100 %.');
        }

        return $basisPoints;
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
