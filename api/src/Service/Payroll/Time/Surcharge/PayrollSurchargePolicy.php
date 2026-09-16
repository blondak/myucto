<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;

/**
 * Co je u pracovního vztahu SJEDNÁNO nad rámec zákonného minima.
 *
 * Ruleset drží zákon, tenhle objekt smlouvu. Oddělení je záměrné: sazba
 * z kolektivní ani z pracovní smlouvy není legislativa a nesmí se dostat do
 * dodané sady, jinak by se otisk sady lišil firmu od firmy a přestala by být
 * poznána jako dodaná ({@see \MyInvoice\Service\Payroll\Ruleset\VendorRulesetManifest}).
 *
 * ── Co se smí sjednat ────────────────────────────────────────────────────────
 *
 * Vyšší sazbu vždycky. NIŽŠÍ jen u noční práce a u víkendu, protože jen § 116
 * a § 118 obsahují větu „Je možné sjednat jinou minimální výši a způsob určení
 * příplatku". § 114, § 115 a § 117 mají kogentní „nejméně" a podlézt se nedá —
 * hlídá to {@see assertAgreedRateIsLawful()} už při stavbě objektu, ne až ve
 * výpočtu, aby neplatná zásada nemohla v databázi vůbec vzniknout.
 *
 * ── Procento, nebo PEVNÁ ČÁSTKA na hodinu ───────────────────────────────────
 *
 * Příplatek jde sjednat dvojím způsobem a volí se u KAŽDÉHO DRUHU zvlášť:
 * procentem z průměrného výdělku, nebo pevnou částkou za hodinu („přesčas
 * 75 Kč/h"). Pevná částka je v praxi častější, protože si ji zaměstnanec přečte
 * na mzdovém výměru a nemusí čekat, až se spočítá čtvrtletní průměr.
 *
 * Obojí u téhož druhu naráz sjednat NELZE: kdyby bylo vyplněné procento i
 * částka, musel by si výpočet vybrat, které z nich je to sjednané, a člověk by
 * na pásce našel číslo, které ve smlouvě nestojí.
 *
 * Kogentní podlaha platí u obou stejně, ale POZNÁ SE JINDY. Procento se dá
 * porovnat se zákonnou sazbou hned tady, protože obojí je zlomek. Pevná částka
 * se s ní porovnat NEDÁ: zákonné minimum je podíl z průměrného výdělku
 * KONKRÉTNÍHO člověka, takže 42 Kč/h je nad minimem u toho, kdo má průměr
 * 400 Kč/h, a pod ním u toho, kdo má 500 Kč/h. Táž zásada je tedy pro jednoho
 * dost a pro druhého málo a při stavbě objektu, kde se o žádném člověku neví,
 * to rozhodnout nelze. Posuzuje to proto až {@see PayrollSurchargeLine} nad
 * skutečným základem — a nepadá, nýbrž zákonné minimum dopočítá a rozdíl
 * vykáže. Sjednat podlezenou částku smí totiž jen § 116 a § 118; u ostatních
 * druhů je to vada sjednání, kterou musí vidět účtárna, ne tichý nedoplatek.
 */
final readonly class PayrollSurchargePolicy
{
    /**
     * @param array<string,int> $agreedRateBasisPoints klíč = hodnota {@see PayrollSurchargeKind}
     * @param array<string,int> $agreedFixedHourlyMinor týž klíč; haléře za hodinu
     */
    private function __construct(
        public PayrollSurchargeCompensationMode $overtimeMode,
        public PayrollSurchargeCompensationMode $holidayMode,
        public ?int $difficultEnvironmentFactors,
        private array $agreedRateBasisPoints,
        private array $agreedFixedHourlyMinor,
        public bool $isStatutoryDefault,
        /**
         * ODKUD sazba přišla: `employment` ze sjednání na vztahu, `employer`
         * z firemních zásad, `statutory` ze zákona. Účetní musí u příplatku
         * poznat důvod — bez toho je „proč vyšel zrovna takhle" nezodpověditelné.
         */
        public string $source = self::SOURCE_STATUTORY,
    ) {}

    /** Zdroj sjednání: vztah přebíjí firmu a firma přebíjí zákonné minimum. */
    public const SOURCE_EMPLOYMENT = 'employment';
    public const SOURCE_EMPLOYER = 'employer';
    public const SOURCE_STATUTORY = 'statutory';

    /**
     * Zásada, kterou určuje sám zákon, když u vztahu není nic sjednáno.
     *
     * Přesčas: § 114 odst. 1 — příplatek. Zákon ho přiznává bez jakékoli dohody,
     * takže chybějící evidence tady nebrání výpočtu; naopak, počítat NEPŘÍPLATEK
     * by byl nedoplatek.
     *
     * Svátek: § 115 odst. 1 — náhradní volno. Tady je to obráceně a je to past:
     * vyplatit příplatek bez dohody podle odst. 2 by znamenalo zaplatit něco,
     * na co bez dohody nárok není, a zároveň nechat nevyčerpané náhradní volno.
     * Modul přitom evidenci „za tenhle svátek bylo poskytnuto volno" nemá
     * (viz {@see PayrollSurchargeEvidence::HOLIDAY_ARRANGEMENT_MISSING}), takže
     * výchozí režim svátku vede na FAIL-CLOSED, ne na tichou nulu.
     *
     * Ztížené prostředí: počet ztěžujících vlivů zákon neurčuje, ten plyne
     * z nařízení vlády a z konkrétního pracoviště — proto zůstává `null`
     * a bez doloženého počtu se § 117 nepočítá.
     */
    public static function statutoryDefault(): self
    {
        return new self(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::CompensatoryTimeOff,
            null,
            [],
            [],
            true,
        );
    }

    /**
     * @param array<string,int|null> $agreedRateBasisPoints
     * @param array<string,int|null> $agreedFixedHourlyMinor pevná částka v haléřích
     *        za hodinu; u téhož druhu se vylučuje se sazbou v procentech
     */
    public static function agreed(
        PayrollSurchargeCompensationMode $overtimeMode,
        PayrollSurchargeCompensationMode $holidayMode,
        ?int $difficultEnvironmentFactors,
        array $agreedRateBasisPoints,
        PayrollSurchargeRuleset $ruleset,
        array $agreedFixedHourlyMinor = [],
        string $source = self::SOURCE_EMPLOYMENT,
    ): self {
        if ($holidayMode === PayrollSurchargeCompensationMode::IncludedInWage) {
            throw new InvalidArgumentException(
                'Mzda sjednaná s přihlédnutím k práci ve svátek neexistuje; '
                . '§ 114 odst. 3 se týká jen práce přesčas.',
            );
        }
        if ($difficultEnvironmentFactors !== null
            && ($difficultEnvironmentFactors < 0 || $difficultEnvironmentFactors > 255)
        ) {
            throw new InvalidArgumentException(
                'Počet ztěžujících vlivů podle § 117 musí být 0 až 255.',
            );
        }

        $rates = [];
        foreach ($agreedRateBasisPoints as $key => $basisPoints) {
            if ($basisPoints === null) {
                continue;
            }
            $kind = PayrollSurchargeKind::tryFrom((string) $key);
            if ($kind === null) {
                throw new InvalidArgumentException("Neznámý druh příplatku {$key}.");
            }
            self::assertAgreedRateIsLawful($kind, $basisPoints, $ruleset);
            $rates[$kind->value] = $basisPoints;
        }

        $fixed = [];
        foreach ($agreedFixedHourlyMinor as $key => $amountMinor) {
            if ($amountMinor === null) {
                continue;
            }
            $kind = PayrollSurchargeKind::tryFrom((string) $key);
            if ($kind === null) {
                throw new InvalidArgumentException("Neznámý druh příplatku {$key}.");
            }
            if ($amountMinor <= 0) {
                throw new InvalidArgumentException(
                    'Sjednaná pevná částka příplatku musí být kladná.',
                );
            }
            // Obojí naráz by znamenalo dvě různá čísla na týž nárok a výpočet by
            // si musel jedno vybrat. Táž mez drží i CHECK v migraci 1845.
            if (isset($rates[$kind->value])) {
                throw new InvalidArgumentException(sprintf(
                    'Příplatek %s lze sjednat buď procentem, nebo pevnou částkou na hodinu, '
                    . 'ne obojím zároveň.',
                    $kind->section(),
                ));
            }
            $fixed[$kind->value] = $amountMinor;
        }

        return new self(
            $overtimeMode,
            $holidayMode,
            $difficultEnvironmentFactors,
            $rates,
            $fixed,
            false,
            $source,
        );
    }

    /**
     * Firemní výchozí sazby pro vztah, který vlastní sjednání NEMÁ.
     *
     * Režimy odměnění (§ 114 odst. 3, § 115 odst. 1) zůstávají zákonné: to, jestli
     * se za přesčas dává příplatek nebo náhradní volno, se sjednává s konkrétním
     * člověkem, ne plošně. Firemní úroveň nese jen SAZBY — tedy kolik, ne jestli.
     *
     * @param array<string,int|null> $rateBasisPoints
     * @param array<string,int|null> $fixedHourlyMinor
     */
    public static function employerDefault(
        array $rateBasisPoints,
        array $fixedHourlyMinor,
        PayrollSurchargeRuleset $ruleset,
        ?int $difficultEnvironmentFactors = null,
    ): self {
        $statutory = self::statutoryDefault();

        return self::agreed(
            $statutory->overtimeMode,
            $statutory->holidayMode,
            $difficultEnvironmentFactors,
            $rateBasisPoints,
            $ruleset,
            $fixedHourlyMinor,
            self::SOURCE_EMPLOYER,
        );
    }

    /** Nese zásada vůbec nějakou sjednanou sazbu, nebo je celá prázdná? */
    public function hasAnyAgreedRate(): bool
    {
        return $this->agreedRateBasisPoints !== [] || $this->agreedFixedHourlyMinor !== [];
    }

    public function mode(PayrollSurchargeKind $kind): PayrollSurchargeCompensationMode
    {
        return match ($kind) {
            PayrollSurchargeKind::Overtime => $this->overtimeMode,
            PayrollSurchargeKind::Holiday => $this->holidayMode,
            default => PayrollSurchargeCompensationMode::Surcharge,
        };
    }

    /**
     * Sazba, která se skutečně použije: sjednaná, jinak zákonné minimum.
     *
     * @return array{rate:DecimalRate, agreed:bool}
     */
    public function effectiveRate(
        PayrollSurchargeKind $kind,
        PayrollSurchargeRuleset $ruleset,
    ): array {
        $basisPoints = $this->agreedRateBasisPoints[$kind->value] ?? null;
        if ($basisPoints === null) {
            return ['rate' => $ruleset->statutoryRate($kind), 'agreed' => false];
        }

        return ['rate' => self::rateFromBasisPoints($basisPoints), 'agreed' => true];
    }

    public function agreedRateBasisPoints(PayrollSurchargeKind $kind): ?int
    {
        return $this->agreedRateBasisPoints[$kind->value] ?? null;
    }

    /**
     * Sjednaná pevná částka za hodinu v haléřích, nebo `null`.
     *
     * `null` NENÍ nula: znamená „pevná částka sjednána není", takže se použije
     * sazba v procentech, a když není ani ta, zákonné minimum.
     */
    public function agreedFixedHourlyMinor(PayrollSurchargeKind $kind): ?int
    {
        return $this->agreedFixedHourlyMinor[$kind->value] ?? null;
    }

    /**
     * Sjednaná sazba se drží v BÁZOVÝCH BODECH, ne jako desetinné číslo:
     * `0.25` v databázi jako DECIMAL by se sem vrátilo řetězcem, jehož tvar
     * závisí na ovladači, a `DecimalRate` je na kanonický tvar citlivá.
     * Celé číslo desetitisícin je jednoznačné a bezztrátové.
     */
    public static function rateFromBasisPoints(int $basisPoints): DecimalRate
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Sazba příplatku nesmí být záporná.');
        }

        return DecimalRate::fromString(
            sprintf('%d.%04d', intdiv($basisPoints, 10_000), $basisPoints % 10_000),
        );
    }

    /**
     * Opačný převod: zlomek na bázové body, aby se zákonné minimum dalo ukázat
     * ve stejných jednotkách, ve kterých se sjednaná sazba zadává. Bez toho by
     * formulář srovnával 0,25 s 2500 a uživatel by nepoznal, co je víc.
     */
    public static function basisPointsOf(DecimalRate $rate): int
    {
        return \MyInvoice\Service\Payroll\Calculation\RoundingMode::HalfUp->roundFraction(
            $rate->numerator * 10_000,
            $rate->denominator,
        );
    }

    private static function assertAgreedRateIsLawful(
        PayrollSurchargeKind $kind,
        int $basisPoints,
        PayrollSurchargeRuleset $ruleset,
    ): void {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Sazba příplatku nesmí být záporná.');
        }
        if ($kind->allowsLowerAgreedRate()) {
            return;
        }
        $statutory = $ruleset->statutoryRate($kind);
        // Porovnání zlomků křížovým součinem, ne převodem na desetinné číslo:
        // jmenovatel zákonné sazby je mocnina deseti, sjednané vždycky 10 000,
        // a převod přes float by na hranici rozhodl náhodně.
        if ($basisPoints * $statutory->denominator < $statutory->numerator * 10_000) {
            throw new InvalidArgumentException(sprintf(
                'Sjednaná sazba příplatku %s nesmí být nižší než zákonné minimum %s.',
                $kind->section(),
                $statutory->toCanonicalString(),
            ));
        }
    }
}
