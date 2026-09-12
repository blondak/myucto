<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Pdf\TaxReturnReportPdfRenderer;

/**
 * Pracovní PDF sestava přiznání k dani z příjmů (DPPO i DPFO) pro kontrolu s účetní
 * a archiv. Není podáním pro finanční úřad.
 *
 * Data bere výhradně z {@see TaxReturnService::reportSource()}, tedy ze stejného XML
 * a výpočtu jako export pro EPO; {@see TaxReturnReportBuilder} z XML jen čte. Z definic
 * výkazů se doplňují pouze popisky a úrovně řádků rozvahy a VZZ (čísla jsou z XML).
 */
final class TaxReturnReportService
{
    public function __construct(
        private readonly TaxReturnService $returns,
        private readonly StatementDefinitionRepository $statements,
        private readonly TaxReturnReportBuilder $builder,
        private readonly TaxReturnReportPdfRenderer $renderer,
    ) {}

    /** @return array<string,mixed> strukturovaná data sestavy */
    public function data(int $supplierId, int $year, string $type, string $variant = 'radne', int $variantSeq = 1): array
    {
        $source = $this->returns->reportSource($supplierId, $year, $type, $variant, $variantSeq);
        $report = $this->builder->build($source, $type === 'po' ? $this->statementLabels($source) : []);
        $report['variant'] = (string) $source['variant'];
        $report['variant_seq'] = (int) $source['variant_seq'];
        return $report;
    }

    /** @return array{pdf:string, filename:string, report:array<string,mixed>} */
    public function pdf(int $supplierId, int $year, string $type, string $variant = 'radne', int $variantSeq = 1): array
    {
        $report = $this->data($supplierId, $year, $type, $variant, $variantSeq);
        $seq = (int) $report['variant_seq'];
        $suffix = $variant === 'radne' ? '' : '-' . $variant . ($variant === 'dodatecne' && $seq > 1 ? '-' . $seq : '');

        return [
            'pdf' => $this->renderer->render($report),
            'filename' => sprintf('%s-%04d%s-sestava.pdf', strtolower((string) $report['form_code']), $year, $suffix),
            'report' => $report,
        ];
    }

    /**
     * Popisky řádků výkazů pro verzi platnou ke konci zdaňovacího období, stejně jako
     * je vybírá {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService}.
     *
     * @param array<string,mixed> $source
     * @return array<string,array<string,array{label:string,level:int,row_type:string}>>
     */
    private function statementLabels(array $source): array
    {
        $endsOn = (string) ($source['computation']['podklady']['period']['ends_on'] ?? '');
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}/', $endsOn) === 1
            ? substr($endsOn, 0, 10)
            : sprintf('%04d-12-31', (int) $source['year']);

        $out = [];
        foreach (['balance_sheet', 'income_statement'] as $statementType) {
            $version = $this->statements->findVersion($statementType, $asOf);
            if ($version === null) {
                continue;
            }
            foreach ($this->statements->rows((int) $version['id']) as $row) {
                $out[$statementType][(string) $row['row_code']] = [
                    'label' => (string) $row['label'],
                    'level' => (int) $row['level'],
                    'row_type' => (string) $row['row_type'],
                ];
            }
        }
        return $out;
    }
}
