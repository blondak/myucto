<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;

/**
 * Doklady deníku PREMIER, které nejsou fakturou: pokladna a doklady s DPH mimo faktury.
 *
 * PREMIER vede pokladní doklady, bankovní výpisy i interní doklady jen v deníku
 * (`PUB_UCTO`), s kódem DPH na řádku. Doklad s DPH, který nemá fakturu, by bez tohoto
 * kroku z přiznání i KH vypadl.
 *
 * - **Pokladna** (doklad s pohybem na 211): pokladní doklad MyÚčta. Nese-li tuzemský kód
 *   DPH (odpočet ř. 40/41, výstup ř. 1/2), dostane řádky DPH a do evidence DPH vstoupí
 *   jako pokladní doklad; úhradu faktury (bez kódu DPH) naváže {@see DocumentLinker}.
 * - **Ostatní doklad s DPH** (bankovní poplatek s daní, interní doklad se samovyměřením,
 *   pokladní doklad s kódem samovyměření…): přijatý nebo vydaný doklad s položkami po kódech
 *   DPH, zařazený stejnou cestou jako faktura ({@see InvoiceImporter::importJournalDocument()}).
 *
 * Kódy vypořádání DPH (bez příznaku přijatého i uskutečněného plnění - převod 343 na
 * 343.900, koeficient) doklad netvoří: zůstávají jen v deníku.
 */
final class VatDocumentImporter
{
    public const STEP = 'vat_documents';

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly InvoiceImporter $invoices,
    ) {}

    public function import(PremierContext $ctx, PremierDocuments $documents): void
    {
        $p = $ctx->protocol;
        $invoiceSeries = $documents->invoiceSeries();
        $existingCash = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_CASH_DOCUMENT);
        $registers = [];

        foreach ($ctx->journal->documents($ctx->year) as $docKey => $rows) {
            $first = $rows[0];
            if ($first['sb_kod'] !== '' && isset($invoiceSeries[$first['sb_kod']])) {
                continue; // řádky faktury - převádí je InvoiceImporter
            }
            $cashAccount = self::cashAccount($rows);
            $vat = $this->vatBuckets($ctx, $rows, $cashAccount);
            $domesticCash = $cashAccount !== null && $vat['other'] === [] ;
            if ($cashAccount !== null) {
                $cashId = $existingCash['cash|' . $ctx->year . '|' . $docKey] ?? null;
                if ($cashId === null) {
                    $registerId = $registers[$cashAccount] ??= $this->ensureRegister($ctx, $cashAccount);
                    $cashId = $this->insertCash($ctx, $docKey, $rows, $cashAccount, $registerId, $domesticCash ? $vat['domestic'] : []);
                } else {
                    $p->count(self::STEP, 'cash_existing');
                }
                if ($cashId !== null) {
                    $ctx->cashDocuments[$docKey] = $cashId;
                }
            }
            $journalVat = $domesticCash ? [] : array_merge($vat['domestic'], $vat['other']);
            if ($journalVat === []) {
                if ($vat['review'] !== []) {
                    $p->warn(self::STEP, 'vat_code_review', sprintf('Doklad %s %s z %s: %s. Doklad zůstává jen v deníku, DPH doplňte ručně.',
                        $first['series'], $first['number'], $first['date'], implode('; ', $vat['review'])), ['document_no' => $first['series'] . ' ' . $first['number']]);
                    $p->count(self::STEP, 'review');
                }
                continue;
            }
            $this->importVatDocument($ctx, $docKey, $rows, $journalVat, $vat['review']);
        }
        $p->finish(self::STEP);
    }

    /**
     * Řádky dokladu po kódech DPH: základ a daň. `domestic` = tuzemské kódy (pokladna je
     * unese), `other` = samovyměření a ostatní zařazené kódy, `review` = kódy, které převod
     * nezařadí.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{domestic:list<array<string,mixed>>,other:list<array<string,mixed>>,review:list<string>}
     */
    private function vatBuckets(PremierContext $ctx, array $rows, ?string $cashAccount): array
    {
        $buckets = [];
        $review = [];
        $settle = $cashAccount ?? self::settlementAccount($rows);
        foreach ($rows as $r) {
            $code = $r['vat_code'];
            if ($code === '' || $ctx->vat->isPurchase($code) === null) {
                if ($code !== '' && !$ctx->vat->known($code)) {
                    $review[] = "kód DPH „{$code}“ v číselníku PREMIER chybí";
                }
                continue;
            }
            $purchase = (bool) $ctx->vat->isPurchase($code);
            $md343 = str_starts_with($r['md'], '343');
            $dal343 = str_starts_with($r['dal'], '343');
            if ($md343 && $dal343) {
                continue; // samovyměření zaúčtované MD 343 / D 343 - daň dopočte evidence z kódu
            }
            $isVat = $r['line_kind'] === 'D' || $md343 || $dal343;
            // Přijaté plnění se vyrovnává na straně Dal (pokladna, banka, závazek),
            // uskutečněné na straně MD; řádek na opačné straně doklad snižuje.
            $side = $purchase ? 'dal' : 'md';
            if ($settle !== null && ($r['md'] === $settle || $r['dal'] === $settle)) {
                $sign = $r[$side] === $settle ? 1 : -1;
            } else {
                $sign = $purchase
                    ? (preg_match('/^(0|1|343|5)/', $r['md']) === 1 ? 1 : -1)
                    : (preg_match('/^(343|6)/', $r['dal']) === 1 ? 1 : -1);
            }
            $value = round($sign * $r['amount'], 2);
            $buckets[$code] ??= ['code' => $code, 'purchase' => $purchase, 'base' => 0.0, 'vat' => 0.0, 'rate' => 0.0, 'date' => $r['tax_date'] ?? $r['date']];
            if ($isVat) {
                $buckets[$code]['vat'] += $value;
            } else {
                $buckets[$code]['base'] += $value;
                if (abs($r['vat_amount']) >= 0.005 && !self::hasVatRow($rows, $code)) {
                    // Daň jen v poli řádku základu (bez samostatného řádku D).
                    $buckets[$code]['vat'] += $sign * $r['vat_amount'];
                }
            }
            if ($r['vat_rate'] > 0) {
                $buckets[$code]['rate'] = $r['vat_rate'];
            }
        }
        $out = ['domestic' => [], 'other' => [], 'review' => $review];
        foreach ($buckets as $code => $b) {
            $b['base'] = round($b['base'], 2);
            $b['vat'] = round($b['vat'], 2);
            if (abs($b['base']) < 0.005 && abs($b['vat']) < 0.005) {
                continue;
            }
            $class = $b['purchase'] ? $ctx->vat->purchase((string) $code) : $ctx->vat->sale((string) $code);
            if ($class === null) {
                $out['review'][] = "kód DPH „{$code}“ ({$ctx->vat->name((string) $code)}) převod nezařazuje";
                continue;
            }
            if (!$class['in_return']) {
                if (abs($b['vat']) >= 0.005 && $b['purchase']) {
                    $out['other'][] = $b; // daň bez nároku na odpočet
                }
                continue;
            }
            $domestic = $b['purchase'] ? (!$class['reverse'] && $class['code'] === null) : ($class['code'] === null && !$class['asset_sale']);
            $out[$domestic ? 'domestic' : 'other'][] = $b;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $vat tuzemské řádky DPH (prázdné = pokladní doklad bez DPH)
     */
    private function insertCash(PremierContext $ctx, string $docKey, array $rows, string $cashAccount, int $registerId, array $vat): ?int
    {
        $p = $ctx->protocol;
        $movement = 0.0;
        $transfer = true;
        foreach ($rows as $r) {
            $m = PremierJournal::movement($r, $cashAccount);
            $movement += $m;
            $other = $r['md'] === $cashAccount ? $r['dal'] : $r['md'];
            if (abs($m) >= 0.005 && !preg_match('/^(21|22|26)/', $other)) {
                $transfer = false;
            }
        }
        $movement = round($movement, 2);
        if (abs($movement) < 0.005) {
            $p->count(self::STEP, 'cash_zero');
            return null;
        }
        $first = $rows[0];
        $isOut = $movement < 0;
        $pdo = $this->db->pdo();
        $number = mb_substr($first['series'] . $first['number'] . '/' . $ctx->year, 0, 30);
        $taken = $pdo->prepare('SELECT 1 FROM cash_documents WHERE supplier_id = ? AND doc_number = ? LIMIT 1');
        $taken->execute([$ctx->supplierId, $number]);
        if ($taken->fetchColumn() !== false) {
            $number = mb_substr($first['series'] . $first['number'] . '/' . $first['date'], 0, 30);
        }
        $partnerDic = $first['partner_dic'] !== '' ? mb_substr($first['partner_dic'], 0, 20) : null;
        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, partner_ic, partner_dic, description, vat_mode, total_amount, currency_code, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "CZK", "posted", ?)'
        )->execute([
            $ctx->supplierId,
            $registerId,
            $isOut ? 'out' : 'in',
            $transfer ? 'transfer' : ($vat !== [] ? ($isOut ? 'purchase' : 'sale') : 'other'),
            $number,
            $first['date'],
            $first['tax_date'] ?? $first['date'],
            $first['partner_name'] !== '' ? mb_substr($first['partner_name'], 0, 255) : null,
            $first['partner_ico'] !== '' ? mb_substr($first['partner_ico'], 0, 12) : null,
            $partnerDic,
            mb_substr(($first['text'] !== '' ? $first['text'] : $first['series'] . ' ' . $first['number']), 0, 255),
            $vat === [] ? 'none' : 'vat',
            abs($movement),
            $ctx->userOrNull(),
        ]);
        $id = (int) $pdo->lastInsertId();
        $line = $pdo->prepare('INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount, vat_deduction, vat_classification_code, is_fixed_asset)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($vat as $b) {
            $rate = $this->rate($ctx, $b);
            $reduced = $rate > 0.0 && $rate < 19.0;
            $class = $b['purchase'] ? $ctx->vat->purchase($b['code']) : null;
            $line->execute([
                $id,
                number_format($rate, 2, '.', ''),
                number_format($b['base'], 2, '.', ''),
                number_format($b['vat'], 2, '.', ''),
                $class !== null ? $class['deduction'] : 'full',
                $b['purchase'] ? ($reduced ? '41' : '40') : ($reduced ? '2' : '1'),
                $class !== null && $class['fixed_asset'] ? 1 : 0,
            ]);
        }
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CASH_DOCUMENT, 'cash|' . $ctx->year . '|' . $docKey, $id, $ctx->runId);
        $p->count(self::STEP, 'cash_documents');
        if ($vat !== []) {
            $p->count(self::STEP, 'cash_with_vat');
        }
        return $id;
    }

    /**
     * Doklad s DPH z deníku jako přijatý / vydaný doklad s položkami po kódech.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $buckets
     * @param list<string> $review
     */
    private function importVatDocument(PremierContext $ctx, string $docKey, array $rows, array $buckets, array $review): void
    {
        $first = $rows[0];
        $purchase = $buckets[0]['purchase'];
        foreach ($buckets as $b) {
            if ($b['purchase'] !== $purchase) {
                $review[] = 'doklad kombinuje kódy přijatého i uskutečněného plnění';
            }
        }
        $items = [];
        foreach ($buckets as $b) {
            $items[] = [
                'description' => $first['text'] !== '' ? $first['text'] : ($first['series'] . ' ' . $first['number']),
                'quantity' => 1.0, 'unit' => null, 'unit_price' => $b['base'],
                'base' => $b['base'], 'vat' => $b['vat'], 'rate' => $this->rate($ctx, $b), 'code' => $b['code'],
            ];
        }
        $base = round(array_sum(array_column($items, 'base')), 2);
        $vat = round(array_sum(array_column($items, 'vat')), 2);
        $date = $first['date'];
        $label = $first['series'] . ' ' . $first['number'] . ' (' . $date . ')';
        $header = [
            'CISLO_ODB' => $first['partner_no'], 'NAZEV_ODB' => $first['partner_name'] !== '' ? $first['partner_name'] : ('Doklad ' . $first['series'] . ' z deníku PREMIER'),
            'ICO_ODB' => $first['partner_ico'], 'DIC_ODB' => $first['partner_dic'], 'ULICE_ODB' => $first['partner_street'],
            'MESTO_ODB' => $first['partner_city'], 'PSC_ODB' => $first['partner_zip'], 'STAT_ODB' => $first['partner_country'], 'ID_PAR' => $first['partner_id'],
        ];
        $doc = [
            'direction' => $purchase ? PremierDocuments::PURCHASE : PremierDocuments::ISSUED,
            'inter' => $first['inter'], 'series' => $first['series'], 'number' => $first['number'], 'kind' => 'invoice',
            'issue' => $date, 'supply' => $first['tax_date'] ?? $date, 'accounting' => $date, 'year' => $ctx->year, 'due' => $date,
            'vat_date' => $first['tax_date'], 'kh_date' => null, 'currency' => 'CZK', 'factor' => 1.0,
            'text' => $first['text'], 'note' => '', 'variable_symbol' => $first['variable_symbol'], 'vendor_number' => $first['series'] . ' ' . $first['number'],
            'constant_symbol' => '', 'account_no' => '', 'payment_form' => self::cashAccount($rows) !== null ? 'hotově' : 'převod', 'storno' => false,
            'booked' => true, 'header' => $header, 'rows' => $rows, 'reasons' => array_values(array_unique($review)), 'previous' => false,
            'items' => $items, 'items_source' => 'journal', 'rounding' => 0.0, 'base' => $base, 'vat' => $vat, 'total' => round($base + $vat, 2),
            'paid' => round($base + $vat, 2), 'paid_at' => $date, 'settled' => true, 'payment_rows' => [], 'partner_account' => null,
            'map_key' => 'journal|' . $ctx->year . '|' . $docKey, 'label' => $label,
            'forced_type' => $purchase ? ($base + $vat < 0 ? 'credit_note' : 'receipt') : ($base + $vat < 0 ? 'credit_note' : 'invoice'),
            'numbers' => [$first['series'] . $first['number'] . '/' . $ctx->year, $first['series'] . 'I' . $first['inter']],
        ];
        $id = $this->invoices->importJournalDocument($ctx, $doc, self::STEP);
        if ($id !== null) {
            $ctx->vatDocuments[$docKey] = ['table' => $purchase ? 'purchase_invoice' : 'invoice', 'id' => $id];
        }
    }

    /** @param array<string,mixed> $b */
    private function rate(PremierContext $ctx, array $b): float
    {
        if (abs($b['base']) >= 1.0 && abs($b['vat']) >= 0.005) {
            $ratio = 100 * $b['vat'] / $b['base'];
            foreach ([21.0, 15.0, 12.0, 10.0, 20.0, 14.0, 19.0, 9.0, 5.0] as $known) {
                if (abs($ratio - $known) <= 0.6) {
                    return $known;
                }
            }
        }
        if ($b['rate'] > 0) {
            return (float) $b['rate'];
        }
        $other = $ctx->vat->otherRate($b['code']);
        if ($other > 0) {
            return $other;
        }
        $class = $ctx->vat->rateClass($b['code']);
        return $class !== null && $ctx->vat->lines($b['code']) !== [] ? PremierVat::rateFor($class, $b['date']) : 0.0;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function hasVatRow(array $rows, string $code): bool
    {
        foreach ($rows as $r) {
            if ($r['vat_code'] === $code && ($r['line_kind'] === 'D' || str_starts_with($r['md'], '343') || str_starts_with($r['dal'], '343'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Účet, přes který se doklad vyrovnává (banka, pokladna, závazek, pohledávka): nejčastější
     * účet tříd 2 a 3 kromě DPH.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function settlementAccount(array $rows): ?string
    {
        $votes = [];
        foreach ($rows as $r) {
            foreach ([$r['md'], $r['dal']] as $code) {
                if (preg_match('/^[23]/', $code) === 1 && !str_starts_with($code, '343')) {
                    $votes[$code] = ($votes[$code] ?? 0) + 1;
                }
            }
        }
        if ($votes === []) {
            return null;
        }
        arsort($votes);
        return (string) array_key_first($votes);
    }

    /**
     * Účet pokladny dokladu (211…), na který účtuje; `null` = doklad bez pohybu hotovosti.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function cashAccount(array $rows): ?string
    {
        foreach ($rows as $r) {
            foreach ([$r['md'], $r['dal']] as $code) {
                if (str_starts_with($code, '211')) {
                    return $code;
                }
            }
        }
        return null;
    }

    private function ensureRegister(PremierContext $ctx, string $premierAccount): int
    {
        $account = AccountCode::fromMoney($premierAccount) ?? '211';
        $key = 'register|' . $account;
        $mapped = $this->map->get($ctx->supplierId, PremierImportRepository::KIND_CASH_REGISTER, $key);
        if ($mapped !== null) {
            return $mapped;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND account_code = ? LIMIT 1');
        $stmt->execute([$ctx->supplierId, $account]);
        $found = $stmt->fetchColumn();
        if ($found !== false) {
            $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CASH_REGISTER, $key, (int) $found, $ctx->runId);
            return (int) $found;
        }
        $name = 'Pokladna ' . $account;
        $pdo->prepare('INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, ?, ?, "CZK", 1)')
            ->execute([$ctx->supplierId, $name, $account]);
        $id = (int) $pdo->lastInsertId();
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CASH_REGISTER, $key, $id, $ctx->runId);
        $ctx->protocol->count(self::STEP, 'registers');
        return $id;
    }
}
