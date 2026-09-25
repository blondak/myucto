<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Je oprava zápisu jen PŘESUNEM mezi účty, který nemá daňový dopad?
 *
 * Zámek k datu (`accounting_supplier_settings.locked_until`) se posouvá s podaným
 * přiznáním k DPH. Chrání tedy DPH, ne kontaci nákladu: evidence DPH se počítá
 * z řádků dokladu ({@see \MyInvoice\Service\Report\VatLedgerService}), ne z deníku.
 * Přeúčtování 511 → 518.100 u zamčeného data proto na podané přiznání nesahá, a přesto
 * se dosud muselo dělat stornem a novým zápisem k dnešku. Náklad se tím přesunul
 * do jiného měsíce a v deníku zůstaly tři zápisy místo jednoho.
 *
 * Tahle třída rozhoduje, kdy se smí zápis v zamčeném datu přepsat NA MÍSTĚ. Porovnává
 * se NETTO pohyb (MD − Dal) per účet v haléřích, ne hrubé součty stran. Sloučení
 * protisměrných řádků téhož účtu (sleva zaúčtovaná jako Dal 518 se rozpustí do MD 501)
 * mění součet stran, ale ne to, co zápis znamená. Podmínky:
 *   - žádný daňový účet (34x: DPH, daň z příjmů, ostatní daně) nemění netto pohyb,
 *   - netto pohyb peněz (třída 2) a zvlášť pohledávek a závazků (31x–33x, 35x–37x) je
 *     v součtu stejný — přesun 321 → 325 projde, změna částky závazku ani banky ne,
 *   - když je za rok už PODANÉ přiznání k dani z příjmů, navíc:
 *       - všechny měněné účty patří do TÉŽE účtové třídy (5 ↔ 5, ne 5 ↔ 3), takže
 *         netto součet výsledkových účtů zůstává stejný,
 *       - nemění se netto součet podle daňové uznatelnosti (518.100 → 518.990 jde jen stornem).
 *     Dokud přiznání podané není, mění se tím jen základ daně, který se teprve spočítá
 *     (typicky časové rozlišení 518 → 381 u dodatečně doplněné faktury).
 *
 * Uzavřené účetní období (uzávěrka roku) tahle třída neřeší. To hlídá volající a platí
 * tam bez výjimky §35 ZoÚ. Je to čistá funkce, aby ji mohly zavolat obě strany téže
 * operace: {@see DocumentRepostService} (rozhodnutí před zápisem) i {@see PostingService}
 * (znovu pod zámkem, těsně před přepisem).
 */
final class TaxNeutralReclassification
{
    public const UNKNOWN_ACCOUNT = 'unknown_account';
    public const SPECIAL_ACCOUNT = 'special_account';
    public const TAX_ACCOUNT_CHANGED = 'tax_account_changed';
    public const AMOUNTS_CHANGED = 'amounts_changed';
    public const ACCOUNT_CLASS_CHANGED = 'account_class_changed';
    public const TAX_DEDUCTIBILITY_CHANGED = 'tax_deductibility_changed';

    /** Strop haléřového dorovnání úhrady; týž jako BankPostingService::ROUNDING_TOLERANCE_CENTS. */
    public const ROUNDING_TOLERANCE_CENTS = 100;

    private const MESSAGES = [
        self::UNKNOWN_ACCOUNT           => 'oprava používá účet, který není v osnově',
        self::SPECIAL_ACCOUNT           => 'oprava mění závěrkový nebo podrozvahový účet',
        self::TAX_ACCOUNT_CHANGED       => 'oprava mění účet daně (34x)',
        self::AMOUNTS_CHANGED           => 'oprava mění částku peněz, pohledávek nebo závazků',
        self::ACCOUNT_CLASS_CHANGED     => 'oprava přesouvá částku do jiné účtové třídy',
        self::TAX_DEDUCTIBILITY_CHANGED => 'oprava mění daňovou uznatelnost',
    ];

    /**
     * @param list<array{account_id:int, side:string, amount:float|int|string}> $before řádky v deníku
     * @param list<array{account_id:int, side:string, amount:float|int|string}> $after  opravené řádky
     * @param array<int, array{code:string, account_type?:string, tax_deductibility?:string}> $accounts
     * @param bool $incomeTaxFiled přiznání k dani z příjmů za rok zápisu už je podané
     *
     * @return ?string NULL = daňově neutrální přesun (nebo žádná změna), jinak kód důvodu
     */
    public static function violation(array $before, array $after, array $accounts, bool $incomeTaxFiled = true): ?string
    {
        $net = [];
        foreach ([[$before, -1], [$after, 1]] as [$lines, $sign]) {
            foreach ($lines as $line) {
                $cents = (int) round(((float) $line['amount']) * 100.0);
                $signed = (string) $line['side'] === 'credit' ? -$cents : $cents;
                $accountId = (int) $line['account_id'];
                $net[$accountId] = ($net[$accountId] ?? 0) + $sign * $signed;
            }
        }
        $changed = array_filter($net, static fn (int $cents): bool => $cents !== 0);
        if ($changed === []) {
            return null;
        }

        $money = 0;
        $settlement = 0;
        $rounding = 0;
        $onlySettlementAndRounding = true;
        $perDeductibility = [];
        $classes = [];
        foreach ($changed as $accountId => $cents) {
            $account = $accounts[(int) $accountId] ?? null;
            if ($account === null) {
                return self::UNKNOWN_ACCOUNT;
            }
            $code = (string) $account['code'];
            if (in_array($account['account_type'] ?? '', ['closing', 'offbalance'], true)) {
                return self::SPECIAL_ACCOUNT;
            }
            if (str_starts_with($code, '34')) {
                return self::TAX_ACCOUNT_CHANGED;
            }
            if (str_starts_with($code, '2')) {
                $money += $cents;
            } elseif (self::isSettlementAccount($code)) {
                $settlement += $cents;
            } elseif (self::isRoundingAccount($code)) {
                $rounding += $cents;
            } else {
                $onlySettlementAndRounding = false;
            }
            $classes[$code[0] ?? ''] = true;
            $bucket = (string) ($account['tax_deductibility'] ?? 'deductible');
            $perDeductibility[$bucket] = ($perDeductibility[$bucket] ?? 0) + $cents;
        }

        if ($money !== 0) {
            return self::AMOUNTS_CHANGED;
        }
        if ($settlement !== 0) {
            // Jediná výjimka: haléřové dorovnání úhrady. Doklad se zaokrouhlením
            // (zaplaceno 16 371,00 na předpis 16 370,09) se uzavře až tehdy, když se
            // úhrada srovná na nominál předpisu a rozdíl jde na 548/648 — stejně jako
            // to od začátku dělá BankPostingService při živém párování. Mění se jen
            // saldokonto proti účtu zaokrouhlení, nejvýš o 1 Kč, DPH ani peníze ne.
            // Mění to ale výsledek, a tak jen do podání DPPO (jako přesun mezi třídami).
            if ($incomeTaxFiled
                || !$onlySettlementAndRounding
                || $settlement + $rounding !== 0
                || abs($rounding) > self::ROUNDING_TOLERANCE_CENTS
            ) {
                return self::AMOUNTS_CHANGED;
            }
            return null;
        }
        if (!$incomeTaxFiled) {
            return null;
        }
        if (count($classes) > 1) {
            return self::ACCOUNT_CLASS_CHANGED;
        }
        foreach ($perDeductibility as $cents) {
            if ($cents !== 0) {
                return self::TAX_DEDUCTIBILITY_CHANGED;
            }
        }

        return null;
    }

    /** Lidský popis důvodu pro hlášku. */
    public static function describe(string $code): string
    {
        return self::MESSAGES[$code] ?? $code;
    }

    /**
     * Pohledávky a závazky: skupiny 31–33, 35–37. Jejich netto pohyb je skutečnost
     * (dlužno), ne kontace, takže ho přepis nesmí změnit. Peníze (třída 2) se hlídají
     * zvlášť, aby záměna banky za závazek nevypadala jako přesun uvnitř jedné skupiny.
     * 34x řeší samostatná podmínka, 38x (časové rozlišení, dohadné položky) a 39x jsou
     * cílem běžného přeúčtování.
     */
    private static function isSettlementAccount(string $code): bool
    {
        return in_array(substr($code, 0, 2), ['31', '32', '33', '35', '36', '37'], true);
    }

    /** Účty haléřového dorovnání úhrad (BankPostingService::appendRounding). */
    private static function isRoundingAccount(string $code): bool
    {
        return str_starts_with($code, '548') || str_starts_with($code, '648');
    }
}
