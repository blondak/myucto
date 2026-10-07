<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Ozuspoj;

use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;

/**
 * Převzetí záměru uplatňovat slevu na pojistném, který ČSSZ přijala od
 * předchozího mzdového programu (§ 7a odst. 5 a § 7c odst. 2 a 3 zákona
 * č. 589/1992 Sb.).
 *
 * Po převodu mezd záměr v evidenci chyběl a sleva se neuplatnila. Převzetí
 * jde stejnou cestou jako vlastní záměr ({@see OzuspojIntentService}) a nese
 * dvě věci, které datová věta OZUSPOJ23 nemá:
 *
 * - **den doručení** z protokolu ČSSZ (`acceptedOn`) — na něm nárok stojí,
 *   a proto se nikdy nedosazuje,
 * - **příznak převzatého podání** — záměr nemá podání z MyÚčta, takže k němu
 *   nevzniká povinnost ani lhůta oznámení.
 *
 * Záměr se NEODVOZUJE z měsíčního hlášení (příznak slevy v JMHZ neříká, kdy
 * ČSSZ záměr přijala), a CHECK na `accepted_on` se neuvolňuje.
 *
 * Párování osoby je stejné jako u importu registrací: rodné číslo (u cizince
 * EČP) přes slepý index, jinak jméno, příjmení a datum narození. Vztah musí
 * být jediný pracovní poměr osoby, který v den zahájení záměru trvá.
 */
final readonly class OzuspojPredecessorIntentImporter
{
    public const SOURCE = 'ozuspoj_xml';

    public function __construct(
        private OzuspojXmlReader $reader,
        private OzuspojIntentService $intents,
        private RegistrationImportLookup $lookup,
        private PayrollSensitiveData $sensitiveData,
    ) {}

    /**
     * @param string $acceptedOn den doručení oznámení ČSSZ podle protokolu
     *        předchozího programu
     * @return array<string,mixed> popis záměru, `import_status` a `warnings`
     */
    public function import(
        int $supplierId,
        string $environment,
        string $xml,
        string $acceptedOn,
        int $createdBy,
    ): array {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Prostředí musí být test nebo production.');
        }
        $file = $this->reader->read($xml);
        $payload = $file->payload;
        $warnings = $this->assertOwnEmployer($supplierId, $payload->employerVariableSymbol);
        $employeeId = $this->employee($supplierId, $payload);
        $anchorDate = (string) ($payload->intentFrom ?? $payload->intentTo);
        $employmentId = $this->employment($supplierId, $employeeId, $anchorDate);
        $reference = $file->sha256;

        $result = match ($payload->kind) {
            OzuspojSubmissionKind::Start => $this->intents->importPredecessorStart(
                $supplierId,
                $environment,
                $employmentId,
                (string) $payload->intentFrom,
                $payload->osszCode,
                $acceptedOn,
                self::SOURCE,
                $reference,
                $createdBy,
            ),
            OzuspojSubmissionKind::End => $this->intents->importPredecessorEnd(
                $supplierId,
                $environment,
                $employmentId,
                (string) $payload->intentTo,
                $acceptedOn,
            ),
            OzuspojSubmissionKind::Cancellation => throw new OzuspojException(
                'ozuspoj_import_cancellation_unsupported',
                'Storno záměru se z předchozího programu nepřebírá. Stornovaný záměr '
                    . 'nepřebírejte; převzatý záměr zrušte zápisem výsledku „zrušeno".',
            ),
        };

        return $result + ['warnings' => $warnings, 'file_sha256' => $reference];
    }

    /**
     * Oznámení jiného zaměstnavatele se nepřebírá — rozhoduje variabilní
     * symbol zaměstnavatele proti VS mzdových účtáren firmy. Firma bez VS se
     * ověřit nedá; převzetí projde s varováním.
     *
     * @return list<string>
     */
    private function assertOwnEmployer(int $supplierId, string $variableSymbol): array
    {
        $known = $this->lookup->variableSymbols($supplierId);
        $symbol = RegistrationImportLookup::variableSymbol($variableSymbol);
        if ($known === []) {
            return ['Firma nemá u mzdových účtáren variabilní symbol ČSSZ, takže nejde ověřit, '
                . 'že oznámení podal tento zaměstnavatel.'];
        }
        if ($symbol === null || !in_array($symbol, $known, true)) {
            throw new OzuspojException(
                'ozuspoj_import_foreign_employer',
                "Oznámení podal zaměstnavatel s variabilním symbolem {$variableSymbol}, který "
                    . 'nepatří žádné mzdové účtárně této firmy.',
            );
        }

        return [];
    }

    private function employee(int $supplierId, OzuspojXmlPayload $payload): int
    {
        $found = [];
        if ($payload->employeeBirthNumber !== null) {
            try {
                $value = CzechBirthNumber::normalize($payload->employeeBirthNumber);
                $type = 'birth_number';
            } catch (\InvalidArgumentException) {
                $value = $payload->employeeBirthNumber;
                $type = 'ecp';
            }
            $hash = $this->sensitiveData->lookupHash(
                $value,
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
            );
            $found = $this->lookup->employeesByIdentifierHash($supplierId, $type, $hash);
        }
        if ($found === []) {
            $found = $this->lookup->employeesByNameAndBirthDate(
                $supplierId,
                $payload->employeeFirstName,
                $payload->employeeLastName,
                $payload->employeeBirthDate,
            );
        }
        if (count($found) !== 1) {
            throw new OzuspojException(
                $found === [] ? 'ozuspoj_import_person_not_found' : 'ozuspoj_import_person_ambiguous',
                $found === []
                    ? 'Osobu z oznámení se nepodařilo najít podle rodného čísla ani jména a data narození. '
                        . 'Převeďte nejdřív zaměstnance a oznámení převezměte znovu.'
                    : 'Oznámení odpovídá víc osobám; převzetí by záměr přiřadilo naslepo.',
            );
        }

        return $found[0];
    }

    private function employment(int $supplierId, int $employeeId, string $onDate): int
    {
        $matches = [];
        foreach ($this->lookup->employments($supplierId, $employeeId) as $employment) {
            $start = $employment['actual_start_date'] ?? $employment['start_date'];
            if ($employment['relation_type'] !== 'employment'
                || $start === null
                || $start > $onDate
                || ($employment['end_date'] !== null && $employment['end_date'] < $onDate)
            ) {
                continue;
            }
            $matches[] = $employment['id'];
        }
        if (count($matches) !== 1) {
            throw new OzuspojException(
                $matches === [] ? 'ozuspoj_import_employment_not_found' : 'ozuspoj_import_employment_ambiguous',
                $matches === []
                    ? 'Osoba nemá ke dni ' . $onDate . ' pracovní poměr, ke kterému by záměr patřil.'
                    : 'Osoba má ke dni ' . $onDate . ' víc pracovních poměrů; záměr nejde přiřadit jednoznačně.',
            );
        }

        return $matches[0];
    }
}
