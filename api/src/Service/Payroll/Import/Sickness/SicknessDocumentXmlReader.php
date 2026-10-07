<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Sickness;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;

/**
 * Čtení NEMPRI (25 i starého formátu 20 od Money S3) a HZUPN20, které podal
 * předchozí mzdový program, z XML souboru.
 *
 * Soubor přichází od uživatele, proto se čte obranně jako registrace: DOCTYPE
 * a entity se odmítají, síť je vypnutá a NEMPRI25 s HZUPN20 se ověří proti
 * připnutému schématu dřív, než se z nich cokoli převezme. Soubor, který
 * schématu neodpovídá, se nepřebírá ani zčásti.
 *
 * NEMPRI20 žádné připnuté XSD nemá. Čte se jen to, co se dá spolehlivě
 * pojmenovat (osoba, zaměstnavatel, druh dávky, číslo rozhodnutí, potvrzení
 * zaměstnavatele), a soubor to ve varování říká.
 *
 * Čtečka nic nedomýšlí: čeho dokument nenese, zůstává prázdné. U nemocenského
 * proto věta nemá den vzniku neschopnosti (zná ho ČSSZ z eNeschopenky) a
 * přiřazuje se k případu z evidence.
 */
final class SicknessDocumentXmlReader
{
    private const NS_NEMPRI25 = 'http://schemas.cssz.cz/nem/NEMPRI25';
    private const NS_NEMPRI20 = 'http://schemas.cssz.cz/nem/NEMPRI20';
    private const NS_HZUPN20 = 'http://schemas.cssz.cz/nem/HZUPN20';

    public function __construct(
        private readonly CsszSchemaCatalog $schemas = new CsszSchemaCatalog(),
    ) {}

    /** Je obsah NEMPRI nebo HZUPN? Pro rozdělení souborů importu. */
    public static function looksLike(string $xml): bool
    {
        $head = substr($xml, 0, 4000);

        return preg_match(
            '~xmlns(?::[A-Za-z0-9_]+)?\s*=\s*["\']http://schemas\.cssz\.cz/nem/(?:NEMPRI(?:20|25)|HZUPN20)["\']~',
            $head,
        ) === 1;
    }

    /**
     * @return array{document_type:string,records:list<SicknessImportRecord>,warnings:list<string>}
     * @throws RegistrationImportFileException
     */
    public function read(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) === 1) {
            throw new RegistrationImportFileException(
                'Soubor obsahuje definici DOCTYPE nebo ENTITY, kterou import z bezpečnostních '
                . 'důvodů nepřijímá. Nahrajte soubor přesně tak, jak ho vytvořil mzdový systém.',
            );
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$document->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new RegistrationImportFileException(
                    'Soubor není platné XML' . $this->libxmlDetail() . '. Zkontrolujte, že nahráváte podání NEMPRI nebo HZUPN ve formátu XML.',
                );
            }
            if ($document->doctype !== null) {
                throw new RegistrationImportFileException(
                    'Soubor obsahuje definici DOCTYPE, kterou import z bezpečnostních důvodů nepřijímá.',
                );
            }
            $documentType = $this->documentType($document);
            $warnings = [];
            if ($documentType === SicknessImportRecord::NEMPRI20) {
                $warnings[] = 'NEMPRI ve starém formátu 2020 se čte bez ověření proti schématu ČSSZ (připnuté '
                    . 'XSD pro něj nemáme). Přebírá se jen osoba, druh dávky, číslo rozhodnutí a potvrzení zaměstnavatele.';
            } else {
                $this->validate($document, $documentType);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [
            'document_type' => $documentType,
            'records' => $this->records($document, $documentType),
            'warnings' => $warnings,
        ];
    }

    private function documentType(DOMDocument $document): string
    {
        $root = $document->documentElement;
        if ($root === null) {
            throw new RegistrationImportFileException('Soubor je prázdný.');
        }
        $type = match (true) {
            $root->localName === 'NEMPRI' && $root->namespaceURI === self::NS_NEMPRI25 => SicknessImportRecord::NEMPRI25,
            $root->localName === 'NEMPRI' && $root->namespaceURI === self::NS_NEMPRI20 => SicknessImportRecord::NEMPRI20,
            $root->localName === 'PodaniHZUPN' && $root->namespaceURI === self::NS_HZUPN20 => SicknessImportRecord::HZUPN20,
            default => null,
        };
        if ($type === null) {
            throw new RegistrationImportFileException(
                'Soubor není podání NEMPRI (2020 ani 2025) ani HZUPN 2020, které import dávek umí přečíst.',
            );
        }

        return $type;
    }

    private function validate(DOMDocument $document, string $documentType): void
    {
        $catalogType = $documentType === SicknessImportRecord::HZUPN20
            ? CsszSchemaCatalog::HZUPN20
            : CsszSchemaCatalog::NEMPRI25;
        try {
            $schema = $this->schemas->schemaFor($catalogType);
        } catch (\RuntimeException $e) {
            throw new RegistrationImportFileException($e->getMessage(), 0, $e);
        }
        libxml_clear_errors();
        if (!$document->schemaValidate($schema['path'])) {
            throw new RegistrationImportFileException(
                "Soubor neodpovídá schématu ČSSZ {$documentType}" . $this->libxmlDetail()
                . '. Import převezme jen soubor, který by ČSSZ přijala.',
            );
        }
    }

    /** @return list<SicknessImportRecord> */
    private function records(DOMDocument $document, string $documentType): array
    {
        $root = $document->documentElement;
        if ($root === null) {
            return [];
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('n', (string) $root->namespaceURI);
        $elements = $documentType === SicknessImportRecord::HZUPN20 ? 'n:FormularHZUPN' : 'n:datovaVeta';
        $records = [];
        $position = 0;
        foreach ($xpath->query('/n:' . $root->localName . '/' . $elements) ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $position++;
            $sequence = (int) $node->getAttribute('poradoveCislo');
            $records[] = $documentType === SicknessImportRecord::HZUPN20
                ? $this->hzupn($xpath, $node, $position, $sequence > 0 ? $sequence : $position)
                : $this->nempri($xpath, $node, $documentType, $position, $sequence > 0 ? $sequence : $position);
        }

        return $records;
    }

    private function nempri(
        DOMXPath $xpath,
        DOMElement $node,
        string $documentType,
        int $position,
        int $sequence,
    ): SicknessImportRecord {
        $old = $documentType === SicknessImportRecord::NEMPRI20;
        $kindText = strtoupper((string) $this->text($xpath, $node, 'n:dokument/n:druhDavky'));
        $kind = SicknessBenefitKind::tryFrom($kindText);
        if ($kind === null) {
            throw new RegistrationImportFileException(
                "Věta {$position} má neznámý druh dávky „{$kindText}“. Soubor se nepřebírá ani zčásti.",
            );
        }

        $fields = [];
        $notes = [];
        $workDays = [];
        $incapacityFrom = null;
        $incapacityTo = null;

        $decision = $this->text($xpath, $node, $old ? 'n:dokument/n:cisloPotvrzeni' : 'n:dokument/n:cisloRozhodnuti');
        $decision = $decision === null ? null : strtoupper($decision);
        $ossz = $this->text($xpath, $node, 'n:dokument/n:kodOSSZ');
        $correction = $this->flag($this->text($xpath, $node, 'n:dokument/n:opravnePodani')) === true;
        $foreign = !$old && $this->flag($this->text($xpath, $node, 'n:dokument/n:zahranicni')) === true;
        $fields['foreign_case'] = $foreign ? 1 : 0;
        $fields['correction'] = $correction ? 1 : 0;
        if ($decision !== null) {
            $fields['decision_number'] = $decision;
        }
        if ($ossz !== null) {
            $fields['ossz_code'] = (int) $ossz;
        }

        $decisiveTo = $this->date($this->text($xpath, $node, 'n:rozhodneObdobi/n:rozhodneObdobiDo'));
        $probable = $this->text($xpath, $node, 'n:rozhodneObdobi/n:pravdepodobnaVysePrijmu');
        if ($probable !== null && ctype_digit($probable)) {
            $fields['probable_income_czk'] = (int) $probable;
        }
        $note = $this->text($xpath, $node, 'n:dalsiSdeleni');
        if ($note !== null) {
            $fields['additional_note'] = mb_substr($note, 0, 200);
        }
        $this->contact($xpath, $node, $fields, $old);

        if ($old) {
            $this->confirmationOld($xpath, $node, $fields);
        } else {
            $element = 'n:davka/n:' . $kind->elementName();
            $this->confirmation($xpath, $node, $element . '/n:potvrzeniZamestnavatele', $kind, $fields);
            $this->application($xpath, $node, $element, $kind, $fields, $workDays, $incapacityFrom, $incapacityTo, $notes);
        }

        return new SicknessImportRecord(
            documentType: $documentType,
            position: $position,
            sequence: $sequence,
            document: SicknessDocumentKind::Nempri,
            kind: $kind,
            firstName: $this->text($xpath, $node, 'n:pojistenec/n:jmeno'),
            lastName: $this->text($xpath, $node, 'n:pojistenec/n:prijmeni'),
            birthNumber: $this->digits($this->text($xpath, $node, 'n:pojistenec/n:rodneCislo')),
            birthDate: null,
            employerVariableSymbol: $this->text($xpath, $node, 'n:zamestnani/n:VSZamestnavatel'),
            employerBusinessId: $this->text($xpath, $node, 'n:zamestnani/n:ICZamestnavatel'),
            employmentFrom: $this->date($this->text($xpath, $node, 'n:zamestnani/n:zamestnanOd')),
            employmentTo: $this->date($this->text($xpath, $node, 'n:zamestnani/n:zamestnanDo')),
            decisionNumber: $decision,
            osszCode: $ossz === null ? null : (int) $ossz,
            incapacityFrom: $incapacityFrom,
            incapacityTo: $incapacityTo,
            decisiveTo: $decisiveTo,
            issuedOn: null,
            returnedOn: $this->date($fields['returned_on'] ?? null),
            caseFields: $fields,
            workDays: $workDays,
            notes: $notes,
        );
    }

    /**
     * Potvrzení zaměstnavatele NEMPRI25.
     *
     * @param array<string,mixed> $fields
     */
    private function confirmation(
        DOMXPath $xpath,
        DOMNode $node,
        string $path,
        SicknessBenefitKind $kind,
        array &$fields,
    ): void {
        $text = fn (string $name): ?string => $this->text($xpath, $node, $path . '/n:' . $name);
        $flag = fn (string $name): ?bool => $this->flag($text($name));

        if (($worked = $flag('pracoval')) !== null) {
            $fields['worked_on_decisive_day'] = $worked ? 1 : 0;
        }
        $this->put($fields, 'hours_worked', $this->decimal($text('pocetOdpracovanychHodin')));
        $this->put($fields, 'daily_working_hours', $this->decimal($text('pracovniDoba')));
        $income = $text('prijemMalyRozsah');
        if ($income !== null && ctype_digit($income)) {
            $fields['small_scope_income_minor'] = (int) $income * 100;
        }
        if (($pension = $flag('pobiraDuchod')) !== null) {
            $fields['receives_pension'] = $pension ? 1 : 0;
            $this->put($fields, 'pension_kind', $text('druhDuchodu'));
        }
        if (($student = $flag('jeStudentem')) !== null) {
            $fields['is_student'] = $student ? 1 : 0;
        }
        if (($holidays = $flag('spadaDoPrazdnin')) !== null) {
            $fields['within_school_holidays'] = $holidays ? 1 : 0;
        }
        if (($free = $flag('dobaVolnaPrvniZamestnani')) !== null) {
            $fields['first_employment_free_time'] = $free ? 1 : 0;
        }
        $leaveFrom = $this->date($text('volnoBezNahradyOd'));
        if ($flag('volnoBezNahrady') === true && $leaveFrom !== null) {
            $leaveTo = $this->date($text('volnoBezNahradyDo'));
            $fields['unpaid_leave'] = 1;
            $fields['unpaid_leave_from'] = $leaveFrom;
            $fields['unpaid_leave_to'] = $leaveTo !== null && $leaveTo >= $leaveFrom ? $leaveTo : null;
        }
        if (($maternity = $flag('nastupujePPM')) !== null) {
            $fields['starts_maternity'] = $maternity ? 1 : 0;
        }
        $this->put($fields, 'child_birth_date', $this->date($text('narozeniDitete')));
        $transferred = $this->date($text('datumNaJinouPraci'));
        if ($flag('prevedenaNaJinouPraci') === true && $transferred !== null) {
            $fields['transferred_other_work'] = 1;
            $fields['transferred_on'] = $transferred;
        }
        if (($enforcement = $flag('exekuce')) !== null) {
            $fields['enforcement'] = $enforcement ? 1 : 0;
        }
        if (($insolvency = $flag('insolvence')) !== null) {
            $fields['insolvency'] = $insolvency ? 1 : 0;
        }
    }

    /**
     * Potvrzení zaměstnavatele starého NEMPRI20 (`prilohaStrana2`, hodnoty A/N).
     *
     * @param array<string,mixed> $fields
     */
    private function confirmationOld(DOMXPath $xpath, DOMNode $node, array &$fields): void
    {
        $flag = fn (string $name): ?bool => $this->flag($this->text($xpath, $node, 'n:prilohaStrana2/n:' . $name));
        foreach ([
            'pracoval' => 'worked_on_decisive_day',
            'pobiraDuchod' => 'receives_pension',
            'jeStudentem' => 'is_student',
            'spadaDoPrazdnin' => 'within_school_holidays',
            'dobaVolnaPrvniZamestnani' => 'first_employment_free_time',
            'nastupujePPM' => 'starts_maternity',
            'exekuce' => 'enforcement',
            'insolvence' => 'insolvency',
        ] as $element => $column) {
            if (($value = $flag($element)) !== null) {
                $fields[$column] = $value ? 1 : 0;
            }
        }
    }

    /**
     * Kontaktní pracovník zaměstnavatele.
     *
     * @param array<string,mixed> $fields
     */
    private function contact(DOMXPath $xpath, DOMNode $node, array &$fields, bool $old): void
    {
        if ($old) {
            $this->put($fields, 'contact_worker_phone', $this->text($xpath, $node, 'n:prilohaStrana2/n:kontaktniTelefon'));
            $this->put($fields, 'contact_worker_email', $this->text($xpath, $node, 'n:prilohaStrana2/n:kontaktniEmail'));

            return;
        }
        $this->put($fields, 'contact_worker_name', $this->text($xpath, $node, 'n:kontaktPracovnik/n:kontaktniPracovnik'));
        $this->put($fields, 'contact_worker_phone', $this->text($xpath, $node, 'n:kontaktPracovnik/n:telefon'));
        $this->put($fields, 'contact_worker_email', $this->text($xpath, $node, 'n:kontaktPracovnik/n:email'));
    }

    /**
     * Žádost o dávku (`zadostODavku`) a podklady pro výplatu: den vzniku,
     * konec, péče o druhou osobu a podklady ošetřovného, dlouhodobého
     * ošetřovného, otcovské a peněžité pomoci v mateřství.
     *
     * @param array<string,mixed> $fields
     * @param list<array{from:string,to:string}> $workDays
     * @param list<string> $notes
     */
    private function application(
        DOMXPath $xpath,
        DOMNode $node,
        string $element,
        SicknessBenefitKind $kind,
        array &$fields,
        array &$workDays,
        ?string &$incapacityFrom,
        ?string &$incapacityTo,
        array &$notes,
    ): void {
        if ($kind->hasActions()) {
            $prefix = $kind === SicknessBenefitKind::Ose ? 'ose' : 'dlo';
            $fields['action_start'] = $this->flag($this->text($xpath, $node, $element . '/n:' . $prefix . 'Vznik')) === true ? 1 : 0;
            $fields['action_continuation'] = $this->flag($this->text($xpath, $node, $element . '/n:' . $prefix . 'Trvani')) === true ? 1 : 0;
            $fields['action_end'] = $this->flag($this->text($xpath, $node, $element . '/n:' . $prefix . 'Ukonceni')) === true ? 1 : 0;
        }
        if (!$kind->hasApplication()) {
            return;
        }
        $application = $element . '/n:zadostODavku';
        $text = fn (string $name): ?string => $this->text($xpath, $node, $application . '/n:' . $name);
        $flag = fn (string $name): ?bool => $this->flag($text($name));

        $incapacityFrom = $this->date($text('odeDne'));
        $incapacityTo = $this->date($text('doDne'));

        // Osoba, o kterou se pečuje (nebo dítě u otcovské).
        $person = $kind === SicknessBenefitKind::Opp ? 'dite' : 'osetrovanaOsoba';
        $this->put($fields, 'cared_first_name', $this->text($xpath, $node, $application . '/n:' . $person . '/n:jmeno'));
        $this->put($fields, 'cared_last_name', $this->text($xpath, $node, $application . '/n:' . $person . '/n:prijmeni'));
        $this->put($fields, 'cared_birth_date', $this->date($this->text($xpath, $node, $application . '/n:' . $person . '/n:datumNarozeni')));

        $reason = match (true) {
            $flag('onemocnela') === true => 'ill',
            $flag('narizenaKarantena') === true => 'quarantine',
            $flag('nemuzePecovatODite') === true => 'cannot_care',
            $this->text($xpath, $node, $application . '/n:uzavrenaSkola/n:nazevZarizeniSkoly') !== null => 'school_closed',
            default => null,
        };
        if ($kind === SicknessBenefitKind::Ose && $reason !== null) {
            $fields['care_reason'] = $reason;
            if ($reason === 'school_closed') {
                $this->put($fields, 'school_name', $this->text($xpath, $node, $application . '/n:uzavrenaSkola/n:nazevZarizeniSkoly'));
                $this->put($fields, 'school_business_id', $this->text($xpath, $node, $application . '/n:uzavrenaSkola/n:ICZarizeniSkoly'));
            }
        }
        foreach ([
            'spolecnaDomacnost' => 'shared_household',
            'jeOsamely' => 'lone_caregiver',
            'vPeciDiteDo16Let' => 'child_under_16',
            'narokNaPPMjinouOsobou' => 'other_maternity_claim',
            'narokNaRPjinaOsobaNecerpaVolnoNeboOSVC' => 'other_parental_claim',
            'jinaFOParagraf57' => 'other_person_s57',
            'pecovalOsobne' => 'cared_personally',
            'jeStridani' => 'alternation',
        ] as $elementName => $column) {
            if (($value = $flag($elementName)) !== null) {
                $fields[$column] = $value ? 1 : 0;
            }
        }
        $careDays = $this->periods($xpath, $node, $application . '/n:pecovalVeDnech/n:obdobi', 'n:od', 'n:do');
        if ($careDays !== []) {
            $fields['care_days'] = $careDays;
        }
        $this->put($fields, 'relationship_code', $text($kind === SicknessBenefitKind::Dlo ? 'kodVztah' : 'kodRodVztah'));
        $this->put($fields, 'paternity_reason', $text('duvodOtcovske'));
        $this->put($fields, 'maternity_care_reason', $text('duvodPece'));

        // Podklady pro výplatu dávky.
        $basis = $element . '/n:podkladyProVyplatDavky';
        $basisText = fn (string $name): ?string => $this->text($xpath, $node, $basis . '/n:' . $name);
        $basisFlag = fn (string $name): ?bool => $this->flag($basisText($name));
        if (($worked = $basisFlag('pracovalPoslDenPD')) !== null) {
            $fields['worked_last_day'] = $worked ? 1 : 0;
        }
        $this->put($fields, 'shift_hours_last_day', $this->decimal($basisText('pracovniDobaPoslDenPD')));
        $this->put($fields, 'hours_worked_last_day', $this->decimal($basisText('pocetOdpracHodinPoslDenPD')));
        $plannedShifts = $basisFlag('planovaneSmeny');
        if ($plannedShifts !== null) {
            $fields['planned_shifts'] = $plannedShifts ? 1 : 0;
        }
        if (($shiftsWorked = $basisFlag('planovaneSmenyOdpracoval')) !== null) {
            $fields['planned_shifts_worked'] = $shiftsWorked ? 1 : 0;
        }
        $this->put($fields, 'returned_on', $this->date($basisText('datumNavratDoPrace')));
        $workDays = $this->periods($xpath, $node, $basis . '/n:seznamPraceVeDnech/n:obdobi', 'n:od', 'n:do');
        if ($kind === SicknessBenefitKind::Dlo) {
            if (($leave = $basisFlag('maVolno')) !== null) {
                $fields['dlo_has_leave'] = $leave ? 1 : 0;
                $leavePeriods = $this->periods($xpath, $node, $basis . '/n:pracovniVolno/n:obdobi', 'n:od', 'n:do');
                if ($leave && $leavePeriods !== []) {
                    $fields['dlo_leave_periods'] = $leavePeriods;
                }
            }
            $schedule = $this->periods($xpath, $node, $basis . '/n:seznamRozvrhuSmen/n:obdobi', 'n:od', 'n:do');
            if ($plannedShifts === true && $schedule !== []) {
                $fields['dlo_shift_schedule'] = $schedule;
            }
        }
    }

    private function hzupn(DOMXPath $xpath, DOMElement $node, int $position, int $sequence): SicknessImportRecord
    {
        $fields = [];
        $text = fn (string $path): ?string => $this->text($xpath, $node, $path);
        $employerReport = $this->flag($text('n:dokument/n:hlasZamest')) !== false;
        $personReport = $this->flag($text('n:dokument/n:hlasOsoby')) === true || !$employerReport;
        $ossz = $text('n:dokument/n:kodOSSZ');
        $decision = $text('n:dokument/n:cisloPotvrzeni');
        $decision = $decision === null ? null : strtoupper($decision);
        $issued = $this->date($text('n:dokument/n:datumVystaveni'));
        $fields['foreign_case'] = $this->flag($text('n:dokument/n:zahranicni')) === true ? 1 : 0;
        $fields['correction'] = $this->flag($text('n:dokument/n:opravnePodani')) === true ? 1 : 0;
        if ($decision !== null) {
            $fields['decision_number'] = $decision;
        }
        if ($ossz !== null) {
            $fields['ossz_code'] = (int) $ossz;
        }
        if ($issued !== null) {
            $fields['issued_on'] = $issued;
        }

        $returned = $this->flag($text('n:potvrzeniZamestnavatele/n:navratDoPrace'));
        $returnedOn = $this->date($text('n:potvrzeniZamestnavatele/n:datumNavratDoPrace'));
        if ($returned !== null) {
            $fields['returned_to_work'] = $returned ? 1 : 0;
        }
        $this->put($fields, 'return_reason', $text('n:potvrzeniZamestnavatele/n:duvodNavratDoPrace'));
        $this->put($fields, 'returned_on', $returnedOn);
        $this->put($fields, 'hours_worked_last_day', $this->decimal($text('n:potvrzeniZamestnavatele/n:pocetOdpracHodinPoslDenPD')));
        $this->put($fields, 'shift_hours_last_day', $this->decimal($text('n:potvrzeniZamestnavatele/n:pracovniDobaPoslDenPD')));
        $workDays = $this->periods($xpath, $node, 'n:praceVeDnech/n:interval', 'n:pracovalOd', 'n:pracovalDo');

        // Posledním dnem neschopnosti je den před návratem do práce. Kdo se do
        // práce nevrátil, nese datum jen jako den, ke kterému hlášení vzniklo.
        $incapacityTo = $returned === true && $returnedOn !== null
            ? (new \DateTimeImmutable($returnedOn))->modify('-1 day')->format('Y-m-d')
            : null;

        return new SicknessImportRecord(
            documentType: SicknessImportRecord::HZUPN20,
            position: $position,
            sequence: $sequence,
            document: SicknessDocumentKind::Hzupn,
            kind: SicknessBenefitKind::Nem,
            firstName: $text('n:pojistenec/n:jmeno'),
            lastName: $text('n:pojistenec/n:prijmeni'),
            birthNumber: $this->digits($text('n:pojistenec/n:rodCislo')),
            birthDate: $this->date($text('n:pojistenec/n:datumNar')),
            employerVariableSymbol: $text('n:zamestnani/n:variabilniSymbol'),
            employerBusinessId: $text('n:zamestnani/n:ICZamestnavatel'),
            employmentFrom: null,
            employmentTo: null,
            decisionNumber: $decision,
            osszCode: $ossz === null ? null : (int) $ossz,
            incapacityFrom: null,
            incapacityTo: $incapacityTo,
            decisiveTo: null,
            issuedOn: $issued,
            returnedOn: $returnedOn,
            caseFields: $fields,
            workDays: $workDays,
            personReport: $personReport,
            incapacityToDerived: $incapacityTo !== null,
        );
    }

    /** @return list<array{from:string,to:string}> */
    private function periods(DOMXPath $xpath, DOMNode $node, string $path, string $from, string $to): array
    {
        $periods = [];
        foreach ($xpath->query($path, $node) ?: [] as $item) {
            $start = $this->date($this->text($xpath, $item, $from));
            $end = $this->date($this->text($xpath, $item, $to));
            if ($start !== null && $end !== null && $end >= $start) {
                $periods[] = ['from' => $start, 'to' => $end];
            }
        }
        usort($periods, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $periods;
    }

    /** @param array<string,mixed> $fields */
    private function put(array &$fields, string $column, mixed $value): void
    {
        if ($value !== null) {
            $fields[$column] = $value;
        }
    }

    private function text(DOMXPath $xpath, DOMNode $context, string $query): ?string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    /** `true`/`1`/`A` a `false`/`0`/`N`; cokoli jiného je neuvedeno. */
    private function flag(?string $value): ?bool
    {
        return match (strtolower((string) $value)) {
            'true', '1', 'a' => true,
            'false', '0', 'n' => false,
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function digits(?string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    /** Desetinné číslo s nejvýš dvěma místy; jinak `null`. */
    private function decimal(?string $value): ?string
    {
        if ($value === null || !is_numeric($value) || (float) $value < 0) {
            return null;
        }
        $formatted = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        if ($formatted === '') {
            $formatted = '0';
        }

        return preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $formatted) === 1 ? $formatted : null;
    }

    private function libxmlDetail(): string
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if ($errors === []) {
            return '';
        }

        return ' (' . trim($errors[0]->message) . ')';
    }
}
