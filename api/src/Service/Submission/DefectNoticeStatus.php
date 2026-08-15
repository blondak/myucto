<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Kde se výzva k odstranění vad nachází v čase.
 *
 * {@see Unknown} je výchozí a je to plnohodnotný stav, ne chybějící hodnota:
 * dokud neznáme konec náhradní lhůty, nelze o dodržení nic tvrdit. Databáze to
 * vynucuje — `chk_submission_defect_notices_status_needs_deadline` (migrace
 * 1394) nepustí `open`, `answered_in_time`, `answered_late` ani `missed` bez
 * vyplněného `respond_by_on`.
 */
enum DefectNoticeStatus: string
{
    /** Neznáme lhůtu, takže o stavu nelze rozhodnout. */
    case Unknown = 'unknown';

    /** Lhůta běží. */
    case Open = 'open';

    /** Vada odstraněna ve lhůtě — § 74 odst. 3: hledí se na podání jako na řádné a včasné. */
    case AnsweredInTime = 'answered_in_time';

    /** Odpověď přišla, ale po lhůtě. Účinky § 74 odst. 3 nenastávají. */
    case AnsweredLate = 'answered_late';

    /** Lhůta uplynula bez odpovědi. */
    case Missed = 'missed';

    /** Správce daně výzvu vzal zpět nebo pozbyla významu. */
    case Withdrawn = 'withdrawn';

    /** Potřebuje to pozornost člověka? */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Unknown, self::Open, self::AnsweredLate, self::Missed => true,
            self::AnsweredInTime, self::Withdrawn => false,
        };
    }
}
