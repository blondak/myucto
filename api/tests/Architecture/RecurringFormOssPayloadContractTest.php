<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Co uložení šablony z formuláře NEPOŠLE, to v databázi po uložení není.
 *
 * Sesterský guard k {@see InvoiceEditorOssPayloadContractTest}, jen o patro výš:
 * `RecurringTemplateRepository::replaceItems()` je taky DELETE + INSERT, takže každý OSS
 * sloupec, který zapisuje, MUSÍ být v mapě položky ve `RecurringForm.vue` — jinak ho
 * první uložení šablony z UI tiše vynuluje.
 *
 * U šablony je to horší než u faktury: šablonu nikdo neotevírá, aby se na ni podíval, ale
 * aby v ní opravil cenu — a doklady z ní pak vyrábí cron bez dozoru. Ztracené OSS
 * rozhodnutí by se projevilo až tím, že polská daň měsíce padá na ř. 1 tuzemského
 * přiznání. Přesně tenhle stav tady byl: repozitář i generátor OSS sloupce uměly
 * (migrace 1297), ale formulář pro ně neměl pole ani je neposílal zpět.
 *
 * Seznam sloupců se čte ze SSOT (z `replaceItems()`), ne z konstanty v testu — dvě kopie
 * by se rozešly a guard by hlídal minulost.
 *
 * Běhový důkaz téže věci přes celou trasu:
 * {@see \MyInvoice\Tests\Integration\Recurring\RecurringTemplateOssApiTest}.
 */
#[Group('architecture')]
final class RecurringFormOssPayloadContractTest extends TestCase
{
    private const REPOSITORY = '/api/src/Repository/RecurringTemplateRepository.php';
    private const FORM       = '/web/src/pages/recurring/RecurringForm.vue';

    public function testEveryOssColumnWrittenByReplaceItemsIsSentBackByTheForm(): void
    {
        $columns = self::ossItemColumns(self::read(self::REPOSITORY));
        // Kdyby se čtení konstanty rozbilo, guard by nad prázdným seznamem prošel vždycky.
        self::assertGreaterThanOrEqual(
            4,
            count($columns),
            'Ze RecurringTemplateRepository se nepodařilo přečíst OSS_ITEM_COLUMNS — guard by netvrdil nic.',
        );
        self::assertContains('oss_consumer_country', $columns,
            'Země spotřeby zmizela ze zápisu položek šablony (migrace 1297).');

        $payload = self::formItemPayloadRegion(self::read(self::FORM));
        self::assertStringContainsString('oss_applicable', $payload,
            'V payloadu formuláře nejsou OSS pole vůbec — buď chybí, nebo se rozbilo vyříznutí mapy položky.');

        foreach ($columns as $column) {
            self::assertStringContainsString(
                $column . ':',
                $payload,
                sprintf(
                    'Mapa položky v RecurringForm.vue neposílá „%s". replaceItems() je DELETE + INSERT, '
                        . 'takže první uložení šablony z UI tenhle sloupec vynuluje — a doklady z ní pak '
                        . 'generuje cron bez dozoru.',
                    $column,
                ),
            );
        }
    }

    /**
     * Formulář musí OSS sloupce taky NAČÍST. Bez čtecí strany by se rozhodnutí sice
     * uložilo, ale první editace šablony by ho poslala zpátky prázdné — tedy stejná
     * ztráta, jen o jedno uložení později.
     */
    public function testFormReadsOssColumnsBackFromTheTemplate(): void
    {
        $form = self::stripLineComments(self::read(self::FORM));
        foreach (self::ossItemColumns(self::read(self::REPOSITORY)) as $column) {
            self::assertStringContainsString(
                'it.' . $column,
                $form,
                sprintf('RecurringForm.vue nečte „%s" z načtené šablony — editace by ho vynulovala.', $column),
            );
        }
    }

    /**
     * OSS sloupce položky šablony ze SSOT konstanty repozitáře (tu používá `replaceItems()`
     * pro INSERT i `ossItemSelect()` pro SELECT).
     *
     * @return list<string>
     */
    private static function ossItemColumns(string $php): array
    {
        $start = strpos($php, 'private const OSS_ITEM_COLUMNS');
        if ($start === false) {
            return [];
        }
        $end = strpos($php, '];', $start);
        if ($end === false) {
            return [];
        }

        preg_match_all("/'(oss_[a-z_]+)'/", substr($php, $start, $end - $start), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Mapa položky z `submit()` — od `items: form.value.items.map(` po uzavření téhož
     * volání (počítají se závorky, ne řádky). Komentáře se odstraní PŘED hledáním:
     * guard, kterému stačí výskyt řetězce v komentáři, nekontroluje nic.
     */
    private static function formItemPayloadRegion(string $vue): string
    {
        $vue = self::stripLineComments($vue);
        $anchor = strpos($vue, 'items: form.value.items.map(');
        if ($anchor === false) {
            return '';
        }
        $open = strpos($vue, '(', $anchor);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($vue);
        for ($i = $open; $i < $length; $i++) {
            if ($vue[$i] === '(') {
                $depth++;
            } elseif ($vue[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($vue, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }

    /** Řádkové komentáře pryč; uvozovka před `//` znamená, že jde o obsah řetězce (URL). */
    private static function stripLineComments(string $source): string
    {
        $out = [];
        foreach (explode("\n", $source) as $line) {
            $pos = strpos($line, '//');
            if ($pos !== false && !preg_match('/[\'"`]/', substr($line, 0, $pos))) {
                $line = substr($line, 0, $pos);
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
