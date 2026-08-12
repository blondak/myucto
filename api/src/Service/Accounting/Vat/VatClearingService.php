<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Interní doklad „zúčtování DPH" na konci zdaňovacího období.
 *
 * PROČ. Od migrace 1323 nesedí daň na jednom plochém 343, ale na analytikách
 * (viz {@see PostingService::INPUT_VAT_ACCOUNT} a spol.):
 *   343.100  daň na vstupu (odpočet)   343.200  daň na výstupu   343.900  zúčtování s FÚ
 * Účetní pak na konci KAŽDÉHO zdaňovacího období převede obrat období na zúčtovací účet:
 *
 *     MD 343.200 / D 343.900     … daň na VÝSTUPU za období
 *     MD 343.900 / D 343.100     … daň na VSTUPU za období
 *
 * Po tomhle dokladu jsou 343.100 i 343.200 za období nulové a na 343.900 leží přesně
 * částka, která se odvádí (nebo se má vrátit) — tu pak vynuluje bankovní úhrada
 * (kontace `vat.payment`, {@see \MyInvoice\Service\Accounting\Bank\Detect\TaxRemittanceDetector}).
 * Zůstatek zúčtovacího účtu je tím pádem přímo srovnatelný se saldem u správce daně,
 * což na plochém 343 (kde se vstup s výstupem hned vyruší) z principu nešlo.
 *
 * IDEMPOTENCE. Zápis nese `source_type = 'vat_clearing'` a DETERMINISTICKÉ `source_id`
 * odvozené z období ({@see sourceIdFor()}), takže platí kontrakt
 * `uq_je_supplier_source` popsaný v {@see PostingService}: opakovaný běh existující
 * zápis PŘEPÍŠE (přepočítá) místo aby založil druhý. Dopočtená částka přitom vlastní
 * zápis IGNORUJE (source_type se z obratu vylučuje), jinak by každé přepočítání
 * přičetlo samo sebe.
 *
 * CO SE DO OBRATU NEPOČÍTÁ:
 *   - uzavření/otevření knih ('closing'/'opening') — převod zůstatku na 702/701 není
 *     daňová transakce (stejná výjimka jako ve {@see \MyInvoice\Service\Report\VatCrossCheckService}),
 *   - vlastní zúčtovací doklady ('vat_clearing') — viz idempotence výše,
 *   - koncepty (posted_at IS NULL) — nezaúčtovaný doklad ještě není v knihách.
 *
 * BEZPEČNOST ZÁPISU. Doklad se nikdy nepostne nevyvážený: skládá se ze dvou
 * self-balanced párů, každý pár se přidá jen když je jeho částka nenulová. Když jsou
 * nulové obě, doklad se NEZAKLÁDÁ vůbec (status `skipped_zero`).
 */
final class VatClearingService
{
    /** source_type zúčtovacího dokladu (ENUM `journal_entries.source_type`, migrace 1324). */
    public const SOURCE_TYPE = 'vat_clearing';

    public const STATUS_POSTED            = 'posted';
    public const STATUS_DRY_RUN           = 'dry_run';
    public const STATUS_ZERO              = 'skipped_zero';
    public const STATUS_NOT_VAT_PAYER     = 'skipped_not_vat_payer';
    public const STATUS_NOT_DOUBLE_ENTRY  = 'skipped_not_double_entry';
    public const STATUS_MISSING_ACCOUNTS  = 'skipped_missing_accounts';
    public const STATUS_FLAT_VAT_ACCOUNT  = 'skipped_flat_vat_account';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly JournalEntryRepository $journal,
    ) {}

    // ── čistá aritmetika období (bez DB — jednotkově testovatelné) ────────────

    /**
     * Hranice zdaňovacího období, do kterého spadá zadaný měsíc.
     *
     * @param 'monthly'|'quarterly' $periodType
     * @return array{0:string, 1:string, 2:int, 3:int} [start, end, firstMonth, lastMonth]
     */
    public static function periodBounds(int $year, int $month, string $periodType): array
    {
        $month = max(1, min(12, $month));
        if ($periodType === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $first = ($quarter - 1) * 3 + 1;
            $last = $quarter * 3;
        } else {
            $first = $month;
            $last = $month;
        }
        $start = sprintf('%04d-%02d-01', $year, $first);
        $end = date('Y-m-t', (int) mktime(0, 0, 0, $last, 1, $year));

        return [$start, $end, $first, $last];
    }

    /**
     * Deterministické `source_id` — YYYYMMD, kde MM je PRVNÍ měsíc období a poslední
     * číslice odlišuje čtvrtletní plátce (1) od měsíčního (0). Bez toho příznaku by
     * lednový doklad měsíčního plátce a doklad za Q1 sdílely týž klíč a při změně
     * zdaňovacího období by si navzájem přepsaly zápis.
     *
     * @param 'monthly'|'quarterly' $periodType
     */
    public static function sourceIdFor(int $year, int $month, string $periodType): int
    {
        [, , $first] = self::periodBounds($year, $month, $periodType);

        return $year * 1000 + $first * 10 + ($periodType === 'quarterly' ? 1 : 0);
    }

    /** Označení období pro popis a číslo dokladu ('01/2026', 'Q1/2026'). */
    public static function periodLabel(int $year, int $month, string $periodType): string
    {
        [, , $first] = self::periodBounds($year, $month, $periodType);

        return $periodType === 'quarterly'
            ? sprintf('Q%d/%04d', (int) ceil($first / 3), $year)
            : sprintf('%02d/%04d', $first, $year);
    }

    /**
     * Období, které se má zaúčtovat k danému dni — poslední UZAVŘENÉ (tj. to, do
     * kterého spadá předchozí měsíc). Cron běží 1. dne v měsíci, takže měsíčnímu
     * plátci vyjde minulý měsíc a čtvrtletnímu čtvrtletí, do kterého minulý měsíc
     * patří (pro nekoncové měsíce je to čtvrtletí ještě neuzavřené — proto
     * {@see isPeriodClosed()}).
     *
     * @return array{0:int, 1:int} [rok, měsíc]
     */
    public static function previousPeriod(\DateTimeImmutable $today): array
    {
        // `first day of this month` PŘED odečtením měsíce (31. 3. − 1 měsíc = 3. 3.).
        $target = $today->modify('first day of this month')->modify('-1 month');

        return [(int) $target->format('Y'), (int) $target->format('n')];
    }

    /**
     * Je období obsahující (rok, měsíc) k danému dni už kompletní? U čtvrtletního
     * plátce se doklad smí udělat až po posledním měsíci čtvrtletí.
     *
     * @param 'monthly'|'quarterly' $periodType
     */
    public static function isPeriodClosed(int $year, int $month, string $periodType, \DateTimeImmutable $today): bool
    {
        [, $end] = self::periodBounds($year, $month, $periodType);

        return $end < $today->format('Y-m-d');
    }

    // ── čtení konfigurace tenanta ─────────────────────────────────────────────

    /**
     * Dodavatelé, kteří zúčtovací doklad vůbec mohou mít: podvojné účetnictví
     * a plátce (nebo identifikovaná osoba — ta taky podává přiznání).
     *
     * @return list<int>
     */
    public function candidateSupplierIds(): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT id FROM supplier
              WHERE accounting_mode = 'double_entry'
                AND (is_vat_payer = 1 OR is_identified = 1)
              ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows ?: []);
    }

    /**
     * Zdaňovací období tenanta. Identifikovaná osoba podává měsíčně bez ohledu na
     * `vat_period` (shodně s {@see \MyInvoice\Action\Report\DphPriznaniAction}).
     *
     * @return 'monthly'|'quarterly'
     */
    public function vatPeriodFor(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_period, is_vat_payer, is_identified FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'monthly';
        }
        if ((bool) ($row['is_identified'] ?? false) && !(bool) ($row['is_vat_payer'] ?? false)) {
            return 'monthly';
        }
        $period = (string) ($row['vat_period'] ?? 'monthly');

        return $period === 'quarterly' ? 'quarterly' : 'monthly';
    }

    // ── výpočet a zaúčtování ──────────────────────────────────────────────────

    /**
     * Spočítá zúčtování za období obsahující (rok, měsíc) — BEZ zápisu do deníku.
     *
     * @return array{
     *   supplier_id:int, period_type:string, period_start:string, period_end:string,
     *   period_label:string, source_id:int, input_vat:float, output_vat:float,
     *   settlement:float, accounts:array{input:string, output:string, settlement:string},
     *   status:?string, entry_id:?int
     * }
     */
    public function preview(int $supplierId, int $year, int $month): array
    {
        $periodType = $this->vatPeriodFor($supplierId);
        [$start, $end] = self::periodBounds($year, $month, $periodType);
        $sourceId = self::sourceIdFor($year, $month, $periodType);

        $inputAcc      = $this->ruleCode($supplierId, 'vat.clearing.input', 'credit', PostingService::INPUT_VAT_ACCOUNT);
        $outputAcc     = $this->ruleCode($supplierId, 'vat.clearing.output', 'debit', PostingService::OUTPUT_VAT_ACCOUNT);
        $settlementAcc = $this->ruleCode($supplierId, 'vat.clearing.output', 'credit', PostingService::VAT_SETTLEMENT_ACCOUNT);

        $existing = $this->journal->findBySource($supplierId, self::SOURCE_TYPE, $sourceId);

        $base = [
            'supplier_id'  => $supplierId,
            'period_type'  => $periodType,
            'period_start' => $start,
            'period_end'   => $end,
            'period_label' => self::periodLabel($year, $month, $periodType),
            'source_id'    => $sourceId,
            'input_vat'    => 0.0,
            'output_vat'   => 0.0,
            'settlement'   => 0.0,
            'accounts'     => ['input' => $inputAcc, 'output' => $outputAcc, 'settlement' => $settlementAcc],
            'status'       => null,
            'entry_id'     => $existing !== null ? (int) $existing['id'] : null,
        ];

        // Tenant, který si analytiky vypnul (obě nohy míří na týž účet), nemá co
        // převádět — doklad by byl 343/343 v obou párech, tedy prázdné gesto.
        if ($inputAcc === $outputAcc || $inputAcc === $settlementAcc || $outputAcc === $settlementAcc) {
            $base['status'] = self::STATUS_FLAT_VAT_ACCOUNT;
            return $base;
        }
        foreach ([$inputAcc, $outputAcc, $settlementAcc] as $code) {
            $account = $this->accounts->findByCode($supplierId, $code);
            if ($account === null || empty($account['is_active'])) {
                $base['status'] = self::STATUS_MISSING_ACCOUNTS;
                return $base;
            }
        }

        $turnover = $this->turnover($supplierId, $start, $end, [$inputAcc, $outputAcc]);
        // Vstup má přirozeně debetní zůstatek, výstup kreditní — počítáme je tak, aby
        // kladné číslo znamenalo „normální" směr a záporné obrácený (dobropisy).
        $inputVat  = round($turnover[$inputAcc]['debit'] - $turnover[$inputAcc]['credit'], 2);
        $outputVat = round($turnover[$outputAcc]['credit'] - $turnover[$outputAcc]['debit'], 2);

        $base['input_vat']  = $inputVat;
        $base['output_vat'] = $outputVat;
        $base['settlement'] = round($outputVat - $inputVat, 2);
        if (self::cents($inputVat) === 0 && self::cents($outputVat) === 0) {
            $base['status'] = self::STATUS_ZERO;
        }

        return $base;
    }

    /**
     * Spočítá a ZAÚČTUJE zúčtovací doklad za období obsahující (rok, měsíc).
     * Idempotentní — opakované volání existující zápis přepíše.
     *
     * @param array<string,mixed> $meta auditní meta pro {@see PostingService::postDocument()}
     * @return array<string,mixed> tvar {@see preview()} + status/entry_id
     *
     * @throws \MyInvoice\Service\Accounting\PostingException zavřené / chybějící / zamčené období
     */
    public function postForPeriod(int $supplierId, int $year, int $month, array $meta = [], bool $dryRun = false): array
    {
        $result = $this->preview($supplierId, $year, $month);
        if ($result['status'] !== null) {
            return $result;
        }

        $lines = self::buildLines(
            $result['input_vat'],
            $result['output_vat'],
            $result['accounts']['input'],
            $result['accounts']['output'],
            $result['accounts']['settlement'],
        );
        if ($lines === []) {
            $result['status'] = self::STATUS_ZERO;
            return $result;
        }
        if ($dryRun) {
            $result['status'] = self::STATUS_DRY_RUN;
            return $result;
        }

        $entryId = $this->posting->postDocument(
            $supplierId,
            self::SOURCE_TYPE,
            $result['source_id'],
            $lines,
            [
                'entry_date'  => $result['period_end'],
                'document_no' => 'DPH-' . $result['period_label'],
                'description' => 'Zúčtování DPH za ' . $result['period_label'],
                'posted'      => true,
                'posted_by'   => $meta['user_id'] ?? null,
                'user_id'     => $meta['user_id'] ?? null,
                'ip'          => $meta['ip'] ?? null,
                'user_agent'  => $meta['user_agent'] ?? null,
            ],
        );

        $result['status'] = self::STATUS_POSTED;
        $result['entry_id'] = $entryId;

        return $result;
    }

    /**
     * Řádky dokladu — dva self-balanced páry, každý jen když je nenulový.
     * Kladná částka = obvyklý směr; záporná (převaha dobropisů) obrací strany, aby
     * se nikdy neúčtovala záporná částka (`chk_jel_amount_positive`).
     *
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float}>
     */
    public static function buildLines(
        float $inputVat,
        float $outputVat,
        string $inputAccount,
        string $outputAccount,
        string $settlementAccount,
    ): array {
        $lines = [];
        // MD 343.200 / D 343.900 — daň na výstupu období na zúčtovací účet.
        if (self::cents($outputVat) !== 0) {
            $amount = round(abs($outputVat), 2);
            $outSide = $outputVat > 0 ? 'debit' : 'credit';
            $lines[] = ['account_code' => $outputAccount, 'side' => $outSide, 'amount' => $amount];
            $lines[] = [
                'account_code' => $settlementAccount,
                'side'         => $outSide === 'debit' ? 'credit' : 'debit',
                'amount'       => $amount,
            ];
        }
        // MD 343.900 / D 343.100 — daň na vstupu období na zúčtovací účet.
        if (self::cents($inputVat) !== 0) {
            $amount = round(abs($inputVat), 2);
            $inSide = $inputVat > 0 ? 'credit' : 'debit';
            $lines[] = ['account_code' => $inputAccount, 'side' => $inSide, 'amount' => $amount];
            $lines[] = [
                'account_code' => $settlementAccount,
                'side'         => $inSide === 'credit' ? 'debit' : 'credit',
                'amount'       => $amount,
            ];
        }

        return $lines;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    /**
     * Obrat období na zadaných účtech (MD/D zvlášť). Vylučuje koncepty, uzávěrkové
     * převody i vlastní zúčtovací doklady — viz třídní docblock.
     *
     * @param list<string> $codes
     * @return array<string, array{debit:float, credit:float}>
     */
    private function turnover(int $supplierId, string $start, string $end, array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $out[$code] = ['debit' => 0.0, 'credit' => 0.0];
        }
        if ($codes === []) {
            return $out;
        }
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0) AS d,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0) AS c
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a  ON a.id = l.account_id
              WHERE l.supplier_id = ?
                AND a.account_code IN ({$placeholders})
                AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND e.source_type NOT IN ('closing', 'opening', '" . self::SOURCE_TYPE . "')
              GROUP BY a.account_code"
        );
        $stmt->execute([$supplierId, ...$codes, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['account_code']] = [
                'debit'  => round((float) $row['d'], 2),
                'credit' => round((float) $row['c'], 2),
            ];
        }

        return $out;
    }

    private function ruleCode(int $supplierId, string $ruleKey, string $side, string $fallback): string
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        $code = $rule[$side . '_account_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : $fallback;
    }

    /** Peníze se porovnávají v haléřích (int), nikdy přes float == (viz PostingService). */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
