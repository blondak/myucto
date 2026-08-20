<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\PayrollAgendaGroupCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Žádná reálná agenda nesmí skončit ve skupině `other` nedopatřením.
 *
 * Proč zrovna tenhle test: klasifikaci dělal ručně psaný REGEXP, který
 * vyžadoval hned za kódem oddělovač nebo konec řetězce. Reálné kódy ale nesou
 * ROČNÍK (`JMHZ25`, `PREZEC26`, `REGZELDOPL25`) a `ELDP` v seznamu nebyl vůbec,
 * takže všechny tyhle povinnosti padaly do `other`. Existující test to
 * nezachytil, protože si seedoval vlastní kódy BEZ ročníku (`JMHZ`, `HOZ`) —
 * zelená, která nekontrolovala to, co běží v provozu.
 *
 * Test proto nečte výčet řetězců, ale KONSTANTY služeb: jakmile někdo přidá
 * novou agendu (nebo posune ročník) a nezařadí ji do
 * {@see PayrollAgendaGroupCatalog}, spadne to tady.
 *
 * Sken jde ZÁMĚRNĚ přes celé `src/Service`, ne jen přes mzdový modul —
 * agendy přibývají i na daňové (EPO) straně a i ty musí projít vědomým
 * rozhodnutím. Kdo novou agendu do skupiny nechce, zapíše ji do
 * `DELIBERATE_OTHER`; co není ani tam, ani ve skupině, je opomenutí.
 */
final class PayrollAgendaGroupCoverageTest extends TestCase
{
    /**
     * Pojistka proti vakuově zelenému testu: kdyby se sken rozbil a nenašel
     * nic, prošlo by prázdno. Číslo se smí ZVYŠOVAT s novými agendami.
     */
    private const MINIMUM_KNOWN_CODES = 20;

    public function testEveryAgendaCodeConstantHasGroup(): void
    {
        $codes = $this->agendaCodeConstants();
        self::assertGreaterThanOrEqual(
            self::MINIMUM_KNOWN_CODES,
            count($codes),
            'Sken konstant agend nic nenašel — test by nekontroloval nic.',
        );

        $unclassified = [];
        foreach ($codes as $reference => $code) {
            if (PayrollAgendaGroupCatalog::groupOf($code)
                    === PayrollAgendaGroupCatalog::GROUP_OTHER
                && !PayrollAgendaGroupCatalog::isDeliberatelyOther($code)
            ) {
                $unclassified[$reference] = $code;
            }
        }

        self::assertSame(
            [],
            $unclassified,
            'Tyhle agendy nemají skupinu a v přehledu podání by je nikdo'
            . ' neviděl. Zařaď je v PayrollAgendaGroupCatalog — a když do'
            . ' `other` patří, napiš je do DELIBERATE_OTHER.',
        );
    }

    /**
     * Daňová podání EPO patří do `other` VĚDOMĚ, ne omylem.
     */
    public function testEpoAgendasStayDeliberatelyOther(): void
    {
        foreach (['DPHDP3', 'DPHKH1', 'OSVC25', 'DPPDP9'] as $code) {
            self::assertTrue(
                PayrollAgendaGroupCatalog::isDeliberatelyOther($code),
                "Agenda {$code} zmizela ze seznamu vědomých výjimek.",
            );
            self::assertSame(
                PayrollAgendaGroupCatalog::GROUP_OTHER,
                PayrollAgendaGroupCatalog::groupOf($code),
                "Daňová agenda {$code} nepatří do mzdových panelů.",
            );
        }
    }

    /**
     * Ročník v kódu nesmí zařazení rozbít — ani ten, který ještě nenastal.
     */
    public function testGroupSurvivesFutureYearSuffixes(): void
    {
        foreach ([
            'JMHZ26' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
            'JMHZ_2030' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
            'PREZEC27' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
            'REGZELDOPL30' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
            'REGZEL31' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
            'ELDP' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
            'HOZ_2031' => PayrollAgendaGroupCatalog::GROUP_HEALTH,
            'PPZ28' => PayrollAgendaGroupCatalog::GROUP_HEALTH,
            ' jmhz25 ' => PayrollAgendaGroupCatalog::GROUP_CSSZ,
        ] as $code => $expected) {
            self::assertSame(
                $expected,
                PayrollAgendaGroupCatalog::groupOf((string) $code),
                "Kód {$code} se zařadil jinam, než měl.",
            );
        }
    }

    /**
     * `other` musí zůstat živá skupina — `agenda_code` je u povinnosti volný
     * text, takže neznámý kód nesmí spadnout do některého panelu omylem.
     */
    public function testUnknownCodeStaysOther(): void
    {
        foreach (['VYMYSLENA', 'JMHZX', 'HOZZ', 'REGZELDOPLXX'] as $code) {
            self::assertSame(
                PayrollAgendaGroupCatalog::GROUP_OTHER,
                PayrollAgendaGroupCatalog::groupOf($code),
                "Neznámý kód {$code} se neměl zařadit.",
            );
        }
    }

    /**
     * Konstanty `AGENDA_*` ze všech služeb aplikace.
     *
     * Skalární konstanta nese kód přímo (`AGENDA_CODE = 'JMHZ25'`), pole je
     * mapa agenda → něco (`AGENDA_SCHEMAS`), takže kód je v KLÍČI.
     *
     * @return array<string,string> „Trida::KONSTANTA" => kód agendy
     */
    private function agendaCodeConstants(): array
    {
        $codes = [];
        foreach ($this->serviceClasses() as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if (!str_contains($name, 'AGENDA')) {
                    continue;
                }
                $reference = $class . '::' . $name;
                if (is_string($value) && $this->looksLikeAgendaCode($value)) {
                    $codes[$reference] = $value;
                    continue;
                }
                if (!is_array($value)) {
                    continue;
                }
                foreach (array_keys($value) as $key) {
                    if (is_string($key) && $this->looksLikeAgendaCode($key)) {
                        $codes[$reference . '[' . $key . ']'] = $key;
                    }
                }
            }
        }
        ksort($codes);

        return $codes;
    }

    /**
     * Kód agendy je vždy VERZÁLKAMI a aspoň tři znaky — `regular`, `open`
     * a podobné stavové řetězce se do skenu dostat nesmí.
     */
    private function looksLikeAgendaCode(string $value): bool
    {
        return preg_match('/^[A-Z][A-Z0-9]{2,}(?:[_-][A-Z0-9]+)*$/D', $value) === 1;
    }

    /** @return list<class-string> */
    private function serviceClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Service';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root),
        );
        $prefixLength = strlen(str_replace('\\', '/', $root)) + 1;
        $classes = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo
                || $file->getExtension() !== 'php'
            ) {
                continue;
            }
            $relative = substr(
                str_replace('\\', '/', $file->getPathname()),
                $prefixLength,
            );
            $class = 'MyInvoice\\Service\\'
                . str_replace('/', '\\', substr($relative, 0, -4));
            if (class_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
