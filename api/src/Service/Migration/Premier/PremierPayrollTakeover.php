<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Geo\CountryNameMatcher;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEvidencePeriod;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPayoutAccount;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;

/**
 * Překlad vztahu z PREMIER ({@see PremierPayroll}) do kanonické podoby převzatých mezd
 * ({@see PayrollTakeoverRecord}). Nic nečte ani nezapisuje.
 *
 * PREMIER zatím plní jen část kanonické podoby (identita, karta, jeden výplatní účet,
 * zákonná evidence, skončení); ostatní pole zůstávají prázdná a zápis je přeskočí.
 */
final class PremierPayrollTakeover
{
    public const LABEL = 'PREMIER';
    private const NOTE = 'Převzato z PREMIER: ';

    /**
     * Pravidla zápisu převodu z PREMIER (viz {@see PayrollTakeoverPolicy}); kde se liší
     * od PAMICA, je to vědomě zachované dosavadní chování převodu z PREMIER.
     */
    public static function policy(): PayrollTakeoverPolicy
    {
        return new PayrollTakeoverPolicy(
            sourceKey: 'premier',
            label: self::LABEL,
            strict: false,
            // Kontaktní adresa (`PER_ADR`) se doplňuje vedle trvalé stejně jako u PAMICA.
            addressesPerType: true,
            birthSurnameOnCurrentVersion: false,
            verifyPayoutAccounts: false,
            countPlannedTermination: false,
            ignoreEndBeforeStart: true,
            rewriteOwnOpenings: true,
            checklistToleratesRuntime: true,
        );
    }

    /**
     * @param array<string,mixed> $relation vztah z {@see PremierPayroll::$relations}
     * @param string $until poslední den převáděného období (prohlášení po měsících mezd do něj)
     */
    public static function record(array $relation, string $until, ?CountryNameMatcher $countries = null): PayrollTakeoverRecord
    {
        $start = (string) $relation['start'];
        // Zákonná evidence má účinnost po celých měsících (čte se k prvnímu dni měsíce);
        // nástup uprostřed měsíce by uložení celé evidence odmítl.
        $from = substr($start, 0, 7) . '-01';
        $surname = $relation['birth_surname'];
        $declarations = [];
        foreach (self::declarations($relation, $until) as $run) {
            $declarations[] = new PayrollTakeoverEvidencePeriod(
                $run['status'],
                $run['from'],
                $run['to'],
                'premier:mzdy:' . $run['period'],
                self::NOTE . ($run['status'] === 'signed' ? 'podepsané' : 'nepodepsané') . ' prohlášení poplatníka od mzdy za ' . $run['period'] . '.',
            );
        }
        $account = $relation['account'];
        $person = new PayrollTakeoverPerson(
            key: (string) $relation['person_key'],
            identity: ['birth_date' => $relation['birth_date']] + (array) $relation['identity'],
            // Rodné příjmení shodné s příjmením PREMIER vyplňuje i u osob bez změny jména.
            birthSurname: is_string($surname) && mb_strtolower($surname) !== mb_strtolower((string) $relation['last_name']) ? $surname : null,
            residence: self::address(is_array($relation['residence']) ? $relation['residence'] : null, $countries),
            mailing: self::address(is_array($relation['mailing'] ?? null) ? $relation['mailing'] : null, $countries),
            email: is_string($relation['email']) ? $relation['email'] : null,
            phone: is_string($relation['phone']) ? $relation['phone'] : null,
            payoutAccounts: is_array($account) ? [new PayrollTakeoverPayoutAccount($account['account'], $account['bank_code'])] : [],
            taxResidence: $relation['non_resident'] === true
                ? new PayrollTakeoverEvidencePeriod('non-resident', $from)
                : new PayrollTakeoverEvidencePeriod('czech-resident', $from, null, 'premier:per_main:rezident',
                    self::NOTE . 'osoba není v PREMIER vedená jako daňový nerezident.'),
            healthCoverage: is_string($relation['insurer_code'])
                ? new PayrollTakeoverEvidencePeriod($relation['insurer_code'], $from, null, null,
                    self::NOTE . 'zdravotní pojišťovna ' . $relation['insurer_code'] . '.')
                : null,
            socialJurisdiction: $relation['foreign_legislation'] === true
                ? new PayrollTakeoverEvidencePeriod('foreign', $from)
                : new PayrollTakeoverEvidencePeriod('czech', $from, null, null, self::NOTE . 'osoba nepodléhá v PREMIER cizím právním předpisům.'),
            taxDeclarations: $declarations,
            healthCoverageHistory: array_map(
                static fn (array $run): PayrollTakeoverEvidencePeriod => new PayrollTakeoverEvidencePeriod($run['code'], $run['from'], $run['to'], $run['reference'],
                    self::NOTE . 'zdravotní pojišťovna ' . $run['code'] . ' podle oznámení pojišťovně.'),
                (array) ($relation['insurer_history'] ?? []),
            ),
        );
        $jmhz = $relation['registry']['jmhz'] ?? null;
        $identifiers = self::identifiers($relation);
        $employment = new PayrollTakeoverEmployment(
            personalNumber: (string) $relation['personal_number'],
            relationKey: (string) $relation['key'],
            start: $start,
            end: is_string($relation['end']) ? $relation['end'] : null,
            workplace: is_array($jmhz) && is_string($jmhz['municipality_code']) && is_string($jmhz['municipality']) && is_string($jmhz['country'])
                ? ['work_place' => mb_substr($jmhz['municipality'], 0, 255), 'municipality_code' => $jmhz['municipality_code'],
                    'country_code' => $jmhz['country'], 'regular_workplace' => null]
                : null,
            czIsco: is_string($relation['registry']['cz_isco'] ?? null) ? $relation['registry']['cz_isco'] : null,
            oic: $identifiers['confirmed'] ? $identifiers['oic'] : null,
            idPpv: $identifiers['confirmed'] ? $identifiers['id_ppv'] : null,
            checklistNotes: self::checklistNotes($relation, $until),
        );
        return new PayrollTakeoverRecord($person, $employment);
    }

    /**
     * OIČ a ID pracovněprávního vztahu. PREMIER je nese ve formuláři JMHZ za vztah
     * (`X10051`, `X10228`) a OIČ i na kartě osoby (`PER_MAIN.IK_MPSV`; na reálné záloze
     * se obě hodnoty shodují ve všech formulářích).
     *
     * Převzít je smí převod jen doložené: převod z PAMICA na to má potvrzení uživatele
     * v průvodci, převod z PREMIER takové potvrzení nemá. Doklad je tu přijetí formuláře
     * ČSSZ ({@see PremierPayrollRegistry}): ČSSZ ho s těmi čísly zpracovala. Čísla bez
     * přijatého formuláře (jen z karty osoby nebo z neodeslaného hlášení) zůstávají
     * k ověření a protokol je spočítá.
     *
     * @param array<string,mixed> $relation
     * @return array{oic:?string,id_ppv:?string,confirmed:bool}
     */
    public static function identifiers(array $relation): array
    {
        $jmhz = $relation['registry']['jmhz'] ?? null;
        $accepted = is_array($jmhz) && $jmhz['accepted'] === true;
        return [
            'oic' => (is_array($jmhz) ? $jmhz['oic'] : null) ?? (is_string($relation['oic'] ?? null) ? $relation['oic'] : null),
            'id_ppv' => is_array($jmhz) ? $jmhz['id_ppv'] : null,
            'confirmed' => $accepted,
        ];
    }

    /**
     * Doklady k položkám Zákonných termínů, které proběhly v PREMIER. Doklad o skončení
     * přidává orchestrátor až podle stavu vztahu v MyÚčtu.
     *
     * @param array<string,mixed> $relation
     * @return array<string,string>
     */
    private static function checklistNotes(array $relation, string $until): array
    {
        $notes = ['employment_contract' => self::NOTE . 'vztah vedený v předchozím mzdovém systému, nástup ' . self::czechDate((string) $relation['start']) . '.'];
        foreach ($relation['months'] as $period => $m) {
            if ($period . '-01' > $until) {
                break;
            }
            if ($m['signed'] === true) {
                $notes['tax_declaration'] = self::NOTE . 'podepsané prohlášení poplatníka, mzda za ' . $period . '.';
                break;
            }
        }
        $registry = (array) ($relation['registry'] ?? []);
        $health = (array) ($registry['health_notices'] ?? []);
        if ($relation['insurer_registered'] === true || self::accepted($health, 'P') !== null) {
            $notes['health_insurance_registration'] = self::NOTE . 'přihláška zdravotní pojišťovně přijatá v PREMIER.';
        }
        $deregistration = self::accepted($health, 'O');
        if ($deregistration !== null) {
            $notes['health_insurance_deregistration'] = self::NOTE . 'odhláška zdravotní pojišťovně'
                . (is_string($deregistration['date']) ? ' k ' . self::czechDate($deregistration['date']) : '') . ' přijatá v PREMIER.';
        }
        $social = (array) ($registry['social_notices'] ?? []);
        $start = self::accepted($social, '1');
        $jmhz = $registry['jmhz'] ?? null;
        if ($start !== null) {
            $notes['social_jmhz_registration'] = self::NOTE . 'oznámení o nástupu ČSSZ přijaté'
                . (is_string($start['accepted_on']) ? ' ' . self::czechDate($start['accepted_on']) : '') . '.';
        } elseif (is_array($jmhz) && $jmhz['accepted'] === true) {
            $notes['social_jmhz_registration'] = self::NOTE . 'měsíční hlášení JMHZ za vztah za ' . $jmhz['period'] . ' přijaté ČSSZ.';
        }
        $end = self::accepted($social, '2');
        if ($end !== null) {
            $notes['social_jmhz_deregistration'] = self::NOTE . 'oznámení o skončení ČSSZ přijaté'
                . (is_string($end['accepted_on']) ? ' ' . self::czechDate($end['accepted_on']) : '') . '.';
        }
        $eldp = $registry['eldp'] ?? null;
        if (is_array($eldp)) {
            $notes['eldp_submission'] = self::NOTE . 'evidenční list důchodového pojištění za rok ' . $eldp['year'] . ' přijatý ČSSZ.';
        }
        return $notes;
    }

    /**
     * Poslední přijaté oznámení daného druhu.
     *
     * @param list<array<string,mixed>> $notices
     * @return array<string,mixed>|null
     */
    private static function accepted(array $notices, string $kind): ?array
    {
        $found = null;
        foreach ($notices as $notice) {
            if ($notice['kind'] === $kind && $notice['accepted'] === true
                && ($found === null || (string) ($notice['date'] ?? $notice['accepted_on'] ?? '') >= (string) ($found['date'] ?? $found['accepted_on'] ?? ''))) {
                $found = $notice;
            }
        }
        return $found;
    }

    private static function czechDate(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->format('j. n. Y');
    }

    /**
     * Adresa v podobě karty osoby. Stát zapsaný v PREMIER volným textem („Slovenská
     * republika", „Německo") se převede na kód číselníkem zemí ({@see CountryNameMatcher});
     * adresa, jejíž stát nejde určit, se nezapíše (špatně přiřazená země je horší než
     * chybějící adresa).
     *
     * @param array<string,mixed>|null $address {@see PremierPayroll} (`country_code`, `country_text`)
     * @return array{street_line:string,city:string,postal_code:string,country_code:string}|null
     */
    public static function address(?array $address, ?CountryNameMatcher $countries): ?array
    {
        if ($address === null) {
            return null;
        }
        $code = $address['country_code'] ?? null;
        if (!is_string($code) && $countries !== null) {
            $code = $countries->match((string) ($address['country_text'] ?? ''));
        }
        if (!is_string($code)) {
            return null;
        }
        return [
            'street_line' => (string) $address['street_line'],
            'city' => (string) $address['city'],
            'postal_code' => (string) $address['postal_code'],
            'country_code' => $code,
        ];
    }

    /**
     * Prohlášení poplatníka jako souvislé úseky stejného stavu po měsících mezd.
     *
     * @param array<string,mixed> $relation
     * @return list<array{from:string,to:?string,status:string,period:string}>
     */
    private static function declarations(array $relation, string $until): array
    {
        $runs = [];
        foreach ($relation['months'] as $period => $m) {
            if ($period . '-01' > $until) {
                break;
            }
            $status = $m['signed'] === true ? 'signed' : 'not-signed';
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['status'] === $status) {
                continue;
            }
            $from = max($period . '-01', substr((string) $relation['start'], 0, 7) . '-01');
            if ($last !== null) {
                $runs[$last]['to'] = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            }
            $runs[] = ['from' => $from, 'to' => null, 'status' => $status, 'period' => (string) $period];
        }
        return $runs;
    }
}
