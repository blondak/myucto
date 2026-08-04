<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;

/** @phpstan-import-type TermsInput from PayrollEmploymentValidator */
final class PayrollPersonQuickEditService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array{profile:array<string,mixed>,employment:?array<string,mixed>}
     */
    public function save(
        int $supplierId,
        int $employeeId,
        array $input,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $profileInput = $this->object($input['profile'] ?? null, 'profile');
        $profileVersion = $this->version($profileInput['row_version'] ?? null, true);
        $normalizedProfile = $this->profileValidator->validate($profileInput);

        $employmentInput = null;
        if (($input['employment'] ?? null) !== null) {
            $employmentInput = $this->object($input['employment'], 'employment');
        }
        $normalizedEmployment = $employmentInput === null
            ? null
            : $this->employment($employmentInput);

        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $normalizedProfile,
            $profileVersion,
            $normalizedEmployment,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $profile = $this->profiles->save(
                $supplierId,
                $employeeId,
                $normalizedProfile,
                $profileVersion,
                $userId,
                $ip,
                $userAgent,
            );

            if ($normalizedEmployment === null) {
                return [
                    'profile' => $profile,
                    'employment' => $this->primaryEmployment($supplierId, $employeeId),
                ];
            }

            $owned = false;
            foreach ($this->employments->listForEmployee($supplierId, $employeeId) as $employment) {
                if ($this->requiredInt($employment, 'id') === $normalizedEmployment['id']) {
                    $owned = true;
                    break;
                }
            }
            if (!$owned) {
                throw new PayrollEmploymentNotFoundException(
                    'Primární pracovní vztah zaměstnance nebyl nalezen.',
                );
            }

            $employment = $this->employments->addTerms(
                $supplierId,
                $normalizedEmployment['id'],
                $normalizedEmployment['terms'],
                $normalizedEmployment['row_version'],
                $userId,
                $ip,
                $userAgent,
                true,
                $normalizedEmployment['monthly_gross_minor'],
            );

            return ['profile' => $profile, 'employment' => $employment];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   id:int,
     *   row_version:int,
     *   monthly_gross_minor:?int,
     *   terms:TermsInput
     * }
     */
    private function employment(array $input): array
    {
        $id = $this->version($input['id'] ?? null, false);
        $rowVersion = $this->version($input['row_version'] ?? null, false);
        if (!array_key_exists('monthly_gross_minor', $input)) {
            throw new \InvalidArgumentException('employment.monthly_gross_minor je povinné.');
        }
        $monthlyGross = $input['monthly_gross_minor'];
        if ($monthlyGross !== null && (!is_int($monthlyGross) || $monthlyGross < 0)) {
            throw new \InvalidArgumentException(
                'Pravidelná hrubá mzda musí být nezáporná částka v haléřích.',
            );
        }
        $terms = $this->employmentValidator->terms(
            $this->object($input['terms'] ?? null, 'employment.terms'),
        );

        return [
            'id' => $id,
            'row_version' => $rowVersion,
            'monthly_gross_minor' => $monthlyGross,
            'terms' => $terms,
        ];
    }

    /** @return ?array<string,mixed> */
    private function primaryEmployment(int $supplierId, int $employeeId): ?array
    {
        $fallback = null;
        foreach ($this->employments->listForEmployee($supplierId, $employeeId) as $employment) {
            if ($this->requiredBool($employment, 'is_primary') === false) {
                continue;
            }
            $fallback ??= $employment;
            if (in_array(
                $this->requiredString($employment, 'status'),
                ['planned', 'preregistered', 'active', 'suspended'],
                true,
            )) {
                return $employment;
            }
        }

        return $fallback;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("{$path} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException("{$path} musí být objekt.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function version(mixed $value, bool $allowZero): int
    {
        $minimum = $allowZero ? 0 : 1;
        if (!is_int($value) || $value < $minimum) {
            $kind = $allowZero ? 'nezáporné' : 'kladné';
            throw new \InvalidArgumentException(
                "row_version a identifikátory musí být {$kind} celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("Pracovní vztah neobsahuje platné pole {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredBool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException("Pracovní vztah neobsahuje platné pole {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pracovní vztah neobsahuje platné pole {$key}.");
        }

        return $value;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_person_quick_edit');
        }

        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_person_quick_edit');
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_person_quick_edit');
                $pdo->exec('RELEASE SAVEPOINT payroll_person_quick_edit');
            }
            throw $e;
        }
    }
}
