<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Service\Payroll\Import\Registration\RegistrationRecord;

/**
 * Věty registrací, které dávka hlášení dokládá, i když je v ní nikdo nepodal.
 *
 * Firma, která přechází z jiného programu, často nemá přihlášky ani export
 * zaměstnanců — má jen přijatá měsíční hlášení. Ta přitom nesou dost na to,
 * aby se vztah dal založit: pro každý vztah, na který dávka nemá větu registrace
 * ani exportu, vznikne přihlášení (akce 1) s identifikátory ČSSZ, nástupem
 * a druhem činnosti ({@see JmhzEmploymentHistory}). Formulář větve B nese i jméno
 * a datum narození; formulář větve A jen OIČ a ID PPV — osoba pak vznikne se
 * zástupným jménem, které účetní přepíše.
 *
 * Skončení vztahu, které řada měsíců dokládá, zapisuje převzetí historie mezd
 * ({@see JmhzTakeoverPlanner}) společným zápisem převodů.
 *
 * Věty jdou stejnou cestou jako nahrané registrace (náhled, výběr, plánování
 * nad aktuální evidencí), takže existující osobu nezaloží podruhé — spáruje ji
 * podle OIČ, ID PPV, případně jména s datem narození.
 */
final class JmhzDerivedRegistrations
{
    public const FILE_NAME = 'Odvozeno z měsíčních hlášení JMHZ';
    public const PLACEHOLDER_FIRST_NAME = 'Doplňte';
    public const PLACEHOLDER_LAST_NAME = 'Jméno z hlášení JMHZ';

    /**
     * @param list<RegistrationRecord> $fileRecords věty registrací a exportu z téže dávky
     * @return array{sha256:string,records:list<RegistrationRecord>}
     */
    public static function build(JmhzBatch $batch, JmhzEmploymentHistory $history, array $fileRecords): array
    {
        $covered = [];
        foreach ($fileRecords as $record) {
            if ($record->employmentIdentifier !== null) {
                $covered['ppv:' . $record->employmentIdentifier] = true;
            }
        }
        $shas = [];
        foreach ($batch->items() as $item) {
            $shas[$item->fileSha256] = true;
        }
        ksort($shas, SORT_STRING);

        $records = [];
        $placeholder = 0;
        foreach ($history->keys() as $index => $key) {
            $first = $history->first($key);
            $latest = $history->latest($key);
            $start = $history->start($key);
            if ($first === null || $latest === null || $start === null || isset($covered[$key])) {
                continue;
            }
            $identity = self::identity($history->months($key));
            $notes = self::startNotes($start);
            $position = $index + 1;
            $firstName = $identity['first_name'];
            $lastName = $identity['last_name'];
            if ($firstName === null || $lastName === null) {
                $placeholder++;
                $firstName = self::PLACEHOLDER_FIRST_NAME;
                $lastName = self::PLACEHOLDER_LAST_NAME . ' ' . $placeholder;
                $notes[] = 'Hlášení nese jen OIČ a ID PPV, ne jméno. Osoba se založí se zástupným jménem — '
                    . 'jméno, rodné číslo, adresu a zdravotní pojišťovnu doplňte na kartě osoby '
                    . '(nebo nahrajte export zaměstnanců z ePortálu ČSSZ).';
            }
            $activity = $history->activityCode($key);
            if ($activity === null) {
                $notes[] = 'Vztah v hlášení nemá ELDP ani druh činnosti — není účasten na pojištění, takže '
                    . 'z hlášení nejde poznat, jestli jde o dohodu, nebo zaměstnání malého rozsahu.';
            }
            $workplace = $latest->form->workplace;
            $records[] = new RegistrationRecord(
                documentType: RegistrationRecord::JMHZ_DERIVED,
                position: $position,
                sequence: $position,
                actionCode: 1,
                preparedOn: substr($latest->file->filledAt, 0, 10),
                personIdentifier: $identity['oic'],
                firstName: $firstName,
                lastName: $lastName,
                birthDate: $identity['birth_date'],
                employmentIdentifier: $identity['id_ppv'],
                startOn: $start['on'],
                activityCode: $activity,
                workplaceCity: $workplace !== null && $workplace['city'] !== '' ? $workplace['city'] : null,
                workplaceMunicipalityCode: $workplace !== null && $workplace['municipality_code'] !== ''
                    ? $workplace['municipality_code']
                    : null,
                employerVariableSymbol: $first->file->variableSymbol,
                workload: self::workload($history->months($key)),
                notes: $notes,
            );
        }

        return [
            'sha256' => hash('sha256', 'jmhz-derived|' . implode('|', array_keys($shas))),
            'records' => $records,
        ];
    }

    /**
     * @param array<string,JmhzBatchItem> $months
     * @return array{oic:?string,id_ppv:?string,first_name:?string,last_name:?string,birth_date:?string}
     */
    private static function identity(array $months): array
    {
        $identity = ['oic' => null, 'id_ppv' => null, 'first_name' => null, 'last_name' => null, 'birth_date' => null];
        foreach (array_reverse($months) as $item) {
            $form = $item->form;
            $identity['oic'] ??= $form->personIdentifier;
            $identity['id_ppv'] ??= $form->employmentIdentifier;
            if ($identity['last_name'] === null && $form->lastName !== null && $form->firstName !== null) {
                $identity['first_name'] = $form->firstName;
                $identity['last_name'] = $form->lastName;
            }
            $identity['birth_date'] ??= $form->birthDate;
        }

        return $identity;
    }

    /**
     * Úvazek k nástupu: z nejstaršího měsíce, který ho dokládá.
     *
     * @param array<string,JmhzBatchItem> $months
     * @return array{workload_basis_points:int,weekly_hours:string}|null
     */
    private static function workload(array $months): ?array
    {
        foreach ($months as $item) {
            $workload = $item->form->workload();
            if ($workload !== null) {
                return $workload;
            }
        }

        return null;
    }

    /**
     * @param array{on:string,source:string,period:string,needs_check:bool} $start
     * @return list<string>
     */
    private static function startNotes(array $start): array
    {
        $note = match ($start['source']) {
            JmhzEmploymentHistory::START_DATE => "Nástup {$start['on']} uvádí hlášení za {$start['period']}.",
            JmhzEmploymentHistory::START_INSURANCE_FROM => "Nástup {$start['on']} je začátek pojištění v hlášení za {$start['period']}.",
            default => "Nástup se odvodil z prvního hlášeného měsíce {$start['period']}.",
        };
        $notes = [$note];
        if ($start['needs_check']) {
            $notes[] = 'Dávka nemá hlášení za dřívější měsíce, vztah tedy mohl začít už dřív. Skutečný nástup '
                . 'zkontrolujte (pracovní smlouva, přihláška) — nebo nahrajte i hlášení za dřívější měsíce.';
        }

        return $notes;
    }
}
