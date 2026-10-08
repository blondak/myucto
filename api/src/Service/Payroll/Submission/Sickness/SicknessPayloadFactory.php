<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Z případu dávky, faktů pracovního vztahu a identity osoby sestaví obsah
 * datové věty NEMPRI nebo HZUPN.
 *
 * Třída je čistá — nesahá do databáze ani nic nedešifruje. Všechno, co se
 * musí načíst nebo odhalit (rodné číslo dítěte, účet, převzaté mzdy), dostane
 * hotové. Díky tomu jde každé pravidlo mapování otestovat bez databáze.
 */
final readonly class SicknessPayloadFactory
{
    /**
     * @param array<string,mixed> $row řádek `payroll_sickness_cases` (+ `work_days`)
     * @param array<string,mixed> $context fakta pracovního vztahu ke dni události
     * @param array<string,mixed> $identity citlivý snapshot identity osoby
     */
    public function nempri(
        array $row,
        SicknessBenefitKind $kind,
        array $context,
        array $identity,
        string $payloadVersion,
        string $productName,
        string $productVersion,
        ?NempriPerson $person = null,
        ?NempriDecisivePeriod $decisivePeriod = null,
        ?NempriPaymentConnection $paymentConnection = null,
        ?array $insuredContact = null,
    ): NempriXmlPayload {
        $worked = (bool) $row['worked_on_decisive_day'];

        return new NempriXmlPayload(
            benefitKind: $kind,
            osszCode: (int) $row['ossz_code'],
            correction: (bool) $row['correction'],
            decisionNumber: self::decisionNumber($row['decision_number'] ?? null),
            foreignCase: self::nempriForeignCase($row),
            insuredFirstName: self::requiredIdentity($identity, 'first_name'),
            insuredLastName: self::requiredIdentity($identity, 'last_name'),
            insuredBirthNumber: self::requireBirthNumber($identity),
            insuredPhone: self::nullableText($insuredContact['phone'] ?? null),
            insuredEmail: self::nullableText($insuredContact['email'] ?? null),
            employerVariableSymbol: self::variableSymbol($context),
            employerIdentificationNumber: self::nullableText(
                $context['employer_business_id'] ?? null,
            ),
            employerName: (string) ($context['employer_name'] ?? ''),
            employmentFrom: self::employmentFrom($context),
            employmentTo: self::nullableText($context['end_date'] ?? null),
            activityCode: self::activityCode($context),
            workedOnDecisiveDay: $worked,
            hoursWorked: self::decimal($row['hours_worked'] ?? null),
            // Pracovní doba patří k potvrzení jen s `pracoval=true`; u případu
            // ji drží řádek vždy (předvyplnění), do věty ale nepatří.
            dailyWorkingHours: $worked
                ? self::decimal($row['daily_working_hours'] ?? null)
                : null,
            smallScopeIncomeMinor: ($row['small_scope_income_minor'] ?? null) === null
                ? null
                : (int) $row['small_scope_income_minor'],
            receivesPension: (bool) $row['receives_pension'],
            pensionKind: self::code($row['pension_kind'] ?? null),
            isStudent: (bool) $row['is_student'],
            withinSchoolHolidays: self::nullableBool($row['within_school_holidays'] ?? null),
            firstEmploymentFreeTime: (bool) $row['first_employment_free_time'],
            unpaidLeave: (bool) $row['unpaid_leave'],
            unpaidLeaveFrom: self::nullableText($row['unpaid_leave_from'] ?? null),
            unpaidLeaveTo: self::nullableText($row['unpaid_leave_to'] ?? null),
            startsMaternity: self::nullableBool($row['starts_maternity'] ?? null),
            childBirthDate: self::nullableText($row['child_birth_date'] ?? null),
            transferredOtherWork: (bool) $row['transferred_other_work'],
            transferredOn: self::nullableText($row['transferred_on'] ?? null),
            enforcement: (bool) $row['enforcement'],
            insolvency: (bool) $row['insolvency'],
            additionalNote: self::nullableText($row['additional_note'] ?? null),
            productName: $productName,
            productVersion: $productVersion,
            payloadVersion: $payloadVersion,
            contactWorkerName: self::nullableText($row['contact_worker_name'] ?? null),
            contactWorkerPhone: self::nullableText($row['contact_worker_phone'] ?? null),
            contactWorkerEmail: self::nullableText($row['contact_worker_email'] ?? null),
            application: $kind->hasApplication()
                ? $this->application($row, $person, $kind)
                : null,
            decisivePeriod: $decisivePeriod,
            paymentConnection: $paymentConnection,
        );
    }

    /**
     * Žádost o dávku, jak ji zaměstnanec předal.
     *
     * Dny práce (`seznamPraceVeDnech`) jsou tytéž intervaly, které u nemocenského
     * jdou do HZUPN — dny, kdy zaměstnanec v období dávky přesto pracoval.
     *
     * ## Neučiněné prohlášení je „NE“
     *
     * Zásady NEMPRI ukládají zaměstnavateli žádost předat, i když v ní
     * zaměstnanec některé prohlášení nevyplnil — a takové prohlášení uvést
     * jako „NE“. U ošetřovného se proto prohlášení o společné domácnosti,
     * osamělosti, péči o dítě do 16 let, nároku jiné osoby na PPM a osobní
     * péči posílají vždy; nevyplněné jako `false`. U dlouhodobého ošetřovného
     * stejně střídání, nárok jiné osoby na PPM, společná domácnost a osobní
     * péče. Přesně tak je nesou přijatá podání jiných mzdových programů.
     *
     * @param array<string,mixed> $row
     */
    public function application(
        array $row,
        ?NempriPerson $person,
        ?SicknessBenefitKind $kind = null,
    ): NempriBenefitApplication {
        $order = $row['child_order'] ?? null;
        $start = (bool) ($row['action_start'] ?? true);
        $duration = (bool) ($row['action_continuation'] ?? false)
            || (bool) ($row['action_end'] ?? false);
        // Neučiněné prohlášení je u ošetřovného i dlouhodobého ošetřovného
        // „NE“, ale jen u prvků, které DV NEMPRI25 pro danou akci a druh
        // vyžaduje: prohlášení vzniku při vzniku, `pecovalOsobne` při trvání
        // nebo ukončení. Prvek, který druh dávky nemá, zůstane prázdný.
        $care = $kind !== null && $kind->hasActions();
        $declared = static fn (string $key, bool $applies): ?bool => $care
            ? ($applies ? (self::nullableBool($row[$key] ?? null) ?? false) : null)
            : self::nullableBool($row[$key] ?? null);
        $oseStart = $start && $kind === SicknessBenefitKind::Ose;
        $otherClaim = $declared('other_maternity_claim', $start);
        $workedLastDay = self::nullableBool($row['worked_last_day'] ?? null);
        // U ošetřovného patří hodiny posledního dne jen k `pracovalPoslDenPD`
        // = true (DV NEMPRI25, jinak zakázané); DLO a otcovská je vážou
        // na návrat do práce.
        $lastDayHours = $kind !== SicknessBenefitKind::Ose || $workedLastDay === true;
        $maternityCareReason = self::code($row['maternity_care_reason'] ?? null);
        // Při běžném nástupu na PPM se dítě neuvádí, jen při převzetí dítěte do
        // péče (Všeobecné zásady NEMPRI, Postupy zaměstnavatelů bod 2). Dítě
        // vyplněné u případu se proto bez důvodu převzetí do věty nedostane.
        if ($kind === SicknessBenefitKind::Ppm && $maternityCareReason === null) {
            $person = null;
        }

        return new NempriBenefitApplication(
            actionStart: (bool) ($row['action_start'] ?? true),
            actionContinuation: (bool) ($row['action_continuation'] ?? false),
            actionEnd: (bool) ($row['action_end'] ?? false),
            // Žádá-li zaměstnanec o dávku za celé trvání události, jsou hranice
            // žádosti tytéž jako hranice případu; jinak je účetní přepíše.
            fromDate: self::nullableText($row['application_from'] ?? null)
                ?? self::nullableText($row['incapacity_from'] ?? null),
            toDate: self::nullableText($row['application_to'] ?? null)
                ?? self::nullableText($row['incapacity_to'] ?? null),
            person: $person,
            careReason: self::nullableText($row['care_reason'] ?? null),
            schoolName: self::nullableText($row['school_name'] ?? null),
            schoolBusinessId: self::nullableText($row['school_business_id'] ?? null),
            sharedHousehold: $declared('shared_household', $start),
            loneCaregiver: $declared('lone_caregiver', $oseStart || !$care),
            childUnder16: $declared('child_under_16', $oseStart || !$care),
            otherMaternityClaim: $otherClaim,
            otherParentalClaim: $kind === SicknessBenefitKind::Ose && $otherClaim === true
                ? (self::nullableBool($row['other_parental_claim'] ?? null) ?? false)
                : self::nullableBool($row['other_parental_claim'] ?? null),
            otherPersonS57: $care && $otherClaim === true
                ? (self::nullableBool($row['other_person_s57'] ?? null) ?? false)
                : self::nullableBool($row['other_person_s57'] ?? null),
            caredPersonally: $declared('cared_personally', $duration),
            careDays: self::periods($row['care_days'] ?? null),
            relationshipCode: self::code($row['relationship_code'] ?? null),
            alternation: $declared('alternation', ($start && $kind === SicknessBenefitKind::Dlo) || !$care),
            paternityReason: self::code($row['paternity_reason'] ?? null),
            maternityCareReason: $maternityCareReason,
            childOrder: $order === null || $order === '' ? null : (int) $order,
            workedLastDay: $workedLastDay,
            shiftHoursLastDay: $lastDayHours ? self::decimal($row['shift_hours_last_day'] ?? null) : null,
            hoursWorkedLastDay: $lastDayHours ? self::decimal($row['hours_worked_last_day'] ?? null) : null,
            plannedShifts: self::nullableBool($row['planned_shifts'] ?? null),
            plannedShiftsWorked: self::nullableBool($row['planned_shifts_worked'] ?? null),
            returnedOn: self::nullableText($row['returned_on'] ?? null),
            workDays: self::periods($row['work_days'] ?? null),
            hasLeave: self::nullableBool($row['dlo_has_leave'] ?? null),
            leavePeriods: self::periods($row['dlo_leave_periods'] ?? null),
            shiftSchedule: self::periods($row['dlo_shift_schedule'] ?? null),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @param array<string,mixed> $identity
     */
    public function hzupn(
        array $row,
        array $context,
        array $identity,
        string $payloadVersion,
        string $productName,
        string $productVersion,
    ): HzupnXmlPayload {
        $returnedToWork = self::nullableBool($row['returned_to_work'] ?? null);
        $issuedOn = self::nullableText($row['issued_on'] ?? null);
        if ($issuedOn === null) {
            throw new SicknessException(
                'hzupn_issue_date_missing',
                'Hlášení musí nést den vystavení (dokument/datumVystaveni). Doplňte ho u případu.',
            );
        }

        return new HzupnXmlPayload(
            // Podání zaměstnavatele. Hlášení osoby dobrovolně nemocensky
            // pojištěné je tentýž tiskopis, ale podává ho pojištěnec sám —
            // aplikace ho za něj sestavovat nesmí.
            employerReport: true,
            personReport: false,
            foreignCase: self::hzupnForeignCase($row),
            confirmationNumber: self::decisionNumber($row['decision_number'] ?? null),
            osszCode: (int) $row['ossz_code'],
            osszName: CsszWorkplaceCatalog::nameFor((int) $row['ossz_code']),
            issuedOn: $issuedOn,
            correction: (bool) $row['correction'],
            insuredFirstName: self::requiredIdentity($identity, 'first_name'),
            insuredLastName: self::requiredIdentity($identity, 'last_name'),
            insuredTitle: null,
            insuredBirthNumber: \MyInvoice\Service\Payroll\CzechBirthNumber::forSubmission(
                $identity['identifiers']['birth_number']
                    ?? $identity['identifiers']['ecp']
                    ?? null,
            ),
            insuredBirthDate: self::nullableText(
                $identity['identity']['birth_date'] ?? null,
            ),
            employerName: (string) ($context['employer_name'] ?? ''),
            employerIdentificationNumber: self::nullableText(
                $context['employer_business_id'] ?? null,
            ),
            employerVariableSymbol: self::variableSymbol($context),
            returnedToWork: $returnedToWork,
            returnReason: $returnedToWork === false
                ? self::nullableText($row['return_reason'] ?? null)
                : null,
            // Datum a hodiny posledního dne patří podle DV HZUPN20 jen
            // k návratu do práce. Řádek případu `returned_on` drží i u „ne“
            // (z něj běží lhůta hlášení), do věty ale nejde.
            returnedOn: $returnedToWork === true
                ? self::nullableText($row['returned_on'] ?? null)
                : null,
            hoursWorkedLastDay: $returnedToWork === true
                ? self::decimal($row['hours_worked_last_day'] ?? null)
                : null,
            shiftHoursLastDay: $returnedToWork === true
                ? self::decimal($row['shift_hours_last_day'] ?? null)
                : null,
            workIntervals: self::periods($row['work_days'] ?? null),
            productName: $productName,
            productVersion: $productVersion,
            payloadVersion: $payloadVersion,
            slovakCase: self::slovakCase($row),
        );
    }

    /**
     * `zahranicni` ve větě NEMPRI25: „true" pro případ mimo Česko, tedy i pro
     * slovenský (DV NEMPRI25, element Zahraniční).
     *
     * @param array<string,mixed> $row
     */
    public static function nempriForeignCase(array $row): bool
    {
        return (bool) ($row['foreign_case'] ?? false) || self::slovakCase($row);
    }

    /**
     * `zahranicni` ve větě HZUPN20: „A" jen pro zahraničí mimo Česko a Slovensko,
     * slovenský případ je jako český „N" (DV HZUPN20, element Zahraniční).
     *
     * @param array<string,mixed> $row
     */
    public static function hzupnForeignCase(array $row): bool
    {
        return (bool) ($row['foreign_case'] ?? false);
    }

    /** @param array<string,mixed> $row */
    private static function slovakCase(array $row): bool
    {
        return (bool) ($row['slovak_case'] ?? false);
    }

    /**
     * `zamestnanOd` je den, kdy zaměstnání SKUTEČNĚ vzniklo.
     *
     * `start_date` je sjednaný den nástupu ze smlouvy; nastoupil-li zaměstnanec
     * jindy, drží skutečný den `actual_start_date` a jen ten zakládá účast na
     * nemocenském pojištění (§ 10 odst. 1 zák. č. 187/2006 Sb.). Stejně to čte
     * evidenční list i registrace zaměstnance.
     *
     * @param array<string,mixed> $context
     */
    public static function employmentFrom(array $context): string
    {
        return self::nullableText($context['actual_start_date'] ?? null)
            ?? (string) ($context['start_date'] ?? '');
    }

    /** @param array<string,mixed> $context */
    private static function variableSymbol(array $context): string
    {
        $raw = $context['employer_variable_symbol'] ?? null;
        $digits = preg_replace('/\D/', '', is_string($raw) ? $raw : '') ?? '';
        if ($digits === '') {
            throw new SicknessException(
                'sickness_variable_symbol_missing',
                'Mzdová účtárna pracovního vztahu nemá vyplněný variabilní symbol ČSSZ. '
                . 'Doplňte ho v Nastavení mezd → Zaměstnavatel u účtárny vztahu '
                . 'a podání připravte znovu.',
            );
        }

        // Doplnit zleva nulami NELZE: obě XSD mají variabilní symbol jako typ N
        // s pevnou délkou 10 a vzorem `[1-9][0-9]*`, takže nula na začátku je
        // tvrdá chyba. Symbol se proto předává tak, jak je, a neplatný odhalí
        // validátor s vlastním důvodovým kódem.
        return $digits;
    }

    /** @param array<string,mixed> $context */
    private static function activityCode(array $context): string
    {
        $code = $context['activity_code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            throw new SicknessException(
                'nempri_activity_code_missing',
                'Pracovní vztah nemá ke dni vzniku sociální události vyplněný druh činnosti. '
                . 'NEMPRI ho vyžaduje (zamestnani/druhCinnosti) — doplňte ho v podmínkách vztahu.',
            );
        }

        return strtoupper(trim($code));
    }

    /** @param array<string,mixed> $identity */
    private static function requiredIdentity(array $identity, string $key): string
    {
        $value = $identity['identity'][$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new SicknessException(
                'sickness_identity_incomplete',
                'Osoba nemá k rozhodnému dni doplněné jméno, příjmení a datum narození. '
                . 'Bez nich ČSSZ podání nepřijme.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $identity */
    private static function requireBirthNumber(array $identity): string
    {
        $value = $identity['identifiers']['birth_number']
            ?? $identity['identifiers']['ecp']
            ?? null;
        if (!is_string($value) || $value === '') {
            throw new SicknessException(
                'nempri_birth_number_missing',
                'NEMPRI vyžaduje rodné číslo nebo evidenční číslo pojištěnce '
                . '(pojistenec/rodneCislo je povinný prvek). Doplňte ho na kartě osoby.',
            );
        }

        // Karta osoby drží RČ jako RRMMDD/XXXX, schéma ČSSZ bere jen číslice.
        return (string) \MyInvoice\Service\Payroll\CzechBirthNumber::forSubmission($value);
    }

    /** @return list<array{from:string,to:string}> */
    private static function periods(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }
        $periods = [];
        foreach ($value as $item) {
            if (is_array($item) && is_string($item['from'] ?? null) && is_string($item['to'] ?? null)) {
                $periods[] = ['from' => $item['from'], 'to' => $item['to']];
            }
        }

        return $periods;
    }

    /** Písmena v čísle rozhodnutí (N, Z, M, T, L) jsou velká. */
    private static function decisionNumber(mixed $value): ?string
    {
        $text = self::nullableText($value);

        return $text === null ? null : strtoupper($text);
    }

    private static function code(mixed $value): ?string
    {
        $text = self::nullableText($value);

        return $text === null ? null : strtoupper($text);
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }

    /**
     * Desetinné číslo pro XSD. DECIMAL z MariaDB přichází jako `8.00`; pro
     * `xs:double` je to platná hodnota, takže se jen ořízne prázdný řetězec.
     */
    private static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
