<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Společný stav případu dávky nemocenského pojištění.
 *
 * Stav se NEUKLÁDÁ, jen odvozuje (virtuální sloupec `status`) ze stavů obou
 * podání ({@see SicknessDocumentStatus}) a ze zrušení případu. NEMPRI a HZUPN
 * mají vlastní lhůty podle § 97 odst. 1 až 3 zák. č. 187/2006 Sb.; dokud čeká
 * kterékoli z nich, případ je otevřený a hlídač termínů ho vidí.
 *
 * - `draft` — nic připravené,
 * - `prepared` — podání připravené, nic ještě nedoručeno,
 * - `submitted` — část podání vyřízena, další čeká,
 * - `accepted` — všechna podání vyřízena (HZUPN jen u nemocenského),
 * - `rejected` — některé podání odmítnuto a čeká na nové,
 * - `cancelled` — případ zrušen.
 *
 * Stav NENÍ stav podání: `prepared` znamená „XML je zmrazené", povinnost splní
 * až PŘEDÁNÍ územní správě a to se zapisuje dnem doručení z protokolu.
 */
enum SicknessCaseStatus: string
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Jsou všechna podání případu doložitelně vyřízená? */
    public function isFulfilled(): bool
    {
        return $this === self::Accepted;
    }

    /** Hlídá se u tohoto stavu ještě nějaká lhůta? */
    public function isOpen(): bool
    {
        return $this !== self::Accepted && $this !== self::Cancelled;
    }
}
