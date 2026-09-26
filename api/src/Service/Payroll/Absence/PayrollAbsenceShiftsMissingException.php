<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Nepřítomnost s náhradou mzdy nemá v okně žádnou publikovanou směnu.
 *
 * Náhrada se počítá z neodpracovaných směn, takže bez rozvrhu vyjde nula.
 * Výjimka nese vztah a měsíc, aby odpověď mohla poslat uživatele rovnou
 * do Docházky a směn, kde se směny rozvrhnou podle kalendáře.
 */
final class PayrollAbsenceShiftsMissingException extends \InvalidArgumentException
{
    public function __construct(
        public readonly int $employmentId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {
        parent::__construct(sprintf(
            'Náhrada mzdy při DPN se počítá z neodpracovaných směn, ale pracovní vztah nemá '
            . 'v době %s až %s žádnou publikovanou směnu. Otevřete Docházku a směny a použijte '
            . '„Rozvrhnout směny podle kalendáře“; pak nepřítomnost schvalte znovu.',
            $dateFrom,
            $dateTo,
        ));
    }

    public function period(): string
    {
        return substr($this->dateFrom, 0, 7);
    }
}
