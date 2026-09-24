<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

final class InvoiceKhSections
{
    public function __construct(
        private readonly DphBookBuilder $book,
        private readonly VatLedgerService $ledger,
    ) {}

    /** @param array<int,array<string,mixed>> $groups */
    public function addToGroups(int $supplierId, array &$groups, string $direction): void
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group['invoices'] as $invoice) {
                $ids[] = (int) $invoice['id'];
            }
        }
        if ($ids === []) return;

        $claimInfo = $direction === 'received' ? $this->ledger->purchaseClaimInfo($supplierId, $ids) : [];
        $periods = [];
        foreach ($groups as $group) {
            foreach ($group['invoices'] as $invoice) {
                $id = (int) $invoice['id'];
                $date = $direction === 'received'
                    ? ($claimInfo[$id]['claim_date'] ?? '')
                    : ($invoice['month_bucket'] ?? '');
                $period = substr((string) $date, 0, 7);
                if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
                    $periods[$period][$id] = true;
                }
            }
        }

        $sectionsById = [];
        foreach ($periods as $period => $periodIds) {
            $book = $this->book->build($supplierId, (int) substr($period, 0, 4), (int) substr($period, 5, 2));
            foreach ($book['sections'] as $section) {
                foreach ($section['rows'] as $row) {
                    $id = (int) ($row['invoice_id'] ?? 0);
                    $kh = (string) ($row['kh_section'] ?? '');
                    if (!isset($periodIds[$id]) || $kh === '' || ($row['direction'] ?? '') !== $direction
                        || ($row['document_kind'] ?? '') === 'cash') continue;
                    $sectionsById[$id][$kh] = true;
                }
            }
        }

        foreach ($groups as &$group) {
            foreach ($group['invoices'] as &$invoice) {
                $sections = array_keys($sectionsById[(int) $invoice['id']] ?? []);
                sort($sections, SORT_NATURAL);
                $invoice['kh_sections'] = $sections;
            }
            unset($invoice);
        }
        unset($group);
    }
}
