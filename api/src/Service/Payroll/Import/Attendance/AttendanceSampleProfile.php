<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Ukázkový profil „Vzor GIRITON" — měsíční podklady z docházkového systému
 * GIRITON a tabulek, které z nich firma skládá:
 *
 *  - list `data*` = export docházky (trvání v excelovém formátu). Rozpad
 *    odpracované doby podle činností je jen členění téhož času, proto se
 *    z exportu berou jen údaje, které výpočetní list nemá;
 *  - list `výpočet*` = měsíční souhrn v desetinných hodinách a peněžní složky.
 *    Známé sloupce mají vlastní složky, ostatní sloupce s částkami dostanou
 *    složku podle hlavičky; součty a kontrolní sloupce se nikdy nepřenášejí;
 *  - list `mzdy*` = seznam osob se zařazením a měsíčními příplatky;
 *  - CSV exportu mezd = osobní a rodné číslo a referenční hrubá a čistá mzda.
 *
 * Každé pravidlo míří na konkrétní druh listu a na konci je „vše ostatní
 * ignorovat" — pomocné listy sešitu (přehledy, produktivita, kopie jmen) tak
 * nevyrobí osoby ani hodnoty. Pořadí je priorita: hodiny z výpočetního listu
 * mají přednost, stejný údaj se nikdy nesčítá.
 *
 * Firma dostane profil jako vlastní záznam (upravit, smazat, zkopírovat);
 * kód drží jen výchozí podobu. Při každé změně pravidel nebo složek zvyšte
 * {@see self::VERSION} — nedotčený vzor firmy se pak převede sám, upravený
 * dostane jen nabídku (PayrollImportProfileRepository::upgradeSample()).
 */
final class AttendanceSampleProfile
{
    public const NAME = 'Vzor GIRITON';
    public const VERSION = 2;

    private const CALCULATION = 'výpočet*';
    private const EXPORT = 'data*';
    private const MAIN = 'mzdy*';
    private const CSV = 'csv';

    /** @return list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> */
    public static function rules(): array
    {
        $rules = [];
        $add = static function (?string $sheet, string $header, string $meaning, ?string $unit = null, ?string $component = null) use (&$rules): void {
            $rules[] = ['sheet' => $sheet, 'header' => $header, 'meaning' => $meaning, 'unit' => $unit, 'component_code' => $component];
        };

        // Výpočetní list: osoba, hodiny, peníze.
        $add(self::CALCULATION, AttendanceSheetAnalyzer::PERSON_PLACEHOLDER, 'person_name');
        $add(self::CALCULATION, 'jméno*', 'person_name');
        foreach ([
            'fond prac. doby' => 'fund_hours',
            'odpracováno celkem*' => 'worked_hours',
            'přesčasové hodiny' => 'overtime_hours',
            'svátek' => 'holiday_hours',
            'dovolená' => 'vacation_hours',
            'lékař' => 'doctor_hours',
            'nemocenská' => 'sick_hours',
            'paragraf' => 'obstacle_employee_hours',
            'očr' => 'care_hours',
            'otcovská' => 'paternity_hours',
            'neomluveno' => 'unexcused_hours',
            'neplacené volno' => 'unpaid_leave_hours',
            'služební cesta' => 'business_trip_hours',
            'doma za 80*' => 'obstacle_employer_hours',
            'práce ve svátek' => 'holiday_work_hours',
            'práce noční*' => 'night_hours',
            'práce o víkendu*' => 'weekend_hours',
        ] as $header => $meaning) {
            $add(self::CALCULATION, $header, $meaning, AttendanceMeaning::UNIT_HOURS);
        }
        foreach ([
            'mzda úkol' => 'MZDA_UKOLOVA',
            'suma hodinovky noc*' => 'MZDA_HODINOVA_NOC',
            'suma hodinovky*' => 'MZDA_HODINOVA_DOCH',
            'příplatky k hodinové mzdě*' => 'PRIPLATKY_K_HODINOVE',
            'příplatek odpolední*' => 'PRIPLATEK_ODPOLEDNI',
            'mimořádná odměna' => 'ODMENA_MIMORADNA',
            'hotovostní odměna*' => 'ODMENA_HOTOVOSTNI',
        ] as $header => $code) {
            $add(self::CALCULATION, $header, 'component', AttendanceMeaning::UNIT_AMOUNT, $code);
        }
        // Mezisoučty, kontroly a pomocná množství — kdyby se přenesly, zdvojily by mzdu.
        foreach ([
            'z toho v hodinovce*', 'úkol', 'hod', 'součet*', 'orientační*', 'hrubý příjem',
            'čas hodinovky*', 'přesčas*25*', 'noční úvazek', 'záloha*', 'oddělení', 'poznámka',
        ] as $header) {
            $add(self::CALCULATION, $header, 'ignore');
        }
        $add(self::CALCULATION, '*', 'component', AttendanceMeaning::UNIT_AMOUNT, AttendanceRules::AUTO_COMPONENT);

        // Export docházky: jen údaje, které výpočetní list nemá.
        $add(self::EXPORT, AttendanceSheetAnalyzer::PERSON_PLACEHOLDER, 'person_name');
        $add(self::EXPORT, 'jméno*', 'person_name');
        $add(self::EXPORT, 'práce odpolední', 'afternoon_hours', AttendanceMeaning::UNIT_DURATION);
        $add(self::EXPORT, 'home office', 'home_office_hours', AttendanceMeaning::UNIT_DURATION);
        $add(self::EXPORT, 'volno z přesčasu', 'compensatory_time_off_hours', AttendanceMeaning::UNIT_DURATION);
        $add(self::EXPORT, '*', 'ignore');

        // Hlavní seznam osob měsíce.
        $add(self::MAIN, 'jméno*', 'person_name');
        $add(self::MAIN, 'oddělení', 'department');
        $add(self::MAIN, 'středisko', 'cost_center');
        $add(self::MAIN, 'týdenní fond', 'weekly_hours');
        $add(self::MAIN, 'název pozice', 'position');
        // Sjednaná měsíční mzda patří do podmínek vztahu, ne mezi měsíční vstupy:
        // předvyplní se při zakládání osoby a u existujícího vztahu ji import
        // zapíše do podmínek jen na výslovné potvrzení.
        $add(self::MAIN, 'mv', 'monthly_wage');
        $add(self::MAIN, 'mzdový výměr*', 'monthly_wage');
        $add(self::MAIN, 'měsíční mzda*', 'monthly_wage');
        $add(self::MAIN, 'nový nástup*', 'start_end_note');
        $add(self::MAIN, 'příplatek bozp', 'component', AttendanceMeaning::UNIT_AMOUNT, 'PRIPLATEK_BOZP');
        $add(self::MAIN, 'odměny*', 'component', AttendanceMeaning::UNIT_AMOUNT, 'ODMENA');
        $add(self::MAIN, '*', 'ignore');

        // CSV exportu mezd.
        $add(self::CSV, 'zaměstnanec', 'person_name');
        $add(self::CSV, 'rodné číslo', 'birth_number');
        $add(self::CSV, 'osobní číslo', 'personal_number');
        $add(self::CSV, 'pracpoměr', 'relation_label');
        $add(self::CSV, 'odprachod', 'reference_hours', AttendanceMeaning::UNIT_HOURS);
        $add(self::CSV, 'hrubá mzda', 'reference_gross', AttendanceMeaning::UNIT_AMOUNT);
        $add(self::CSV, 'čistá mzda', 'reference_net', AttendanceMeaning::UNIT_AMOUNT);
        $add(self::CSV, '*', 'ignore');

        // Ostatní listy (přehledy, produktivita, poznámky) do importu nepatří.
        $add('*', '*', 'ignore');

        return $rules;
    }

    /** @return list<array{code:string,name:string,kind:string}> */
    public static function components(): array
    {
        return [
            ['code' => 'MZDA_HODINOVA_DOCH', 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage'],
            ['code' => 'MZDA_HODINOVA_NOC', 'name' => 'Hodinová mzda za noční směny podle docházky', 'kind' => 'hourly_wage'],
            ['code' => 'PRIPLATKY_K_HODINOVE', 'name' => 'Příplatky k hodinové mzdě', 'kind' => 'premium'],
            ['code' => 'PRIPLATEK_ODPOLEDNI', 'name' => 'Příplatek za odpolední směnu', 'kind' => 'premium'],
            ['code' => 'PRIPLATEK_BOZP', 'name' => 'Příplatek BOZP', 'kind' => 'premium'],
            ['code' => 'ODMENA_MIMORADNA', 'name' => 'Mimořádná odměna', 'kind' => 'bonus'],
            ['code' => 'ODMENA_HOTOVOSTNI', 'name' => 'Hotovostní odměna', 'kind' => 'bonus'],
        ];
    }
}
