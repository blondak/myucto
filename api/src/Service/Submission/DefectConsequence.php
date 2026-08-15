<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Co se s podáním stane, když se vada ve lhůtě neodstraní.
 *
 * Odvozuje se z {@see DefectGround}, ale ukládá se zvlášť: uživatel se ptá na
 * následek, ne na písmeno, a auditor musí vidět, jaký následek aplikace
 * v době rozhodování tvrdila.
 */
enum DefectConsequence: string
{
    /**
     * § 74 odst. 4 DŘ — podání se uplynutím lhůty stává NEÚČINNÝM.
     * Právně tedy dopadne stejně, jako by nebylo podáno vůbec.
     */
    case Ineffective = 'ineffective';

    /**
     * Neúčinnost nenastává (vady podle písm. c/d). Neznamená to „nic se
     * neděje" — za podání učiněné nesprávným způsobem nebo formátem hrozí
     * pokuta podle § 247a daňového řádu.
     */
    case NoIneffectiveness = 'no_ineffectiveness';

    /** Nevíme. Ne „nic nehrozí". */
    case Unknown = 'unknown';
}
