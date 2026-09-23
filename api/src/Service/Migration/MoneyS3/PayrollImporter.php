<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService;

/**
 * Mzdy z Money S3: to, co ze zálohy čitelně jde ({@see MoneyS3PayrollLedger}).
 *
 * Mzdové zápisy přišly převodem deníku 1:1, takže tenhle krok **nezakládá žádný
 * účetní zápis**. Z mzdových dokladů:
 *
 *  - uloží návrh kontací mezd k potvrzení účetní ({@see MoneyS3PayrollPostingMap}),
 *  - spočítá měsíční kontrolní úhrny celé firmy a porovná daň s měsíčním úhrnem daně
 *    z příjmů v Money ({@see MoneyS3PayrollTotals}); jdou do protokolu,
 *  - zapne firmě, která mzdy vede, modul Mzdy se začátkem za posledním zaúčtovaným
 *    měsícem mezd ({@see PayrollMigrationModuleSetup}).
 *
 * Zaměstnance a mzdy jednotlivých osob nepřevádí: Money je od listopadu 2020 vede
 * v šifrované databázi agendy a starší historie osob převodem neprochází. Protokol
 * řekne, jak aktuální zaměstnance převzít z podání JMHZ a registrací.
 */
final class PayrollImporter
{
    public const STEP = 'payroll';
    private const PROGRAM = 'Money S3';
    private const LIST_LIMIT = 12;

    public function __construct(
        private readonly PayrollPostingMapProposalService $postingMap,
        private readonly PayrollMigrationModuleSetup $moduleSetup,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $ledger = MoneyS3PayrollLedger::read($ctx->backup, $ctx->dirYears);
        $map = MoneyS3PayrollPostingMap::fromLedger($ledger);
        $last = self::lastPayrollPeriod($ledger);
        if ($last === null && $ledger->lastPersonPeriod === null) {
            return;
        }
        $insurance = MoneyS3PayrollPostingMap::insuranceAccounts($ledger);
        $p->setCount(self::STEP, 'payroll_lines', count(array_filter(
            $ledger->lines,
            static fn (array $line): bool => $line['flagged'] || MoneyS3PayrollPostingMap::classify($line, $insurance, $ledger->accountNames) !== null,
        )));

        if ($last !== null) {
            PayrollMigrationModuleSetup::report($p, self::STEP, $this->moduleSetup->ensure(
                $ctx->supplierId, $ctx->userId > 0 ? $ctx->userId : null, $last, $ledger->lastDataPeriod,
            ), self::PROGRAM);
        }
        $this->postingMap($ctx, $map);
        $this->totals($ctx, $ledger);
        $this->peopleNotice($ctx, $ledger);
    }

    /**
     * Poslední měsíc mezd: období posledního mzdového dokladu, u starších dat bez
     * příznaku měsíc posledního zápisu hrubých mezd.
     */
    public static function lastPayrollPeriod(MoneyS3PayrollLedger $ledger): ?string
    {
        $last = null;
        $insurance = MoneyS3PayrollPostingMap::insuranceAccounts($ledger);
        foreach ($ledger->lines as $line) {
            $period = $line['period'];
            if ($period === null) {
                $result = MoneyS3PayrollPostingMap::classify($line, $insurance, $ledger->accountNames);
                if (!in_array($result['concept'] ?? null, ['employment_gross', 'partner_gross', 'statutory_gross'], true)) {
                    continue;
                }
                $period = $line['month'] !== '' ? $line['month'] : null;
            }
            if ($period !== null && ($last === null || $period > $last)) {
                $last = $period;
            }
        }
        return $last;
    }

    private function postingMap(ImportContext $ctx, MoneyS3PayrollPostingMap $map): void
    {
        $stored = $this->postingMap->refresh($ctx->supplierId, $map, $map->year, self::PROGRAM . ' ' . $ctx->agenda->ico);
        if ($stored === null) {
            return;
        }
        $p = $ctx->protocol;
        $p->set('posting_map', $stored['proposal']);
        $conflicts = (int) ($stored['proposal']['summary']['conflict'] ?? 0);
        $unmapped = count($stored['proposal']['unmapped'] ?? []);
        if ($conflicts > 0) {
            $p->setCount(self::STEP, 'posting_map_conflicts', $conflicts);
        }
        if ($unmapped > 0) {
            $p->setCount(self::STEP, 'posting_map_unmapped', $unmapped);
        }
        $p->info(self::STEP, 'posting_map', sprintf(
            'Ze mzdových zápisů deníku %d vznikl návrh kontací mezd (Mzdy → Importy → Kontace mezd). Nastavení se nemění, '
            . 'dokud návrh nepotvrdíte%s%s.',
            (int) $map->year,
            $conflicts > 0 ? "; u {$conflicts} kontací jsou v deníku různé účty a vybrat musíte sami" : '',
            $unmapped > 0 ? "; {$unmapped} dvojic účtů převod nezařadil a jsou jen v přehledu" : '',
        ));
    }

    private function totals(ImportContext $ctx, MoneyS3PayrollLedger $ledger): void
    {
        $months = MoneyS3PayrollTotals::fromLedger($ledger);
        if ($months === []) {
            return;
        }
        $p = $ctx->protocol;
        $p->set('payroll_totals', $months);
        $p->setCount(self::STEP, 'payroll_months', count($months));
        $mismatch = array_values(array_filter($months, static fn (array $m): bool => $m['tax_ok'] === false));
        if ($mismatch === []) {
            $p->info(self::STEP, 'payroll_totals', sprintf(
                'Kontrolní úhrny mezd celé firmy za %d měsíců (%s až %s) jsou v protokolu. Jsou to součty mzdových dokladů Money, '
                . 'ne převzaté mzdy zaměstnanců.',
                count($months),
                $months[0]['period'],
                $months[count($months) - 1]['period'],
            ));
            return;
        }
        $p->setCount(self::STEP, 'payroll_tax_mismatches', count($mismatch));
        $p->warn(self::STEP, 'payroll_tax_mismatch', sprintf(
            'Daň ze mzdových dokladů nesedí na měsíční úhrn daně z příjmů ze závislé činnosti v Money za %d měsíců: %s. '
            . 'Rozdíl je už v Money (doklady vs. vyúčtování daně), převod ho nemění; ověřte ho před podáním vyúčtování.',
            count($mismatch),
            implode(', ', array_map(
                static fn (array $m): string => sprintf('%s (doklady %s Kč, úhrn %s Kč)', $m['period'],
                    self::money($m['advance_tax'] + $m['withholding_tax']), self::money((float) $m['dpfo'])),
                array_slice($mismatch, 0, self::LIST_LIMIT),
            )) . (count($mismatch) > self::LIST_LIMIT ? ', …' : ''),
        ), ['periods' => array_column($mismatch, 'period')]);
    }

    /**
     * Osoby a mzdy jednotlivých zaměstnanců převod z Money nepřevádí. Upozornění je
     * vždy, když záloha mzdy nese: bez něj by převod o zaměstnancích mlčel.
     */
    private function peopleNotice(ImportContext $ctx, MoneyS3PayrollLedger $ledger): void
    {
        $until = $ledger->lastPersonPeriod;
        $ctx->protocol->warn(self::STEP, 'payroll_people_not_converted', sprintf(
            'Zaměstnanci a mzdy jednotlivých osob se z Money nepřevádějí. %sNovější verze Money vedou zaměstnance a mzdy '
            . 'v šifrované databázi agendy, kterou převod přečíst nemůže, a historie osob ze starších tabulek se nepřevádí. '
            . 'Aktuální zaměstnance převezměte importem přijatých podání JMHZ a registrací zaměstnanců v Mzdy → Importy '
            . '(manuál, kapitola 90.9.1); převedený deník a kontrolní úhrny výše slouží ke kontrole.',
            $until === null ? '' : 'Čitelné tabulky mzdového modulu v záloze končí mzdami za ' . self::monthLabel($until) . '. ',
        ));
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private static function monthLabel(string $period): string
    {
        return ((int) substr($period, 5, 2)) . '/' . substr($period, 0, 4);
    }
}
