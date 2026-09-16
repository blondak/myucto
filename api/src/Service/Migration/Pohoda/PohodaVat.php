<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/**
 * Členění DPH z Pohody převedené na zařazení dokladu v MyÚčtu.
 *
 * Zkratky členění (`UD`, `PD`, `PDslRegEU`…) si účetní jednotka v Pohodě může upravit
 * i doplnit, proto se nepárují podle názvu, ale podle řádků přiznání, které členění
 * v číselníku jednotky nese (`lineInVATReturn` v `06_cleneni_dph.xml`):
 *
 *   uskutečněná plnění   ř. 1,2 tuzemsko (kód se odvodí ze sazby), 1,2,51 prodej majetku
 *                        mimo koeficient, 20 dodání zboží do EU, 21 služba do EU, 22 vývoz,
 *                        23 nový dopravní prostředek, 24 zasílání zboží, 25 tuzemský
 *                        přenos (dodavatel), 26 plnění s místem mimo tuzemsko, 31 třístranný
 *                        obchod, 50 osvobozené bez nároku, 50,51 osvobozené mimo koeficient
 *   přijatá plnění       ř. 40,41 tuzemský odpočet (`PK…` krácený podle § 76), 40,41,47
 *                        pořízení majetku, 43,44 odpočet ze samovyměření - daň na výstupu
 *                        nese interní doklad s členěním `DD…`
 *   `---`                mimo přiznání
 *
 * Členění jiných řádků (dovoz ř. 42, insolvence, nedobytné pohledávky, vrácená daň…)
 * převod nezařazuje a doklad nechá jako koncept k ruční kontrole.
 */
final class PohodaVat
{
    /** @param array<string,array{lines:list<int>,name:string,section:string}> $classes */
    private function __construct(private readonly array $classes) {}

    public static function fromExport(PohodaExport $export): self
    {
        $classes = [];
        foreach ($export->records('vat_classes', 'classificationVAT') as $r) {
            $h = PohodaXml::get($r, 'classificationVATHeader');
            $code = PohodaXml::text($h, 'code');
            if ($code === '') {
                continue;
            }
            $classes[$code] = [
                'lines' => self::parseLines(PohodaXml::text($h, 'lineInVATReturn')),
                'name' => PohodaXml::text($h, 'name'),
                'section' => PohodaXml::text($h, 'sectionInVATLedgerStatement'),
            ];
        }
        return new self($classes);
    }

    public function known(string $code): bool
    {
        return isset($this->classes[$code]);
    }

    public function name(string $code): string
    {
        return $this->classes[$code]['name'] ?? $code;
    }

    /**
     * Vydaný doklad (faktura, pohledávka, příjem v pokladně).
     *
     * @return array{in_return:bool,code:?string}|null null = převod členění nezařadí
     */
    public function sale(string $code, float $rate): ?array
    {
        $lines = $this->classes[$code]['lines'] ?? null;
        if ($lines === null) {
            return null;
        }
        if ($lines === []) {
            return ['in_return' => false, 'code' => null];
        }
        $mapped = match ($lines) {
            [1, 2] => ['in_return' => true, 'code' => null],
            [1, 2, 51] => match (true) {
                abs($rate - 21.0) < 0.01 => ['in_return' => true, 'code' => '1m'],
                abs($rate - 12.0) < 0.01 => ['in_return' => true, 'code' => '2m'],
                default => null,
            },
            [20] => ['in_return' => true, 'code' => '20'],
            [21] => ['in_return' => true, 'code' => '22'],
            [22] => ['in_return' => true, 'code' => '26'],
            [23] => ['in_return' => true, 'code' => '23n'],
            [24] => ['in_return' => true, 'code' => '24z'],
            [25] => ['in_return' => true, 'code' => '25s'],
            [26] => ['in_return' => true, 'code' => '26s'],
            [31] => ['in_return' => true, 'code' => '31'],
            [50] => ['in_return' => true, 'code' => '3'],
            [50, 51] => ['in_return' => true, 'code' => '3m'],
            default => null,
        };
        return $mapped;
    }

    /**
     * Přijatý doklad (faktura, závazek, výdej v pokladně).
     *
     * `reverse` = odpočet ze samovyměření (ř. 43, 44): daň na výstupu nese interní doklad
     * a převod ji k faktuře dohledá ({@see InvoiceImporter}).
     *
     * @return array{in_return:bool,deduction:'full'|'reduced'|'none',reverse:bool}|null
     */
    public function purchase(string $code): ?array
    {
        $lines = $this->classes[$code]['lines'] ?? null;
        if ($lines === null) {
            return null;
        }
        if ($lines === []) {
            return ['in_return' => false, 'deduction' => 'none', 'reverse' => false];
        }
        $deduction = str_starts_with($code, 'PK') ? 'reduced' : 'full';
        return match ($lines) {
            [40, 41], [40, 41, 47] => ['in_return' => true, 'deduction' => $deduction, 'reverse' => false],
            [43, 44], [43, 44, 47] => ['in_return' => true, 'deduction' => $deduction, 'reverse' => true],
            default => null,
        };
    }

    /**
     * Členění, které v Pohodě vynucuje oddíl A.5 kontrolního hlášení (`UDA5` „pod limit
     * 10 000 Kč") bez ohledu na částku dokladu. Běžné tuzemské členění (`UD`) má oddíly
     * „A.4., A.5." a rozhoduje částka.
     */
    public function forcesA5(string $code): bool
    {
        return rtrim($this->classes[$code]['section'] ?? '', '. ') === 'A.5';
    }

    /**
     * Kód zařazení samovyměření podle výstupního řádku členění interního dokladu (`DD…`):
     * ř. 3,4 pořízení zboží z EU, 5,6 služba z EU, 7,8 dovoz, 10,11 tuzemský přenos,
     * 12,13 ostatní plnění ze zahraničí.
     */
    public function selfAssessmentCode(string $code): ?string
    {
        return match ($this->classes[$code]['lines'] ?? null) {
            [3, 4] => '23',
            [5, 6] => '24e',
            [7, 8] => '25',
            [10, 11] => '5',
            [12, 13] => '24',
            default => null,
        };
    }

    /** @return list<int> */
    private static function parseLines(string $text): array
    {
        if (preg_match_all('/\d+/', $text, $m) === 0) {
            return [];
        }
        $lines = array_values(array_unique(array_map('intval', $m[0])));
        sort($lines);
        return $lines;
    }
}
