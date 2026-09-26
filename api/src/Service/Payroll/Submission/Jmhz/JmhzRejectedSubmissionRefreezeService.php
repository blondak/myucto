<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use Psr\Clock\ClockInterface;

/**
 * Znovuzmrazení podání JMHZ, které ČSSZ zpracovala a zamítla.
 *
 * NÁLEZ
 * ------------------------------------------------------------------------------
 * Zahození odeslání po zamítnutí vracelo podání na `ready` s TÝMŽ zmrazeným
 * XML, tedy se stejným GUID podání. Pravidlo podání přitom říká, že řádné
 * podání dostává nový GUID vždy, i po zamítnutí
 * ({@see JmhzSubmissionGuidPolicy::forSubmission()}). Stejné R se stejným GUID,
 * VS, obdobím a balíkem ČSSZ od katalogu 1.4.2.10 odmítne kontrolou 22.
 *
 * CO SE MĚNÍ A CO NE
 * ------------------------------------------------------------------------------
 * O každém GUID rozhoduje {@see JmhzSubmissionGuidPolicy}, ne tahle třída:
 *  - řádné podání (R): nový GUID podání a nové GUIDy všech součástí;
 *  - opravné (O) a stornovací (S): GUID podání zůstává, je to GUID řádného
 *    podání. Nový GUID dostanou jen součásti typu R uvnitř opravného podání
 *    (zamítnutá součást se posílá znovu s novým GUID); opravované a stornované
 *    součásti se dál odkazují na svůj původní GUID.
 * Variabilní symbol, období i obsah zůstávají beze změny. Mění se jen čas
 * vyplnění, který musí být mezi hlášeními jedinečný.
 *
 * Nové XML se uloží jako další artefakt téhož podání a čtení zmrazeného
 * dokumentu bere nejnovější. Původní artefakt zůstává jako doklad prvního
 * odeslání.
 */
final readonly class JmhzRejectedSubmissionRefreezeService
{
    public function __construct(
        private JmhzFrozenPayloadReader $frozen,
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private JmhzSubmissionGuidFactory $guids,
        private JmhzScenario1XmlValidator $validator,
        private ClockInterface $clock,
        private JmhzSubmissionGuidPolicy $policy = new JmhzSubmissionGuidPolicy(),
    ) {}

    /**
     * @return array{
     *   refrozen:bool,submission_guid:string,previous_submission_guid:string,
     *   renewed_form_guids:int,artifact_id:?int,submission_row_version:int
     * }
     */
    public function refreeze(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedRowVersion,
        ?int $createdBy,
    ): array {
        // Zamítnuté rozdělené hlášení (zamítnutý první balík) se nezmrazuje
        // znovu po jednom artefaktu: nový GUID musí nést všechny balíky naráz.
        // Připraví se znovu z přípravy hlášení, tlačítkem Odeslat.
        if ($this->repository->listPackageOutboundXmlArtifacts($supplierId, $environment, $submissionId) !== []) {
            throw new JmhzXmlException(
                'jmhz_submission_split_refreeze_unsupported',
                'Hlášení rozdělené do dílčích balíků nejde znovu zmrazit s novým GUID po jednom balíku.'
                    . ' Zahoďte zamítnuté podání a hlášení zmrazte znovu z přípravy.',
            );
        }
        $artifactId = $this->repository->findOutboundXmlArtifactId($supplierId, $environment, $submissionId);
        $artifact = $artifactId === null ? null : $this->repository->findArtifact($supplierId, $artifactId);
        if ($artifact === null) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_missing',
                'Podání nemá uloženou zmrazenou datovou větu, takže ho nelze'
                    . ' znovu zmrazit s novým GUID.',
            );
        }
        $xml = $this->submissions->artifactBytes($supplierId, $artifactId);
        $identity = JmhzFrozenSubmissionIdentity::read($xml);
        $described = $this->frozen->describe($supplierId, $environment, $submissionId);

        $submissionGuid = $identity->submissionGuid;
        if ($this->policy->forSubmission(
            $described['submission_type'],
            JmhzSubmissionGuidPolicy::SUBMISSION_REJECTED,
        ) === JmhzSubmissionGuidPolicy::NEW_GUID) {
            $submissionGuid = strtoupper($this->guids->next());
        }
        $renewedForms = [];
        foreach ($described['forms'] as $form) {
            if ($form['form_type'] !== JmhzSubmissionFlagMatrix::TYPE_REGULAR) {
                continue;
            }
            if ($this->policy->forForm(
                $described['submission_type'],
                $form['form_type'],
                JmhzSubmissionGuidPolicy::FORM_REJECTED,
            ) === JmhzSubmissionGuidPolicy::NEW_GUID) {
                $renewedForms[$form['form_guid']] = strtoupper($this->guids->next());
            }
        }
        if ($submissionGuid === $identity->submissionGuid && $renewedForms === []) {
            return [
                'refrozen' => false,
                'submission_guid' => $identity->submissionGuid,
                'previous_submission_guid' => $identity->submissionGuid,
                'renewed_form_guids' => 0,
                'artifact_id' => null,
                'submission_row_version' => $expectedRowVersion,
            ];
        }

        $refrozen = $this->rewrite($xml, $identity->submissionGuid, $submissionGuid, $renewedForms);
        $this->validator->validateFrozen($refrozen);
        $check = JmhzFrozenSubmissionIdentity::read($refrozen);
        if ($check->submissionGuid !== $submissionGuid
            || $check->variableSymbol !== $identity->variableSymbol
            || $check->month !== $identity->month
            || $check->year !== $identity->year
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_refreeze_identity_mismatch',
                'Znovu zmrazené podání nenese očekávaný GUID, variabilní symbol nebo období.',
            );
        }

        $stored = $this->submissions->storeArtifact(
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $artifact['part_id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            $refrozen,
            $artifact['xsd_version'],
            $artifact['catalog_version'],
            $artifact['channel'],
            'jmhz25-refreeze:' . $submissionId . ':' . $submissionGuid . ':' . hash('sha256', $refrozen),
            $createdBy,
        );

        return [
            'refrozen' => true,
            'submission_guid' => $submissionGuid,
            'previous_submission_guid' => $identity->submissionGuid,
            'renewed_form_guids' => count($renewedForms),
            'artifact_id' => (int) $stored['id'],
            'submission_row_version' => (int) $stored['submission_row_version'],
        ];
    }

    /**
     * Mění se jen hodnoty v hlavičkách, bajty zbytku zůstávají, jak se
     * zmrazily. Každá náhrada se počítá: GUID, který by v XML nebyl právě
     * jednou, znamená dokument jiného tvaru, než jaký známe, a takový se
     * raději nezmrazí.
     *
     * @param array<string,string> $renewedForms starý GUID součásti → nový
     */
    private function rewrite(
        string $xml,
        string $previousSubmissionGuid,
        string $submissionGuid,
        array $renewedForms,
    ): string {
        $replace = static function (string $xml, string $element, string $old, string $new): string {
            $count = 0;
            $result = preg_replace(
                '~(<(?:[A-Za-z_][\w.-]*:)?' . $element . '>)\s*' . preg_quote($old, '~')
                    . '\s*(</(?:[A-Za-z_][\w.-]*:)?' . $element . '>)~i',
                '${1}' . $new . '${2}',
                $xml,
                -1,
                $count,
            );
            if (!is_string($result) || $count !== 1) {
                throw new JmhzXmlException(
                    'jmhz_submission_refreeze_guid_not_unique',
                    "GUID {$old} není ve zmrazeném podání právě jednou.",
                );
            }

            return $result;
        };

        if ($submissionGuid !== $previousSubmissionGuid) {
            $xml = $replace($xml, 'idPodani', $previousSubmissionGuid, $submissionGuid);
        }
        foreach ($renewedForms as $old => $new) {
            $xml = $replace($xml, 'idFormulare', $old, $new);
        }
        $filledAt = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $count = 0;
        $xml = (string) preg_replace(
            '~(<(?:[A-Za-z_][\w.-]*:)?datumVyplneni>)[^<]*(</(?:[A-Za-z_][\w.-]*:)?datumVyplneni>)~',
            '${1}' . $filledAt . '${2}',
            $xml,
            1,
            $count,
        );
        if ($count !== 1) {
            throw new JmhzXmlException(
                'jmhz_submission_refreeze_filled_at_missing',
                'Zmrazené podání nenese čas vyplnění.',
            );
        }

        return $xml;
    }
}
