<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceOverlapException;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Absence\AverageEarningResult;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationService;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Component\PayrollRecurringComponentValidator;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Doplnění osob a pracovních vztahů po převodu mezd z PAMICA ({@see PohodaPayrollPeople}).
 *
 * Každý údaj jde toutéž cestou jako ruční zápis na kartě (identita, karta osoby, zákonná
 * evidence, podmínky vztahu, identifikátory ČSSZ, skončení vztahu, checklist, počáteční
 * stavy), vlastní zápis do cizích tabulek tu není. Doplňuje se jen to, co v MyÚčtu chybí:
 * vyplněný údaj převod nepřepíše, a opakovaný převod proto nic nezdvojí.
 *
 * Každý údaj má vlastní savepoint: když neprojde kontrolou karty, zbytek se zapíše a důvod
 * jde do protokolu s osobním číslem.
 *
 * Položky Zákonných termínů se odškrtnou jen tam, kde PAMICA nese doklad (odeslané
 * podání, oznámení pojišťovně k datu události, podepsané prohlášení). Poznámka položky
 * uvede původ a datum z PAMICA. Pracovní smlouva a doklad o skončení se odškrtnou u vztahu,
 * který PAMICA vedla: smlouva i skončení prošly předchozím mzdovým systémem.
 */
final class PohodaPayrollPeopleWriter
{
    private const ENVIRONMENT = 'production';
    private const MESSAGE_LIMIT = 40;
    private const SAVEPOINT = 'pohoda_payroll_people';
    private const NOTE = 'Převzato z PAMICA: ';
    /** Změnové položky checklistu, které převod umí přiřadit ke změně zpracované v PAMICA. */
    private const CHANGE_ITEMS = ['contract_amendment', 'health_insurance_change', 'social_jmhz_change'];
    /** Důvod verze podmínek, kterou zapisuje import docházky z měsíční mzdy sešitu. */
    private const WAGE_CHANGE_NOTE = 'Měsíční mzda z importu docházky za ';
    /** Důvod verze podmínek, kterou zapisuje tenhle převod ze sjednané mzdy v PAMICA. */
    private const PAMICA_WAGE_NOTE = 'Sjednaná měsíční mzda z PAMICA.';
    private const CHECKLIST_LABELS = [
        'employment_contract' => 'pracovní smlouva / dohoda',
        'tax_declaration' => 'prohlášení k dani',
        'health_insurance_registration' => 'registrace zdravotní pojišťovny',
        'social_jmhz_registration' => 'registrace ČSSZ / JMHZ',
        'contract_amendment' => 'dodatek smlouvy',
        'health_insurance_change' => 'změna pro zdravotní pojišťovnu',
        'social_jmhz_change' => 'změna pro ČSSZ / JMHZ',
        'termination_document' => 'doklad o skončení',
        'health_insurance_deregistration' => 'odhlášení zdravotní pojišťovny',
        'social_jmhz_deregistration' => 'odhlášení ČSSZ / JMHZ',
        'eldp_submission' => 'ELDP',
        'taxable_income_confirmation' => 'potvrzení o zdanitelných příjmech',
        'enforcement_insolvency_review' => 'kontrola exekucí a insolvence',
        'later_income_review' => 'kontrola pozdějších příjmů',
    ];

    /**
     * Druh nepřítomnosti => hodiny souhrnu importu docházky, které tutéž dobu nesou.
     * Zrcadlí `PayrollWageProrationService::IMPORT_SUMMARY_TITLES`: co je v souhrnu,
     * má jediný zdroj v importu. Peněžitá pomoc v mateřství, rodičovská a dlouhodobé
     * ošetřovné v souhrnu nejsou, ty zapisuje převod dál.
     *
     * Rozhoduje se podle SKUTEČNÝCH hodin souhrnu ({@see self::carriedByImportSummary()}),
     * ne podle druhu, a to je i cesta pro nemoc, ošetřovné, otcovskou, neplacené volno
     * a neomluvenou absenci: ty do měsíčního sešitu vůbec nejdou, pokud je `MZneprit`
     * nese s daty ({@see PohodaPayrollCatalog::absenceNeedsDates()}), souhrn pro ně proto
     * hodiny nemá a zapíšou se tudy s daty. Bez dat zůstanou v souhrnu a tahle tabulka je
     * z datovaného zápisu vyloučí, aby se doba nevedla dvakrát.
     */
    private const IMPORT_SUMMARY_ABSENCE_HOURS = [
        'vacation' => ['vacation_hours'],
        'dpn' => ['sick_hours'],
        'quarantine' => ['sick_hours'],
        'ocr' => ['care_hours'],
        'paternity' => ['paternity_hours'],
        'employee_obstacle' => ['doctor_hours', 'obstacle_employee_hours'],
        'employer_obstacle' => ['obstacle_employer_hours'],
        'unpaid_leave' => ['unpaid_leave_hours'],
        'unexcused' => ['unexcused_hours'],
        'compensatory_time_off' => ['compensatory_time_off_hours'],
    ];

    /** Názvy druhů nepřítomnosti pro protokol. */
    private const ABSENCE_LABELS = [
        'vacation' => 'dovolená',
        'dpn' => 'nemoc',
        'quarantine' => 'karanténa',
        'ocr' => 'ošetřovné',
        'paternity' => 'otcovská',
        'employee_obstacle' => 'překážka na straně zaměstnance',
        'employer_obstacle' => 'překážka na straně zaměstnavatele',
        'unpaid_leave' => 'neplacené volno',
        'unexcused' => 'neomluvená absence',
        'compensatory_time_off' => 'náhradní volno',
    ];

    private int $messages = 0;
    /** @var array<string,int> */
    private array $completed = [];
    /** @var list<string> */
    private array $invalidOic = [];
    /** @var list<string> */
    private array $foreignLegislation = [];
    private int $accountsToVerify = 0;
    private int $accountsVerified = 0;
    private int $hourlyWageRelations = 0;
    /** @var list<string> příjemci odvodů, pro které export nedal účet */
    private array $institutionGaps = [];
    /** Účty institucí převzaté z PAMICA, které čekají na potvrzení účetní. */
    private int $institutionsToConfirm = 0;
    private int $leaveTransferred = 0;
    private int $leaveTakenHours = 0;
    /** Osoby se souběžnými vztahy, u kterých nejde určit, komu zůstatek dovolené patří. */
    private int $leaveShared = 0;
    /** @var array<string,int> pravidelné plnění => počet vztahů */
    private array $regularBenefits = [];
    /** @var array<string,int> druh nepřítomnosti => počet ponechaných na souhrnu importu */
    private array $absencesFromImport = [];
    /** @var array<string,int> osobní číslo => nepřítomnosti vyžadující data, které je v PAMICA nemají */
    private array $absencesWithoutDates = [];
    /** @var array<string,int> osobní číslo => nepřítomnosti vynechané pro překryv s jinou */
    private array $absenceOverlaps = [];
    /** @var array<string,array<string,int>|null> vztah a měsíc => hodiny souhrnu importu */
    private array $importSummaries = [];
    /** @var array<string,array{employee_id:int,employment_id:int}> vztah v PAMICA => vztah v MyÚčtu */
    private array $matched = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollOpeningBalanceService $openings,
        private readonly PayrollDependantRepository $dependants,
        private readonly PayrollDependantValidator $dependantValidator,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollAbsenceValidator $absenceValidator,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollRecurringComponentRepository $recurring,
        private readonly PayrollRecurringComponentValidator $recurringValidator,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollPersonAccountVerificationService $accountVerification,
        private readonly PayrollLeaveRepository $leave,
    ) {}

    /**
     * @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $institutions příjemci odvodů z PAMICA (zdravotní pojišťovny,
     *     ČSSZ, finanční úřad) - {@see PohodaPayrollPeople::institutions()}
     */
    /**
     * Vztahy, které se při posledním zápisu podařilo spárovat: klíč z PAMICA => naše id.
     * Čte to srovnávací sestava převzatých mezd; jinde ta mapa nikde neexistuje.
     *
     * @return array<string,array{employee_id:int,employment_id:int}>
     */
    public function matchedRelations(): array
    {
        return $this->matched;
    }

    public function write(int $supplierId, ?int $userId, array $records, int $year, bool $confirmIdentifiers, ImportProtocol $protocol, string $step, array $institutions = []): void
    {
        $this->messages = 0;
        $this->completed = [];
        $this->invalidOic = [];
        $this->foreignLegislation = [];
        $this->accountsToVerify = 0;
        $this->accountsVerified = 0;
        $this->hourlyWageRelations = 0;
        $this->institutionGaps = [];
        $this->institutionsToConfirm = 0;
        $this->leaveTransferred = 0;
        $this->leaveTakenHours = 0;
        $this->leaveShared = 0;
        $this->regularBenefits = [];
        $this->absencesFromImport = [];
        $this->absencesWithoutDates = [];
        $this->absenceOverlaps = [];
        $this->importSummaries = [];
        $this->matched = [];
        $today = date('Y-m-d');
        $moduleStart = $this->moduleStart($supplierId);
        $employees = [];
        $unconfirmed = 0;
        foreach ($records as $record) {
            $number = (string) $record['personal_number'];
            $employment = $this->employmentByCode($supplierId, $number);
            if ($employment === null) {
                $protocol->count($step, 'people_not_found');
                $this->warn($protocol, $step, 'person_not_found', "Osobní číslo {$number}: pracovní vztah s tímto kódem ve firmě není, údaje z PAMICA se nepřevzaly.", $number);
                continue;
            }
            $employeeId = (int) $employment['employee_id'];
            $employmentId = (int) $employment['id'];
            // Párovací mapa pro srovnávací sestavu: převzatá mzda z PAMICA nese jen
            // své vlastní identifikátory, a spojit ji s naším přepočtem jde jedině tady,
            // kde je vztah právě dohledaný podle osobního čísla.
            $this->matched[(string) $record['relation_key']] = [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                // Druh vztahu a druh činnosti z MZ odvodit nejde (PAMICA má vlastní číselník),
                // ale evidenční list je bez nich nesestaví. Berou se proto z už převedeného
                // vztahu a jeho podmínek; chybí-li, zůstanou prázdné a list to řekne.
                'relation_type' => self::text($employment['relation_type'] ?? null),
                'activity_code' => $this->employmentActivityCode($supplierId, $employmentId),
            ];
            foreach ((array) $record['regular_benefits'] as $benefit) {
                $this->regularBenefits[(string) $benefit] = ($this->regularBenefits[(string) $benefit] ?? 0) + 1;
            }
            if (!isset($employees[$employeeId])) {
                $employees[$employeeId] = true;
                $this->part($protocol, $step, $number, 'Údaje o narození a občanství', fn (): array => $this->identity($supplierId, $employeeId, $record));
                $this->part($protocol, $step, $number, 'Adresa a kontakt', fn (): array => $this->personCard($supplierId, $employeeId, $record, $userId));
                $this->part($protocol, $step, $number, 'Daňová rezidence a prohlášení poplatníka', fn (): array => $this->statutoryEvidence($supplierId, $employeeId, $record, $today, $userId));
                $this->part($protocol, $step, $number, 'Počáteční stavy kumulací', fn (): array => $this->openingBalances($supplierId, $employeeId, $record, $year, $moduleStart, $userId));
                $this->part($protocol, $step, $number, 'Děti a daňové zvýhodnění', fn (): array => $this->children($supplierId, $employeeId, $record, $userId));
                $this->part($protocol, $step, $number, 'Výplatní účty', fn (): array => $this->payoutAccounts($supplierId, $employeeId, $record, $userId));
            }
            $this->part($protocol, $step, $number, 'Sjednaná měsíční mzda', fn (): array => $this->monthlyWage($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Předpis měsíční mzdy', fn (): array => $this->recurringWage($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Průměrný výdělek', fn (): array => $this->averageEarnings($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Nepřítomnosti', fn (): array => $this->absences($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Zůstatek dovolené', fn (): array => $this->leaveCarryover($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Pracoviště JMHZ', fn (): array => $this->workplace($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Kód CZ-ISCO', fn (): array => $this->czIsco($supplierId, $employmentId, $record, $userId));
            if ($record['oic'] !== null || $record['id_ppv'] !== null) {
                if ($confirmIdentifiers) {
                    $this->part($protocol, $step, $number, 'OIČ a ID PPV', fn (): array => $this->identifiers($supplierId, $employeeId, $employmentId, $record, $userId));
                } else {
                    $unconfirmed++;
                }
            }
            $this->part($protocol, $step, $number, 'Skončení vztahu', fn (): array => $this->termination($supplierId, $employmentId, $record, $today, $userId));
            $this->part($protocol, $step, $number, 'Zákonné termíny', fn (): array => $this->checklist($protocol, $step, $supplierId, $employmentId, $record, $userId));
        }

        if ($unconfirmed > 0) {
            $protocol->count($step, 'identifiers_unconfirmed', $unconfirmed);
            $protocol->warn($step, 'identifiers_unconfirmed', "OIČ nebo ID PPV z PAMICA má {$unconfirmed} vztahů. Převod je bez potvrzení, že čísla pocházejí z protokolů ČSSZ, nepřevzal. Potvrďte to v průvodci a převod zopakujte.");
        }
        if ($this->invalidOic !== []) {
            $protocol->warn($step, 'oic_invalid', sprintf(
                'OIČ z PAMICA nesedí na kontrolní číslici u %d osob (osobní čísla %s). Porovnejte je s protokolem ČSSZ a doplňte ručně.',
                count($this->invalidOic),
                implode(', ', array_slice($this->invalidOic, 0, 30)) . (count($this->invalidOic) > 30 ? ', …' : ''),
            ));
        }
        if ($institutions !== []) {
            $this->part($protocol, $step, '-', 'Účty institucí', fn (): array => $this->institutionAccounts($supplierId, $institutions, $year, $userId));
        }
        if ($this->institutionsToConfirm > 0) {
            $protocol->warn($step, 'institution_accounts_unconfirmed', sprintf(
                'Účtů ČSSZ a finančního úřadu převzatých z PAMICA: %d. Registr institucí je v PAMICA nemá, '
                . 'převod je odvodil z vystavených závazků podle předčíslí účtu u ČNB, a proto je založil s původem '
                . '„převzato z jiného systému". Platební dávka takový účet ODMÍTNE: než se z mezd zaplatí, otevřete '
                . 'Nastavení mezd → Účty institucí, porovnejte číslo účtu a symboly s rozhodnutím úřadu a uložte '
                . 'je jako ověřené.',
                $this->institutionsToConfirm,
            ));
        }
        if ($this->institutionGaps !== []) {
            $protocol->warn($step, 'institution_accounts_missing', sprintf(
                'Účty, které převod z PAMICA nedoložil a je nutné je zadat ručně v Nastavení mezd → Účty institucí: %s. '
                . 'Bez nich neprojde kontrola připravenosti běhu ani příprava plateb.',
                implode('; ', array_slice($this->institutionGaps, 0, 10))
                    . (count($this->institutionGaps) > 10 ? '; …' : ''),
            ));
        }
        if ($this->leaveTransferred > 0) {
            $protocol->info($step, 'leave_carryover', sprintf(
                'Zůstatek dovolené z PAMICA převzalo %d vztahů jako převod do knihy dovolené. Čerpání se nepřenáší: '
                . 'položku typu „čerpáno" kniha dovolené ručně zapsat neumí, vzniká jen ze schválené nepřítomnosti, '
                . 'a už je v převáděném zůstatku odečtené. V PAMICA bylo v převáděném roce vyčerpáno %d hodin.',
                $this->leaveTransferred,
                $this->leaveTakenHours,
            ));
        }
        if ($this->leaveShared > 0) {
            $protocol->warn($step, 'leave_shared', sprintf(
                'Zůstatek dovolené se nepřevzal u %d osob se souběžnými pracovními vztahy: PAMICA vede kartu dovolené '
                . 'na osobě, ne na vztahu, takže nejde poznat, kterému vztahu zůstatek patří. Zadejte ho ručně.',
                $this->leaveShared,
            ));
        }
        if ($this->regularBenefits !== []) {
            arsort($this->regularBenefits);
            $top = [];
            foreach (array_slice($this->regularBenefits, 0, 6, true) as $label => $count) {
                $top[] = "{$label} ({$count} vztahů)";
            }
            $protocol->warn($step, 'regular_benefits_manual', sprintf(
                'Pravidelná plnění z PAMICA, která převod nezakládá jako opakovanou složku: %s. Částka se u nich mění měsíc '
                . 'od měsíce (u zdanitelné části stravování nejde odvodit ani denní sazba, export nevede dny stravenek), takže '
                . 'pevný předpis by byl vymyšlený. V převedených měsících jsou tyto částky ve mzdových vstupech z PAMICA; '
                . 'pro další měsíce předpis nebo podklad nastavte ručně.',
                implode(', ', $top),
            ));
        }
        if ($this->absencesFromImport !== []) {
            arsort($this->absencesFromImport);
            $byType = [];
            foreach ($this->absencesFromImport as $type => $count) {
                $byType[] = (self::ABSENCE_LABELS[$type] ?? $type) . ' ' . $count;
            }
            $protocol->warn($step, 'absences_from_import', sprintf(
                'Nepřítomnosti, které převod nezapsal, protože tytéž hodiny nese souhrn z importu '
                . 'docházky: %s. Jeden údaj má mít jediný zdroj; kdyby ležely v měsíci se souhrnem, '
                . 'vedly by se dvakrát a krácení měsíční mzdy by se neprovedlo. Druhy, které souhrn '
                . 'nenese (peněžitá pomoc v mateřství, rodičovská, dlouhodobé ošetřovné), zapsané jsou.',
                implode(', ', $byType),
            ));
        }
        if ($this->absencesWithoutDates !== []) {
            $protocol->warn($step, 'absences_without_dates', sprintf(
                'Nepřítomností, které evidence vede jedině s daty od a do (nemoc, ošetřovné, otcovská, neplacené '
                . 'volno, neomluvená absence) a PAMICA k nim datum nemá: %d u osobních čísel %s. Zapsat je nejde, '
                . 'z hodin se den od ani do dopočítat nedá. Hodiny zůstaly v souhrnu z importu docházky, takže se '
                . 'neztratily, ale schválení měsíce si je vyžádá s daty: doplňte nepřítomnost v kartě zaměstnance '
                . 'a měsíc schvalte ručně.',
                array_sum($this->absencesWithoutDates),
                self::personalNumbers($this->absencesWithoutDates),
            ));
        }
        if ($this->absenceOverlaps !== []) {
            $protocol->warn($step, 'absences_overlap', sprintf(
                'Nepřítomností vynechaných pro překryv s jinou: %d u osobních čísel %s. Evidence dva druhy v týchž '
                . 'dnech nepovolí, takže se zapsala jen ta první a zbytek dne zůstal bez nepřítomnosti. Zkontrolujte '
                . 'je v kartě zaměstnance.',
                array_sum($this->absenceOverlaps),
                self::personalNumbers($this->absenceOverlaps),
            ));
        }
        if ($this->hourlyWageRelations > 0) {
            $protocol->warn($step, 'recurring_wage_hourly', sprintf(
                'Předpis základní měsíční mzdy nedostalo %d vztahů: mzdu mají v převáděných měsících i hodinovou nebo úkolovou '
                . 'a ta jde do běhu ze zpracovaných mezd. Základní mzda je u nich v PAMICA obsažená v týchž složkách, ne vedle nich, '
                . 'takže předpis by ji započetl podruhé. Zkontrolujte je a případný předpis zadejte ručně.',
                $this->hourlyWageRelations,
            ));
        }
        if ($this->accountsVerified > 0 || $this->accountsToVerify > 0) {
            $protocol->warn($step, 'payout_accounts_unverified', sprintf(
                'Výplatních účtů převzatých z PAMICA: %d, z toho ověřených %d a k ověření %d. Za ověřený se bere účet, '
                . 'na který předchozí mzdový systém opakovaně vyplácel mzdu: to je věcný doklad, ne domněnka. Datem '
                . 'ověření je den poslední výplaty z PAMICA a původ nese popisek účtu. Neověřený zůstává účet, který '
                . 'PAMICA vede jako neaktivní (mzda na něj nechodila) nebo u kterého výplatu nedoložila; ty ověřte '
                . 'v kartě osoby.',
                $this->accountsVerified + $this->accountsToVerify,
                $this->accountsVerified,
                $this->accountsToVerify,
            ));
        }
        if ($this->foreignLegislation !== []) {
            $protocol->count($step, 'social_jurisdiction_manual', count($this->foreignLegislation));
            $protocol->warn($step, 'social_jurisdiction_manual', sprintf(
                'Příslušnost k sociálnímu pojištění převod nevyplnil u %d osob: PAMICA je vede jako podléhající cizím právním předpisům. Stát a doklad A1 doplňte ručně.',
                count($this->foreignLegislation),
            ));
        }
        if ($this->completed !== []) {
            $protocol->info($step, 'checklist_completed', 'Zákonné termíny splněné podle dokladů z PAMICA: ' . self::describe($this->completed) . '.');
        }
        $open = $this->openChecklist($supplierId);
        if ($open !== []) {
            $protocol->info($step, 'checklist_open', 'Zákonné termíny, ke kterým PAMICA doklad nemá a zůstávají otevřené: ' . self::describe($open) . '.');
        }
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function identity(int $supplierId, int $employeeId, array $record): array
    {
        $identity = $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'))
            ?? throw new \DomainException('osoba nemá evidovanou identitu, údaje doplňte na kartě osoby.');
        $merged = [];
        $changed = false;
        foreach (['title_prefix', 'title_suffix', 'birth_date', 'birth_place', 'birth_country_code', 'citizenship_country_code', 'sex'] as $field) {
            $current = $identity[$field] ?? null;
            $current = $current === null || $current === '' ? null : (string) $current;
            $incoming = $record['identity'][$field] ?? null;
            if ($current === null && is_string($incoming) && $incoming !== '') {
                $current = $incoming;
                $changed = true;
            }
            $merged[$field] = $current;
        }
        if (!$changed) {
            return [];
        }
        $this->identities->saveIdentityFacts($supplierId, $employeeId, (int) $identity['id'], (int) $identity['row_version'], $merged);
        return ['identity' => 1];
    }

    /**
     * Adresa trvalého pobytu, kontaktní adresa, e-mail a telefon, rodné příjmení - jedno
     * uložení karty osoby (jeden optimistický zámek).
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function personCard(int $supplierId, int $employeeId, array $record, ?int $userId): array
    {
        $current = $this->profiles->get($supplierId, $employeeId)
            ?? throw new \DomainException('osobní karta zaměstnance nebyla nalezena.');
        $today = date('Y-m-d');
        $from = min(is_string($record['start']) ? $record['start'] : $today, $today);
        $addresses = [];
        foreach (['residence' => $record['residence'], 'mailing' => $record['mailing']] as $type => $address) {
            if (!is_array($address) || ($type === 'mailing' && $address === $record['residence'])) {
                continue;
            }
            foreach ($current['addresses'] as $row) {
                if (($row['address_type'] ?? null) === $type) {
                    continue 2;
                }
            }
            $addresses[] = $address + ['id' => null, 'address_type' => $type, 'effective_from' => $from, 'effective_to' => null];
        }
        $contacts = [];
        $hasContact = false;
        foreach ($current['contacts'] as $row) {
            $hasContact = $hasContact || (!empty($row['is_active']) && !empty($row['is_primary']));
        }
        if (!$hasContact) {
            if (is_string($record['email'])) {
                $contacts[] = ['id' => null, 'contact_type' => 'email', 'value' => $record['email'], 'is_primary' => true, 'is_active' => true];
            }
            if (is_string($record['phone'])) {
                $contacts[] = ['id' => null, 'contact_type' => 'phone', 'value' => $record['phone'], 'is_primary' => true, 'is_active' => true];
            }
        }
        $identity = [];
        if (is_string($record['birth_surname'])) {
            $version = self::covering($current['identity_history'], $today) ?? ($current['identity_history'][0] ?? null);
            if ($version !== null && ($version['birth_surname_masked'] ?? null) === null) {
                $identity[] = [
                    'id' => $version['id'],
                    'full_name' => $version['full_name'],
                    'first_name' => $version['first_name'],
                    'last_name' => $version['last_name'],
                    'birth_surname' => $record['birth_surname'],
                    'effective_from' => $version['effective_from'],
                    'effective_to' => $version['effective_to'],
                ];
            }
        }
        if ($addresses === [] && $contacts === [] && $identity === []) {
            return [];
        }
        $payload = [
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => $identity,
            'addresses' => $addresses,
            'contacts' => $contacts,
            'identifiers' => [],
            'accounts' => [],
        ];
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate($payload), $current['row_version'], $userId, null, null);
        return ['person_card' => 1];
    }

    /**
     * Daňová rezidence a prohlášení poplatníka - jen do prázdné řady zákonné evidence.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function statutoryEvidence(int $supplierId, int $employeeId, array $record, string $today, ?int $userId): array
    {
        $view = $this->statutory->editorView($supplierId, $employeeId, $today)
            ?? throw new \DomainException('zákonná evidence zaměstnance nebyla nalezena.');
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        $counts = [];
        if (($sections['tax_residences'] ?? []) === []) {
            if ($record['tax_residence'] === 'czech-resident') {
                $sections['tax_residences'] = [[
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'pamica:zam:rezident',
                    'effective_from' => self::monthStart((string) $record['first_period'], $record['start']),
                    'effective_to' => null,
                    'evidence_note' => self::NOTE . 'zaměstnanec není v PAMICA veden jako daňový nerezident.',
                ]];
                $counts['tax_residence'] = 1;
            } elseif ($record['tax_residence'] === 'non-resident') {
                throw new \DomainException('PAMICA vede zaměstnance jako daňového nerezidenta bez státu rezidence. Daňovou rezidenci doplňte ručně.');
            }
        }
        if (($sections['tax_declarations'] ?? []) === [] && $record['declarations'] !== []) {
            $rows = [];
            foreach ($record['declarations'] as $run) {
                $rows[] = [
                    'status' => $run['status'],
                    'evidence_reference' => 'pamica:mz-prohlas:' . $run['period'],
                    'effective_from' => $run['from'],
                    'effective_to' => $run['to'],
                    'evidence_note' => self::NOTE . ($run['status'] === 'signed' ? 'podepsané' : 'nepodepsané') . ' prohlášení poplatníka od mzdy za ' . self::czechPeriod($run['period']) . '.',
                ];
            }
            $sections['tax_declarations'] = $rows;
            $counts['tax_declarations'] = 1;
        }
        // Zdravotní pojištění: osoba založená druhým souběžným vztahem ho od importu mezd
        // nedostane, kód pojišťovny ale PAMICA má.
        if (($sections['health_coverages'] ?? []) === [] && is_string($record['insurer_code'])) {
            $sections['health_coverages'] = [[
                'jurisdiction' => 'czech_regime_verified',
                'foreign_country_code' => null,
                'jurisdiction_evidence_reference' => null,
                'insurer_status' => 'verified',
                'insurer_code' => $record['insurer_code'],
                'insurer_evidence_reference' => null,
                'health_evidence_document_id' => null,
                'health_evidence_document_sha256' => null,
                'effective_from' => self::monthStart((string) $record['first_period'], $record['start']),
                'effective_to' => null,
                'evidence_note' => self::NOTE . 'zdravotní pojišťovna ' . $record['insurer_code'] . ' z karty zaměstnance.',
            ]];
            $counts['health_coverage'] = 1;
        }
        // Příslušnost k sociálnímu pojištění: český režim bez A1, ledaže PAMICA vede
        // zaměstnance jako podléhajícího cizím právním předpisům (to se ručně ověří).
        if (($sections['social_jurisdictions'] ?? []) === []) {
            if ($record['foreign_legislation'] === true) {
                $this->foreignLegislation[] = (string) $record['personal_number'];
            } else {
                $sections['social_jurisdictions'] = [[
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                    'effective_from' => self::monthStart((string) $record['first_period'], $record['start']),
                    'effective_to' => null,
                    'evidence_note' => self::NOTE . 'zaměstnanec nepodléhá v PAMICA cizím právním předpisům.',
                ]];
                $counts['social_jurisdiction'] = 1;
            }
        }
        // Sleva pracujícího důchodce po měsících podle žádosti v PAMICA (SocPojSlevaZadost).
        if (($sections['social_discount_claims'] ?? []) === [] && $record['pensioner_discounts'] !== []) {
            $rows = [];
            foreach ($record['pensioner_discounts'] as $run) {
                $claimed = $run['status'] === 'verified';
                $rows[] = [
                    'status' => $run['status'],
                    'evidence_reference' => $claimed ? 'pamica:mz-socpojslevazadost:' . $run['period'] : null,
                    'effective_from' => $run['from'],
                    'effective_to' => $run['to'],
                    'evidence_note' => self::NOTE . ($claimed ? 'sleva pracujícího důchodce uplatněná' : 'sleva pracujícího důchodce se neuplatňuje')
                        . ' od mzdy za ' . self::czechPeriod($run['period']) . '.',
                ];
            }
            $sections['social_discount_claims'] = $rows;
            $counts['pensioner_discount'] = 1;
        }
        if ($counts === []) {
            return [];
        }
        $this->statutory->save($supplierId, $employeeId, ['sections' => $sections], $today, $userId, null, null);
        return $counts;
    }

    /**
     * Počáteční stavy ročních kumulací za měsíce roku před prvním obdobím, které zpracovává
     * MyÚčto (začátek vedení mezd). Jen souvislá řada měsíců a jen tam, kde stavy nejsou.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function openingBalances(int $supplierId, int $employeeId, array $record, int $year, ?string $moduleStart, ?int $userId): array
    {
        if ($moduleStart === null || (int) substr($moduleStart, 0, 4) !== $year) {
            return [];
        }
        $startMonth = (int) substr($moduleStart, 5, 2);
        /** @var array<int,array<string,int|bool>> $months */
        $months = array_filter((array) $record['months'], static fn (int $month): bool => $month < $startMonth, ARRAY_FILTER_USE_KEY);
        if ($months === []) {
            return [];
        }
        foreach ($this->openings->current($supplierId, $employeeId, $year)['openings'] as $id) {
            if ($id !== null) {
                return ['openings_existing' => 1];
            }
        }
        $numbers = array_keys($months);
        if (count($numbers) !== max($numbers) - min($numbers) + 1) {
            throw new \DomainException('mzdy v PAMICA před prvním obdobím MyÚčta nejsou za souvislou řadu měsíců, počáteční stavy zadejte ručně.');
        }
        $rows = [];
        foreach ($months as $month => $sums) {
            $rows[] = [
                'month' => $month,
                'social_assessment_base_minor_units' => (int) $sums['social'],
                'advance_base_minor_units' => (int) $sums['advance_base'],
                'advance_tax_minor_units' => (int) $sums['advance_tax'],
                'withholding_base_minor_units' => (int) $sums['withholding_base'],
                'withholding_tax_minor_units' => (int) $sums['withholding_tax'],
                'applied_non_refundable_credits_minor_units' => (int) $sums['non_refundable'],
                'applied_child_credit_minor_units' => (int) $sums['child'],
                'tax_bonus_minor_units' => (int) $sums['bonus'],
                'bonus_qualifying_income_minor_units' => (int) $sums['advance_base'],
            ];
        }
        $this->openings->save($supplierId, $employeeId, $year, $rows, sprintf('PAMICA: zpracované mzdy %d. až %d. měsíc %d', min($numbers), max($numbers), $year), $userId);
        return ['openings' => 1];
    }

    /**
     * Děti s daňovým zvýhodněním z `ZAMpDet` jako vyživované osoby s nárokem daného pořadí.
     * Jen osobě, která vyživované osoby ještě nemá. Vztah k dítěti PAMICA nevede (zapíše se
     * vlastní dítě) a nezná ani jinou vyživující osobu v domácnosti (zůstane nevyplněná).
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function children(int $supplierId, int $employeeId, array $record, ?int $userId): array
    {
        /** @var list<array<string,mixed>> $children */
        $children = $record['children'];
        $counts = (int) $record['children_without_credit'] > 0 ? ['children_without_credit' => 1] : [];
        if ($children === []) {
            return $counts;
        }
        // Doložený nárok stojí na podepsaném prohlášení; převod ho zapisuje od prvního
        // měsíce, kdy je prohlášení v PAMICA podepsané.
        if (!is_string($record['first_signed_period'])) {
            throw new \DomainException('dítě má v PAMICA daňové zvýhodnění, ale prohlášení poplatníka není v převáděném roce podepsané. Nárok doplňte ručně.');
        }
        $declaredFrom = $record['first_signed_period'] . '-01';
        $today = date('Y-m-d');
        $view = $this->dependants->overview($supplierId, $employeeId, $today)
            ?? throw new \DomainException('zaměstnanec nebyl nalezen.');
        if ($view['dependants'] !== []) {
            return $counts + ['children_existing' => 1];
        }
        $created = 0;
        foreach ($children as $child) {
            if (!is_string($child['birth_number'])) {
                throw new \DomainException('dítě s daňovým zvýhodněním nemá v PAMICA rodné číslo, doplňte ho ručně.');
            }
            $birthNumber = CzechBirthNumber::normalize($child['birth_number']);
            $birthDate = (string) CzechBirthNumber::birthDate($birthNumber);
            $from = is_string($child['from']) && $child['from'] > $birthDate ? $child['from'] : $birthDate;
            // Nárok běží po celých měsících a nesmí přesahovat dobu, po kterou je dítě vedené
            // jako vyživované: vyživování se proto vede od začátku měsíce nároku (nejdřív od
            // narození) do konce měsíce, kdy nárok v PAMICA končí.
            $claimFrom = max(substr($from, 0, 7) . '-01', $declaredFrom);
            $existenceFrom = max($birthDate, min($from, $claimFrom));
            if ($claimFrom < $existenceFrom) {
                $claimFrom = (new \DateTimeImmutable(substr($existenceFrom, 0, 7) . '-01'))->modify('+1 month')->format('Y-m-d');
            }
            $until = is_string($child['to']) ? $this->dependantValidator->monthEnd($child['to']) : null;
            if ($until !== null && $until < $claimFrom) {
                continue;
            }
            $name = trim(($child['given_name'] ?? '') . ' ' . ($child['family_name'] ?? ''));
            $known = array_column($view['dependants'], 'id');
            $view = $this->dependants->createDependant($supplierId, $employeeId, $this->dependantValidator->validateDependant([
                'relation' => 'child_own',
                'full_name' => $name !== '' ? $name : 'Dítě',
                'given_name' => $child['given_name'],
                'family_name' => $child['family_name'],
                'birth_date' => $birthDate,
                'birth_number' => $birthNumber,
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $existenceFrom,
                'existence_to' => $until,
                'note' => self::NOTE . 'dítě s daňovým zvýhodněním; vztah k dítěti PAMICA nevede, zapsáno jako vlastní dítě.',
            ]), $today, $userId, null, null);
            $dependantId = null;
            foreach ($view['dependants'] as $dependant) {
                if (!in_array($dependant['id'], $known, true)) {
                    $dependantId = (int) $dependant['id'];
                }
            }
            if ($dependantId === null) {
                throw new \DomainException('vyživovanou osobu se nepodařilo založit.');
            }
            $view = $this->dependants->createClaim($supplierId, $employeeId, $dependantId, $this->dependantValidator->validateClaim([
                'child_order' => $child['order'],
                'credit_status' => 'claimed',
                'claim_reason' => null,
                'evidence_status' => 'verified',
                'evidence_reference' => 'pamica:zampdet:' . $child['code'],
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => true,
                'ztp_p' => false,
                'effective_from' => $claimFrom,
                'effective_to' => $until,
                'other_household_caregiver_status' => null,
            ]), $today, $userId, null, null);
            $created++;
        }
        return $counts + ['children' => $created];
    }

    /**
     * Výplatní účty osoby z PAMICA. Ověření (doklad, potvrzení zaměstnance) zůstává na
     * účetní, proto se účet zapisuje jako neověřený.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function payoutAccounts(int $supplierId, int $employeeId, array $record, ?int $userId): array
    {
        /** @var list<array{account:string,bank_code:string,active:bool}> $accounts */
        $accounts = $record['accounts'];
        if ($accounts === []) {
            return [];
        }
        $current = $this->profiles->get($supplierId, $employeeId)
            ?? throw new \DomainException('osobní karta zaměstnance nebyla nalezena.');
        $today = date('Y-m-d');
        // Den poslední mzdy, kterou PAMICA na účet vyplatila. Nese ho popisek účtu, protože
        // pole pro odkaz na zdroj ověření tabulka výplatních účtů nemá.
        $paidOn = is_string($record['accounts_paid_on']) ? $record['accounts_paid_on'] : null;
        // Účty z dřívějšího běhu se nezakládají znovu — ověřit se ale musí. Dřív tu bylo
        // holé `return []`, což znamenalo, že převod spuštěný znovu nad už převedenou
        // firmou ověření NIKDY nedoplnil: krok skončil dřív, než se k němu dostal. Přesně
        // to potkalo instalace, kde účty založil starší běh, který ověřovat ještě neuměl.
        if ($current['accounts'] !== []) {
            return $this->verifyAccounts($supplierId, $employeeId, $paidOn, $userId);
        }
        $from = min(is_string($record['start']) ? $record['start'] : $today, $today);
        $rows = [];
        foreach ($accounts as $index => $account) {
            // Účet, který PAMICA vede jako neaktivní, mzdu nedostával: není aktivní ani tady
            // a podíl výplaty nemá.
            $primary = $index === 0 && $account['active'];
            $rows[] = [
                'id' => null,
                'label' => $primary
                    ? 'Výplatní účet z PAMICA' . ($paidOn === null ? '' : ', mzdy vypláceny do ' . $paidOn)
                    : ($account['active'] ? 'Další účet z PAMICA ' . ($index + 1) : 'Účet z PAMICA bez výplat ' . ($index + 1)),
                'bank_account' => $account['account'] . '/' . $account['bank_code'],
                'allocation_basis_points' => $primary ? 10000 : 0,
                'effective_from' => $from,
                'effective_to' => null,
                'is_active' => $primary,
            ];
        }
        // Mzda odcházela v PAMICA na účet, takže způsob výplaty je bankovní; hotovostní
        // dávka by jinak čekala na ruční přepnutí u každé osoby.
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate([
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'] === 'cash' ? 'bank' : $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => [],
            'addresses' => [],
            'contacts' => [],
            'identifiers' => [],
            'accounts' => $rows,
        ]), $current['row_version'], $userId, null, null);
        return ['payout_accounts' => count($rows)]
            + $this->verifyAccounts($supplierId, $employeeId, $paidOn, $userId);
    }

    /**
     * Ověření účtu: na účet předchozí mzdový systém opakovaně vyplácel mzdu, a to je
     * věcný doklad, ne domněnka. Zdroj `user_verified` je z přípustných hodnot nejbližší
     * (převod ani migrace mezi nimi nejsou) a původ nese popisek účtu, protože pole pro
     * odkaz na zdroj tabulka účtů nemá. Datum je den poslední výplaty z PAMICA.
     *
     * Je to samostatný krok schválně: pouští se i nad účty, které založil dřívější běh,
     * takže opakovaný převod dovede evidenci doplnit místo aby ji nechal, jak byla.
     *
     * @return array<string,int>
     */
    private function verifyAccounts(int $supplierId, int $employeeId, ?string $paidOn, ?int $userId): array
    {
        $saved = $this->profiles->get($supplierId, $employeeId);
        $unverified = array_values(array_filter(
            $saved['accounts'] ?? [],
            static fn (array $account): bool => $account['verification_source'] === null,
        ));
        if ($unverified === []) {
            return [];
        }
        // Bez přihlášeného uživatele nebo bez dokladu o výplatě ověřit nejde: `verified_by`
        // i `verified_on` jsou povinné společně (trigger z migrace 1271).
        if ($userId === null || $paidOn === null) {
            $this->accountsToVerify += count($unverified);
            return [];
        }
        $verified = 0;
        $pending = 0;
        foreach ($unverified as $account) {
            // Neaktivní účet ověřit nejde a ani nemá čím: v PAMICA je jen veden, mzda na něj
            // nechodila. Zůstane k rozhodnutí účetní.
            if ($account['is_active'] !== true) {
                $pending++;
                continue;
            }
            try {
                $this->accountVerification->verify(
                    $supplierId,
                    $employeeId,
                    $account['id'],
                    'user_verified',
                    $paidOn,
                    $userId,
                    $account['row_version'],
                );
                $verified++;
            } catch (\DomainException|\InvalidArgumentException|\RuntimeException) {
                $pending++;
            }
        }
        $this->accountsVerified += $verified;
        $this->accountsToVerify += $pending;
        $counts = [];
        if ($verified > 0) {
            $counts['payout_accounts_verified'] = $verified;
        }
        if ($pending > 0) {
            $counts['payout_accounts_to_verify'] = $pending;
        }
        return $counts;
    }

    /**
     * Sjednaná měsíční mzda v podmínkách vztahu podle historie v PAMICA. První verze se
     * opraví na místě, další se zakládají s účinností od měsíce změny. Bez ní běh nemá
     * z čeho spočítat základní mzdu.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function monthlyWage(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        /** @var list<array{from:string,amount:float,prorated:bool}> $wages */
        $wages = $record['monthly_wages'];
        if ($wages === []) {
            return [];
        }
        $employmentRow = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        // Ukončený vztah už podmínky měnit nedovolí a jeho mzdy jsou historie; sjednaná
        // mzda se u něj nepřenáší.
        if (in_array((string) $employmentRow['status'], ['ended', 'archived', 'no_show'], true)) {
            return ['monthly_wage_ended' => 1];
        }
        $counts = $wages[0]['prorated'] === true ? ['monthly_wage_max' => 1] : [];
        $written = 0;
        $settled = 0;
        foreach ($wages as $index => $wage) {
            $minor = (int) round($wage['amount'] * 100);
            if ($minor <= 0) {
                continue;
            }
            $current = $this->employments->currentTerms($supplierId, $employmentId)
                ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
            if ((int) ($current['monthly_gross_minor'] ?? 0) === $minor && (string) $current['effective_from'] >= $wage['from']) {
                continue;
            }
            // Verze vztahu se po každém zápisu mění, proto se čte znovu před každou verzí mzdy.
            $employment = $this->employmentById($supplierId, $employmentId);
            $body = RegistrationImportWriter::termsBody($current, self::PAMICA_WAGE_NOTE);
            $terms = $this->employmentValidator->terms(
                $body + ['effective_from' => $wage['from']],
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            );
            // První verze podmínek se opraví na místě (vztah začal dřív, než převod sahá),
            // pozdější změna mzdy je nová verze od měsíce, ve kterém ji PAMICA zvedla.
            if ($index === 0 || $wage['from'] <= (string) $current['effective_from']) {
                $terms['effective_from'] = (string) $current['effective_from'];
                try {
                    $this->employments->correctTerms($supplierId, $employmentId, $terms, (int) $employment['row_version'], $userId, null, null, true, $minor);
                } catch (PayrollTermsSettledException $e) {
                    // Z verze už bylo zúčtováno: mzda se zapíše jako nová verze od měsíce,
                    // který uzavřený běh nepokrývá.
                    $from = self::nextMonth($e->settledPeriod);
                    if ($from === null) {
                        throw $e;
                    }
                    $terms['effective_from'] = $from;
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $employment['row_version'], $userId, null, null, true, $minor);
                }
            } else {
                try {
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $employment['row_version'], $userId, null, null, true, $minor);
                } catch (PayrollTermsSettledException $e) {
                    $from = self::nextMonth($e->settledPeriod);
                    if ($from === null || $from <= (string) $current['effective_from']) {
                        $settled++;
                        continue;
                    }
                    $terms['effective_from'] = $from;
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $employment['row_version'], $userId, null, null, true, $minor);
                }
            }
            $written++;
        }
        if ($settled > 0) {
            $counts['monthly_wage_settled'] = $settled;
        }
        return $written > 0 ? $counts + ['monthly_wage' => $written] : $counts;
    }

    /**
     * Předpis pravidelné měsíční mzdy (`MZDA_MESICNI`, druh `base_wage`). Bez něj za období
     * nevznikne vstup základní mzdy a běh počítá jen to, co přišlo z docházky.
     *
     * Předpis dostane každý vztah se sjednanou měsíční mzdou, a to od prvního převáděného
     * měsíce (ořízne se na nástup a na platnost složky v číselníku); další verze mzdy
     * předchozí předpis ukončí, takže řada je souvislá bez děr a překryvů. Rozpočítání je
     * podle kalendářních dnů, takže nástup nebo skončení v půlce měsíce krátí částku samo
     * ({@see PayrollRecurringAmountCalculator}).
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function recurringWage(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        /** @var list<array{from:string,amount:float,prorated:bool}> $wages */
        $wages = $record['monthly_wages'];
        if ($wages === []) {
            return [];
        }
        /*
         * Vztah, který má v převáděných měsících hodinovou nebo úkolovou mzdu, předpis
         * NEDOSTANE. Tyhle složky jdou do běhu jako vstupy z docházky a `KcZaklM` je v PAMICA
         * nese v sobě, ne vedle nich: ověřeno na spočítaném běhu za 6/2026, kde všech 116
         * takových vztahů vyšlo výš (o 4,32 mil. Kč), tedy dvojí započtení. Základní mzdu
         * u nich zadá účetní, protokol je spočítá.
         */
        if ($record['hourly_wage'] === true) {
            $this->hourlyWageRelations++;
            return ['recurring_wage_hourly' => 1];
        }
        $counts = [];
        $component = $this->monthlyWageComponent($supplierId);
        if ($component === null) {
            throw new \DomainException('firma nemá v číselníku složku základní měsíční mzdy (MZDA_MESICNI).');
        }
        $componentId = (int) $component['id'];
        $employment = $this->employmentById($supplierId, $employmentId);
        // Předpis musí ležet uvnitř trvání vztahu i platnosti složky v číselníku.
        $lower = max(
            (string) ($employment['actual_start_date'] ?? $employment['start_date'] ?? '0000-01-01'),
            (string) ($component['valid_from'] ?? '0000-01-01'),
        );
        $upper = null;
        foreach ([$employment['end_date'] ?? null, $component['valid_to'] ?? null] as $limit) {
            if (is_string($limit) && ($upper === null || $limit < $upper)) {
                $upper = $limit;
            }
        }
        $existing = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? AND component_id = ?'
        );
        $existing->execute([$supplierId, $employmentId, $componentId]);
        if ((int) $existing->fetchColumn() > 0) {
            return $counts;
        }
        $written = 0;
        foreach ($wages as $index => $wage) {
            $minor = (int) round($wage['amount'] * 100);
            if ($minor <= 0) {
                continue;
            }
            $next = $wages[$index + 1]['from'] ?? null;
            $from = max($wage['from'], $lower);
            $to = $next === null ? null : (new \DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
            if ($upper !== null && ($to === null || $to > $upper)) {
                $to = $upper;
            }
            if ($to !== null && $to < $from) {
                continue;
            }
            $this->recurring->create($supplierId, $this->recurringValidator->validate([
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'calculation_kind' => 'fixed_amount',
                'amount_minor' => $minor,
                'rate_basis_points' => null,
                'valid_from' => $from,
                'valid_to' => $to,
                'allocation_rule' => 'calendar_days',
                'maximum_amount_minor' => null,
                'note' => self::NOTE . 'sjednaná měsíční mzda ze zpracovaných mezd.',
                'is_active' => true,
            ]), $userId);
            $written++;
        }
        return $written > 0 ? $counts + ['recurring_wage' => $written] : $counts;
    }

    /** @return array<string,mixed>|null složka základní měsíční mzdy i s platností v číselníku */
    private function monthlyWageComponent(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, valid_from, valid_to FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = 'MZDA_MESICNI' AND is_active = 1
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Čtvrtletní průměrný výdělek pro náhrady: hodnota, se kterou počítala PAMICA, jako
     * schválený snímek. Rozhodné období a výdělek v něm jdou do snímku jako doložení.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function averageEarnings(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        /** @var list<array<string,mixed>> $averages */
        $averages = $record['averages'];
        if ($averages === []) {
            return [];
        }
        $written = 0;
        foreach ($averages as $average) {
            $hourlyMinor = (int) round(((float) $average['hourly']) * 100);
            if ($hourlyMinor <= 0) {
                continue;
            }
            $quarterStart = sprintf('%04d-%02d-01', (int) $average['year'], ((int) $average['quarter'] - 1) * 3 + 1);
            if ($this->averages->findApproved($supplierId, $employmentId, (int) $average['year'], (int) $average['quarter']) !== null) {
                continue;
            }
            // `probable`, ne `actual`: rozhodné období leží před převodem a jeho odpracované
            // hodiny a dny export nenese. Hodnota je ta, se kterou PAMICA počítala náhrady.
            $result = new AverageEarningResult('probable', $hourlyMinor, 'supported', [
                'source' => 'pamica',
                'hourly_minor' => $hourlyMinor,
            ]);
            $snapshot = $this->averages->create(
                $supplierId,
                $employmentId,
                (int) $average['year'],
                (int) $average['quarter'],
                (string) $average['from'],
                (string) $average['to'],
                (int) round(((float) $average['gross']) * 100),
                0,
                (int) round(((float) $average['worked']) * 60),
                (int) round((float) $average['days']),
                self::NOTE . 'průměrný výdělek, se kterým PAMICA počítala náhrady čtvrtletí.',
                $result,
                $this->rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $quarterStart),
                $userId,
            );
            $this->averages->approve($supplierId, (int) $snapshot['id'], (int) $snapshot['row_version'], $userId);
            $written++;
        }
        return $written > 0 ? ['averages' => $written] : [];
    }

    /**
     * Nepřítomnosti z PAMICA s daty od a do; u peněžité pomoci v mateřství i den porodu.
     * Schvalují se jen ty, které schválení pustí (u náhrady z průměru musí existovat
     * schválený průměr); ostatní zůstanou zapsané k rozhodnutí.
     *
     * Nepřítomnost, kterou `MZneprit` nenese s daty, se nezapisuje: den od ani do se
     * z hodin dopočítat nedá. Její hodiny proto zůstávají v měsíčním sešitu a protokol
     * ji hlásí s osobním číslem.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function absences(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        /** @var list<array<string,mixed>> $absences */
        $absences = $record['absences'];
        $number = (string) $record['personal_number'];
        $counts = [];
        $undated = (int) ($record['absences_without_dates'] ?? 0);
        if ($undated > 0) {
            $this->absencesWithoutDates[$number] = $undated;
            $counts['absences_without_dates'] = $undated;
        }
        if ($absences === []) {
            return $counts;
        }
        $existing = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND employment_id = ?');
        $existing->execute([$supplierId, $employmentId]);
        if ((int) $existing->fetchColumn() > 0) {
            return $counts + ['absences_existing' => 1];
        }
        $written = 0;
        $overlaps = 0;
        $approved = 0;
        $fromImport = 0;
        foreach (self::mergedAbsences($absences, $overlaps) as $absence) {
            // Měsíc, jehož docházku nese souhrn z importu, má tytéž hodiny i náhradu už z něj.
            // Zapsat k němu ještě nepřítomnost s daty znamená vést jeden údaj dvakrát a krácení
            // měsíční mzdy se pak neprovede vůbec: `PayrollWageProrationService` takový měsíc
            // odmítne měřit. Nestačí nechat nepřítomnost neschválenou, protože se do překážky
            // počítá i nerozhodnutá.
            $type = (string) $absence['type'];
            if ($this->carriedByImportSummary($supplierId, $employmentId, $type, (string) $absence['from'], (string) $absence['to'])) {
                $this->absencesFromImport[$type] = ($this->absencesFromImport[$type] ?? 0) + 1;
                $fromImport++;
                continue;
            }
            $body = [
                'employment_id' => $employmentId,
                'absence_type' => $absence['type'],
                'date_from' => $absence['from'],
                'date_to' => $absence['to'],
                'note' => self::NOTE . 'nepřítomnost ze zpracovaných mezd.',
            ];
            if ($absence['type'] === 'ppm' && is_string($absence['childbirth'])) {
                $body['expected_childbirth_date'] = $absence['childbirth'];
                $body['childbirth_date'] = $absence['childbirth'];
            }
            try {
                $created = $this->absences->create($supplierId, $this->absenceValidator->absence($body), $userId);
            } catch (PayrollAbsenceOverlapException) {
                // Týž den už nepřítomnost má (jiný druh z téže mzdy): druhý zápis se vynechá.
                $overlaps++;
                continue;
            } catch (\DomainException|\InvalidArgumentException) {
                // Nepřípustné datum nebo uzavřený rok: nechá se na účetní.
                continue;
            }
            $written++;
            // Schvaluje se vše, co schválení pustí: druhy bez náhrady z průměru rovnou,
            // ostatní tehdy, když čtvrtletí už má schválený průměr. Nerozhodnutá
            // nepřítomnost jinak blokuje schválení pracovního měsíce.
            $quarter = (int) ceil(((int) substr((string) $absence['from'], 5, 2)) / 3);
            $hasAverage = $this->averages->findApproved($supplierId, $employmentId, (int) substr((string) $absence['from'], 0, 4), $quarter) !== null;
            if (!in_array($absence['type'], PayrollAbsenceValidator::TYPES_REQUIRING_AVERAGE, true) || $hasAverage) {
                try {
                    $this->absences->decide($supplierId, (int) $created['id'], (int) $created['row_version'], 'approved', $userId);
                    $approved++;
                } catch (\DomainException|\InvalidArgumentException) {
                    continue;
                }
            }
        }
        if ($overlaps > 0) {
            $this->absenceOverlaps[$number] = $overlaps;
            $counts['absences_overlap'] = $overlaps;
        }
        if ($approved > 0) {
            $counts['absences_approved'] = $approved;
        }
        if ($fromImport > 0) {
            $counts['absences_from_import'] = $fromImport;
        }
        return $written > 0 ? $counts + ['absences' => $written] : $counts;
    }

    /**
     * Nese tutéž dobu souhrn z importu docházky? Rozhoduje se podle skutečných hodin
     * souhrnu daného měsíce, ne podle druhu nepřítomnosti: podklady se firmu od firmy
     * liší a co v souhrnu opravdu je, má mít jediný zdroj. Nepřítomnost přes víc měsíců
     * se vynechá jen tehdy, když ji nese souhrn v každém z nich, jinak by se část
     * evidence ztratila.
     */
    private function carriedByImportSummary(int $supplierId, int $employmentId, string $type, string $from, string $to): bool
    {
        $meanings = self::IMPORT_SUMMARY_ABSENCE_HOURS[$type] ?? [];
        if ($meanings === []) {
            return false;
        }
        $cursor = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 7) . '-01');
        if ($cursor === false) {
            return false;
        }
        $last = substr($to, 0, 7);
        while ($cursor->format('Y-m') <= $last) {
            $month = $cursor->format('Y-m');
            $key = $employmentId . '|' . $month;
            if (!array_key_exists($key, $this->importSummaries)) {
                $summary = $this->time->importSummary($supplierId, $employmentId, $month . '-01');
                $this->importSummaries[$key] = $summary === null ? null : $summary['values'];
            }
            $values = $this->importSummaries[$key];
            $carried = false;
            foreach ($meanings as $meaning) {
                if ($values !== null && (int) ($values[$meaning] ?? 0) > 0) {
                    $carried = true;
                    break;
                }
            }
            if (!$carried) {
                return false;
            }
            $cursor = $cursor->modify('+1 month');
        }

        return true;
    }

    /** První den měsíce následujícího po uzavřeném období, nebo null u neznámého tvaru. */
    private static function nextMonth(string $period): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})/', $period, $match) !== 1) {
            return null;
        }
        return (new \DateTimeImmutable("{$match[1]}-{$match[2]}-01"))->modify('+1 month')->format('Y-m-d');
    }

    /**
     * Souvislé nepřítomnosti téhož druhu (tentýž případ rozepsaný po měsících) se spojí
     * a překryv dvou různých druhů v týchž dnech se ořízne: evidence překryv nepovolí
     * a nezapsaná nepřítomnost by pak blokovala schválení pracovního měsíce.
     *
     * @param list<array<string,mixed>> $absences
     * @return list<array<string,mixed>>
     */
    private static function mergedAbsences(array $absences, int &$trimmed): array
    {
        usort($absences, static fn (array $a, array $b): int => [$a['from'], $a['to'], $a['type']] <=> [$b['from'], $b['to'], $b['type']]);
        $out = [];
        foreach ($absences as $absence) {
            $last = $out === [] ? null : array_key_last($out);
            if ($last !== null && $out[$last]['type'] === $absence['type']
                && (new \DateTimeImmutable($out[$last]['to']))->modify('+1 day')->format('Y-m-d') >= $absence['from']
            ) {
                $out[$last]['to'] = max($out[$last]['to'], $absence['to']);
                if ($out[$last]['childbirth'] === null) {
                    $out[$last]['childbirth'] = $absence['childbirth'];
                }
                continue;
            }
            if ($last !== null && $out[$last]['to'] >= $absence['from']) {
                // Jiný druh ve stejných dnech: zapíše se jen část, která zbývá.
                $trimmed++;
                $absence['from'] = (new \DateTimeImmutable($out[$last]['to']))->modify('+1 day')->format('Y-m-d');
                if ($absence['from'] > $absence['to']) {
                    continue;
                }
            }
            $out[] = $absence;
        }
        return $out;
    }

    /**
     * Účty příjemců odvodů z PAMICA, jednou za firmu.
     *
     * Zdravotní pojišťovnu nese registr `sMzPoj` i s číslem účtu, takže je to sdělení
     * instituce (`institution_notice`) a platební cesta ho uznává. Účet ČSSZ a finančního
     * úřadu registr PAMICA NENESE - odvozuje se z vystavených závazků podle předčíslí
     * účtu u ČNB, což je doklad o tom, kam předchozí systém platil, ne rozhodnutí úřadu.
     * Takový účet se proto zakládá s původem `imported`: platební cesta ho odmítne
     * ({@see \MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder}), dokud ho
     * účetní neporovná s výměrem a neuloží znovu. Radši nepoužitelný účet než tiše
     * špatně nasměrovaná platba odvodů.
     *
     * Příjemce, pro kterého se účet nenašel, se NEZAKLÁDÁ ani jako holá identita: účet je
     * v evidenci povinný, instituce bez něj se nikde neukáže a jediné, co by přinesla, je
     * dojem, že je vyřízená. Místo toho jde do protokolu, co přesně má účetní doplnit.
     *
     * @param list<array<string,mixed>> $institutions
     * @return array<string,int>
     */
    private function institutionAccounts(int $supplierId, array $institutions, int $year, ?int $userId): array
    {
        $known = [];
        foreach ($this->institutions->list($supplierId) as $account) {
            $known[(string) ($account['institution_type'] ?? '') . '|' . (string) ($account['institution_code'] ?? '')] = true;
        }
        $officeCode = $this->socialSecurityOfficeCode($supplierId);
        $taxVariableSymbol = $this->taxPayerVariableSymbol($supplierId);
        $written = 0;
        foreach ($institutions as $institution) {
            $type = (string) ($institution['type'] ?? 'health_insurer');
            // Kód pracoviště ČSSZ je NAŠE klasifikace platebního cíle: příprava plateb hledá
            // účet pod kódem z nastavení zaměstnavatele, takže ten má přednost před kódem
            // z podání PAMICA. Bez obou se účet nedá dohledat a zakládat ho nemá smysl.
            $code = $type === 'social_security'
                ? ($officeCode ?? self::text($institution['code'] ?? null))
                : self::text($institution['code'] ?? null);
            if ($code === null) {
                $this->institutionGaps[] = 'Správa sociálního zabezpečení: kód pracoviště není ani v nastavení '
                    . 'zaměstnavatele, ani v podáních PAMICA';
                continue;
            }
            $key = $type . '|' . $code;
            if (isset($known[$key])) {
                continue;
            }
            $account = self::text($institution['account'] ?? null);
            $bankCode = self::text($institution['bank_code'] ?? null);
            if ($account === null || $bankCode === null) {
                $this->institutionGaps[] = self::institutionGap($institution, $code);
                continue;
            }
            $notice = $type === 'health_insurer';
            // Variabilní symbol u finančního úřadu je kmenová část DIČ plátce, ne symbol
            // z dokladu: doklad může nést symbol opravný nebo cizí.
            $variableSymbol = $type === 'tax_office'
                ? $taxVariableSymbol
                : self::text($institution['variable_symbol'] ?? null);
            if ($type === 'tax_office' && $variableSymbol === null) {
                $this->institutionGaps[] = sprintf(
                    'Finanční úřad (%s): variabilní symbol zůstal prázdný, firma nemá vyplněné DIČ',
                    $code,
                );
            }
            $this->institutions->create($supplierId, [
                'institution_type' => $type,
                'institution_code' => $code,
                'institution_name' => self::institutionName($institution, $type, $code),
                'bank_account' => $account . '/' . $bankCode,
                'currency_code' => 'CZK',
                'variable_symbol' => $variableSymbol,
                'specific_symbol' => null,
                'constant_symbol' => null,
                'valid_from' => sprintf('%04d-01-01', $year),
                'valid_to' => null,
                'source_kind' => $notice ? 'institution_notice' : 'imported',
                'source_reference' => $notice
                    ? 'Převzato z registru PAMICA' . ($institution['data_box'] !== null ? ', datová schránka ' . $institution['data_box'] : '')
                    : mb_substr('Odvozeno z ' . (self::text($institution['source'] ?? null) ?? 'dokladů PAMICA')
                        . '; nepotvrzeno účetní', 0, 500),
                'verified_on' => date('Y-m-d'),
            ], $userId);
            $known[$key] = true;
            $written++;
            if (!$notice) {
                $this->institutionsToConfirm++;
            }
        }
        return $written > 0 ? ['institution_accounts' => $written] : [];
    }

    /**
     * Proč se pro příjemce nezaložil účet - věta do protokolu, ne kód chyby.
     *
     * @param array<string,mixed> $institution
     */
    private static function institutionGap(array $institution, string $code): string
    {
        $name = self::text($institution['name'] ?? null) ?? $code;
        return match ($institution['issue'] ?? null) {
            'ambiguous' => sprintf(
                '%s: v závazcích PAMICA je pod stejným předčíslím %d různých účtů, převod mezi nimi nevybírá',
                $name,
                (int) ($institution['candidates'] ?? 0),
            ),
            default => sprintf(
                '%s: export PAMICA číslo účtu nenese (číselník úřadů je v nastavení programu, které se neexportuje, '
                . 'a vystavené závazky v exportu nejsou)',
                $name,
            ),
        };
    }

    /**
     * @param array<string,mixed> $institution
     */
    private static function institutionName(array $institution, string $type, string $code): string
    {
        $name = self::text($institution['name'] ?? null);
        if ($name !== null) {
            return mb_substr($name, 0, 190);
        }
        return $type === 'health_insurer' ? 'Zdravotní pojišťovna ' . $code : $code;
    }

    /** Kód pracoviště ČSSZ z nastavení zaměstnavatele; pod ním hledá účet příprava plateb. */
    private function socialSecurityOfficeCode(int $supplierId): ?string
    {
        if (!$this->db->hasTable('payroll_employer_settings')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT social_security_office_code FROM payroll_employer_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        $value = is_string($value) ? strtoupper(trim($value)) : '';
        return preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $value) === 1 ? $value : null;
    }

    /** Kmenová část DIČ firmy („CZ12345678" => „12345678"); VS odvodů finančnímu úřadu. */
    private function taxPayerVariableSymbol(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        $digits = is_string($value) ? (string) preg_replace('/\D/', '', $value) : '';
        return preg_match('/^[0-9]{1,10}$/D', $digits) === 1 ? $digits : null;
    }

    /**
     * Zůstatek dovolené z PAMICA jako převod (`carryover`) do knihy dovolené.
     *
     * Zapisuje se JEN zůstatek, ne čerpání. Ruční `taken` za období před zahájením vedení
     * mezd kniha dovolené sice přijmout umí, ale v převáděném zůstatku je čerpání už
     * odečtené, takže by se počítalo dvakrát; kdo chce čerpání i po jednotlivých položkách,
     * zadá je ručně a zůstatek si o ně sníží. Záporný zůstatek se nepřevádí vůbec -
     * přečerpání je rozhodnutí zaměstnavatele, ne údaj k opsání.
     *
     * Účinnost má den, od kterého mzdy vede MyÚčto; od té chvíle se z knihy odečítá.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function leaveCarryover(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        $leave = $record['leave'] ?? null;
        if (!is_array($leave)) {
            if (($record['leave_shared'] ?? false) === true) {
                $this->leaveShared++;
            }
            return [];
        }
        $this->leaveTakenHours += (int) round((float) $leave['taken_hours']);
        $year = (int) $leave['year'];
        $minutes = (int) round(((float) $leave['balance_hours']) * 60);
        if ($minutes <= 0) {
            return $minutes < 0 ? ['leave_overdrawn' => 1] : [];
        }
        $existing = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ? AND entry_type = 'carryover'"
        );
        $existing->execute([$supplierId, $employmentId, $year]);
        if ((int) $existing->fetchColumn() > 0) {
            return ['leave_existing' => 1];
        }
        $from = (string) $record['transfer_start'] . '-01';
        if (substr($from, 0, 4) !== (string) $year) {
            $from = sprintf('%04d-01-01', $year);
        }
        $daily = $leave['daily_hours'] === null ? null : (float) $leave['daily_hours'];
        $reason = self::NOTE . 'zůstatek dovolené ke dni převodu, ' . self::decimal((float) $leave['balance_hours']) . ' h'
            . ($leave['balance_days'] === null || $daily === null
                ? ''
                : ' (' . self::decimal((float) $leave['balance_days']) . ' dne při úvazku ' . self::decimal($daily) . ' h denně)')
            . ($leave['from_days'] === true ? '; export nesl jen dny, hodiny dopočteny denním úvazkem vztahu' : '')
            . '.';
        $this->leave->appendManual($supplierId, $employmentId, $year, $from, 'carryover', $minutes, $reason, $userId);
        $this->leaveTransferred++;
        return ['leave_carryover' => 1];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Číslo do věty protokolu: desetinná čárka, bez zbytečných nul. */
    private static function decimal(float $value): string
    {
        $text = number_format($value, 2, ',', ' ');
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function workplace(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        $place = $record['workplace'];
        if (!is_array($place)) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if (($current['jmhz_workplace_municipality_code'] ?? null) !== null) {
            return [];
        }
        $workPlace = trim((string) ($current['work_place'] ?? ''));
        if ($workPlace !== '' && $workPlace !== $place['work_place']) {
            throw new \DomainException("místo výkonu práce na vztahu ({$workPlace}) se liší od obce pracoviště v PAMICA, kód obce doplňte ručně.");
        }
        $changes = [
            'work_place' => $place['work_place'],
            'jmhz_workplace_municipality_code' => $place['municipality_code'],
            'jmhz_workplace_country_code' => $place['country_code'],
        ];
        if (trim((string) ($current['regular_workplace'] ?? '')) === '' && $place['regular_workplace'] !== null) {
            $changes['regular_workplace'] = $place['regular_workplace'];
        }
        $this->correctTerms($supplierId, $employmentId, $current, $changes, $userId);
        return ['workplace' => 1];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function czIsco(int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        if (!is_string($record['cz_isco'])) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if (($current['cz_isco_code'] ?? null) !== null && $current['cz_isco_code'] !== '') {
            return [];
        }
        $this->correctTerms($supplierId, $employmentId, $current, ['cz_isco_code' => $record['cz_isco']], $userId);
        return ['cz_isco' => 1];
    }

    /**
     * OIČ a ID PPV z PAMICA - uživatel v průvodci potvrdil, že pocházejí z protokolů ČSSZ.
     * Platí od nástupu, uložená čísla se nepřepisují.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function identifiers(int $supplierId, int $employeeId, int $employmentId, array $record, ?int $userId): array
    {
        $employment = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        $validFrom = $employment['actual_start_date'] ?? $employment['start_date'];
        if ($validFrom === null) {
            throw new \DomainException('vztah nemá datum nástupu, OIČ a ID PPV doplňte ručně.');
        }
        $validFrom = (string) $validFrom;
        $counts = [];
        $oic = is_string($record['oic']) ? $record['oic'] : null;
        if ($oic !== null) {
            try {
                $oic = PayrollRegistrationIdentityService::oic($oic);
            } catch (\InvalidArgumentException) {
                $this->invalidOic[] = (string) $record['personal_number'];
                $counts['oic_invalid'] = 1;
                $oic = null;
            }
        }
        if ($oic !== null && $this->registrations->personExternalIdAt($supplierId, $employeeId, self::ENVIRONMENT, 'ik_mpsv', $validFrom) !== null) {
            $oic = null;
        }
        $ppv = is_string($record['id_ppv']) ? $record['id_ppv'] : null;
        if ($ppv !== null && $this->registrations->externalIdAt($supplierId, $employmentId, self::ENVIRONMENT, 'id_ppv', $validFrom) !== null) {
            $ppv = null;
        }
        if ($oic === null && $ppv === null) {
            return $counts;
        }
        $this->identities->assignManualJmhzIdentity(
            $supplierId,
            $employmentId,
            self::ENVIRONMENT,
            $oic,
            $ppv,
            $validFrom,
            'Převzato z PAMICA, osobní číslo ' . $record['personal_number'],
            true,
            $userId,
        );
        if ($oic !== null) {
            $counts['oic'] = 1;
        }
        if ($ppv !== null) {
            $counts['id_ppv'] = 1;
        }
        return $counts;
    }

    /**
     * Skončení vztahu k datu z PAMICA. Budoucí skončení (smlouva na dobu určitou) převod
     * jen spočítá, zapíše se až v den skončení běžnou cestou.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function termination(int $supplierId, int $employmentId, array $record, string $today, ?int $userId): array
    {
        $end = $record['end'];
        if (!is_string($end)) {
            return [];
        }
        if ($end > $today) {
            return ['end_planned' => 1];
        }
        $employment = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        if (!in_array($employment['status'], ['active', 'suspended'], true)) {
            return [];
        }
        $this->employments->transition(
            $supplierId,
            $employmentId,
            'ended',
            (int) $employment['row_version'],
            $end,
            self::NOTE . 'vztah skončil ' . self::czechDate($end) . '.',
            $userId,
            null,
            null,
        );
        return ['ended' => 1];
    }

    /**
     * Odškrtne nevyřízené položky checklistu, ke kterým PAMICA nese doklad.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function checklist(ImportProtocol $protocol, string $step, int $supplierId, int $employmentId, array $record, ?int $userId): array
    {
        $notes = self::evidenceNotes($record);
        $stmt = $this->db->pdo()->prepare(
            "SELECT phase, item_key, row_version FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND status = 'pending' ORDER BY id"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $done = 0;
        $changeNote = false;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            $key = (string) $item['item_key'];
            if ($item['phase'] === 'change' && in_array($key, self::CHANGE_ITEMS, true)) {
                $changeNote = $changeNote === false ? $this->changeArtefactNote($supplierId, $employmentId) : $changeNote;
                if ($changeNote !== null) {
                    $notes[$key] = $changeNote;
                }
            }
            if (!isset($notes[$key])) {
                continue;
            }
            try {
                $this->employments->updateChecklist($supplierId, $employmentId, $key, (int) $item['row_version'], 'completed', $notes[$key], $userId, null, null);
            } catch (PayrollEmploymentConflictException|PayrollEmploymentNotFoundException|\DomainException|\InvalidArgumentException $e) {
                $this->warn($protocol, $step, 'checklist_failed', "Osobní číslo {$record['personal_number']}: položku {$key} se nepodařilo odškrtnout: {$e->getMessage()}", (string) $record['personal_number']);
                continue;
            }
            $this->completed[$key] = ($this->completed[$key] ?? 0) + 1;
            $done++;
        }
        return $done > 0 ? ['checklist_completed' => $done] : [];
    }

    /**
     * Poznámka k položce checklistu podle dokladu z PAMICA; položka bez dokladu chybí.
     *
     * @param array<string,mixed> $record
     * @return array<string,string>
     */
    private static function evidenceNotes(array $record): array
    {
        $evidence = (array) $record['evidence'];
        $notes = [];
        if (is_string($record['start'])) {
            $notes['employment_contract'] = self::NOTE . 'vztah vedený v předchozím mzdovém systému, nástup ' . self::czechDate($record['start']) . '.';
        }
        if (is_string($record['first_signed_period'])) {
            $notes['tax_declaration'] = self::NOTE . 'podepsané prohlášení poplatníka, mzda za ' . self::czechPeriod($record['first_signed_period']) . '.';
        }
        // Vztah vzniklý před prvním převáděným měsícem: přihlášky podával předchozí systém,
        // PAMICA ale oznámení drží jen za poslední roky.
        $beforeTransfer = is_string($record['start']) && $record['start'] < $record['transfer_start'] . '-01';
        // Stav oznámení 2 mají v PAMICA téměř všechna oznámení; jiný stav se bere jako nedokončené.
        $healthStart = $evidence['health_start'] ?? null;
        if (is_array($healthStart) && $healthStart['state'] === '2') {
            $notes['health_insurance_registration'] = self::NOTE . 'oznámení zdravotní pojišťovně o nástupu k ' . self::czechDate((string) $healthStart['date'])
                . self::suffix('zpracované', $healthStart['submitted']) . '.';
        } elseif ($beforeTransfer && is_string($record['insurer_code'])) {
            $notes['health_insurance_registration'] = self::NOTE . 'vztah vznikl před převodem (nástup ' . self::czechDate((string) $record['start'])
                . '), pojištěn u ZP ' . $record['insurer_code'] . ', oznámení proběhlo v předchozím systému.';
        }
        $socialStart = $evidence['social_start'] ?? null;
        if (is_array($socialStart)) {
            $notes['social_jmhz_registration'] = self::NOTE . ($socialStart['source'] === 'RegZAM' ? 'registrace zaměstnance u ČSSZ (JMHZ)' : 'přihláška u ČSSZ')
                . self::suffix('odeslaná', $socialStart['submitted']) . self::suffix('přijatá', $socialStart['accepted']) . '.';
        } elseif ($beforeTransfer && $record['social_participation'] === true) {
            $notes['social_jmhz_registration'] = self::NOTE . 'vztah vznikl před převodem (nástup ' . self::czechDate((string) $record['start'])
                . '), přihláška proběhla v předchozím systému.';
        }
        if (is_string($record['end']) && $record['ended_by_code'] === true) {
            $notes['termination_document'] = self::NOTE . 'skončení vztahu k ' . self::czechDate($record['end']) . ' vedené v předchozím mzdovém systému.';
        }
        $healthEnd = $evidence['health_end'] ?? null;
        if (is_array($healthEnd) && $healthEnd['state'] === '2') {
            $notes['health_insurance_deregistration'] = self::NOTE . 'oznámení zdravotní pojišťovně o skončení k ' . self::czechDate((string) $healthEnd['date'])
                . self::suffix('zpracované', $healthEnd['submitted']) . '.';
        }
        $socialEnd = $evidence['social_end'] ?? null;
        if (is_array($socialEnd)) {
            $notes['social_jmhz_deregistration'] = self::NOTE . ($socialEnd['source'] === 'RegZAM' ? 'odhláška zaměstnance u ČSSZ (JMHZ)' : 'odhláška u ČSSZ')
                . self::suffix('odeslaná', $socialEnd['submitted']) . self::suffix('přijatá', $socialEnd['accepted']) . '.';
        }
        $eldp = $evidence['eldp'] ?? null;
        if (is_array($eldp)) {
            $notes['eldp_submission'] = self::NOTE . 'evidenční list důchodového pojištění za rok ' . $eldp['state'] . self::suffix('odeslaný', $eldp['submitted']) . '.';
        }
        return $notes;
    }

    /**
     * Změnové položky checklistu, které vznikly jen tím, že import mezd z PAMICA zapsal
     * historickou verzi podmínek (měsíční mzda platná od převáděného měsíce). Změnu
     * zpracovala PAMICA; jiná změna podmínek vztahu (ruční, jiný import) položku nechá
     * otevřenou.
     */
    private function changeArtefactNote(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT effective_on, note FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'terms_changed' ORDER BY effective_on, id"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($events === []) {
            return null;
        }
        $pamica = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_attendance_imports
              WHERE supplier_id = ? AND period_start = ? AND files_json LIKE ? LIMIT 1"
        );
        $dates = [];
        foreach ($events as $event) {
            $on = (string) $event['effective_on'];
            $note = (string) $event['note'];
            if (substr($on, 8, 2) !== '01') {
                return null;
            }
            // Verzi zapsal buď import mezd ze sešitu měsíce, nebo tenhle převod ze sjednané
            // mzdy v PAMICA; u sešitu se navíc ověří, že měsíc opravdu z PAMICA přišel.
            if (str_starts_with($note, self::WAGE_CHANGE_NOTE)) {
                $pamica->execute([$supplierId, $on, '%' . PohodaPayrollConverter::SHEET . '-' . substr($on, 0, 7) . '%']);
                if ($pamica->fetchColumn() === false) {
                    return null;
                }
            } elseif ($note !== self::PAMICA_WAGE_NOTE) {
                return null;
            }
            $dates[] = self::czechDate($on);
        }
        return self::NOTE . 'změnu měsíční mzdy od ' . implode(', ', array_unique($dates)) . ' zpracovala PAMICA, převod ji zapsal jako historickou verzi podmínek vztahu.';
    }

    /** @return array<string,int> položka checklistu => počet nevyřízených */
    private function openChecklist(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT item_key, COUNT(*) FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND status = 'pending' GROUP BY item_key ORDER BY item_key"
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /**
     * Oprava platné verze podmínek - stejná cesta jako karta vztahu (validátor, zámek verze).
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $changes
     */
    private function correctTerms(int $supplierId, int $employmentId, array $current, array $changes, ?int $userId): void
    {
        $body = RegistrationImportWriter::termsBody($current, 'Údaje převzaté z PAMICA.');
        foreach ($changes as $field => $value) {
            $body[$field] = $value;
        }
        $body['effective_from'] = (string) $current['effective_from'];
        $employment = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        $this->employments->correctTerms(
            $supplierId,
            $employmentId,
            $this->employmentValidator->terms(
                $body,
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            ),
            (int) $employment['row_version'],
            $userId,
            null,
            null,
        );
    }

    /**
     * Pracoviště a CZ-ISCO hned po importu měsíce, dokud je verze podmínek toho měsíce
     * ta poslední.
     *
     * Oprava podmínek sahá vždy na POSLEDNÍ verzi a je to tak správně: přepsat starší
     * verzi by změnilo jiné období, než o které jde. Totéž pravidlo má hromadné doplnění
     * pracoviště, které takový vztah vyloučí jako `later_terms`. Kdyby se pracoviště
     * zapisovalo až po všech měsících, dostala by ho jen poslední verze a měsíce před ní
     * by zůstaly bez ověřeného pracoviště, takže by u nich nešlo zmrazit hlášení. Zapsané
     * po každém měsíci si ho každá další verze opíše z předchozí
     * ({@see \MyInvoice\Service\Payroll\PayrollEmploymentTermsBody}).
     *
     * @param list<array<string,mixed>> $records
     */
    public function writeWorkplaces(int $supplierId, ?int $userId, array $records, ImportProtocol $protocol, string $step): void
    {
        foreach ($records as $record) {
            $number = (string) $record['personal_number'];
            $employment = $this->employmentByCode($supplierId, $number);
            if ($employment === null) {
                continue;
            }
            $employmentId = (int) $employment['id'];
            $this->part($protocol, $step, $number, 'Pracoviště JMHZ', fn (): array => $this->workplace($supplierId, $employmentId, $record, $userId));
            $this->part($protocol, $step, $number, 'Kód CZ-ISCO', fn (): array => $this->czIsco($supplierId, $employmentId, $record, $userId));
        }
    }

    /** @return array<string,mixed>|null */
    private function employmentByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, relation_type FROM payroll_employments WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Druh činnosti z poslední verze podmínek vztahu; pro evidenční list. */
    private function employmentActivityCode(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT activity_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?'
            . ' ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return self::text($stmt->fetchColumn() ?: null);
    }

    /** @return array<string,mixed>|null */
    private function employmentById(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, status, start_date, actual_start_date, end_date, row_version
               FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function moduleStart(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** @param callable():array<string,int> $work */
    private function part(ImportProtocol $protocol, string $step, string $number, string $label, callable $work): void
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $counts = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        } catch (\Exception $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            $protocol->count($step, 'people_failed');
            $this->warn($protocol, $step, 'person_data_failed', "Osobní číslo {$number}: {$label}: {$e->getMessage()}", $number);
            return;
        }
        foreach ($counts as $key => $value) {
            if ($value > 0) {
                $protocol->count($step, $key, $value);
            }
        }
    }

    private function warn(ImportProtocol $protocol, string $step, string $code, string $message, string $number): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $protocol->warn($step, $code, $message, ['personal_number' => $number]);
        } elseif ($this->messages === self::MESSAGE_LIMIT + 1) {
            $protocol->warn($step, 'messages_truncated', 'Další upozornění k údajům osob protokol nevypisuje, jejich počet je v počtech kroku.');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private static function covering(array $rows, string $onDate): ?array
    {
        foreach ($rows as $row) {
            $to = $row['effective_to'] ?? null;
            if ((string) $row['effective_from'] <= $onDate && ($to === null || (string) $to >= $onDate)) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string,int> $counts */
    private static function describe(array $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = (self::CHECKLIST_LABELS[$key] ?? $key) . ' ' . $count;
        }
        return implode(', ', $parts);
    }

    /**
     * Osobní čísla do protokolu, nejvýš třicet; bez nich by nález nešlo dohledat.
     *
     * @param array<string,int> $byNumber osobní číslo => počet
     */
    private static function personalNumbers(array $byNumber): string
    {
        $numbers = array_keys($byNumber);
        sort($numbers);

        return implode(', ', array_slice($numbers, 0, 30)) . (count($numbers) > 30 ? ', …' : '');
    }

    /** První den měsíce první mzdy, nebo nástupu, je-li dřív. */
    private static function monthStart(string $period, mixed $start): string
    {
        $first = $period . '-01';
        if (is_string($start) && substr($start, 0, 7) . '-01' < $first) {
            $first = substr($start, 0, 7) . '-01';
        }
        return $first;
    }

    private static function suffix(string $label, mixed $date): string
    {
        return is_string($date) && $date !== '' ? ", {$label} " . self::czechDate($date) : '';
    }

    private static function czechDate(string $iso): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($iso, 0, 10)) + [0, 0, 0]);
        return "{$d}. {$m}. {$y}";
    }

    private static function czechPeriod(string $period): string
    {
        return (int) substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }
}
