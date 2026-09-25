<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Repository\CreditCardSettingsRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Účetní akce nad úvěrovým účtem kreditní karty (detail na stránce Kreditní karty):
 *
 *  - „Zaúčtovat čekající pohyby": každý nezaúčtovaný pohyb výpisů účtu projde stejnou
 *    automatikou jako při importu ({@see BankPostingService::handleTransaction()}),
 *  - počáteční dluh z prvního výpisu: zůstatek, který výpis převzal z doby před evidencí.
 *
 * Nic z toho nemá vlastní účtovací logiku - zápis pohybu staví bankovní engine, zápis
 * počátečního dluhu {@see PostingService}.
 */
final class CreditCardPostingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardAccountRepository $accounts,
        private readonly CreditCardSettingsRepository $settings,
        private readonly BankPostingService $bankPosting,
        private readonly PostingService $posting,
        private readonly AccountingPeriodRepository $periods,
        private readonly DocumentSeriesService $series,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    /** @return array<string,mixed> */
    public function account(int $supplierId, int $id): array
    {
        $account = $this->accounts->find($supplierId, $id);
        if ($account === null) {
            throw new PostingException('not_found', 'Úvěrový účet nenalezen.', 404);
        }
        return $account;
    }

    /**
     * Pohyby výpisů účtu, které ještě nemají živý bankovní zápis a nejsou ignorované.
     *
     * @return list<int>
     */
    public function pendingTransactionIds(int $supplierId, array $account): array
    {
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $this->accounts->statements($supplierId, $account));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id FROM bank_transactions bt
              WHERE bt.statement_id IN ({$in}) AND bt.source = 'statement' AND bt.match_status <> 'ignored'
                AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                 WHERE je.supplier_id = ? AND je.source_type = 'bank'
                                   AND je.source_id = bt.id AND je.reversed_by IS NULL)
              ORDER BY bt.posted_at, bt.id"
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Dohnání výpisu po změně nastavení: každý čekající pohyb projde automatikou
     * znovu. Co automatika zaúčtovat nesmí (politika, uzavřené období), skončí jako návrh.
     *
     * @return array{total:int, posted:int, suggested:int, skipped:int, reasons:array<string,int>}
     */
    public function postPending(int $supplierId, int $id, ?int $userId): array
    {
        $account = $this->account($supplierId, $id);
        if (!$this->isDoubleEntry($supplierId)) {
            throw new PostingException('not_double_entry', 'Zaúčtování pohybů je jen pro podvojné účetnictví.', 422);
        }
        $out = ['total' => 0, 'posted' => 0, 'suggested' => 0, 'skipped' => 0, 'reasons' => []];
        foreach ($this->pendingTransactionIds($supplierId, $account) as $txId) {
            $out['total']++;
            $res = $this->bankPosting->handleTransaction($txId, $userId);
            $action = (string) ($res['action'] ?? 'skipped');
            if ($action === 'posted') {
                $out['posted']++;
            } elseif ($action === 'suggested') {
                $out['suggested']++;
            } else {
                $out['skipped']++;
                $reason = (string) ($res['reason'] ?? 'unknown');
                $out['reasons'][$reason] = ($out['reasons'][$reason] ?? 0) + 1;
            }
        }
        return $out;
    }

    /**
     * Náhled počátečního dluhu. Částka = počáteční zůstatek nejstaršího výpisu minus to, co
     * už na analytice úvěru leží mimo pohyby výpisů (dřív zaúčtovaný počáteční stav, převod
     * zůstatků). Díky tomu se dluh nikdy nezaúčtuje dvakrát, ani když ho firma převzala jinak.
     *
     * @return array<string,mixed>
     */
    public function openingPreview(int $supplierId, int $id): array
    {
        $account = $this->account($supplierId, $id);
        $statements = $this->accounts->statements($supplierId, $account);
        $first = $statements === [] ? null : $statements[count($statements) - 1];
        $code = $account['analytic_suffix'] !== null ? CreditCardAccounts::codeFor((string) $account['analytic_suffix']) : null;
        $settings = $this->settings->find($supplierId);
        $defaultCode = (string) $settings['opening_account_code'];
        $posted = $this->liveOpeningEntry($supplierId, $account);

        $base = [
            'account_code'         => $code,
            'statement'            => $first !== null ? [
                'id'             => (int) $first['id'],
                'statement_date' => (string) $first['statement_date'],
                'statement_number' => (string) ($first['statement_number'] ?? ''),
                'prev_balance'   => round((float) $first['prev_balance'], 2),
            ] : null,
            'entry_date'           => $first !== null ? $this->openingDate($first) : null,
            'contra_account_code'  => $defaultCode,
            'contra_account_id'    => $settings['opening_account_id'],
            'posted_entry_id'      => $posted,
            'other_balance'        => 0.0,
            'amount'               => 0.0,
            'needed'               => false,
        ];
        if ($first === null || $code === null || $posted !== null) {
            return $base;
        }
        $other = $this->balanceOutsideStatements($supplierId, $code, $account);
        $amount = round((float) $first['prev_balance'] - $other, 2);
        return [
            'other_balance' => $other,
            'amount'        => $amount,
            'needed'        => abs($amount) >= 0.005,
        ] + $base;
    }

    /**
     * Zaúčtuje počáteční dluh: dluh (záporný zůstatek) D 231.x / MD protiúčet, přeplatek
     * obráceně. Jen jednou za účet; uzavřené nebo zamčené období odmítne s vysvětlením.
     *
     * @return array{entry_id:int, amount:float, contra_account_code:string, entry_date:string}
     */
    public function postOpening(int $supplierId, int $id, ?string $contraCode, ?string $entryDate, ?int $userId): array
    {
        if (!$this->isDoubleEntry($supplierId)) {
            throw new PostingException('not_double_entry', 'Počáteční dluh se účtuje jen v podvojném účetnictví.', 422);
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $account = $this->accounts->lockForUpdate($supplierId, $id);
            if ($account === null) {
                throw new PostingException('not_found', 'Úvěrový účet nenalezen.', 404);
            }
            $preview = $this->openingPreview($supplierId, $id);
            if ($preview['posted_entry_id'] !== null) {
                throw new PostingException('already_posted', 'Počáteční dluh je už zaúčtovaný (zápis #' . $preview['posted_entry_id'] . ').', 409);
            }
            if ($preview['statement'] === null || $preview['account_code'] === null) {
                throw new PostingException('no_statement', 'Úvěrový účet nemá načtený výpis ani analytiku 231 - počáteční dluh není odkud vzít.', 422);
            }
            if (!$preview['needed']) {
                throw new PostingException('nothing_to_post', 'Počáteční zůstatek výpisu už v účetnictví je - není co zaúčtovat.', 409);
            }
            $contra = trim((string) ($contraCode ?? '')) ?: (string) $preview['contra_account_code'];
            $this->assertContra($supplierId, $contra, (string) $preview['account_code']);
            $date = trim((string) ($entryDate ?? '')) ?: (string) $preview['entry_date'];
            if (!self::isDate($date)) {
                throw new PostingException('invalid_date', 'Datum zápisu není platné datum.', 422, ['field' => 'entry_date']);
            }
            $period = $this->periods->ensureOpenPeriodFor($supplierId, $date);
            if (($period['status'] ?? 'open') !== 'open') {
                throw new PostingException('period_closed', 'Období ' . $date . ' je uzavřené - počáteční dluh do něj nejde zaúčtovat. Zvolte datum v otevřeném období.', 409, ['field' => 'entry_date']);
            }

            $amount = (float) $preview['amount'];
            $abs = round(abs($amount), 2);
            $debt = $amount < 0;
            $lines = [
                ['account_code' => (string) $preview['account_code'], 'side' => $debt ? 'credit' : 'debit', 'amount' => $abs],
                ['account_code' => $contra, 'side' => $debt ? 'debit' : 'credit', 'amount' => $abs],
            ];
            try {
                $entryId = $this->posting->postDocument($supplierId, 'manual', null, $lines, [
                    'entry_date'    => $date,
                    'document_date' => $date,
                    'document_no'   => $this->series->next($supplierId, 'manual', (int) ($period['fiscal_year'] ?? substr($date, 0, 4))),
                    'description'   => 'Počáteční zůstatek kreditní karty ' . $account['label'] . ' (výpis ' . $preview['statement']['statement_number'] . ')',
                    'posted'        => true,
                    'user_id'       => $userId,
                    'posted_by'     => $userId,
                ]);
            } catch (PostingException $e) {
                if (in_array($e->errorCode, ['period_not_open', 'no_accounting_period', 'date_locked'], true)) {
                    throw new PostingException('period_closed', 'Datum ' . $date . ' je v uzavřeném nebo zamčeném období - počáteční dluh do něj nejde zaúčtovat. Zvolte datum v otevřeném období.', 409, ['field' => 'entry_date']);
                }
                throw $e;
            }
            $this->accounts->setOpeningEntry($supplierId, $id, $entryId);
            if ($ownTx) {
                $pdo->commit();
            }
            return ['entry_id' => $entryId, 'amount' => $amount, 'contra_account_code' => $contra, 'entry_date' => $date];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Živý zápis počátečního dluhu, nebo null (storno ho uvolní k novému zaúčtování). */
    private function liveOpeningEntry(int $supplierId, array $account): ?int
    {
        $entryId = $account['opening_entry_id'] ?? null;
        if ($entryId === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([(int) $entryId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && $row['reversed_by'] === null ? (int) $entryId : null;
    }

    /** Zůstatek analytiky úvěru bez zápisů pohybů výpisů účtu (storna se vyruší). */
    private function balanceOutsideStatements(int $supplierId, string $code, array $account): float
    {
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $this->accounts->statements($supplierId, $account));
        $in = $ids === [] ? '0' : implode(',', $ids);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND e.posted_at IS NOT NULL
                AND NOT (e.source_type = 'bank' AND e.source_id IN (
                        SELECT bt.id FROM bank_transactions bt WHERE bt.statement_id IN ({$in})))"
        );
        $stmt->execute([$supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Den prvního pohybu výpisu (u výpisu bez pohybů den výpisu): dluh existoval před ním,
     * takže zůstatek 231.x sedí na výpis ke každému dni jeho období.
     */
    private function openingDate(array $statement): string
    {
        $first = $statement['first_posted_at'] ?? null;
        return is_string($first) && $first !== ''
            ? substr($first, 0, 10)
            : substr((string) $statement['statement_date'], 0, 10);
    }

    private function assertContra(int $supplierId, string $code, string $creditCode): void
    {
        $row = $this->chart->findByCode($supplierId, $code);
        if ($row === null || empty($row['is_active'])) {
            throw new PostingException('invalid_account', 'Protiúčet ' . $code . ' v účtové osnově firmy není.', 422, ['field' => 'contra_account_code']);
        }
        // Účty třídy 7 (701 Počáteční účet rozvažný) smí jen uzávěrka a otevření roku -
        // počáteční stav k začátku roku patří do otevíracího zápisu, ne sem.
        if ($code === $creditCode || preg_match('/^[34]/', $code) !== 1) {
            throw new PostingException('invalid_account', 'Protiúčet ' . $code . ' se pro počáteční dluh nehodí (povolené jsou účty tříd 3 a 4 mimo úvěr karty).', 422, ['field' => 'contra_account_code']);
        }
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === 'double_entry';
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
