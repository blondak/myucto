<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Report\EpoEnvelope;

/**
 * Lokální nácvik podání. Sestaví XML běžného měsíčního hlášení z ověřeného
 * preparation snapshotu a ověří je proti připnutému XSD — nic neodesílá,
 * nic neukládá a nezakládá žádné podání.
 *
 * GUIDy vznikají při každém běhu nové a jsou proto použitelné VÝHRADNĚ pro
 * náhled. Ostré podání si musí GUID vyžádat a zmrazit, protože duplicitu
 * přijatého podání nelze u ČSSZ nikdy zopakovat.
 */
final readonly class JmhzScenario1XmlDryRunService
{
    private const PRODUCT_NAME = 'MyÚčto.cz';

    public function __construct(
        private JmhzScenario1DocumentService $documents,
        private JmhzScenario1XmlValidator $validator,
        private JmhzSubmissionGuidFactory $guids,
        private JmhzScenario1ControlValidator $controls,
    ) {}

    /** @return array<string,mixed> */
    public function dryRun(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): array {
        $resolution = $this->documents->resolve(
            $supplierId,
            $environment,
            $preparationId,
        );
        $blockers = array_map(
            static fn (JmhzScenario1Blocker $blocker): array => $blocker->toArray(),
            $resolution->blockers,
        );
        if ($resolution->status() !== 'resolved') {
            return [
                'status' => 'blocked',
                'preparation_id' => $preparationId,
                'blockers' => $blockers,
                'official_submission' => $this->officialSubmission(),
            ];
        }

        $document = $resolution->requireResolvedDocument();
        $result = $this->validator->dryRun(
            $resolution,
            JmhzSubmissionEnvelope::create(
                $this->guids->next(),
                $this->formGuids($document),
                gmdate('Y-m-d\TH:i:s\Z'),
                self::PRODUCT_NAME,
                EpoEnvelope::appVersion() ?? '0',
            ),
        );

        // XSD hlídá tvar, katalog kontrol obsah. Teprve oboje dohromady říká,
        // jestli by ČSSZ podání přijala — a mezera v pokrytí katalogu se musí
        // projevit jako nepřipravenost, ne jako zelený nácvik.
        $controls = $this->controls->validate(
            $result['xml'],
            JmhzControlContext::today(schemaValidated: true),
        );

        return [
            'status' => $controls->submittable() ? 'dry_run_valid' : 'dry_run_incomplete',
            'preparation_id' => $preparationId,
            'blockers' => [],
            'controls' => $controls->toArray(),
            'xml' => $result['xml'],
            'xml_sha256' => $result['sha256'],
            'schema' => $result['schema'],
            'guids' => [
                'scope' => 'preview_only',
                'note' => 'GUIDy náhledu se pro ostré podání nepoužijí; to si vyžádá vlastní a zmrazí je.',
            ],
            'official_submission' => $this->officialSubmission(),
        ];
    }

    /** @return array<int,string> */
    private function formGuids(JmhzScenario1NormalizedDocument $document): array
    {
        $people = $document->payload['people'] ?? null;
        $guids = [];
        if (!is_array($people)) {
            return $guids;
        }
        foreach ($people as $person) {
            $employments = is_array($person) ? ($person['employments'] ?? null) : null;
            if (!is_array($employments)) {
                continue;
            }
            foreach ($employments as $employment) {
                $employmentId = is_array($employment)
                    ? ($employment['employment_id'] ?? null)
                    : null;
                if (is_int($employmentId) && $employmentId > 0) {
                    $guids[$employmentId] = $this->guids->next();
                }
            }
        }

        return $guids;
    }

    /** @return array<string,mixed> */
    private function officialSubmission(): array
    {
        return [
            'supported' => false,
            'reason_code' => 'jmhz_transport_not_implemented',
            'reason' => 'Kanál VREP/APEP ani ISDS zatím není zapojený; jde o lokální nácvik, ne o podání.',
        ];
    }
}
