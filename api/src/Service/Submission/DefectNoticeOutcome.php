<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/** Jak výzva dopadla na osud podání. */
enum DefectNoticeOutcome: string
{
    /** Nevíme — chybí lhůta, písmeno § 74 odst. 1, nebo obojí. */
    case Unknown = 'unknown';

    /** Vada odstraněna ve lhůtě. § 74 odst. 3: hledí se na podání jako na řádné a včasné. */
    case Cured = 'cured';

    /** Podání se stalo neúčinným (§ 74 odst. 4). Prakticky: jako by nebylo podáno. */
    case Ineffective = 'ineffective';

    /** Neúčinnost nenastala, ale hrozí pokuta podle § 247a DŘ. */
    case PenaltyRisk = 'penalty_risk';
}
