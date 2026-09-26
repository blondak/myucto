<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;

/**
 * Zdravotní pojišťovna osoby od daného měsíce — jediná zapisovací cesta mimo
 * editor karty.
 *
 * Zapisuje se přes zákonnou evidenci osoby (tentýž validátor, zmrazení období,
 * souvislost řady a activity log jako karta). Věta, která k měsíci platí, se
 * buď opraví (začíná týmž měsícem nebo později), nebo ukončí a naváže se nová.
 * Když osoba žádnou větu nemá, založí se první od zadaného měsíce. Věta, která
 * k měsíci neplatí a navazuje jinam, se nepřepisuje — to rozhodne člověk na kartě.
 *
 * Používá ji import registrací (REGZEC/PREZEC nese kód pojišťovny) i hromadné
 * zadání pojišťoven po importu hlášení JMHZ, které kód pojišťovny nenese.
 */
final class PayrollHealthInsurerWriter
{
    private const COVERAGE_FIELDS = [
        'jurisdiction',
        'foreign_country_code',
        'jurisdiction_evidence_reference',
        'insurer_status',
        'insurer_code',
        'insurer_evidence_reference',
        'health_evidence_document_id',
    ];

    public function __construct(
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
    ) {}

    public function assign(
        int $supplierId,
        int $employeeId,
        string $insurerCode,
        string $onDate,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $monthStart = substr($onDate, 0, 7) . '-01';
        $view = $this->statutory->editorView($supplierId, $employeeId, $onDate)
            ?? throw new \DomainException('Zákonná evidence zaměstnance nebyla nalezena.');
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        $rows = $sections['health_coverages'];
        $covering = self::covering($rows, $monthStart);
        if ($covering === null) {
            if ($rows !== []) {
                throw new \DomainException(
                    "Zdravotní pojištění k {$monthStart} v evidenci vedené není a navazuje na jiné období. "
                    . 'Zapište pojišťovnu ručně v zákonné evidenci osoby.',
                );
            }
            $rows[] = [
                'jurisdiction' => 'czech_regime_verified',
                'foreign_country_code' => null,
                'jurisdiction_evidence_reference' => null,
                'insurer_status' => 'verified',
                'insurer_code' => $insurerCode,
                'insurer_evidence_reference' => null,
                'health_evidence_document_id' => null,
                'effective_from' => $monthStart,
                'effective_to' => null,
                'evidence_note' => null,
            ];
        } elseif ((string) $covering['effective_from'] >= $monthStart) {
            foreach ($rows as $index => $row) {
                if ((int) $row['id'] === (int) $covering['id']) {
                    $rows[$index]['insurer_code'] = $insurerCode;
                    $rows[$index]['insurer_status'] = 'verified';
                }
            }
        } else {
            $next = ['id' => null];
            foreach (self::COVERAGE_FIELDS as $field) {
                $next[$field] = $covering[$field] ?? null;
            }
            $next['insurer_code'] = $insurerCode;
            $next['insurer_status'] = 'verified';
            $next['insurer_evidence_reference'] = null;
            $next['health_evidence_document_id'] = null;
            $next['effective_from'] = $monthStart;
            $next['effective_to'] = $covering['effective_to'];
            $next['evidence_note'] = null;
            foreach ($rows as $index => $row) {
                if ((int) $row['id'] === (int) $covering['id']) {
                    $rows[$index]['effective_to'] = (new \DateTimeImmutable($monthStart))->modify('-1 day')->format('Y-m-d');
                }
            }
            $rows[] = $next;
        }
        $sections['health_coverages'] = $rows;

        $this->statutory->save(
            $supplierId,
            $employeeId,
            ['sections' => $sections],
            $onDate,
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private static function covering(array $rows, string $onDate): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            $from = (string) $row['effective_from'];
            $to = ($row['effective_to'] ?? null) === null ? null : (string) $row['effective_to'];
            if ($from > $onDate || ($to !== null && $to < $onDate)) {
                continue;
            }
            if ($best === null || $from > (string) $best['effective_from']) {
                $best = $row;
            }
        }

        return $best;
    }
}
