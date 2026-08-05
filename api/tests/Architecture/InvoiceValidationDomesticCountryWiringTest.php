<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * MRTVÝ PARAMETR JE HORŠÍ NEŽ POCTIVÁ KONSTANTA.
 *
 * `InvoiceValidation::invoice()` dostalo parametr `$domesticCountry`, aby validace
 * a derivace OSS mluvily o TÉMŽ tuzemsku — jenže ho zpočátku nepředával ani jeden
 * volající, takže validace dál jela na natvrdo zapsané 'CZ'. Navenek to vypadalo, že se
 * tuzemsko bere ze země dodavatele, a to je horší stav než viditelná konstanta: kdo si
 * signaturu přečte, uzavře nález, který ve skutečnosti otevřený zůstal.
 *
 * Test proto kontroluje SKUTEČNÁ VOLÁNÍ v `src/`, ne existenci parametru. Čte zdroják
 * a u každého výskytu `InvoiceValidation::invoice(` vytáhne vyváženou závorku argumentů —
 * hledání pouhého řetězce „domesticCountry" v souboru by prošlo i tehdy, když ho volání
 * míjí a slovo je jen v komentáři (přesně ten typ falešné zelené, který AGENTS.md
 * zakazuje).
 */
final class InvoiceValidationDomesticCountryWiringTest extends TestCase
{
    /**
     * Hranice vlevo je podstatná: `PurchaseInvoiceValidation::invoice(` obsahuje tenhle
     * řetězec jako podřetězec, a přijatá větev vlastní tuzemsko nemá — bez hranice by
     * test hlásil nález na místě, kterého se pravidlo netýká.
     */
    private const CALL = '/(?<![A-Za-z0-9_\\\\])InvoiceValidation::invoice\(/';

    /**
     * Země dodavatele smí přijít JEDINĚ z `OssItemDeriver::domesticCountry()` — to je
     * jediná definice tuzemska v systému. Vlastní dotaz do `supplier` by ji zduplikoval
     * a obě kopie by se dřív nebo později rozešly u dodavatele identifikovaného mimo ČR.
     */
    private const REQUIRED_SOURCE = 'domesticCountry(';

    public function testEveryCallerPassesTheSupplierCountry(): void
    {
        $calls = self::callSites();

        self::assertGreaterThanOrEqual(2, count($calls),
            'Volání validace faktury zmizela, nebo se změnil jejich tvar — test by pak nehlídal nic.');

        foreach ($calls as $where => $args) {
            self::assertStringContainsString(self::REQUIRED_SOURCE, $args,
                $where . ' volá validaci faktury bez země dodavatele, takže kontrola cizí sazby '
                    . 'jede na natvrdo zapsané tuzemsko.');
        }
    }

    /**
     * Argumenty všech volání v `src/`, klíčované „soubor:řádek".
     *
     * @return array<string, string>
     */
    private static function callSites(): array
    {
        $out = [];
        $root = dirname(__DIR__, 2) . '/src';
        /** @var iterable<\SplFileInfo> $files */
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match_all(self::CALL, $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as [$needle, $pos]) {
                $start = $pos + strlen($needle);
                $line = substr_count($code, "\n", 0, $pos) + 1;
                $out[basename($file->getPathname()) . ':' . $line] = self::balancedArguments($code, $start);
            }
        }

        return $out;
    }

    /** Text mezi otevírací závorkou volání a jí odpovídající zavírací. */
    private static function balancedArguments(string $code, int $start): string
    {
        $depth = 1;
        $length = strlen($code);
        for ($i = $start; $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $start, $i - $start);
                }
            }
        }

        self::fail('Volání InvoiceValidation::invoice() nemá uzavřenou závorku — zdroják se nedá přečíst.');
    }
}
