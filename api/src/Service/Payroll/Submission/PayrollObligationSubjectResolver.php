<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollObligationSubjectRepository;

/**
 * `subject_label` a `subject_employee_id` pro řádky přehledu, inboxu a detailu
 * podání. Kde je předmětem povinnosti pracovní vztah, dohledá jméno osoby
 * a její id pro proklik na kartu zaměstnance (stejně jako fronta podání);
 * zbytek (účtárna, pojišťovna) nechá na {@see PayrollObligationSubjectFormatter}.
 *
 * Jména se čtou jedním dotazem pro celou stránku, ne po řádcích.
 */
final class PayrollObligationSubjectResolver
{
    public function __construct(
        private readonly PayrollObligationSubjectRepository $repository,
    ) {}

    /**
     * @param list<array<string,mixed>> $rows řádky s `agenda_code` a `subject_reference`
     * @return list<array{subject_label:?string,subject_employee_id:?int}> ve stejném pořadí
     */
    public function resolve(int $supplierId, array $rows): array
    {
        $employmentIds = [];
        foreach ($rows as $row) {
            $employmentId = PayrollObligationSubjectFormatter::employmentId(
                (string) ($row['subject_reference'] ?? ''),
            );
            if ($employmentId !== null) {
                $employmentIds[] = $employmentId;
            }
        }
        $people = $this->repository->employmentPeople($supplierId, $employmentIds);

        $result = [];
        foreach ($rows as $row) {
            $reference = (string) ($row['subject_reference'] ?? '');
            $employmentId = PayrollObligationSubjectFormatter::employmentId($reference);
            $person = $employmentId === null ? null : ($people[$employmentId] ?? null);
            $result[] = [
                'subject_label' => $person['full_name'] ?? PayrollObligationSubjectFormatter::humanSubject(
                    (string) ($row['agenda_code'] ?? ''),
                    $reference,
                ),
                'subject_employee_id' => $person['employee_id'] ?? null,
            ];
        }

        return $result;
    }
}
