<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use PHPUnit\Framework\TestCase;

/**
 * Strojová kontrola sazeb sociálního pojištění proti OTEVŘENÝM DATŮM ČSSZ.
 *
 * Dodaná sada je od 8/2026 účinná bez zákaznického schválení — za hodnoty ručí
 * dodavatel. Tenhle test je jeden z důkazů, že to ručení není prázdné: sazby
 * se porovnávají s oficiální publikací ČSSZ, ne s tím, co si o nich myslíme.
 *
 * Data jsou PŘIPNUTÁ v `api/resources/payroll/cssz-insurance-rates` i s otiskem;
 * test ZÁMĚRNĚ nesahá na síť — jinak by netestoval naše sazby, ale dostupnost
 * cizího serveru, a tiše by měnil, proti čemu se porovnává.
 *
 * Sazby, které jsou v naší sadě vedené jako ruční posouzení (záchranář/hasič,
 * rizikové zaměstnání), nenesou číslo — u nich se proto ověřuje, že procento
 * uvedené v odůvodnění pořád odpovídá tomu, co publikuje ČSSZ. Kdyby se sazba
 * změnila, nesmí v aplikaci zůstat zastaralý text.
 *
 * Pro ostatní mzdové veličiny strojový zdroj neexistuje (§5 rešerše
 * `private/LEGISLATIVNI-SADY-KONKURENCE.md`) a zůstávají na ruční kontrole.
 */
final class CsszInsuranceRatesPinTest extends TestCase
{
    private const RESOURCE = 'cssz-insurance-rates/rates-2026-08-15/sazby-pojisteni-v-cr.csv';

    private const SHA256 = '7977808aea348fb52ba27245688517e3bd17294cbc40049365706e2a0b8e03c9';

    private const SOURCE_URL = 'https://data.cssz.cz/dump/sazby-pojisteni-v-cr.csv';

    private const EFFECTIVE_FROM = '2026-01-01';

    public function testPinnedDatasetMatchesItsChecksum(): void
    {
        $raw = file_get_contents(self::path());
        self::assertIsString($raw, 'Připnutá otevřená data ČSSZ nelze načíst.');
        self::assertSame(
            self::SHA256,
            hash('sha256', $raw),
            'Připnutá otevřená data ČSSZ neodpovídají otisku. Zdroj: ' . self::SOURCE_URL,
        );
    }

    public function testPinnedDatasetCoversTheEffectiveYearWithIntervalValidity(): void
    {
        $rows = self::rows();

        self::assertArrayHasKey('Celková sazba', $rows);
        self::assertSame(
            '2026-12-31',
            $rows['Celková sazba']['platnost_do'],
            'Otevřená data ČSSZ musí nést intervalovou platnost, ne jen rok.',
        );
    }

    public function testDeliveredSocialInsuranceRatesMatchTheOfficialDataset(): void
    {
        $rows = self::rows();
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::SocialInsurance, '2026-08-03');

        // Celková sazba zaměstnavatele i zaměstnance — v naší sadě jako desetinná
        // sazba, v otevřených datech ČSSZ v procentech.
        self::assertSame(
            self::rate($rows, 'Celková sazba', 'zamestnavatel'),
            $ruleset->parameter('employer.rate.ordinary')->value,
            'Sazba zaměstnavatele neodpovídá otevřeným datům ČSSZ.',
        );
        self::assertSame(
            self::rate($rows, 'Celková sazba', 'zamestnanec'),
            $ruleset->parameter('employee.rate.ordinary')->value,
            'Sazba zaměstnance neodpovídá otevřeným datům ČSSZ.',
        );

        // Pracující důchodce neplatí nemocenské, zbývá mu důchodové pojištění.
        self::assertSame(
            self::rate($rows, 'Důchodové pojištění', 'zamestnanec'),
            $ruleset->parameter('employee.discount.working_pensioner')->value,
            'Sazba pracujícího důchodce neodpovídá otevřeným datům ČSSZ.',
        );
    }

    /**
     * Sazby podle § 5a odst. 1 písm. b) a c) rostou po letech — písm. b) 26,8 →
     * 27,8 → 28,8 a počínaje rokem 2026 29,8, písm. c) startuje v roce 2025 na
     * 26,8 a v roce 2026 je na 27,8. Přepsat ročník sady bez přepsání sazby by
     * odvedlo staré procento z nového základu, takže se obě pinují na otevřená
     * data ČSSZ stejně jako běžná sazba — ne na text v poznámce.
     */
    public function testSpecialEmployerRatesMatchTheOfficialDataset(): void
    {
        $rows = self::rows();
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::SocialInsurance, '2026-08-03');

        foreach ([
            'employer.rate.rescue_and_company_fire_service' => 'zamestnavatel_zachranar_hasic',
            'employer.rate.risk_employment' => 'zamestnavatel_rizikove_zam',
        ] as $key => $column) {
            $parameter = $ruleset->parameters[$key] ?? null;
            self::assertNotNull($parameter, "Dodaná sada nemá parametr {$key}.");
            self::assertSame(
                PayrollRulesetCapability::Supported,
                $parameter->capability,
                "Parametr {$key} musí být počitatelný — zařazení dokládá vztah, ne ruleset.",
            );
            self::assertSame(
                self::rate($rows, 'Celková sazba', $column),
                $parameter->value,
                "Sazba {$key} neodpovídá otevřeným datům ČSSZ.",
            );
        }
    }

    public function testDeliveredRulesetCitesCsszAsItsSource(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::SocialInsurance, '2026-08-03');

        $cssz = array_filter(
            $ruleset->sources,
            static fn (object $source): bool => str_contains($source->url, 'cssz.cz'),
        );

        self::assertNotSame([], $cssz, 'Doména social_insurance musí citovat ČSSZ.');
    }

    /**
     * Řádky platné od {@see EFFECTIVE_FROM}, klíčované složkou pojistného.
     *
     * @return array<string, array<string, string>>
     */
    private static function rows(): array
    {
        $raw = file_get_contents(self::path());
        self::assertIsString($raw);

        $lines = preg_split('/\R/', trim($raw)) ?: [];
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');
        self::assertContains('platnost_od', $header);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== count($header)) {
                continue;
            }
            /** @var array<string, string> $row */
            $row = array_combine(
                array_map(static fn (?string $key): string => (string) $key, $header),
                array_map(static fn (?string $cell): string => (string) $cell, $cells),
            );
            if ($row['platnost_od'] !== self::EFFECTIVE_FROM) {
                continue;
            }
            $rows[$row['sazba_pojistneho']] = $row;
        }

        self::assertNotSame([], $rows, 'Otevřená data ČSSZ neobsahují rok ' . self::EFFECTIVE_FROM . '.');

        return $rows;
    }

    /**
     * Procenta z otevřených dat → kanonická desetinná sazba naší sady. Dělení sto
     * běží posunem desetinné čárky v řetězci, ne přes float: 7,1 % nemá v binárním
     * plovoucím čísle přesné vyjádření a porovnávat by se pak nemuselo to, co sedí.
     *
     * @param array<string, array<string, string>> $rows
     */
    private static function rate(array $rows, string $component, string $column): string
    {
        $percent = self::cell($rows, $component, $column);
        [$integer, $fraction] = array_pad(explode('.', $percent, 2), 2, '');
        $scale = strlen($fraction) + 2;
        $digits = str_pad($integer . $fraction, $scale + 1, '0', STR_PAD_LEFT);
        $decimal = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);

        // Přes tovární metodu sady, aby se porovnával stejně kanonizovaný tvar.
        return (string) PayrollRuleValue::rate($decimal)->value;
    }

    /** @param array<string, array<string, string>> $rows */
    private static function percentLabel(array $rows, string $component, string $column): string
    {
        return str_replace('.', ',', self::cell($rows, $component, $column)) . ' %';
    }

    /** @param array<string, array<string, string>> $rows */
    private static function cell(array $rows, string $component, string $column): string
    {
        self::assertArrayHasKey($component, $rows, "Otevřená data ČSSZ nemají složku {$component}.");
        $value = $rows[$component][$column] ?? '';
        self::assertNotSame('', $value, "Otevřená data ČSSZ nemají hodnotu {$component}/{$column}.");

        return $value;
    }

    private static function path(): string
    {
        return dirname(__DIR__, 4) . '/resources/payroll/' . self::RESOURCE;
    }
}
