<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

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
            addressesPerType: false,
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
    public static function record(array $relation, string $until): PayrollTakeoverRecord
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
            residence: is_array($relation['residence']) ? $relation['residence'] : null,
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
        );
        $employment = new PayrollTakeoverEmployment(
            personalNumber: (string) $relation['personal_number'],
            relationKey: (string) $relation['key'],
            start: $start,
            end: is_string($relation['end']) ? $relation['end'] : null,
        );
        return new PayrollTakeoverRecord($person, $employment);
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
