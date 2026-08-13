<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final readonly class EmployerRegistrationDeadlineWindow
{
    public function __construct(
        public string $earliestRegistrationOn,
        public string $dueOn,
        public string $deemedEmployerFrom,
        public string $noShowNotificationDueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}
}
