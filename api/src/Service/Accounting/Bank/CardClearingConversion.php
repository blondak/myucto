<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Převod plateb kartou zaúčtovaných přes mezičlen (378.x / 261.x / 395.x s analytikou
 * karty) na přímé účtování banky. Mezičlen se v aplikaci už nepoužívá, platba kartou se
 * účtuje jako každá jiná bankovní platba (321/221, 231.x u kreditní karty).
 *
 * Co převod dělá, jen v OTEVŘENÉM období a mimo datum zamčené podaným přiznáním:
 *
 *  - bankovní zápis platby (MD 378.x / D 221) a jeho živé vypořádání s dokladem
 *    (`card_settlement`, MD 321 / D 378.x) nebo uzavření bez dokladu (`card_writeoff`,
 *    MD 548 / D 378.x) sloučí NA MÍSTĚ do bankovního zápisu: řádky vypořádání se
 *    přesunou pod bankovní zápis (i s dimenzemi, páry okruhů a přílohami), řádky
 *    mezičlenu z obou zápisů zmizí a vypořádání se fyzicky smaže. Bankovní zápis si
 *    ponechá id i číslo dokladu. Výsledek je MD 321 [+ 563/663 / 548/648] / D 221.
 *    Obě strany se vyrovnají jen tehdy, když se mezičlen v součtu obou zápisů vynuluje
 *    na haléř a oba zápisy leží v témž účetním období,
 *  - bankovní zápis na mezičlenu bez vypořádání (platba, ke které doklad ještě nebyl)
 *    se smaže a pohyb se vrátí do fronty bankovních pohybů (s bankovním enginem ho
 *    automatika hned zpracuje běžnou cestou),
 *  - storno dvojice (zápis a jeho storno), která leží na mezičlenu, se smaže celá:
 *    na žádném účtu nic nemění a na mezičlenu by nechala obrat,
 *  - prázdné analytiky mezičlenu karet (bez jediného řádku deníku) odstraní z osnovy.
 *
 * Uzavřené a schválené období ani zamčené datum se nepřepisuje; co v nich zůstalo,
 * vrací report (`blocked`). Přesun mezi 378 a 221 je sice daňově neutrální, ale
 * sloučení zápisů mění částky na straně MD i D bankovního zápisu, takže precedens
 * `tax_neutral_rewrite` ({@see \MyInvoice\Service\Accounting\TaxNeutralReclassification})
 * se na něj nevztahuje a zámek se neobchází.
 *
 * Analytiky mezičlenu pozná z nastavení karet (syntetika `card_clearing_settings`,
 * suffixy `payment_cards.analytic_suffix` a `credit_card_accounts.clearing_suffix`,
 * záchranná 199) a z deníku (účet, na kterém se potkal bankovní zápis s vypořádáním).
 * Chybějící tabulky a sloupce toleruje.
 *
 * Idempotentní: druhý běh nemá co převádět.
 */
final class CardClearingConversion
{
    public const SOURCE_TYPES = ['card_settlement', 'card_writeoff'];
    private const SYNTHETICS = ['378', '261', '395'];
    private const FALLBACK_SUFFIX = '199';

    /** Tabulky se sloupcem entry_id, které se přesouvají ze smazaného vypořádání na bankovní zápis. */
    private const MOVED_ENTRY_TABLES = ['journal_entry_document_links', 'journal_entry_attachments', 'journal_entry_notes'];

    /** @var array<string,bool> */
    private array $tableCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ?ActivityLogger $activity = null,
        private readonly ?BankPostingService $bankPosting = null,
    ) {}

    /** Počet převoditelných položek napříč firmami (auto-backfill v migrate.php). */
    public function pending(): int
    {
        $total = 0;
        foreach ($this->supplierIds(null) as $sid) {
            $plan = $this->plan($sid);
            $total += count($plan['merge']) + count($plan['release']) + count($plan['pairs']) + count($plan['empty_accounts']);
        }
        return $total;
    }

    /**
     * @return array{suppliers:array<int,array<string,mixed>>, merged:int, released:int, pairs_deleted:int, accounts_deleted:int, blocked:int}
     */
    public function run(?int $supplierId, bool $apply, ?int $userId = null): array
    {
        $out = ['suppliers' => [], 'merged' => 0, 'released' => 0, 'pairs_deleted' => 0, 'accounts_deleted' => 0, 'blocked' => 0];
        foreach ($this->supplierIds($supplierId) as $sid) {
            $report = $apply ? $this->applySupplier($sid, $userId) : $this->plan($sid);
            $out['suppliers'][$sid] = $report;
            $out['merged'] += count($report['merge']);
            $out['released'] += count($report['release']);
            $out['pairs_deleted'] += count($report['pairs']);
            $out['accounts_deleted'] += count($report['empty_accounts']);
            $out['blocked'] += count($report['blocked']);
        }
        return $out;
    }

    // ── plán ──────────────────────────────────────────────────────────────────

    /**
     * Co by převod u firmy udělal. Nic nezapisuje.
     *
     * @return array{
     *   codes: list<string>,
     *   merge: list<array{tx_id:int, bank_entry_id:int, settlement_entry_ids:list<int>, document_no:?string, entry_date:string}>,
     *   release: list<array{tx_id:int, bank_entry_id:int, document_no:?string, entry_date:string}>,
     *   pairs: list<array{entry_id:int, reversal_id:int, source_type:string}>,
     *   empty_accounts: list<string>,
     *   blocked: list<array{entry_id:int, reason:string, detail:string}>,
     *   remaining: array<string,array{lines:int, debit:float, credit:float}>
     * }
     */
    public function plan(int $supplierId): array
    {
        $codes = $this->clearingCodes($supplierId);
        $plan = ['codes' => $codes, 'merge' => [], 'release' => [], 'pairs' => [], 'empty_accounts' => [], 'blocked' => [], 'remaining' => []];
        $lockedUntil = $this->lockedUntil($supplierId);
        $handled = [];

        if ($codes !== []) {
            foreach ($this->liveBankEntriesOnClearing($supplierId, $codes) as $bank) {
                $handled[$bank['id']] = true;
                $block = $this->blockReason($bank, $lockedUntil);
                $settlements = $this->liveSettlements($supplierId, (int) $bank['source_id']);
                foreach ($settlements as $s) {
                    $handled[$s['id']] = true;
                }
                if ($block !== null) {
                    $plan['blocked'][] = ['entry_id' => $bank['id'], 'reason' => $block, 'detail' => 'bankovní zápis ' . ($bank['document_no'] ?? '#' . $bank['id']) . ' (' . $bank['entry_date'] . ')'];
                    continue;
                }
                if ($settlements === []) {
                    $plan['release'][] = ['tx_id' => (int) $bank['source_id'], 'bank_entry_id' => $bank['id'], 'document_no' => $bank['document_no'], 'entry_date' => $bank['entry_date']];
                    continue;
                }
                $problem = null;
                foreach ($settlements as $s) {
                    $problem ??= $this->blockReason($s, $lockedUntil);
                    if ($problem === null && $s['period_id'] !== $bank['period_id']) {
                        $problem = 'other_period';
                    }
                }
                $problem ??= $this->mergeProblem($supplierId, $bank, $settlements, $codes);
                if ($problem !== null) {
                    $plan['blocked'][] = ['entry_id' => $bank['id'], 'reason' => $problem, 'detail' => 'bankovní zápis ' . ($bank['document_no'] ?? '#' . $bank['id']) . ' s vypořádáním #' . implode(', #', array_column($settlements, 'id'))];
                    continue;
                }
                $plan['merge'][] = [
                    'tx_id'                => (int) $bank['source_id'],
                    'bank_entry_id'        => $bank['id'],
                    'settlement_entry_ids' => array_map(static fn (array $s): int => $s['id'], $settlements),
                    'document_no'          => $bank['document_no'],
                    'entry_date'           => $bank['entry_date'],
                ];
            }
        }

        foreach ($this->reversalPairs($supplierId, $codes) as $pair) {
            if (isset($handled[$pair['entry']['id']]) || isset($handled[$pair['reversal']['id']])) {
                continue;
            }
            $handled[$pair['entry']['id']] = $handled[$pair['reversal']['id']] = true;
            $block = $this->blockReason($pair['entry'], $lockedUntil) ?? $this->blockReason($pair['reversal'], $lockedUntil);
            if ($block !== null) {
                $plan['blocked'][] = ['entry_id' => $pair['entry']['id'], 'reason' => $block, 'detail' => 'storno dvojice #' . $pair['entry']['id'] . ' / #' . $pair['reversal']['id']];
                continue;
            }
            $plan['pairs'][] = ['entry_id' => $pair['entry']['id'], 'reversal_id' => $pair['reversal']['id'], 'source_type' => $pair['entry']['source_type']];
        }

        // Živé vypořádání bez živého bankového zápisu na mezičlenu se sloučit nedá.
        foreach ($this->liveCardEntries($supplierId) as $entry) {
            if (!isset($handled[$entry['id']])) {
                $plan['blocked'][] = ['entry_id' => $entry['id'], 'reason' => 'orphan_settlement', 'detail' => $entry['source_type'] . ' #' . $entry['id'] . ' (' . $entry['entry_date'] . ')'];
            }
        }

        $plan['empty_accounts'] = $this->emptyClearingAccounts($supplierId, $codes);
        $plan['remaining'] = $this->turnover($supplierId, $codes);
        return $plan;
    }

    // ── zápis ─────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function applySupplier(int $supplierId, ?int $userId): array
    {
        $plan = $this->plan($supplierId);
        $done = ['merge' => [], 'release' => [], 'pairs' => [], 'empty_accounts' => []];
        $requeue = [];
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT card_clearing_conversion');
        }
        try {
            foreach ($plan['merge'] as $item) {
                $this->merge($supplierId, $item, $plan['codes'], $userId);
                $done['merge'][] = $item;
            }
            foreach ($plan['release'] as $item) {
                if ($this->bankPosting === null) {
                    $plan['blocked'][] = ['entry_id' => $item['bank_entry_id'], 'reason' => 'needs_bank_engine', 'detail' => 'pohyb #' . $item['tx_id'] . ' vrací do fronty jen api/bin/card-clearing-to-direct.php'];
                    continue;
                }
                $this->release($supplierId, $item, $userId);
                $done['release'][] = $item;
                $requeue[] = $item['tx_id'];
            }
            foreach ($plan['pairs'] as $item) {
                $this->deletePair($supplierId, $item, $userId);
                $done['pairs'][] = $item;
            }
            if ($ownTx) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT card_clearing_conversion');
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTx && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT card_clearing_conversion');
            }
            throw $e;
        }

        foreach ($this->emptyClearingAccounts($supplierId, $plan['codes']) as $code) {
            if ($this->deleteAccount($supplierId, $code)) {
                $done['empty_accounts'][] = $code;
            } else {
                $plan['blocked'][] = ['entry_id' => 0, 'reason' => 'account_referenced', 'detail' => 'analytika ' . $code . ' je navázaná jinde v nastavení a zůstává v osnově'];
            }
        }

        // Vrácené pohyby projdou bankovní automatikou až po commitu převodu: pravidla,
        // párování a návrhy fungují stejně jako u každého jiného nezaúčtovaného pohybu.
        foreach ($requeue as $txId) {
            try {
                $this->bankPosting?->handleTransaction($txId, $userId);
            } catch (\Throwable) {
            }
        }

        return [
            'codes'          => $plan['codes'],
            'merge'          => $done['merge'],
            'release'        => $done['release'],
            'pairs'          => $done['pairs'],
            'empty_accounts' => $done['empty_accounts'],
            'blocked'        => $plan['blocked'],
            'remaining'      => $this->turnover($supplierId, $plan['codes']),
        ];
    }

    /**
     * @param array{tx_id:int, bank_entry_id:int, settlement_entry_ids:list<int>} $item
     * @param list<string> $codes
     */
    private function merge(int $supplierId, array $item, array $codes, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $bankId = $item['bank_entry_id'];
        $lock = $pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND id IN (' . implode(',', array_map('intval', [$bankId, ...$item['settlement_entry_ids']])) . ') FOR UPDATE');
        $lock->execute([$supplierId]);

        $codeIds = $this->accountIds($supplierId, $codes);
        $bankLines = $this->lines($supplierId, $bankId);
        $before = ['bank' => $bankLines, 'settlements' => []];
        $nextNo = max(array_map(static fn (array $l): int => (int) $l['line_no'], $bankLines)) + 1;
        $pairings = $this->tableExists('journal_line_pairing_items');

        $movedTo = [];
        foreach ($item['settlement_entry_ids'] as $sid) {
            $sLines = $this->lines($supplierId, $sid);
            $before['settlements'][$sid] = $sLines;
            foreach ($sLines as $l) {
                if (isset($codeIds[(int) $l['account_id']])) {
                    continue;
                }
                $pdo->prepare('UPDATE journal_entry_lines SET entry_id = ?, line_no = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$bankId, $nextNo, (int) $l['id'], $supplierId]);
                if ($pairings) {
                    $pdo->prepare('UPDATE journal_line_pairing_items SET entry_id = ?, line_no = ? WHERE supplier_id = ? AND entry_id = ? AND line_no = ?')
                        ->execute([$bankId, $nextNo, $supplierId, $sid, (int) $l['line_no']]);
                }
                $movedTo[(int) $l['id']] = $nextNo;
                $nextNo++;
            }
            foreach (self::MOVED_ENTRY_TABLES as $table) {
                if ($this->tableExists($table)) {
                    $pdo->prepare("UPDATE IGNORE {$table} SET entry_id = ? WHERE supplier_id = ? AND entry_id = ?")
                        ->execute([$bankId, $supplierId, $sid]);
                }
            }
        }

        foreach ($bankLines as $l) {
            if (!isset($codeIds[(int) $l['account_id']])) {
                continue;
            }
            if ($pairings) {
                $pdo->prepare('DELETE FROM journal_line_pairing_items WHERE supplier_id = ? AND entry_id = ? AND line_no = ?')
                    ->execute([$supplierId, $bankId, (int) $l['line_no']]);
            }
            $pdo->prepare('DELETE FROM journal_entry_lines WHERE id = ? AND supplier_id = ?')->execute([(int) $l['id'], $supplierId]);
        }
        foreach ($item['settlement_entry_ids'] as $sid) {
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?')->execute([$sid, $supplierId]);
        }
        $pdo->prepare('UPDATE journal_entries SET row_version = row_version + 1 WHERE id = ? AND supplier_id = ?')
            ->execute([$bankId, $supplierId]);

        $this->assertBalanced($supplierId, $bankId);
        $this->activity?->log('accounting.card_clearing_converted', $userId, 'journal_entry', $bankId, [
            'bank_transaction_id' => $item['tx_id'],
            'deleted_entries'     => $item['settlement_entry_ids'],
            'moved_lines'         => $movedTo,
            'before'              => $before,
            'after'               => $this->lines($supplierId, $bankId),
        ], null, null, $supplierId);
    }

    /** @param array{tx_id:int, bank_entry_id:int} $item */
    private function release(int $supplierId, array $item, ?int $userId): void
    {
        $lines = $this->lines($supplierId, $item['bank_entry_id']);
        $this->bankPosting?->prepareEntryDeletion($supplierId, $item['tx_id'], $item['bank_entry_id'], ['user_id' => $userId, 'reason' => 'card_clearing_removed']);
        $this->deleteEntry($supplierId, $item['bank_entry_id']);
        $this->activity?->log('accounting.card_clearing_released', $userId, 'bank_transaction', $item['tx_id'], [
            'deleted_entry_id' => $item['bank_entry_id'],
            'lines'            => $lines,
        ], null, null, $supplierId);
    }

    /** @param array{entry_id:int, reversal_id:int, source_type:string} $item */
    private function deletePair(int $supplierId, array $item, ?int $userId): void
    {
        $lines = [$item['entry_id'] => $this->lines($supplierId, $item['entry_id']), $item['reversal_id'] => $this->lines($supplierId, $item['reversal_id'])];
        // Protizápis první: reversed_by je FK se SET NULL.
        $this->deleteEntry($supplierId, $item['reversal_id']);
        $this->deleteEntry($supplierId, $item['entry_id']);
        $this->activity?->log('accounting.reversal_pair_deleted', $userId, 'journal_entry', $item['entry_id'], [
            'reason'            => 'card_clearing_removed',
            'reversal_entry_id' => $item['reversal_id'],
            'source_type'       => $item['source_type'],
            'lines'             => $lines,
        ], null, null, $supplierId);
    }

    private function deleteEntry(int $supplierId, int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $supplierId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Účetní zápis #' . $entryId . ' se nepodařilo smazat.');
        }
    }

    private function deleteAccount(int $supplierId, string $code): bool
    {
        $pdo = $this->db->pdo();
        $inTx = $pdo->inTransaction();
        if ($inTx) {
            $pdo->exec('SAVEPOINT card_clearing_account');
        }
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM chart_of_accounts
                  WHERE supplier_id = ? AND account_code = ?
                    AND NOT EXISTS (SELECT 1 FROM journal_entry_lines l WHERE l.supplier_id = chart_of_accounts.supplier_id AND l.account_id = chart_of_accounts.id)'
            );
            $stmt->execute([$supplierId, $code]);
            if ($inTx) {
                $pdo->exec('RELEASE SAVEPOINT card_clearing_account');
            }
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            if ($inTx) {
                $pdo->exec('ROLLBACK TO SAVEPOINT card_clearing_account');
            }
            return false;
        }
    }

    private function assertBalanced(int $supplierId, int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN side = 'debit' THEN ROUND(amount * 100) ELSE -ROUND(amount * 100) END), 0), COUNT(*)
               FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ?"
        );
        $stmt->execute([$supplierId, $entryId]);
        [$diff, $count] = $stmt->fetch(PDO::FETCH_NUM);
        if ((int) $diff !== 0 || (int) $count < 2) {
            throw new \RuntimeException('Převedený bankovní zápis #' . $entryId . ' není vyrovnaný - převod firmy se vrací.');
        }
    }

    // ── čtení ─────────────────────────────────────────────────────────────────

    /**
     * Analytiky mezičlenu karet firmy.
     *
     * @return list<string>
     */
    public function clearingCodes(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $synthetics = [];
        if ($this->tableExists('card_clearing_settings')) {
            $stmt = $pdo->prepare('SELECT clearing_synthetic FROM card_clearing_settings WHERE supplier_id = ?');
            $stmt->execute([$supplierId]);
            $syn = $stmt->fetchColumn();
            if (is_string($syn) && in_array($syn, self::SYNTHETICS, true)) {
                $synthetics[$syn] = true;
            }
        }
        $suffixes = [];
        if ($this->columnExists('payment_cards', 'analytic_suffix')) {
            $stmt = $pdo->prepare('SELECT analytic_suffix FROM payment_cards WHERE supplier_id = ? AND analytic_suffix IS NOT NULL');
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $s) {
                $suffixes[(string) $s] = true;
            }
        }
        if ($this->columnExists('credit_card_accounts', 'clearing_suffix')) {
            $stmt = $pdo->prepare('SELECT clearing_suffix FROM credit_card_accounts WHERE supplier_id = ? AND clearing_suffix IS NOT NULL');
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $s) {
                $suffixes[(string) $s] = true;
            }
        }
        $codes = [];

        // Z deníku: účet, přes který se bankovní zápis potkal s vypořádáním (i stornovaným).
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.account_code
               FROM journal_entries s
               JOIN journal_entry_lines sl ON sl.entry_id = s.id AND sl.supplier_id = s.supplier_id
               JOIN chart_of_accounts c ON c.id = sl.account_id AND c.supplier_id = sl.supplier_id
              WHERE s.supplier_id = ? AND s.source_type IN ('card_settlement', 'card_writeoff')
                AND c.account_code REGEXP '^(378|261|395)[.][0-9]{1,6}$'"
        );
        try {
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $code) {
                $code = (string) $code;
                $codes[$code] = true;
                $synthetics[substr($code, 0, 3)] = true;
            }
        } catch (\PDOException) {
            // enum už hodnoty nezná - v deníku žádná vypořádání nejsou
        }

        if ($synthetics !== []) {
            $suffixes[self::FALLBACK_SUFFIX] = true;
            foreach (array_keys($synthetics) as $syn) {
                foreach (array_keys($suffixes) as $suffix) {
                    $codes[$syn . '.' . $suffix] = true;
                }
            }
        }
        if ($codes === []) {
            return [];
        }
        // Jen analytiky, které v osnově firmy opravdu jsou.
        $list = array_keys($codes);
        $in = implode(',', array_fill(0, count($list), '?'));
        $stmt = $pdo->prepare("SELECT account_code FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ({$in}) ORDER BY account_code");
        $stmt->execute([$supplierId, ...$list]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param list<string> $codes
     * @return list<array<string,mixed>>
     */
    private function liveBankEntriesOnClearing(int $supplierId, array $codes): array
    {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            self::ENTRY_SELECT . " WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.source_id IS NOT NULL
                AND je.reversed_by IS NULL
                AND EXISTS (SELECT 1 FROM journal_entry_lines l
                              JOIN chart_of_accounts c ON c.id = l.account_id AND c.supplier_id = l.supplier_id
                             WHERE l.entry_id = je.id AND l.supplier_id = je.supplier_id AND c.account_code IN ({$in}))
              ORDER BY je.entry_date, je.id"
        );
        $stmt->execute([$supplierId, ...$codes]);
        return array_map(self::castEntry(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> živá vypořádání a uzavření pohybu */
    private function liveSettlements(int $supplierId, int $txId): array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                self::ENTRY_SELECT . " WHERE je.supplier_id = ? AND je.source_type IN ('card_settlement', 'card_writeoff')
                    AND je.source_id = ? AND je.reversed_by IS NULL ORDER BY je.id"
            );
            $stmt->execute([$supplierId, $txId]);
        } catch (\PDOException) {
            return [];
        }
        return array_map(self::castEntry(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    private function liveCardEntries(int $supplierId): array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                self::ENTRY_SELECT . " WHERE je.supplier_id = ? AND je.source_type IN ('card_settlement', 'card_writeoff')
                    AND je.reversed_by IS NULL
                    AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.supplier_id = je.supplier_id AND r.reversed_by = je.id)
                  ORDER BY je.id"
            );
            $stmt->execute([$supplierId]);
        } catch (\PDOException) {
            return [];
        }
        return array_map(self::castEntry(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Storno dvojice, které leží na mezičlenu: stornovaná vypořádání a uzavření a stornované
     * bankovní zápisy s řádkem na analytice karty.
     *
     * @param list<string> $codes
     * @return list<array{entry:array<string,mixed>, reversal:array<string,mixed>}>
     */
    private function reversalPairs(int $supplierId, array $codes): array
    {
        $conditions = ["je.source_type IN ('card_settlement', 'card_writeoff')"];
        $params = [$supplierId];
        if ($codes !== []) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $conditions[] = "(je.source_type = 'bank' AND EXISTS (SELECT 1 FROM journal_entry_lines l
                                JOIN chart_of_accounts c ON c.id = l.account_id AND c.supplier_id = l.supplier_id
                               WHERE l.entry_id = je.id AND l.supplier_id = je.supplier_id AND c.account_code IN ({$in})))";
            array_push($params, ...$codes);
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                self::ENTRY_SELECT . ' WHERE je.supplier_id = ? AND je.reversed_by IS NOT NULL AND (' . implode(' OR ', $conditions) . ') ORDER BY je.id'
            );
            $stmt->execute($params);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        $byId = $this->db->pdo()->prepare(self::ENTRY_SELECT . ' WHERE je.supplier_id = ? AND je.id = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $entry = self::castEntry($row);
            $byId->execute([$supplierId, (int) $row['reversed_by']]);
            $reversal = $byId->fetch(PDO::FETCH_ASSOC);
            // Storno se stornem (řetěz) se nemaže - vyřeší ho účetní ručně.
            if ($reversal === false || $reversal['reversed_by'] !== null) {
                continue;
            }
            $out[] = ['entry' => $entry, 'reversal' => self::castEntry($reversal)];
        }
        return $out;
    }

    /**
     * Proč se bankovní zápis s vypořádáním nedá sloučit, nebo null.
     *
     * @param array<string,mixed> $bank
     * @param list<array<string,mixed>> $settlements
     * @param list<string> $codes
     */
    private function mergeProblem(int $supplierId, array $bank, array $settlements, array $codes): ?string
    {
        $codeIds = $this->accountIds($supplierId, $codes);
        $net = [];
        foreach ([$bank, ...$settlements] as $entry) {
            if ($entry['posted_at'] === null) {
                return 'draft_entry';
            }
            foreach ($this->lines($supplierId, $entry['id']) as $l) {
                $accountId = (int) $l['account_id'];
                if (isset($codeIds[$accountId])) {
                    $cents = (int) round(((float) $l['amount']) * 100);
                    $net[$accountId] = ($net[$accountId] ?? 0) + ($l['side'] === 'debit' ? $cents : -$cents);
                }
            }
        }
        foreach ($net as $cents) {
            if ($cents !== 0) {
                return 'clearing_not_settled';
            }
        }
        foreach ($settlements as $s) {
            if ($this->foreignReferences($supplierId, $s['id']) !== []) {
                return 'settlement_referenced';
            }
        }
        return null;
    }

    /**
     * Cizí klíče na zápis mimo řádky, přílohy, poznámky, vazby na doklady a páry okruhů
     * (ty převod přesune) - kdyby na vypořádání něco ukazovalo, smazání by to rozbilo.
     *
     * @return list<string>
     */
    private function foreignReferences(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'journal_entries'
                AND REFERENCED_COLUMN_NAME = 'id'"
        );
        $skip = [...self::MOVED_ENTRY_TABLES, 'journal_entry_lines', 'journal_line_pairing_items'];
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fk) {
            $table = (string) $fk['TABLE_NAME'];
            $column = (string) $fk['COLUMN_NAME'];
            if (in_array($table, $skip, true) || ($table === 'journal_entries' && $column === 'reversed_by')) {
                continue;
            }
            if (preg_match('/^[a-z0-9_]+$/', $table . $column) !== 1) {
                continue;
            }
            $check = $this->db->pdo()->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
            $check->execute([$entryId]);
            if ($check->fetchColumn() !== false) {
                $out[] = $table . '.' . $column;
            }
        }
        return $out;
    }

    /**
     * Analytiky mezičlenu, na kterých v deníku není žádný řádek.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    private function emptyClearingAccounts(int $supplierId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code FROM chart_of_accounts c
              WHERE c.supplier_id = ? AND c.account_code IN ({$in})
                AND NOT EXISTS (SELECT 1 FROM journal_entry_lines l WHERE l.supplier_id = c.supplier_id AND l.account_id = c.id)
                AND NOT EXISTS (SELECT 1 FROM chart_of_accounts ch WHERE ch.supplier_id = c.supplier_id AND ch.parent_id = c.id)
              ORDER BY c.account_code"
        );
        $stmt->execute([$supplierId, ...$codes]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Obraty analytik mezičlenu (všechna období).
     *
     * @param list<string> $codes
     * @return array<string,array{lines:int, debit:float, credit:float}>
     */
    public function turnover(int $supplierId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code, COUNT(l.id) AS n,
                    COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount END), 0) AS d,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount END), 0) AS cr
               FROM chart_of_accounts c
               JOIN journal_entry_lines l ON l.account_id = c.id AND l.supplier_id = c.supplier_id
              WHERE c.supplier_id = ? AND c.account_code IN ({$in})
              GROUP BY c.account_code ORDER BY c.account_code"
        );
        $stmt->execute([$supplierId, ...$codes]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['account_code']] = ['lines' => (int) $r['n'], 'debit' => round((float) $r['d'], 2), 'credit' => round((float) $r['cr'], 2)];
        }
        return $out;
    }

    /** @param array<string,mixed> $entry */
    private function blockReason(array $entry, ?string $lockedUntil): ?string
    {
        if ($entry['period_status'] !== 'open') {
            return 'period_' . $entry['period_status'];
        }
        if ($lockedUntil !== null && $entry['entry_date'] <= $lockedUntil) {
            return 'date_locked';
        }
        return null;
    }

    /**
     * @param list<string> $codes
     * @return array<int,string> id účtu => kód
     */
    private function accountIds(int $supplierId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ({$in})");
        $stmt->execute([$supplierId, ...$codes]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = (string) $r['account_code'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.line_no, l.account_id, c.account_code, l.side, l.amount, l.currency_code, l.fx_rate, l.amount_foreign
               FROM journal_entry_lines l
               JOIN chart_of_accounts c ON c.id = l.account_id AND c.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ?
              ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$supplierId, $entryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function lockedUntil(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null || $v === '' ? null : (string) $v;
    }

    /** @return list<int> */
    private function supplierIds(?int $supplierId): array
    {
        if ($supplierId !== null) {
            return [$supplierId];
        }
        $ids = [];
        try {
            foreach ($this->db->pdo()->query(
                "SELECT DISTINCT supplier_id FROM journal_entries WHERE source_type IN ('card_settlement', 'card_writeoff')"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $ids[(int) $id] = true;
            }
        } catch (\PDOException) {
        }
        if ($this->tableExists('card_clearing_settings')) {
            foreach ($this->db->pdo()->query('SELECT supplier_id FROM card_clearing_settings')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $ids[(int) $id] = true;
            }
        }
        foreach (['payment_cards' => 'analytic_suffix', 'credit_card_accounts' => 'clearing_suffix'] as $table => $column) {
            if ($this->columnExists($table, $column)) {
                foreach ($this->db->pdo()->query("SELECT DISTINCT supplier_id FROM {$table} WHERE {$column} IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }
        $out = array_keys($ids);
        sort($out);
        return $out;
    }

    private function tableExists(string $table): bool
    {
        if (!isset($this->tableCache[$table])) {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            $this->tableCache[$table] = $stmt->fetchColumn() !== false;
        }
        return $this->tableCache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset($this->tableCache[$key])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            $this->tableCache[$key] = $stmt->fetchColumn() !== false;
        }
        return $this->tableCache[$key];
    }

    private const ENTRY_SELECT =
        'SELECT je.id, je.period_id, je.entry_date, je.document_no, je.source_type, je.source_id,
                je.posted_at, je.reversed_by, p.status AS period_status
           FROM journal_entries je
           JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id';

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function castEntry(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'period_id'     => (int) $r['period_id'],
            'entry_date'    => (string) $r['entry_date'],
            'document_no'   => $r['document_no'] !== null ? (string) $r['document_no'] : null,
            'source_type'   => (string) $r['source_type'],
            'source_id'     => $r['source_id'] !== null ? (int) $r['source_id'] : null,
            'posted_at'     => $r['posted_at'] !== null ? (string) $r['posted_at'] : null,
            'reversed_by'   => $r['reversed_by'] !== null ? (int) $r['reversed_by'] : null,
            'period_status' => (string) $r['period_status'],
        ];
    }
}
