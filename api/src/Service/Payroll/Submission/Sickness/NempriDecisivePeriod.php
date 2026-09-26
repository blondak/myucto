<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * `CtRozhodneObdobi` — rozhodné období s měsíci, které nepokrývá jednotné
 * měsíční hlášení podané z MyÚčta, a případně pravděpodobný příjem.
 *
 * Součty (`zapocitatelnyPrijemCelkem`, `vylouceneDnyCelkem`) se posílají JEN
 * tehdy, když seznam měsíců pokrývá celé rozhodné období (`$complete`). Jinak
 * by součet částečného seznamu vypadal jako součet celého období a ÚSSZ by
 * z něj spočítala nižší denní vyměřovací základ.
 */
final readonly class NempriDecisivePeriod
{
    /**
     * @param list<NempriDecisiveMonth> $months
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $months,
        public bool $complete,
        public ?int $probableIncomeCzk = null,
    ) {}
}
