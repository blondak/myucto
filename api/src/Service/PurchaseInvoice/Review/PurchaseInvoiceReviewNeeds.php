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
        $id = (int) ($invoice['id'] ?? 0);
        return $this->forRows($supplierId, [$invoice])[$id]
            ?? ['reasons' => [], 'details' => []];
    }

    /**
     * Pro řádky seznamu. Chybějící povinná dimenze se počítá jen u konceptů — seznam má
     * až stovky řádků a u přijatého dokladu se ozve nejpozději při zaúčtování; detail
     * dokladu ({@see forInvoice()}) kontroluje každý nezaúčtovaný doklad.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,array{reasons:list<string>, details:array<string,array<string,mixed>>}>
     */
    public function forListRows(int $supplierId, array $rows): array
    {
        $drafts = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === 'draft'));
        return $this->forRows($supplierId, $rows, $drafts);
    }

    /**
     * Tvar pro JSON: `details` je vždy objekt, i když je prázdný.
     *
     * @param array{reasons:list<string>, details:array<string,array<string,mixed>>} $review
     * @return array{reasons:list<string>, details:object}
     */
    public static function toApi(array $review): array
    {
        return ['reasons' => $review['reasons'], 'details' => (object) $review['details']];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>>|null $checked řádky, na které se pustí kontroly (null = všechny)
     * @return array<int,array{reasons:list<string>, details:array<string,array<string,mixed>>}>
     */
    private function forRows(int $supplierId, array $rows, ?array $checked = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = [
                'reasons' => trim((string) ($row['extraction_warning'] ?? '')) !== '' ? [self::EXTRACTION_WARNING] : [],
                'details' => [],
            ];
        }
        foreach ($this->checks as $check) {
            foreach ($check->evaluateMany($supplierId, $checked ?? $rows) as $id => $detail) {
                if (isset($out[$id])) {
                    $out[$id]['reasons'][] = $check->reason();
                    $out[$id]['details'][$check->reason()] = $detail;
                }
            }
        }
        return $out;
    }
}
