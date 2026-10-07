<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Sickness;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportPlanner;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriCodebook;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentStatus;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;

/**
 * Ze věty NEMPRI nebo HZUPN předchozího programu a stavu evidence spočítá, co
 * by import udělal. Nic nezapisuje; zapisuje {@see SicknessImportWriter}, který
 * si plán počítá znovu těsně před zápisem.
 *
 * ## Co import je
 *
 * Podání už odeslal předchozí program a ČSSZ ho přijala. Import tedy nic
 * neodesílá, jen do evidence zapíše, že NEMPRI či HZUPN k události už vyřídil
 * předchozí program (stav podání `predecessor`), aby je hlídač lhůt nepožadoval
 * podruhé. Případ, který z cizího podání vzniká, nese `source = predecessor`.
 * Druhé podání téhož případu (typicky HZUPN k rozběhnuté neschopnosti) zůstává
 * otevřené a hlídač ho dál drží.
 *
 * ## Párování
 *
 * Konzervativní jako u registrací: osoba podle rodného čísla (u cizince EČP)
 * přes slepý index, u HZUPN bez rodného čísla podle jména a data narození;
 * vztah podle doby události a dne nástupu. Víc kandidátů znamená blokaci.
 * Případ se hledá podle čísla rozhodnutí, jinak podle dne vzniku nebo konce
 * neschopnosti, a u nemocenského NEMPRI (které den vzniku nenese) podle
 * schválené nepřítomnosti v měsíci události. Co se nenajde, se nedomýšlí.
 *
 * ## Idempotence
 *
 * Případ drží odkaz na převzatý dokument (`NEMPRI|HZUPN <otisk souboru>:<věta>`
 * v `external_reference`) a stav podání. Opakovaný import téhož souboru, nebo
 * podání, které už je v evidenci vyřízené, nic nezapíše.
 */
final class SicknessImportPlanner
{
    private const KIND_LABELS = [
        'NEM' => 'nemocenské',
        'VPM' => 'vyrovnávací příspěvek v těhotenství a mateřství',
        'OPP' => 'otcovská poporodní péče',
        'PPM' => 'peněžitá pomoc v mateřství',
        'OSE' => 'ošetřovné',
        'DLO' => 'dlouhodobé ošetřovné',
    ];

    /** Druhy absencí, ze kterých plyne nemocenské. */
    private const NEM_ABSENCES = ['dpn', 'quarantine'];

    /** Sloupce, které patří HZUPN a po jeho vyřízení se zamykají. */
    private const HZUPN_COLUMNS = [
        'returned_to_work' => 'Návrat do práce',
        'return_reason' => 'Důvod nenávratu do práce',
        'returned_on' => 'Datum návratu do práce',
        'hours_worked_last_day' => 'Odpracované hodiny poslední den',
        'shift_hours_last_day' => 'Pracovní doba poslední den',
        'issued_on' => 'Datum vystavení hlášení',
        'incapacity_to' => 'Poslední den neschopnosti',
    ];

    public function __construct(
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollSicknessCaseRepository $cases,
        private readonly PayrollAbsenceRepository $absences,
        private readonly SicknessCaseService $caseService,
    ) {}

    /** Odkaz na převzatý dokument do `external_reference` případu. */
    public static function reference(SicknessDocumentKind $document, string $key): string
    {
        return $document->agendaCode() . ' ' . $key;
    }

    /** Je odkaz na dokument v `external_reference` případu? */
    public static function hasReference(?string $existing, SicknessDocumentKind $document, string $key): bool
    {
        return in_array(self::reference($document, $key), self::references($existing), true);
    }

    /** Připojí odkaz za stávající (nejvýš 190 znaků; nejstarší se zahodí). */
    public static function mergeReference(?string $existing, SicknessDocumentKind $document, string $key): string
    {
        $parts = self::references($existing);
        $new = self::reference($document, $key);
        if (!in_array($new, $parts, true)) {
            $parts[] = $new;
        }
        while ($parts !== [] && mb_strlen(implode('; ', $parts)) > 190) {
            array_shift($parts);
        }

        return implode('; ', $parts);
    }

    /** @return list<string> */
    private static function references(?string $existing): array
    {
        if ($existing === null || trim($existing) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(';', $existing)),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /** @return array<string,mixed> */
    public function plan(
        int $supplierId,
        string $environment,
        SicknessImportRecord $record,
        string $fileName,
        string $fileSha256,
        ?string $receivedOn = null,
    ): array {
        $key = RegistrationImportPlanner::key($fileSha256, $record->position);
        [$birthNumber, $ecp] = $this->birthNumber($record);
        $kindLabel = self::KIND_LABELS[$record->kind->value] ?? $record->kind->value;
        $plan = [
            'key' => $key,
            'file' => $fileName,
            'sequence' => $record->sequence,
            'document_type' => $record->documentType,
            'action_code' => 0,
            'action_label' => $record->isHzupn()
                ? 'Hlášení při ukončení pracovní neschopnosti (HZUPN)'
                : 'Oznámení o žádosti o dávku (NEMPRI) - ' . $kindLabel,
            'prepared_on' => $record->issuedOn,
            'effective_on' => $record->incapacityFrom ?? $record->returnedOn ?? $record->eventMonthStart(),
            'person' => [
                'full_name' => $record->fullName() ?? 'Neuvedené jméno',
                'first_name' => $record->firstName,
                'last_name' => $record->lastName,
                'birth_date' => $record->birthDate ?? ($birthNumber === null ? null : CzechBirthNumber::birthDate($birthNumber)),
                'birth_number_masked' => $this->masked($birthNumber ?? $ecp),
                'has_oic' => false,
            ],
            'employment' => [
                'start_on' => $record->employmentFrom,
                'end_on' => $record->employmentTo,
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
            'warnings' => $record->notes,
            'blocker' => null,
            'selectable' => false,
            'termination_offer' => null,
            'benefit' => [
                'document' => $record->document->agendaCode(),
                'benefit_kind' => $record->kind->value,
                'decision_number' => $record->decisionNumber,
                'incapacity_from' => $record->incapacityFrom,
                'incapacity_to' => $record->incapacityTo,
                'case_id' => null,
                'case_source' => null,
                'received_on' => $receivedOn,
                'needs_received_on' => false,
            ],
            '_record' => $record,
            '_file_sha256' => $fileSha256,
            '_supplier_id' => $supplierId,
            '_employee_id' => null,
            '_employment_id' => null,
            '_case_id' => null,
            '_received_on' => $receivedOn,
            '_steps' => ['create' => null, 'update' => null],
        ];

        if ($record->personReport) {
            $plan['operation'] = 'unsupported';

            return $this->finish($plan, 'Hlášení podává sama osoba dobrovolně nemocensky pojištěná, ne zaměstnavatel. '
                . 'Takové hlášení se do evidence zaměstnavatele nepřebírá.');
        }
        if ($record->isHzupn() && $record->kind !== SicknessBenefitKind::Nem) {
            $plan['operation'] = 'unsupported';

            return $this->finish($plan, 'HZUPN se podává jen u nemocenského.');
        }
        $foreign = $this->foreignEmployerBlocker($supplierId, $record, $plan['warnings']);
        if ($foreign !== null) {
            return $this->finish($plan, $foreign);
        }
        if ($receivedOn !== null && $receivedOn > date('Y-m-d')) {
            return $this->finish($plan, 'Den doručení podání nemůže být v budoucnosti.');
        }

        $person = $this->matchPerson($supplierId, $record, $birthNumber, $ecp);
        if ($person['blocker'] !== null) {
            $plan['match'] = array_merge($plan['match'], [
                'status' => $person['candidates'] === [] ? 'not_found' : 'ambiguous',
                'candidates' => $person['candidates'],
            ]);

            return $this->finish($plan, $person['blocker']);
        }
        $employeeId = (int) $person['employee_id'];
        $plan['_employee_id'] = $employeeId;
        $plan['match'] = array_merge($plan['match'], [
            'status' => 'matched',
            'matched_by' => $person['matched_by'],
            'employee_id' => $employeeId,
            'employee_name' => $this->lookup->employeeName($supplierId, $employeeId),
        ]);

        $employment = $this->matchEmployment($supplierId, $employeeId, $record);
        if ($employment['blocker'] !== null) {
            $plan['match']['status'] = $employment['candidates'] === [] ? 'matched' : 'ambiguous';
            $plan['match']['candidates'] = $employment['candidates'];

            return $this->finish($plan, $employment['blocker']);
        }
        $row = $employment['row'];
        $employmentId = (int) $row['id'];
        $plan['_employment_id'] = $employmentId;
        $plan['match']['employment_id'] = $employmentId;
        $plan['match']['employment_code'] = $row['code'];
        $plan['employment']['start_on'] = $row['actual_start_date'] ?? $row['start_date'];
        $plan['employment']['end_on'] = $row['end_date'];
        $plan['employment']['relation_type'] = $row['relation_type'];

        $icBlocker = $this->foreignBusinessIdBlocker($supplierId, $employmentId, $record);
        if ($icBlocker !== null) {
            return $this->finish($plan, $icBlocker);
        }

        $found = $this->findCase($supplierId, $environment, $employmentId, $record);
        if ($found['blocker'] !== null) {
            return $this->finish($plan, $found['blocker']);
        }
        if ($found['case'] !== null) {
            return $this->planExisting($plan, $record, $found['case'], $receivedOn, $key);
        }

        return $this->planCreate($supplierId, $plan, $record, $found, $receivedOn, $key);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function planExisting(array $plan, SicknessImportRecord $record, array $case, ?string $receivedOn, string $key): array
    {
        $document = $record->document;
        $status = SicknessCaseService::documentStatus($case, $document);
        $plan['_case_id'] = (int) $case['id'];
        $plan['benefit']['case_id'] = (int) $case['id'];
        $plan['benefit']['case_source'] = (string) ($case['source'] ?? 'myucto');
        $plan['benefit']['incapacity_from'] = (string) $case['incapacity_from'];
        $plan['benefit']['incapacity_to'] = $case['incapacity_to'] === null ? null : (string) $case['incapacity_to'];
        $label = $document->agendaCode();

        if (self::hasReference($case['external_reference'] ?? null, $document, $key)) {
            $plan['warnings'][] = "{$label} z tohoto souboru už v případu je, import ho nezapisuje podruhé.";

            return $this->finish($plan, null);
        }
        if ($status === SicknessDocumentStatus::Accepted) {
            $plan['warnings'][] = "{$label} už je u případu zapsané jako přijaté z MyÚčta, podání předchozího programu "
                . 'se k němu nepřebírá.';

            return $this->finish($plan, null);
        }

        $fields = [];
        $workDays = null;
        $unsettled = !$status->isSettled();
        if ($record->decisionNumber !== null) {
            $existing = $case['decision_number'] ?? null;
            if ($existing === null || $existing === '') {
                $fields['decision_number'] = $record->decisionNumber;
                $this->change($plan, 'decision_number', 'Číslo rozhodnutí', null, $record->decisionNumber);
            } elseif ($existing !== $record->decisionNumber) {
                $plan['warnings'][] = "Číslo rozhodnutí v případu ({$existing}) se liší od podání ({$record->decisionNumber}). Případ se nemění.";
            }
        }
        if ($record->isHzupn() && $unsettled) {
            foreach (self::HZUPN_COLUMNS as $column => $label2) {
                $value = $record->caseFields[$column] ?? ($column === 'incapacity_to' ? $record->incapacityTo : null);
                if ($value === null) {
                    continue;
                }
                $existing = $case[$column] ?? null;
                if ($existing !== null && $this->same($column, $existing, $value)) {
                    continue;
                }
                $fields[$column] = $value;
                $this->change($plan, $column, $label2, $existing === null ? null : $this->display($existing), $this->display($value));
                if ($column === 'incapacity_to' && $existing !== null) {
                    $plan['warnings'][] = "Poslední den neschopnosti v případu ({$existing}) se liší od hlášení ({$value}); "
                        . 'případ se přizpůsobí podání, zkontrolujte nepřítomnosti.';
                }
            }
            if ($record->workDays !== [] && ($case['work_days'] ?? []) !== $record->workDays) {
                $workDays = $record->workDays;
                $this->change($plan, 'work_days', 'Dny práce v době neschopnosti', null, (string) count($record->workDays) . ' interval(y)');
            }
            $end = $fields['incapacity_to'] ?? $case['incapacity_to'];
            if ($end !== null && (string) $end < (string) $case['incapacity_from']) {
                return $this->finish($plan, "Poslední den neschopnosti z hlášení ({$end}) předchází dni vzniku případu "
                    . "({$case['incapacity_from']}). Hlášení patrně patří k jiné události, přiřaďte ho ručně.");
            }
            if ((int) ($fields['returned_to_work'] ?? $case['returned_to_work'] ?? 0) === 1
                && ($fields['returned_on'] ?? $case['returned_on'] ?? null) === null
            ) {
                unset($fields['returned_to_work']);
                $plan['changes'] = array_values(array_filter(
                    $plan['changes'],
                    static fn (array $change): bool => $change['field'] !== 'returned_to_work',
                ));
                $plan['warnings'][] = 'Hlášení uvádí návrat do práce bez data návratu; návrat se do případu nepřevzal.';
            }
        }

        $mark = $status === SicknessDocumentStatus::Pending || $status === SicknessDocumentStatus::Rejected;
        if ($mark) {
            if (($case['source'] ?? 'myucto') !== SicknessCaseService::SOURCE_PREDECESSOR) {
                return $this->finish($plan, 'Případ vede MyÚčto jako vlastní podání (nevznikl z předchozího programu), takže '
                    . "vyřízení {$label} předchozím programem do něj import nezapíše. Pokud ČSSZ {$label} skutečně "
                    . 'přijala, zapište přijetí dnem doručení z protokolu v Podání → Dávky nemocenského pojištění.');
            }
            $this->change(
                $plan,
                $document->statusColumn(),
                "Stav {$label}",
                $status->value,
                'predecessor',
            );
        } else {
            $plan['warnings'][] = "{$label} je u případu už vyřízené předchozím programem.";
        }
        if ($fields === [] && $workDays === null && !$mark) {
            return $this->finish($plan, null);
        }
        if ($record->kind === SicknessBenefitKind::Nem && !$record->isHzupn()) {
            $plan['warnings'][] = 'HZUPN k návratu do práce zůstává otevřené a hlídač lhůt ho dál drží.';
        }
        $plan['operation'] = 'update_case';
        $plan['_steps']['update'] = [
            'fields' => $fields,
            'work_days' => $workDays,
            'mark' => $mark,
            'reference' => $key,
        ];

        return $this->finish($plan, null);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array{case:?array<string,mixed>,blocker:?string,event_from:?string,event_to:?string,absence_id:?int} $found
     * @return array<string,mixed>
     */
    private function planCreate(int $supplierId, array $plan, SicknessImportRecord $record, array $found, ?string $receivedOn, string $key): array
    {
        $eventFrom = $found['event_from'];
        if ($eventFrom === null) {
            return $this->finish($plan, $this->missingEventBlocker($record));
        }
        $eventTo = $record->incapacityTo ?? $found['event_to'];
        $kind = $record->kind;
        $document = $record->document;
        $plan['benefit']['incapacity_from'] = $eventFrom;
        $plan['benefit']['incapacity_to'] = $eventTo;
        $plan['effective_on'] = $eventFrom;
        $plan['employment']['start_on'] ??= $record->employmentFrom;

        $input = $record->caseFields;
        $input['incapacity_from'] = $eventFrom;
        if ($eventTo !== null) {
            $input['incapacity_to'] = $eventTo;
        }
        if ($record->workDays !== []) {
            $input['work_days'] = $record->workDays;
        }
        if (($input['returned_to_work'] ?? 0) === 1 && ($input['returned_on'] ?? null) === null) {
            unset($input['returned_to_work']);
            $plan['warnings'][] = 'Hlášení uvádí návrat do práce bez data návratu; návrat se do případu nepřevzal.';
        }
        if (($input['correction'] ?? 0) === 1 && ($input['decision_number'] ?? null) === null) {
            $input['correction'] = 0;
            $plan['warnings'][] = 'Podání je opravné, ale nenese číslo rozhodnutí; opravné podání se do případu nepřebírá jako opravné.';
        }
        try {
            NempriCodebook::assertValid(
                $kind,
                $input['relationship_code'] ?? null,
                $input['paternity_reason'] ?? null,
                $input['maternity_care_reason'] ?? null,
            );
        } catch (SicknessException $exception) {
            foreach (['relationship_code', 'paternity_reason', 'maternity_care_reason'] as $column) {
                unset($input[$column]);
            }
            $plan['warnings'][] = 'Kód z číselníku ČSSZ v podání neodpovídá druhu dávky a nepřevzal se: ' . $exception->getMessage();
        }
        if (!$kind->hasEndOfIncapacityReport() && $document === SicknessDocumentKind::Hzupn) {
            return $this->finish($plan, 'HZUPN se podává jen u nemocenského.');
        }

        try {
            $context = $this->caseService->requireContext($supplierId, (int) $plan['_employment_id'], $eventFrom);
            $this->caseService->assertEventCovered($kind, $eventFrom, $context, $input);
        } catch (SicknessException $exception) {
            return $this->finish($plan, $exception->getMessage());
        }
        if (($input['ossz_code'] ?? null) === null
            && (!is_numeric($context['employer_ossz_code'] ?? null)
                || (int) $context['employer_ossz_code'] < 100
                || (int) $context['employer_ossz_code'] > 999)
        ) {
            return $this->finish($plan, 'Podání nenese kód OSSZ a firma ho nemá vyplněný v nastavení zaměstnavatele.');
        }

        $this->change($plan, 'benefit_kind', 'Druh dávky', null, $kind->value . ' - ' . (self::KIND_LABELS[$kind->value] ?? ''));
        $this->change($plan, 'incapacity_from', 'Den vzniku události', null, $eventFrom);
        $this->change($plan, 'incapacity_to', 'Poslední den události', null, $eventTo);
        $this->change($plan, 'decision_number', 'Číslo rozhodnutí', null, $input['decision_number'] ?? null);
        $this->change($plan, $document->statusColumn(), 'Stav ' . $document->agendaCode(), null, 'vyřízeno předchozím programem');
        $plan['warnings'][] = 'Případ vznikne jako převzatý z předchozího programu; ' . $document->agendaCode()
            . ' se v něm vede jako podané předchozím programem a MyÚčto ho znovu nepodává.';
        if ($document === SicknessDocumentKind::Nempri && $kind === SicknessBenefitKind::Nem) {
            $plan['warnings'][] = 'HZUPN k návratu do práce zůstává otevřené a hlídač lhůt ho dál drží.';
        }
        if ($document === SicknessDocumentKind::Hzupn) {
            $plan['warnings'][] = 'Případ vznikne jen z HZUPN. NEMPRI k němu zůstane čekat; podal-li ho předchozí program, '
                . 'nahrajte i jeho soubor.';
        }
        $plan['operation'] = 'create_case';
        $plan['_steps']['create'] = [
            'kind' => $kind->value,
            'input' => $input,
            'absence_id' => $found['absence_id'],
            'reference' => $key,
        ];

        return $this->finish($plan, null);
    }

    /**
     * Najde případ, ke kterému věta patří, nebo den vzniku, ze kterého by vznikl
     * nový.
     *
     * @return array{case:?array<string,mixed>,blocker:?string,event_from:?string,event_to:?string,absence_id:?int}
     */
    private function findCase(int $supplierId, string $environment, int $employmentId, SicknessImportRecord $record): array
    {
        $none = ['case' => null, 'blocker' => null, 'event_from' => null, 'event_to' => null, 'absence_id' => null];
        $kind = $record->kind->value;

        if ($record->decisionNumber !== null) {
            $byNumber = $this->cases->findByDecisionNumber($supplierId, $environment, $employmentId, $kind, $record->decisionNumber);
            if (count($byNumber) === 1) {
                return ['case' => $this->caseService->requireCase($supplierId, $environment, (int) $byNumber[0]['id'])] + $none;
            }
            if (count($byNumber) > 1) {
                return ['blocker' => 'Číslo rozhodnutí ' . $record->decisionNumber . ' má v evidenci víc případů téhož vztahu. '
                    . 'Zrušte duplicitní případ a import zopakujte.'] + $none;
            }
        }

        if ($record->incapacityFrom !== null) {
            $exact = $this->cases->findByScope($supplierId, $environment, $employmentId, $kind, $record->incapacityFrom);
            if ($exact !== null) {
                if ((int) ($exact['cancelled'] ?? 0) === 1) {
                    return ['blocker' => 'Případ s tímto dnem vzniku je v evidenci zrušený a nový stejného dne založit nejde. '
                        . 'Obnovte ho v Podání → Dávky nemocenského pojištění.'] + $none;
                }

                return ['case' => $this->caseService->requireCase($supplierId, $environment, (int) $exact['id'])] + $none;
            }
            $overlapping = $this->cases->overlappingForEmployment(
                $supplierId,
                $environment,
                $employmentId,
                $kind,
                $record->incapacityFrom,
                $record->incapacityTo ?? $record->incapacityFrom,
            );
            if (count($overlapping) === 1) {
                return ['case' => $this->caseService->requireCase($supplierId, $environment, (int) $overlapping[0]['id'])] + $none;
            }
            if (count($overlapping) > 1) {
                return ['blocker' => 'Období podání se překrývá s víc případy téhož druhu dávky. Zrušte duplicitní případ a import zopakujte.'] + $none;
            }

            return ['event_from' => $record->incapacityFrom, 'event_to' => $record->incapacityTo] + $none;
        }

        // Den vzniku podání nenese: HZUPN se přiřadí podle dne konce, NEMPRI
        // podle měsíce události z rozhodného období.
        if ($record->isHzupn()) {
            $end = $record->incapacityTo;
            if ($end === null) {
                return ['blocker' => 'Hlášení nenese datum návratu do práce, takže ho nejde přiřadit k neschopnosti. '
                    . 'Zapište jeho výsledek ručně v Podání → Dávky nemocenského pojištění.'] + $none;
            }
            $covering = $this->cases->overlappingForEmployment($supplierId, $environment, $employmentId, $kind, $end, $end);
            if (count($covering) === 1) {
                return ['case' => $this->caseService->requireCase($supplierId, $environment, (int) $covering[0]['id'])] + $none;
            }
            if (count($covering) > 1) {
                return ['blocker' => 'Konec neschopnosti ' . $end . ' spadá do víc případů téhož vztahu. Zrušte duplicitní případ a import zopakujte.'] + $none;
            }

            return $this->fromAbsence($supplierId, $employmentId, $record, $end, $end, null, null);
        }

        $monthStart = $record->eventMonthStart();
        $monthEnd = $record->eventMonthEnd();
        if ($monthStart === null || $monthEnd === null) {
            return ['blocker' => $this->missingEventBlocker($record)] + $none;
        }
        $inMonth = array_values(array_filter(
            $this->cases->overlappingForEmployment($supplierId, $environment, $employmentId, $kind, $monthStart, $monthEnd),
            static fn (array $case): bool => (string) $case['incapacity_from'] >= $monthStart
                && (string) $case['incapacity_from'] <= $monthEnd,
        ));
        if (count($inMonth) === 1) {
            return ['case' => $this->caseService->requireCase($supplierId, $environment, (int) $inMonth[0]['id'])] + $none;
        }
        if (count($inMonth) > 1) {
            return ['blocker' => 'V měsíci události (' . substr($monthStart, 0, 7) . ') začíná víc případů téhož druhu dávky. '
                . 'Podání nejde přiřadit jednoznačně.'] + $none;
        }

        return $this->fromAbsence($supplierId, $employmentId, $record, $monthStart, $monthEnd, $monthStart, $monthEnd);
    }

    /**
     * Neschopnost z nepřítomností: den vzniku začíná prvním dnem řetězu
     * navazujících schválených absencí zmenšeným o dny téže neschopnosti
     * vyčerpané u předchozího plátce (jako při schválení absence).
     *
     * @return array{case:?array<string,mixed>,blocker:?string,event_from:?string,event_to:?string,absence_id:?int}
     */
    private function fromAbsence(
        int $supplierId,
        int $employmentId,
        SicknessImportRecord $record,
        string $from,
        string $to,
        ?string $startFrom,
        ?string $startTo,
    ): array {
        $none = ['case' => null, 'blocker' => null, 'event_from' => null, 'event_to' => null, 'absence_id' => null];
        if ($record->kind !== SicknessBenefitKind::Nem) {
            return ['blocker' => $this->missingEventBlocker($record)] + $none;
        }
        $chains = [];
        $items = $this->absences->list($supplierId, $from, $to, $employmentId, PayrollAbsenceRepository::LIST_MAX_LIMIT)['items'];
        foreach ($items as $absence) {
            if (($absence['status'] ?? null) !== 'approved'
                || !in_array((string) ($absence['absence_type'] ?? ''), self::NEM_ABSENCES, true)
            ) {
                continue;
            }
            $chain = $this->absences->contiguousChainStart($supplierId, (int) $absence['id']);
            $chainFrom = (string) ($chain['date_from'] ?? $absence['date_from']);
            $carried = (int) ($chain['carried_days'] ?? PayrollAbsenceRepository::carriedWindowDays($absence));
            $eventFrom = $carried > 0
                ? (new \DateTimeImmutable($chainFrom))->modify('-' . $carried . ' days')->format('Y-m-d')
                : $chainFrom;
            if ($startFrom !== null && $startTo !== null && ($eventFrom < $startFrom || $eventFrom > $startTo)) {
                continue;
            }
            $id = (int) ($chain['id'] ?? $absence['id']);
            $chains[$id] = [
                'event_from' => $eventFrom,
                'event_to' => (string) $absence['date_to'],
                'absence_id' => $id,
            ];
        }
        if (count($chains) === 1) {
            return ['case' => null, 'blocker' => null] + array_values($chains)[0];
        }
        if (count($chains) > 1) {
            return ['blocker' => 'V době události je víc schválených neschopností a podání nejde přiřadit jednoznačně. '
                . 'Přiřaďte ho ručně v Podání → Dávky nemocenského pojištění.'] + $none;
        }

        return ['blocker' => $this->missingEventBlocker($record)] + $none;
    }

    private function missingEventBlocker(SicknessImportRecord $record): string
    {
        return $record->isHzupn()
            ? 'K hlášení se v evidenci nenašla neschopnost, která by končila ke dni před návratem do práce, a případ s číslem '
                . 'rozhodnutí tu také není. Zapište nejdřív neschopnost v Nepřítomnostech a import zopakujte.'
            : 'Podání nenese den vzniku události (u této dávky ho zná ČSSZ z rozhodnutí lékaře) a v evidenci k němu není '
                . 'případ ani schválená nepřítomnost v měsíci události. Zapište nejdřív neschopnost v Nepřítomnostech '
                . 'a import zopakujte.';
    }

    /**
     * @return array{employee_id:?int,matched_by:?string,blocker:?string,candidates:list<array<string,mixed>>}
     */
    private function matchPerson(int $supplierId, SicknessImportRecord $record, ?string $birthNumber, ?string $ecp): array
    {
        /** @var array<int,string> $found */
        $found = [];
        if ($birthNumber !== null || $ecp !== null) {
            $hash = $this->sensitiveData->lookupHash(
                (string) ($birthNumber ?? $ecp),
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
            );
            $type = $birthNumber !== null ? 'birth_number' : 'ecp';
            foreach ($this->lookup->employeesByIdentifierHash($supplierId, $type, $hash) as $id) {
                $found[$id] = 'birth_number';
            }
        }
        // Bez rodného čísla (HZUPN ho nemusí nést) zbývá jméno a datum narození.
        if ($found === [] && $birthNumber === null && $ecp === null
            && $record->firstName !== null && $record->lastName !== null && $record->birthDate !== null
        ) {
            foreach ($this->lookup->employeesByNameAndBirthDate(
                $supplierId,
                $record->firstName,
                $record->lastName,
                $record->birthDate,
            ) as $id) {
                $found[$id] = 'name_birth_date';
            }
        }
        if (count($found) > 1) {
            $candidates = [];
            foreach (array_keys($found) as $employeeId) {
                $candidates[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => null,
                    'label' => $this->lookup->employeeName($supplierId, $employeeId) ?? ('Osoba #' . $employeeId),
                ];
            }

            return [
                'employee_id' => null,
                'matched_by' => null,
                'blocker' => 'Rodné číslo z podání ukazuje na víc osob v evidenci. Nejspíš jde o duplicitní kartu - '
                    . 'vyjasněte to ručně a import zopakujte.',
                'candidates' => $candidates,
            ];
        }
        if ($found === []) {
            return [
                'employee_id' => null,
                'matched_by' => null,
                'blocker' => 'Osoba z podání v evidenci není (nenašla se podle '
                    . ($birthNumber === null && $ecp === null ? 'jména a data narození' : 'rodného čísla')
                    . '). Nejdřív naimportujte její registraci nebo převeďte zaměstnance.',
                'candidates' => [],
            ];
        }
        $employeeId = (int) array_key_first($found);

        return ['employee_id' => $employeeId, 'matched_by' => $found[$employeeId], 'blocker' => null, 'candidates' => []];
    }

    /**
     * Pracovní vztah osoby, který trval v době události (a v ochranné lhůtě po
     * něm); víc kandidátů se rozlišuje dnem nástupu z podání.
     *
     * @return array{row:?array<string,mixed>,blocker:?string,candidates:list<array<string,mixed>>}
     */
    private function matchEmployment(int $supplierId, int $employeeId, SicknessImportRecord $record): array
    {
        $rows = array_values(array_filter(
            $this->lookup->employments($supplierId, $employeeId),
            static fn (array $row): bool => $row['status'] !== 'archived',
        ));
        $anchor = $record->anchorDate();
        $candidates = $rows;
        if ($anchor !== null) {
            $to = $record->incapacityTo ?? $record->eventMonthEnd() ?? $anchor;
            $candidates = array_values(array_filter($rows, static function (array $row) use ($anchor, $to): bool {
                $start = $row['actual_start_date'] ?? $row['start_date'];
                if ($start === null || $start > $to) {
                    return false;
                }
                if ($row['end_date'] === null) {
                    return true;
                }
                $protected = (new \DateTimeImmutable($row['end_date']))->modify('+7 days')->format('Y-m-d');

                return $protected >= $anchor;
            }));
        }
        if ($candidates === []) {
            return [
                'row' => null,
                'candidates' => [],
                'blocker' => 'Osoba nemá pracovní vztah, který by trval v době události'
                    . ($anchor === null ? '' : ' (' . $anchor . ')') . '.',
            ];
        }
        if (count($candidates) > 1 && $record->employmentFrom !== null) {
            $sameStart = array_values(array_filter(
                $candidates,
                static fn (array $row): bool => $row['start_date'] === $record->employmentFrom
                    || $row['actual_start_date'] === $record->employmentFrom,
            ));
            if (count($sameStart) === 1) {
                $candidates = $sameStart;
            }
        }
        if (count($candidates) > 1) {
            return [
                'row' => null,
                'blocker' => 'Osoba měla v době události víc pracovních vztahů a podání nejde přiřadit jednoznačně. '
                    . 'Zapište jeho vyřízení ručně v Podání → Dávky nemocenského pojištění.',
                'candidates' => array_map(static fn (array $row): array => [
                    'employee_id' => $row['employee_id'],
                    'employment_id' => $row['id'],
                    'label' => $row['code'] . ' · ' . $row['relation_type'] . ' · od ' . ($row['start_date'] ?? '-'),
                ], $candidates),
            ];
        }

        return ['row' => $candidates[0], 'blocker' => null, 'candidates' => []];
    }

    /** Podání jiného zaměstnavatele se nepřebírá - rozhoduje VS proti VS účtáren firmy. */
    private function foreignEmployerBlocker(int $supplierId, SicknessImportRecord $record, array &$warnings): ?string
    {
        $symbol = $record->employerVariableSymbol === null
            ? null
            : RegistrationImportLookup::variableSymbol($record->employerVariableSymbol);
        if ($symbol === null) {
            return null;
        }
        $known = $this->lookup->variableSymbols($supplierId);
        if ($known === []) {
            $warnings[] = 'Firma nemá vyplněný VS mzdové účtárny, takže nejde ověřit, že podání (VS '
                . $record->employerVariableSymbol . ') patří jí. Doplňte VS v nastavení mzdové účtárny.';

            return null;
        }
        if (in_array($symbol, $known, true)) {
            return null;
        }

        return 'Podání nese variabilní symbol zaměstnavatele ' . $record->employerVariableSymbol . ', který nepatří '
            . 'žádné mzdové účtárně této firmy. Soubor je nejspíš jiného zaměstnavatele.';
    }

    private function foreignBusinessIdBlocker(int $supplierId, int $employmentId, SicknessImportRecord $record): ?string
    {
        $theirs = ltrim((string) preg_replace('/\D/', '', (string) $record->employerBusinessId), '0');
        if ($theirs === '') {
            return null;
        }
        $context = $this->cases->findEmploymentContext($supplierId, $employmentId, $record->anchorDate() ?? date('Y-m-d'));
        $ours = ltrim((string) preg_replace('/\D/', '', (string) ($context['employer_business_id'] ?? '')), '0');
        if ($ours === '' || $ours === $theirs) {
            return null;
        }

        return 'Podání nese IČ zaměstnavatele ' . $record->employerBusinessId . ', které se liší od IČ firmy. '
            . 'Soubor je nejspíš jiného zaměstnavatele.';
    }

    private function same(string $column, mixed $existing, mixed $value): bool
    {
        if (in_array($column, ['returned_to_work'], true)) {
            return (int) $existing === (int) $value;
        }
        if (in_array($column, ['hours_worked_last_day', 'shift_hours_last_day'], true)) {
            return round((float) $existing * 100) === round((float) $value * 100);
        }

        return (string) $existing === (string) $value;
    }

    private function display(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'ano' : 'ne') : (string) $value;
    }

    /** @return array{0:?string,1:?string} [rodné číslo v kanonickém tvaru, EČP] */
    private function birthNumber(SicknessImportRecord $record): array
    {
        if ($record->birthNumber === null) {
            return [null, null];
        }
        try {
            return [CzechBirthNumber::normalize($record->birthNumber), null];
        } catch (\InvalidArgumentException) {
            return [null, $record->birthNumber];
        }
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
        $plan['selectable'] = $blocker === null && in_array($plan['operation'], ['create_case', 'update_case'], true);

        return $plan;
    }
}
