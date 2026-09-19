<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Psr\Log\LoggerInterface;

/**
 * Parser PDF výpisu od KB (Komerční banka). Text extrahuje registry (Smalot\PdfParser),
 * tahle třída ho rozparsuje deterministicky (žádné AI — finanční data). Protějšek
 * {@see CreditasStatementPdfParser}/{@see CsobStatementPdfParser} pro layout KB.
 *
 * Layout KB je „vertikální" — na rozdíl od ČSOB nemá transakce na jednom řádku, ale
 * rozprostřenou do víc fyzických řádků (datum zúčtování / datum transakce / popis /
 * identifikace / název protiúčtu / protiúčet a kód banky / VS / KS / SS / částka).
 * Ne každá transakce má všechny řádky. Běžný zůstatek se u řádků NEuvádí (jen sloupce
 * Připsáno/Odepsáno = částka se znaménkem; kredit bez znaménka, debet s „-").
 *
 * Kotvy parsování transakce:
 *   - Slice začíná řádkem s celým datem „DD.MM.YYYY" (popis může být nalepený za ním).
 *   - Samostatný řádek s datem je datum ZÚČTOVÁNÍ a patří k následujícímu slice
 *     (druhé datum je datum transakce — u karet bývá o den dřív, ale do výpisu
 *     pohyb spadá dnem zúčtování).
 *   - Částka = POSLEDNÍ peněžní hodnota ve slice (sloupec Připsáno/Odepsáno je vpravo).
 *   - Protiúčet = první řádek `<číslo>/<kód banky>`; VS/KS/SS = celočíselné tokeny ZA ním.
 *
 * Umí obě podoby výpisu: „VÝPIS PERIODICKÝ" (za období) i „VÝPIS DENNÍ PŘI POHYBU"
 * (jeden den, KB ho posílá e-mailem po každém pohybu) — liší se jen hlavičkou,
 * layout transakcí je shodný. Druh nese `header['period_kind']` (`period` / `day`).
 *
 * Self-check: součet transakcí musí sedět na `curr_balance - prev_balance` z hlavičky.
 */
final class KbStatementPdfParser implements BankStatementPdfParserInterface
{
    /** Peněžní hodnota: volitelné znaménko, tisíce oddělené mezerou/NBSP, čárka desetinná. */
    private const MONEY = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';

    /**
     * Řádky, ze kterých se částky NEodstraňují při skládání popisu: nesou původní
     * částku a kurz karetní transakce v cizí měně („zúčt. částka: 22,63 EUR",
     * „kurz: 1,0000"). Vyříznutím MONEY by z nich zbyly trosky („kurz: 00").
     */
    private const KEEP_MONEY_LINE = '/^(zúčt\. částka|původní částka|částka v měně|kurz)\b/iu';

    /** Řádky, kterými tabulka transakcí končí (za nimi je rekapitulace / zůstatky dle data). */
    private const END_LINE_PATTERNS = [
        '/^KONEČNÝ ZŮSTATEK/u',
        '/^Rekapitulace transakcí/u',
        '/^Zůstatek podle data/u',
        '/^Vklad na tomto účtu/u',
    ];

    private const SKIP_LINE_PATTERNS = [
        '/^Pokračování na další straně/u',
        '/^Komerční banka, a\.s\./u',
        '/^se sídlem: Praha/u',
        '/^zapsaná v obchodním rejstříku/u',
        '/^DN\d/u', // technický identifikátor stránky „DN260630_…"
        // Opakovaná záhlaví sloupců (na každé stránce znovu).
        '/^Datum$/u',
        '/^zúčtování$/u',
        '/^transakce$/u',
        '/^Popis transakce$/u',
        '/^Identifikace transakce$/u',
        '/^Název protiúčtu \/ Číslo a typ karty$/u',
        '/^Protiúčet a kód banky \/ Obchodní místo$/u',
        '/^VS$/u',
        '/^KS$/u',
        '/^SS$/u',
        '/^Připsáno$/u',
        '/^Odepsáno$/u',
        // Opakovaná hlavička výpisu na dalších stránkách.
        '/^VÝPIS PERIODICKÝ/u',
        '/^VÝPIS DENNÍ/u',
        '/^Počáteční zůstatek\b/u',
        '/^Konečný zůstatek\b/u',
        '/^BIC \/ SWIFT kód:/u',
        '/^k účtu:/u',
        '/^IBAN:/u',
        '/^typ:/u',
        '/^měna:/u',
        '/^Datum výpisu:/u',
        '/^Číslo výpisu:/u',
        '/^Strana:/u',
        '/^Zaslání:/u',
        '/^Frekvence:/u',
        '/^Za období:/u',
        '/^POČÁTEČNÍ ZŮSTATEK/u',
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function key(): string
    {
        return 'kb';
    }

    public function supports(string $text): bool
    {
        return (str_contains($text, 'KOMBCZPP') || str_contains($text, 'Komerční banka'))
            && (str_contains($text, 'VÝPIS PERIODICKÝ')
                || str_contains($text, 'VÝPIS DENNÍ')
                || str_contains($text, 'www.kb.cz'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);

        // Self-check: součet transakcí musí souhlasit s pohybem zůstatku na haléř přesně.
        $sum = 0.0;
        foreach ($transactions as $tx) $sum += (float) $tx['amount'];
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($sum, 2) - $expected) > 0.01) {
            throw new \RuntimeException(sprintf(
                'KB PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
                $sum,
                $expected,
            ));
        }

        // Denní výpis nesmí projít, když se v něm objeví pohyb z jiného dne než
        // z dne výpisu: skládá se do měsíčního výpisu podle data zúčtování a tichý
        // posun o den by rozhodil jak období, tak zůstatky měsíce.
        if ($header['period_kind'] === 'day') {
            foreach ($transactions as $tx) {
                if ((string) $tx['posted_at'] !== $header['statement_date']) {
                    throw new \RuntimeException(sprintf(
                        'KB PDF: denní výpis k %s obsahuje pohyb zúčtovaný %s. Parsování zamítnuto.',
                        $header['statement_date'],
                        (string) $tx['posted_at'],
                    ));
                }
            }
        }

        $currency = $header['currency'] ?? 'CZK';
        foreach ($transactions as &$tx) {
            $tx['currency'] = $currency;
        }
        unset($tx);
        unset($header['currency']);
        // Měna účtu zůstává v hlavičce pod vlastním klíčem: výpis bez jediného pohybu
        // (dormantní účet) by ji jinak nenesl vůbec a automatický import z e-mailu by
        // nepoznal, ke kterému měnovému účtu firmy výpis patří.
        $header['account_currency'] = $currency;

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float,
     *   currency:?string, period_kind:string}
     */
    public function parseHeaderFromText(string $text): array
    {
        $money = '(' . self::MONEY . ')';

        if (!preg_match('/k účtu:\s*([\d\-]+)\/(\d{3,4})/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "k účtu:" v hlavičce.');
        }
        $accountNumber = $m[1];

        if (!preg_match('/Datum výpisu:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Datum výpisu:" v hlavičce.');
        }
        $statementDate = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

        $statementNumber = preg_match('/Číslo výpisu:\s*(\d+)/u', $text, $m) ? $m[1] : '';
        $currency = preg_match('/měna:\s*([A-Za-z]{3})/u', $text, $m) ? strtoupper($m[1]) : null;

        // KB píše zůstatky bez dvojtečky, oddělené mezerami: „Počáteční zůstatek   304 038,38".
        if (!preg_match('/Počáteční zůstatek\s+' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Počáteční zůstatek" v hlavičce.');
        }
        $prevBalance = $this->num($m[1]);

        if (!preg_match('/Konečný zůstatek\s+' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Konečný zůstatek" v hlavičce.');
        }
        $currBalance = $this->num($m[1]);

        // „Obraty na účtu   18 412,72   -13 050,87" → Připsáno (kredit) / Odepsáno (debet).
        $creditTotal = 0.0;
        $debitTotal = 0.0;
        if (preg_match('/Obraty na účtu\s+' . $money . '\s+' . $money . '/u', $text, $m)) {
            $creditTotal = abs($this->num($m[1]));
            $debitTotal = abs($this->num($m[2]));
        }

        return [
            'account_number'   => $accountNumber,
            'statement_date'   => $statementDate,
            'statement_number' => $statementNumber,
            'prev_balance'     => $prevBalance,
            'curr_balance'     => $currBalance,
            'debit_total'      => $debitTotal,
            'credit_total'     => $creditTotal,
            'currency'         => $currency,
            // „VÝPIS DENNÍ PŘI POHYBU" = jeden den. Bez „Za období" v hlavičce je to
            // taky jednodenní doklad (KB ho tak posílá e-mailem), ale řídíme se jen
            // explicitním nadpisem — domýšlet druh výpisu z nepřítomnosti pole by
            // z každého nerozpoznaného layoutu udělalo denní výpis.
            'period_kind'      => preg_match('/VÝPIS\s+DENNÍ/u', $text) === 1 ? 'day' : 'period',
        ];
    }

    /**
     * Testovací seam — vytěží transakce z prostého textu (bez PDF bytes / self-checku).
     *
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromText(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];

        // Rozsekat na slice = řádky jedné transakce. Nový slice začíná řádkem s celým
        // datem „DD.MM.YYYY". Za koncovým markerem (rekapitulace / zůstatky dle data)
        // parsování končí — jinak by se „Zůstatek podle data" s daty a zůstatky rozparsoval
        // jako falešné transakce. Opakovaná záhlaví/patičky (page break) přeskakujeme.
        $slices = [];
        $current = [];
        foreach ($lines as $raw) {
            $t = trim((string) preg_replace('/\x{00A0}/u', ' ', (string) $raw));
            if ($t === '') continue;
            if ($this->isEndLine($t)) break;
            if ($this->isSkipLine($t)) continue;

            if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}/u', $t)) {
                if ($current !== []) $slices[] = $current;
                $current = [$t];
            } elseif ($current !== []) {
                $current[] = $t;
            }
        }
        if ($current !== []) $slices[] = $current;

        $slices = $this->mergePostingDateSlices($slices);

        $rows = [];
        foreach ($slices as $slice) {
            $row = $this->parseSlice($slice);
            if ($row !== null) $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Sloupce „Datum zúčtování" a „Datum transakce" jsou dva samostatné řádky. Slice,
     * který obsahuje JEN datum, je tedy datum zúčtování následující transakce — ne
     * transakce vlastní (žádnou částku nenese). Slepíme je a datum zúčtování necháme
     * jako první řádek: do výpisu pohyb patří dnem zúčtování, ne dnem transakce.
     * U karetních plateb se ta data liší (nákup v neděli, zúčtování v pondělí) a bez
     * tohohle kroku by pohyb vypadl mimo období výpisu.
     *
     * @param list<list<string>> $slices
     * @return list<list<string>>
     */
    private function mergePostingDateSlices(array $slices): array
    {
        $merged = [];
        $pendingDate = null;
        foreach ($slices as $slice) {
            if (count($slice) === 1 && preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/u', $slice[0])) {
                // Dva osamocené datumové řádky za sebou: první zahodit nelze, tak si
                // držíme ten poslední (bližší k transakci) — dřívější byl bez obsahu.
                $pendingDate = $slice[0];
                continue;
            }
            if ($pendingDate !== null) {
                array_unshift($slice, $pendingDate);
                $pendingDate = null;
            }
            $merged[] = $slice;
        }
        return $merged;
    }

    private function isEndLine(string $line): bool
    {
        foreach (self::END_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    /**
     * @param list<string> $slice
     */
    private function parseSlice(array $slice): ?array
    {
        // 1) Datum zúčtování + volitelně popis nalepený za ním („05.06.2026OKAMŽITÁ …").
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(.*)$/u', $slice[0], $m)) {
            return null;
        }
        $day = (int) $m[1];
        $mon = (int) $m[2];
        $year = (int) $m[3];
        if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) return null;
        $postingDate = sprintf('%04d-%02d-%02d', $year, $mon, $day);

        $n = count($slice);
        $idx = 1;
        $type = trim($m[4]);
        if ($type === '') {
            // Vertikální layout: další řádek může být datum transakce (přeskočit), pak popis.
            if ($idx < $n && preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/u', $slice[$idx])) $idx++;
            if ($idx < $n) { $type = $slice[$idx]; $idx++; }
        }

        // 2) Částka = POSLEDNÍ peněžní hodnota ve slice (nese vlastní znaménko).
        //    Řádky s původní částkou a kurzem karetní transakce se přeskakují — nesou
        //    částku v cizí měně BEZ znaménka, takže by se z výdaje stal příjem.
        $amount = null;
        foreach ($slice as $line) {
            if (preg_match(self::KEEP_MONEY_LINE, $line)) continue;
            if (preg_match_all('/' . self::MONEY . '/u', $line, $mm)) {
                $amount = $this->num($mm[0][count($mm[0]) - 1]);
            }
        }
        if ($amount === null) {
            return null; // řádek bez částky = informativní / falešný slice
        }

        // 3) Protiúčet + VS/KS/SS. Kotva = první řádek `<číslo>/<kód banky>`; symboly jsou
        //    celočíselné tokeny ZA ním (na jeho konci nebo na dalších řádcích), název
        //    protistrany = řádek těsně PŘED protiúčtem (má-li písmeno a není to štítek).
        $account = null;
        $bankCode = null;
        $accountIdx = null;
        for ($i = $idx; $i < $n; $i++) {
            if (preg_match('/^([\d][\d\-]{3,})\/(\d{3,4})(.*)$/u', $slice[$i], $acm)) {
                $account = $acm[1];
                $bankCode = $acm[2];
                $accountIdx = $i;
                break;
            }
        }

        // Zahraniční platba nemá protiúčet v domácím tvaru, jen IBAN (a pod ním BIC).
        // Symboly se z ní ZÁMĚRNĚ netahají: čísla, která KB u těchhle plateb tiskne do
        // pravého bloku, jsou vlastní reference a EndToEnd, ne VS/KS/SS — a falešný VS
        // by spároval úhradu s cizí fakturou.
        $counterpartyIban = null;
        if ($accountIdx === null) {
            for ($i = $idx; $i < $n; $i++) {
                $cand = trim((string) preg_replace('/' . self::MONEY . '/u', '', $slice[$i]));
                if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $cand)) {
                    $counterpartyIban = $cand;
                    break;
                }
            }
        }

        $vs = null; $ks = null; $ss = null;
        $counterpartyName = null;
        if ($accountIdx !== null) {
            // Symboly: celočíselné tokeny za protiúčtem (částku z řádku napřed odstraníme).
            $symbolLines = [(string) preg_replace('/^[\d][\d\-]{3,}\/\d{3,4}/u', '', $slice[$accountIdx])];
            for ($i = $accountIdx + 1; $i < $n; $i++) $symbolLines[] = $slice[$i];
            $nums = [];
            foreach ($symbolLines as $sl) {
                $sl = trim((string) preg_replace('/' . self::MONEY . '/u', '', $sl));
                foreach (preg_split('/[\t ]+/u', $sl) ?: [] as $tok) {
                    if (preg_match('/^\d+$/', $tok)) $nums[] = $tok;
                }
            }
            $vs = isset($nums[0]) ? (ltrim($nums[0], '0') ?: null) : null;
            $ks = isset($nums[1]) ? (ltrim($nums[1], '0') ?: null) : null;
            $ss = isset($nums[2]) ? (ltrim($nums[2], '0') ?: null) : null;

            // Název protistrany = řádek TĚSNĚ před protiúčtem (sloupec „Název protiúčtu"
            // stojí v layoutu přímo nad číslem účtu). Zpětné hledání se neosvědčilo:
            // u převodu bez názvu protistrany sebralo jako jméno text zprávy pro
            // příjemce („Platba faktury 920266528") nebo identifikaci transakce
            // („OI0004A3V61"). Když těsně předcházející řádek je tělo zprávy pro
            // příjemce (pozná se podle štítku nad ním), jméno protistrany ve výpisu není.
            $nameIdx = $accountIdx - 1;
            if ($nameIdx >= $idx) {
                $cand = trim($slice[$nameIdx]);
                $isMessageBody = $nameIdx - 1 >= $idx
                    && preg_match('/^Zpráva pro příjemce:/u', trim($slice[$nameIdx - 1])) === 1;
                if ($cand !== '' && !$isMessageBody
                    && preg_match('/^Zpráva pro příjemce:/u', $cand) !== 1
                    && preg_match('/\p{L}/u', $cand)) {
                    $counterpartyName = $cand;
                }
            }
        }

        // Karetní platba: sloupec „Číslo a typ karty" nese maskované číslo, řádek pod ním
        // („Obchodní místo") obchodníka. Protiúčet karta nemá, takže jméno protistrany
        // výše nevzniklo — doplníme ho z obchodního místa.
        $cardLast4 = null;
        $cardIdx = null;
        for ($i = $idx; $i < $n; $i++) {
            $last4 = \MyInvoice\Service\Bank\Card\CardNumberMask::last4FromText($slice[$i]);
            if ($last4 !== null) {
                $cardLast4 = $last4;
                $cardIdx = $i;
                break;
            }
        }
        if ($cardIdx !== null && $counterpartyName === null) {
            for ($i = $cardIdx + 1; $i < $n; $i++) {
                if (preg_match(self::KEEP_MONEY_LINE, $slice[$i])) continue;
                $cand = trim((string) preg_replace('/' . self::MONEY . '/u', '', $slice[$i]));
                if ($cand !== '' && preg_match('/\p{L}/u', $cand)) {
                    $counterpartyName = $cand;
                    break;
                }
            }
        }

        // Popis: typ + zbývající poznámkové řádky (bez protiúčtu, symbolů a částky).
        $descParts = [];
        if ($type !== '') $descParts[] = $type;
        for ($i = $idx; $i < $n; $i++) {
            if ($i === $accountIdx) continue;
            $line = preg_match(self::KEEP_MONEY_LINE, $slice[$i]) === 1
                ? trim($slice[$i])
                : trim((string) preg_replace('/' . self::MONEY . '/u', '', $slice[$i]));
            if ($line === '') continue;
            if ($line === $counterpartyName || $line === $counterpartyIban) continue;
            if (preg_match('/^\d[\d\-]*$/', $line)) continue; // čisté číselné tokeny (symboly/identifikace)
            if (preg_match('/^Zpráva pro příjemce:$/u', $line)) continue;
            $descParts[] = $line;
        }
        $description = $descParts !== [] ? mb_substr(implode(' | ', $descParts), 0, 255) : null;

        $row = [
            'posted_at'            => $postingDate,
            'amount'               => round($amount, 2),
            'variable_symbol'      => $vs,
            'constant_symbol'      => $ks,
            'specific_symbol'      => $ss,
            'counterparty_account' => $account ?? $counterpartyIban,
            'counterparty_bank'    => $bankCode,
            'counterparty_name'    => $counterpartyName !== null ? mb_substr($counterpartyName, 0, 190) : null,
            'description'          => $description,
            'bank_ref'             => null,
        ];
        if ($cardLast4 !== null) {
            $row['card_last4'] = $cardLast4;
        }
        return $row;
    }

    /** „1 234,56" / „-9 999,00" → float (mezery/NBSP oddělovač tisíců, čárka desetinná). */
    private function num(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }
}
