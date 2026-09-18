<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Portfolio\PortfolioCheckService;
use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přehled firem smí počítat jen AKUTNÍ kontroly.
 *
 * Rozcestník účetní kanceláře je denní nástroj. Kontroly vázané na rozvahový den
 * (účetní odpisy roku, inventarizace, nerozdělený VH, neuzavřený minulý rok) se
 * u otevřeného roku rozsvítí jako NORMÁLNÍ stav rozdělané práce a svítí měsíce.
 * V pruhu pak trvale sedí čtyři nálezy, které nikdo neřeší — a mezi nimi zapadne
 * chybějící zápis nebo saldo, které nesedí. Proto {@see PortfolioCheckService::SEASONAL_KEYS}
 * zůstávají jen v měsíční kontrole a v uzávěrkové bráně.
 *
 * Guard je tu proto, že samotné rozdělení na dvě konstanty nic nevynucuje: stačí
 * v `summary()` poslat do `buildChecks()` sjednocení obou a přehled se potichu vrátí
 * k šumu. Kontroluje se tedy skutečné VOLÁNÍ, ne existence konstanty — a to nad
 * kódem bez komentářů, aby guard nešlo uspokojit větou v docbloku.
 */
#[Group('architecture')]
final class PortfolioAcuteChecksTest extends TestCase
{
    private const SERVICE = __DIR__ . '/../../src/Service/Portfolio/PortfolioCheckService.php';

    public function testAcuteAndSeasonalKeysAreDisjoint(): void
    {
        $overlap = array_intersect(PortfolioCheckService::ACUTE_KEYS, PortfolioCheckService::SEASONAL_KEYS);
        self::assertSame([], array_values($overlap), 'Klíč nemůže být zároveň akutní i sezónní.');
        self::assertNotEmpty(PortfolioCheckService::ACUTE_KEYS);
    }

    /**
     * Sezónní sada je věcné rozhodnutí, ne úklid: každý klíč tu je proto, že u OTEVŘENÉHO
     * roku chybí legitimně. Kdyby se sem měl přidat další, patří k němu důvod v docbloku
     * konstanty a řádek tady — jinak se seznam za rok rozteče zpátky do celé sady.
     */
    public function testSeasonalKeysCoverYearEndWork(): void
    {
        self::assertSame(
            ['depreciation_missing', 'inventory_unresolved', 'prior_period_open', 'vh_431_undistributed'],
            self::sorted(PortfolioCheckService::SEASONAL_KEYS),
        );
    }

    public function testSummaryRequestsOnlyAcuteKeys(): void
    {
        $body = self::methodBodyWithoutComments(self::SERVICE, 'summary');

        self::assertStringContainsString('buildChecks', $body, 'summary() přestal počítat kontroly — guard míří jinam.');
        self::assertStringContainsString(
            'self::ACUTE_KEYS',
            $body,
            'summary() musí do buildChecks() poslat AKUTNÍ sadu, jinak se do přehledu vrátí sezónní kontroly.',
        );
        self::assertStringNotContainsString(
            'SEASONAL_KEYS',
            $body,
            'Sezónní kontroly se v přehledu firem nepočítají — patří do měsíční kontroly.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/self::KEYS\b/',
            $body,
            'Sjednocená sada KEYS už neexistuje; přehled se ptá výhradně na ACUTE_KEYS.',
        );
    }

    /** Tělo metody bez komentářů — komentář nesmí guard ani uspokojit, ani shodit. */
    private static function methodBodyWithoutComments(string $file, string $method): string
    {
        $code = (string) file_get_contents($file);
        $region = null;
        foreach (PhpSourceRegions::symbols($code) as $symbol) {
            if ($symbol['name'] === $method) {
                $region = $symbol;
                break;
            }
        }
        self::assertNotNull($region, 'Metoda ' . $method . '() ve zdroji chybí — guard se má opravit, ne smazat.');

        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($token[2] < $region['startLine'] || $token[2] > $region['endLine']) {
                    continue;
                }
                $out .= $token[1];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        sort($keys);
        return $keys;
    }
}
