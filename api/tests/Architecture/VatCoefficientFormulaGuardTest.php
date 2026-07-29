<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Invoice\InvoiceMath;
use PHPUnit\Framework\TestCase;

/**
 * § 37 odst. 2 ZDPH — výpočet daně z ceny včetně daně.
 *
 * Zákonný vzorec je `daň = cena_s_daní × sazba / (100 + sazba)`; základ je zbytek.
 * Opačné pořadí (spočítat základ jako `cena / (1 + sazba/100)` a daň dopočítat)
 * dává **jiný haléř**: při 12 % u 4 547 z 200 000 částek do 2 000 Kč, při 21 %
 * u žádné. Právě tak to počítala pokladna (`CashVatBreakdown.vue`), zatímco faktury
 * jedou přes `InvoiceMath` — a obojí teče do téže knihy DPH.
 *
 * Pokladna byla navíc jediné místo, kde byla hodnota z frontendu AUTORITATIVNÍ:
 * `validateVatLines()` ověřovala jen `Σ(base + vat) == total`. Při brutto 121 Kč
 * a sazbě 21 % tou kontrolou projde 12 101 různých rozpadů, přestože zákonný je
 * právě jeden — chyba ve vzorci (i záměrně podhodnocená daň z cizího API klienta)
 * by se tedy uložila. Od fáze F2 rozpad přepočítává backend
 * (`CashDocumentService::recomputeVatLines()`) přes tentýž `InvoiceMath`.
 *
 * Guard hlídá tři strany:
 *   - PHP referenci charakterizačním testem (kdyby se změnila, ví se to),
 *   - frontend staticky, protože `.vue` z PHPUnitu spustit nejde,
 *   - a že backend na klientův rozpad nespoléhá.
 */
final class VatCoefficientFormulaGuardTest extends TestCase
{
    private const CASH_COMPONENT = 'web/src/components/cash/CashVatBreakdown.vue';

    /**
     * Charakterizace zákonného vzorce na částkách, kde se obě metody prokazatelně
     * rozcházejí. Kdyby `InvoiceMath` přešel na „základ první", tenhle test padne.
     *
     * @return list<array{0:float, 1:float, 2:float, 3:float}> gross, sazba, základ, daň
     */
    public static function statutorySplits(): array
    {
        return [
            [0.14, 12.0, 0.12, 0.02],
            [1.26, 12.0, 1.12, 0.14],
            [1.54, 12.0, 1.37, 0.17],
            [4.62, 12.0, 4.12, 0.50],
            [10.50, 12.0, 9.37, 1.13],
            [121.00, 21.0, 100.00, 21.00],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statutorySplits')]
    public function testInvoiceMathFollowsStatutoryCoefficient(
        float $gross,
        float $rate,
        float $expectedBase,
        float $expectedVat,
    ): void {
        $result = InvoiceMath::compute(
            [['quantity' => 1.0, 'unit_price_without_vat' => $gross, 'vat_rate_snapshot' => $rate]],
            false, // reverseCharge
            true,  // pricesIncludeVat — režim „shora", o který tu jde
        );
        $line = $result['items'][0];

        self::assertSame($expectedVat, round((float) $line['vat'], 2), 'daň u ' . $gross);
        self::assertSame($expectedBase, round((float) $line['base'], 2), 'základ u ' . $gross);
        self::assertSame($gross, round((float) $line['with'], 2), 'součet u ' . $gross);
    }

    /**
     * Pokladní komponenta musí počítat DAŇ PRVNÍ. Statická kontrola je tu na místě:
     * jde o jediný řádek, jehož obrácení je tichá a plně věrohodně vypadající změna.
     */
    public function testCashComponentComputesTaxFirst(): void
    {
        $code = $this->source(self::CASH_COMPONENT);

        self::assertMatchesRegularExpression(
            '/row\.vat\s*=\s*[^\n]*\(\s*g\s*\*\s*r\s*\)\s*\/\s*\(\s*100\s*\+\s*r\s*\)/',
            $code,
            'Pokladna musí počítat daň koeficientem sazba/(100+sazba) — § 37 odst. 2 ZDPH.',
        );
        self::assertStringNotContainsString(
            'row.base = round2(g / (1 + r / 100))',
            $code,
            'Vzorec „základ první" se rozchází s InvoiceMath o haléř (12 %). Viz SSOT-REGISTR.md.',
        );
    }

    /**
     * Backend nesmí přebírat rozpad od klienta. Kontroluje se, že normalizace vstupu
     * volá přepočet a že přepočet jde přes SSOT `InvoiceMath` v režimu shora —
     * vlastní varianta vzorce v pokladně je přesně ta divergence, kvůli které
     * tenhle guard vznikl.
     */
    public function testCashBackendRecomputesSplitViaInvoiceMath(): void
    {
        $code = $this->source('api/src/Service/Accounting/Cash/CashDocumentService.php');

        self::assertStringContainsString(
            'self::recomputeVatLines($vatLines)',
            $code,
            'normalize() musí rozpad DPH přepočítat — jinak je autoritativní hodnota z klienta.',
        );
        self::assertMatchesRegularExpression(
            '/function recomputeVatLines\b[\s\S]{0,1600}InvoiceMath::compute\(\s*\$items\s*,\s*false\s*,\s*true\s*\)/',
            $code,
            'Přepočet musí jít přes InvoiceMath v režimu shora (§ 37/2), ne vlastním vzorcem.',
        );
    }

    /**
     * Issue #82 — koeficient se NIKDY nesmí spočítat dřív než násobení.
     *
     * `base * (rate/100)` uloží `rate/100` do floatu (0,21 není v IEEE754 přesné)
     * a teprve pak násobí; `base * rate / 100` násobí celými čísly a dělí až nakonec.
     * Rozdíl je REÁLNÝ a změřený: při 21 % dá jiný haléř u **676 z 500 000** částek
     * (první případ 29,50 → 6,19 místo 6,20), při 12 % u žádné.
     *
     * Zakázaný tvar se do frontendu vrátil v `RecurringForm.vue` — náhled šablony se
     * tím rozcházel s fakturou, kterou vygeneruje `InvoiceMath` na backendu. Guard
     * hlídá celý `web/src`, protože se to očividně stane znovu.
     */
    public function testFrontendNeverPrecomputesTheVatCoefficient(): void
    {
        $webSrc = dirname(__DIR__, 3) . '/web/src';
        self::assertDirectoryExists($webSrc, 'web/src zmizel — guard by nekontroloval nic.');

        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($webSrc, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()
                || !in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
                continue;
            }
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $i => $line) {
                // Komentáře ven — zakázaný tvar se v nich cituje jako vysvětlivka,
                // takže by guard hlásil vlastní dokumentaci.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // `* ( <cokoli> / 100 )` — koeficient spočítaný před násobením.
                if (preg_match('/\*\s*\(\s*[A-Za-z_$][\w.$\[\]\']*\s*\/\s*100\s*\)/', $line) !== 1) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($webSrc) + 1));
                $offenders[] = sprintf('web/src/%s:%d — %s', $rel, $i + 1, trim($line));
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Zakázaný tvar výpočtu DPH (issue #82) — koeficient spočítaný před násobením:\n  %s\n\n"
                . 'Piš `base * rate / 100`, ne `base * (rate / 100)`. Rozdíl je jeden haléř '
                . 'u 0,14 %% částek při 21 %% a rozejde frontend s InvoiceMath.',
            implode("\n  ", $offenders),
        ));
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path, 'Komponenta se přesunula — aktualizuj guard.');

        return (string) file_get_contents($path);
    }
}
