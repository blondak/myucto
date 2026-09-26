<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Převod nalezených rozdílů na vstup existujícího schválení události A3.
 *
 * Detekce umí najít víc, než umí tenhle core podat. To není nedodělek, který
 * se má zamlčet: změnou (A3) se tu hlásí titul, trvalý pobyt, doručovací
 * adresa, daňová rezidence, zdravotní pojišťovna, nejvyšší vzdělání,
 * přístup cizince na trh práce a pracovní údaje (postavení, režim, místo
 * výkonu, profese, pozice…). Změna bližšího určení
 * vztahu je uzavřená kvůli povinné příloze s vysvětlením
 * ({@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService}).
 *
 * Planner proto rozdělí nález na dvě hromádky:
 * - **`changes`** — co se dá schválit jedním kliknutím do neměnné události,
 * - **`unsupported`** — co detekce našla, ale podat to musí člověk jinudy.
 *
 * Nález z druhé hromádky se NEZAHAZUJE. Povinnost i osmidenní lhůta existují
 * bez ohledu na to, jestli je aplikace umí odbavit, takže návrh zůstane
 * otevřený s termínem a dá se uzavřít až ručně. Tiše zmizet by znamenalo
 * tvrdit, že se nic nestalo.
 */
final class PayrollRegistrationChangeDeltaPlanner
{
    /** Povinná pole doručovací adresy v datové větě A3. */
    private const CONTACT_ADDRESS_REQUIRED = [
        'street', 'house_number', 'postal_code', 'city', 'country_code',
    ];

    private const CONTACT_ADDRESS_OPTIONAL = ['orientation_number', 'ruian_point'];

    /** Trvalý pobyt: ulice může chybět (obec bez ulic), číslo popisné ne. */
    private const PERMANENT_ADDRESS_REQUIRED = [
        'house_number', 'postal_code', 'city', 'country_code',
    ];

    /**
     * Pracovní údaje, které A3 nese (EDV 1.4.0.6, sloupec A3-OST). Druh
     * činnosti měnit nejde a bližší určení vyžaduje přílohu, proto tu nejsou.
     */
    public const EMPLOYMENT_FIELDS = [
        'contract_start_on' => 'date',
        'employment_status_code' => 'text',
        'work_mode_code' => 'text',
        'continuous_operation' => 'bool',
        'prevailing_workplace_code' => 'text',
        'expected_workplaces' => 'text',
        'contract_workplace' => 'text',
        'workplace_city' => 'text',
        'workplace_municipality_code' => 'text',
        'profession_code' => 'text',
        'required_education_code' => 'text',
        'position_name' => 'text',
        'leadership' => 'bool',
    ];

    private const EMPLOYMENT_PATHS = [
        'employment.contract_start_on',
        'employment.employment_status_code',
        'employment.work_mode_code',
        'employment.continuous_operation',
        'employment.prevailing_workplace_code',
        'employment.expected_workplaces',
        'employment.contract_workplace',
        'employment.workplace_city',
        'employment.workplace_municipality_code',
        'employment.profession_code',
        'employment.required_education_code',
        'employment.position_name',
        'employment.leadership',
    ];

    /**
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return array{
     *   changes:array<string,mixed>,
     *   unsupported:list<array{path:string,reason_code:string}>
     * }
     */
    public function plan(
        array $findings,
        PayrollRegistrationReportableProfile $current,
        string $effectiveOn,
    ): array {
        $changes = [];
        $unsupported = [];
        foreach ($findings as $finding) {
            if ($finding->actionCode !== PayrollRegistrationReportableCatalog::ACTION_CHANGE) {
                // Vznik a skončení příslušnosti k cizím předpisům se podává
                // akcí A6/A7, která má vlastní vstup (nositel pojištění).
                // Do A3 ji přimíchat nelze.
                $unsupported[] = [
                    'path' => $finding->path,
                    'reason_code' => 'registration_change_requires_other_action',
                ];
                continue;
            }
            switch (true) {
                case $finding->path === 'identity.title_prefix':
                    $title = $current->get('identity.title_prefix');
                    if ($title === null) {
                        // Datová věta umí titul jen NASTAVIT, ne vymazat.
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['title_prefix'] = $title;
                    break;

                case $finding->path === 'health_insurance_code':
                    $code = $current->get('health_insurance_code');
                    if ($code === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['health_insurance_code'] = $code;
                    break;

                case $finding->path === 'tax_residency.country_code':
                    $country = $current->get('tax_residency.country_code');
                    if ($country === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['tax_residency'] = [
                        'country_code' => $country,
                        // Všechny údaje jednoho podání A3 musí mít stejné datum
                        // účinnosti; jiné by událost odmítla.
                        'changed_on' => $effectiveOn,
                    ];
                    break;

                case str_starts_with($finding->path, 'permanent_address.'):
                    if (array_key_exists('permanent_address', $changes)
                        || in_array('permanent_address', array_column($unsupported, 'path'), true)
                    ) {
                        break;
                    }
                    $permanent = $this->address(
                        $current,
                        'permanent_address',
                        self::PERMANENT_ADDRESS_REQUIRED,
                        ['street', 'orientation_number', 'ruian_point'],
                    );
                    if ($permanent === null) {
                        // Kmenová data vedou adresu jedním řádkem; číslo
                        // popisné zvlášť zná jen profil A1. Návrh zůstane
                        // otevřený a proklik vede do profilu.
                        $unsupported[] = [
                            'path' => 'permanent_address',
                            'reason_code' => 'registration_change_permanent_address_incomplete',
                        ];
                        break;
                    }
                    $changes['permanent_address'] = $permanent;
                    break;

                case str_starts_with($finding->path, 'foreign_worker.'):
                    if (array_key_exists('foreign_worker', $changes)
                        || in_array('foreign_worker', array_column($unsupported, 'path'), true)
                    ) {
                        break;
                    }
                    $worker = $this->foreignWorker($current, $findings);
                    if ($worker === null) {
                        $unsupported[] = [
                            'path' => 'foreign_worker',
                            'reason_code' => 'registration_change_foreign_permit_incomplete',
                        ];
                        break;
                    }
                    $changes['foreign_worker'] = $worker;
                    break;

                case str_starts_with($finding->path, 'contact_address.'):
                    if (array_key_exists('contact_address', $changes)) {
                        break;
                    }
                    $address = $this->contactAddress($current);
                    if ($address === null) {
                        $unsupported[] = [
                            'path' => 'contact_address',
                            'reason_code' => 'registration_change_contact_address_incomplete',
                        ];
                        break;
                    }
                    $changes['contact_address'] = $address;
                    break;

                case $finding->path === 'facts.highest_education_code':
                    $education = $current->get('facts.highest_education_code');
                    if ($education === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['highest_education_code'] = $education;
                    break;

                case in_array($finding->path, self::EMPLOYMENT_PATHS, true):
                    // Mění-li se kterýkoli pracovní údaj, pošle se celý blok
                    // v aktuální podobě — ČSSZ přijímá i částečný snímek
                    // a skupina se tak nerozpadne na polovinu.
                    if (!array_key_exists('employment', $changes)) {
                        $changes['employment'] = $this->employment($current);
                    }
                    break;

                default:
                    $unsupported[] = [
                        'path' => $finding->path,
                        'reason_code' => 'registration_change_field_not_in_a3_payload',
                    ];
            }
        }
        ksort($changes, SORT_STRING);
        usort(
            $unsupported,
            static fn (array $a, array $b): int => $a['path'] <=> $b['path'],
        );

        return ['changes' => $changes, 'unsupported' => array_values($unsupported)];
    }

    /** @return array<string,string|bool> */
    private function employment(PayrollRegistrationReportableProfile $current): array
    {
        $employment = [];
        foreach (self::EMPLOYMENT_FIELDS as $field => $kind) {
            $value = $current->get("employment.{$field}");
            if ($value === null) {
                continue;
            }
            $employment[$field] = $kind === 'bool' ? $value === '1' : $value;
        }

        return $employment;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @return array<string,string>|null
     */
    private function address(
        PayrollRegistrationReportableProfile $current,
        string $block,
        array $required,
        array $optional,
    ): ?array {
        $address = [];
        foreach ($required as $field) {
            $value = $current->get("{$block}.{$field}");
            if ($value === null) {
                return null;
            }
            $address[$field] = $value;
        }
        foreach ($optional as $field) {
            $value = $current->get("{$block}.{$field}");
            if ($value !== null) {
                $address[$field] = $value;
            }
        }
        ksort($address, SORT_STRING);

        return $address;
    }

    /**
     * Přístup cizince na trh práce: buď volný přístup s důvodem, nebo úplné
     * povolení. Prodloužení povolení (nové datum „do") bez nového čísla
     * rozhodnutí se podat nedá — to je jiné rozhodnutí a jeho číslo zná jen
     * profil A1; návrh pak zůstane otevřený s prokliknutím do profilu.
     *
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return array<string,string|bool>|null
     */
    private function foreignWorker(
        PayrollRegistrationReportableProfile $current,
        array $findings,
    ): ?array {
        $freeAccess = $current->get('foreign_worker.free_access');
        if ($freeAccess === '1') {
            $reason = $current->get('foreign_worker.free_access_reason_code');

            return $reason === null
                ? null
                : ['free_access' => true, 'free_access_reason_code' => $reason];
        }
        $paths = array_map(
            static fn (PayrollRegistrationChangeFinding $finding): string => $finding->path,
            $findings,
        );
        $datesChanged = array_intersect(
            ['foreign_worker.permit_from', 'foreign_worker.permit_to'],
            $paths,
        ) !== [];
        if ($datesChanged && !in_array('foreign_worker.permit_identifier', $paths, true)) {
            return null;
        }
        $worker = ['free_access' => false];
        foreach ([
            'permit_type_code', 'permit_identifier', 'permit_from', 'permit_to',
        ] as $field) {
            $value = $current->get("foreign_worker.{$field}");
            if ($value === null) {
                return null;
            }
            $worker[$field] = $value;
        }
        $office = $current->get('foreign_worker.issuing_labour_office_code');
        if ($office !== null) {
            $worker['issuing_labour_office_code'] = $office;
        }
        ksort($worker, SORT_STRING);

        return $worker;
    }

    /** @return array<string,string>|null */
    private function contactAddress(
        PayrollRegistrationReportableProfile $current,
    ): ?array {
        $address = [];
        foreach (self::CONTACT_ADDRESS_REQUIRED as $field) {
            $value = $current->get("contact_address.{$field}");
            if ($value === null) {
                return null;
            }
            $address[$field] = $value;
        }
        foreach (self::CONTACT_ADDRESS_OPTIONAL as $field) {
            $value = $current->get("contact_address.{$field}");
            if ($value !== null) {
                $address[$field] = $value;
            }
        }

        return $address;
    }
}
