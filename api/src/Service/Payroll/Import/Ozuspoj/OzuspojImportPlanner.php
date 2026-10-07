<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Ozuspoj;

use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportPlanner;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;

/**
 * Náhled převzetí OZUSPOJ23 předchozího programu v importu registrací.
 *
 * Zápis dělá {@see OzuspojPredecessorIntentImporter}; tahle třída jen spočítá,
 * co by zapsal, aniž by cokoli změnila. Den doručení oznámení soubor nenese
 * (patří do protokolu ČSSZ), proto ho zadává účetní u věty a bez něj věta
 * zůstává nevybratelná - nárok na slevu stojí na dni doručení a nikdy se
 * nedosazuje.
 */
final class OzuspojImportPlanner
{
    public function __construct(
        private readonly OzuspojPredecessorIntentImporter $importer,
        private readonly PayrollDiscountIntentRepository $intents,
        private readonly RegistrationImportLookup $lookup,
    ) {}

    /** @return array<string,mixed> */
    public function plan(
        int $supplierId,
        string $environment,
        OzuspojPredecessorFile $file,
        string $fileName,
        int $position,
        ?string $receivedOn,
    ): array {
        $payload = $file->payload;
        $key = RegistrationImportPlanner::key($file->sha256, $position);
        $birthNumber = $payload->employeeBirthNumber;
        $normalized = null;
        if ($birthNumber !== null) {
            try {
                $normalized = CzechBirthNumber::normalize($birthNumber);
            } catch (\InvalidArgumentException) {
                $normalized = null;
            }
        }
        $label = match ($payload->kind) {
            OzuspojSubmissionKind::Start => 'Oznámení záměru uplatňovat slevu na pojistném (OZUSPOJ) - zahájení',
            OzuspojSubmissionKind::End => 'Oznámení záměru uplatňovat slevu na pojistném (OZUSPOJ) - skončení',
            OzuspojSubmissionKind::Cancellation => 'Oznámení záměru uplatňovat slevu na pojistném (OZUSPOJ) - storno',
        };
        $plan = [
            'key' => $key,
            'file' => $fileName,
            'sequence' => 1,
            'document_type' => 'OZUSPOJ23',
            'action_code' => 0,
            'action_label' => $label,
            'prepared_on' => null,
            'effective_on' => $payload->intentFrom ?? $payload->intentTo,
            'person' => [
                'full_name' => trim($payload->employeeFirstName . ' ' . $payload->employeeLastName),
                'first_name' => $payload->employeeFirstName,
                'last_name' => $payload->employeeLastName,
                'birth_date' => $payload->employeeBirthDate,
                'birth_number_masked' => $this->masked($normalized ?? $birthNumber),
                'has_oic' => false,
            ],
            'employment' => [
                'start_on' => null,
                'end_on' => null,
                'activity_code' => null,
                'relation_type' => null,
                'relation_type_options' => [],
                'start_estimated' => false,
                'position_name' => null,
                'has_id_ppv' => false,
            ],
            'match' => [
                'status' => 'not_found',
                'matched_by' => null,
                'employee_id' => null,
                'employee_name' => null,
                'employment_id' => null,
                'employment_code' => null,
                'candidates' => [],
            ],
            'operation' => 'none',
            'changes' => [],
            'warnings' => [],
            'blocker' => null,
            'selectable' => false,
            'termination_offer' => null,
            'benefit' => [
                'document' => 'OZUSPOJ',
                'benefit_kind' => null,
                'decision_number' => null,
                'incapacity_from' => $payload->intentFrom,
                'incapacity_to' => $payload->intentTo,
                'case_id' => null,
                'case_source' => null,
                'received_on' => $receivedOn,
                'needs_received_on' => false,
            ],
            '_ozuspoj_file' => $file,
            '_supplier_id' => $supplierId,
            '_employee_id' => null,
            '_employment_id' => null,
            '_received_on' => $receivedOn,
        ];

        if ($payload->kind === OzuspojSubmissionKind::Cancellation) {
            $plan['operation'] = 'unsupported';

            return $this->finish($plan, 'Storno záměru se z předchozího programu nepřebírá. Stornovaný záměr nepřebírejte; '
                . 'převzatý záměr zrušte zápisem výsledku „zrušeno“.');
        }

        try {
            $located = $this->importer->locate($supplierId, $payload);
        } catch (OzuspojException $exception) {
            return $this->finish($plan, $exception->getMessage());
        }
        $plan['warnings'] = $located['warnings'];
        $plan['_employee_id'] = $located['employee_id'];
        $plan['_employment_id'] = $located['employment_id'];
        $plan['match'] = array_merge($plan['match'], [
            'status' => 'matched',
            'matched_by' => $normalized !== null ? 'birth_number' : 'name_birth_date',
            'employee_id' => $located['employee_id'],
            'employee_name' => $this->lookup->employeeName($supplierId, $located['employee_id']),
            'employment_id' => $located['employment_id'],
        ]);
        $employment = $this->lookup->employment($supplierId, $located['employment_id']);
        if ($employment !== null) {
            $plan['match']['employment_code'] = $employment['code'];
            $plan['employment']['start_on'] = $employment['actual_start_date'] ?? $employment['start_date'];
            $plan['employment']['end_on'] = $employment['end_date'];
            $plan['employment']['relation_type'] = $employment['relation_type'];
        }

        if ($payload->kind === OzuspojSubmissionKind::Start) {
            $existing = $this->intents->findByScope(
                $supplierId,
                $environment,
                $located['employment_id'],
                (string) $payload->intentFrom,
            );
            if ($existing !== null) {
                if (($existing['predecessor_source'] ?? null) === OzuspojPredecessorIntentImporter::SOURCE) {
                    $plan['warnings'][] = 'Záměr z tohoto oznámení už v evidenci je, import ho nezapisuje podruhé.';

                    return $this->finish($plan, null);
                }

                return $this->finish($plan, 'K tomuto pracovnímu vztahu je od ' . $payload->intentFrom . ' už evidovaný jiný záměr. '
                    . 'Převzetí by ho přepsalo; ověřte, který z nich ČSSZ skutečně přijala.');
            }
            $this->change($plan, 'intent_from', 'Záměr platí od', null, $payload->intentFrom);
            $this->change($plan, 'ossz_code', 'Kód OSSZ', null, (string) $payload->osszCode);
        } else {
            $covering = $this->intents->acceptedCovering(
                $supplierId,
                $environment,
                $located['employment_id'],
                (string) $payload->intentTo,
            );
            if (count($covering) !== 1) {
                $ended = array_values(array_filter(
                    $this->intents->listForSupplier($supplierId, $environment, $located['employment_id']),
                    static fn (array $row): bool => $row['status'] === 'ended' && (string) $row['intent_to'] === $payload->intentTo,
                ));
                if ($ended !== []) {
                    $plan['warnings'][] = 'Skončení záměru k tomuto dni je v evidenci už zapsané, import ho nezapisuje podruhé.';

                    return $this->finish($plan, null);
                }

                return $this->finish($plan, $covering === []
                    ? 'K pracovnímu vztahu není ke dni ' . $payload->intentTo . ' žádný přijatý záměr, který by oznámení o skončení uzavřelo. '
                        . 'Převezměte nejdřív oznámení o zahájení.'
                    : 'K pracovnímu vztahu je ke dni ' . $payload->intentTo . ' víc přijatých záměrů; oznámení o skončení nejde jednoznačně přiřadit.');
            }
            $this->change($plan, 'intent_to', 'Záměr končí', (string) ($covering[0]['intent_to'] ?? ''), $payload->intentTo);
        }

        $plan['operation'] = 'import_intent';
        if ($receivedOn === null) {
            $plan['benefit']['needs_received_on'] = true;

            return $this->finish($plan, 'Zadejte den doručení oznámení podle protokolu ČSSZ. Soubor ho nenese a nárok na slevu '
                . 'stojí právě na něm, proto se nedosazuje.');
        }
        $this->change($plan, 'accepted_on', 'Den doručení ČSSZ', null, $receivedOn);

        return $this->finish($plan, null);
    }

    private function masked(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $value);

        return strlen($digits) >= 6 ? substr($digits, 0, 6) . '/****' : '****';
    }

    /** @param array<string,mixed> $plan */
    private function change(array &$plan, string $field, string $label, ?string $current, ?string $imported): void
    {
        if ($imported === null) {
            return;
        }
        $plan['changes'][] = ['field' => $field, 'label' => $label, 'current' => $current, 'imported' => $imported];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function finish(array $plan, ?string $blocker): array
    {
        $plan['blocker'] = $blocker;
        $plan['selectable'] = $blocker === null && $plan['operation'] === 'import_intent';

        return $plan;
    }
}
