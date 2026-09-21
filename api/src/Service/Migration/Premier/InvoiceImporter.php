<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter as PohodaPartners;
use MyInvoice\Service\Stats\StatsRecomputer;

/**
 * Vydané a přijaté faktury z PREMIER ({@see PremierDocuments}).
 *
 * | PREMIER                               | MyÚčto                                      |
 * |---------------------------------------|---------------------------------------------|
 * | vydaná faktura (`FA_OUT`)             | `invoices` faktura, záporná = dobropis      |
 * | vydaný zálohový list (řada typu 11)   | `invoices` proforma (neúčtuje se)           |
 * | přijatá faktura (`FA_IN`)             | `purchase_invoices` faktura / dobropis      |
 * | přijatý zálohový list (řada typu 10)  | `purchase_invoices` záloha (neúčtuje se)    |
 *
 * **Zařazení DPH je po položkách** podle kódu DPH položky a jeho definice v číselníku
 * PREMIER ({@see PremierVat}). U samovyměření (pořízení z EU, služby ze zahraničí, dovoz,
 * tuzemský přenos) má položka daň 0 a kód zařazení - daň na výstupu i odpočet dopočte
 * evidence DPH ze základu a sazby, takže nezáleží na tom, jestli ji účetní v PREMIER
 * zaúčtovala na 343. Vydaná položka s kódem mimo přiznání, která nese daň, je režim OSS
 * a rozhodne o ní {@see OssMigrationPolicy} (táž autorita jako u ostatních kanálů).
 *
 * Doklad, jehož daňovou povahu převod spolehlivě nezná (kód jiných řádků přiznání, daň
 * u plnění bez daně…), se převezme jako koncept k ruční kontrole a do DPH nevstoupí,
 * dokud ho uživatel nepotvrdí. Faktura minulého roku neuhrazená k začátku roku se
 * převezme kvůli saldu a párování úhrad (zápis má v deníku svého roku).
 */
final class InvoiceImporter
{
    public const STEP_ISSUED = 'issued_invoices';
    public const STEP_PURCHASE = 'purchase_invoices';

    /** Limit KH pro jednotlivý doklad (A.4/B.2), vč. DPH. */
    private const KH_LIMIT = 10000.0;

    private const PAYMENT_FORMS = [
        'převod' => 'bank_transfer', 'prevod' => 'bank_transfer', 'příkaz' => 'bank_transfer',
        'hotov' => 'cash', 'pokladn' => 'cash', 'kart' => 'card', 'zápoč' => 'offset', 'zapoc' => 'offset',
        'dobír' => 'cash_on_delivery', 'dobir' => 'cash_on_delivery', 'inkas' => 'direct_debit', 'záloh' => 'other',
    ];

    /** @var array<string,?int> */
    private array $rateCache = [];

    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly StatsRecomputer $stats,
        private readonly OssMigrationPolicy $oss,
    ) {}

    public function importIssued(PremierContext $ctx, PremierDocuments $documents): void
    {
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_INVOICE);
        foreach ($documents->forYear(PremierDocuments::ISSUED, $ctx->year) as $doc) {
            $this->importIssuedDocument($ctx, $doc, $existing);
        }
        $ctx->protocol->finish(self::STEP_ISSUED);
    }

    public function importPurchases(PremierContext $ctx, PremierDocuments $documents): void
    {
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_PURCHASE_INVOICE);
        foreach ($documents->forYear(PremierDocuments::PURCHASE, $ctx->year) as $doc) {
            $this->importPurchaseDocument($ctx, $doc, $existing);
        }
        $ctx->protocol->finish(self::STEP_PURCHASE);
    }

    /**
     * Doklad s DPH složený z deníku (banka, interní doklad, pokladna se samovyměřením -
     * {@see VatDocumentImporter}): zapíše se stejnou cestou jako faktura, jen s vlastním
     * klíčem v mapě převodu a druhem dokladu.
     *
     * @param array<string,mixed> $doc tvar {@see PremierDocuments::build()} + `map_key`, `forced_type`, `numbers`
     * @return int|null id dokladu (`invoices` / `purchase_invoices`), null = nepřevzat
     */
    public function importJournalDocument(PremierContext $ctx, array $doc, string $step): ?int
    {
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_VAT_DOCUMENT);
        return $doc['direction'] === PremierDocuments::ISSUED
            ? $this->importIssuedDocument($ctx, $doc, $existing, $step)
            : $this->importPurchaseDocument($ctx, $doc, $existing, $step);
    }

    /**
     * Seznam klientů čte cache statistik - převedené faktury by v něm bez přepočtu chyběly.
     * Běží mimo transakci kroku, zkouška nanečisto ho vynechá.
     */
    public function recomputeClientStats(PremierContext $ctx): void
    {
        if ($ctx->statsClients === [] || $this->db->pdo()->inTransaction()) {
            return;
        }
        $this->stats->recomputeMany(array_values(array_unique($ctx->statsClients)));
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,int> $existing
     */
    private function importIssuedDocument(PremierContext $ctx, array $doc, array $existing, string $step = self::STEP_ISSUED): ?int
    {
        $p = $ctx->protocol;
        $key = $doc['map_key'] ?? ($doc['series'] . '|' . $doc['inter']);
        $label = $doc['label'] ?? ($doc['series'] . ' ' . $doc['number']);
        if (isset($existing[$key])) {
            if (!isset($doc['map_key'])) {
                $ctx->issuedInvoices[$doc['inter']] = $existing[$key];
            }
            if ($doc['previous']) {
                $ctx->previousPeriod['invoice|' . $existing[$key]] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'invoices', $existing[$key], $label, $doc['total']);
            return $existing[$key];
        }
        $reasons = $doc['reasons'];
        $type = $doc['forced_type'] ?? ($doc['kind'] === 'advance' ? 'proforma' : (($doc['total'] < 0 || ($doc['storno'] && $doc['total'] <= 0)) ? 'credit_note' : 'invoice'));
        $snapshot = $this->partners->documentSnapshot($doc['header']);
        $clientId = $this->partners->resolvePartner($ctx, $snapshot);
        $taxDate = $doc['vat_date'] ?? $doc['supply'];
        $items = $doc['items'];
        $notes = [];
        $ossItems = 0;
        $ossClient = null;
        $forcedSummary = false;
        foreach ($items as $i => $item) {
            $items[$i]['oss'] = OssMigrationPolicy::DOMESTIC_COLUMNS;
            $items[$i]['rate_id'] = null;
            $items[$i]['target_code'] = null;
            if ($type === 'proforma') {
                continue;
            }
            $code = (string) $item['code'];
            $hasVat = abs((float) $item['vat']) >= 0.005;
            if ($code === '') {
                if ($hasVat) {
                    $reasons[] = 'položka s daní bez kódu DPH';
                }
                continue;
            }
            $class = $ctx->vat->sale($code);
            if ($class === null) {
                $reasons[] = "kód DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje";
                continue;
            }
            if ($ctx->vat->forcesSummaryKh($code)) {
                $forcedSummary = true;
            }
            if (!$class['in_return']) {
                if (!$hasVat) {
                    continue; // plnění mimo přiznání bez daně (nepodléhá DPH)
                }
                if ($ossClient === null) {
                    $warning = $this->oss->runWarning($ctx->supplierId);
                    if ($warning !== null) {
                        $p->warn($step, 'oss_setup', $warning);
                    }
                    $ossClient = $this->oss->clientContext($clientId, $snapshot['country'], $snapshot['dic']);
                }
                $plan = $this->oss->planItem($ctx->supplierId, $ossClient, (float) $item['rate'], $item['unit'], $taxDate, $code);
                if ($plan['rate_id'] !== null) {
                    $items[$i]['rate_id'] = $plan['rate_id'];
                    $items[$i]['rate'] = $plan['rate_percent'];
                    $items[$i]['oss'] = $plan['columns'];
                    $ossItems++;
                    foreach ($plan['warnings'] as $w) {
                        $p->warn($step, 'oss_item_warning', "Doklad {$label} (režim OSS): {$w}", ['document_no' => $label]);
                    }
                } elseif ($plan['reason'] !== null) {
                    $reasons[] = $plan['reason'];
                }
                continue;
            }
            if ($class['code'] !== null) {
                if ($hasVat) {
                    $reasons[] = "kód DPH „{$code}“ (plnění bez daně) u položky s daní";
                    continue;
                }
                $items[$i]['target_code'] = $class['code'];
                continue;
            }
            $reduced = $this->isReduced($ctx->vat, $code, (float) $item['rate']);
            $items[$i]['target_code'] = ($reduced ? '2' : '1') . ($class['asset_sale'] ? 'm' : '');
        }
        $reasons = array_values(array_unique($reasons));
        if ($ossItems > 0) {
            $p->count($step, 'oss_items', $ossItems);
        }
        if ($forcedSummary && $snapshot['dic'] !== '' && abs($doc['total']) > self::KH_LIMIT) {
            // PREMIER vykázal doklad v KH souhrnně (A.5) - MyÚčto rozhoduje podle DIČ ve
            // snapshotu dokladu, proto se tam nepřebírá. Na kartě klienta zůstává.
            $notes[] = 'v KH vykázáno souhrnně (A.5), DIČ ' . $snapshot['dic'] . ' ve snapshotu dokladu vynecháno';
            $snapshot['dic'] = '';
            $p->count($step, 'kh_a5_forced');
        }
        if (!$this->rateItems($ctx, $step, $label, $items, $taxDate)) {
            return null;
        }
        $number = $this->freeNumber('invoices', $ctx->supplierId, $doc['numbers'] ?? [$doc['number']], (int) substr($doc['issue'], 0, 4));
        if ($number === null) {
            $p->error($step, 'number_taken', "Číslo dokladu {$label} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $review = $reasons !== [];
        $status = $review ? 'draft' : ($doc['settled'] ? 'paid' : 'sent');
        $booked = !$review && $type !== 'proforma' && $doc['booked'];
        $codes = array_values(array_unique(array_filter(array_column($items, 'target_code'))));
        $vs = preg_replace('/\D/', '', $doc['variable_symbol']) ?? '';
        $reverse = array_intersect($codes, ['25s', '25s3', '25s5']) !== [];
        try {
            $this->stmt('issued', 'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, varsymbol, payment_variable_symbol, issue_date, tax_date, due_date, currency_id,
                 note_above_items, note_below_items, client_snapshot, total_without_vat, total_vat, total_with_vat,
                 rounding, advance_paid_amount, paid_total, paid_at, status, booked_at, booked_by,
                 vat_classification_code, reverse_charge, payment_method, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')->execute([
                $ctx->supplierId,
                $type,
                $clientId,
                $number['number'],
                $vs !== '' && strlen(ltrim($vs, '0')) <= VariableSymbolNormalizer::MAX_LENGTH && $vs !== VariableSymbolNormalizer::forPayment((string) $number['number']) ? $vs : null,
                $doc['issue'],
                $type === 'proforma' ? null : $taxDate,
                $doc['due'],
                $this->currencyId($ctx->supplierId),
                $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                self::note($label, $reasons, $doc, $notes),
                PohodaPartners::snapshotJson($snapshot),
                $doc['base'],
                $doc['vat'],
                $doc['total'],
                $doc['rounding'],
                $doc['paid'],
                $doc['settled'] ? ($doc['paid_at'] ?? $doc['issue']) : null,
                $status,
                $booked ? $doc['accounting'] . ' 00:00:00' : null,
                $booked ? $ctx->userOrNull() : null,
                count($codes) === 1 ? $codes[0] : null,
                $reverse ? 1 : 0,
                self::paymentMethod($doc['payment_form']),
                $ctx->userOrNull(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->error($step, 'insert_conflict', "Doklad {$label} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        $insertItem = $this->stmt('issued_item', 'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code,
                 oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type, oss_needs_manual_review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $i => $item) {
            $oss = $item['oss'];
            $insertItem->execute([
                $id, $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                $item['target_code'],
                $oss['oss_applicable'], $oss['oss_consumer_country'], $oss['oss_rate_type'], $oss['oss_supply_type'], $oss['oss_needs_manual_review'],
            ]);
        }
        $this->map->put($ctx->supplierId, isset($doc['map_key']) ? PremierImportRepository::KIND_VAT_DOCUMENT : PremierImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
        if (!isset($doc['map_key'])) {
            $ctx->issuedInvoices[$doc['inter']] = $id;
        }
        $ctx->statsClients[] = $clientId;
        if ($doc['previous']) {
            $ctx->previousPeriod['invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        $p->count($step, 'created');
        $p->count($step, 'items_' . $doc['items_source'], count($items));
        $this->reportNumberAndReview($ctx, $step, $label, $number, $reasons);
        return $id;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,int> $existing
     */
    private function importPurchaseDocument(PremierContext $ctx, array $doc, array $existing, string $step = self::STEP_PURCHASE): ?int
    {
        $p = $ctx->protocol;
        $key = $doc['map_key'] ?? ($doc['series'] . '|' . $doc['inter']);
        $label = $doc['label'] ?? ($doc['series'] . ' ' . $doc['number'] . '/' . $doc['year']);
        if (isset($existing[$key])) {
            if (!isset($doc['map_key'])) {
                $ctx->purchaseInvoices[$doc['inter']] = $existing[$key];
            }
            if ($doc['previous']) {
                $ctx->previousPeriod['purchase_invoice|' . $existing[$key]] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'purchase_invoices', $existing[$key], $label, $doc['total']);
            return $existing[$key];
        }
        $reasons = $doc['reasons'];
        $type = $doc['forced_type'] ?? ($doc['kind'] === 'advance' ? 'advance' : (($doc['total'] < 0 || ($doc['storno'] && $doc['total'] <= 0)) ? 'credit_note' : 'invoice'));
        $items = $doc['items'];
        $deductions = [];
        $reverse = false;
        $assets = 0;
        foreach ($items as $i => $item) {
            $items[$i]['target_code'] = null;
            $items[$i]['fixed_asset'] = false;
            if ($type === 'advance') {
                continue;
            }
            $code = (string) $item['code'];
            $hasVat = abs((float) $item['vat']) >= 0.005;
            if ($code === '') {
                if ($hasVat) {
                    $reasons[] = 'položka s daní bez kódu DPH';
                }
                continue;
            }
            $class = $ctx->vat->purchase($code);
            if ($class === null) {
                $reasons[] = "kód DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje";
                continue;
            }
            if (!$class['in_return']) {
                if ($hasVat) {
                    $deductions['none'] = true; // daň je součástí nákladu, odpočet se neuplatnil
                }
                continue;
            }
            if ($class['reverse']) {
                if ($hasVat) {
                    $reasons[] = "kód DPH „{$code}“ (samovyměření) u položky s daní od dodavatele";
                    continue;
                }
                $reverse = true;
                $items[$i]['target_code'] = $class['code'];
                $deductions[$class['deduction']] = true;
            } else {
                $items[$i]['target_code'] = $this->isReduced($ctx->vat, $code, (float) $item['rate']) ? '41' : '40';
                $deductions[$class['deduction']] = true;
            }
            if ($class['fixed_asset']) {
                $items[$i]['fixed_asset'] = true;
                $assets++;
            }
        }
        if (count($deductions) > 1) {
            $reasons[] = 'doklad kombinuje položky s nárokem a bez nároku na odpočet (' . implode(', ', array_keys($deductions)) . ')';
        }
        $deduction = count($deductions) === 1 ? (string) array_key_first($deductions) : 'full';
        $reasons = array_values(array_unique($reasons));

        // Období odpočtu: PREMIER uplatňuje DPH k datu pro KH / DPH, jinak k datu zápisu.
        $vatDate = $doc['kh_date'] ?? $doc['vat_date'] ?? $doc['accounting'];
        $taxDate = $reverse && ($doc['kh_date'] !== null || $doc['vat_date'] !== null) ? $vatDate : $doc['supply'];
        $default = max($taxDate, $doc['issue']);
        $claim = null;
        if (!$reverse && $vatDate > $default) {
            $claim = $vatDate;
        } elseif (!$reverse && $vatDate < $default && substr($vatDate, 0, 7) !== substr($default, 0, 7) && $type !== 'advance') {
            $p->warn($step, 'claim_before_document', sprintf(
                'Doklad %s: PREMIER uplatnil odpočet k %s, dřív než je datum plnění nebo vystavení (%s). MyÚčto odpočet před držením dokladu nepřipustí - doklad je v DPH v období %s.',
                $label, $vatDate, $default, substr($default, 0, 7)
            ), ['document_no' => $label]);
            $p->count($step, 'claim_moved');
        }

        if (!$this->rateItems($ctx, $step, $label, $items, $taxDate)) {
            return null;
        }
        $snapshot = $this->partners->documentSnapshot($doc['header']);
        $vendorId = $this->partners->resolvePartner($ctx, $snapshot);
        $number = $this->freeNumber('purchase_invoices', $ctx->supplierId, $doc['numbers'] ?? [$doc['series'] . $doc['number'] . '/' . $doc['year']], $doc['year']);
        if ($number === null) {
            $p->error($step, 'number_taken', "Číslo dokladu {$label} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $vendorNumber = mb_substr($doc['vendor_number'] !== '' ? $doc['vendor_number'] : $number['number'], 0, 50);
        $duplicate = $this->stmt('purchase_duplicate', 'SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1');
        $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $doc['issue']]);
        if ($duplicate->fetchColumn() !== false) {
            $vendorNumber = mb_substr($vendorNumber . ' (' . $label . ')', 0, 50);
        }
        $review = $reasons !== [];
        $unbooked = $review || $type === 'advance' || !$doc['booked'];
        $status = $review ? 'draft' : ($doc['settled'] ? 'paid' : ($unbooked ? 'received' : 'booked'));
        $codes = array_values(array_unique(array_filter(array_column($items, 'target_code'))));
        [$accountNo, $bankCode] = self::bankAccount($doc['account_no']);
        $vs = preg_replace('/\D/', '', $doc['variable_symbol']) ?? '';
        try {
            $this->stmt('purchase', 'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, received_at_source, currency_id, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, advance_paid_amount,
                 payment_variable_symbol, payment_constant_symbol, payment_account_number, payment_bank_code,
                 payment_method, status, paid_at, booked_at, booked_by, note_above_items, note_below_items,
                 vat_deduction, vat_classification_code, reverse_charge, is_fixed_asset, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')->execute([
                $ctx->supplierId,
                $vendorId,
                PohodaPartners::vatId($snapshot['dic']) !== '' ? 1 : 0,
                $number['number'],
                $vendorNumber,
                $type,
                $doc['issue'],
                $taxDate,
                $doc['due'],
                $claim ?? $taxDate,
                $claim !== null ? 'manual' : 'import',
                $this->currencyId($ctx->supplierId),
                PohodaPartners::snapshotJson($snapshot),
                $doc['base'],
                $doc['vat'],
                $doc['total'],
                $doc['rounding'],
                $vs !== '' && strlen(ltrim($vs, '0')) <= VariableSymbolNormalizer::MAX_LENGTH && ltrim($vs, '0') !== '' ? $vs : null,
                preg_match('/^\d{1,4}$/', $doc['constant_symbol']) === 1 ? $doc['constant_symbol'] : null,
                $accountNo,
                $bankCode,
                self::paymentMethod($doc['payment_form']),
                $status,
                $doc['settled'] ? ($doc['paid_at'] ?? $doc['issue']) : null,
                $unbooked ? null : $doc['accounting'] . ' 00:00:00',
                $unbooked ? null : $ctx->userOrNull(),
                $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                self::note($label, $reasons, $doc),
                $deduction,
                count($codes) === 1 ? $codes[0] : null,
                $reverse ? 1 : 0,
                $assets > 0 && $assets === count($items) ? 1 : 0,
                $ctx->userId,
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->error($step, 'insert_conflict', "Doklad {$label} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        $insertItem = $this->stmt('purchase_item', 'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, is_fixed_asset, expense_kind)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $i => $item) {
            // Drobný majetek odvozený z účtu položky, když PREMIER evidenci nevede ({@see PremierSmallAssets}).
            $qty = (float) $item['quantity'];
            $expenseKind = $ctx->smallAssets?->expenseKind((string) ($item['account'] ?? ''), $qty != 0.0 ? (float) $item['base'] / $qty : (float) $item['base']);
            if ($expenseKind !== null && $expenseKind !== 'material') {
                $p->count($step, 'small_asset_items');
            }
            $insertItem->execute([
                $id, $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                $item['target_code'], $item['fixed_asset'] ? 1 : 0, $expenseKind,
            ]);
        }
        $this->map->put($ctx->supplierId, isset($doc['map_key']) ? PremierImportRepository::KIND_VAT_DOCUMENT : PremierImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
        if (!isset($doc['map_key'])) {
            $ctx->purchaseInvoices[$doc['inter']] = $id;
        }
        if ($doc['previous']) {
            $ctx->previousPeriod['purchase_invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        if ($reverse) {
            $p->count($step, 'self_assessed');
        }
        if ($claim !== null) {
            $p->count($step, 'claim_date_from_premier');
        }
        $p->count($step, 'created');
        $p->count($step, 'items_' . $doc['items_source'], count($items));
        $this->reportNumberAndReview($ctx, $step, $label, $number, $reasons);
        return $id;
    }

    /** Snížená sazba? Podle třídy sazby kódu, u jiné (historické) sazby podle její výše. */
    private function isReduced(PremierVat $vat, string $code, float $rate): bool
    {
        $class = $vat->rateClass($code);
        if ($class !== null && $vat->otherRate($code) <= 0.0) {
            return $class === PremierVat::RATE_REDUCED;
        }
        return $rate > 0.0 && $rate < 19.0;
    }

    /**
     * Tuzemská sazba položek (OSS řádky už ji mají ze státu spotřeby). `false` = doklad
     * nejde zapsat, protože sazba v číselníku firmy chybí.
     *
     * @param list<array<string,mixed>> $items MĚNÍ SE
     */
    private function rateItems(PremierContext $ctx, string $step, string $label, array &$items, string $taxDate): bool
    {
        foreach ($items as $i => $item) {
            if (($item['rate_id'] ?? null) !== null) {
                continue;
            }
            $rateId = $this->rateId((float) $item['rate'], $taxDate);
            if ($rateId === null) {
                $ctx->protocol->error($step, 'unknown_vat_rate', sprintf(
                    'Doklad %s: sazba DPH %s %% není v číselníku sazeb, nepřevzat. Založte ji v Nastavení → Číselníky → DPH sazby a převod zopakujte.',
                    $label, rtrim(rtrim(number_format((float) $item['rate'], 2, ',', ''), '0'), ',')
                ), ['document_no' => $label, 'rate' => $item['rate']]);
                return false;
            }
            $items[$i]['rate_id'] = $rateId;
        }
        return true;
    }

    /**
     * Číslo dokladu, které ve firmě ještě není; obsazené dostane příponu roku.
     *
     * @return array{number:string,suffixed:bool}|null
     */
    /** @param list<string> $numbers kandidáti v pořadí preference */
    private function freeNumber(string $table, int $supplierId, array $numbers, int $year): ?array
    {
        $stmt = $this->stmt('free_' . $table, "SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $suffix = '-' . $year;
        $candidates = [];
        foreach ($numbers as $i => $docNo) {
            $candidates[] = [mb_substr($docNo, 0, 20), $i > 0];
        }
        $candidates[] = [mb_substr($numbers[0], 0, 20 - mb_strlen($suffix)) . $suffix, true];
        foreach ($candidates as [$candidate, $suffixed]) {
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
    private function reportNumberAndReview(PremierContext $ctx, string $step, string $label, array $number, array $reasons): void
    {
        $p = $ctx->protocol;
        if ($number['suffixed']) {
            $p->count($step, 'suffixed');
            $p->info($step, 'number_suffixed', "Doklad {$label}: číslo už ve firmě je, převzat jako {$number['number']}.", ['document_no' => $label]);
        }
        if ($reasons !== []) {
            $p->count($step, 'review');
            $p->warn($step, 'needs_review', "Doklad {$label} převzat jako koncept k ruční kontrole: " . implode('; ', $reasons)
                . '. Do DPH ani do účtování nevstoupí, dokud ho neopravíte a nepotvrdíte.', ['document_no' => $label, 'reasons' => $reasons]);
        }
    }

    /**
     * Opakovaný převod už převedený doklad NEPŘEPISUJE (mohl být mezitím upravený nebo
     * spárovaný). Liší-li se v PREMIER, protokol to řekne.
     */
    private function reportChanged(PremierContext $ctx, string $step, string $table, int $id, string $label, float $total): void
    {
        $stmt = $this->stmt('changed_' . $table, "SELECT total_with_vat FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false || abs((float) $stored - $total) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_premier', sprintf(
            'Doklad %s se v PREMIER od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $label, number_format((float) $stored, 2, ',', ' '), number_format($total, 2, ',', ' ')
        ), ['document_no' => $label, 'id' => $id]);
    }

    /**
     * @param list<string> $reasons
     * @param array<string,mixed> $doc
     * @param list<string> $notes
     */
    private static function note(string $label, array $reasons, array $doc, array $notes = []): string
    {
        $note = 'Převzato z PREMIER, doklad ' . $label;
        if ($doc['previous']) {
            $note .= ' (doklad minulého období, zůstatek je v počátečních stavech)';
        }
        if ($doc['currency'] !== 'CZK') {
            $note .= '; doklad v ' . $doc['currency'] . ', převzat v Kč podle zaúčtování';
        }
        foreach ($notes as $n) {
            $note .= '; ' . $n;
        }
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . implode('; ', $reasons) . '.';
    }

    private static function paymentMethod(string $form): string
    {
        foreach (self::PAYMENT_FORMS as $needle => $method) {
            if ($form !== '' && str_contains($form, $needle)) {
                return $method;
            }
        }
        return 'bank_transfer';
    }

    /** @return array{0:?string,1:?string} číslo účtu a kód banky z „123-456/0100" */
    private static function bankAccount(string $value): array
    {
        $value = str_replace(' ', '', $value);
        if (preg_match('/^([0-9-]{1,40})\/(\d{4})$/', $value, $m) === 1) {
            return [$m[1], $m[2]];
        }
        return [$value !== '' ? mb_substr($value, 0, 34) : null, null];
    }

    private function stmt(string $key, string $sql): \PDOStatement
    {
        return $this->stmts[$key] ??= $this->db->pdo()->prepare($sql);
    }

    /** Tuzemská sazba firmy k datu, `null` = v číselníku není. */
    private function rateId(float $rate, string $date): ?int
    {
        $cacheKey = number_format($rate, 2, '.', '') . '|' . $date;
        if (array_key_exists($cacheKey, $this->rateCache)) {
            return $this->rateCache[$cacheKey];
        }
        $stmt = $this->stmt('rate', "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND rate_percent = ? AND is_reverse_charge = 0
              ORDER BY ((valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)) DESC,
                       is_default DESC, id
              LIMIT 1");
        $stmt->execute([number_format($rate, 2, '.', ''), $date, $date]);
        $id = $stmt->fetchColumn();
        return $this->rateCache[$cacheKey] = $id === false ? null : (int) $id;
    }

    private function currencyId(int $supplierId): int
    {
        $stmt = $this->stmt('currency', "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            $s = $this->stmt('currency_default', 'SELECT default_currency_id FROM supplier WHERE id = ?');
            $s->execute([$supplierId]);
            $id = (int) $s->fetchColumn();
        }
        return $id;
    }
}
