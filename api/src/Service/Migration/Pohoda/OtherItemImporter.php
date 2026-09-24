<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use PDO;

final class OtherItemImporter
{
    public const STEP = 'other_items';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $mapped = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM);
        $journal = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        foreach (['receivable' => PohodaJournal::RECEIVABLE, 'commitment' => PohodaJournal::COMMITMENT] as $agenda => $source) {
            $invoiceKind = $agenda === 'receivable'
                ? PohodaImportRepository::KIND_INVOICE
                : PohodaImportRepository::KIND_PURCHASE_INVOICE;
            foreach ($ctx->export->records($agenda, 'invoice') as $record) {
                $header = PohodaXml::get($record, 'invoiceHeader');
                $number = PohodaXml::text($header, 'number/numberRequested');
                $issued = PohodaXml::date($header, 'date') ?? PohodaXml::date($header, 'dateAccounting');
                if ($number === '' || $issued === null || $ctx->skipsDate($issued)) {
                    $ctx->protocol->count(self::STEP, 'skipped');
                    continue;
                }
                $key = $agenda . '|' . $number . '|' . $issued;
                if (isset($mapped[$key]) || $this->map->get($ctx->supplierId, $invoiceKind, $key) !== null) {
                    $ctx->protocol->count(self::STEP, 'existing');
                    continue;
                }
                $candidate = self::candidate($record, $agenda, $ctx->vat);
                if ($candidate === null) {
                    $ctx->protocol->count(self::STEP, 'requires_review');
                    continue;
                }
                $entries = [];
                foreach ($journal as $journalKey => $entryId) {
                    $parts = explode('|', (string) $journalKey, 4);
                    if (count($parts) === 4 && $parts[1] === $source && $parts[2] === $number
                        && (int) $parts[0] >= $ctx->year() - 1 && (int) $parts[0] <= $ctx->year()) {
                        $entries[(int) $entryId] = true;
                    }
                }
                if (count($entries) !== 1) {
                    $ctx->protocol->count(self::STEP, 'unlinked');
                    continue;
                }
                $entryId = (int) array_key_first($entries);
                $posting = $this->posting($ctx->supplierId, $entryId, $agenda, $candidate['amount']);
                if ($posting === null) {
                    $ctx->protocol->count(self::STEP, 'requires_review');
                    continue;
                }
                $snapshot = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
                $pdo = $this->db->pdo();
                try {
                    $insert = $pdo->prepare(
                        'INSERT INTO other_items
                          (supplier_id, side, kind, title, partner_name, issued_on, accounting_on, due_on,
                           currency, amount, amount_czk, variable_symbol, account_code, counter_account_code,
                           note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        $ctx->supplierId, $agenda === 'receivable' ? 'receivable' : 'payable', 'other',
                        mb_substr($candidate['title'], 0, 255),
                        mb_substr($snapshot['name'], 0, 190) ?: null,
                        $issued, $posting['date'], $candidate['due'], 'CZK', $candidate['amount'],
                        $candidate['amount'], $candidate['symbol'], $posting['account'], $posting['counter'],
                        'Převzato z POHODY, doklad ' . $number, 'posted', mb_substr($number, 0, 50),
                        $entryId, $posting['posted_at'], $ctx->userOrNull(), $ctx->userOrNull(),
                    ]);
                } catch (\PDOException $e) {
                    if ((string) $e->getCode() !== '23000') throw $e;
                    $ctx->protocol->warn(self::STEP, 'number_taken', "Doklad {$number} nelze převzít, číslo už má jiná pohledávka nebo závazek.", ['document_no' => $number]);
                    continue;
                }
                $id = (int) $pdo->lastInsertId();
                $owner = $pdo->prepare(
                    "UPDATE journal_entries SET source_type = 'other_item', source_id = ?
                       WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL"
                );
                $owner->execute([$id, $entryId, $ctx->supplierId]);
                if ($owner->rowCount() !== 1) {
                    throw new PohodaException('other_item_journal_claim', 'Převzatý zápis deníku už vlastní jiný doklad.');
                }
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM, $key, $id, $ctx->runId);
                $ctx->protocol->count(self::STEP, 'created');
            }
        }
    }

    public static function candidate(array $record, string $agenda, PohodaVat $vat): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return null;
        $header = PohodaXml::get($record, 'invoiceHeader');
        $summary = PohodaXml::get($record, 'invoiceSummary/homeCurrency');
        $code = PohodaXml::text($header, 'classificationVAT/ids');
        $class = $agenda === 'receivable' ? $vat->sale($code, 0.0) : $vat->purchase($code);
        if ($code === '' || $class === null || $class['in_return'] || PohodaXml::text($header, 'MOSS/ids') !== '') return null;
        if (PohodaXml::text($record, 'invoiceSummary/foreignCurrency/currency/ids') !== '') return null;
        if (PohodaXml::all($record, 'liquidations/liquidation') !== []
            || PohodaXml::all($record, 'invoiceDetail/invoiceAdvancePaymentItem') !== []) return null;
        foreach (['priceLow', 'priceLowVAT', 'priceHigh', 'priceHighVAT', 'price3', 'price3VAT', 'round/priceRound'] as $field) {
            if (abs(PohodaXml::num($summary, $field)) >= 0.005) return null;
        }
        $amount = round(PohodaXml::num($summary, 'priceNone'), 2);
        $summaryTotal = PohodaXml::text($summary, 'priceSum');
        if ($summaryTotal !== '' && (!is_numeric($summaryTotal) || abs((float) $summaryTotal - $amount) >= 0.005)) return null;
        $detailAmount = 0.0;
        $details = PohodaXml::all($record, 'invoiceDetail/invoiceItem');
        foreach ($details as $detail) {
            if (abs(PohodaXml::num($detail, 'homeCurrency/priceVAT')) >= 0.005) return null;
            $detailAmount += PohodaXml::num($detail, 'homeCurrency/price');
        }
        if ($details !== [] && abs($detailAmount - $amount) >= 0.005) return null;
        $remaining = PohodaXml::text($header, 'liquidation/amountHome');
        if ($amount <= 0 || !is_numeric($remaining) || abs((float) $remaining - $amount) >= 0.005) return null;
        $due = PohodaXml::date($header, 'dateDue') ?? PohodaXml::date($header, 'date');
        if ($due === null) return null;
        $title = PohodaXml::text($header, 'text');
        $symbol = PohodaXml::text($header, 'symVar');
        return [
            'amount' => $amount,
            'due' => $due,
            'title' => $title !== '' ? $title : PohodaXml::text($header, 'number/numberRequested'),
            'symbol' => $symbol !== '' && mb_strlen($symbol) <= 20 ? $symbol : null,
        ];
    }

    private function posting(int $supplierId, int $entryId, string $agenda, float $amount): ?array
    {
        $entry = $this->db->pdo()->prepare(
            "SELECT entry_date, posted_at FROM journal_entries
              WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL
                AND reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l
                                 WHERE l.supplier_id = journal_entries.supplier_id AND l.entry_id = journal_entries.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi
                                 WHERE oi.supplier_id = journal_entries.supplier_id AND oi.journal_entry_id = journal_entries.id)"
        );
        $entry->execute([$entryId, $supplierId]);
        $head = $entry->fetch(PDO::FETCH_ASSOC);
        if ($head === false) return null;
        $lines = $this->db->pdo()->prepare(
            'SELECT l.side, l.amount, a.account_code
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $lines->execute([$entryId, $supplierId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        $accounts = self::postingLines($rows, $agenda, $amount);
        if ($accounts === null) return null;
        return $accounts + ['date' => (string) $head['entry_date'], 'posted_at' => (string) $head['posted_at']];
    }

    public static function postingLines(array $rows, string $agenda, float $amount): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return null;
        if (count($rows) !== 2) return null;
        $balanceSide = $agenda === 'receivable' ? 'debit' : 'credit';
        $balanceCode = $agenda === 'receivable' ? '315' : '325';
        $balance = null;
        $counter = null;
        foreach ($rows as $row) {
            if (abs((float) $row['amount'] - $amount) >= 0.005) return null;
            if ($row['side'] === $balanceSide && str_starts_with((string) $row['account_code'], $balanceCode)) {
                $balance = (string) $row['account_code'];
            } elseif ($row['side'] !== $balanceSide && !str_starts_with((string) $row['account_code'], '3')) {
                $counter = (string) $row['account_code'];
            }
        }
        if ($balance === null || $counter === null) return null;
        return ['account' => $balance, 'counter' => $counter];
    }
}
