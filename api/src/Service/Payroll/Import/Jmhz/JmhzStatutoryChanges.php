<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Co se z hlášení propíše do zákonné evidence osoby — prohlášení poplatníka,
 * nepřenositelné slevy a slevu pracujícího důchodce.
 *
 * Náhled i zápis volají TYTÉŽ funkce nad sekcemi z `editorView()`, takže
 * náhled nemůže slíbit něco jiného, než zápis udělá. Každá funkce vrací cílový
 * stav všech sekcí; zapisovač ho pošle celý do `save()` (repozitář ukládá
 * cílový stav, vynechaný řádek by smazal).
 *
 * ── Proč jsou 10299–10302 nárok, ne částka ──────────────────────────────────
 * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver::taxCreditsCzk()}
 * vykazuje v těchto prvcích NÁROK podle prohlášení (rozpad
 * `claimed_non_refundable_credit_breakdown`), ne částku, která se do zálohy
 * vešla — ta je rozdíl 10298 − 10305. Přítomnost prvku s kladnou částkou je
 * proto tvrzení „poplatník slevu uplatňuje" a jako takové se převezme; výše
 * nároku se neukládá, evidence ji počítá sama podle rulesetu.
 */
final class JmhzStatutoryChanges
{
    public const CREDIT_KINDS = ['taxpayer', 'disability-basic', 'disability-extended', 'ztp-p'];

    public const CREDIT_LABELS = [
        'taxpayer' => 'Sleva na poplatníka',
        'disability-basic' => 'Sleva na invaliditu I. a II. stupně',
        'disability-extended' => 'Sleva na invaliditu III. stupně',
        'ztp-p' => 'Sleva na držitele průkazu ZTP/P',
    ];

    /**
     * @param array<string,list<array<string,mixed>>> $sections
     * @return array{sections:array<string,list<array<string,mixed>>>,changed:bool,current:?string,imported:string,warning:?string}
     */
    public static function declaration(
        array $sections,
        ?string $frozenThrough,
        string $monthStart,
        bool $signed,
        string $reference,
    ): array {
        $imported = $signed ? 'signed' : 'not-signed';
        $result = JmhzEvidenceTimeline::setFrom(
            $sections['tax_declarations'] ?? [],
            $monthStart,
            ['status' => $imported, 'evidence_reference' => $reference],
            ['status'],
            true,
            $frozenThrough,
        );
        $sections['tax_declarations'] = $result['rows'];

        return [
            'sections' => $sections,
            'changed' => $result['changed'],
            'current' => isset($result['current']['status']) ? (string) $result['current']['status'] : null,
            'imported' => $imported,
            'warning' => $result['warning'],
        ];
    }

    /**
     * Uplatněné slevy se převezmou od měsíce hlášení. Slevu, kterou hlášení
     * neuvádí, import ukončí jen tehdy, když ji sám dřív založil (odkaz na
     * doklad začíná `jmhz-import:`); ručně zapsanou slevu jen ohlásí.
     *
     * @param array<string,list<array<string,mixed>>> $sections
     * @param array<string,int> $claims druh => částka nároku z hlášení
     * @return array{sections:array<string,list<array<string,mixed>>>,changes:list<array{kind:string,action:string,current:?string}>,warnings:list<string>}
     */
    public static function credits(
        array $sections,
        ?string $frozenThrough,
        string $monthStart,
        array $claims,
        string $reference,
    ): array {
        $rows = $sections['tax_credit_claims'] ?? [];
        $changes = [];
        $warnings = [];
        foreach (self::CREDIT_KINDS as $kind) {
            $series = [];
            $others = [];
            foreach ($rows as $row) {
                if (($row['credit_kind'] ?? null) === $kind) {
                    $series[] = $row;
                } else {
                    $others[] = $row;
                }
            }
            if (($claims[$kind] ?? 0) > 0) {
                $result = JmhzEvidenceTimeline::setFrom(
                    $series,
                    $monthStart,
                    ['credit_kind' => $kind, 'evidence_status' => 'verified', 'evidence_reference' => $reference],
                    ['evidence_status'],
                    false,
                    $frozenThrough,
                );
                if ($result['changed']) {
                    $changes[] = [
                        'kind' => $kind,
                        'action' => 'claim',
                        'current' => isset($result['current']['evidence_status'])
                            ? (string) $result['current']['evidence_status']
                            : null,
                    ];
                }
            } else {
                $result = JmhzEvidenceTimeline::endBefore(
                    $series,
                    $monthStart,
                    static fn (array $row): bool => JmhzReportPlanner::isImported($row['evidence_reference'] ?? null),
                    $frozenThrough,
                );
                if ($result['changed']) {
                    $changes[] = ['kind' => $kind, 'action' => 'end', 'current' => 'verified'];
                } elseif ($result['current'] !== null) {
                    $warnings[] = self::CREDIT_LABELS[$kind] . ' v hlášení za ' . substr($monthStart, 0, 7)
                        . ' není, evidence ji ale uplatňuje. Import ručně zapsanou slevu neukončuje — '
                        . 'zkontrolujte ji v zákonné evidenci osoby.';
                }
            }
            if ($result['warning'] !== null) {
                $warnings[] = $result['warning'];
            }
            $rows = [...$others, ...$result['rows']];
        }
        $sections['tax_credit_claims'] = $rows;

        return ['sections' => $sections, 'changes' => $changes, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Sleva na pojistném pracujícího důchodce (10490 → `social_discount_claims`).
     *
     * @param array<string,list<array<string,mixed>>> $sections
     * @return array{sections:array<string,list<array<string,mixed>>>,changed:bool,current:?string,imported:string,warning:?string}
     */
    public static function socialDiscount(
        array $sections,
        ?string $frozenThrough,
        string $monthStart,
        bool $claimed,
        string $reference,
    ): array {
        $imported = $claimed ? 'verified' : 'not_claimed';
        $result = JmhzEvidenceTimeline::setFrom(
            $sections['social_discount_claims'] ?? [],
            $monthStart,
            ['status' => $imported, 'evidence_reference' => $claimed ? $reference : null],
            ['status'],
            true,
            $frozenThrough,
        );
        $sections['social_discount_claims'] = $result['rows'];

        return [
            'sections' => $sections,
            'changed' => $result['changed'],
            'current' => isset($result['current']['status']) ? (string) $result['current']['status'] : null,
            'imported' => $imported,
            'warning' => $result['warning'],
        ];
    }
}
