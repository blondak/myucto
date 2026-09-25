<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\CreditCard;

/**
 * Druh pohybu na úvěrovém účtu kreditní karty - jediné místo, které ho určuje.
 *
 * Volají ho parsery výpisů (koncovka karty patří jen ke karetním operacím) i detektor
 * účtování úroků, poplatků a splátek ({@see \MyInvoice\Service\Accounting\CreditCard\CreditCardChargeDetector}).
 * Druh se neukládá: počítá se z popisu pohybu, do kterého parser deterministicky vkládá
 * typ transakce z výpisu jako první část („Nákup na internetu | d.tran. …"). Platí tak
 * i pro výpis, který dorazí jinou cestou než přes stránku Kreditní karty.
 *
 * Kladná částka (připsáno na úvěrový účet) je splátka, odměna nebo vratka; záporná
 * (čerpáno) je nákup, výběr, úrok nebo poplatek. Pořadí testů je záměrné: typ splátky
 * nese slovo „úrok" („SPLÁTKA ÚVĚRU/ÚROKU"), ale je kladný, takže ho úrok nepřebije.
 */
final class CreditCardTransactionKind
{
    public const PURCHASE = 'purchase';
    public const REFUND = 'refund';
    public const REPAYMENT = 'repayment';
    public const INTEREST = 'interest';
    public const FEE = 'fee';
    public const CASH = 'cash';
    public const REWARD = 'reward';

    public const ALL = [
        self::PURCHASE, self::REFUND, self::REPAYMENT, self::INTEREST,
        self::FEE, self::CASH, self::REWARD,
    ];

    /** Karetní operace - jen ty nesou koncovku karty. */
    public const CARD_KINDS = [self::PURCHASE, self::REFUND, self::CASH];

    public static function classify(?string $description, float $amount): string
    {
        // Jen typ transakce (první část popisu) - jméno obchodníka nebo místo ve zbytku
        // popisu („POPLATKY.CZ", „Bankomat servis s.r.o.") nesmí druh pohybu změnit.
        $t = self::normalize(explode(' | ', (string) $description, 2)[0]);
        if ($amount > 0) {
            if (preg_match('/\bSPLATK|\bVASE PLATBA|\bUHRADA (?:UVERU|DLUHU|DLUZNE)|\bPLATBA DEKUJEME/', $t) === 1) {
                return self::REPAYMENT;
            }
            // ERSTE připisuje odměnu jako „Moneyback".
            if (preg_match('/\bODMEN|\bCASHBACK|\bMONEYBACK|\bBONUS/', $t) === 1) {
                return self::REWARD;
            }
            if (preg_match('/\bUROK/', $t) === 1) {
                return self::INTEREST;
            }
            if (preg_match('/\bPOPLAT/', $t) === 1) {
                return self::FEE;
            }
            return self::REFUND;
        }
        if (preg_match('/\bUROK/', $t) === 1) {
            return self::INTEREST;
        }
        if (preg_match('/\bPOPLAT|\bCENA ZA|\bVEDENI UCTU/', $t) === 1) {
            return self::FEE;
        }
        if (preg_match('/\bVYBER|\bBANKOMAT|\bATM\b|\bCASH ADVANCE|\bHOTOVOST/', $t) === 1) {
            return self::CASH;
        }
        return self::PURCHASE;
    }

    /** Velká písmena bez diakritiky - výpisy píšou tentýž typ jednou s háčky, jednou bez. */
    private static function normalize(string $text): string
    {
        $upper = mb_strtoupper($text, 'UTF-8');
        return strtr($upper, [
            'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N',
            'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
        ]);
    }
}
