<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Přijaté (`PFaktury`) a vydané (`VFaktury`) faktury.
 *
 * Money drží základ daně po sazbách (`Zaklad_0` mimo DPH, `Zaklad_1` … `Zaklad_6`),
 * sazby na dokladu (`SazbaDPH1` …) a daň (`DPH_1` …), takže doklad ze staršího roku
 * přijde se sazbou, kterou opravdu měl. Z každé sazby vznikne jedna položka. Ceny jsou
 * vždy bez DPH (`prices_include_vat = 0`) — kdyby se převzaly jako brutto, přepočítaly
 * by se znovu.
 *
 * Doklad zaúčtovaný deníkem z Money ({@see DocumentLinker}) má stav „zaúčtováno" nebo
 * „uhrazeno". **Doklad, jehož daňovou povahu z Money spolehlivě neznáme, se převezme
 * jako koncept k ruční kontrole** ({@see classify()}): zálohové a jiné než běžné
 * faktury, dobropisy, stornované a neúčtované doklady, cizí měna a členění DPH mimo
 * tuzemské řádky přiznání. Koncept do DPH evidence ani do účtování nevstoupí, dokud ho
 * účetní neopraví a nepotvrdí — hádat by znamenalo zálohu vedle konečné faktury
 * započíst do DPH dvakrát nebo přenesenou daňovou povinnost vykázat jako tuzemské plnění.
 */
final class InvoiceImporter
{
    public const STEP_PURCHASE = 'purchase_invoices';
    public const STEP_ISSUED = 'issued_invoices';

    /** Rozdíl do 1 Kč mezi součtem sazeb a celkem z Money je zaokrouhlení dokladu. */
    private const ROUNDING_LIMIT = 1.0;

    /** Sazby dokladu Money: `Zaklad_n` + `SazbaDPHn` + `DPH_n`. */
    private const RATE_SLOTS = 6;

    /** Druhy zálohové faktury v Money (`L`, starší `Z`, u vydaných `F`) — nejsou daňovým dokladem. */
    private const ADVANCE_KINDS = ['L', 'Z', 'F'];

    /** @var array<string,int> */
    private array $rateCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly CodebookImporter $codebooks,
        private readonly StatsRecomputer $stats,
    ) {}

    public function importPurchases(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $currencyId = $this->currencyId($ctx->supplierId);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE);
        $insert = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number,
                 document_kind, issue_date, tax_date, due_date, received_at, received_at_source, currency_id,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, rounding,
                 payment_variable_symbol, payment_method, status, paid_at, booked_at, booked_by,
                 note_above_items, note_below_items, external_barcode, vat_deduction, vat_classification_code,
                 prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $claimShifts = $this->claimShifts($ctx);
        $selfAssessed = $this->selfAssessments($ctx);
        $usedSelfAssessments = [];
        $duplicate = $pdo->prepare(
            'SELECT 1 FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1'
        );

        foreach ($ctx->backup->rowsAcrossYears('PFaktury') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $key = $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->purchaseInvoices[$key] = $existing[$key];
                $p->count(self::STEP_PURCHASE, 'existing');
                $this->reportChangedInMoney($ctx, self::STEP_PURCHASE, 'purchase_invoices', $existing[$key], $docNo, $year, $r);
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_PURCHASE, 'year_locked', "Faktura {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $issue = self::date($r, ['Vystaveno', 'DatUcPr']);
            if ($issue === null) {
                $p->warn(self::STEP_PURCHASE, 'missing_date', "Faktura {$docNo} nemá datum vystavení, nepřevzata.");
                continue;
            }
            $number = $this->freeNumber('purchase_invoices', $ctx->supplierId, $docNo, $year);
            if ($number === null) {
                $p->error(self::STEP_PURCHASE, 'number_taken', "Číslo dokladu {$docNo} ({$year}) už ve firmě má jiný doklad, faktura nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $snapshot = [
                'name' => trim((string) ($r['D_Nazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['D_ICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['D_DIC'] ?? '')))),
                'street' => trim((string) ($r['D_Ulice'] ?? '')),
                'city' => trim((string) ($r['D_Mesto'] ?? '')),
                'zip' => trim((string) ($r['D_Psc'] ?? '')),
                'country' => trim((string) ($r['D_Stat'] ?? '')),
            ];
            $vendorId = $this->codebooks->resolvePartner($ctx, $snapshot);
            $amounts = $this->amounts($ctx, self::STEP_PURCHASE, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            $class = self::classify($r, false, $amounts['vat']);
            $review = $class['reasons'] !== [];
            if ($review && self::historicalUnposted($ctx, $year, 'FP', $docNo)) {
                $p->count(self::STEP_PURCHASE, 'unposted_review_skipped');
                continue;
            }
            $taxDate = self::date($r, ['PlnenoDPH']) ?? $issue;
            $selfAssessment = $review ? null : self::pickSelfAssessment($selfAssessed, $docNo, $year);
            if ($selfAssessment !== null) {
                $usedSelfAssessments[$selfAssessment['key']] = true;
                if ($selfAssessment['error'] !== null) {
                    $p->warn(self::STEP_PURCHASE, 'self_assessment_review', "Faktura {$docNo} ({$year}): samovyměření z interního dokladu {$selfAssessment['doc']} převod nezařadí ({$selfAssessment['error']}), DPH doplňte ručně.", ['document_no' => $docNo, 'year' => $year]);
                } else {
                    // Samovyměření se vykazuje ke dni z interního dokladu (datum uplatnění DPH).
                    $taxDate = $selfAssessment['date'] ?? $taxDate;
                    [$amounts, $class] = $this->applySelfAssessment($selfAssessment, $amounts, $class, $taxDate);
                    $p->count(self::STEP_PURCHASE, 'self_assessed');
                }
            }
            $paidAt = self::date($r, ['Uhrazeno']);
            // Zálohovou fakturu Money neúčtuje — v MyÚčtu je přijatá, ne zaúčtovaná.
            $unbooked = $review || $class['kind'] === 'advance';
            // Odpočet, který Money přesunulo do pozdějšího období (§ 73), se uplatní ke dni
            // z Money — stejně jako ručně zadané datum přijetí dokladu.
            $claimDate = $claimShifts[$key] ?? null;
            $vendorNumber = mb_substr(trim((string) ($r['PrijatDokl'] ?? '')) ?: (trim((string) ($r['VarSymbol'] ?? '')) ?: $docNo), 0, 50);
            $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $issue]);
            if ($duplicate->fetchColumn() !== false) {
                $vendorNumber = mb_substr($vendorNumber . ' (' . $docNo . ')', 0, 50);
            }
            try {
                $insert->execute([
                    $ctx->supplierId,
                    $vendorId,
                    $snapshot['dic'] !== '' ? 1 : 0,
                    $number['number'],
                    $vendorNumber,
                    $class['kind'],
                    $issue,
                    $taxDate,
                    self::date($r, ['Splatno']) ?? $issue,
                    $claimDate ?? (self::date($r, ['Doruceno', 'DatUcPr']) ?? $issue),
                    $claimDate !== null ? 'manual' : 'import',
                    $currencyId,
                    json_encode([
                        'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
                        'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
                    ], JSON_UNESCAPED_UNICODE),
                    $amounts['base'],
                    $amounts['vat'],
                    $amounts['total'],
                    $amounts['rounding'],
                    mb_substr(trim((string) ($r['VarSymbol'] ?? '')), 0, 20) ?: null,
                    self::paymentMethod((string) ($r['Uhrada'] ?? '')),
                    $review ? 'draft' : ($paidAt !== null ? 'paid' : ($unbooked ? 'received' : 'booked')),
                    $paidAt,
                    $unbooked ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                    $unbooked || $ctx->userId <= 0 ? null : $ctx->userId,
                    mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    self::note($docNo, $class['reasons']),
                    // Čárový kód z Money je jistý klíč pro párování naskenovaných příloh.
                    mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                    $class['vat_deduction'],
                    $class['code'],
                    $ctx->userId,
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $p->error(self::STEP_PURCHASE, 'insert_conflict', "Faktura {$docNo} ({$year}) koliduje s existujícím dokladem firmy, nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $id = (int) $pdo->lastInsertId();
            foreach ($amounts['items'] as $i => $item) {
                $insertItem->execute([
                    $id,
                    trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
                    $item['base'], $item['rate_id'], $item['rate'],
                    $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                    $item['code'] ?? $class['code'],
                ]);
            }
            if ($claimDate !== null) {
                $p->count(self::STEP_PURCHASE, 'claim_shifted');
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
            $ctx->purchaseInvoices[$key] = $id;
            $p->count(self::STEP_PURCHASE, 'created');
            $this->reportNumberAndReview($ctx, self::STEP_PURCHASE, $docNo, $year, $number, $class['reasons']);
        }
        $unlinked = [];
        foreach ($selfAssessed as $byYear) {
            foreach ($byYear as $sa) {
                if (!isset($usedSelfAssessments[$sa['key']]) && count($unlinked) < ImportProtocol::LIST_LIMIT) {
                    $unlinked[] = $sa['doc'];
                }
            }
        }
        if ($unlinked !== []) {
            $p->warn(self::STEP_PURCHASE, 'self_assessment_unlinked', count($unlinked) . ' interních dokladů se samovyměřením DPH nejde přiřadit k převedené faktuře ('
                . implode(', ', array_slice($unlinked, 0, 20)) . '). Jejich DPH doplňte ručně.', ['documents' => $unlinked]);
        }
        $p->finish(self::STEP_PURCHASE);
    }

    public function importIssued(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $currencyId = $this->currencyId($ctx->supplierId);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE);
        $insert = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date,
                 currency_id, note_above_items, note_below_items, client_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, paid_total,
                 paid_at, status, booked_at, booked_by, vat_classification_code, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        // Převedená faktura není OSS: Money S3 v záloze místo plnění pro OSS nedrží,
        // a kdyby šlo o OSS, podané přiznání za ten rok už je v Money.
        $insertItem = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, oss_applicable, vat_classification_code)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );

        $clients = [];
        foreach ($ctx->backup->rowsAcrossYears('VFaktury') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $key = $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->issuedInvoices[$key] = $existing[$key];
                $p->count(self::STEP_ISSUED, 'existing');
                $this->reportChangedInMoney($ctx, self::STEP_ISSUED, 'invoices', $existing[$key], $docNo, $year, $r);
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_ISSUED, 'year_locked', "Faktura {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $issue = self::date($r, ['Vystaveno', 'DatUcPr']);
            if ($issue === null) {
                $p->warn(self::STEP_ISSUED, 'missing_date', "Faktura {$docNo} nemá datum vystavení, nepřevzata.");
                continue;
            }
            $number = $this->freeNumber('invoices', $ctx->supplierId, $docNo, $year);
            if ($number === null) {
                $p->error(self::STEP_ISSUED, 'number_taken', "Číslo faktury {$docNo} ({$year}) už ve firmě má jiný doklad, faktura nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $snapshot = [
                'name' => trim((string) ($r['O_Nazev'] ?? $r['AdNazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['O_ICO'] ?? $r['AdICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['O_DIC'] ?? $r['AdDIC'] ?? '')))),
                'street' => trim((string) ($r['O_Ulice'] ?? $r['AdUlice'] ?? '')),
                'city' => trim((string) ($r['O_Mesto'] ?? $r['AdMesto'] ?? '')),
                'zip' => trim((string) ($r['O_Psc'] ?? $r['AdPSC'] ?? '')),
                'country' => trim((string) ($r['O_Stat'] ?? $r['AdStat'] ?? '')),
            ];
            $clientId = $this->codebooks->resolvePartner($ctx, $snapshot);
            $amounts = $this->amounts($ctx, self::STEP_ISSUED, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            $class = self::classify($r, true, $amounts['vat']);
            $review = $class['reasons'] !== [];
            if ($review && self::historicalUnposted($ctx, $year, 'FV', $docNo)) {
                $p->count(self::STEP_ISSUED, 'unposted_review_skipped');
                continue;
            }
            // Zálohovou fakturu Money neúčtuje — v MyÚčtu je vystavená, ne zaúčtovaná.
            $unbooked = $review || $class['kind'] === 'proforma';
            $paidAt = self::date($r, ['Uhrazeno']);
            try {
                $insert->execute([
                    $ctx->supplierId,
                    $class['kind'],
                    $clientId,
                    $number['number'],
                    $issue,
                    self::date($r, ['PlnenoDPH']) ?? $issue,
                    self::date($r, ['Splatno']) ?? $issue,
                    $currencyId,
                    mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    self::note($docNo, $class['reasons']),
                    json_encode([
                        'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
                        'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
                    ], JSON_UNESCAPED_UNICODE),
                    $amounts['base'],
                    $amounts['vat'],
                    $amounts['total'],
                    $amounts['rounding'],
                    $paidAt !== null ? $amounts['total'] : 0,
                    $paidAt,
                    $review ? 'draft' : ($paidAt !== null ? 'paid' : 'sent'),
                    $unbooked ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                    $unbooked || $ctx->userId <= 0 ? null : $ctx->userId,
                    $class['code'],
                    $ctx->userId > 0 ? $ctx->userId : null,
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $p->error(self::STEP_ISSUED, 'insert_conflict', "Faktura {$docNo} ({$year}) koliduje s existujícím dokladem firmy, nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $clients[$clientId] = $clientId;
            $id = (int) $pdo->lastInsertId();
            foreach ($amounts['items'] as $i => $item) {
                $insertItem->execute([
                    $id,
                    trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
                    $item['base'], $item['rate_id'], $item['rate'],
                    $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                    $class['code'],
                ]);
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
            $ctx->issuedInvoices[$key] = $id;
            $p->count(self::STEP_ISSUED, 'created');
            $this->reportNumberAndReview($ctx, self::STEP_ISSUED, $docNo, $year, $number, $class['reasons']);
        }
        $this->importOtherReceivables($ctx, $insert, $insertItem, $currencyId, $clients);
        $ctx->statsClients = array_values(array_unique(array_merge($ctx->statsClients, array_values($clients))));
        $p->finish(self::STEP_ISSUED);
    }

    /**
     * Ostatní pohledávky Money (`KnihPohl`, v deníku zdroj KP) v přiznání — věcná břemena,
     * pachty, osvobozená plnění ř. 50 — jsou plněním jako vydaná faktura. Bez nich by
     * v přiznání chyběla daň na výstupu i hodnota osvobozených plnění pro koeficient § 76.
     * Převedou se jako vydané doklady (záporná = opravný doklad) a zápis KP se naváže
     * ({@see DocumentLinker}). Pohledávky mimo přiznání (19Ř00U) zůstanou jen v deníku.
     *
     * @param array<int,int> $clients
     */
    private function importOtherReceivables(ImportContext $ctx, \PDOStatement $insert, \PDOStatement $insertItem, int $currencyId, array &$clients): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE);
        foreach ($ctx->backup->rowsAcrossYears('KnihPohl') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $resolved = Ms3VatCode::resolve((string) ($r['Cleneni'] ?? ''), true);
            if ($year === null || $docNo === '' || (int) ($r['Storno'] ?? 0) === 1 || $resolved === null || !$resolved['in_return']) {
                continue;
            }
            $key = 'KP|' . $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->otherReceivables[$year . '|' . $docNo] = $existing[$key];
                $p->count(self::STEP_ISSUED, 'existing');
                continue;
            }
            $issue = self::date($r, ['DatVyst', 'DatUcPr']);
            if ($ctx->isLocked($year) || $issue === null) {
                continue;
            }
            $number = $this->freeNumber('invoices', $ctx->supplierId, $docNo, $year);
            if ($number === null) {
                $p->error(self::STEP_ISSUED, 'number_taken', "Číslo pohledávky {$docNo} ({$year}) už ve firmě má jiný doklad, pohledávka nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $taxDate = self::date($r, ['DatPln', 'DatUcPr']) ?? $issue;
            // Částky pohledávky nesou znaménko z Money (opravná pohledávka je záporná).
            $items = [];
            foreach (CashBankImporter::vatLines($r, false) as $line) {
                $items[] = ['base' => $line['base'], 'vat' => $line['vat'], 'rate' => $line['rate']];
            }
            $zero = round((float) ($r['Zakl0'] ?? 0), 2);
            if ($zero !== 0.0) {
                $items[] = ['base' => $zero, 'vat' => 0.0, 'rate' => 0.0];
            }
            $total = round((float) ($r['Celkem'] ?? 0), 2);
            if ($items === [] && $total !== 0.0) {
                $items[] = ['base' => $total, 'vat' => 0.0, 'rate' => 0.0];
            }
            if ($items === []) {
                continue;
            }
            $base = round(array_sum(array_column($items, 'base')), 2);
            $vat = round(array_sum(array_column($items, 'vat')), 2);
            $total = $total !== 0.0 ? $total : round($base + $vat, 2);
            $snapshot = [
                'name' => trim((string) ($r['AdNazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['AdICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['AdDIC'] ?? '')))),
                'street' => trim((string) ($r['AdUlice'] ?? '')),
                'city' => trim((string) ($r['AdMesto'] ?? '')),
                'zip' => trim((string) ($r['AdPSC'] ?? '')),
                'country' => trim((string) ($r['AdStat'] ?? '')),
            ];
            $clientId = $this->codebooks->resolvePartner($ctx, $snapshot);
            $paidAt = self::date($r, ['UhDatum']);
            $insert->execute([
                $ctx->supplierId,
                $total < 0 ? 'credit_note' : 'invoice',
                $clientId,
                $number['number'],
                $issue,
                $taxDate,
                self::date($r, ['DatSpl']) ?? $issue,
                $currencyId,
                mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                'Převzato z Money S3, ostatní pohledávka ' . $docNo,
                json_encode([
                    'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
                    'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
                ], JSON_UNESCAPED_UNICODE),
                $base,
                $vat,
                $total,
                round($total - $base - $vat, 2),
                $paidAt !== null ? $total : 0,
                $paidAt,
                $paidAt !== null ? 'paid' : 'sent',
                (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                $ctx->userId > 0 ? $ctx->userId : null,
                $resolved['code'],
                $ctx->userId > 0 ? $ctx->userId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach ($items as $i => $item) {
                $insertItem->execute([
                    $id,
                    trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
                    $item['base'], $this->rateId($item['rate'], $taxDate), $item['rate'],
                    $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                    $resolved['code'],
                ]);
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
            $ctx->otherReceivables[$year . '|' . $docNo] = $id;
            $clients[$clientId] = $clientId;
            $p->count(self::STEP_ISSUED, 'other_receivables');
        }
    }

    /**
     * Seznam klientů (počet faktur, obrat) čte cache — převedené faktury by v něm bez
     * přepočtu chyběly. Přepočet si řídí vlastní transakce, proto běží až po zápisu
     * dokladů mimo transakci kroku; zkouška nanečisto ho vynechá (všechno se vrací).
     */
    public function recomputeClientStats(ImportContext $ctx): void
    {
        if ($ctx->statsClients === []) {
            return;
        }
        if ($this->db->pdo()->inTransaction()) {
            $ctx->protocol->info(self::STEP_ISSUED, 'stats_deferred', 'Statistiky klientů se přepočtou po dokončení transakce.');
            return;
        }
        $this->stats->recomputeMany($ctx->statsClients);
    }

    /**
     * Daňová povaha dokladu z Money. Vrací důvody, proč doklad NEJDE převzít bez ruční
     * kontroly (prázdné = jde), druh dokladu v MyÚčtu, nárok na odpočet a kód zařazení
     * do přiznání.
     *
     * Druh Money: `N` běžná faktura, `D` daňový doklad k přijaté platbě (Money ho účtuje
     * jen 343/314), `L`/`Z`/`F` zálohová faktura — Money ji neúčtuje a do přiznání nejde,
     * DPH nese až daňový doklad k platbě nebo konečná faktura. Dobropis (`Dobropis`) má
     * v Money záporné částky. Členění DPH (`KodDPH`) převádí {@see Ms3VatCode}; členění,
     * které převod nezná, a stornované či neúčtované doklady jdou k ruční kontrole.
     * Doklad v cizí měně má v Money základ i daň v Kč, převezme se tak.
     *
     * @param array<string,mixed> $r
     * @return array{reasons:list<string>,vat_deduction:string,code:?string,kind:string}
     */
    public static function classify(array $r, bool $issued, float $vat): array
    {
        $reasons = [];
        $deduction = 'full';
        $vatCode = null;
        $kind = 'invoice';
        $druh = strtoupper(trim((string) ($r['Druh'] ?? '')));
        $advance = in_array($druh, self::ADVANCE_KINDS, true);
        if ($druh === 'D') {
            $kind = 'tax_document';
        } elseif ($advance) {
            $kind = $issued ? 'proforma' : 'advance';
        } elseif ($druh !== '' && $druh !== 'N') {
            $reasons[] = "neznámý druh dokladu „{$druh}“";
        }
        if ((int) ($r['Dobropis'] ?? 0) === 1) {
            if ($kind === 'invoice') {
                $kind = 'credit_note';
            } else {
                $reasons[] = 'dobropis zálohy nebo daňového dokladu k platbě';
            }
        }
        if ((int) ($r['Storno'] ?? 0) === 1) {
            $reasons[] = 'stornovaný doklad';
        }
        if ((int) ($r['Neuctovat'] ?? 0) === 1) {
            $reasons[] = 'v Money označený „neúčtovat“';
        }
        if ($advance) {
            return ['reasons' => $reasons, 'vat_deduction' => $deduction, 'code' => null, 'kind' => $kind];
        }

        $code = trim((string) ($r['KodDPH'] ?? ''));
        $resolved = $code === '' ? null : Ms3VatCode::resolve($code, $issued);
        $hasVat = abs($vat) >= 0.005;
        if ($code === '') {
            if ($hasVat) {
                $reasons[] = 'doklad s daní bez členění DPH';
            }
        } elseif ($resolved === null) {
            $reasons[] = "členění DPH „{$code}“ (přenesená daňová povinnost na vstupu, pořízení z EU, dovoz, poměrný nárok nebo zvláštní režim)";
        } elseif (!$resolved['in_return']) {
            if ($hasVat) {
                if ($issued) {
                    $reasons[] = "členění DPH „{$code}“ mimo přiznání u dokladu s daní";
                } else {
                    // Přijatý doklad mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
                    $deduction = 'none';
                }
            }
        } elseif ($resolved['code'] !== null && $hasVat) {
            $reasons[] = "členění DPH „{$code}“ (plnění bez daně) u dokladu s daní";
        } else {
            $deduction = $resolved['deduction'];
            $vatCode = $resolved['code'];
        }
        return ['reasons' => $reasons, 'vat_deduction' => $deduction, 'code' => $vatCode, 'kind' => $kind];
    }

    /**
     * Doklad k ruční kontrole (záloha, proforma, nejisté DPH), který Money v historickém roce
     * vůbec nezaúčtovalo. Do uzavřeného roku nic nepřidá — v účetnictví ani v DPH není —
     * a jen by zaplevelil koncepty; převod ho přeskočí. V posledním (otevřeném) roce se
     * převezme, záloha tam ještě může být rozpracovaná.
     */
    private static function historicalUnposted(ImportContext $ctx, int $year, string $source, string $docNo): bool
    {
        if ($ctx->periods === [] || $year >= max(array_keys($ctx->periods))) {
            return false;
        }
        if ($ctx->journalDocuments === null) {
            $ctx->journalDocuments = [];
            foreach ($ctx->backup->rowsAcrossYears('UcDenik') as $row) {
                $y = $ctx->yearOf($row);
                if ($y !== null) {
                    $ctx->journalDocuments[$y . '|' . strtoupper(trim((string) ($row['Zdroj'] ?? ''))) . '|' . trim((string) ($row['Doklad'] ?? ''))] = true;
                }
            }
        }
        return !isset($ctx->journalDocuments[$year . '|' . $source . '|' . $docNo]);
    }

    /**
     * Doklady, jejichž odpočet Money přesunulo do pozdějšího období (§ 73 — doklad došel
     * až po podání přiznání za měsíc plnění). Money je vede v tabulce `UcPrvDPH` roku,
     * do kterého odpočet přešel, s datem uplatnění (`DatPln`); doklad sám patří do roku
     * data plnění (`DatumD`), bez něj do roku předchozího.
     *
     * @return array<string,string> "rok|číslo dokladu" => datum uplatnění odpočtu
     */
    private function claimShifts(ImportContext $ctx): array
    {
        $out = [];
        foreach ($ctx->backup->rowsAcrossYears('UcPrvDPH') as $r) {
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $claim = self::date($r, ['DatPln']);
            $year = $ctx->yearOf($r);
            if ($docNo === '' || $claim === null || $year === null) {
                continue;
            }
            $docDate = self::date($r, ['DatumD']);
            $out[($docDate !== null ? (int) substr($docDate, 0, 4) : $year - 1) . '|' . $docNo] = $claim;
        }
        return $out;
    }

    /**
     * Samovyměření DPH, které Money vede interním dokladem (`IntDokl`, řádky `PolUcDID`)
     * k faktuře v přenesené povinnosti — pořízení z EU, služby ze zahraničí, dovoz,
     * tuzemský přenos. Faktura sama má členění mimo přiznání; výstup (ř. 3–13) a zrcadlový
     * odpočet (ř. 43/44) nese interní doklad. Faktura se pozná z popisu („RCH k PFZ…").
     *
     * @return array<string,array<int,array{key:string,doc:string,date:?string,lines:list<array{base:float,rate:float,code:string}>,deduction:string,error:?string}>>
     *   číslo faktury (''= nepoznaná) => rok interního dokladu => samovyměření
     */
    private function selfAssessments(ImportContext $ctx): array
    {
        $lines = [];
        foreach ($ctx->backup->rowsAcrossYears('PolUcDID') as $l) {
            $lines[$l['__dir'] . '|' . (int) ($l['CISLO'] ?? 0)][] = $l;
        }
        $out = [];
        if ($lines === []) {
            return $out;
        }
        foreach ($ctx->backup->rowsAcrossYears('IntDokl') as $h) {
            $year = $ctx->yearOf($h);
            $docNo = trim((string) ($h['Doklad'] ?? ''));
            $docLines = $lines[$h['__dir'] . '|' . (int) ($h['Cislo'] ?? 0)] ?? [];
            if ($year === null || $docNo === '' || $docLines === []) {
                continue;
            }
            $mirror = null;
            $output = [];
            foreach ($docLines as $l) {
                $code = trim((string) ($l['Cleneni'] ?? ''));
                if (Ms3VatCode::isReverseChargeOutput($code)) {
                    $output[] = $l;
                } elseif (preg_match('/^\d{2}Ř\s*4[234]/u', $code) === 1) {
                    $mirror ??= $code;
                }
            }
            if ($output === []) {
                continue;
            }
            $entry = ['key' => $year . '|' . $docNo, 'doc' => $docNo, 'date' => self::date($h, ['DatUplDPH', 'DatPln', 'DatUcPr']),
                'lines' => [], 'deduction' => 'full', 'error' => null];
            foreach ($output as $l) {
                $resolved = Ms3VatCode::reverseCharge((string) $l['Cleneni'], $mirror, (string) ($l['PredmPln'] ?? ''));
                if ($resolved === null) {
                    $entry['error'] = sprintf('členění „%s“ / „%s“', trim((string) $l['Cleneni']), (string) $mirror);
                    break;
                }
                $entry['lines'][] = [
                    'base' => round((float) ($l['Cena'] ?? 0) * (((float) ($l['PocetMJ'] ?? 0)) ?: 1.0), 2),
                    'rate' => (float) ($l['SazbaDPH'] ?? 0),
                    'code' => $resolved['code'],
                ];
                $entry['deduction'] = $resolved['deduction'];
            }
            // „RCH k PFZ190001" — první číslo dokladu v popisu (písmena + číslice).
            $ref = preg_match('/\b([A-Z]{1,5}\d{4,})\b/u', (string) ($h['Popis'] ?? ''), $m) === 1 ? $m[1] : '';
            $out[$ref][$year] = $entry;
        }
        return $out;
    }

    /**
     * Samovyměření k faktuře: interní doklad z roku faktury, z následujícího (faktura
     * z prosince samovyměřená v lednu) nebo z předchozího.
     *
     * @param array<string,array<int,array<string,mixed>>> $selfAssessed
     * @return array<string,mixed>|null
     */
    private static function pickSelfAssessment(array $selfAssessed, string $docNo, int $year): ?array
    {
        foreach ([$year, $year + 1, $year - 1] as $y) {
            if (isset($selfAssessed[$docNo][$y])) {
                return $selfAssessed[$docNo][$y];
            }
        }
        return null;
    }

    /**
     * Položky faktury se samovyměřením = řádky interního dokladu (základ, sazba a kód
     * zařazení podle výstupního řádku), nárok na odpočet podle zrcadlového řádku. Money
     * samovyměřuje kurzem ke dni plnění, takže základ se od částky faktury může lišit —
     * rozdíl zůstane jako položka bez DPH a bez kódu, aby doklad seděl na závazek.
     * Hlavička kód nenese: položka rozdílu by jinak zdědila kód přenesené povinnosti.
     *
     * @param array<string,mixed> $sa
     * @param array{items:list<array<string,mixed>>,base:float,vat:float,total:float,rounding:float} $amounts
     * @param array{reasons:list<string>,vat_deduction:string,code:?string,kind:string} $class
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function applySelfAssessment(array $sa, array $amounts, array $class, string $taxDate): array
    {
        $items = [];
        $base = 0.0;
        foreach ($sa['lines'] as $line) {
            $items[] = ['base' => $line['base'], 'rate' => $line['rate'], 'vat' => 0.0,
                'rate_id' => $this->rateId($line['rate'], $taxDate), 'code' => $line['code']];
            $base += $line['base'];
        }
        $diff = round($amounts['total'] - $base, 2);
        if (abs($diff) >= 0.01) {
            $items[] = ['base' => $diff, 'rate' => 0.0, 'vat' => 0.0, 'rate_id' => $this->rateId(0.0, $taxDate), 'code' => null];
        }
        $amounts['items'] = $items;
        $amounts['base'] = $amounts['total'];
        $amounts['vat'] = 0.0;
        $amounts['rounding'] = 0.0;
        $class['vat_deduction'] = $sa['deduction'];
        $class['code'] = null;
        return [$amounts, $class];
    }

    /** Způsob úhrady z Money (volný text `Uhrada`, viz {@see \MyInvoice\Service\Export\MoneyS3XmlExporter}). */
    public static function paymentMethod(string $label): string
    {
        $l = mb_strtolower(trim($label));
        return match (true) {
            $l === '' => 'bank_transfer',
            str_contains($l, 'kart') => 'card',
            str_contains($l, 'hotov') => 'cash',
            str_contains($l, 'dobír') || str_contains($l, 'dobir') => 'cash_on_delivery',
            str_contains($l, 'inkas') => 'direct_debit',
            str_contains($l, 'zápoč') || str_contains($l, 'zapoc') => 'offset',
            str_contains($l, 'převod') || str_contains($l, 'prevod') || str_contains($l, 'příkaz') || str_contains($l, 'prikaz') => 'bank_transfer',
            default => 'other',
        };
    }

    /**
     * Číslo dokladu, které ve firmě ještě není. Money čísluje řady každý rok od začátku,
     * takže FP001 z roku 2025 narazí na FP001 z roku 2024 — dostane příponu roku, stejně
     * jako pokladní doklady ({@see CashBankImporter}).
     *
     * @return array{number:string,suffixed:bool}|null
     */
    private function freeNumber(string $table, int $supplierId, string $docNo, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $suffix = '/' . $year;
        foreach ([[mb_substr($docNo, 0, 20), false], [mb_substr($docNo, 0, 20 - mb_strlen($suffix)) . $suffix, true]] as [$candidate, $suffixed]) {
            $stmt->execute([$supplierId, $candidate]);
            if ($stmt->fetchColumn() === false) {
                return ['number' => $candidate, 'suffixed' => $suffixed];
            }
        }
        return null;
    }

    /**
     * @param array{number:string,suffixed:bool} $number
     * @param list<string> $reasons
     */
    private function reportNumberAndReview(ImportContext $ctx, string $step, string $docNo, int $year, array $number, array $reasons): void
    {
        $p = $ctx->protocol;
        if ($number['suffixed']) {
            $p->count($step, 'suffixed');
            $p->info($step, 'number_suffixed', "Doklad {$docNo} ({$year}): číslo už ve firmě je, převzat jako {$number['number']}.", ['document_no' => $docNo, 'year' => $year]);
        }
        if ($reasons !== []) {
            $p->count($step, 'review');
            $p->warn($step, 'needs_review', "Doklad {$docNo} ({$year}) převzat jako koncept k ruční kontrole: " . implode('; ', $reasons)
                . '. Do DPH ani do účtování nevstoupí, dokud ho neopravíte a nepotvrdíte.', ['document_no' => $docNo, 'year' => $year, 'reasons' => $reasons]);
        }
    }

    /**
     * Opakovaný převod (novější zálohy) už převedený doklad NEPŘEPISUJE — mohl být mezitím
     * zaúčtovaný, spárovaný nebo upravený v MyÚčtu. Liší-li se ale v Money, protokol to
     * řekne, ať ho účetní upraví ručně.
     *
     * @param array<string,mixed> $r
     */
    private function reportChangedInMoney(ImportContext $ctx, string $step, string $table, int $id, string $docNo, int $year, array $r): void
    {
        if (!array_key_exists('CelkemSDPH', $r)) {
            return;
        }
        $stmt = $this->db->pdo()->prepare("SELECT total_with_vat FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        $money = round((float) $r['CelkemSDPH'], 2);
        if ($stored === false || abs((float) $stored - $money) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_money', sprintf(
            'Doklad %s (%d) se v Money od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $docNo, $year, number_format((float) $stored, 2, ',', ' '), number_format($money, 2, ',', ' ')
        ), ['document_no' => $docNo, 'year' => $year, 'id' => $id]);
    }

    /** @param list<string> $reasons */
    private static function note(string $docNo, array $reasons): string
    {
        $note = 'Převzato z Money S3, doklad ' . $docNo;
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . implode('; ', $reasons) . '.';
    }

    /**
     * Položky dokladu po sazbách a jeho součty. DPH se bere z Money (`DPH_1` …), když ho
     * doklad nese, jinak se dopočte ze základu. Rozdíl proti celku z Money do 1 Kč je
     * zaokrouhlení dokladu, větší rozdíl se ohlásí (rekonciliace dokladů proti deníku
     * ho pak ukáže i v součtu).
     *
     * @param array<string,mixed> $r
     * @return array{items:list<array{base:float,rate:float,vat:float,rate_id:int}>,base:float,vat:float,total:float,rounding:float}
     */
    private function amounts(ImportContext $ctx, string $step, string $docNo, array $r, string $taxDate): array
    {
        $slots = [['Zaklad_0', null, []]];
        for ($i = 1; $i <= self::RATE_SLOTS; $i++) {
            $slots[] = ['Zaklad_' . $i, 'SazbaDPH' . $i, ['DPH_' . $i, 'DPH' . $i]];
        }
        $items = [];
        foreach ($slots as [$baseField, $rateField, $vatFields]) {
            $base = round((float) ($r[$baseField] ?? 0), 2);
            if ($base === 0.0) {
                continue;
            }
            $rate = $rateField === null ? 0.0 : (float) ($r[$rateField] ?? 0);
            $vat = null;
            foreach ($vatFields as $vatField) {
                if (array_key_exists($vatField, $r)) {
                    $vat = round((float) $r[$vatField], 2);
                    break;
                }
            }
            $vat ??= round($base * $rate / 100, 2);
            $items[] = ['base' => $base, 'rate' => $rate, 'vat' => $vat, 'rate_id' => $this->rateId($rate, $taxDate)];
        }
        $sumBase = round(array_sum(array_column($items, 'base')), 2);
        $sumVat = round(array_sum(array_column($items, 'vat')), 2);
        $moneyTotal = array_key_exists('CelkemSDPH', $r) ? round((float) $r['CelkemSDPH'], 2) : null;

        if ($items === [] && $moneyTotal !== null && $moneyTotal !== 0.0) {
            $items[] = ['base' => $moneyTotal, 'rate' => 0.0, 'vat' => 0.0, 'rate_id' => $this->rateId(0.0, $taxDate)];
            $sumBase = $moneyTotal;
        }
        $total = round($sumBase + $sumVat, 2);
        $rounding = 0.0;
        if ($moneyTotal !== null && abs($moneyTotal - $total) >= 0.005) {
            $diff = round($moneyTotal - $total, 2);
            if (abs($diff) <= self::ROUNDING_LIMIT) {
                $rounding = $diff;
                $total = $moneyTotal;
            } else {
                $ctx->protocol->warn($step, 'total_mismatch', sprintf(
                    'Doklad %s: součet sazeb %s nesedí na celkem %s z Money.',
                    $docNo, number_format($total, 2, ',', ' '), number_format($moneyTotal, 2, ',', ' ')
                ), ['document_no' => $docNo]);
            }
        }
        return ['items' => $items, 'base' => $sumBase, 'vat' => $sumVat, 'total' => $total, 'rounding' => $rounding];
    }

    private function rateId(float $rate, string $date): int
    {
        $cacheKey = number_format($rate, 2, '.', '') . '|' . $date;
        if (isset($this->rateCache[$cacheKey])) {
            return $this->rateCache[$cacheKey];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND rate_percent = ? AND is_reverse_charge = 0
              ORDER BY ((valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)) DESC,
                       is_default DESC, id
              LIMIT 1"
        );
        $stmt->execute([number_format($rate, 2, '.', ''), $date, $date]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new MoneyS3Exception('unknown_vat_rate', 'Sazba DPH ' . $rate . ' % není v číselníku sazeb.');
        }
        return $this->rateCache[$cacheKey] = (int) $id;
    }

    private function currencyId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            $s = $this->db->pdo()->prepare('SELECT default_currency_id FROM supplier WHERE id = ?');
            $s->execute([$supplierId]);
            $id = (int) $s->fetchColumn();
        }
        return $id;
    }

    /**
     * První vyplněné datum z polí v pořadí.
     *
     * @param array<string,mixed> $r
     * @param list<string> $fields
     */
    public static function date(array $r, array $fields): ?string
    {
        foreach ($fields as $f) {
            $v = $r[$f] ?? null;
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                return $v;
            }
        }
        return null;
    }
}
