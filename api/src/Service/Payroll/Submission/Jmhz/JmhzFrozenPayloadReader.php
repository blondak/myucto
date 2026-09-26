<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

/**
 * Zmrazená datová věta podání a její identita, načtené z archivu artefaktů.
 *
 * Existuje proto, že tutéž otázku — „co jsme vlastně odeslali?" — potřebují tři
 * různá místa: odeslání bez ručně předaného XML, dotaz na výsledek na pozadí
 * (potřebuje variabilní symbol) a storno (potřebuje GUID a rozhodné období).
 * Kdyby si každé sahalo do archivu po svém, rozešly by se v tom, co považují za
 * zdroj pravdy — a rozejít se dá jen tak, že jedno z nich sáhne po jiném podání.
 *
 * `artifactBytes()` sám ověřuje délku i SHA-256 proti archivu, takže odsud
 * nevyjde nic jiného než přesně to, co se kdysi zmrazilo.
 */
readonly class JmhzFrozenPayloadReader
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
    ) {
    }

    /**
     * Zmrazená datová věta podání, které se nedělí do balíků.
     *
     * Rozdělené hlášení (nad 1500 formulářů) má tolik datových vět, kolik má
     * balíků, a „ta jedna" neexistuje: vrátit jen poslední by tiše ztratilo
     * ostatní formuláře. Pro něj slouží {@see self::packages()}; tady se
     * odmítne, aby žádná cesta neodeslala ani nenavázala opravu jen na část.
     */
    public function bytes(int $supplierId, string $environment, int $submissionId): string
    {
        if ($this->repository->listPackageOutboundXmlArtifacts($supplierId, $environment, $submissionId) !== []) {
            throw new JmhzXmlException(
                'jmhz_submission_split_payload',
                'Hlášení je rozdělené do dílčích balíků; s každým balíkem se pracuje zvlášť.',
            );
        }

        return $this->singleBytes($supplierId, $environment, $submissionId);
    }

    /**
     * Dílčí balíky rozděleného hlášení bez bajtů (pořadí, součást, artefakt,
     * otisk). Pro nerozdělené podání prázdný seznam.
     *
     * @return list<array{ordinal:int,part_id:int,artifact_id:int,artifact_sha256:string}>
     */
    public function packageArtifacts(int $supplierId, string $environment, int $submissionId): array
    {
        return $this->repository->listPackageOutboundXmlArtifacts($supplierId, $environment, $submissionId);
    }

    public function packageBytes(int $supplierId, int $artifactId): string
    {
        return $this->submissions->artifactBytes($supplierId, $artifactId);
    }

    /**
     * Dílčí balíky rozděleného hlášení s bajty, v pořadí balíků. Pro
     * nerozdělené podání prázdný seznam.
     *
     * @return list<array{ordinal:int,part_id:int,artifact_id:int,sha256:string,xml:string}>
     */
    public function packages(int $supplierId, string $environment, int $submissionId): array
    {
        $packages = [];
        foreach ($this->repository->listPackageOutboundXmlArtifacts($supplierId, $environment, $submissionId) as $package) {
            $packages[] = [
                'ordinal' => $package['ordinal'],
                'part_id' => $package['part_id'],
                'artifact_id' => $package['artifact_id'],
                'sha256' => $package['artifact_sha256'],
                'xml' => $this->submissions->artifactBytes($supplierId, $package['artifact_id']),
            ];
        }

        return $packages;
    }

    /**
     * Všechny datové věty podání: jediná, nebo balíky v pořadí.
     *
     * @return list<string>
     */
    private function documents(int $supplierId, string $environment, int $submissionId): array
    {
        $packages = $this->packages($supplierId, $environment, $submissionId);
        if ($packages !== []) {
            return array_map(static fn (array $package): string => $package['xml'], $packages);
        }

        return [$this->singleBytes($supplierId, $environment, $submissionId)];
    }

    private function singleBytes(int $supplierId, string $environment, int $submissionId): string
    {
        $artifactId = $this->repository->findOutboundXmlArtifactId(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($artifactId === null) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_missing',
                'Podání nemá uloženou zmrazenou datovou větu, takže s ním nelze'
                    . ' dál pracovat. Zmrazte hlášení znovu z přípravy.',
            );
        }

        return $this->submissions->artifactBytes($supplierId, $artifactId);
    }

    /**
     * GUID podání a variabilní symbol. U rozděleného hlášení jsou ve všech
     * balících shodné, bere se první.
     */
    public function identity(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): JmhzFrozenSubmissionIdentity {
        return JmhzFrozenSubmissionIdentity::read(
            $this->documents($supplierId, $environment, $submissionId)[0],
        );
    }

    /**
     * Součásti přesně tak, jak byly zmrazené v řádném podání. UI z nich
     * sestaví výběr pro opravné podání, takže účetní nikdy neopisuje GUID ani
     * zákonné identifikátory ručně. U rozděleného hlášení ze všech balíků.
     *
     * @return list<array{
     *   form_guid:string,
     *   person_external_identifier:string,
     *   employment_external_identifier:string
     * }>
     */
    public function components(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $components = [];
        foreach ($this->documents($supplierId, $environment, $submissionId) as $xml) {
            $xpath = self::xpath($xml);
            $forms = $xpath->query('/p:jmhz/p:formulareOsob/p:formularOsoby');
            if ($forms === false) {
                throw new JmhzXmlException(
                    'jmhz_submission_components_unreadable',
                    'Součásti zmrazeného podání nelze načíst.',
                );
            }
            foreach ($forms as $form) {
                if (!$form instanceof DOMElement) {
                    continue;
                }
                $component = JmhzComponentCancellation::create(
                    self::value($xpath, './p:hlavicka/p:idFormulare', $form),
                    self::value($xpath, './/f:identifikace/f:ikMpsv', $form),
                    self::value($xpath, './/f:identifikace/f:idPpv', $form),
                );
                $components[] = [
                    'form_guid' => $component->formGuid,
                    'person_external_identifier' => $component->personExternalIdentifier,
                    'employment_external_identifier' => $component->employmentExternalIdentifier,
                ];
            }
        }
        if ($components === []) {
            throw new JmhzXmlException(
                'jmhz_submission_components_missing',
                'Řádné podání neobsahuje žádný pracovní vztah, který by šel opravit.',
            );
        }

        return $components;
    }

    /**
     * @return array{
     *   submission_type:string,
     *   forms:list<array{
     *     form_guid:string,form_type:string,
     *     person_external_identifier:?string,
     *     employment_external_identifier:?string
     *   }>
     * }
     */
    public function describe(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $submissionType = null;
        $forms = [];
        foreach ($this->documents($supplierId, $environment, $submissionId) as $xml) {
            $xpath = self::xpath($xml);
            $type = self::documentValue($xpath, '/p:jmhz/p:hlavicka/p:typPodani');
            if (!in_array($type, ['R', 'O', 'S'], true)
                || ($submissionType !== null && $submissionType !== $type)
            ) {
                throw new JmhzXmlException(
                    'jmhz_submission_type_invalid',
                    'Zmrazené podání nemá podporovaný typ R, O nebo S.',
                );
            }
            $submissionType = $type;
            $nodes = $xpath->query('/p:jmhz/p:formulareOsob/p:formularOsoby');
            if ($nodes === false) {
                throw new JmhzXmlException(
                    'jmhz_submission_components_unreadable',
                    'Součásti zmrazeného podání nelze načíst.',
                );
            }
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $guid = strtoupper(self::value($xpath, './p:hlavicka/p:idFormulare', $node));
                $formType = self::value($xpath, './p:hlavicka/p:typFormulare', $node);
                if (!in_array($formType, ['R', 'O', 'S'], true)) {
                    throw new JmhzXmlException(
                        'jmhz_submission_form_type_invalid',
                        'Zmrazený formulář nemá podporovaný typ R, O nebo S.',
                    );
                }
                $forms[] = [
                    'form_guid' => $guid,
                    'form_type' => $formType,
                    'person_external_identifier' => self::optionalValue(
                        $xpath,
                        './/f:identifikace/f:ikMpsv',
                        $node,
                    ),
                    'employment_external_identifier' => self::optionalValue(
                        $xpath,
                        './/f:identifikace/f:idPpv',
                        $node,
                    ),
                ];
            }
        }

        return ['submission_type' => (string) $submissionType, 'forms' => $forms];
    }

    /** @return list<string> */
    public function formGuids(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $guids = [];
        foreach ($this->documents($supplierId, $environment, $submissionId) as $xml) {
            $nodes = self::xpath($xml)->query(
                '/p:jmhz/p:formulareOsob/p:formularOsoby/p:hlavicka/p:idFormulare',
            );
            if ($nodes === false) {
                throw new JmhzXmlException(
                    'jmhz_submission_components_unreadable',
                    'Součásti zmrazeného podání nelze načíst.',
                );
            }
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $guid = strtoupper(trim($node->textContent));
                if ($guid !== '') {
                    $guids[] = $guid;
                }
            }
        }

        return array_values(array_unique($guids));
    }

    private static function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_invalid',
                'Zmrazenou datovou větu podání nelze přečíst.',
            );
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);

        return $xpath;
    }

    private static function value(
        DOMXPath $xpath,
        string $query,
        DOMElement $context,
    ): string {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        $value = $node instanceof DOMElement ? trim($node->textContent) : '';
        if ($value === '') {
            throw new JmhzXmlException(
                'jmhz_submission_component_identity_missing',
                'Ve zmrazeném podání chybí identifikace pracovního vztahu.',
            );
        }

        return $value;
    }

    private static function documentValue(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);
        $value = $node instanceof DOMElement ? trim($node->textContent) : '';
        if ($value === '') {
            throw new JmhzXmlException(
                'jmhz_submission_identity_missing',
                'Ve zmrazeném podání chybí povinná identita.',
            );
        }

        return $value;
    }

    private static function optionalValue(
        DOMXPath $xpath,
        string $query,
        DOMElement $context,
    ): ?string {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        $value = $node instanceof DOMElement ? trim($node->textContent) : '';

        return $value === '' ? null : $value;
    }
}
