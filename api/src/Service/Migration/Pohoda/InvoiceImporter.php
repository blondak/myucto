<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Support\Sql\PayablePredicate;

/**
 * Vydané a přijaté doklady z agend faktur a daňové doklady k platbám z interních dokladů.
 *
 * | agenda Pohody                           | MyÚčto                                    |
 * |-----------------------------------------|-------------------------------------------|
 * | vydané faktury, vrubopisy               | `invoices` faktura                         |
 * | opravné daňové doklady, dobropisy       | `invoices` dobropis (záporné částky)       |
 * | vydané zálohové a proforma faktury      | `invoices` proforma (neúčtuje se)          |
 * | ostatní pohledávky v přiznání DPH       | `invoices` faktura, zápis se naváže        |
 * | interní doklad s tuzemským výstupem     | `invoices` daňový doklad k přijaté platbě  |
 * | přijaté faktury, vrubopisy              | `purchase_invoices` faktura                |
 * | přijaté opravné doklady, dobropisy      | `purchase_invoices` dobropis               |
 * | přijaté zálohové faktury                | `purchase_invoices` záloha (neúčtuje se)   |
 * | ostatní závazky v přiznání DPH          | `purchase_invoices` faktura                |
 * | interní doklad s tuzemským odpočtem     | `purchase_invoices` daňový doklad k záloze |
 *
 * Ostatní pohledávky a závazky mimo přiznání (odvody FÚ, mzdové závazky, půjčky…) zůstávají
 * jen v deníku - v MyÚčtu pro ně druh dokladu není a do DPH nevstupují.
 *
 * Položky se berou z rozpisu dokladu (text, množství, sazba), když rozpis sedí na
 * rekapitulaci; jinak z rekapitulace po sazbách. Ceny jsou vždy bez DPH.
 *
 * **Odpočet zálohy** (`invoiceAdvancePaymentItem`): zdaněná záloha (daňový doklad k platbě)
 * je záporná položka se sazbou a daní - v přiznání i v KH je za konečnou fakturu jen
 * rozdíl, přesně jak to podává Pohoda. Nezdaněná záloha je jen úhrada
 * (`advance_paid_amount`).
 *
 * **Doklad, jehož daňovou povahu z Pohody spolehlivě neznáme, se převezme jako koncept
 * k ruční kontrole.** Doklad minulého období (Pohoda přenáší neuhrazené doklady do nové
 * agendy) se převezme kvůli saldu a párování úhrad, v deníku roku zápis nemá.
 *
 * **Vydaný doklad s členěním mimo přiznání, který nese daň, je typicky plnění v režimu
 * OSS** (e-shop prodávající koncovým zákazníkům do EU: vlastní zkratka členění bez řádku
 * přiznání, sazba státu spotřeby, odběratel bez DIČ). Rozhoduje o tom {@see OssMigrationPolicy},
 * tedy táž autorita jako u ostatních vstupních kanálů; konceptem zůstane jen řádek, který
 * neprojde ani tudy.
 */
final class InvoiceImporter
{
    public const STEP_ISSUED = 'issued_invoices';
    public const STEP_PURCHASE = 'purchase_invoices';
    public const STEP_INTERNAL = 'internal_tax_documents';

    /** Rozdíl do 1 Kč mezi položkami a celkem dokladu je zaokrouhlení dokladu. */
    private const ROUNDING_LIMIT = 1.0;

    /** Limit KH pro jednotlivý doklad (A.4/B.2), vč. DPH. */
    private const KH_LIMIT = 10000.0;

    private const ISSUED_AGENDAS = [
        'issued' => 'invoice',
        'issued_debit' => 'invoice',
        'issued_credit' => 'credit_note',
        'issued_corrective' => 'credit_note',
        'issued_advance' => 'proforma',
        'issued_proforma' => 'proforma',
        'receivable' => 'invoice',
    ];

    private const PURCHASE_AGENDAS = [
        'received' => 'invoice',
        'received_debit' => 'invoice',
        'received_credit' => 'credit_note',
        'received_corrective' => 'credit_note',
        'received_advance' => 'advance',
        'received_proforma' => 'advance',
        'commitment' => 'invoice',
    ];

    /** `typ:paymentType` Pohody → způsob úhrady MyÚčta. */
    private const PAYMENT_TYPES = [
        '' => 'bank_transfer',
        'draft' => 'bank_transfer',
        'cash' => 'cash',
        'creditcard' => 'card',
        'delivery' => 'cash_on_delivery',
        'encashment' => 'direct_debit',
        'compensation' => 'offset',
    ];

    /** Přihrádky rekapitulace a jejich výchozí sazba, když export `@rate` nenese. */
    private const BUCKETS = ['Low' => 12.0, 'High' => 21.0, '3' => 10.0];

    /** @var array<string,?int> `null` = sazba v číselníku není; drží se taky, ať se marný dotaz neopakuje u každého dokladu */
    private array $rateCache = [];

    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly StatsRecomputer $stats,
        private readonly OssMigrationPolicy $oss,
    ) {}

    public function importIssued(PohodaContext $ctx): void
    {
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_INVOICE);
        foreach (self::ISSUED_AGENDAS as $agenda => $kind) {
            foreach ($ctx->export->records($agenda, 'invoice') as $r) {
                $this->importIssuedDocument($ctx, self::STEP_ISSUED, $r, $agenda, $kind, 'invoice', $existing);
            }
        }
        $ctx->protocol->finish(self::STEP_ISSUED);
    }

    public function importPurchases(PohodaContext $ctx): void
    {
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE);
        $selfAssessed = $this->selfAssessments($ctx);
        $used = [];
        foreach (self::PURCHASE_AGENDAS as $agenda => $kind) {
            foreach ($ctx->export->records($agenda, 'invoice') as $r) {
                $this->importPurchaseDocument($ctx, self::STEP_PURCHASE, $r, $agenda, $kind, 'invoice', $existing, $selfAssessed, $used);
            }
        }
        $unlinked = array_values(array_diff(array_keys($selfAssessed), array_keys($used)));
        if ($unlinked !== []) {
            $ctx->protocol->warn(self::STEP_PURCHASE, 'self_assessment_unlinked', count($unlinked) . ' interních dokladů s vyměřením DPH nejde přiřadit k převedené faktuře (' . implode(', ', array_slice($unlinked, 0, 20)) . '). Jejich DPH doplňte ručně.', ['documents' => $unlinked]);
        }
        $ctx->protocol->finish(self::STEP_PURCHASE);
    }

    /**
     * Daňové doklady k přijaté platbě (tuzemský výstup, ř. 1/2) a k uhrazené záloze
     * (tuzemský odpočet, ř. 40/41), které Pohoda vede jako interní doklady. Do přiznání
     * vstupují samostatně v měsíci platby a konečná faktura je pak odečítá položkou
     * „odpočet zálohy". Převádějí se jen doklady roku agendy - starší jsou v přiznáních
     * minulých let. Interní doklady se samovyměřením řeší přijatá faktura, ostatní
     * (mimo přiznání) zůstávají jen v deníku.
     */
    public function importInternalTaxDocuments(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $existingSales = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_INVOICE);
        $existingPurchases = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE);
        $none = [];
        foreach ($ctx->export->records('internal', 'intDoc') as $r) {
            $h = PohodaXml::get($r, 'intDocHeader');
            $code = PohodaXml::text($h, 'classificationVAT/ids');
            if ($code === '' || $ctx->vat->selfAssessmentCode($code) !== null) {
                continue;
            }
            $sale = $ctx->vat->sale($code, 21.0);
            $purchase = $ctx->vat->purchase($code);
            $isSale = $sale !== null && $sale['in_return'] && $sale['code'] === null;
            $isPurchase = !$isSale && $purchase !== null && $purchase['in_return'] && !$purchase['reverse'];
            if (!$isSale && !$isPurchase) {
                $p->count(self::STEP_INTERNAL, 'journal_only');
                continue;
            }
            $taxDate = PohodaXml::date($h, 'dateTax') ?? PohodaXml::date($h, 'date');
            if ($taxDate === null || (int) substr($taxDate, 0, 4) !== $ctx->year()) {
                $p->count(self::STEP_INTERNAL, 'previous_years');
                continue;
            }
            if ($isSale) {
                $this->importIssuedDocument($ctx, self::STEP_INTERNAL, $r, 'internal', 'tax_document', 'intDoc', $existingSales);
            } else {
                $this->importPurchaseDocument($ctx, self::STEP_INTERNAL, $r, 'internal', 'tax_document', 'intDoc', $existingPurchases, $none, $none);
            }
        }
        $p->finish(self::STEP_INTERNAL);
    }

    /**
     * Seznam klientů čte cache statistik - převedené faktury by v něm bez přepočtu chyběly.
     * Běží mimo transakci kroku, zkouška nanečisto ho vynechá.
     */
    public function recomputeClientStats(PohodaContext $ctx): void
    {
        if ($ctx->statsClients === [] || $this->db->pdo()->inTransaction()) {
            return;
        }
        $this->stats->recomputeMany(array_values(array_unique($ctx->statsClients)));
    }

    /** @param array<string,int> $existing */
    private function importIssuedDocument(PohodaContext $ctx, string $step, array $r, string $agenda, string $kind, string $prefix, array $existing): void
    {
        $p = $ctx->protocol;
        $bucket = match ($agenda) { 'receivable' => 'receivable', 'internal' => 'internal_sale', default => 'issued' };
        $doc = self::withoutSkippedPayments($ctx, $this->header($r, $prefix), $r);
        if ($doc['number'] === '' || $doc['issue'] === null) {
            $p->warn($step, 'missing_number_or_date', "Doklad {$doc['number']} ({$agenda}) nemá číslo nebo datum, nepřevzat.");
            return;
        }
        $key = $agenda . '|' . $doc['number'] . '|' . $doc['issue'];
        if (isset($existing[$key])) {
            $this->remember($ctx, $bucket, $doc['number'], $existing[$key]);
            self::rememberRemaining($ctx, 'invoice', $existing[$key], $doc);
            $this->captureLiquidations($ctx, 'invoice', $existing[$key], $doc['number'], $r);
            // Vazby na deník se při opakovaném běhu hodnotí znovu: doklad minulého období
            // zápis v deníku roku nemá a nesmí se napodruhé hlásit jako nezaúčtovaný.
            if ($this->isPreviousPeriod($ctx, $doc)) {
                $ctx->previousPeriod['invoice|' . $existing[$key]] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'invoices', $existing[$key], $doc['number'], $r, $prefix);
            $this->refreshSettlement($ctx, $step, 'invoices', $existing[$key], $doc);
            return;
        }
        if ($ctx->skipsDate($doc['accounting'])) {
            $p->count($step, 'later_year_skipped');
            return;
        }
        $amounts = $this->amounts($ctx, $step, $doc, $r, $prefix);
        $class = $this->classifyIssued($ctx, $kind, $doc['vat_class'], $amounts);
        if (($agenda === 'receivable' || $agenda === 'internal') && !$class['in_return']) {
            // Pohledávka mimo přiznání (odvod, půjčka…) zůstává jen v deníku.
            $p->count($step, 'journal_only');
            return;
        }
        $type = $kind === 'invoice' && $amounts['gross_total'] < 0 ? 'credit_note' : $kind;
        $previous = $this->isPreviousPeriod($ctx, $doc);
        $number = $this->freeNumber('invoices', $ctx->supplierId, $doc['number'], (int) substr($doc['issue'], 0, 4));
        if ($number === null) {
            $p->error($step, 'number_taken', "Číslo dokladu {$doc['number']} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $snapshot = PartnerImporter::snapshot(PohodaXml::get($doc['h'], 'partnerIdentity'));
        $clientId = $this->partners->resolvePartner($ctx, $snapshot);
        if (!$this->rateIssuedItems($ctx, $step, $doc, $amounts, $class, $clientId, $snapshot)) {
            return;
        }
        $review = $class['reasons'] !== [];
        $notes = [];
        if ($snapshot['dic'] !== '' && $ctx->vat->forcesA5($doc['vat_class']) && abs($amounts['total']) > self::KH_LIMIT) {
            // Pohoda doklad vykázala v KH A.5 (odběratel bez DIČ plátce) - MyÚčto rozhoduje
            // podle DIČ ve snapshotu dokladu, proto se tam nepřebírá. Na kartě klienta zůstává.
            $notes[] = "členění {$doc['vat_class']}: v KH vykázáno v A.5, DIČ {$snapshot['dic']} ve snapshotu dokladu vynecháno";
            $p->count($step, 'kh_a5_forced');
            $p->info($step, 'kh_a5_forced', "Doklad {$doc['number']} ({$snapshot['name']}, DIČ {$snapshot['dic']}) má v Pohodě členění {$doc['vat_class']}, které ho podle číselníku členění DPH řadí do oddílu A.5 kontrolního hlášení, přestože je nad 10 000 Kč a odběratel má DIČ. Ověřte, že odběratel není plátce DPH.", ['document_no' => $doc['number']]);
            $snapshot['dic'] = '';
        }
        $pay = $type === 'tax_document'
            ? ['paid' => $amounts['total'], 'settled' => true, 'paid_at' => $doc['tax'] ?? $doc['issue']]
            : self::payment($doc, $amounts);
        $status = $review ? 'draft' : ($pay['settled'] ? 'paid' : 'sent');
        $booked = !$review && $type !== 'proforma';
        [$insert, $insertItem] = $this->issuedStatements();
        try {
            $insert->execute([
                $ctx->supplierId,
                $type,
                $clientId,
                $number['number'],
                // Platební VS z Pohody, liší-li se od čísla dokladu (#249).
                preg_match('/^\d{1,10}$/', $doc['symvar']) === 1
                    && $doc['symvar'] !== VariableSymbolNormalizer::forPayment((string) $number['number'])
                    ? $doc['symvar'] : null,
                $doc['issue'],
                $type === 'proforma' ? $doc['tax'] : ($doc['tax'] ?? $doc['issue']),
                $doc['due'],
                $this->currencyId($ctx->supplierId),
                $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                self::note($doc['number'], $class['reasons'], $previous, $doc['foreign'], $notes),
                PartnerImporter::snapshotJson($snapshot),
                $amounts['base'],
                $amounts['vat'],
                $amounts['total'],
                $amounts['rounding'],
                $amounts['advance'],
                $pay['paid'],
                $pay['settled'] ? $pay['paid_at'] : null,
                $status,
                $booked ? $doc['accounting'] . ' 00:00:00' : null,
                $booked ? $ctx->userOrNull() : null,
                $class['code'],
                $doc['payment'],
                $ctx->userOrNull(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->error($step, 'insert_conflict', "Doklad {$doc['number']} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        foreach ($amounts['items'] as $i => $item) {
            $oss = $item['oss'] ?? OssMigrationPolicy::DOMESTIC_COLUMNS;
            $insertItem->execute([
                $id, $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                $item['code'] ?? $class['code'],
                $oss['oss_applicable'], $oss['oss_consumer_country'], $oss['oss_rate_type'],
                $oss['oss_supply_type'], $oss['oss_needs_manual_review'],
            ]);
        }
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
        $this->remember($ctx, $bucket, $doc['number'], $id);
        self::rememberRemaining($ctx, 'invoice', $id, $doc);
        $this->captureLiquidations($ctx, 'invoice', $id, $doc['number'], $r);
        if ($previous) {
            $ctx->previousPeriod['invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        $ctx->statsClients[] = $clientId;
        $p->count($step, match ($bucket) { 'receivable' => 'receivables', 'internal_sale' => 'tax_documents_sale', default => 'created' });
        $p->count($step, 'items_' . $amounts['source'], count($amounts['items']));
        if ($amounts['advance_items'] > 0) {
            $p->count($step, 'advance_deductions_with_vat', $amounts['advance_items']);
        }
        $this->reportNumberAndReview($ctx, $step, $doc['number'], $number, $class['reasons']);
    }

    /**
     * @param array<string,int> $existing
     * @param array<string,array<string,mixed>> $selfAssessed
     * @param array<string,true> $used
     */
    private function importPurchaseDocument(PohodaContext $ctx, string $step, array $r, string $agenda, string $kind, string $prefix, array $existing, array $selfAssessed, array &$used): void
    {
        $p = $ctx->protocol;
        $bucket = match ($agenda) { 'commitment' => 'commitment', 'internal' => 'internal_purchase', default => 'purchase' };
        $doc = self::withoutSkippedPayments($ctx, $this->header($r, $prefix), $r);
        if ($doc['number'] === '' || $doc['issue'] === null) {
            $p->warn($step, 'missing_number_or_date', "Doklad {$doc['number']} ({$agenda}) nemá číslo nebo datum, nepřevzat.");
            return;
        }
        $key = $agenda . '|' . $doc['number'] . '|' . $doc['issue'];
        if (isset($existing[$key])) {
            $this->remember($ctx, $bucket, $doc['number'], $existing[$key]);
            self::rememberRemaining($ctx, 'purchase_invoice', $existing[$key], $doc);
            $this->captureLiquidations($ctx, 'purchase_invoice', $existing[$key], $doc['number'], $r);
            if ($this->isPreviousPeriod($ctx, $doc)) {
                $ctx->previousPeriod['purchase_invoice|' . $existing[$key]] = true;
            }
            // Samovyměření už převedené faktury je přiřazené - jinak by ho opakovaný běh
            // hlásil jako interní doklad bez faktury.
            if (isset($selfAssessed[$doc['number']])) {
                $used[$doc['number']] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'purchase_invoices', $existing[$key], $doc['number'], $r, $prefix);
            $this->refreshSettlement($ctx, $step, 'purchase_invoices', $existing[$key], $doc);
            return;
        }
        if ($ctx->skipsDate($doc['accounting'])) {
            $p->count($step, 'later_year_skipped');
            return;
        }
        $amounts = $this->amounts($ctx, $step, $doc, $r, $prefix);
        $class = $this->classifyPurchase($ctx, $kind, $doc['vat_class'], $amounts);
        if (($agenda === 'commitment' || $agenda === 'internal') && !$class['in_return']) {
            $p->count($step, 'journal_only');
            return;
        }
        if ($class['reverse']) {
            $sa = $selfAssessed[$doc['number']] ?? null;
            if ($sa === null) {
                $class['reasons'][] = 'odpočet ze samovyměření bez interního dokladu s vyměřením daně';
            } elseif ($sa['error'] !== null) {
                $class['reasons'][] = 'samovyměření z interního dokladu ' . $sa['doc'] . ': ' . $sa['error'];
            } else {
                $used[$doc['number']] = true;
                $amounts = $this->applySelfAssessment($sa, $amounts, $doc['tax'] ?? $doc['issue']);
                $doc['tax'] = $sa['date'] ?? $doc['tax'];
                $p->count($step, 'self_assessed');
            }
        }
        // Přijatá strana OSS nezná (režim je pro plnění, která poskytujeme), takže se sazba
        // páruje tuzemsky - jen až tady, protože `amounts()` ji kvůli vydané větvi nechává
        // nenapárovanou. Nenalezená sazba je pořád tvrdá chyba celého běhu jako dřív.
        foreach ($amounts['items'] as $i => $item) {
            $amounts['items'][$i]['rate_id'] ??= $this->rateId((float) $item['rate'], $doc['tax'] ?? $doc['issue']);
        }
        $type = $kind === 'invoice' && $amounts['gross_total'] < 0 ? 'credit_note' : $kind;
        $review = $class['reasons'] !== [];
        $previous = $this->isPreviousPeriod($ctx, $doc);
        $number = $this->freeNumber('purchase_invoices', $ctx->supplierId, $doc['number'], (int) substr($doc['issue'], 0, 4));
        if ($number === null) {
            $p->error($step, 'number_taken', "Číslo dokladu {$doc['number']} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $snapshot = PartnerImporter::snapshot(PohodaXml::get($doc['h'], 'partnerIdentity'));
        $vendorId = $this->partners->resolvePartner($ctx, $snapshot);
        $vendorNumber = mb_substr($doc['original'] !== '' ? $doc['original'] : ($doc['symvar'] !== '' ? $doc['symvar'] : $doc['number']), 0, 50);
        $duplicate = $this->stmt('purchase_duplicate', 'SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1');
        $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $doc['issue']]);
        if ($duplicate->fetchColumn() !== false) {
            $vendorNumber = mb_substr($vendorNumber . ' (' . $doc['number'] . ')', 0, 50);
        }
        $pay = $type === 'tax_document'
            ? ['paid' => $amounts['total'], 'settled' => true, 'paid_at' => $doc['tax'] ?? $doc['issue']]
            : self::payment($doc, $amounts);
        $unbooked = $review || $type === 'advance';
        $status = $review ? 'draft' : ($pay['settled'] ? 'paid' : ($unbooked ? 'received' : 'booked'));
        // Datum pro KH je v Pohodě den, ke kterému účetní odpočet uplatnila - MyÚčto
        // ho respektuje jen jako ručně zadané datum přijetí (§ 73).
        $claim = $doc['claim'];
        [$insert, $insertItem] = $this->purchaseStatements();
        try {
            $insert->execute([
                $ctx->supplierId,
                $vendorId,
                $snapshot['dic'] !== '' ? 1 : 0,
                $number['number'],
                $vendorNumber,
                $type,
                $doc['issue'],
                $doc['tax'] ?? $doc['issue'],
                $doc['due'],
                $claim ?? ($doc['tax'] ?? $doc['issue']),
                $claim !== null ? 'manual' : 'import',
                $this->currencyId($ctx->supplierId),
                PartnerImporter::snapshotJson($snapshot),
                $amounts['base'],
                $amounts['vat'],
                $amounts['total'],
                $amounts['rounding'],
                $amounts['advance'],
                preg_match('/^\d{1,10}$/', $doc['symvar']) === 1 ? $doc['symvar'] : null,
                preg_match('/^\d{1,4}$/', $doc['symconst']) === 1 ? $doc['symconst'] : null,
                $doc['account_no'] !== '' ? mb_substr($doc['account_no'], 0, 34) : null,
                $doc['bank_code'] !== '' ? mb_substr($doc['bank_code'], 0, 10) : null,
                $doc['payment'],
                $status,
                $pay['settled'] ? $pay['paid_at'] : null,
                $unbooked ? null : $doc['accounting'] . ' 00:00:00',
                $unbooked ? null : $ctx->userOrNull(),
                $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                self::note($doc['number'], $class['reasons'], $previous, $doc['foreign']),
                $class['deduction'],
                null,
                $class['reverse'] ? 1 : 0,
                $ctx->userId,
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->error($step, 'insert_conflict', "Doklad {$doc['number']} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        foreach ($amounts['items'] as $i => $item) {
            $insertItem->execute([
                $id, $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                $item['code'] ?? null,
            ]);
        }
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
        $this->remember($ctx, $bucket, $doc['number'], $id);
        self::rememberRemaining($ctx, 'purchase_invoice', $id, $doc);
        $this->captureLiquidations($ctx, 'purchase_invoice', $id, $doc['number'], $r);
        if ($previous) {
            $ctx->previousPeriod['purchase_invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        $p->count($step, match ($bucket) { 'commitment' => 'commitments', 'internal_purchase' => 'tax_documents_purchase', default => 'created' });
        $p->count($step, 'items_' . $amounts['source'], count($amounts['items']));
        if ($amounts['advance_items'] > 0) {
            $p->count($step, 'advance_deductions_with_vat', $amounts['advance_items']);
        }
        $this->reportNumberAndReview($ctx, $step, $doc['number'], $number, $class['reasons']);
    }

    /** @return array<string,mixed> */
    private function header(array $r, string $prefix): array
    {
        $h = PohodaXml::get($r, $prefix . 'Header') ?? [];
        $issue = PohodaXml::date($h, 'date') ?? PohodaXml::date($h, 'dateAccounting');
        return [
            'h' => $h,
            'number' => PohodaXml::text($h, 'number/numberRequested'),
            'issue' => $issue,
            'tax' => PohodaXml::date($h, 'dateTax'),
            'accounting' => PohodaXml::date($h, 'dateAccounting') ?? $issue,
            'due' => PohodaXml::date($h, 'dateDue') ?? $issue,
            'claim' => PohodaXml::date($h, 'dateKHDPH'),
            'vat_class' => PohodaXml::text($h, 'classificationVAT/ids'),
            'text' => PohodaXml::text($h, 'text'),
            'symvar' => PohodaXml::text($h, 'symVar'),
            'symconst' => PohodaXml::text($h, 'symConst'),
            'original' => PohodaXml::text($h, 'originalDocument'),
            'account_no' => PohodaXml::text($h, 'paymentAccount/accountNo'),
            'bank_code' => PohodaXml::text($h, 'paymentAccount/bankCode'),
            'payment' => self::PAYMENT_TYPES[PohodaXml::text($h, 'paymentType/paymentType')] ?? 'other',
            'remaining' => PohodaXml::text($h, 'liquidation/amountHome'),
            'paid_date' => PohodaXml::date($h, 'liquidation/date'),
            'foreign' => PohodaXml::text($r, $prefix . 'Summary/foreignCurrency/currency/ids'),
        ];
    }

    /**
     * Položky a součty dokladu v Kč.
     *
     * @param array<string,mixed> $doc
     * @return array{items:list<array<string,mixed>>,base:float,vat:float,total:float,gross_total:float,rounding:float,advance:float,advance_items:int,source:string}
     */
    private function amounts(PohodaContext $ctx, string $step, array $doc, array $r, string $prefix): array
    {
        $summary = PohodaXml::get($r, $prefix . 'Summary/homeCurrency');
        $rates = ['none' => 0.0];
        $buckets = [];
        $none = round(PohodaXml::num($summary, 'priceNone'), 2);
        foreach (self::BUCKETS as $bucket => $default) {
            $rate = PohodaXml::attr($summary, 'price' . $bucket . 'VAT', 'rate');
            $rate = is_numeric($rate) ? (float) $rate : $default;
            $rates[match ($bucket) { 'Low' => 'low', 'High' => 'high', default => 'third' }] = $rate;
            $base = round(PohodaXml::num($summary, 'price' . $bucket), 2);
            $vat = round(PohodaXml::num($summary, 'price' . $bucket . 'VAT'), 2);
            if ($base !== 0.0 || $vat !== 0.0) {
                $buckets[] = ['base' => $base, 'vat' => $vat, 'rate' => $rate];
            }
        }
        $docRound = round(PohodaXml::num($summary, 'round/priceRound'), 2);
        $taxedSum = round(array_sum(array_map(static fn (array $b): float => $b['base'] + $b['vat'], $buckets)), 2);
        $docTotal = round($none + $taxedSum + $docRound, 2);
        $itemRate = static function (mixed $it) use ($rates): float {
            $value = PohodaXml::attr($it, 'rateVAT', 'value');
            return is_numeric($value) ? (float) $value : ($rates[PohodaXml::text($it, 'rateVAT')] ?? 0.0);
        };

        $items = [];
        foreach (PohodaXml::all($r, $prefix . 'Detail/' . $prefix . 'Item') as $it) {
            $base = round(PohodaXml::num($it, 'homeCurrency/price'), 2);
            $qty = PohodaXml::num($it, 'quantity');
            $items[] = [
                'description' => PohodaXml::text($it, 'text') ?: ($doc['text'] ?: 'Převzato z Pohody'),
                'quantity' => $qty !== 0.0 ? round($qty, 3) : 1.0,
                'unit' => mb_substr(PohodaXml::text($it, 'unit'), 0, 20) ?: null,
                'unit_price' => $qty !== 0.0 ? round($base / $qty, 6) : $base,
                'base' => $base,
                'vat' => round(PohodaXml::num($it, 'homeCurrency/priceVAT'), 2),
                'rate' => $itemRate($it),
            ];
        }
        $source = 'detail';
        if ($items !== []) {
            $itemSum = round(array_sum(array_map(static fn (array $i): float => $i['base'] + $i['vat'], $items)), 2);
            $tolerance = max(0.05, 0.005 * abs($itemSum));
            // Haléřové vyrovnání zaokrouhlení (`math2one`) parkuje Pohoda v priceNone.
            $fits = abs($itemSum - ($taxedSum + $none)) <= $tolerance
                || (abs($none) <= self::ROUNDING_LIMIT && abs($itemSum - $taxedSum) <= $tolerance);
            if (!$fits) {
                $ctx->protocol->info($step, 'items_from_summary', sprintf(
                    'Doklad %s: rozpis položek (%s) nesedí na rekapitulaci (%s), položky jsou z rekapitulace po sazbách.',
                    $doc['number'], number_format($itemSum, 2, ',', ' '), number_format($taxedSum + $none, 2, ',', ' ')
                ), ['document_no' => $doc['number']]);
                $items = [];
            }
        }
        if ($items === []) {
            $source = 'summary';
            $lines = [];
            if ($none !== 0.0 && !(abs($none) <= self::ROUNDING_LIMIT && $buckets !== [])) {
                $lines[] = ['base' => $none, 'vat' => 0.0, 'rate' => 0.0];
            }
            foreach ($buckets as $b) {
                $lines[] = $b;
            }
            foreach ($lines as $line) {
                $items[] = [
                    'description' => $doc['text'] ?: ('Doklad ' . $doc['number']),
                    'quantity' => 1.0, 'unit' => null, 'unit_price' => $line['base'],
                    'base' => $line['base'], 'vat' => $line['vat'], 'rate' => $line['rate'],
                ];
            }
        }

        $grossTotal = round(array_sum(array_column($items, 'base')) + array_sum(array_column($items, 'vat')), 2);
        $rounding = round($docTotal - $grossTotal, 2);
        if (abs($rounding) > self::ROUNDING_LIMIT) {
            $ctx->protocol->warn($step, 'total_mismatch', sprintf(
                'Doklad %s: položky %s nesedí na celkem %s z Pohody.',
                $doc['number'], number_format($grossTotal, 2, ',', ' '), number_format($docTotal, 2, ',', ' ')
            ), ['document_no' => $doc['number']]);
            $rounding = 0.0;
        }

        // Rekapitulace Pohody je před odpočtem záloh - odpočty se přidávají až teď.
        $advance = 0.0;
        $advanceItems = 0;
        foreach (PohodaXml::all($r, $prefix . 'Detail/invoiceAdvancePaymentItem') as $a) {
            $vat = round(PohodaXml::num($a, 'homeCurrency/priceVAT'), 2);
            if (abs($vat) < 0.005) {
                $advance += PohodaXml::num($a, 'homeCurrency/priceSum');
                continue;
            }
            $base = round(PohodaXml::num($a, 'homeCurrency/price'), 2);
            $ref = PohodaXml::text($a, 'note') ?: PohodaXml::text($a, 'sourceDocument/number');
            $items[] = [
                'description' => 'Odpočet zálohy' . ($ref !== '' ? ' ' . $ref : ''),
                'quantity' => 1.0, 'unit' => null, 'unit_price' => $base,
                'base' => $base, 'vat' => $vat, 'rate' => $itemRate($a),
            ];
            $advanceItems++;
        }
        foreach ($items as &$item) {
            // Sazba se páruje až v zapisující větvi: u OSS řádku se hledá ve STÁTĚ SPOTŘEBY,
            // a dokud není rozhodnuto o místě plnění, není známá země, ve které se má hledat.
            $item['rate_id'] = null;
            $item['code'] = null;
        }
        unset($item);

        $base = round(array_sum(array_column($items, 'base')), 2);
        $vat = round(array_sum(array_column($items, 'vat')), 2);
        return [
            'items' => $items,
            'base' => $base,
            'vat' => $vat,
            'total' => round($base + $vat + $rounding, 2),
            'gross_total' => round($grossTotal + $rounding, 2),
            'rounding' => $rounding,
            'advance' => round(-$advance, 2),
            'advance_items' => $advanceItems,
            'source' => $source,
        ];
    }

    /**
     * Opakovaný převod doplní úhradu, kterou Pohoda mezitím zaznamenala (likvidace dokladu).
     * Stav se posouvá jen dopředu: neuhrazený doklad se označí jako uhrazený, u vydaného se
     * zvýší uhrazená částka. Zpět se nevrací nic - úhradu zapsanou v MyÚčtu (spárovaná platba,
     * ruční označení) převod nepřepíše, a koncept k ruční kontrole zůstává konceptem.
     *
     * @param array<string,mixed> $doc
     */
    private function refreshSettlement(PohodaContext $ctx, string $step, string $table, int $id, array $doc): void
    {
        $remaining = is_numeric($doc['remaining']) ? round((float) $doc['remaining'], 2) : 0.0;
        $settled = abs($remaining) < 0.005;
        if ($table === 'purchase_invoices') {
            if (!$settled) {
                return;
            }
            $stmt = $this->stmt('settle_purchase',
                "UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?)
                  WHERE id = ? AND supplier_id = ? AND status IN ('received', 'booked')" . PayablePredicate::excludeAdvanceVatDocument(''));
            $stmt->execute([$doc['paid_date'], $id, $ctx->supplierId]);
        } else {
            $stmt = $this->stmt('settle_issued',
                "UPDATE invoices
                    SET paid_total = total_with_vat - advance_paid_amount - ?,
                        paid_at = IF(?, COALESCE(paid_at, ?), paid_at),
                        status = IF(?, 'paid', status)
                  WHERE id = ? AND supplier_id = ? AND status IN ('issued', 'sent', 'reminded')
                    AND ABS(total_with_vat - advance_paid_amount - ?) > ABS(paid_total) + 0.005");
            $stmt->execute([$remaining, (int) $settled, $doc['paid_date'], (int) $settled, $id, $ctx->supplierId, $remaining]);
        }
        if ($stmt->rowCount() > 0) {
            $ctx->protocol->count($step, $settled ? 'settled_in_pohoda' : 'partially_paid_in_pohoda');
        }
    }

    /**
     * Stav úhrady z Pohody: `liquidation/amountHome` je zbývá uhradit (chybí = uhrazeno),
     * `liquidation/date` datum poslední úhrady.
     *
     * @param array<string,mixed> $doc
     * @param array{total:float,advance:float} $amounts
     * @return array{paid:float,settled:bool,paid_at:?string}
     */
    private static function payment(array $doc, array $amounts): array
    {
        $toPay = round($amounts['total'] - $amounts['advance'], 2);
        $remaining = is_numeric($doc['remaining']) ? round((float) $doc['remaining'], 2) : 0.0;
        $settled = abs($remaining) < 0.005;
        return [
            'paid' => round($toPay - $remaining, 2),
            'settled' => $settled,
            'paid_at' => $doc['paid_date'] ?? ($settled && abs($toPay) < 0.005 ? $doc['issue'] : null),
        ];
    }

    /**
     * Sazba a režim OSS na řádcích vydaného dokladu. `false` = doklad nelze zapsat.
     *
     * Běží až tady, protože potřebuje odběratele (zemi a DIČ), a ten je znám teprve po
     * `resolvePartner()`. Doklad, jehož členění stojí mimo přiznání a přesto nese daň,
     * projde politikou převodu ({@see OssMigrationPolicy}); ostatní řádky se párují
     * tuzemsky přesně jako dosud.
     *
     * Sazba, kterou nejde napárovat ani jednou cestou, doklad PŘESKOČÍ s chybou v
     * protokolu. Dřív shodila celý běh výjimkou - jenže u agendy, kde takových dokladů
     * bývají stovky, je „převod spadl na prvním z nich" ta nejhorší z možných odpovědí:
     * uživatel nezjistí ani kolik jich je, ani co všechno mu chybí v číselníku.
     *
     * @param array<string,mixed> $doc
     * @param array{items:list<array<string,mixed>>,vat:float} $amounts MĚNÍ SE: řádky dostanou sazbu a OSS sloupce
     * @param array{reasons:list<string>,code:?string,in_return:bool} $class MĚNÍ SE: přibývají důvody k ruční kontrole
     * @param array<string,mixed> $snapshot odběratel z dokladu
     */
    private function rateIssuedItems(PohodaContext $ctx, string $step, array $doc, array &$amounts, array &$class, int $clientId, array $snapshot): bool
    {
        $p = $ctx->protocol;
        $taxDate = $doc['tax'] ?? $doc['issue'];
        $code = (string) $doc['vat_class'];
        $candidate = !$class['in_return'] && abs($amounts['vat']) >= 0.005;
        $client = null;
        $ossItems = 0;
        $manualReview = 0;
        $warnings = [];

        if ($candidate) {
            $warning = $this->oss->runWarning($ctx->supplierId);
            if ($warning !== null) {
                $p->warn($step, 'oss_setup', $warning);
            }
        }

        foreach ($amounts['items'] as $i => $item) {
            if ($candidate && abs((float) $item['vat']) >= 0.005) {
                $client ??= $this->oss->clientContext($clientId, (string) $snapshot['country'], (string) $snapshot['dic']);
                $plan = $this->oss->planItem($ctx->supplierId, $client, (float) $item['rate'], $item['unit'], $taxDate, $code);
                if ($plan['reason'] !== null && !in_array($plan['reason'], $class['reasons'], true)) {
                    $class['reasons'][] = $plan['reason'];
                }
                if ($plan['rate_id'] !== null) {
                    $amounts['items'][$i]['rate_id'] = $plan['rate_id'];
                    $amounts['items'][$i]['rate'] = $plan['rate_percent'];
                    $amounts['items'][$i]['oss'] = $plan['columns'];
                    $ossItems++;
                    $manualReview += (int) $plan['columns']['oss_needs_manual_review'] === 1 ? 1 : 0;
                    foreach ($plan['warnings'] as $warning) {
                        $warnings[$warning] = true;
                    }
                    continue;
                }
            }
            $rateId = $this->rateId((float) $item['rate'], $taxDate, false);
            if ($rateId === null) {
                $p->error($step, 'unknown_vat_rate', sprintf(
                    'Doklad %s: sazba DPH %s %% není v číselníku sazeb firmy, nepřevzat. Založte ji '
                        . 'v Nastavení → Číselníky → DPH sazby (u zahraniční sazby nezapomeňte na sloupec '
                        . 'Stát, formulář ho předvyplňuje na CZ) a převod zopakujte.',
                    $doc['number'],
                    rtrim(rtrim(number_format((float) $item['rate'], 2, ',', ''), '0'), ','),
                ), ['document_no' => $doc['number'], 'rate' => $item['rate']]);
                return false;
            }
            $amounts['items'][$i]['rate_id'] = $rateId;
        }

        if ($candidate && $ossItems === 0 && $class['reasons'] === []) {
            // Pojistka pro doklad, u kterého se daň nedá přiřadit k žádnému řádku se sazbou
            // (rozpis z Pohody nesedí na rekapitulaci). OSS se nerozhodlo, ale doklad daň
            // nese a do přiznání nepatří - to člověk vidět musí.
            $class['reasons'][] = "členění DPH „{$code}“ mimo přiznání u dokladu s daní";
        }
        if ($ossItems > 0) {
            $p->count($step, 'oss_items', $ossItems);
        }
        if ($warnings !== []) {
            $p->warn($step, 'oss_item_warning', sprintf('Doklad %s (režim OSS): %s', $doc['number'], implode('; ', array_keys($warnings))),
                ['document_no' => $doc['number']]);
        }
        if ($manualReview > 0) {
            $p->count($step, 'oss_manual_review', $manualReview);
            $p->info($step, 'oss_manual_review', sprintf(
                'Doklad %s je v režimu OSS, ale u %d řádků si převod místem plnění nebo typem sazby '
                    . 'není jistý - jsou označené k ručnímu posouzení. Projděte je v náhledu OSS přiznání '
                    . 'nebo hromadnou akcí Nastavit OSS.',
                $doc['number'],
                $manualReview,
            ), ['document_no' => $doc['number'], 'items' => $manualReview]);
        }

        return true;
    }

    /**
     * @param array{items:list<array<string,mixed>>,vat:float} $amounts
     * @return array{reasons:list<string>,code:?string,in_return:bool}
     */
    private function classifyIssued(PohodaContext $ctx, string $kind, string $code, array &$amounts): array
    {
        if ($kind === 'proforma') {
            return ['reasons' => [], 'code' => null, 'in_return' => true];
        }
        $hasVat = abs($amounts['vat']) >= 0.005;
        if ($code === '') {
            return ['reasons' => $hasVat ? ['doklad s daní bez členění DPH'] : [], 'code' => null, 'in_return' => true];
        }
        $rate = 0.0;
        foreach ($amounts['items'] as $i) {
            $rate = max($rate, abs($i['vat']) >= 0.005 ? $i['rate'] : 0.0);
        }
        $res = $ctx->vat->sale($code, $rate);
        if ($res === null) {
            return ['reasons' => ["členění DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje"], 'code' => null, 'in_return' => true];
        }
        if (!$res['in_return']) {
            // Doklad s daní mimo přiznání ještě není vada - je to podpis režimu OSS.
            // Rozhoduje o něm až {@see rateIssuedItems()}, které jediné zná odběratele.
            return ['reasons' => [], 'code' => null, 'in_return' => false];
        }
        if ($res['code'] !== null && $hasVat && !in_array($res['code'], ['1m', '2m'], true)) {
            return ['reasons' => ["členění DPH „{$code}“ (plnění bez daně) u dokladu s daní"], 'code' => null, 'in_return' => true];
        }
        if ($res['code'] !== null) {
            foreach ($amounts['items'] as &$item) {
                $item['code'] = $res['code'];
            }
            unset($item);
        }
        return ['reasons' => [], 'code' => $res['code'], 'in_return' => true];
    }

    /**
     * @param array{items:list<array<string,mixed>>,vat:float} $amounts
     * @return array{reasons:list<string>,deduction:string,in_return:bool,reverse:bool}
     */
    private function classifyPurchase(PohodaContext $ctx, string $kind, string $code, array $amounts): array
    {
        if ($kind === 'advance') {
            return ['reasons' => [], 'deduction' => 'full', 'in_return' => true, 'reverse' => false];
        }
        $hasVat = abs($amounts['vat']) >= 0.005;
        if ($code === '') {
            return ['reasons' => $hasVat ? ['doklad s daní bez členění DPH'] : [], 'deduction' => 'full', 'in_return' => true, 'reverse' => false];
        }
        $res = $ctx->vat->purchase($code);
        if ($res === null) {
            return ['reasons' => ["členění DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje"], 'deduction' => 'full', 'in_return' => true, 'reverse' => false];
        }
        if (!$res['in_return']) {
            // Přijatý doklad mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
            return ['reasons' => [], 'deduction' => $hasVat ? 'none' : 'full', 'in_return' => false, 'reverse' => false];
        }
        if ($res['reverse'] && $hasVat) {
            return ['reasons' => ["členění DPH „{$code}“ (samovyměření) u dokladu s daní od dodavatele"], 'deduction' => $res['deduction'], 'in_return' => true, 'reverse' => false];
        }
        return ['reasons' => [], 'deduction' => $res['deduction'], 'in_return' => true, 'reverse' => $res['reverse']];
    }

    /**
     * Samovyměření DPH, které Pohoda vede interním dokladem (`DD…` - ř. 3–13) s vazbou
     * na přijatou fakturu (`linkedDocuments`, agenda `receivedInvoice`).
     *
     * @return array<string,array{doc:string,date:?string,lines:list<array{base:float,rate:float,code:string}>,error:?string}>
     */
    private function selfAssessments(PohodaContext $ctx): array
    {
        $out = [];
        foreach ($ctx->export->records('internal', 'intDoc') as $r) {
            $h = PohodaXml::get($r, 'intDocHeader');
            $headerCode = $ctx->vat->selfAssessmentCode(PohodaXml::text($h, 'classificationVAT/ids'));
            if ($headerCode === null) {
                continue;
            }
            $ref = '';
            foreach (PohodaXml::all($r, 'linkedDocuments/link') as $link) {
                if (PohodaXml::text($link, 'sourceAgenda') === 'receivedInvoice') {
                    $ref = PohodaXml::text($link, 'sourceDocument/number');
                }
            }
            $docNo = PohodaXml::text($h, 'number/numberRequested');
            $entry = ['doc' => $docNo, 'date' => PohodaXml::date($h, 'dateTax'), 'lines' => [], 'error' => null];
            foreach (PohodaXml::all($r, 'intDocDetail/intDocItem') as $it) {
                $code = $ctx->vat->selfAssessmentCode(PohodaXml::text($it, 'classificationVAT/ids')) ?? $headerCode;
                $rate = PohodaXml::attr($it, 'rateVAT', 'value');
                $entry['lines'][] = [
                    'base' => round(PohodaXml::num($it, 'homeCurrency/price'), 2),
                    'rate' => is_numeric($rate) ? (float) $rate : 21.0,
                    'code' => $code,
                ];
            }
            if ($entry['lines'] === []) {
                $summary = PohodaXml::get($r, 'intDocSummary/homeCurrency');
                foreach (self::BUCKETS as $bucket => $default) {
                    $base = round(PohodaXml::num($summary, 'price' . $bucket), 2);
                    $rate = PohodaXml::attr($summary, 'price' . $bucket . 'VAT', 'rate');
                    if ($base !== 0.0) {
                        $entry['lines'][] = ['base' => $base, 'rate' => is_numeric($rate) ? (float) $rate : $default, 'code' => $headerCode];
                    }
                }
            }
            if ($entry['lines'] === []) {
                $entry['error'] = 'interní doklad nemá základ daně';
            }
            if ($ref === '') {
                $ctx->protocol->warn(self::STEP_PURCHASE, 'self_assessment_without_invoice', "Interní doklad {$docNo} s vyměřením DPH nemá vazbu na přijatou fakturu. Jeho DPH doplňte ručně.", ['document_no' => $docNo]);
                continue;
            }
            $out[$ref] = $entry;
        }
        return $out;
    }

    /**
     * Položky faktury se samovyměřením = řádky interního dokladu (základ, sazba, kód
     * zařazení), daň je nulová - výstup i odpočet dopočte evidence DPH z kódu. Rozdíl
     * proti celku faktury (jiný kurz vyměření) zůstane jako položka bez DPH a bez kódu.
     *
     * @param array{lines:list<array{base:float,rate:float,code:string}>} $sa
     * @param array<string,mixed> $amounts
     * @return array<string,mixed>
     */
    private function applySelfAssessment(array $sa, array $amounts, string $taxDate): array
    {
        $items = [];
        $base = 0.0;
        foreach ($sa['lines'] as $line) {
            $items[] = [
                'description' => $amounts['items'][0]['description'] ?? 'Převzato z Pohody',
                'quantity' => 1.0, 'unit' => null, 'unit_price' => $line['base'],
                'base' => $line['base'], 'vat' => 0.0, 'rate' => $line['rate'],
                'rate_id' => $this->rateId($line['rate'], $taxDate), 'code' => $line['code'],
            ];
            $base += $line['base'];
        }
        $diff = round($amounts['total'] - $base, 2);
        if (abs($diff) >= 0.01) {
            $items[] = [
                'description' => 'Rozdíl proti základu samovyměření (kurz)', 'quantity' => 1.0, 'unit' => null, 'unit_price' => $diff,
                'base' => $diff, 'vat' => 0.0, 'rate' => 0.0, 'rate_id' => $this->rateId(0.0, $taxDate), 'code' => null,
            ];
        }
        $amounts['items'] = $items;
        $amounts['base'] = $amounts['total'];
        $amounts['vat'] = 0.0;
        $amounts['rounding'] = 0.0;
        return $amounts;
    }

    private function captureLiquidations(PohodaContext $ctx, string $docType, int $docId, string $number, array $r): void
    {
        foreach (PohodaXml::all($r, 'liquidations/liquidation') as $l) {
            $ctx->liquidations[] = [
                'doc' => $docType,
                'id' => $docId,
                'number' => $number,
                'agenda' => PohodaXml::text($l, 'sourceAgenda'),
                'source' => PohodaXml::text($l, 'sourceDocument/number'),
                'source_id' => PohodaXml::text($l, 'sourceDocument/id'),
                'date' => PohodaXml::date($l, 'date'),
                'amount' => PohodaXml::num($l, 'amount'),
                'liq' => PohodaXml::text($l, 'id') ?: substr(md5((string) json_encode($l)), 0, 12),
            ];
        }
    }

    /**
     * Úhrady s datem v roce, který se nepřevádí, v převodu nejsou - doklad podle nich nesmí
     * být uhrazený. Zbývá uhradit se o ně zvýší a datum úhrady je poslední převedené úhrady.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function withoutSkippedPayments(PohodaContext $ctx, array $doc, array $r): array
    {
        if ($ctx->skippedYears === []) {
            return $doc;
        }
        $skipped = 0.0;
        $last = null;
        foreach (PohodaXml::all($r, 'liquidations/liquidation') as $l) {
            $date = PohodaXml::date($l, 'date');
            if ($ctx->skipsDate($date)) {
                $skipped += PohodaXml::num($l, 'amount');
            } elseif ($date !== null && ($last === null || $date > $last)) {
                $last = $date;
            }
        }
        if (abs($skipped) < 0.005) {
            return $doc;
        }
        $remaining = is_numeric($doc['remaining']) ? (float) $doc['remaining'] : 0.0;
        $doc['remaining'] = number_format($remaining + $skipped, 2, '.', '');
        $doc['paid_date'] = $last;
        return $doc;
    }

    /**
     * Zbývá uhradit podle Pohody - podle ní páruje úhrady pohybů bez zápisu v deníku
     * {@see UnbookedBankPayments} (u přijaté faktury MyÚčto částečnou úhradu nevede).
     *
     * @param array<string,mixed> $doc
     */
    private static function rememberRemaining(PohodaContext $ctx, string $docType, int $id, array $doc): void
    {
        if (is_numeric($doc['remaining'])) {
            $ctx->remaining[$docType . '|' . $id] = round((float) $doc['remaining'], 2);
        }
    }

    private function remember(PohodaContext $ctx, string $bucket, string $number, int $id): void
    {
        match ($bucket) {
            'issued' => $ctx->issuedInvoices[$number] = $id,
            'receivable' => $ctx->receivables[$number] = $id,
            'internal_sale' => $ctx->internalSales[$number] = $id,
            'purchase' => $ctx->purchaseInvoices[$number] = $id,
            'commitment' => $ctx->commitments[$number] = $id,
            'internal_purchase' => $ctx->internalPurchases[$number] = $id,
        };
    }

    /** @param array<string,mixed> $doc */
    private function isPreviousPeriod(PohodaContext $ctx, array $doc): bool
    {
        $start = $ctx->period['starts_on'] ?? sprintf('%04d-01-01', $ctx->year());
        return (string) $doc['accounting'] < $start;
    }

    /**
     * Číslo dokladu, které ve firmě ještě není; obsazené dostane příponu roku.
     *
     * @return array{number:string,suffixed:bool}|null
     */
    private function freeNumber(string $table, int $supplierId, string $docNo, int $year): ?array
    {
        $stmt = $this->stmt('free_' . $table, "SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
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
    private function reportNumberAndReview(PohodaContext $ctx, string $step, string $docNo, array $number, array $reasons): void
    {
        $p = $ctx->protocol;
        if ($number['suffixed']) {
            $p->count($step, 'suffixed');
            $p->info($step, 'number_suffixed', "Doklad {$docNo}: číslo už ve firmě je, převzat jako {$number['number']}.", ['document_no' => $docNo]);
        }
        if ($reasons !== []) {
            $p->count($step, 'review');
            $p->warn($step, 'needs_review', "Doklad {$docNo} převzat jako koncept k ruční kontrole: " . implode('; ', $reasons)
                . '. Do DPH ani do účtování nevstoupí, dokud ho neopravíte a nepotvrdíte.', ['document_no' => $docNo, 'reasons' => $reasons]);
        }
    }

    /**
     * Opakovaný převod už převedený doklad NEPŘEPISUJE (mohl být mezitím upravený nebo
     * spárovaný). Liší-li se v Pohodě, protokol to řekne.
     */
    private function reportChanged(PohodaContext $ctx, string $step, string $table, int $id, string $docNo, array $r, string $prefix): void
    {
        $summary = PohodaXml::get($r, $prefix . 'Summary/homeCurrency');
        $pohoda = round(PohodaXml::num($summary, 'priceNone') + PohodaXml::num($summary, 'priceLowSum')
            + PohodaXml::num($summary, 'priceHighSum') + PohodaXml::num($summary, 'price3Sum') + PohodaXml::num($summary, 'round/priceRound'), 2);
        foreach (PohodaXml::all($r, $prefix . 'Detail/invoiceAdvancePaymentItem') as $a) {
            if (abs(PohodaXml::num($a, 'homeCurrency/priceVAT')) >= 0.005) {
                $pohoda = round($pohoda + PohodaXml::num($a, 'homeCurrency/priceSum'), 2);
            }
        }
        $stmt = $this->stmt('changed_' . $table, "SELECT total_with_vat FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false || abs((float) $stored - $pohoda) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_pohoda', sprintf(
            'Doklad %s se v Pohodě od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $docNo, number_format((float) $stored, 2, ',', ' '), number_format($pohoda, 2, ',', ' ')
        ), ['document_no' => $docNo, 'id' => $id]);
    }

    /**
     * @param list<string> $reasons
     * @param list<string> $notes
     */
    private static function note(string $docNo, array $reasons, bool $previous, string $foreign, array $notes = []): string
    {
        $note = 'Převzato z Pohody, doklad ' . $docNo;
        if ($previous) {
            $note .= ' (doklad minulého období, zůstatek je v počátečních stavech)';
        }
        if ($foreign !== '' && $foreign !== 'CZK') {
            $note .= '; doklad v ' . $foreign . ', převzat v Kč';
        }
        foreach ($notes as $n) {
            $note .= '; ' . $n;
        }
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . implode('; ', $reasons) . '.';
    }

    /** @return array{0:\PDOStatement,1:\PDOStatement} */
    private function issuedStatements(): array
    {
        return [
            $this->stmt('issued', 'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, varsymbol, payment_variable_symbol, issue_date, tax_date, due_date, currency_id,
                 note_above_items, note_below_items, client_snapshot, total_without_vat, total_vat, total_with_vat,
                 rounding, advance_paid_amount, paid_total, paid_at, status, booked_at, booked_by,
                 vat_classification_code, payment_method, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'),
            $this->stmt('issued_item', 'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code,
                 oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type, oss_needs_manual_review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'),
        ];
    }

    /** @return array{0:\PDOStatement,1:\PDOStatement} */
    private function purchaseStatements(): array
    {
        return [
            $this->stmt('purchase', 'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, received_at_source, currency_id, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, advance_paid_amount,
                 payment_variable_symbol, payment_constant_symbol, payment_account_number, payment_bank_code,
                 payment_method, status, paid_at, booked_at, booked_by, note_above_items, note_below_items,
                 vat_deduction, vat_classification_code, reverse_charge, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'),
            $this->stmt('purchase_item', 'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'),
        ];
    }

    private function stmt(string $key, string $sql): \PDOStatement
    {
        return $this->stmts[$key] ??= $this->db->pdo()->prepare($sql);
    }

    /**
     * Tuzemská sazba firmy. `$required = false` vrací `null` místo výjimky - volající, který
     * umí doklad přeskočit, si o něm řekne v protokolu sám.
     */
    private function rateId(float $rate, string $date, bool $required = true): ?int
    {
        $cacheKey = number_format($rate, 2, '.', '') . '|' . $date;
        if (array_key_exists($cacheKey, $this->rateCache) && ($this->rateCache[$cacheKey] !== null || !$required)) {
            return $this->rateCache[$cacheKey];
        }
        $stmt = $this->stmt('rate', "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND rate_percent = ? AND is_reverse_charge = 0
              ORDER BY ((valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)) DESC,
                       is_default DESC, id
              LIMIT 1");
        $stmt->execute([number_format($rate, 2, '.', ''), $date, $date]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $this->rateCache[$cacheKey] = null;
            if (!$required) {
                return null;
            }
            throw new PohodaException('unknown_vat_rate', 'Sazba DPH ' . $rate . ' % není v číselníku sazeb.');
        }
        return $this->rateCache[$cacheKey] = (int) $id;
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
