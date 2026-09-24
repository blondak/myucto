<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;

/**
 * Čtení registrací ČSSZ REGZEC25 a PREZEC26 z XML souboru.
 *
 * Mapa elementů a atributů je zrcadlem {@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer}
 * — co aplikace umí odeslat, to umí i přečíst zpátky.
 *
 * Soubor přichází od uživatele, proto se čte obranně: DOCTYPE a entity se
 * odmítají celé (XXE ani „billion laughs" tak nemají kudy projít), síť je
 * vypnutá a obsah se ověří proti připnutému schématu dřív, než se z něj
 * cokoli převezme. Soubor, který schématu neodpovídá, se nepřebírá ani
 * zčásti — napůl přečtená věta by do evidence zapsala napůl pravdu.
 *
 * Umí i „Export zaměstnanců" z ePortálu ČSSZ (kořen `ExportZamestnancu` bez
 * jmenného prostoru). Ten schéma nemá, takže se tvar hodnot kontroluje ručně
 * ({@see validateExport()}) se stejným pravidlem: vadná věta odmítne celý soubor.
 */
final class RegistrationXmlReader
{
    public const NAMESPACE_REGZEC = 'http://schemas.cssz.cz/REGZEC/2025';
    public const NAMESPACE_PREZEC = 'http://schemas.cssz.cz/PREZEC/2026';

    /** Element věty exportu => [vzor hodnoty po normalizaci, název do chybové hlášky]. */
    private const EXPORT_FORMATS = [
        'RodneCislo' => ['/^[0-9]{9,10}$/D', 'rodné číslo'],
        'OIC' => ['/^[0-9]{10}$/D', 'OIČ'],
        'IdZamestnani' => ['/^[0-9]{1,22}$/D', 'ID PPV (IdZamestnani)'],
        'KodDruhuCinnosti' => ['/^[0-9A-Z]{1,2}$/D', 'kód druhu činnosti'],
        'ZMR' => ['/^[AN]$/D', 'příznak zaměstnání malého rozsahu (ZMR)'],
        'VariabilniSymbol' => ['/^[0-9]{1,10}$/D', 'variabilní symbol zaměstnavatele'],
        'Jmeno' => ['/^.{1,100}$/Dsu', 'jméno'],
        'Prijmeni' => ['/^.{1,100}$/Dsu', 'příjmení'],
    ];

    public function __construct(
        private readonly PayrollRegistrationSchemaCatalog $schemas,
    ) {}

    /**
     * @return array{document_type:string,records:list<RegistrationRecord>}
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
                . 'důvodů nepřijímá. Nahrajte soubor přesně tak, jak ho vytvořil mzdový systém '
                . 'nebo jak ho vrátil portál ČSSZ.',
            );
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
            if (!$loaded) {
                throw new RegistrationImportFileException(
                    'Soubor není platné XML' . $this->libxmlDetail()
                    . '. Zkontrolujte, že nahráváte registraci ČSSZ ve formátu XML.',
                );
            }
            if ($document->doctype !== null) {
                throw new RegistrationImportFileException(
                    'Soubor obsahuje definici DOCTYPE, kterou import z bezpečnostních důvodů nepřijímá.',
                );
            }
            $documentType = $this->documentType($document);
            $this->validate($document, $documentType);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [
            'document_type' => $documentType,
            'records' => $this->records($document, $documentType),
        ];
    }

    private function documentType(DOMDocument $document): string
    {
        $root = $document->documentElement;
        if ($root === null) {
            throw new RegistrationImportFileException('Soubor je prázdný.');
        }
        $type = match (true) {
            $root->localName === 'REGZEC' && $root->namespaceURI === self::NAMESPACE_REGZEC => 'REGZEC25',
            $root->localName === 'PREZEC' && $root->namespaceURI === self::NAMESPACE_PREZEC => 'PREZEC26',
            $root->localName === 'ExportZamestnancu' && $root->namespaceURI === null => RegistrationRecord::CSSZ_EXPORT,
            default => null,
        };
        if ($type === null) {
            throw new RegistrationImportFileException(
                'Soubor není registrace zaměstnance ČSSZ (REGZEC25 ani PREZEC26), export zaměstnanců '
                . 'z ePortálu ČSSZ ani měsíční hlášení JMHZ. Import registrací přijímá jen tyto soubory; '
                . 'ostatní vynechte.',
            );
        }

        return $type;
    }

    private function validate(DOMDocument $document, string $documentType): void
    {
        if ($documentType === RegistrationRecord::CSSZ_EXPORT) {
            $this->validateExport($document);

            return;
        }
        try {
            $schema = $this->schemas->schemaFor($documentType);
        } catch (PayrollRegistrationXmlException $e) {
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

    /**
     * Export zaměstnanců z ePortálu ČSSZ nemá zveřejněné schéma, takže se
     * kontroluje ručně: tvar každé převzaté hodnoty a aspoň jeden identifikátor
     * osoby. Jediná vadná věta odmítne celý soubor, stejně jako u XSD.
     */
    private function validateExport(DOMDocument $document): void
    {
        $root = $document->documentElement;
        $list = $root === null ? null : $this->plainChild($root, 'Zamestnanci');
        if ($list === null) {
            throw new RegistrationImportFileException(
                'Export zaměstnanců ČSSZ nemá seznam zaměstnanců (element Zamestnanci). '
                . 'Nahrajte soubor přesně tak, jak ho stáhl ePortál ČSSZ.',
            );
        }
        $generated = $this->plainText($root, 'DatumGenerovani');
        if ($generated !== null && $this->date($generated) === null) {
            throw new RegistrationImportFileException("Datum vytvoření exportu „{$generated}“ není platné datum.");
        }
        $position = 0;
        foreach ($list->childNodes as $employee) {
            if (!$employee instanceof DOMElement || $employee->localName !== 'Zamestnanec') {
                continue;
            }
            $position++;
            foreach (self::EXPORT_FORMATS as $element => [$pattern, $label]) {
                $node = $this->plainChild($employee, $element);
                if ($node === null) {
                    continue;
                }
                foreach ($node->childNodes as $inner) {
                    if ($inner instanceof DOMElement) {
                        throw new RegistrationImportFileException(
                            "Věta {$position} exportu zaměstnanců má v elementu {$element} vnořené prvky. "
                            . 'Soubor se nepřebírá ani zčásti.',
                        );
                    }
                }
                $value = $this->exportValue($element, trim($node->textContent));
                if ($value !== null && preg_match($pattern, $value) !== 1) {
                    throw new RegistrationImportFileException(
                        "Věta {$position} exportu zaměstnanců má neplatný {$label}. Soubor se nepřebírá ani zčásti.",
                    );
                }
            }
            if ($this->plainText($employee, 'RodneCislo') === null
                && $this->plainText($employee, 'OIC') === null
                && $this->plainText($employee, 'IdZamestnani') === null
            ) {
                throw new RegistrationImportFileException(
                    "Věta {$position} exportu zaměstnanců nemá rodné číslo, OIČ ani ID PPV, takže ji nejde "
                    . 'přiřadit k žádné osobě. Soubor se nepřebírá ani zčásti.',
                );
            }
        }
    }

    /** @return list<RegistrationRecord> */
    private function exportRecords(DOMDocument $document): array
    {
        $root = $document->documentElement;
        $list = $root === null ? null : $this->plainChild($root, 'Zamestnanci');
        if ($root === null || $list === null) {
            return [];
        }
        $preparedOn = $this->date($this->plainText($root, 'DatumGenerovani'));
        $records = [];
        $position = 0;
        foreach ($list->childNodes as $employee) {
            if (!$employee instanceof DOMElement || $employee->localName !== 'Zamestnanec') {
                continue;
            }
            $position++;
            $value = fn (string $element): ?string => $this->exportValue($element, $this->plainText($employee, $element));
            $records[] = new RegistrationRecord(
                documentType: RegistrationRecord::CSSZ_EXPORT,
                position: $position,
                sequence: $position,
                actionCode: 0,
                preparedOn: $preparedOn,
                birthNumber: $value('RodneCislo'),
                personIdentifier: $value('OIC'),
                firstName: $value('Jmeno'),
                lastName: $value('Prijmeni'),
                employmentIdentifier: $value('IdZamestnani'),
                activityCode: $value('KodDruhuCinnosti'),
                smallScale: $value('ZMR') === 'A',
                employerVariableSymbol: $value('VariabilniSymbol'),
            );
        }

        return $records;
    }

    private function exportValue(string $element, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($element) {
            'RodneCislo' => str_replace('/', '', $value),
            'KodDruhuCinnosti', 'ZMR' => strtoupper($value),
            default => $value,
        };
    }

    private function plainChild(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name && $child->namespaceURI === null) {
                return $child;
            }
        }

        return null;
    }

    private function plainText(DOMElement $parent, string $name): ?string
    {
        $node = $this->plainChild($parent, $name);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /** @return list<RegistrationRecord> */
    private function records(DOMDocument $document, string $documentType): array
    {
        if ($documentType === RegistrationRecord::CSSZ_EXPORT) {
            return $this->exportRecords($document);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace(
            'r',
            $documentType === 'REGZEC25' ? self::NAMESPACE_REGZEC : self::NAMESPACE_PREZEC,
        );
        $records = [];
        $position = 0;
        foreach ($xpath->query('/r:*/r:employees/r:employee') ?: [] as $employee) {
            if (!$employee instanceof DOMElement) {
                continue;
            }
            $position++;
            $records[] = $this->record($xpath, $employee, $documentType, $position);
        }

        return $records;
    }

    private function record(
        DOMXPath $xpath,
        DOMElement $employee,
        string $documentType,
        int $position,
    ): RegistrationRecord {
        $client = $this->child($xpath, $employee, 'r:client');
        $name = $this->child($xpath, $employee, 'r:client/r:name');
        $birth = $this->child($xpath, $employee, 'r:client/r:birth');
        $stat = $this->child($xpath, $employee, 'r:client/r:stat');
        $job = $this->child($xpath, $employee, 'r:job');

        return new RegistrationRecord(
            documentType: $documentType,
            position: $position,
            sequence: (int) $this->attribute($employee, 'sqnr'),
            actionCode: (int) $this->attribute($employee, 'act'),
            preparedOn: $this->date($this->attribute($employee, 'dat')),
            effectiveOn: $this->date($this->attribute($employee, 'fro')),
            expectedStartOn: $this->date($this->attribute($employee, 'predat')),
            birthNumber: $this->attribute($client, 'bno'),
            personIdentifier: $this->attribute($client, 'ikmpsv'),
            firstName: $this->attribute($name, 'fir'),
            lastName: $this->attribute($name, 'sur'),
            titlePrefix: $this->attribute($name, 'tit'),
            birthDate: $this->date($this->attribute($birth, 'dat')),
            birthSurname: $this->attribute($birth, 'nam'),
            birthPlace: $this->attribute($birth, 'cit'),
            birthCountryCode: $this->upper($this->attribute($birth, 'stat')),
            sex: match ($this->attribute($stat, 'mal')) {
                'M' => 'male',
                'Ž', 'Z', 'F' => 'female',
                default => null,
            },
            citizenshipCountryCode: $this->upper($this->attribute($stat, 'cnt')),
            permanentAddress: $this->address($this->child($xpath, $employee, 'r:client/r:adr')),
            contactAddress: $this->address($this->child($xpath, $employee, 'r:client/r:cdr')),
            employmentIdentifier: $this->attribute($job, 'oid'),
            startOn: $this->date($this->attribute($job, 'fro')),
            endOn: $this->date($this->attribute($job, 'to')),
            activityCode: $this->upper($this->attribute($job, 'rel')),
            relationshipDetailCode: $this->attribute($job, 'relDetail'),
            smallScale: $this->attribute($job, 'sme') === 'A',
            contractPlace: $this->attribute($job, 'contractplace'),
            workplaceCity: $this->attribute($job, 'cit'),
            workplaceMunicipalityCode: $this->attribute($job, 'municode'),
            professionCode: $this->attribute($this->child($xpath, $employee, 'r:job/r:prof'), 'clas'),
            positionName: $this->attribute($this->child($xpath, $employee, 'r:job/r:position'), 'name'),
            healthInsurerCode: $this->attribute($this->child($xpath, $employee, 'r:insh'), 'cnr'),
            highestEducationCode: $this->attribute($this->child($xpath, $employee, 'r:fact'), 'highedu'),
        );
    }

    /** @return array<string,?string>|null */
    private function address(?DOMElement $node): ?array
    {
        if ($node === null) {
            return null;
        }

        return [
            'street' => $this->attribute($node, 'str'),
            'house_number' => $this->attribute($node, 'num'),
            'orientation_number' => $this->attribute($node, 'onum'),
            'postal_code' => $this->attribute($node, 'pnu'),
            'city' => $this->attribute($node, 'cit'),
            'country_code' => $this->upper($this->attribute($node, 'cnt')) ?? 'CZ',
        ];
    }

    private function child(DOMXPath $xpath, DOMElement $context, string $path): ?DOMElement
    {
        $node = $xpath->query($path, $context)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function attribute(?DOMElement $node, string $name): ?string
    {
        if ($node === null || !$node->hasAttribute($name)) {
            return null;
        }
        $value = trim($node->getAttribute($name));

        return $value === '' ? null : $value;
    }

    private function upper(?string $value): ?string
    {
        return $value === null ? null : strtoupper($value);
    }

    /** Schéma pouští `xs:date` i s časovou zónou; evidence chce holé datum. */
    private function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) === 1 ? $match[1] : null;
    }

    private function libxmlDetail(): string
    {
        $messages = [];
        foreach (array_slice(libxml_get_errors(), 0, 3) as $error) {
            $messages[] = 'řádek ' . $error->line . ': ' . trim($error->message);
        }

        return $messages === [] ? '' : ' (' . implode('; ', $messages) . ')';
    }
}
