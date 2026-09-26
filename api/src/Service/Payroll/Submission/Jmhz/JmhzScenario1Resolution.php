<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzScenario1Resolution
{
    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @param list<JmhzScenario1Blocker> $excludedBlockers nálezy vztahů, jejichž
     *        formulář se do tohoto hlášení nepodává (odložené, u opravy nevybrané);
     *        sestavení neblokují, ale UI je musí ukázat
     */
    public function __construct(
        public ?JmhzScenario1NormalizedDocument $candidate,
        public array $blockers,
        public array $excludedBlockers = [],
        public ?JmhzFormExclusion $exclusion = null,
    ) {}

    public function status(): string
    {
        return $this->blockers === [] ? 'resolved' : 'blocked';
    }

    public function requireResolvedDocument(): JmhzScenario1NormalizedDocument
    {
        if ($this->candidate === null || $this->blockers !== []) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_scenario1_resolution_blocked',
                'Normalizovaný dokument JMHZ scenario_1 není úplný.',
            );
        }
        return $this->candidate;
    }
}
