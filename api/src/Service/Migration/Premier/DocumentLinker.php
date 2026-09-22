<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PDO;

/**
 * Provázání převedených dokladů s převedeným deníkem a úhrad s doklady.
 *
 * Zaúčtování se nepřepočítává: zápis deníku PREMIER dostane `source_id` = id dokladu
 * a vazbu v `journal_entry_document_links`. Faktura se pozná vazbou řádku do sborníku
 * (`SB_KOD` + `SBORNIK`), pokladní doklad a doklad s DPH z deníku klíčem dokladu, bankovní
 * pohyb řádkem deníku na účtu banky.
 *
 * Úhrady vede PREMIER vazbou řádku deníku na fakturu (`VAZBY`), párování je proto přesné:
 * bankovní pohyb z navázaného řádku = úhrada faktury, pokladní doklad s navázaným řádkem
 * hradí fakturu. Zápis úhrady bez pohybu peněz (zápočet, haléřový a kurzový rozdíl,
 * odpočet zálohy) dostane vazbu na fakturu s poznámkou {@see PAYMENT_NOTE}.
 */
final class DocumentLinker
{
    public const STEP_LINK = 'link';
    public const STEP_PAYMENTS = 'payments';

    /** Vazba zápisu úhrady na fakturu - není to zaúčtování faktury (rekonciliace a sirotci ji vynechávají). */
    public const PAYMENT_NOTE = 'Úhrada převzatá z PREMIER';

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
    ) {}

    public function link(PremierContext $ctx, PremierDocuments $documents): void
    {
        $p = $ctx->protocol;
        $issued = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_INVOICE);
        $purchases = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_PURCHASE_INVOICE);
        $series = $documents->invoiceSeries();
        $linkedDocs = [];
        foreach ($ctx->journal->documents($ctx->year) as $docKey => $rows) {
            $entryId = $ctx->entries[$docKey] ?? null;
            if ($entryId === null) {
                continue;
            }
            $first = $rows[0];
            if ($first['sb_kod'] !== '' && isset($series[$first['sb_kod']])) {
                $isIssued = $series[$first['sb_kod']] === PremierDocuments::ISSUED;
                $docId = ($isIssued ? $issued : $purchases)[$first['sb_kod'] . '|' . $first['sbornik']] ?? null;
                if ($docId === null) {
                    $p->count(self::STEP_LINK, 'document_missing');
                    continue;
                }
                $type = $isIssued ? 'invoice' : 'purchase_invoice';
                $linkedDocs[$type . '|' . $docId] = true;
                $p->count(self::STEP_LINK, $this->attach($ctx, $type, $type, $docId, $entryId) ? 'linked' : 'existing');
                continue;
            }
            // Zdroj zápisu dostane první navázaný doklad v pořadí doklad s DPH → bankovní
            // pohyb → pokladní doklad ({@see attach()} přepíše jen zápis bez zdroje). Doklad
            // s DPH i pokladna vznikly z celého dokladu deníku, bankovní zápis je jeho část.
            $group = PremierJournal::groupKey((string) $docKey);
            if (isset($ctx->vatDocuments[$group])) {
                $d = $ctx->vatDocuments[$group];
                $p->count(self::STEP_LINK, $this->attach($ctx, $d['table'], $d['table'], $d['id'], $entryId) ? 'linked' : 'existing');
            }
            foreach ($rows as $r) {
                $txId = $ctx->bankTransactions[$r['inter']] ?? null;
                if ($txId !== null) {
                    $p->count(self::STEP_LINK, $this->attach($ctx, 'bank', 'bank', $txId, $entryId) ? 'bank_linked' : 'existing');
                }
            }
            if (isset($ctx->cashDocuments[$group]) && self::touchesCash($rows)) {
                $p->count(self::STEP_LINK, $this->attach($ctx, 'cash', 'cash', $ctx->cashDocuments[$group], $entryId) ? 'linked' : 'existing');
            }
        }
        foreach (['invoice' => $ctx->issuedInvoices, 'purchase_invoice' => $ctx->purchaseInvoices] as $type => $ids) {
            foreach ($ids as $id) {
                if (isset($ctx->previousPeriod[$type . '|' . $id]) && $this->linkOpening($ctx, $type, $id)) {
                    $p->count(self::STEP_LINK, 'previous_period');
                }
            }
        }
        $orphans = $this->orphans($ctx, $linkedDocs);
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' převedených faktur nemá v deníku PREMIER zápis. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.', ['documents' => array_slice($orphans, 0, 50)]);
        }
        $this->refreshDescriptions($ctx->supplierId, $p);
        $p->finish(self::STEP_LINK);
    }

    public function matchPayments(PremierContext $ctx, PremierDocuments $documents): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $issued = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_INVOICE);
        $purchases = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_PURCHASE_INVOICE);
        $series = array_flip(array_keys($documents->invoiceSeries()));
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_PAYMENT);
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'manual', t.matched_at = NOW(), t.matched_by = ?, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $cashLink = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $cashByRow = [];
        foreach ($ctx->journal->groups($ctx->year) as $docKey => $rows) {
            if (isset($ctx->cashDocuments[$docKey])) {
                // Úhradou v hotovosti je jen řádek na pokladně; haléřové vyrovnání nebo řádek
                // bankovního výpisu s výběrem hotovosti pokladní doklad k faktuře nepřiřadí.
                foreach ($rows as $r) {
                    if (self::touchesCash([$r])) {
                        $cashByRow[$r['inter']] = $ctx->cashDocuments[$docKey];
                    }
                }
            }
        }
        $entryByRow = [];
        foreach ($ctx->journal->documents($ctx->year) as $docKey => $rows) {
            foreach ($rows as $r) {
                if (isset($ctx->entries[$docKey])) {
                    $entryByRow[$r['inter']] = $ctx->entries[$docKey];
                }
            }
        }
        foreach ($documents->paymentLinks() as $rowInter => $targets) {
            $row = $ctx->journal->row($rowInter);
            if ($row === null || $row['year'] !== $ctx->year) {
                continue;
            }
            foreach ($targets as $t) {
                $isIssued = $t['direction'] === PremierDocuments::ISSUED;
                $docId = null;
                foreach ($isIssued ? $issued : $purchases as $key => $id) {
                    if (str_ends_with($key, '|' . $t['inter']) && isset($series[strstr($key, '|', true)])) {
                        $docId = $id;
                        break;
                    }
                }
                if ($docId === null) {
                    continue;
                }
                $mapKey = $rowInter . '|' . ($isIssued ? 'invoice' : 'purchase_invoice') . '|' . $docId;
                if (isset($existing[$mapKey])) {
                    $p->count(self::STEP_PAYMENTS, 'existing');
                    continue;
                }
                $txId = $ctx->bankTransactions[$rowInter] ?? null;
                if ($txId !== null) {
                    $insertMatch->execute([
                        $ctx->supplierId, $txId, $isIssued ? $docId : null, $isIssued ? null : $docId,
                        number_format(abs($row['amount']), 2, '.', ''), $ctx->userOrNull(),
                    ]);
                    $matchId = (int) $pdo->lastInsertId();
                    $markTx->execute([$ctx->userOrNull(), $isIssued ? $docId : null, $txId, $ctx->supplierId]);
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'bank');
                    continue;
                }
                $cashId = $cashByRow[$rowInter] ?? null;
                if ($cashId !== null) {
                    $cashLink->execute([$cashId, $ctx->supplierId]);
                    $linked = $cashLink->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (($linked['invoice_id'] ?? null) === null && ($linked['purchase_invoice_id'] ?? null) === null) {
                        $pdo->prepare('UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id') . " = ?, purpose = ?, vat_mode = 'none' WHERE id = ? AND supplier_id = ?")
                            ->execute([$docId, $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                    } else {
                        $p->count(self::STEP_PAYMENTS, 'cash_multi_invoice');
                    }
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'cash');
                    continue;
                }
                // Zápočet, haléřový nebo kurzový rozdíl, odpočet zálohy - úhrada bez pohybu peněz.
                // Uhrazenost faktury nese už převod faktury (PremierDocuments), zápis úhrady
                // dostane vazbu na fakturu, ať je vidět, čím byla vyrovnaná.
                $entryId = $entryByRow[$rowInter] ?? null;
                if ($entryId !== null) {
                    $this->linkPayment($ctx, $isIssued ? 'invoice' : 'purchase_invoice', $docId, $entryId);
                }
                $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYMENT, $mapKey, $docId, $ctx->runId);
                $p->count(self::STEP_PAYMENTS, 'without_money');
            }
        }
        $this->markBookedWithoutDocument($ctx);
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Pohyby, které PREMIER zaúčtoval bez faktury (poplatky, převody mezi vlastními účty,
     * mzdy, odvody), jsou vyřízené - označí se jako ignorované s poznámkou. Jen pohyb
     * s vlastním zápisem ({@see BankTransactionPostingScope}): ignorovaný pohyb Doúčtování
     * nevidí, bez zápisu by tak zůstal nezaúčtovaný.
     */
    private function markBookedWithoutDocument(PremierContext $ctx): void
    {
        $marked = 0;
        foreach (array_chunk(array_values(array_unique($ctx->bankTransactions)), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                    SET t.match_status = 'ignored', t.match_reason = 'premier_booked', t.matched_at = NOW(), t.matched_by = ?,
                        t.ignore_note = 'Zaúčtováno v PREMIER bez faktury (převod z PREMIER).'
                  WHERE s.supplier_id = ? AND t.match_status = 'unmatched' AND " . BankTransactionPostingScope::existsSql('s.supplier_id', 't.id') . '
                    AND t.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute(array_merge([$ctx->userOrNull(), $ctx->supplierId], $chunk));
            $marked += $stmt->rowCount();
        }
        if ($marked > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'booked_without_document', $marked);
        }
    }

    /** @return array<string,int> „řada|INTER" → id dokladu (i z dřívějších běhů) */
    private function docIndex(int $supplierId, string $kind): array
    {
        return $this->map->all($supplierId, $kind);
    }

    /**
     * Převedené faktury roku bez zápisu v deníku (kromě konceptů, záloh a dokladů minulého období).
     *
     * @param array<string,true> $linked
     * @return list<array{type:string,id:int}>
     */
    private function orphans(PremierContext $ctx, array $linked): array
    {
        $out = [];
        foreach (['invoice' => [$ctx->issuedInvoices, 'invoices', "invoice_type <> 'proforma'"], 'purchase_invoice' => [$ctx->purchaseInvoices, 'purchase_invoices', "document_kind <> 'advance'"]] as $type => [$ids, $table, $filter]) {
            $candidates = array_values(array_filter($ids, static fn (int $id): bool => !isset($linked[$type . '|' . $id]) && !isset($ctx->previousPeriod[$type . '|' . $id])));
            if ($candidates === []) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND status <> 'draft' AND {$filter} AND booked_at IS NOT NULL
                AND id IN (" . implode(',', array_fill(0, count($candidates), '?')) . ')');
            $stmt->execute(array_merge([$ctx->supplierId], $candidates));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!$this->hasLink($ctx->supplierId, $type, (int) $id)) {
                    $out[] = ['type' => $type, 'id' => (int) $id];
                }
            }
        }
        return array_slice($out, 0, ImportProtocol::LIST_LIMIT);
    }

    private function hasLink(int $supplierId, string $type, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND (note IS NULL OR note <> ?) LIMIT 1');
        $stmt->execute([$supplierId, $type, $id, self::PAYMENT_NOTE]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Doklad minulého období (neuhrazená faktura z předchozího roku) dostane vazbu na
     * otevírací zápis roku - Doúčtování dokladů ho podle ní vynechá.
     */
    private function linkOpening(PremierContext $ctx, string $docType, int $docId): bool
    {
        if ($ctx->period === null) {
            return false;
        }
        $pdo = $this->db->pdo();
        $opening = $pdo->prepare(
            "SELECT id FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = 'opening' AND reversed_by IS NULL ORDER BY id LIMIT 1"
        );
        $opening->execute([$ctx->supplierId, (int) $ctx->period['id']]);
        $entryId = $opening->fetchColumn();
        if ($entryId === false) {
            return false;
        }
        $link = $pdo->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $link->execute([$ctx->supplierId, (int) $entryId, $docType, $docId, OpeningBalanceDocuments::NOTE . ' (převzato z PREMIER)', $ctx->userOrNull()]);
        return $link->rowCount() > 0;
    }

    /**
     * Zápis dostane `source_type`/`source_id` dokladu (jen první navázaný doklad a jen
     * zápis převodu bez zdroje) a vazbu v `journal_entry_document_links`.
     *
     * @return bool true = nová vazba
     */
    private function attach(PremierContext $ctx, string $sourceType, string $docType, int $docId, int $entryId, bool $setSource = true): bool
    {
        $pdo = $this->db->pdo();
        $isNew = false;
        if ($setSource) {
            $owner = $pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL LIMIT 1');
            $owner->execute([$ctx->supplierId, $sourceType, $docId]);
            if ($owner->fetchColumn() === false) {
                $stmt = $pdo->prepare(
                    "UPDATE journal_entries SET source_type = ?, source_id = ?
                      WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL"
                );
                $stmt->execute([$sourceType, $docId, $entryId, $ctx->supplierId]);
                $isNew = $stmt->rowCount() > 0;
            }
        }
        $link = $pdo->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $link->execute([$ctx->supplierId, $entryId, $docType, $docId, 'Převzato z PREMIER', $ctx->userOrNull()]);
        $isNew = $isNew || $link->rowCount() > 0;
        if ($docType === 'cash') {
            $pdo->prepare('UPDATE cash_documents SET journal_entry_id = ? WHERE id = ? AND supplier_id = ? AND journal_entry_id IS NULL')
                ->execute([$entryId, $docId, $ctx->supplierId]);
        }
        return $isNew;
    }

    private function linkPayment(PremierContext $ctx, string $docType, int $docId, int $entryId): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $entryId, $docType, $docId, self::PAYMENT_NOTE, $ctx->userOrNull()]);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function touchesCash(array $rows): bool
    {
        foreach ($rows as $r) {
            if (str_starts_with($r['md'], '211') || str_starts_with($r['dal'], '211')) {
                return true;
            }
        }
        return false;
    }

    /** Popisy zápisů doplní rebuilder o číslo dokladu a protistranu (stejně jako u POHODY). */
    private function refreshDescriptions(int $supplierId, ImportProtocol $p): void
    {
        try {
            $rebuilder = new \MyInvoice\Service\Accounting\JournalDescriptionRebuilder(
                $this->db,
                new \MyInvoice\Service\Accounting\JournalDescriptionBuilder($this->db),
            );
            $changed = $rebuilder->rebuild(['supplier_id' => $supplierId]);
            if ($changed > 0) {
                $p->info(self::STEP_LINK, 'descriptions_rebuilt', "U {$changed} převedených zápisů se popis doplnil o číslo dokladu a protistranu.");
            }
        } catch (\Throwable $e) {
            $p->warn(self::STEP_LINK, 'descriptions_rebuild_failed', 'Popisy převedených zápisů se nepodařilo doplnit: ' . $e->getMessage());
        }
    }
}
