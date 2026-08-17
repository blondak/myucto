<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Codebook\HealthInsurers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontraktová brána mezi číselníkem zdravotních pojišťoven a klientem.
 *
 * ## Co hlídá
 *
 * Číselník žije na dvou místech: {@see HealthInsurers::CODES} (zdroj pravdy,
 * na něm stojí validace kódu až do zákonného podání) a `HEALTH_INSURERS`
 * ve `web/src/utils/healthInsurers.ts` (nabídka ve formuláři). Druhá strana je
 * RUČNÍ kopie první a nic je nespojuje, takže se rozejít musí — je to jen
 * otázka času. Číselník se přitom mění: pojišťovny zanikají a slučují se.
 *
 * Projev rozejití je tichý a v obou směrech drahý:
 *  - kód navíc v UI → uživatel vybere pojišťovnu, kterou server odmítne
 *    hláškou {@see HealthInsurers::invalidCodeMessage()};
 *  - kód chybějící v UI → pojišťovnu nejde vybrat, i když ji server uzná;
 *  - rozdílný NÁZEV → uživatel vybírá podle štítku, který neodpovídá
 *    instituci, na kterou pak odejde platba pojistného.
 *
 * ## Proč kontraktový test, a ne generování TS z PHP
 *
 * Táž úvaha jako u {@see PayrollEnumContractTest} (viz jeho „Proč PHP a ne
 * vitest"), a pro tenhle číselník platí dvojnásob:
 *
 * 1. Generovaný `.ts` by byl artefakt, jehož ZASTARÁNÍ je přesně ta chyba,
 *    kterou chceme chytat — kdo zapomene regenerovat, dostane zelenou.
 * 2. Znamenalo by to nový build krok v řetězu, který dnes staví jen Vite;
 *    číselník o sedmi řádcích takovou infrastrukturu nezaplatí.
 * 3. CI pouští `--testsuite Architecture` jako samostatný krok, kdežto plný
 *    vitest v CI neběží — brána psaná ve vitestu by bránou nebyla.
 *
 * Test proto čte KAŽDOU stranu z jejího vlastního zdroje: PHP přes konstantu
 * (reflexe, ne parsování), `.ts` jako text.
 */
#[Group('architecture')]
final class HealthInsurerCodebookContractTest extends TestCase
{
    private const TS_PATH = '/web/src/utils/healthInsurers.ts';

    /**
     * Kód i název musí sedět, a to VČETNĚ pořadí: `healthInsurerOptions()` ho
     * podává do nabídky tak, jak je, takže přeházení je uživatelsky viditelná
     * změna, kterou má někdo udělat na obou stranách vědomě.
     */
    public function testTypeScriptCodebookMirrorsThePhpOne(): void
    {
        $php = [];
        foreach (HealthInsurers::all() as $code => $name) {
            $php[] = (string) $code . ' — ' . $name;
        }
        $typescript = [];
        foreach ($this->typeScriptInsurers() as $code => $name) {
            $typescript[] = $code . ' — ' . $name;
        }

        self::assertSame($php, $typescript, sprintf(
            "Číselník zdravotních pojišťoven ve `web/src/utils/healthInsurers.ts`\n"
                . "se rozešel s `%s`.\n\n"
                . "Kód navíc v UI = uživatel vybere pojišťovnu, kterou server odmítne.\n"
                . "Kód chybějící v UI = pojišťovnu nejde vybrat, i když ji server uzná.\n"
                . 'Jiný název = štítek neodpovídá instituci, na kterou odejde platba.',
            HealthInsurers::class,
        ));
    }

    /**
     * Bez téhle pojistky by z rozbitého čtenáře `.ts` vznikl test, který
     * porovná prázdno s prázdnem a projde vždy.
     */
    public function testBothSidesAreNonEmpty(): void
    {
        self::assertNotSame([], HealthInsurers::all(), 'PHP číselník je prázdný.');
        self::assertNotSame(
            [],
            $this->typeScriptInsurers(),
            'Z `healthInsurers.ts` se nepřečetla žádná pojišťovna — test by nehlídal nic.',
        );
    }

    /**
     * Zkratky pro chybové hlášky jsou třetí kopie téhož seznamu, jen uvnitř
     * PHP. Chybějící zkratka se projeví tím, že {@see
     * HealthInsurers::invalidCodeMessage()} nabídne uživateli neúplný výběr.
     */
    public function testEveryInsurerHasAnAbbreviationForErrorMessages(): void
    {
        $message = HealthInsurers::listForMessage();
        $missing = [];
        foreach (array_keys(HealthInsurers::all()) as $code) {
            if (!str_contains($message, (string) $code . ' ')) {
                $missing[] = (string) $code;
            }
        }

        self::assertSame([], $missing, sprintf(
            'Kód %s nemá zkratku — hláška o neplatném kódu ho uživateli nenabídne.',
            implode(', ', $missing),
        ));
    }

    /**
     * Dvojice kód → název z `HEALTH_INSURERS`, v pořadí, v jakém jsou zapsané.
     *
     * @return array<string,string>
     */
    private function typeScriptInsurers(): array
    {
        $path = dirname(__DIR__, 3) . self::TS_PATH;
        self::assertFileExists($path);
        // \R kvůli CRLF v pracovním stromu (git autocrlf na Windows).
        $source = preg_replace('/\R/', "\n", (string) file_get_contents($path)) ?? '';

        $start = strpos($source, 'export const HEALTH_INSURERS');
        self::assertNotFalse($start, 'V `healthInsurers.ts` není konstanta HEALTH_INSURERS.');
        // Ne prostě první `[` — typová anotace `readonly HealthInsurer[]` ho má
        // dřív než samotný literál.
        $open = strpos($source, '= [', $start);
        self::assertNotFalse($open, 'HEALTH_INSURERS není inicializovaná polem.');
        $close = strpos($source, "\n]", $open);
        self::assertNotFalse($close, 'Pole HEALTH_INSURERS není ukončené.');

        preg_match_all(
            "/\{\s*code:\s*'([^']*)',\s*name:\s*'([^']*)'\s*\}/",
            substr($source, $open, $close - $open),
            $matches,
            PREG_SET_ORDER,
        );

        $insurers = [];
        foreach ($matches as $match) {
            $insurers[$match[1]] = $match[2];
        }

        return $insurers;
    }
}
