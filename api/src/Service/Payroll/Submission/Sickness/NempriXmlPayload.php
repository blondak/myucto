<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Obsah jednoho e-podání NEMPRI25.
 *
 * ## Rozhodné období
 *
 * DV NEMPRI25 vede rozhodné období u dávek s akcí vznik jako povinné vždy,
 * a to úplné: všechny měsíce se součty, nebo jen pravděpodobnou výši příjmu.
 * Výjimku pro měsíce pokryté jednotným měsíčním hlášením norma nezná. Výklad
 * a pravidla jsou v {@see NempriDecisivePeriodResolver}.
 *
 * ## Platební spojení
 *
 * § 97 odst. 2 věta druhá ukládá předat s podklady „údaje o způsobu výplaty
 * mzdy, platu nebo odměny“. Věta proto nese `platebniSpojeni` podle výplatního
 * profilu zaměstnance; viz {@see NempriPaymentConnection}.
 *
 * ## Co tu vědomě NENÍ
 *
 * **`prilohy`.** `CtPrilohy` umí 1 až 9 příloh v base64. § 97 odst. 1 věta
 * třetí je vyžaduje, jen když zaměstnanec předal podklady v listinné podobě
 * — tedy u dokladů, které aplikace nedrží. Přidat sem prázdnou přílohovou
 * část by tvrdilo, že žádné takové podklady nejsou.
 */
final readonly class NempriXmlPayload
{
    public function __construct(
        public SicknessBenefitKind $benefitKind,
        public int $osszCode,
        public bool $correction,
        public ?string $decisionNumber,
        public bool $foreignCase,
        public string $insuredFirstName,
        public string $insuredLastName,
        public string $insuredBirthNumber,
        public ?string $insuredPhone,
        public ?string $insuredEmail,
        public string $employerVariableSymbol,
        public ?string $employerIdentificationNumber,
        public string $employerName,
        public string $employmentFrom,
        public ?string $employmentTo,
        public string $activityCode,
        public bool $workedOnDecisiveDay,
        public ?string $hoursWorked,
        public ?string $dailyWorkingHours,
        public ?int $smallScopeIncomeMinor,
        public bool $receivesPension,
        public ?string $pensionKind,
        public bool $isStudent,
        public ?bool $withinSchoolHolidays,
        public bool $firstEmploymentFreeTime,
        public bool $unpaidLeave,
        public ?string $unpaidLeaveFrom,
        public ?string $unpaidLeaveTo,
        public ?bool $startsMaternity,
        public ?string $childBirthDate,
        public bool $transferredOtherWork,
        public ?string $transferredOn,
        public bool $enforcement,
        public bool $insolvency,
        public ?string $additionalNote,
        public string $productName,
        public string $productVersion,
        public string $payloadVersion,
        public ?string $notificationEmail = null,
        public ?string $contactWorkerName = null,
        public ?string $contactWorkerPhone = null,
        public ?string $contactWorkerEmail = null,
        public ?NempriBenefitApplication $application = null,
        public ?NempriDecisivePeriod $decisivePeriod = null,
        public ?NempriPaymentConnection $paymentConnection = null,
    ) {}
}
