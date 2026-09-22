<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\Payroll\Absence\AverageEarningResult;
use MyInvoice\Service\Payroll\Component\PayrollRecurringComponentValidator;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Zápis převzatého PRACOVNÍHO VZTAHU ({@see PayrollTakeoverEmployment}): sjednaná mzda
 * a její předpis, průměrný výdělek, pracoviště JMHZ a CZ-ISCO, OIČ a ID PPV, skončení
 * vztahu a Zákonné termíny. Společné pro všechny převody mezd z předchozího systému.
 *
 * Jako {@see PayrollTakeoverPersonWriter}: každý krok jde cestou karty vztahu
 * (validátor, zámek verze), doplňuje jen chybějící a savepoint si drží volající.
 */
final class PayrollTakeoverEmploymentWriter
{
    private const ENVIRONMENT = 'production';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollRecurringComponentRepository $recurring,
        private readonly PayrollRecurringComponentValidator $recurringValidator,
    ) {}

    /** Důvod verze podmínek, kterou zapisuje převod ze sjednané mzdy zdroje. */
    public static function wageNote(PayrollTakeoverPolicy $policy): string
    {
        return 'Sjednaná měsíční mzda z ' . $policy->label . '.';
    }

    /**
     * Sjednaná měsíční mzda v podmínkách vztahu podle historie ve zdroji. První verze se
     * opraví na místě, další se zakládají s účinností od měsíce změny. Bez ní běh nemá
     * z čeho spočítat základní mzdu.
     *
     * @return array<string,int>
     */
    public function monthlyWage(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $wages = $employment->monthlyWages;
        if ($wages === []) {
            return [];
        }
        $employmentRow = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        // Ukončený vztah už podmínky měnit nedovolí a jeho mzdy jsou historie; sjednaná
        // mzda se u něj nepřenáší.
        if (in_array((string) $employmentRow['status'], ['ended', 'archived', 'no_show'], true)) {
            return ['monthly_wage_ended' => 1];
        }
        $counts = $wages[0]['prorated'] === true ? ['monthly_wage_max' => 1] : [];
        $written = 0;
        $settled = 0;
        foreach ($wages as $index => $wage) {
            $minor = (int) round($wage['amount'] * 100);
            if ($minor <= 0) {
                continue;
            }
            $current = $this->employments->currentTerms($supplierId, $employmentId)
                ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
            if ((int) ($current['monthly_gross_minor'] ?? 0) === $minor && (string) $current['effective_from'] >= $wage['from']) {
                continue;
            }
            // Verze vztahu se po každém zápisu mění, proto se čte znovu před každou verzí mzdy.
            $row = $this->employmentById($supplierId, $employmentId);
            $body = RegistrationImportWriter::termsBody($current, self::wageNote($policy));
            $terms = $this->employmentValidator->terms(
                $body + ['effective_from' => $wage['from']],
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            );
            // První verze podmínek se opraví na místě (vztah začal dřív, než převod sahá),
            // pozdější změna mzdy je nová verze od měsíce, ve kterém ji zdroj zvedl.
            if ($index === 0 || $wage['from'] <= (string) $current['effective_from']) {
                $terms['effective_from'] = (string) $current['effective_from'];
                try {
                    $this->employments->correctTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                } catch (PayrollTermsSettledException $e) {
                    // Z verze už bylo zúčtováno: mzda se zapíše jako nová verze od měsíce,
                    // který uzavřený běh nepokrývá.
                    $from = self::nextMonth($e->settledPeriod);
                    if ($from === null) {
                        throw $e;
                    }
                    $terms['effective_from'] = $from;
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                }
            } else {
                try {
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                } catch (PayrollTermsSettledException $e) {
                    $from = self::nextMonth($e->settledPeriod);
                    if ($from === null || $from <= (string) $current['effective_from']) {
                        $settled++;
                        continue;
                    }
                    $terms['effective_from'] = $from;
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                }
            }
            $written++;
        }
        if ($settled > 0) {
            $counts['monthly_wage_settled'] = $settled;
        }
        return $written > 0 ? $counts + ['monthly_wage' => $written] : $counts;
    }

    /**
     * Předpis pravidelné měsíční mzdy (`MZDA_MESICNI`, druh `base_wage`). Bez něj za období
     * nevznikne vstup základní mzdy a běh počítá jen to, co přišlo z docházky.
     *
     * Předpis dostane každý vztah se sjednanou měsíční mzdou, a to od prvního převáděného
     * měsíce (ořízne se na nástup a na platnost složky v číselníku); další verze mzdy
     * předchozí předpis ukončí, takže řada je souvislá bez děr a překryvů. Rozpočítání je
     * podle kalendářních dnů, takže nástup nebo skončení v půlce měsíce krátí částku samo
     * ({@see \MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator}).
     *
     * @return array<string,int>
     */
    public function recurringWage(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $wages = $employment->monthlyWages;
        if ($wages === []) {
            return [];
        }
        /*
         * Vztah, který má v převáděných měsících hodinovou nebo úkolovou mzdu, předpis
         * NEDOSTANE. Tyhle složky jdou do běhu jako vstupy z docházky a základní mzda je
         * ve zdroji (PAMICA `KcZaklM`) nese v sobě, ne vedle nich: ověřeno na spočítaném
         * běhu za 6/2026, kde všech 116 takových vztahů vyšlo výš (o 4,32 mil. Kč), tedy
         * dvojí započtení. Základní mzdu u nich zadá účetní, protokol je spočítá.
         */
        if ($employment->hourlyWage) {
            $state->hourlyWageRelations++;
            return ['recurring_wage_hourly' => 1];
        }
        $counts = [];
        $component = $this->monthlyWageComponent($supplierId);
        if ($component === null) {
            throw new \DomainException('firma nemá v číselníku složku základní měsíční mzdy (MZDA_MESICNI).');
        }
        $componentId = (int) $component['id'];
        $row = $this->employmentById($supplierId, $employmentId);
        // Předpis musí ležet uvnitř trvání vztahu i platnosti složky v číselníku.
        $lower = max(
            (string) ($row['actual_start_date'] ?? $row['start_date'] ?? '0000-01-01'),
            (string) ($component['valid_from'] ?? '0000-01-01'),
        );
        $upper = null;
        foreach ([$row['end_date'] ?? null, $component['valid_to'] ?? null] as $limit) {
            if (is_string($limit) && ($upper === null || $limit < $upper)) {
                $upper = $limit;
            }
        }
        $existing = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? AND component_id = ?'
        );
        $existing->execute([$supplierId, $employmentId, $componentId]);
        if ((int) $existing->fetchColumn() > 0) {
            return $counts;
        }
        $written = 0;
        foreach ($wages as $index => $wage) {
            $minor = (int) round($wage['amount'] * 100);
            if ($minor <= 0) {
                continue;
            }
            $next = $wages[$index + 1]['from'] ?? null;
            $from = max($wage['from'], $lower);
            $to = $next === null ? null : (new \DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
            if ($upper !== null && ($to === null || $to > $upper)) {
                $to = $upper;
            }
            if ($to !== null && $to < $from) {
                continue;
            }
            $this->recurring->create($supplierId, $this->recurringValidator->validate([
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'calculation_kind' => 'fixed_amount',
                'amount_minor' => $minor,
                'rate_basis_points' => null,
                'valid_from' => $from,
                'valid_to' => $to,
                'allocation_rule' => 'calendar_days',
                'maximum_amount_minor' => null,
                'note' => $policy->note('sjednaná měsíční mzda ze zpracovaných mezd.'),
                'is_active' => true,
            ]), $userId);
            $written++;
        }
        return $written > 0 ? $counts + ['recurring_wage' => $written] : $counts;
    }

    /**
     * Čtvrtletní průměrný výdělek pro náhrady: hodnota, se kterou počítal zdroj, jako
     * schválený snímek. Rozhodné období a výdělek v něm jdou do snímku jako doložení.
     *
     * @return array<string,int>
     */
    public function averageEarnings(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $averages = $employment->averages;
        if ($averages === []) {
            return [];
        }
        $written = 0;
        $defaultPeriod = 0;
        foreach ($averages as $average) {
            $hourlyMinor = (int) round(((float) $average['hourly']) * 100);
            if ($hourlyMinor <= 0) {
                continue;
            }
            $quarterStart = sprintf('%04d-%02d-01', (int) $average['year'], ((int) $average['quarter'] - 1) * 3 + 1);
            if ($this->averages->findApproved($supplierId, $employmentId, (int) $average['year'], (int) $average['quarter']) !== null) {
                continue;
            }
            // Pravděpodobný výdělek nástupce zdroj vede bez rozhodného období (od > do).
            // Snímek ho vyžaduje, proto se doplní předchozí kalendářní čtvrtletí (§ 354 ZP),
            // jak ho u pravděpodobného výdělku bere i výpočet v aplikaci.
            $from = (string) $average['from'];
            $to = (string) $average['to'];
            if ($from === '' || $to === '' || $to < $from) {
                $previous = (new \DateTimeImmutable($quarterStart))->modify('-3 months');
                $from = $previous->format('Y-m-d');
                $to = $previous->modify('+2 months')->format('Y-m-t');
                $defaultPeriod++;
            }
            // `probable`, ne `actual`: rozhodné období leží před převodem a jeho odpracované
            // hodiny a dny zdroj nenese. Hodnota je ta, se kterou zdroj počítal náhrady.
            $result = new AverageEarningResult('probable', $hourlyMinor, 'supported', [
                'source' => $policy->sourceKey,
                'hourly_minor' => $hourlyMinor,
            ]);
            $snapshot = $this->averages->create(
                $supplierId,
                $employmentId,
                (int) $average['year'],
                (int) $average['quarter'],
                $from,
                $to,
                (int) round(((float) $average['gross']) * 100),
                0,
                (int) round(((float) $average['worked']) * 60),
                (int) round((float) $average['days']),
                $policy->note("průměrný výdělek, se kterým {$policy->label} počítala náhrady čtvrtletí."),
                $result,
                $this->rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $quarterStart),
                $userId,
            );
            $this->averages->approve($supplierId, (int) $snapshot['id'], (int) $snapshot['row_version'], $userId);
            $written++;
        }
        $counts = $written > 0 ? ['averages' => $written] : [];
        return $defaultPeriod > 0 ? $counts + ['averages_default_period' => $defaultPeriod] : $counts;
    }

    /**
     * Pracoviště JMHZ (obec a stát) do platné verze podmínek, pokud ho ještě nemá.
     *
     * @return array<string,int>
     */
    public function workplace(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $place = $employment->workplace;
        if (!is_array($place)) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if (($current['jmhz_workplace_municipality_code'] ?? null) !== null) {
            return [];
        }
        $workPlace = trim((string) ($current['work_place'] ?? ''));
        if ($workPlace !== '' && $workPlace !== $place['work_place']) {
            throw new \DomainException("místo výkonu práce na vztahu ({$workPlace}) se liší od obce pracoviště v {$policy->label}, kód obce doplňte ručně.");
        }
        $changes = [
            'work_place' => $place['work_place'],
            'jmhz_workplace_municipality_code' => $place['municipality_code'],
            'jmhz_workplace_country_code' => $place['country_code'],
        ];
        if (trim((string) ($current['regular_workplace'] ?? '')) === '' && $place['regular_workplace'] !== null) {
            $changes['regular_workplace'] = $place['regular_workplace'];
        }
        $this->correctTerms($supplierId, $employmentId, $current, $changes, $userId, $policy);
        return ['workplace' => 1];
    }

    /**
     * Kód CZ-ISCO do platné verze podmínek, pokud ho ještě nemá.
     *
     * @return array<string,int>
     */
    public function czIsco(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        if (!is_string($employment->czIsco)) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if (($current['cz_isco_code'] ?? null) !== null && $current['cz_isco_code'] !== '') {
            return [];
        }
        $this->correctTerms($supplierId, $employmentId, $current, ['cz_isco_code' => $employment->czIsco], $userId, $policy);
        return ['cz_isco' => 1];
    }

    /**
     * OIČ a ID PPV ze zdroje - uživatel v průvodci potvrdil, že pocházejí z protokolů ČSSZ.
     * Platí od nástupu, uložená čísla se nepřepisují. OIČ, které nesedí na kontrolní
     * číslici, se nepřevezme a zapíše do `$state->invalidOic`.
     *
     * @return array<string,int>
     */
    public function identifiers(int $supplierId, int $employeeId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $row = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        $validFrom = $row['actual_start_date'] ?? $row['start_date'];
        if ($validFrom === null) {
            throw new \DomainException('vztah nemá datum nástupu, OIČ a ID PPV doplňte ručně.');
        }
        $validFrom = (string) $validFrom;
        $counts = [];
        $oic = $employment->oic;
        if ($oic !== null) {
            try {
                $oic = PayrollRegistrationIdentityService::oic($oic);
            } catch (\InvalidArgumentException) {
                $state->invalidOic[] = $employment->personalNumber;
                $counts['oic_invalid'] = 1;
                $oic = null;
            }
        }
        if ($oic !== null && $this->registrations->personExternalIdAt($supplierId, $employeeId, self::ENVIRONMENT, 'ik_mpsv', $validFrom) !== null) {
            $oic = null;
        }
        $ppv = $employment->idPpv;
        if ($ppv !== null && $this->registrations->externalIdAt($supplierId, $employmentId, self::ENVIRONMENT, 'id_ppv', $validFrom) !== null) {
            $ppv = null;
        }
        if ($oic === null && $ppv === null) {
            return $counts;
        }
        $this->identities->assignManualJmhzIdentity(
            $supplierId,
            $employmentId,
            self::ENVIRONMENT,
            $oic,
            $ppv,
            $validFrom,
            'Převzato z ' . $policy->label . ', osobní číslo ' . $employment->personalNumber,
            true,
            $userId,
        );
        if ($oic !== null) {
            $counts['oic'] = 1;
        }
        if ($ppv !== null) {
            $counts['id_ppv'] = 1;
        }
        return $counts;
    }

    /**
     * Skončení vztahu k datu ze zdroje. Budoucí skončení (smlouva na dobu určitou) převod
     * nezapisuje, zapíše se až v den skončení běžnou cestou; se
     * {@see PayrollTakeoverPolicy::$countPlannedTermination} ho aspoň spočítá.
     *
     * @param ?string $until skončení po tomto dni (mimo převáděné období) se nezapisuje
     * @return array<string,int>
     */
    public function termination(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, string $today, ?string $until, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $end = $employment->end;
        if (!is_string($end) || ($until !== null && $end > $until)) {
            return [];
        }
        if ($end > $today) {
            return $policy->countPlannedTermination ? ['end_planned' => 1] : [];
        }
        if ($policy->ignoreEndBeforeStart && $end < (string) $employment->start) {
            return [];
        }
        $row = $this->employmentById($supplierId, $employmentId);
        if ($row === null) {
            if ($policy->strict) {
                throw new \DomainException('pracovní vztah ve firmě není.');
            }
            return [];
        }
        if (!in_array($row['status'], ['active', 'suspended'], true)) {
            return [];
        }
        $this->employments->transition(
            $supplierId,
            $employmentId,
            'ended',
            (int) $row['row_version'],
            $end,
            $policy->note('vztah skončil ' . PayrollTakeoverFormat::czechDate($end) . '.'),
            $userId,
            null,
            null,
        );
        return ['ended' => 1];
    }

    /**
     * Odškrtne nevyřízené položky Zákonných termínů, ke kterým zdroj nese doklad.
     *
     * Změnové položky (`$changeItems`) dostanou poznámku z `$changeNote`, který se zavolá
     * nejvýš jednou a jen tehdy, když taková položka čeká; `null` z něj položku nechá
     * otevřenou. Položku, kterou nejde odškrtnout, předá `$onFailure` a pokračuje dál
     * (co se toleruje, říká {@see self::checklistFailure()}).
     *
     * @param array<string,string> $notes položka => poznámka
     * @param list<string> $changeItems
     * @param (callable():?string)|null $changeNote
     * @param callable(string,\Exception):void $onFailure
     * @return array<string,int>
     */
    public function completeChecklist(
        int $supplierId,
        int $employmentId,
        array $notes,
        array $changeItems,
        ?callable $changeNote,
        ?int $userId,
        PayrollTakeoverPolicy $policy,
        PayrollTakeoverRunState $state,
        callable $onFailure,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT phase, item_key, row_version FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND status = 'pending' ORDER BY id"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $done = 0;
        $change = false;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            $key = (string) $item['item_key'];
            if ($changeNote !== null && $item['phase'] === 'change' && in_array($key, $changeItems, true)) {
                $change = $change === false ? $changeNote() : $change;
                if ($change !== null) {
                    $notes[$key] = $change;
                }
            }
            if (!isset($notes[$key])) {
                continue;
            }
            try {
                $this->employments->updateChecklist($supplierId, $employmentId, $key, (int) $item['row_version'], 'completed', $notes[$key], $userId, null, null);
            } catch (\Exception $e) {
                if (!self::checklistFailure($e)) {
                    throw $e;
                }
                $onFailure($key, $e);
                continue;
            }
            $state->completed[$key] = ($state->completed[$key] ?? 0) + 1;
            $done++;
        }
        return $done > 0 ? ['checklist_completed' => $done] : [];
    }

    /** @return array<string,mixed>|null */
    public function employmentById(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, status, start_date, actual_start_date, end_date, row_version
               FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Očekávané odmítnutí položky: konflikt verze, chybějící vztah nebo položka a zamítnutí
     * kontrolou (datum nástupu). Chyba databáze ani jiná RuntimeException mezi ně nepatří,
     * projde výš a převod ji ohlásí, místo aby položka tiše zůstala neodškrtnutá.
     */
    private static function checklistFailure(\Exception $e): bool
    {
        return $e instanceof PayrollEmploymentConflictException || $e instanceof PayrollEmploymentNotFoundException
            || $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
    }

    /**
     * Oprava platné verze podmínek - stejná cesta jako karta vztahu (validátor, zámek verze).
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $changes
     */
    private function correctTerms(int $supplierId, int $employmentId, array $current, array $changes, ?int $userId, PayrollTakeoverPolicy $policy): void
    {
        $body = RegistrationImportWriter::termsBody($current, 'Údaje převzaté z ' . $policy->label . '.');
        foreach ($changes as $field => $value) {
            $body[$field] = $value;
        }
        $body['effective_from'] = (string) $current['effective_from'];
        $row = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        $this->employments->correctTerms(
            $supplierId,
            $employmentId,
            $this->employmentValidator->terms(
                $body,
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            ),
            (int) $row['row_version'],
            $userId,
            null,
            null,
        );
    }

    /** @return array<string,mixed>|null složka základní měsíční mzdy i s platností v číselníku */
    private function monthlyWageComponent(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, valid_from, valid_to FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = 'MZDA_MESICNI' AND is_active = 1
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** První den měsíce následujícího po uzavřeném období, nebo null u neznámého tvaru. */
    private static function nextMonth(string $period): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})/', $period, $match) !== 1) {
            return null;
        }
        return (new \DateTimeImmutable("{$match[1]}-{$match[2]}-01"))->modify('+1 month')->format('Y-m-d');
    }
}
