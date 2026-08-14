<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Výsledek průchodu celým katalogem kontrol nad jedním podáním.
 *
 * Report je fail-closed: `submittable()` je pravda jen tehdy, když žádná
 * nepropustná kontrola neselhala A ZÁROVEŇ nezůstala ani jedna nepropustná
 * kontrola, která na podání dopadá a kterou zatím neumíme vyhodnotit.
 * Nedodělané pokrytí se tak nedá splést se zeleným výsledkem.
 */
final readonly class JmhzControlEvaluationReport
{
    /** @param list<JmhzControlFinding> $findings */
    public function __construct(
        public array $findings,
        public string $catalogKey,
        public string $catalogManifestSha256,
    ) {}

    /** @return list<JmhzControlFinding> */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (JmhzControlFinding $finding): bool => $finding->blocksSubmission(),
        ));
    }

    /** @return list<JmhzControlFinding> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (JmhzControlFinding $finding): bool => $finding->warnsOnly(),
        ));
    }

    /**
     * Nepropustné kontroly, které na podání dopadají, ale nemáme pro ně
     * vykonávací implementaci. Dokud jsou, nesmí se odesílat.
     *
     * @return list<JmhzControlFinding>
     */
    public function coverageGaps(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (JmhzControlFinding $finding): bool
                => $finding->outcome === JmhzControlOutcome::Unimplemented
                && $finding->passability === JmhzControlPassability::Blocking,
        ));
    }

    public function submittable(): bool
    {
        return $this->blocking() === [] && $this->coverageGaps() === [];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = array_fill_keys(
            array_map(
                static fn (JmhzControlOutcome $outcome): string => $outcome->value,
                JmhzControlOutcome::cases(),
            ),
            0,
        );
        foreach ($this->findings as $finding) {
            ++$counts[$finding->outcome->value];
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $relevant = array_values(array_filter(
            $this->findings,
            static fn (JmhzControlFinding $finding): bool
                => $finding->outcome !== JmhzControlOutcome::NotApplicable,
        ));

        return [
            'schema_reference' => 'payroll-jmhz-control-evaluation.v1',
            'catalog_key' => $this->catalogKey,
            'catalog_manifest_sha256' => $this->catalogManifestSha256,
            'submittable' => $this->submittable(),
            'counts' => $this->counts(),
            'blocking' => array_map(
                static fn (JmhzControlFinding $finding): array => $finding->toArray(),
                $this->blocking(),
            ),
            'warnings' => array_map(
                static fn (JmhzControlFinding $finding): array => $finding->toArray(),
                $this->warnings(),
            ),
            'coverage_gaps' => array_map(
                static fn (JmhzControlFinding $finding): array => $finding->toArray(),
                $this->coverageGaps(),
            ),
            'evaluated' => array_map(
                static fn (JmhzControlFinding $finding): array => $finding->toArray(),
                $relevant,
            ),
        ];
    }
}
