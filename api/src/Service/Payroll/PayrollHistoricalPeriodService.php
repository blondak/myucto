<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;

/**
 * JEDINÉ místo, které odpovídá na otázku „vede tohle období MyÚčto, nebo je
 * převzaté z předchozího programu?".
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Převod mezd z předchozího programu naimportuje docházku i mzdové vstupy i za
 * měsíce PŘED `payroll_module_state.start_period`, tedy za období, které MyÚčto
 * vůbec nepočítá. Ty pak v aplikaci zůstaly viset jako rozdělaná práce:
 * neschválené docházkové měsíce a koncepty vstupů, které nikdo nikdy nedodělá,
 * protože mzdový běh za takové období nejde ani založit —
 * {@see \MyInvoice\Service\Payroll\Run\PayrollRunCommandService::assertModuleAvailable()}
 * ho odmítne jako „období předchází aktivaci plného mzdového modulu".
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Takové období se OZNAČÍ jako historické, nikdy neschová. Data zůstávají
 * v databázi i ve výpisech — jsou podkladem pro srovnávací sestavu a pro
 * počáteční stavy kumulací ({@see PayrollOpeningBalanceService}). Mění se jen
 * to, jak vypadají: místo „neúplné / ke schválení" je z nich „historické,
 * vedl předchozí program".
 *
 * ── Hranice ─────────────────────────────────────────────────────────────────
 * `start_period` je PRVNÍ měsíc, který MyÚčto počítá. Porovnání je proto ostré
 * (`<`), stejně jako v `assertModuleAvailable()`: měsíc rovný `start_period`
 * je už normální práce, ne historie.
 *
 * Bez nastaveného `start_period` není podle čeho historii poznat, takže se
 * neoznačuje nic. Stejně se chová i čtení, které selže — informace o tom, že
 * něco je historické, je pohodlí, a jeho ztráta nesmí shodit výpis.
 */
final class PayrollHistoricalPeriodService
{
    /**
     * Zjištěný začátek podle firmy; dotaz se v rámci požadavku neopakuje.
     *
     * @var array<int,?string>
     */
    private array $startPeriods = [];

    public function __construct(
        private readonly PayrollModuleStateRepository $moduleState,
    ) {}

    /**
     * První mzdové období firmy jako `YYYY-MM`, nebo `null`.
     */
    public function startPeriod(int $supplierId): ?string
    {
        if (array_key_exists($supplierId, $this->startPeriods)) {
            return $this->startPeriods[$supplierId];
        }
        try {
            $value = $this->moduleState->get($supplierId)['start_period'];
        } catch (\Throwable) {
            $value = null;
        }
        $normalized = self::normalize(is_string($value) ? $value : null);

        return $this->startPeriods[$supplierId] = $normalized;
    }

    /**
     * Předchází období prvnímu mzdovému období firmy?
     *
     * @param string $period `YYYY-MM` i `YYYY-MM-DD`; porovnává se měsíc.
     */
    public function isHistorical(int $supplierId, string $period): bool
    {
        return self::precedesStart($this->startPeriod($supplierId), $period);
    }

    /**
     * Tvar, kterým se historie hlásí do JSON odpovědí.
     *
     * `payroll_start_period` se jmenuje stejně jako v knize dovolené, aby se
     * prohlížeč nemusel učit dvě jména pro tutéž hranici.
     *
     * @return array{payroll_start_period:?string,historical:bool}
     */
    public function describe(int $supplierId, string $period): array
    {
        $startPeriod = $this->startPeriod($supplierId);

        return [
            'payroll_start_period' => $startPeriod,
            'historical' => self::precedesStart($startPeriod, $period),
        ];
    }

    /**
     * Čistá podoba pravidla — bez databáze, pro volající, kteří hranici už mají.
     *
     * `null` (firma začátek nemá nastavený) znamená „nevíme", a to nikdy
     * neoznačuje: bez nastaveného začátku se modul odjakživa choval tak, že
     * počítá cokoli.
     */
    public static function precedesStart(?string $startPeriod, string $period): bool
    {
        $start = self::normalize($startPeriod);
        $month = self::normalize($period);
        if ($start === null || $month === null) {
            return false;
        }

        // Ostré porovnání: `start_period` je první POČÍTANÝ měsíc, ne poslední
        // historický. Stejná konvence jako v `assertModuleAvailable()`.
        return $month < $start;
    }

    /** `YYYY-MM` z `YYYY-MM` i `YYYY-MM-DD`; cokoli jiného je `null`. */
    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return preg_match('/^\d{4}-\d{2}/', $trimmed) === 1
            ? substr($trimmed, 0, 7)
            : null;
    }
}
