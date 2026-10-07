<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Sickness;

use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;

/**
 * Zápis plánu z {@see SicknessImportPlanner}: založí případ dávky z podání
 * předchozího programu, nebo do existujícího případu zapíše, že podání
 * vyřídil předchozí program.
 *
 * Vyřízení jde výhradně přes {@see SicknessCaseService} (založení se stavem
 * `predecessor`, `recordReceipt`), takže platí tytéž stráže jako u ručního
 * zápisu: přijaté podání z MyÚčta se nepřepíše, případ vedený v MyÚčtu
 * předchozí program nevyřídí a údaje vyřízeného HZUPN se po zápisu nemění.
 */
final class SicknessImportWriter
{
    public function __construct(
        private readonly SicknessCaseService $caseService,
        private readonly PayrollSicknessCaseRepository $cases,
    ) {}

    /**
     * @param array<string,mixed> $plan
     * @return array{status:string,message:?string,employee_id:?int,employment_id:?int,operations:list<string>,case_id?:int}
     */
    public function apply(int $supplierId, string $environment, array $plan, ?int $userId): array
    {
        /** @var SicknessImportRecord $record */
        $record = $plan['_record'];
        $steps = $plan['_steps'];
        $base = [
            'employee_id' => $plan['_employee_id'],
            'employment_id' => $plan['_employment_id'],
        ];
        $receivedOn = is_string($plan['_received_on'] ?? null) ? $plan['_received_on'] : null;

        try {
            if (is_array($steps['create'] ?? null)) {
                if ($userId === null || $userId <= 0) {
                    return ['status' => 'failed', 'message' => 'Případ dávky se zakládá jménem přihlášené účetní.', 'operations' => []] + $base;
                }

                return $this->create($supplierId, $environment, $plan, $steps['create'], $record, $receivedOn, $userId) + $base;
            }
            if (is_array($steps['update'] ?? null)) {
                return $this->update($supplierId, $environment, $plan, $steps['update'], $record, $receivedOn) + $base;
            }
        } catch (SicknessException $exception) {
            return ['status' => 'failed', 'message' => $exception->getMessage(), 'operations' => []] + $base;
        }

        return ['status' => 'skipped', 'message' => 'Věta nemá co zapsat - evidence už odpovídá.', 'operations' => []] + $base;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     * @return array{status:string,message:?string,operations:list<string>,case_id:int}
     */
    private function create(
        int $supplierId,
        string $environment,
        array $plan,
        array $step,
        SicknessImportRecord $record,
        ?string $receivedOn,
        int $userId,
    ): array {
        $document = $record->document;
        $system = [
            $document->statusColumn() => 'predecessor',
            'source' => SicknessCaseService::SOURCE_PREDECESSOR,
            'external_reference' => SicknessImportPlanner::mergeReference(null, $document, (string) $step['reference']),
        ];
        if ($receivedOn !== null) {
            $system[$document->acceptedOnColumn()] = $receivedOn;
        }
        $caseId = $this->cases->transaction(function () use ($supplierId, $environment, $plan, $step, $system, $userId): int {
            $created = $this->caseService->create(
                $supplierId,
                $environment,
                (int) $plan['_employment_id'],
                (string) $step['kind'],
                $step['input'],
                $userId,
                $system,
            );
            if (is_int($step['absence_id'] ?? null)) {
                $this->cases->update(
                    $supplierId,
                    $environment,
                    (int) $created['id'],
                    (int) $created['row_version'],
                    ['absence_id' => $step['absence_id']],
                );
            }

            return (int) $created['id'];
        });

        return [
            'status' => 'applied',
            'message' => null,
            'operations' => ['case_created', strtolower($document->agendaCode()) . '_predecessor'],
            'case_id' => $caseId,
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     * @return array{status:string,message:?string,operations:list<string>,case_id:int}
     */
    private function update(
        int $supplierId,
        string $environment,
        array $plan,
        array $step,
        SicknessImportRecord $record,
        ?string $receivedOn,
    ): array {
        $caseId = (int) $plan['_case_id'];
        $document = $record->document;
        $operations = [];
        $this->cases->transaction(function () use (
            $supplierId,
            $environment,
            $caseId,
            $step,
            $document,
            $receivedOn,
            &$operations,
        ): void {
            $row = $this->caseService->requireCase($supplierId, $environment, $caseId);
            $fields = is_array($step['fields'] ?? null) ? $step['fields'] : [];
            $workDays = $step['work_days'] ?? null;
            if ($fields !== [] || is_array($workDays)) {
                $input = $fields;
                if (is_array($workDays)) {
                    $input['work_days'] = $workDays;
                }
                $row = $this->caseService->update($supplierId, $environment, $caseId, (int) $row['row_version'], $input);
                $operations[] = 'case_updated';
            }
            if (($step['mark'] ?? false) === true) {
                $row = $this->caseService->recordReceipt(
                    $supplierId,
                    $environment,
                    $caseId,
                    $document,
                    'predecessor',
                    $receivedOn,
                    null,
                );
                $operations[] = strtolower($document->agendaCode()) . '_predecessor';
            }
            $this->cases->update(
                $supplierId,
                $environment,
                $caseId,
                (int) $row['row_version'],
                ['external_reference' => SicknessImportPlanner::mergeReference(
                    is_string($row['external_reference'] ?? null) ? $row['external_reference'] : null,
                    $document,
                    (string) $step['reference'],
                )],
            );
        });

        return ['status' => 'applied', 'message' => null, 'operations' => $operations, 'case_id' => $caseId];
    }
}
