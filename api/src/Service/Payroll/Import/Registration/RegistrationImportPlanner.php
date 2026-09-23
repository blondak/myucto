<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationFieldVocabulary;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Z věty registrace a stavu evidence spočítá, co by import udělal.
 *
 * Výsledek je veřejná část (to, co vidí účetní v náhledu) a interní plán
 * v klíčích začínajících podtržítkem, podle kterého pak zapisuje
 * {@see RegistrationImportWriter}. Použití si plán počítá znovu těsně před
 * zápisem každé věty — náhled z prohlížeče se nepřebírá.
 *
 * Párování je konzervativní: když údaje ve větě ukazují na víc osob nebo víc
 * vztahů, věta se zablokuje. Zapsat změnu k cizí osobě je horší než nechat
 * účetní jednu větu dodělat ručně.
 */
final class RegistrationImportPlanner
{
    private const OPEN_STATUSES = ['planned', 'preregistered', 'active', 'suspended'];
    private const NOT_STARTED_STATUSES = ['planned', 'preregistered'];
    private const EXPORT_LABEL = 'Export zaměstnanců ČSSZ';

    public function __construct(
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollEmploymentRepository $employments,
    ) {}

    /** @return array<string,mixed> */
    public function plan(
        int $supplierId,
        string $environment,
        RegistrationRecord $record,
        string $fileName,
        string $fileSha256,
    ): array {
        [$birthNumber, $ecp] = $this->birthNumber($record);
        $birthDate = $record->birthDate
            ?? ($birthNumber === null ? null : CzechBirthNumber::birthDate($birthNumber));
        $relationType = $record->documentType === 'PREZEC26' ? 'employment' : $record->relationType();

        $plan = [
            'key' => self::key($fileSha256, $record->position),
            'file' => $fileName,
            'sequence' => $record->sequence,
            'document_type' => $record->documentType,
            'action_code' => $record->actionCode,
            'action_label' => $record->isCsszExport()
                ? self::EXPORT_LABEL
                : PayrollRegistrationFieldVocabulary::action($record->documentType, $record->actionCode),
            'prepared_on' => $record->preparedOn,
            'effective_on' => $record->decisiveDate(),
            'person' => [
                'full_name' => $record->fullName() ?? 'Neuvedené jméno',
                'first_name' => $record->firstName,
                'last_name' => $record->lastName,
                'birth_date' => $birthDate,
                'birth_number_masked' => $this->maskedBirthNumber($birthNumber ?? $ecp),
                'has_oic' => $record->personIdentifier !== null,
            ],
            'employment' => [
                'start_on' => $record->documentType === 'PREZEC26' ? $record->expectedStartOn : $record->startOn,
                'end_on' => $record->endOn,
                'activity_code' => $record->activityCode,
                'relation_type' => $relationType,
                'position_name' => $record->positionName,
                'has_id_ppv' => $record->employmentIdentifier !== null,
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
            '_record' => $record,
            '_file_sha256' => $fileSha256,
            '_employee_id' => null,
            '_employment_id' => null,
            '_steps' => [
                'create_person' => null,
                'create_employment' => null,
                'terms' => [],
                'identity_facts' => [],
                'birth_surname' => null,
                'addresses' => [],
                'health_insurer' => null,
                'activate_on' => null,
                'terminate' => null,
                'identifiers' => ['person' => null, 'employment' => null],
            ],
        ];
        if ($ecp !== null) {
            $plan['warnings'][] = 'Číslo pojištěnce ve větě není platné rodné číslo, osoba se hledá '
                . 'jako evidenční číslo pojištěnce (EČP).';
        }

        $supported = match ($record->documentType) {
            'REGZEC25' => in_array($record->actionCode, [1, 2, 3, 4, 8], true),
            'PREZEC26' => in_array($record->actionCode, [9, 10], true),
            RegistrationRecord::CSSZ_EXPORT => true,
            default => false,
        };
        if (!$supported) {
            $plan['operation'] = 'unsupported';

            return $this->finish($plan, $plan['action_label'] . ' import neumí zapsat automaticky. '
                . 'Zpracujte oznámení ručně na kartě pracovního vztahu.');
        }
        if ($record->isCsszExport()) {
            $foreign = $this->foreignEmployerBlocker($supplierId, $record);
            if ($foreign !== null) {
                return $this->finish($plan, $foreign);
            }
        }

        $person = $this->matchPerson($supplierId, $environment, $record, $birthNumber, $ecp);
        if ($person['blocker'] !== null) {
            $plan['match'] = array_merge($plan['match'], [
                'status' => 'ambiguous',
                'candidates' => $person['candidates'],
            ]);

            return $this->finish($plan, $person['blocker']);
        }
        $employeeId = $person['employee_id'];
        if ($employeeId === null) {
            return $this->planWithoutPerson($plan, $record, $relationType, $birthNumber, $birthDate);
        }

        $plan['_employee_id'] = $employeeId;
        $plan['match'] = array_merge($plan['match'], [
            'status' => 'matched',
            'matched_by' => $person['matched_by'],
            'employee_id' => $employeeId,
            'employee_name' => $this->lookup->employeeName($supplierId, $employeeId),
        ]);

        $employment = $this->matchEmployment($supplierId, $employeeId, $record, $relationType, $person['id_ppv_employment_id']);
        if ($employment['blocker'] !== null) {
            $plan['match']['status'] = 'ambiguous';
            $plan['match']['candidates'] = $employment['candidates'];

            return $this->finish($plan, $employment['blocker']);
        }
        $row = $employment['row'];
        if ($row !== null) {
            $plan['_employment_id'] = $row['id'];
            $plan['match']['employment_id'] = $row['id'];
            $plan['match']['employment_code'] = $row['code'];
            $plan['warnings'] = array_merge($plan['warnings'], $employment['warnings']);
        }

        return match (true) {
            $record->actionCode === 2 => $this->planTermination($supplierId, $environment, $plan, $record, $row),
            in_array($record->actionCode, [8, 10], true) => $this->planNoShow($supplierId, $environment, $plan, $record, $row),
            default => $this->planUpdate($supplierId, $environment, $plan, $record, $row, $relationType),
        };
    }

    public static function key(string $fileSha256, int $position): string
    {
        return substr($fileSha256, 0, 16) . ':' . $position;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function planWithoutPerson(
        array $plan,
        RegistrationRecord $record,
        ?string $relationType,
        ?string $birthNumber,
        ?string $birthDate,
    ): array {
        $creates = ($record->documentType === 'REGZEC25' && $record->actionCode === 1)
            || ($record->documentType === 'PREZEC26' && $record->actionCode === 9)
            || $record->isCsszExport();
        if (!$creates) {
            $plan['operation'] = $record->actionCode === 2 ? 'terminate' : 'update';

            return $this->finish($plan, 'Osoba z téhle věty v evidenci není (nenašla se podle rodného čísla, '
                . 'OIČ ani ID PPV). Nejdřív naimportujte její přihlášení, nebo ji založte ručně.');
        }

        $plan['match']['status'] = 'new';
        $plan['operation'] = 'create_person';
        $start = $plan['employment']['start_on'];
        if ($record->firstName === null || $record->lastName === null) {
            return $this->finish($plan, 'Věta nemá vyplněné jméno i příjmení, takže z ní osobu založit nejde. '
                . 'Založte ji ručně na přehledu osob.');
        }
        if ($start === null) {
            if ($record->isCsszExport()) {
                return $this->finish($plan, self::exportWithoutStartBlocker('Osoba v evidenci není a export'));
            }

            return $this->finish($plan, 'Věta nemá datum nástupu, takže z ní vztah založit nejde. '
                . 'Založte osobu ručně na přehledu osob.');
        }
        if ($relationType === null) {
            return $this->finish($plan, 'Druh činnosti „' . ($record->activityCode ?? '—')
                . '“ import neumí přiřadit k druhu pracovního vztahu. Založte osobu ručně.');
        }
        if ($record->documentType === 'PREZEC26') {
            $plan['warnings'][] = 'Částečné přihlášení druh vztahu neuvádí — osoba se založí s pracovním '
                . 'poměrem. Jde-li o dohodu, změňte druh vztahu na kartě.';
        }
        $this->derivedStartWarnings($plan, $record);

        $insurer = $this->insurer($plan, $record->healthInsurerCode);
        $plan['_steps']['create_person'] = [
            'full_name' => $record->fullName(),
            'first_name' => $record->firstName,
            'last_name' => $record->lastName,
            'birth_date' => $birthDate,
            'birth_number' => $birthNumber,
            'health_insurer_code' => $insurer,
            'relation_type' => $relationType,
            'planned_start_on' => $start,
        ];
        $this->change($plan, 'full_name', 'Jméno a příjmení', null, $record->fullName());
        $this->change($plan, 'birth_date', 'Datum narození', null, $birthDate);
        $this->change($plan, 'relation_type', 'Druh pracovního vztahu', null, $relationType);
        $this->change($plan, 'start_on', 'Nástup', null, $start);
        $this->change($plan, 'health_insurer_code', 'Zdravotní pojišťovna', null, $insurer);
        if ($birthNumber === null) {
            $plan['warnings'][] = 'Rodné číslo se z věty nepřevezme — doplňte ho na kartě osoby.';
        }

        $facts = $this->identityFacts($record);
        foreach ($facts as $field => $value) {
            $this->change($plan, $field, $this->factLabel($field), null, $value);
        }
        $plan['_steps']['identity_facts'] = $facts;
        $plan['_steps']['birth_surname'] = $record->birthSurname;
        foreach ($this->addresses($record) as $type => $address) {
            $plan['_steps']['addresses'][$type] = $address;
            $this->change($plan, $type . '_address', $this->addressLabel($type), null, $this->addressText($address));
        }
        if ($record->documentType === 'REGZEC25' || $record->isCsszExport()) {
            $this->newEmploymentTerms($plan, $record, $relationType);
            if ($start <= date('Y-m-d')) {
                $plan['_steps']['activate_on'] = $start;
                $this->change($plan, 'status', 'Stav vztahu', null, 'active');
            }
        }
        $plan['_steps']['identifiers'] = [
            'person' => $record->personIdentifier,
            'employment' => $record->employmentIdentifier,
        ];
        $this->educationInfo($plan, $record, null);

        return $this->finish($plan, null);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function planUpdate(
        int $supplierId,
        string $environment,
        array $plan,
        RegistrationRecord $record,
        ?array $row,
        ?string $relationType,
    ): array {
        $employeeId = (int) $plan['_employee_id'];
        $decisive = $record->decisiveDate() ?? date('Y-m-d');
        $registers = ($record->documentType === 'REGZEC25' && $record->actionCode === 1)
            || ($record->documentType === 'PREZEC26' && $record->actionCode === 9)
            || $record->isCsszExport();

        if ($row === null) {
            if (!$registers) {
                $plan['warnings'][] = 'Pracovní vztah z věty se u osoby nepodařilo určit, zapíšou se jen '
                    . 'údaje osoby. Údaje vztahu (pracoviště, CZ-ISCO) doplňte na kartě vztahu.';
            } else {
                $start = $plan['employment']['start_on'];
                if ($record->isCsszExport() && $start === null) {
                    $plan['operation'] = 'create_employment';

                    return $this->finish($plan, self::exportWithoutStartBlocker(
                        'Osoba v evidenci je, ale otevřený pracovní vztah, ke kterému věta patří, se nenašel. Export',
                    ));
                }
                if ($start === null || $relationType === null) {
                    $plan['operation'] = 'create_employment';

                    return $this->finish($plan, 'Osoba v evidenci je, ale nový vztah z věty založit nejde — '
                        . 'chybí datum nástupu nebo druh vztahu. Založte vztah ručně na kartě osoby.');
                }
                $plan['_steps']['create_employment'] = [
                    'relation_type' => $relationType,
                    'planned_start_on' => $start,
                ];
                $this->change($plan, 'relation_type', 'Nový pracovní vztah', null, $relationType);
                $this->change($plan, 'start_on', 'Nástup', null, $start);
                if ($record->documentType === 'PREZEC26') {
                    $plan['warnings'][] = 'Částečné přihlášení druh vztahu neuvádí — vztah se založí jako '
                        . 'pracovní poměr. Jde-li o dohodu, změňte ho na kartě.';
                }
                $this->derivedStartWarnings($plan, $record);
            }
        }

        $this->planPersonFacts($supplierId, $plan, $record, $employeeId, $decisive);

        if ($record->isCsszExport()) {
            if ($row !== null) {
                $this->verifyExportActivity($supplierId, $plan, $record, $row, $relationType);
            } elseif ($plan['_steps']['create_employment'] !== null && $relationType !== null) {
                $this->newEmploymentTerms($plan, $record, $relationType);
                $start = $plan['employment']['start_on'];
                if (is_string($start) && $start <= date('Y-m-d')) {
                    $plan['_steps']['activate_on'] = $start;
                    $this->change($plan, 'status', 'Stav vztahu', null, 'active');
                }
            }
        }

        if ($record->documentType === 'REGZEC25') {
            $this->planInsurer($supplierId, $plan, $record, $employeeId, $decisive);
            $this->planAddresses($supplierId, $plan, $record, $employeeId, $decisive);
            if ($row !== null) {
                $this->planTerms($supplierId, $plan, $record, $row, $decisive);
            } elseif ($plan['_steps']['create_employment'] !== null && $relationType !== null) {
                $this->newEmploymentTerms($plan, $record, $relationType);
            }
            if ($record->actionCode === 1) {
                $start = $row['start_date'] ?? $plan['employment']['start_on'];
                $notStarted = $row === null || in_array($row['status'], self::NOT_STARTED_STATUSES, true);
                if ($notStarted && is_string($start) && $start <= date('Y-m-d')) {
                    $plan['_steps']['activate_on'] = $start;
                    $this->change($plan, 'status', 'Stav vztahu', $row['status'] ?? null, 'active');
                }
            }
            $this->educationInfo($plan, $record, $employeeId);
        }

        $this->planIdentifiers($supplierId, $environment, $plan, $record, $employeeId, $row);

        $steps = $plan['_steps'];
        $hasWork = $steps['create_employment'] !== null
            || $steps['terms'] !== []
            || $steps['identity_facts'] !== []
            || $steps['birth_surname'] !== null
            || $steps['addresses'] !== []
            || $steps['health_insurer'] !== null
            || $steps['activate_on'] !== null;
        $hasIdentifiers = $steps['identifiers']['person'] !== null || $steps['identifiers']['employment'] !== null;
        $plan['operation'] = match (true) {
            $steps['create_employment'] !== null => 'create_employment',
            $hasWork => 'update',
            $hasIdentifiers => 'assign_identifiers',
            default => 'none',
        };

        return $this->finish($plan, $plan['blocker']);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function planTermination(
        int $supplierId,
        string $environment,
        array $plan,
        RegistrationRecord $record,
        ?array $row,
    ): array {
        $plan['operation'] = 'terminate';
        if ($row === null) {
            return $this->finish($plan, 'Osoba v evidenci je, ale pracovní vztah, který věta odhlašuje, se '
                . 'nepodařilo určit. Ukončete vztah ručně na kartě osoby.');
        }
        $end = $record->endOn;
        if ($end === null) {
            return $this->finish($plan, 'Odhlášení nemá datum skončení vztahu, takže ho nejde zapsat.');
        }
        $status = (string) $row['status'];
        if ($status === 'ended') {
            if ($row['end_date'] === $end) {
                $plan['operation'] = 'none';
                $this->planIdentifiers($supplierId, $environment, $plan, $record, (int) $plan['_employee_id'], $row);
                if ($plan['_steps']['identifiers']['person'] !== null
                    || $plan['_steps']['identifiers']['employment'] !== null
                ) {
                    $plan['operation'] = 'assign_identifiers';
                }

                return $this->finish($plan, $plan['blocker']);
            }

            return $this->finish($plan, "Vztah je už ukončený k {$row['end_date']}, ale odhlášení hlásí {$end}. "
                . 'Datum skončení opravte ručně na kartě vztahu.');
        }
        if (in_array($status, self::NOT_STARTED_STATUSES, true)) {
            return $this->finish($plan, 'Vztah ještě nezačal (je jen plánovaný), odhlášení k němu nejde zapsat. '
                . 'Pokud zaměstnanec nenastoupil, zapište to na kartě vztahu.');
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            return $this->finish($plan, 'Vztah není aktivní, odhlášení k němu nejde zapsat.');
        }
        $start = $row['actual_start_date'] ?? $row['start_date'];
        if (is_string($start) && $end < $start) {
            return $this->finish($plan, "Datum skončení {$end} předchází nástupu {$start}. Zkontrolujte soubor.");
        }
        $plan['_steps']['terminate'] = ['target' => 'ended', 'on' => $end];
        $this->change($plan, 'end_date', 'Skončení vztahu', $row['end_date'], $end);
        $this->planIdentifiers($supplierId, $environment, $plan, $record, (int) $plan['_employee_id'], $row);

        return $this->finish($plan, $plan['blocker']);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function planNoShow(
        int $supplierId,
        string $environment,
        array $plan,
        RegistrationRecord $record,
        ?array $row,
    ): array {
        $plan['operation'] = 'terminate';
        if ($row === null) {
            return $this->finish($plan, 'Pracovní vztah, ke kterému věta hlásí nenastoupení, se nepodařilo '
                . 'určit. Zapište to ručně na kartě osoby.');
        }
        if ($row['status'] === 'no_show') {
            $plan['operation'] = 'none';

            return $this->finish($plan, null);
        }
        if (!in_array($row['status'], self::NOT_STARTED_STATUSES, true)) {
            return $this->finish($plan, 'Vztah už začal nebo skončil, nenastoupení k němu nejde zapsat. '
                . 'Zkontrolujte stav vztahu na kartě.');
        }
        $on = $row['start_date'] ?? ($record->decisiveDate() ?? date('Y-m-d'));
        $plan['_steps']['terminate'] = ['target' => 'no_show', 'on' => $on];
        $this->change($plan, 'status', 'Stav vztahu', (string) $row['status'], 'no_show');

        return $this->finish($plan, null);
    }

    /** @param array<string,mixed> $plan */
    private function planPersonFacts(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        string $decisive,
    ): void {
        try {
            $identity = $this->registrations->identityAt($supplierId, $employeeId, min($decisive, date('Y-m-d')))
                ?? $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'));
        } catch (\DomainException $e) {
            $plan['warnings'][] = $e->getMessage();

            return;
        }
        if ($identity === null) {
            $plan['warnings'][] = 'Osoba nemá k rozhodnému dni evidovanou identitu, údaje o narození '
                . 'a občanství se nezapíšou. Doplňte je na kartě osoby.';

            return;
        }
        $facts = [];
        foreach ($this->identityFacts($record) as $field => $value) {
            $current = self::text($identity[$field] ?? null);
            if ($current !== $value) {
                $facts[$field] = $value;
                $this->change($plan, $field, $this->factLabel($field), $current, $value);
            }
        }
        $plan['_steps']['identity_facts'] = $facts;
        if ($record->birthSurname !== null && self::text($identity['birth_surname'] ?? null) === null) {
            $plan['_steps']['birth_surname'] = $record->birthSurname;
            $this->change($plan, 'birth_surname', 'Rodné příjmení', null, $record->birthSurname);
        }
        foreach (['first_name' => $record->firstName, 'last_name' => $record->lastName] as $field => $value) {
            $current = self::text($identity[$field] ?? null);
            if ($value !== null && $current !== null && $current !== $value) {
                $plan['warnings'][] = ($field === 'first_name' ? 'Jméno' : 'Příjmení')
                    . " ve větě ({$value}) se liší od evidence ({$current}). Import ho nemění — "
                    . 'jde-li o skutečnou změnu, zapište ji na kartě osoby.';
            }
        }
        $birthDate = self::text($identity['birth_date'] ?? null);
        if ($record->birthDate !== null && $birthDate !== null && $birthDate !== $record->birthDate) {
            $plan['warnings'][] = "Datum narození ve větě ({$record->birthDate}) se liší od evidence "
                . "({$birthDate}). Ověřte, že jde o tutéž osobu.";
        }
    }

    /** @param array<string,mixed> $plan */
    private function planInsurer(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        string $decisive,
    ): void {
        $imported = $this->insurer($plan, $record->healthInsurerCode);
        if ($imported === null) {
            return;
        }
        $current = $this->lookup->healthInsurerAt($supplierId, $employeeId, substr($decisive, 0, 7) . '-01');
        if ($current === $imported) {
            return;
        }
        $plan['_steps']['health_insurer'] = ['code' => $imported, 'on' => $decisive];
        $this->change($plan, 'health_insurer_code', 'Zdravotní pojišťovna', $current, $imported);
    }

    /** @param array<string,mixed> $plan */
    private function planAddresses(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        string $decisive,
    ): void {
        foreach ($this->addresses($record) as $type => $address) {
            $current = $this->lookup->addressAt($supplierId, $employeeId, $type, $decisive);
            $currentText = $current === null ? null : $this->addressText($current);
            if ($current !== null && self::sameAddress($current, $address)) {
                continue;
            }
            $plan['_steps']['addresses'][$type] = $address + ['on' => $decisive];
            $this->change($plan, $type . '_address', $this->addressLabel($type), $currentText, $this->addressText($address));
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function planTerms(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        array $row,
        string $decisive,
    ): void {
        if (!in_array($row['status'], self::OPEN_STATUSES, true)) {
            return;
        }
        $current = $this->employments->currentTerms($supplierId, (int) $row['id']);
        if ($current === null) {
            return;
        }
        $imported = $this->importedTerms($plan, $record, (string) $row['relation_type'], $current);
        $changes = [];
        foreach ($imported as $field => $value) {
            $stored = self::text($current[$field] ?? null);
            if ($stored !== $value) {
                $changes[$field] = $value;
            }
        }
        if ($changes === []) {
            return;
        }
        if ((string) $current['effective_from'] > $decisive) {
            $plan['warnings'][] = 'Ke dni věty platila starší verze sjednaných podmínek než ta dnešní, '
                . 'pracoviště ani druh činnosti se proto nezapíšou. Opravte je na kartě vztahu.';

            return;
        }
        foreach ($changes as $field => $value) {
            $this->change($plan, $field, $this->termLabel($field), self::text($current[$field] ?? null), $value);
        }
        $plan['_steps']['terms'] = $changes;
    }

    /**
     * Podmínky vztahu, které věta nese. Druh činnosti se převezme jen tehdy,
     * když sedí na druh vztahu — jinak by ho validátor podmínek stejně odmítl.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $current
     * @return array<string,string>
     */
    private function importedTerms(array &$plan, RegistrationRecord $record, string $relationType, ?array $current): array
    {
        $terms = [];
        if ($record->activityCode !== null) {
            /*
             * Cizí mzdové programy posílají `relDetail="1"` i u dohod a ČSSZ
             * takové podání přijímá. Evidence ale u DPP/DPČ bližší určení
             * vztahu nevede, takže se u nich použije výchozí hodnota druhu
             * vztahu — jinak by import hlásil nesoulad, který žádným není.
             */
            $defaultDetail = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($relationType)[1];
            $detail = $record->relationshipDetailCode
                ?? self::text($current['jmhz_relationship_detail_code'] ?? null)
                ?? $defaultDetail;
            if (!PayrollEmploymentJmhzActivityFamily::matches($relationType, $record->activityCode, $detail)
                && PayrollEmploymentJmhzActivityFamily::matches($relationType, $record->activityCode, $defaultDetail)
            ) {
                $detail = $defaultDetail;
            }
            if (PayrollEmploymentJmhzActivityFamily::matches($relationType, $record->activityCode, $detail)) {
                $terms['activity_code'] = $record->activityCode;
                if ($detail !== null) {
                    $terms['jmhz_relationship_detail_code'] = $detail;
                }
            } else {
                $plan['warnings'][] = "Druh činnosti „{$record->activityCode}“ ve větě neodpovídá druhu "
                    . 'pracovního vztahu v evidenci, nezapisuje se.';
            }
        }
        if ($record->professionCode !== null) {
            if (preg_match('/^[0-9]{4,5}$/D', $record->professionCode) === 1) {
                $terms['cz_isco_code'] = $record->professionCode;
            } else {
                $plan['warnings'][] = "Kód profese „{$record->professionCode}“ není kód CZ-ISCO, nezapisuje se.";
            }
        }
        $workPlace = $record->workplaceCity ?? $record->contractPlace;
        if ($workPlace !== null) {
            $terms['work_place'] = mb_substr($workPlace, 0, 255);
        }
        if ($record->workplaceMunicipalityCode !== null) {
            if ($record->workplaceCity !== null) {
                $terms['jmhz_workplace_municipality_code'] = $record->workplaceMunicipalityCode;
                $terms['jmhz_workplace_country_code'] = 'CZ';
            } else {
                $plan['warnings'][] = 'Kód obce pracoviště je ve větě bez názvu obce, nezapisuje se.';
            }
        }

        return $terms;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     */
    private function planIdentifiers(
        int $supplierId,
        string $environment,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        ?array $row,
    ): void {
        $person = null;
        if ($record->personIdentifier !== null) {
            $person = $this->identifierPlan(
                $plan,
                'Osobní identifikační číslo (OIČ)',
                fn (): ?bool => $this->identities->activePersonExternalIdMatches(
                    $supplierId,
                    $employeeId,
                    $environment,
                    (string) $record->personIdentifier,
                ),
                fn (): ?string => $this->registrations->activePersonExternalId(
                    $supplierId,
                    $employeeId,
                    $environment,
                    'ik_mpsv',
                )['source_kind'] ?? null,
                $record->personIdentifier,
            );
        }
        $employment = null;
        if ($record->employmentIdentifier !== null) {
            if ($row === null) {
                if ($plan['_steps']['create_employment'] !== null) {
                    $employment = $record->employmentIdentifier;
                }
            } else {
                $employment = $this->identifierPlan(
                    $plan,
                    'Identifikátor pracovního vztahu (ID PPV)',
                    fn (): ?bool => $this->identities->activeEmploymentExternalIdMatches(
                        $supplierId,
                        (int) $row['id'],
                        $environment,
                        (string) $record->employmentIdentifier,
                    ),
                    fn (): ?string => $this->registrations->activeExternalId(
                        $supplierId,
                        (int) $row['id'],
                        $environment,
                        'id_ppv',
                    )['source_kind'] ?? null,
                    $record->employmentIdentifier,
                );
            }
        }
        if ($row === null && $plan['_steps']['create_employment'] === null && ($person !== null || $employment !== null)) {
            $plan['warnings'][] = 'Identifikátory od ČSSZ se ukládají k pracovnímu vztahu, a ten se nepodařilo '
                . 'určit. Doplňte je ručně na kartě vztahu.';
            $person = null;
            $employment = null;
        }
        $plan['_steps']['identifiers'] = ['person' => $person, 'employment' => $employment];
        if ($person !== null) {
            $this->change($plan, 'person_external_identifier', 'OIČ (IK MPSV)', null, $person);
        }
        if ($employment !== null) {
            $this->change($plan, 'employment_external_identifier', 'ID pracovního vztahu (ID PPV)', null, $employment);
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param callable():?bool $matches
     * @param callable():?string $sourceKind
     */
    private function identifierPlan(
        array &$plan,
        string $label,
        callable $matches,
        callable $sourceKind,
        string $value,
    ): ?string {
        try {
            $same = $matches();
        } catch (\InvalidArgumentException $e) {
            $plan['warnings'][] = $e->getMessage();

            return null;
        }
        if ($same === null) {
            return $value;
        }
        if ($same) {
            return null;
        }
        if ($sourceKind() === 'trusted_receipt') {
            $plan['blocker'] = "{$label} ve větě se liší od čísla, které v evidenci stojí podle protokolu ČSSZ. "
                . 'Věta nejspíš patří jiné osobě nebo vztahu — zkontrolujte ji a zpracujte ručně.';
        } else {
            $plan['warnings'][] = "{$label} ve větě se liší od ručně zapsaného čísla v evidenci. Import ho "
                . 'nepřepisuje; opravte ho na kartě vztahu, pokud je v evidenci překlep.';
        }

        return null;
    }

    /**
     * @return array{employee_id:?int,matched_by:?string,id_ppv_employment_id:?int,blocker:?string,candidates:list<array<string,mixed>>}
     */
    private function matchPerson(
        int $supplierId,
        string $environment,
        RegistrationRecord $record,
        ?string $birthNumber,
        ?string $ecp,
    ): array {
        /** @var array<int,string> $found */
        $found = [];
        if ($record->personIdentifier !== null) {
            $hash = $this->sensitiveData->lookupHash(
                $record->personIdentifier,
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
            foreach ($this->lookup->employeesByPersonExternalIdHash($supplierId, $environment, $hash) as $id) {
                $found[$id] ??= 'oic';
            }
        }
        if ($birthNumber !== null || $ecp !== null) {
            $hash = $this->sensitiveData->lookupHash(
                (string) ($birthNumber ?? $ecp),
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
            );
            $type = $birthNumber !== null ? 'birth_number' : 'ecp';
            foreach ($this->lookup->employeesByIdentifierHash($supplierId, $type, $hash) as $id) {
                $found[$id] ??= 'birth_number';
            }
        }
        $idPpvEmploymentId = null;
        if ($record->employmentIdentifier !== null) {
            $hit = $this->registrations->employmentByExternalIdValueHash(
                $supplierId,
                $environment,
                'id_ppv',
                $this->sensitiveData->lookupHash(
                    $record->employmentIdentifier,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                ),
            );
            if ($hit !== null) {
                $idPpvEmploymentId = $hit['employment_id'];
                $found[$hit['employee_id']] ??= 'id_ppv';
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
                'id_ppv_employment_id' => null,
                'blocker' => 'Údaje ve větě (rodné číslo, OIČ, ID PPV) ukazují na různé osoby v evidenci. '
                    . 'Nejspíš jde o duplicitní kartu nebo překlep — vyjasněte to ručně a import zopakujte.',
                'candidates' => $candidates,
            ];
        }
        $employeeId = array_key_first($found);

        return [
            'employee_id' => $employeeId,
            'matched_by' => $employeeId === null ? null : $found[$employeeId],
            'id_ppv_employment_id' => $idPpvEmploymentId,
            'blocker' => null,
            'candidates' => [],
        ];
    }

    /**
     * @return array{row:?array<string,mixed>,blocker:?string,candidates:list<array<string,mixed>>,warnings:list<string>}
     */
    private function matchEmployment(
        int $supplierId,
        int $employeeId,
        RegistrationRecord $record,
        ?string $relationType,
        ?int $idPpvEmploymentId,
    ): array {
        $rows = $this->lookup->employments($supplierId, $employeeId);
        if ($idPpvEmploymentId !== null) {
            foreach ($rows as $row) {
                if ($row['id'] === $idPpvEmploymentId) {
                    return ['row' => $row, 'blocker' => null, 'candidates' => [], 'warnings' => []];
                }
            }
        }

        $start = $record->documentType === 'PREZEC26' ? $record->expectedStartOn : $record->startOn;
        if ($start !== null) {
            $sameStart = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['start_date'] === $start && $row['status'] !== 'archived',
            ));
            if (count($sameStart) === 1) {
                return ['row' => $sameStart[0], 'blocker' => null, 'candidates' => [], 'warnings' => []];
            }
            if (count($sameStart) > 1) {
                return $this->ambiguousEmployment($sameStart);
            }
        }

        // Částečné přihlášení se podává PŘED nástupem, k už běžícímu vztahu tedy patřit nemůže.
        $statuses = $record->documentType === 'PREZEC26' ? self::NOT_STARTED_STATUSES : self::OPEN_STATUSES;
        $open = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['status'], $statuses, true)
                && ($relationType === null || $row['relation_type'] === $relationType),
        ));
        // Export vztah jen ověřuje: druh vztahu, který v evidenci nesedí, je
        // důvod k varování, ne k založení druhého vztahu vedle existujícího.
        if ($open === [] && $record->isCsszExport()) {
            $open = array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['status'], $statuses, true),
            ));
        }
        if ($open === [] && $record->actionCode === 2 && $record->endOn !== null) {
            $open = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['status'] === 'ended' && $row['end_date'] === $record->endOn
                    && ($relationType === null || $row['relation_type'] === $relationType),
            ));
        }
        if (count($open) > 1) {
            return $this->ambiguousEmployment($open);
        }
        if ($open === []) {
            return ['row' => null, 'blocker' => null, 'candidates' => [], 'warnings' => []];
        }
        $warnings = [];
        if ($start !== null && $open[0]['start_date'] !== $start && !$record->isCsszExport()) {
            $warnings[] = "Nástup ve vztahu {$open[0]['code']} ({$open[0]['start_date']}) se liší od věty ({$start}). "
                . 'Datum nástupu import nemění — zkontrolujte ho na kartě vztahu.';
        }

        return ['row' => $open[0], 'blocker' => null, 'candidates' => [], 'warnings' => $warnings];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{row:null,blocker:string,candidates:list<array<string,mixed>>,warnings:list<string>}
     */
    private function ambiguousEmployment(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'employee_id' => $row['employee_id'],
                'employment_id' => $row['id'],
                'label' => $row['code'] . ' · ' . $row['relation_type'] . ' · od ' . ($row['start_date'] ?? '—'),
            ];
        }

        return [
            'row' => null,
            'blocker' => 'Osoba má víc pracovních vztahů, na které věta sedí, a ze souboru nejde poznat, '
                . 'kterého se týká. Zpracujte větu ručně na kartě osoby.',
            'candidates' => $candidates,
            'warnings' => [],
        ];
    }

    /**
     * Podmínky nového vztahu z věty. Výchozí druh činnosti druhu vztahu se
     * nezapisuje — založení vztahu ho nastaví samo.
     *
     * @param array<string,mixed> $plan
     */
    private function newEmploymentTerms(array &$plan, RegistrationRecord $record, string $relationType): void
    {
        $terms = $this->importedTerms($plan, $record, $relationType, null);
        [$defaultActivity, $defaultDetail] = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($relationType);
        if (($terms['activity_code'] ?? $defaultActivity) === $defaultActivity
            && ($terms['jmhz_relationship_detail_code'] ?? $defaultDetail) === $defaultDetail
        ) {
            unset($terms['activity_code'], $terms['jmhz_relationship_detail_code']);
        }
        foreach ($terms as $field => $value) {
            $this->change($plan, $field, $this->termLabel($field), null, $value);
        }
        $plan['_steps']['terms'] = $terms;
    }

    /**
     * Export zaměstnanců ČSSZ druh činnosti existujícího vztahu jen ověří:
     * nesoulad je varování, podmínky vztahu import nemění.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function verifyExportActivity(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        array $row,
        ?string $relationType,
    ): void {
        if ($relationType !== null && $row['relation_type'] !== $relationType) {
            $plan['warnings'][] = "Druh vztahu {$row['code']} v evidenci ({$row['relation_type']}) neodpovídá "
                . "exportu ČSSZ ({$relationType}"
                . ($record->smallScale ? ', zaměstnání malého rozsahu' : '')
                . '). Import ho nemění — zkontrolujte vztah a případně podejte opravu u ČSSZ.';
        }
        if ($record->activityCode === null) {
            return;
        }
        $current = self::text($this->employments->currentTerms($supplierId, (int) $row['id'])['activity_code'] ?? null);
        if ($current !== null && $current !== $record->activityCode) {
            $plan['warnings'][] = "Druh činnosti ve vztahu {$row['code']} ({$current}) se liší od exportu ČSSZ "
                . "({$record->activityCode}). Import ho nemění — zkontrolujte, který je správný.";
        }
    }

    /** @param array<string,mixed> $plan */
    private function derivedStartWarnings(array &$plan, RegistrationRecord $record): void
    {
        $start = $record->derivedStart;
        if (!$record->isCsszExport() || $start === null) {
            return;
        }
        if ($start['source'] === CsszExportStartResolver::SOURCE_START_DATE) {
            $plan['warnings'][] = "Export ČSSZ datum nástupu nenese; převzalo se z měsíčního hlášení za {$start['period']}.";

            return;
        }
        $plan['warnings'][] = "Export ČSSZ datum nástupu nenese; jako nástup se použil začátek pojištění "
            . "{$start['on']} z měsíčního hlášení za {$start['period']}.";
        if (CsszExportStartResolver::needsCheck($start)) {
            $plan['warnings'][] = 'Začátek pojištění vyšel na první den nejstaršího hlášeného měsíce, takže '
                . 'pojištění mohlo trvat už dřív. Skutečný nástup zkontrolujte (pracovní smlouva, přihláška) '
                . 'a případně ho opravte na kartě vztahu.';
        }
    }

    /**
     * VS zaměstnavatele ve větě exportu musí patřit některé mzdové účtárně
     * firmy. Když firma žádný VS nevede, kontrola se přeskočí.
     */
    private function foreignEmployerBlocker(int $supplierId, RegistrationRecord $record): ?string
    {
        if ($record->employerVariableSymbol === null) {
            return null;
        }
        $known = $this->lookup->variableSymbols($supplierId);
        $symbol = RegistrationImportLookup::variableSymbol($record->employerVariableSymbol);
        if ($known === [] || $symbol === null || in_array($symbol, $known, true)) {
            return null;
        }

        return "Věta nese variabilní symbol zaměstnavatele {$record->employerVariableSymbol}, který nepatří žádné "
            . 'mzdové účtárně této firmy. Export je nejspíš jiného zaměstnavatele — nahrajte export stažený '
            . 'pod správným VS, nebo VS doplňte v nastavení mzdové účtárny.';
    }

    private static function exportWithoutStartBlocker(string $prefix): string
    {
        return $prefix . ' zaměstnanců ČSSZ datum nástupu nenese a v dávce není měsíční hlášení JMHZ '
            . 's formulářem se stejným ID PPV, ze kterého by šel nástup odvodit. Nahrajte spolu s exportem '
            . 'i měsíční hlášení (nebo přihlášku REGZEC) a obnovte náhled.';
    }

    /** @return array{0:?string,1:?string} [rodné číslo v kanonickém tvaru, EČP] */
    private function birthNumber(RegistrationRecord $record): array
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

    private function maskedBirthNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $value);

        return strlen($digits) >= 6 ? substr($digits, 0, 6) . '/****' : '****';
    }

    /** @return array<string,string> */
    private function identityFacts(RegistrationRecord $record): array
    {
        return array_filter([
            'title_prefix' => $record->titlePrefix,
            'birth_place' => $record->birthPlace,
            'birth_country_code' => $record->birthCountryCode,
            'citizenship_country_code' => $record->citizenshipCountryCode,
            'sex' => $record->sex,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @return array<string,array{street_line:string,city:string,postal_code:string,country_code:string}> */
    private function addresses(RegistrationRecord $record): array
    {
        $result = [];
        foreach (['residence' => $record->permanentAddress, 'mailing' => $record->contactAddress] as $type => $address) {
            if ($address === null || $address['city'] === null || $address['postal_code'] === null) {
                continue;
            }
            $number = $address['house_number'] ?? '';
            if (($address['orientation_number'] ?? null) !== null) {
                $number .= '/' . $address['orientation_number'];
            }
            $result[$type] = [
                'street_line' => trim(($address['street'] ?? $address['city']) . ' ' . $number),
                'city' => $address['city'],
                'postal_code' => $address['postal_code'],
                'country_code' => $address['country_code'] ?? 'CZ',
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $plan */
    private function insurer(array &$plan, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        if (!HealthInsurers::isValid($code)) {
            $plan['warnings'][] = "Kód zdravotní pojišťovny {$code} v číselníku pojišťoven není, nezapisuje se.";

            return null;
        }

        return $code;
    }

    /** @param array<string,mixed> $plan */
    private function educationInfo(array &$plan, RegistrationRecord $record, ?int $employeeId): void
    {
        if ($record->highestEducationCode === null) {
            return;
        }
        $plan['changes'][] = [
            'field' => 'highest_education_code',
            'label' => 'Nejvyšší dosažené vzdělání (jen informace)',
            'current' => null,
            'imported' => $record->highestEducationCode,
        ];
        $plan['warnings'][] = 'Nejvyšší dosažené vzdělání se v kmenových datech nevede, import ho jen ukazuje.';
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
        $plan['selectable'] = $blocker === null && !in_array($plan['operation'], ['none', 'unsupported'], true);

        return $plan;
    }

    private function factLabel(string $field): string
    {
        return match ($field) {
            'title_prefix' => 'Titul před jménem',
            'birth_place' => 'Místo narození',
            'birth_country_code' => 'Stát narození',
            'citizenship_country_code' => 'Státní občanství',
            'sex' => 'Pohlaví',
            default => $field,
        };
    }

    private function termLabel(string $field): string
    {
        return match ($field) {
            'activity_code' => 'Druh činnosti pro ČSSZ',
            'jmhz_relationship_detail_code' => 'Bližší určení vztahu',
            'cz_isco_code' => 'Kód CZ-ISCO',
            'work_place' => 'Místo výkonu práce',
            'jmhz_workplace_municipality_code' => 'Kód obce pracoviště',
            'jmhz_workplace_country_code' => 'Stát pracoviště',
            default => $field,
        };
    }

    private function addressLabel(string $type): string
    {
        return $type === 'mailing' ? 'Kontaktní adresa' : 'Trvalá adresa';
    }

    /** @param array<string,mixed> $address */
    private function addressText(array $address): string
    {
        return trim(sprintf(
            '%s, %s %s, %s',
            (string) ($address['street_line'] ?? ''),
            (string) ($address['postal_code'] ?? ''),
            (string) ($address['city'] ?? ''),
            (string) ($address['country_code'] ?? ''),
        ));
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $imported
     */
    private static function sameAddress(array $current, array $imported): bool
    {
        $fold = static fn (mixed $value): string => mb_strtolower(
            (string) preg_replace('/\s+/u', '', (string) $value),
        );
        foreach (['street_line', 'city', 'postal_code', 'country_code'] as $key) {
            if ($fold($current[$key] ?? '') !== $fold($imported[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
