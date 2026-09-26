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
    ): NempriXmlPayload {
        return new NempriXmlPayload(
            benefitKind: $kind,
            osszCode: (int) $row['ossz_code'],
            correction: (bool) $row['correction'],
            decisionNumber: self::nullableText($row['decision_number'] ?? null),
            foreignCase: (bool) $row['foreign_case'],
            insuredFirstName: self::requiredIdentity($identity, 'first_name'),
            insuredLastName: self::requiredIdentity($identity, 'last_name'),
            insuredBirthNumber: self::requireBirthNumber($identity),
            insuredPhone: null,
            insuredEmail: null,
            employerVariableSymbol: self::variableSymbol($context),
            employerIdentificationNumber: self::nullableText(
                $context['employer_business_id'] ?? null,
            ),
            employerName: (string) ($context['employer_name'] ?? ''),
            employmentFrom: self::employmentFrom($context),
            employmentTo: self::nullableText($context['end_date'] ?? null),
            activityCode: self::activityCode($context),
            workedOnDecisiveDay: (bool) $row['worked_on_decisive_day'],
            hoursWorked: self::decimal($row['hours_worked'] ?? null),
            dailyWorkingHours: self::decimal($row['daily_working_hours'] ?? null),
            smallScopeIncomeMinor: ($row['small_scope_income_minor'] ?? null) === null
                ? null
                : (int) $row['small_scope_income_minor'],
            receivesPension: (bool) $row['receives_pension'],
            pensionKind: self::nullableText($row['pension_kind'] ?? null),
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
     * péči posílají vždy; nevyplněné jako `false`. Přesně tak je nesou
     * přijatá podání jiných mzdových programů.
     *
     * @param array<string,mixed> $row
     */
    public function application(
        array $row,
        ?NempriPerson $person,
        ?SicknessBenefitKind $kind = null,
    ): NempriBenefitApplication {
        $order = $row['child_order'] ?? null;
        $declared = static fn (string $key): ?bool => $kind === SicknessBenefitKind::Ose
            ? (self::nullableBool($row[$key] ?? null) ?? false)
            : self::nullableBool($row[$key] ?? null);

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
            sharedHousehold: $declared('shared_household'),
            loneCaregiver: $declared('lone_caregiver'),
            childUnder16: $declared('child_under_16'),
            otherMaternityClaim: $declared('other_maternity_claim'),
            otherParentalClaim: self::nullableBool($row['other_parental_claim'] ?? null),
            otherPersonS57: self::nullableBool($row['other_person_s57'] ?? null),
            caredPersonally: $declared('cared_personally'),
            careDays: self::periods($row['care_days'] ?? null),
            relationshipCode: self::code($row['relationship_code'] ?? null),
            alternation: self::nullableBool($row['alternation'] ?? null),
            paternityReason: self::code($row['paternity_reason'] ?? null),
            maternityCareReason: self::code($row['maternity_care_reason'] ?? null),
            childOrder: $order === null || $order === '' ? null : (int) $order,
            workedLastDay: self::nullableBool($row['worked_last_day'] ?? null),
            shiftHoursLastDay: self::decimal($row['shift_hours_last_day'] ?? null),
            hoursWorkedLastDay: self::decimal($row['hours_worked_last_day'] ?? null),
            plannedShifts: self::nullableBool($row['planned_shifts'] ?? null),
            plannedShiftsWorked: self::nullableBool($row['planned_shifts_worked'] ?? null),
            returnedOn: self::nullableText($row['returned_on'] ?? null),
            workDays: self::periods($row['work_days'] ?? null),
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
            foreignCase: (bool) $row['foreign_case'],
            confirmationNumber: self::nullableText($row['decision_number'] ?? null),
            osszCode: (int) $row['ossz_code'],
            osszName: null,
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
            returnedToWork: self::nullableBool($row['returned_to_work'] ?? null),
            returnReason: self::nullableText($row['return_reason'] ?? null),
            returnedOn: self::nullableText($row['returned_on'] ?? null),
            hoursWorkedLastDay: self::decimal($row['hours_worked_last_day'] ?? null),
            shiftHoursLastDay: self::decimal($row['shift_hours_last_day'] ?? null),
            workIntervals: self::periods($row['work_days'] ?? null),
            productName: $productName,
            productVersion: $productVersion,
            payloadVersion: $payloadVersion,
        );
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
                'Firma nemá vyplněný variabilní symbol ČSSZ. Doplňte ho v Nastavení → Firma '
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
