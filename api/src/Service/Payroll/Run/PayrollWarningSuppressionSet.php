<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Platná skrytí varování jedné firmy a jejich použití na kontroly běhu.
 *
 * Skrývá se při ČTENÍ, ne při zápisu: uložené validace revize zůstávají úplné
 * (co systém viděl, když běh počítal), takže obnovení skrytí je okamžité i
 * u běhů, které už existují. Blokující chyby a varování vyžadující výjimku
 * projdou vždy, i kdyby pro jejich kód nějaké skrytí v databázi bylo.
 */
final class PayrollWarningSuppressionSet
{
    /**
     * @param array<string,true> $types kódy skryté pro celou firmu
     * @param array<string,array<int,true>> $subjects kód => skryté subjekty
     */
    public function __construct(
        private readonly array $types = [],
        private readonly array $subjects = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /** @param list<array{code:string,subject_type:string,subject_id:int}> $rows */
    public static function fromRows(array $rows): self
    {
        $types = [];
        $subjects = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $type = (string) $row['subject_type'];
            if ($type === PayrollWarningSuppressionCatalog::SUBJECT_SUPPLIER) {
                $types[$code] = true;
                continue;
            }
            if (PayrollWarningSuppressionCatalog::subjectType($code) !== $type) {
                continue;
            }
            $subjects[$code][(int) $row['subject_id']] = true;
        }
        return new self($types, $subjects);
    }

    /**
     * Rozhodnutí pro jednu kontrolu.
     *
     * @param list<int> $subjectIds
     * @return array{hideable:bool,hidden:bool,subject_type:?string,subject_ids:list<int>,visible_subject_ids:list<int>}
     */
    public function decide(
        string $code,
        string $severity,
        bool $requiresOverride,
        string $entityType,
        ?int $entityId,
        array $subjectIds,
    ): array {
        if (!PayrollWarningSuppressionCatalog::isHideable($code, $severity, $requiresOverride)) {
            return [
                'hideable' => false,
                'hidden' => false,
                'subject_type' => null,
                'subject_ids' => [],
                'visible_subject_ids' => [],
            ];
        }
        $subjects = PayrollWarningSuppressionCatalog::subjects($code, $entityType, $entityId, $subjectIds);
        $hiddenSubjects = $this->subjects[$code] ?? [];
        $visible = array_values(array_filter(
            $subjects,
            static fn (int $id): bool => !isset($hiddenSubjects[$id]),
        ));
        $hidden = isset($this->types[$code]) || ($subjects !== [] && $visible === []);

        return [
            'hideable' => true,
            'hidden' => $hidden,
            'subject_type' => PayrollWarningSuppressionCatalog::subjectType($code),
            'subject_ids' => $subjects,
            'visible_subject_ids' => $visible,
        ];
    }

    /**
     * Uložené kontroly běhu bez skrytých, doplněné o `hideable`,
     * `subject_type` a `subject_ids` (jen ty dosud viditelné).
     *
     * @param list<array<string,mixed>> $rows
     * @return array{visible:list<array<string,mixed>>,hidden_count:int}
     */
    public function filterRows(array $rows): array
    {
        $visible = [];
        $hiddenCount = 0;
        foreach ($rows as $row) {
            $decision = $this->decide(
                (string) $row['code'],
                (string) $row['severity'],
                (bool) ($row['requires_override'] ?? false),
                (string) $row['entity_type'],
                ($row['entity_id'] ?? null) === null ? null : (int) $row['entity_id'],
                is_array($row['subject_ids'] ?? null) ? $row['subject_ids'] : [],
            );
            if ($decision['hidden']) {
                $hiddenCount++;
                continue;
            }
            $row = $this->narrowed($row, $decision, (string) $row['code']);
            $visible[] = $row;
        }
        return ['visible' => $visible, 'hidden_count' => $hiddenCount];
    }

    /**
     * Totéž nad kontrolami ze snímku, který se teprve staví (kontrola před
     * zahájením běhu).
     *
     * @param list<PayrollRunValidation> $validations
     * @return list<PayrollRunValidation>
     */
    public function filterValidations(array $validations): array
    {
        $result = [];
        foreach ($validations as $validation) {
            $decision = $this->decide(
                $validation->code,
                $validation->severity,
                $validation->requiresOverride,
                $validation->entityType,
                $validation->entityId,
                $validation->subjectIds,
            );
            if ($decision['hidden']) {
                continue;
            }
            if (PayrollWarningSuppressionCatalog::isAggregate($validation->code)
                && count($decision['visible_subject_ids']) !== count($decision['subject_ids'])
            ) {
                $validation = new PayrollRunValidation(
                    $validation->severity,
                    $validation->code,
                    $validation->entityType,
                    $validation->entityId,
                    PayrollWarningSuppressionCatalog::aggregateMessage(
                        $validation->code,
                        count($decision['visible_subject_ids']),
                    ) ?? $validation->message,
                    $validation->remediationPath,
                    $validation->requiresOverride,
                    $decision['visible_subject_ids'],
                );
            }
            $result[] = $validation;
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{hideable:bool,hidden:bool,subject_type:?string,subject_ids:list<int>,visible_subject_ids:list<int>} $decision
     * @return array<string,mixed>
     */
    private function narrowed(array $row, array $decision, string $code): array
    {
        $row['hideable'] = $decision['hideable'];
        $row['subject_type'] = $decision['subject_type'];
        $row['subject_ids'] = $decision['visible_subject_ids'];
        if ($decision['hideable']
            && PayrollWarningSuppressionCatalog::isAggregate($code)
            && count($decision['visible_subject_ids']) !== count($decision['subject_ids'])
        ) {
            $row['message'] = PayrollWarningSuppressionCatalog::aggregateMessage(
                $code,
                count($decision['visible_subject_ids']),
            ) ?? $row['message'];
        }
        return $row;
    }
}
