<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AiExpenseKindProposal;
use PDO;

/**
 * Hlášení „AI extrakce vyžaduje kontrolu" mizí po částech, jak uživatel body řeší.
 *
 * Hlášení (`purchase_invoices.extraction_warning`) je řada sekcí oddělených prázdným
 * řádkem (tak je skládá {@see \MyInvoice\Repository\PurchaseInvoiceRepository::appendExtractionWarning()}).
 *
 *   - Sekci s návrhy druhu nákladu umí stroj vyhodnotit sám: odrážka řádku, který už
 *     druh nákladu má, zmizí ({@see afterItemsChanged()}), s poslední i celá sekce.
 *   - Ostatní sekce (reverse charge, nesedící součty, …) odstraní až uživatel, když
 *     bod zkontroluje ({@see removeSection()}).
 *
 * Když nezbude žádná sekce, hlášení i podklady (`extraction_review`) se vynulují
 * a doklad přestane být „ke kontrole".
 */
final class ExtractionReviewSync
{
    public function __construct(private readonly Connection $db) {}

    /** Po změně položek (druh nákladu, přeuložení v editoru) odebere odrážky vyřešených řádků. */
    public function afterItemsChanged(int $supplierId, int $invoiceId): void
    {
        $state = $this->load($supplierId, $invoiceId);
        if ($state === null) {
            return;
        }
        [$warning, $review] = $state;
        $entries = (array) ($review['expense_kinds'] ?? []);
        if ($entries === []) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT order_index, description, expense_kind FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[(int) $r['order_index']] = $r;
        }

        $open = array_values(array_filter($entries, static fn (array $e): bool =>
            isset($rows[(int) $e['order_index']]) && ($rows[(int) $e['order_index']]['expense_kind'] ?? null) === null));
        if (count($open) === count($entries)) {
            return;
        }

        $descriptions = array_map(static fn (array $r): string => (string) $r['description'], $rows);
        $section = AiExpenseKindProposal::warningTextFromReview($open, $descriptions);
        $sections = [];
        foreach (self::sections($warning) as $s) {
            if (self::isExpenseKindSection($s)) {
                if ($section !== null) {
                    $sections[] = $section;
                }
                continue;
            }
            $sections[] = $s;
        }
        $review['expense_kinds'] = $open;
        $this->save($supplierId, $invoiceId, $sections, $review);
    }

    /**
     * Odstraní jednu sekci hlášení (uživatel ji zkontroloval). Vrací false, když taková
     * sekce v hlášení není (mezitím zmizela), a nic nemění.
     */
    public function removeSection(int $supplierId, int $invoiceId, string $section): bool
    {
        $state = $this->load($supplierId, $invoiceId);
        if ($state === null) {
            return false;
        }
        [$warning, $review] = $state;
        $needle = self::normalize($section);
        $kept = [];
        $found = false;
        foreach (self::sections($warning) as $s) {
            if (!$found && self::normalize($s) === $needle) {
                $found = true;
                if (self::isExpenseKindSection($s)) {
                    unset($review['expense_kinds']);
                }
                continue;
            }
            $kept[] = $s;
        }
        if ($found) {
            $this->save($supplierId, $invoiceId, $kept, $review);
        }
        return $found;
    }

    /** @return list<string> */
    public static function sections(?string $warning): array
    {
        $parts = preg_split('/\R\s*\R/u', trim((string) $warning)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    private static function isExpenseKindSection(string $section): bool
    {
        return str_starts_with($section, AiExpenseKindProposal::WARNING_HEADER_PREFIX);
    }

    /** Porovnání sekce z frontendu: konce řádků a okrajové mezery se mohly cestou změnit. */
    private static function normalize(string $s): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($s));
    }

    /** @return array{0:string, 1:array<string,mixed>}|null */
    private function load(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT extraction_warning, extraction_review FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || trim((string) ($row['extraction_warning'] ?? '')) === '') {
            return null;
        }
        $review = is_string($row['extraction_review'] ?? null) ? json_decode($row['extraction_review'], true) : null;
        return [(string) $row['extraction_warning'], is_array($review) ? $review : []];
    }

    /**
     * @param list<string>         $sections
     * @param array<string,mixed>  $review
     */
    private function save(int $supplierId, int $invoiceId, array $sections, array $review): void
    {
        if (($review['expense_kinds'] ?? []) === []) {
            unset($review['expense_kinds']);
        }
        $warning = $sections === [] ? null : implode("\n\n", $sections);
        $reviewJson = ($warning === null || $review === [])
            ? null
            : json_encode($review, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET extraction_warning = ?, extraction_review = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$warning, $reviewJson, $invoiceId, $supplierId]);
    }
}
