<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use DOMDocument;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;

/**
 * Validace datových vět NEMPRI25 a HZUPN20 proti PŘIPNUTÉMU XSD a proti těm
 * pravidlům, která XSD vyjádřit neumí.
 *
 * Tři vrstvy, každá chytá jinou třídu chyby:
 *
 * 1. **Obchodní hranice** — co XSD dovolí, ale ČSSZ odmítne až protokolem
 *    (opravné podání bez čísla rozhodnutí, pracovní volno bez období,
 *    interval práce mimo dobu neschopnosti).
 * 2. **Otisk snapshotu** — XML se přeserializuje z payloadu a porovná bajt po
 *    bajtu. Bez toho by šlo uložit artefakt, který neodpovídá datům, ze
 *    kterých vznikl, a nikdo by to nepoznal.
 * 3. **XSD** — proti souboru z {@see CsszSchemaCatalog}. Katalog ověřuje otisk
 *    SHA-256 vstupního schématu i jeho `baseTypes`; nesouhlasí-li, podání
 *    spadne. To je záměr: validovat proti jinému než ověřenému schématu
 *    znamená tvrdit shodu, kterou nikdo neprokázal.
 *
 * ## Past, kterou odhalilo až XSD
 *
 * `VSZamestnavatel` (NEMPRI) i `variabilniSymbol` (HZUPN) jsou
 * `tns:simpleNType_string` s `length=10`, tedy vzor `[1-9][0-9]*` na deseti
 * znacích. Variabilní symbol tudíž NESMÍ začínat nulou a NELZE ho doplnit
 * zleva nulami do desítky, jak to dělá OZUSPOJ u své vlastní datové věty.
 * Krátký nebo nulou začínající symbol je proto tvrdá chyba s vlastním
 * důvodovým kódem, ne tiché doplnění.
 */
final readonly class SicknessXmlValidator
{
    public function __construct(
        private CsszSchemaCatalog $schemas,
        private NempriXmlSerializer $nempri,
        private HzupnXmlSerializer $hzupn,
    ) {}

    public function validateNempri(NempriXmlPayload $payload, string $xml): void
    {
        $this->osszCode($payload->osszCode);
        $this->variableSymbol($payload->employerVariableSymbol);
        if (preg_match('/^\d{9,10}$/D', $payload->insuredBirthNumber) !== 1) {
            $this->invalid(
                'nempri_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic. '
                . 'NEMPRI ho vyžaduje vždy — bez něj ČSSZ případ nespáruje.',
            );
        }
        foreach ([
            'nempri_insured_first_name_missing' => $payload->insuredFirstName,
            'nempri_insured_last_name_missing' => $payload->insuredLastName,
            'nempri_employer_name_missing' => $payload->employerName,
        ] as $code => $value) {
            if (trim($value) === '') {
                $this->invalid(
                    $code,
                    'Oznámení nemá vyplněné povinné identifikační údaje.',
                );
            }
        }
        if (preg_match('/^[0-9A-Z]{1,3}$/D', $payload->activityCode) !== 1) {
            $this->invalid(
                'nempri_activity_code_invalid',
                'Druh činnosti musí být kód z číselníku ČSSZ (1 až 3 znaky 0-9 a A-Z). '
                . 'Doplňte ho v podmínkách pracovního vztahu.',
            );
        }
        if ($payload->correction && $payload->decisionNumber === null) {
            $this->invalid(
                'nempri_correction_without_decision_number',
                'Opravné podání se páruje podle čísla rozhodnutí. Bez něj by ho ČSSZ '
                . 'zpracovala jako nové podání.',
            );
        }
        if ($payload->benefitKind->requiresDecisionNumber()
            && !$payload->foreignCase
            && $payload->decisionNumber === null
        ) {
            $this->invalid(
                'nempri_decision_number_missing',
                'Chybí číslo rozhodnutí (u eNeschopenky a eOČR číslo z rozhodnutí lékaře). '
                . 'ČSSZ podle něj oznámení páruje s rozhodnutím; bez něj ho nezpracuje. '
                . 'Výjimkou je jen zahraniční případ.',
            );
        }
        if ($payload->benefitKind->hasUnpaidLeaveSection()) {
            $this->unpaidLeave($payload);
        } elseif ($payload->unpaidLeave
            || $payload->unpaidLeaveFrom !== null
            || $payload->unpaidLeaveTo !== null
        ) {
            $this->invalid(
                'nempri_unpaid_leave_not_in_benefit_kind',
                'Potvrzení zaměstnavatele u tohoto druhu dávky prvek pracovního volna '
                . 'bez náhrady příjmu nemá; vyplněné volno by datovou větu shodilo.',
            );
        }
        $this->application($payload);
        if ($payload->decisivePeriod !== null) {
            $this->decisivePeriod($payload->decisivePeriod);
        }
        if ($payload->paymentConnection !== null) {
            $this->paymentConnection($payload->paymentConnection);
        }
        if ($payload->transferredOtherWork !== ($payload->transferredOn !== null)) {
            $this->invalid(
                'nempri_transfer_date_mismatch',
                'Převedení na jinou práci musí mít datum a datum nesmí být bez převedení.',
            );
        }
        $this->exactDate($payload->employmentFrom, 'nempri_date_invalid');
        if ($payload->employmentTo !== null) {
            $this->exactDate($payload->employmentTo, 'nempri_date_invalid');
            if ($payload->employmentTo < $payload->employmentFrom) {
                $this->invalid(
                    'nempri_employment_period_invalid',
                    'Den skončení zaměstnání nesmí předcházet dni jeho vzniku.',
                );
            }
        }
        foreach ([
            $payload->unpaidLeaveFrom,
            $payload->unpaidLeaveTo,
            $payload->childBirthDate,
            $payload->transferredOn,
        ] as $date) {
            if ($date !== null) {
                $this->exactDate($date, 'nempri_date_invalid');
            }
        }
        $this->assertSnapshot(
            $this->nempri->serialize($payload),
            $xml,
            'nempri_xml_snapshot_mismatch',
            'XML byteově neodpovídá zdrojovému payloadu NEMPRI.',
        );
        $this->assertSchema(
            $xml,
            CsszSchemaCatalog::NEMPRI25,
            'nempri_xsd_validation_failed',
            'XML NEMPRI neprošlo připnutým XSD: ',
        );
    }

    /**
     * @param string $incapacityFrom První den dočasné pracovní neschopnosti;
     *        intervaly práce ani návrat do práce nesmí být dřív.
     */
    public function validateHzupn(
        HzupnXmlPayload $payload,
        string $xml,
        string $incapacityFrom,
    ): void {
        $this->osszCode($payload->osszCode);
        $this->variableSymbol($payload->employerVariableSymbol);
        if (!$payload->employerReport) {
            $this->invalid(
                'hzupn_employer_report_required',
                'Hlášení, které podává zaměstnavatel, musí mít příznak hlášení zaměstnavatele. '
                . 'Hlášení osoby dobrovolně nemocensky pojištěné podává pojištěnec sám.',
            );
        }
        if ($payload->personReport) {
            $this->invalid(
                'hzupn_person_report_not_supported',
                'Hlášení osoby dobrovolně nemocensky pojištěné aplikace nesestavuje — '
                . 'není to podání zaměstnavatele.',
            );
        }
        if ($payload->insuredBirthNumber === null
            && $payload->insuredBirthDate === null
        ) {
            $this->invalid(
                'hzupn_insured_identifier_missing',
                'Hlášení musí nést rodné číslo pojištěnce nebo alespoň datum narození, '
                . 'jinak ho ČSSZ nespáruje s neschopenkou.',
            );
        }
        if ($payload->insuredBirthNumber !== null
            && preg_match('/^\d{9,10}$/D', $payload->insuredBirthNumber) !== 1
        ) {
            $this->invalid(
                'hzupn_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic.',
            );
        }
        if ($payload->correction && $payload->confirmationNumber === null) {
            $this->invalid(
                'hzupn_correction_without_confirmation_number',
                'Opravné hlášení se páruje podle čísla rozhodnutí. Bez něj by ho ČSSZ '
                . 'zpracovala jako nové hlášení.',
            );
        }
        // Hlášení se vždy váže k jedné neschopence a ČSSZ ho s ní páruje číslem
        // rozhodnutí. Bez čísla lze podat jen zahraniční případ, jehož
        // rozhodnutí nevydal český lékař.
        if ($payload->confirmationNumber === null && !$payload->foreignCase) {
            $this->invalid(
                'hzupn_confirmation_number_missing',
                'Chybí číslo rozhodnutí o dočasné pracovní neschopnosti. ČSSZ podle něj '
                . 'hlášení páruje s neschopenkou; bez něj ho nezpracuje. Výjimkou je jen '
                . 'zahraniční případ.',
            );
        }
        if ($payload->returnedToWork === true && $payload->returnedOn === null) {
            $this->invalid(
                'hzupn_return_date_missing',
                'Návrat do práce musí mít datum; z něj ČSSZ počítá poslední dávku.',
            );
        }
        if ($payload->returnedToWork === null && $payload->returnedOn !== null) {
            $this->invalid(
                'hzupn_return_date_without_return',
                'Datum návratu do práce nesmí být vyplněné bez odpovědi, zda se '
                . 'zaměstnanec do práce vrátil.',
            );
        }
        // „Ne“ nese důvod (nástup na PPM, skončení zaměstnání) a smí nést
        // i datum, ke kterému důvod nastal — takové hlášení ČSSZ přijímá.
        if ($payload->returnedToWork === false && $payload->returnReason === null) {
            $this->invalid(
                'hzupn_return_reason_missing',
                'Když se zaměstnanec do práce nevrátil, hlášení musí uvést důvod '
                . '(například nástup na peněžitou pomoc v mateřství nebo skončení zaměstnání).',
            );
        }
        $this->exactDate($payload->issuedOn, 'hzupn_date_invalid');
        $this->exactDate($incapacityFrom, 'hzupn_date_invalid');
        if ($payload->returnedOn !== null) {
            $this->exactDate($payload->returnedOn, 'hzupn_date_invalid');
            if ($payload->returnedOn < $incapacityFrom) {
                $this->invalid(
                    'hzupn_return_before_incapacity',
                    'Návrat do práce nemůže předcházet vzniku pracovní neschopnosti.',
                );
            }
        }
        $previousTo = null;
        foreach ($payload->workIntervals as $interval) {
            $this->exactDate($interval['from'], 'hzupn_date_invalid');
            $this->exactDate($interval['to'], 'hzupn_date_invalid');
            if ($interval['to'] < $interval['from']) {
                $this->invalid(
                    'hzupn_work_interval_invalid',
                    'Interval práce v době neschopnosti musí končit nejdřív dnem, kterým začíná.',
                );
            }
            if ($interval['from'] < $incapacityFrom) {
                $this->invalid(
                    'hzupn_work_interval_before_incapacity',
                    'Práce v době neschopnosti nemůže spadat před její vznik.',
                );
            }
            if ($previousTo !== null && $interval['from'] <= $previousTo) {
                $this->invalid(
                    'hzupn_work_intervals_overlap',
                    'Intervaly práce v době neschopnosti se nesmí překrývat ani navazovat '
                    . 've stejný den; ČSSZ z nich počítá vyloučené dny.',
                );
            }
            $previousTo = $interval['to'];
        }
        $this->assertSnapshot(
            $this->hzupn->serialize($payload),
            $xml,
            'hzupn_xml_snapshot_mismatch',
            'XML byteově neodpovídá zdrojovému payloadu HZUPN.',
        );
        $this->assertSchema(
            $xml,
            CsszSchemaCatalog::HZUPN20,
            'hzupn_xsd_validation_failed',
            'XML HZUPN neprošlo připnutým XSD: ',
        );
    }

    /**
     * Úplnost žádosti o dávku u OSE, DLO, OPP a PPM.
     *
     * Hlídá se jen to, bez čeho ČSSZ větu odmítne nebo nespáruje: akce,
     * den, od kterého se žádá, dítě nebo ošetřovaná osoba a u otcovské důvod.
     * Prohlášení zaměstnance (společná domácnost, osamělost …) povinná nejsou —
     * neučiněné prohlášení se do věty nedostane a ČSSZ si ho vyžádá sama.
     */
    private function application(NempriXmlPayload $payload): void
    {
        $kind = $payload->benefitKind;
        $application = $payload->application;
        if (!$kind->hasApplication()) {
            return;
        }
        if ($application === null) {
            $this->invalid(
                'nempri_application_missing',
                'U tohoto druhu dávky věta nese žádost zaměstnance o dávku. '
                . 'Vyplňte údaje z žádosti, kterou vám zaměstnanec předal.',
            );
        }
        if ($kind->hasActions()
            && !$application->actionStart
            && !$application->actionContinuation
            && !$application->actionEnd
        ) {
            $this->invalid(
                'nempri_care_action_missing',
                'Ošetřovné musí nést alespoň jednu akci: vznik, trvání nebo ukončení. '
                . 'Větu bez akce ČSSZ odmítne.',
            );
        }
        $starts = !$kind->hasActions() || $application->actionStart;
        if ($starts && $application->fromDate === null) {
            $this->invalid(
                'nempri_application_from_missing',
                'Žádost musí uvést den, od kterého zaměstnanec o dávku žádá.',
            );
        }
        if ($kind->hasActions() && $application->actionEnd && $application->toDate === null) {
            $this->invalid(
                'nempri_application_to_missing',
                'Při ukončení péče musí žádost uvést den, do kterého zaměstnanec o dávku žádá.',
            );
        }
        foreach ([$application->fromDate, $application->toDate, $application->returnedOn] as $date) {
            if ($date !== null) {
                $this->exactDate($date, 'nempri_date_invalid');
            }
        }
        if ($application->fromDate !== null
            && $application->toDate !== null
            && $application->toDate < $application->fromDate
        ) {
            $this->invalid(
                'nempri_application_period_invalid',
                'Den, do kterého se o dávku žádá, nesmí předcházet dni, od kterého se žádá.',
            );
        }
        $needsPerson = $kind === SicknessBenefitKind::Opp
            || ($kind->hasActions() && $application->actionStart);
        if ($needsPerson && $application->person === null) {
            $this->invalid(
                $kind === SicknessBenefitKind::Opp
                    ? 'nempri_child_missing'
                    : 'nempri_cared_person_missing',
                $kind === SicknessBenefitKind::Opp
                    ? 'Otcovská musí uvést dítě, o které zaměstnanec pečuje.'
                    : 'Žádost musí uvést ošetřovanou osobu.',
            );
        }
        if ($application->person !== null) {
            $this->person($application->person);
        }
        if ($kind === SicknessBenefitKind::Opp && $application->paternityReason === null) {
            $this->invalid(
                'nempri_paternity_reason_missing',
                'Otcovská musí uvést důvod podle žádosti (kód z číselníku ČSSZ).',
            );
        }
        if ($kind === SicknessBenefitKind::Ose && $application->actionStart
            && $application->careReason === null
        ) {
            $this->invalid(
                'nempri_care_reason_missing',
                'Žádost o ošetřovné musí uvést důvod péče: onemocnění, karanténa, '
                . 'nemožnost péče o dítě, nebo uzavření školy či zařízení.',
            );
        }
        if ($application->careReason !== null
            && !in_array($application->careReason, NempriBenefitApplication::CARE_REASONS, true)
        ) {
            $this->invalid(
                'nempri_care_reason_invalid',
                'Důvod péče není z nabízeného seznamu.',
            );
        }
        if ($application->careReason === NempriBenefitApplication::CARE_REASON_SCHOOL_CLOSED
            && ($application->schoolName === null || trim($application->schoolName) === '')
        ) {
            $this->invalid(
                'nempri_school_name_missing',
                'U uzavřené školy nebo zařízení musí žádost uvést jeho název.',
            );
        }
        foreach ([
            $application->relationshipCode,
            $application->paternityReason,
            $application->maternityCareReason,
        ] as $code) {
            if ($code !== null && preg_match('/^[0-9A-Z]{1,3}$/D', $code) !== 1) {
                $this->invalid(
                    'nempri_codebook_value_invalid',
                    'Kód z číselníku ČSSZ má 1 až 3 znaky 0-9 a A-Z.',
                );
            }
        }
        if ($application->childOrder !== null
            && ($application->childOrder < 1 || $application->childOrder > 10)
        ) {
            $this->invalid(
                'nempri_child_order_invalid',
                'Pořadí dítěte musí být 1 až 10.',
            );
        }
        foreach ([$application->careDays, $application->workDays] as $periods) {
            foreach ($periods as $period) {
                $this->exactDate($period['from'], 'nempri_date_invalid');
                $this->exactDate($period['to'], 'nempri_date_invalid');
                if ($period['to'] < $period['from']) {
                    $this->invalid(
                        'nempri_period_invalid',
                        'Období péče nebo práce musí končit nejdřív dnem, kterým začíná.',
                    );
                }
            }
        }
    }

    private function person(NempriPerson $person): void
    {
        if (trim($person->firstName) === '' || trim($person->lastName) === '') {
            $this->invalid(
                'nempri_person_name_missing',
                'Dítě nebo ošetřovaná osoba musí mít jméno i příjmení.',
            );
        }
        if ($person->birthNumber !== null
            && preg_match('/^\d{9,10}$/D', $person->birthNumber) !== 1
        ) {
            $this->invalid(
                'nempri_person_birth_number_invalid',
                'Rodné číslo dítěte nebo ošetřované osoby musí mít 9 nebo 10 číslic.',
            );
        }
        if ($person->birthNumber === null && $person->birthDate === null) {
            $this->invalid(
                'nempri_person_identifier_missing',
                'Dítě nebo ošetřovaná osoba musí mít rodné číslo nebo alespoň datum narození, '
                . 'jinak ji ČSSZ neztotožní.',
            );
        }
        if ($person->birthDate !== null) {
            $this->exactDate($person->birthDate, 'nempri_date_invalid');
        }
    }

    private function decisivePeriod(NempriDecisivePeriod $period): void
    {
        $this->exactDate($period->from, 'nempri_date_invalid');
        $this->exactDate($period->to, 'nempri_date_invalid');
        if ($period->to < $period->from) {
            $this->invalid(
                'nempri_decisive_period_invalid',
                'Rozhodné období musí končit nejdřív dnem, kterým začíná.',
            );
        }
        if (count($period->months) > 12) {
            $this->invalid(
                'nempri_decisive_period_too_long',
                'Rozhodné období nese nejvýš 12 kalendářních měsíců.',
            );
        }
        foreach ($period->months as $month) {
            if ($month->excludedDays < 0 || $month->excludedDays > 31
                || $month->countableIncomeMinor < 0
            ) {
                $this->invalid(
                    'nempri_decisive_month_invalid',
                    'Měsíc rozhodného období ' . $month->period()
                    . ' má neplatný příjem nebo počet vyloučených dnů.',
                );
            }
        }
    }

    private function paymentConnection(NempriPaymentConnection $connection): void
    {
        $valid = match ($connection->kind) {
            NempriPaymentConnection::KIND_ACCOUNT_CZ =>
                preg_match('/^\d{2,10}$/D', (string) $connection->accountNumber) === 1
                && preg_match('/^\d{4}$/D', (string) $connection->bankCode) === 1
                && ($connection->accountPrefix === null
                    || preg_match('/^\d{1,6}$/D', $connection->accountPrefix) === 1),
            NempriPaymentConnection::KIND_ACCOUNT_FOREIGN =>
                preg_match('/^[A-Z]{2}[0-9A-Z]{2,32}$/D', (string) $connection->iban) === 1
                && preg_match('/^[0-9A-Z]{1,3}$/D', (string) $connection->countryCode) === 1,
            NempriPaymentConnection::KIND_ADDRESS =>
                trim((string) $connection->city) !== ''
                && preg_match('/^[0-9A-Za-z]{1,4}$/D', (string) $connection->houseNumber) === 1
                && preg_match('/^[0-9A-Za-z]{1,5}$/D', (string) $connection->postalCode) === 1,
            default => false,
        };
        if (!$valid) {
            $this->invalid(
                'nempri_payment_connection_invalid',
                'Způsob výplaty mzdy se nedá zapsat do věty: účet musí být platný český '
                . 'účet nebo IBAN, adresa musí mít obec, číslo popisné a PSČ. '
                . 'Opravte ho ve výplatním profilu zaměstnance.',
            );
        }
    }

    private function unpaidLeave(NempriXmlPayload $payload): void
    {
        if ($payload->unpaidLeave && $payload->unpaidLeaveFrom === null) {
            $this->invalid(
                'nempri_unpaid_leave_period_missing',
                'Pracovní volno bez náhrady příjmu musí mít den, od kterého trvalo — '
                . 'z něj se posuzují vyloučené dny.',
            );
        }
        if (!$payload->unpaidLeave
            && ($payload->unpaidLeaveFrom !== null || $payload->unpaidLeaveTo !== null)
        ) {
            $this->invalid(
                'nempri_unpaid_leave_period_without_flag',
                'Období pracovního volna bez náhrady příjmu nesmí být vyplněné, '
                . 'když volno nebylo čerpáno.',
            );
        }
        if ($payload->unpaidLeaveFrom !== null
            && $payload->unpaidLeaveTo !== null
            && $payload->unpaidLeaveTo < $payload->unpaidLeaveFrom
        ) {
            $this->invalid(
                'nempri_unpaid_leave_period_invalid',
                'Konec pracovního volna bez náhrady příjmu nesmí předcházet jeho začátku.',
            );
        }
    }

    private function osszCode(int $code): void
    {
        if ($code < 100 || $code > 999) {
            $this->invalid(
                'sickness_ossz_code_invalid',
                'Kód OSSZ musí být tříciferný podle číselníku pracovišť ČSSZ. '
                . 'Doplňte ho v Nastavení mezd → Zaměstnavatel.',
            );
        }
    }

    private function variableSymbol(string $symbol): void
    {
        if (preg_match('/^[1-9][0-9]{9}$/D', $symbol) !== 1) {
            $this->invalid(
                'sickness_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít deset číslic a nesmí začínat nulou — '
                . 'obě XSD ho mají jako typ N s pevnou délkou 10. Doplnit ho zleva nulami nelze; '
                . 'opravte ho v Nastavení mezd → Účtárny.',
            );
        }
    }

    private function assertSnapshot(
        string $expected,
        string $actual,
        string $code,
        string $message,
    ): void {
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
            $this->invalid($code, $message);
        }
    }

    private function assertSchema(
        string $xml,
        string $documentType,
        string $code,
        string $messagePrefix,
    ): void {
        try {
            $schema = $this->schemas->schemaFor($documentType);
        } catch (\RuntimeException $exception) {
            $this->invalid(
                'sickness_schema_integrity_failed',
                'Připnutý XSD balíček ČSSZ chybí nebo má jiný otisk, než jaký byl ověřen: '
                . $exception->getMessage(),
            );
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            );
            $this->invalid(
                $code,
                $messagePrefix . implode('; ', array_unique($messages)),
            );
        }
    }

    private function exactDate(string $value, string $code): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                $code,
                'Datum v podání musí být ve tvaru RRRR-MM-DD.',
            );
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new SicknessException($code, $message);
    }
}
