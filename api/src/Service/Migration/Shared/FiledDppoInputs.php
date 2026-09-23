<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;

/**
 * Pravidlo převzetí podaného přiznání k DPPO do ručních vstupů přiznání MyÚčta. Jediné
 * místo, které rozhoduje, co z podaného přiznání MyÚčto přebírá a co spočte samo:
 * převod z PREMIER (hodnoty `D_PO2`) i převzetí podaného EPO XML DPPDP9 volají totéž.
 *
 * Výsledek hospodaření (ř. 10), odpisy (ř. 50/150) a nedaňové účty spočte MyÚčto
 * z deníku, osnovy a karet majetku. Z účetnictví ale neplynou úpravy, které účetní
 * zadala do přiznání ručně. Ty se převezmou jako ruční položky přiznání:
 *
 * - zvyšující: ř. 20, 30, 61, 62 a část ř. 40, kterou výpočet z účetnictví nepokryje;
 * - snižující: ř. 100-162 kromě ř. 150, z ř. 160 jen část nad rozdíl ZC z karet majetku;
 *   ř. 112 je u zdroje, který ho používá pro paušální výdaj na dopravu (§ 24 odst. 2
 *   písm. zt), paušálem i s odpovídajícím vrácením PHM na ř. 40;
 * - odečet ztráty (ř. 230), odečty § 34 odst. 4 (ř. 242, 243), dary (ř. 260)
 *   a zaplacené zálohy.
 *
 * Každá položka nese řádek podání (`line`), takže ji přiznání vykáže na stejném řádku
 * jako podání, ne na obecném ř. 62/162.
 *
 * Třída je čistá (bez DB): částky spočtené z účetnictví dodává volající.
 */
final class FiledDppoInputs
{
    /** Řádky zvyšující základ, které se přebírají celé (ř. 40 jen z části, viz build()). */
    public const INCREASE_LINES = [20, 30, 61, 62];

    /** Řádky snižující základ, které se přebírají; ř. 150 (odpisy) spočte MyÚčto z karet. */
    public const DECREASE_LINES = [100, 101, 109, 110, 111, 112, 120, 130, 140, 160, 161, 162];

    public const TRAVEL_LINE = 112;

    /**
     * @param array<int,float> $filed řádek II. oddílu → částka z podaného přiznání; pořadí
     *   položek ve výsledku drží INCREASE_LINES a DECREASE_LINES, ne pořadí klíčů
     * @param array{40?:float,160?:float} $computed část ř. 40 a ř. 160, kterou MyÚčto spočte
     *   z účetnictví samo ({@see DppoReturnCalculator::accountingAdjustments()}); převezme
     *   se jen zbytek nad ni
     * @param bool $line112IsTravel ř. 112 zdroje je paušál na dopravu
     * @param array{line:string,line40:string,line40_travel:string,travel:string} $texts
     *   texty položek; `line` je šablona sprintf s číslem řádku
     * @param float $advancesPaid zaplacené zálohy na daň
     * @return array{
     *   inputs: array<string,mixed>,
     *   increase: list<array{text:string,amount:float,kind?:string,line:int}>,
     *   decrease: list<array{text:string,amount:float,kind?:string,line:int}>,
     *   shortfalls: array<int,array{computed:float,filed:float}>,
     * }
     */
    public static function build(array $filed, array $computed, bool $line112IsTravel, array $texts, float $advancesPaid = 0.0): array
    {
        $travel = $line112IsTravel && (float) ($filed[self::TRAVEL_LINE] ?? 0) > 0.0;
        $increase = [];
        $decrease = [];
        foreach (self::INCREASE_LINES as $line) {
            $amount = round((float) ($filed[$line] ?? 0), 2);
            if ($amount > 0.0) {
                $increase[] = ['text' => sprintf($texts['line'], (string) $line), 'amount' => $amount, 'line' => $line];
            }
        }
        $shortfalls = [];
        $computed40 = round((float) ($computed[40] ?? 0), 2);
        $line40 = round((float) ($filed[40] ?? 0) - $computed40, 2);
        if ($line40 > 0.0) {
            $item = ['text' => $travel ? $texts['line40_travel'] : $texts['line40'], 'amount' => $line40];
            if ($travel) {
                $item['kind'] = DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL;
            }
            $item['line'] = 40;
            $increase[] = $item;
        } elseif ($line40 < 0.0) {
            $shortfalls[40] = ['computed' => $computed40, 'filed' => (float) ($filed[40] ?? 0)];
        }
        foreach (self::DECREASE_LINES as $line) {
            $amount = round((float) ($filed[$line] ?? 0), 2);
            if ($line === 160) {
                // Rozdíl ZC vyřazeného majetku spočte MyÚčto z karet sám; převzít celý
                // ř. 160 by ho odečetlo podruhé.
                $computed160 = round((float) ($computed[160] ?? 0), 2);
                $rest = round($amount - $computed160, 2);
                if ($rest < 0.0) {
                    $shortfalls[160] = ['computed' => $computed160, 'filed' => $amount];
                }
                $amount = $rest;
            }
            if ($amount <= 0.0) {
                continue;
            }
            $isTravel = $line112IsTravel && $line === self::TRAVEL_LINE;
            $item = ['text' => $isTravel ? $texts['travel'] : sprintf($texts['line'], (string) $line), 'amount' => $amount];
            if ($isTravel) {
                $item['kind'] = DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL;
            }
            $item['line'] = $line;
            $decrease[] = $item;
        }
        $inputs = [];
        if ($increase !== []) {
            $inputs['manual_increase_items'] = $increase;
        }
        if ($decrease !== []) {
            $inputs['manual_decrease_items'] = $decrease;
        }
        $loss = round((float) ($filed[230] ?? 0), 2);
        if ($loss > 0.0) {
            $inputs['loss_carryforward'] = $loss;
        }
        $advances = round($advancesPaid, 2);
        if ($advances > 0.0) {
            $inputs['tax_paid_advances'] = $advances;
        }
        foreach ([242 => 'rnd_deduction', 243 => 'education_deduction', 260 => 'donations'] as $line => $key) {
            $amount = round((float) ($filed[$line] ?? 0), 2);
            if ($amount > 0.0) {
                $inputs[$key] = $amount;
            }
        }

        return ['inputs' => $inputs, 'increase' => $increase, 'decrease' => $decrease, 'shortfalls' => $shortfalls];
    }
}
