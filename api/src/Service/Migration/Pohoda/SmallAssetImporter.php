<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\SmallAssetCard;

/**
 * Drobný majetek z operativní evidence POHODY (tabulka `DM` v `90_majetek.xml`) do evidence
 * drobného majetku v MyÚčtu. Karta nic neúčtuje - náklad je v převedeném deníku.
 *
 * Zdrojový doklad karty dohledá už exportní nástroj (`DM.RelAgID` je kód agendy: 2 faktury,
 * 27 pokladna, 29 interní doklady; `DM.RefPol` záznam v ní) a přidá ke kartě `SrcAgenda`,
 * `SrcCislo`, `SrcText` a `SrcKc`. Převod podle čísla najde převedenou přijatou fakturu
 * (i ostatní závazek), pokladní nebo interní doklad a u faktury i položku - podle textu,
 * jinak podle částky, vždy jen jednoznačně. Číslo dokladu zůstane na kartě i tehdy, když
 * doklad v převodu není (minulý rok).
 *
 * Karta bez odkazu na doklad (v POHODĚ běžné) se naváže na jedinou dosud volnou položku
 * přijaté faktury se stejným datem a stejnou částkou bez DPH; jinak zůstane bez dokladu.
 */
final class SmallAssetImporter
{
    public const STEP = 'small_assets';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly SmallAssetService $cards,
    ) {}

    public function import(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->export->path('assets') === null) {
            return;
        }
        $places = [];
        foreach ($ctx->export->records('assets', 'IMmist') as $row) {
            $places[PohodaXml::text($row, 'ID')] = PohodaXml::text($row, 'SText');
        }
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_SMALL_ASSET);

        foreach ($ctx->export->records('assets', 'DM') as $row) {
            $number = PohodaXml::text($row, 'Cislo');
            $key = 'small_asset|' . ($number !== '' ? $number : '#' . PohodaXml::text($row, 'ID'));
            if (isset($existing[$key])) {
                $p->count(self::STEP, 'existing');
                continue;
            }
            $date = PohodaXml::date($row, 'Datum');
            $name = PohodaXml::text($row, 'SText') ?: $number;
            if ($date === null || $name === '') {
                $p->warn(self::STEP, 'small_asset_incomplete', "Karta drobného majetku {$number} nemá datum nebo název, nepřevzata.", ['document_no' => $number]);
                continue;
            }
            $quantity = max(1, (int) PohodaXml::num($row, 'Pocet'));
            $price = round(PohodaXml::num($row, 'Kc'), 2);
            $unitPrice = round(PohodaXml::num($row, 'KcJedn'), 2);
            $disposed = PohodaXml::date($row, 'DatLikv');
            $vendor = PohodaXml::text($row, 'Firma');
            $note = PohodaXml::text($row, 'Pozn');
            $data = SmallAssetCard::payload(
                // Typ drobného majetku (`DM.RelTpDM`) převod nerozlišuje - kódy nejsou ověřené.
                'tangible',
                $name,
                SmallAssetCard::inventoryNumber($number, false),
                $date,
                $date,
                $quantity,
                $unitPrice > 0 ? $unitPrice : round($price / $quantity, 2),
                $price,
                $places[PohodaXml::text($row, 'RefIMmist')] ?? '',
                $disposed,
                'Vyřazeno v POHODĚ',
                $note !== '' ? $note : null,
                ['vendor_name' => $vendor !== '' ? mb_substr($vendor, 0, 255) : null],
            ) + $this->source($ctx, $row);
            if (!isset($data['purchase_invoice_id']) && !isset($data['cash_document_id']) && PohodaXml::text($row, 'SrcAgenda') === '') {
                // POHODA vazbu karty na doklad často nedrží - náhradou jediná volná položka
                // přijaté faktury se stejným datem a částkou bez DPH.
                $match = $this->matchByDateAndAmount($ctx->supplierId, $date, $price);
                if ($match !== null) {
                    $data += $match;
                    $p->count(self::STEP, 'matched_by_amount');
                }
            }

            try {
                $id = $this->cards->create($ctx->supplierId, $data, $ctx->userOrNull());
            } catch (PostingException $e) {
                $p->warn(self::STEP, 'small_asset_rejected', "Karta drobného majetku {$number} nepřevzata: " . $e->getMessage(), ['document_no' => $number]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_SMALL_ASSET, $key, $id, $ctx->runId);
            $p->count(self::STEP, 'created');
            $p->count(self::STEP, isset($data['purchase_invoice_id']) || isset($data['cash_document_id']) ? 'linked' : 'no_document');
        }
    }

    /**
     * Vazba karty na převedený doklad.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function source(PohodaContext $ctx, array $row): array
    {
        $agenda = PohodaXml::text($row, 'SrcAgenda');
        $number = PohodaXml::text($row, 'SrcCislo');
        if ($agenda === '' || $number === '') {
            return [];
        }
        $out = ['document_ref' => mb_substr($number, 0, 60)];
        if ($agenda === 'HO') {
            if (isset($ctx->cashDocuments[$number])) {
                $out['cash_document_id'] = $ctx->cashDocuments[$number];
            }
            return $out;
        }
        $invoiceId = $agenda === 'FA'
            ? ($ctx->purchaseInvoices[$number] ?? $ctx->commitments[$number] ?? null)
            : ($agenda === 'INT' ? ($ctx->internalPurchases[$number] ?? null) : null);
        if ($invoiceId === null) {
            return $out;
        }
        $out['purchase_invoice_id'] = $invoiceId;
        $item = $this->matchItem($invoiceId, PohodaXml::text($row, 'SrcText'), PohodaXml::num($row, 'SrcKc'));
        if ($item !== null) {
            $out['purchase_invoice_item_id'] = $item;
        }
        return $out;
    }

    /**
     * Jediná položka přijaté faktury s datem vystavení nebo DUZP rovným datu karty a částkou
     * bez DPH rovnou ceně karty, kterou ještě žádná karta nedrží.
     *
     * @return array{purchase_invoice_id:int,purchase_invoice_item_id:int,document_ref:string}|null
     */
    private function matchByDateAndAmount(int $supplierId, string $date, float $price): ?array
    {
        if ($price <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pi.id AS invoice_id, pi.vendor_invoice_number
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pi.supplier_id = ? AND (pi.issue_date = ? OR pi.tax_date = ?)
                AND ABS(' . ForeignCurrencyTakeover::homeAmountSql('pii.total_without_vat', 'pi.exchange_rate') . ' - ?) < 0.005
                AND NOT EXISTS (SELECT 1 FROM small_assets s WHERE s.supplier_id = pi.supplier_id AND s.purchase_invoice_item_id = pii.id)
              LIMIT 2'
        );
        $stmt->execute([$supplierId, $date, $date, $price]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }
        return [
            'purchase_invoice_id' => (int) $rows[0]['invoice_id'],
            'purchase_invoice_item_id' => (int) $rows[0]['id'],
            'document_ref' => mb_substr((string) $rows[0]['vendor_invoice_number'], 0, 60),
        ];
    }

    /**
     * Položka faktury: jediná se shodným textem, jinak jediná se shodnou částkou bez DPH.
     * Cena karty je v Kč, položka dokladu v cizí měně se porovná přepočtená kurzem dokladu.
     */
    private function matchItem(int $invoiceId, string $text, float $amount): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT pii.id, pii.description, '
            . ForeignCurrencyTakeover::homeAmountSql('pii.total_without_vat', 'pi.exchange_rate') . ' AS total_without_vat
               FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? ORDER BY pii.order_index, pii.id');
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $normalize = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $byText = $text === '' ? [] : array_values(array_filter($items, static fn (array $i): bool => $normalize((string) $i['description']) === $normalize($text)));
        if (count($byText) === 1) {
            return (int) $byText[0]['id'];
        }
        $pool = $byText !== [] ? $byText : $items;
        $byAmount = array_values(array_filter($pool, static fn (array $i): bool => abs((float) $i['total_without_vat'] - $amount) < 0.005));
        return count($byAmount) === 1 ? (int) $byAmount[0]['id'] : null;
    }
}
