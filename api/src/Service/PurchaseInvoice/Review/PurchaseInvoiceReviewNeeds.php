<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Review;

/**
 * Proč přijatý doklad potřebuje kontrolu v okně Kontrola vytěžených dokladů.
 *
 * Jediné místo, které o tom rozhoduje — okno kontroly (všech pět míst, odkud se
 * otevírá) i seznam přijatých dokladů čtou výsledek odsud jako `review`:
 *   • `extraction_warning` — hlášení vytěžení (rozdíl součtů, návrh druhu nákladu…),
 *   • `missing_required_dimension` — chybí dimenze povinná podle pravidla
 *     ({@see RequiredDimensionReviewCheck}).
 * Další důvod (např. dimenze se schvalováním) přidá novou {@see PurchaseReviewCheck}
 * do `$checks`.
 */
final class PurchaseInvoiceReviewNeeds
{
    public const EXTRACTION_WARNING = 'extraction_warning';

    /** @var list<PurchaseReviewCheck> */
    private array $checks;

    public function __construct(RequiredDimensionReviewCheck $requiredDimensions)
    {
        $this->checks = [$requiredDimensions];
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array{reasons:list<string>, details:array<string,array<string,mixed>>}
     */
    public function forInvoice(int $supplierId, array $invoice): array
    {
        $reasons = [];
        $details = [];
        if (trim((string) ($invoice['extraction_warning'] ?? '')) !== '') {
            $reasons[] = self::EXTRACTION_WARNING;
        }
        foreach ($this->checks as $check) {
            $detail = $check->evaluate($supplierId, $invoice);
            if ($detail !== null) {
                $reasons[] = $check->reason();
                $details[$check->reason()] = $detail;
            }
        }
        return ['reasons' => $reasons, 'details' => $details];
    }

    /**
     * Pro řádky seznamu. Kontrolují se jen koncepty — seznam má až stovky řádků a
     * u přijatého dokladu se chybějící dimenze ozve nejpozději při zaúčtování; detail
     * dokladu ({@see forInvoice()}) kontroluje každý nezaúčtovaný doklad.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,array{reasons:list<string>, details:array<string,array<string,mixed>>}>
     */
    public function forListRows(int $supplierId, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ((string) ($row['status'] ?? '') !== 'draft') {
                $out[$id] = [
                    'reasons' => trim((string) ($row['extraction_warning'] ?? '')) !== '' ? [self::EXTRACTION_WARNING] : [],
                    'details' => [],
                ];
                continue;
            }
            $out[$id] = $this->forInvoice($supplierId, $row);
        }
        return $out;
    }
}
