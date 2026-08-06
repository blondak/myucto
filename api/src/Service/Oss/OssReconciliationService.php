<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Repository\TaxSubmissionRepository;

/**
 * Rekonciliace OSS: „shoduje se dnešní náhled s tím, co je v archivu podání?"
 *
 * ── Proti čemu tahle kontrola stojí ────────────────────────────────────────────────
 * OSS přiznání se podává čtvrtletně a doklady se opravují průběžně. Nic dnes nebrání
 * tomu, aby se doklad s DUZP v Q1 opravil v Q3 — a protože OSS podání není v aplikaci
 * nijak svázané s daty, ze kterých vzniklo, projde taková oprava beze slova. Rozdíl se
 * pak najde až při kontrole ve státě spotřeby, kde ho platí dodavatel. Rekonciliace je
 * jediné místo, kde se to dá zachytit VČAS, protože porovnává archivovaný obraz podání
 * s tím, co by se za totéž období podalo dnes.
 *
 * ── Stažený soubor není podání ─────────────────────────────────────────────────────
 * OSS se dnes umí jen stáhnout, takže archiv drží snapshoty ve stavu `downloaded`.
 * Ty se jako reference použijí (jinak by rekonciliace neměla nikdy co porovnávat), ale
 * odpověď VŽDY nese `basis.status` a `basis.is_proven_filing` — a UI to musí zobrazit.
 * Manuál (kap. 68.1) tenhle rozdíl staví jako první věc celé kapitoly a rekonciliace ho
 * nesmí smazat tím, že „něco v archivu je".
 *
 * ── Bez snapshotu se nehádá ────────────────────────────────────────────────────────
 * Archivy vzniklé před zavedením {@see OssFilingSnapshot} nesou v `summary` jen agregáty
 * bez dokladů. Porovnat je nelze a odpověď to přizná (`snapshot_available=false`) místo
 * aby vrátila „souhlasí". Tichá shoda je horší než přiznaná neznalost: uživatel by na ni
 * spoléhal přesně v situaci, kdy ho má varovat.
 */
final class OssReconciliationService
{
    public const FORM_CODE = 'ossei1';

    public function __construct(
        private readonly OssLedgerService $ledger,
        private readonly OssFilingSnapshot $snapshot,
        private readonly TaxSubmissionRepository $submissions,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function reconcile(int $supplierId, int $year, int $quarter): array
    {
        [$start, $end] = OssPeriod::range($year, $quarter);
        $period = [
            'year'    => $year,
            'quarter' => $quarter,
            'start'   => $start,
            'end'     => $end,
            'label'   => 'Q' . $quarter . ' ' . $year,
        ];

        $filing = $this->submissions->findLatestArchivedForPeriod(
            $supplierId,
            self::FORM_CODE,
            $year,
            null,
            $quarter,
        );
        if ($filing === null) {
            return [
                'period'             => $period,
                'has_filing'         => false,
                'snapshot_available' => false,
                'basis'              => null,
                'in_sync'            => null,
                'differences'        => self::emptyDifferences(),
            ];
        }

        $status = (string) ($filing['status'] ?? 'generated');
        $basis = [
            'submission_id'     => (int) $filing['id'],
            'status'            => $status,
            'is_proven_filing'  => in_array($status, ['submitted', 'accepted'], true),
            'form_variant'      => (string) ($filing['form_variant'] ?? 'B'),
            'validation_status' => (string) ($filing['validation_status'] ?? 'skipped'),
            'generated_at'      => (string) $filing['generated_at'],
            'submitted_at'      => $filing['submitted_at'] !== null ? (string) $filing['submitted_at'] : null,
            'submission_ref'    => $filing['submission_ref'] !== null ? (string) $filing['submission_ref'] : null,
            'xml_sha256'        => (string) $filing['xml_sha256'],
            'totals'            => [
                'base'        => self::money($filing['summary']['total_base'] ?? null),
                'vat'         => self::money($filing['summary']['total_vat'] ?? null),
                'corrections' => self::money($filing['summary']['total_corrections'] ?? null),
                'payable'     => self::money($filing['summary']['total_payable'] ?? null),
            ],
        ];

        $filed = $filing['summary']['snapshot'] ?? null;
        if (!OssFilingSnapshot::isUsable($filed)) {
            return [
                'period'             => $period,
                'has_filing'         => true,
                'snapshot_available' => false,
                'basis'              => $basis,
                'in_sync'            => null,
                'differences'        => self::emptyDifferences(),
            ];
        }

        $current = $this->snapshot->fromPreview($supplierId, $this->ledger->preview($supplierId, $year, $quarter));
        $diff = OssFilingSnapshot::diff((array) $filed, $current);

        return [
            'period'             => $period,
            'has_filing'         => true,
            'snapshot_available' => true,
            'basis'              => $basis + [
                'fingerprint' => OssFilingSnapshot::fingerprint((array) $filed),
            ],
            'current'            => [
                'return_currency' => (string) $current['return_currency'],
                'totals'          => $current['totals'],
                'fingerprint'     => OssFilingSnapshot::fingerprint($current),
            ],
            'in_sync'            => $diff['in_sync'],
            'differences'        => [
                'totals'      => $diff['totals'],
                'rows'        => $diff['rows'],
                'corrections' => $diff['corrections'],
                'documents'   => $diff['documents'],
            ],
        ];
    }

    /**
     * @return array{totals:list<array<string,mixed>>, rows:list<array<string,mixed>>,
     *               corrections:list<array<string,mixed>>, documents:list<array<string,mixed>>}
     */
    private static function emptyDifferences(): array
    {
        return ['totals' => [], 'rows' => [], 'corrections' => [], 'documents' => []];
    }

    private static function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
