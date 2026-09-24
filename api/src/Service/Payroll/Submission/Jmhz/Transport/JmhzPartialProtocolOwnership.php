<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Příslušnost dílčího protokolu ČSSZ k firmě, když protokol variabilní symbol
 * v podepsané části nenese.
 *
 * Dílčí protokol k podání JMHZ, který ČSSZ pošle do datové schránky, je obálka
 * GovTalk s `ProcessingResult`; příloha se jmenuje
 * `JMH-DILCI-PROTOKOL-VS{VS}-{RRRR}-{MM}-IDCSSZ-{id}.xml`. Variabilní symbol
 * a období jsou jen v názvu přílohy a v XML komentáři před obálkou, tedy MIMO
 * pečeť. Podepsaná část ale u každého formuláře nese `identifier="OIČ;ID PPV"`.
 *
 * ID PPV je identifikátor pracovního vztahu u KONKRÉTNÍHO zaměstnavatele,
 * přiděluje ho ČSSZ a v evidenci firmy je uložený jako slepý index. Protokol
 * se proto uzná za protokol téhle firmy jen tehdy, když:
 *
 * 1. projde pečetí ČSSZ ({@see JmhzProtocolSignatureVerifierInterface}) —
 *    bez ní by identifikátory mohl do souboru dopsat kdokoli;
 * 2. má aspoň jeden formulář a KAŽDÝ formulář nese ID PPV, které v evidenci
 *    téhle firmy (v témže prostředí) je. Jediný cizí nebo neznámý vztah
 *    protokol odmítne: raději ať účetní nejdřív načte hlášení, ze kterých se
 *    ID PPV doplní, než aby se cizí doklad uložil pod tenhle tenant;
 * 3. nepodepsaný variabilní symbol z komentáře nebo názvu přílohy (když jsou
 *    oba, musí se shodovat) patří téhle firmě stejně jako u ostatních druhů
 *    protokolu ({@see JmhzProtocolOwnership}).
 *
 * Období z názvu přílohy je jen nápověda pro přehled; ukládá se až po shodě
 * ID PPV a nic se o ně neopírá.
 */
final readonly class JmhzPartialProtocolOwnership
{
    private const ATTACHMENT_PATTERN = '/JMH-DILCI-PROTOKOL-VS(\d{1,10})-((?:19|20)\d{2})-(0[1-9]|1[0-2])-/u';
    private const COMMENT_SYMBOL_PATTERN = '/Variabiln\S*\s+symbol\s*:\s*(\d{1,10})\b/u';

    public function __construct(
        private JmhzProtocolSignatureVerifierInterface $verifier,
        private PayrollRegistrationIdentityRepository $registrations,
        private PayrollSensitiveData $sensitiveData,
        private PayrollImportedJmhzProtocolRepository $protocols,
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
    ) {
    }

    /**
     * @return array{report:JmhzProtocolReport,variable_symbol:string,period_month:?int,period_year:?int}
     */
    public function verify(
        int $supplierId,
        string $environment,
        string $xml,
        ?string $filename,
    ): array {
        $report = $this->parser->parse($this->verifier->verifiedProtocolXml($xml, $environment));
        if ($report->kind !== JmhzProtocolKind::PartialSubmission) {
            throw new JmhzTransportException(
                'jmhz_protocol_variable_symbol_missing',
                'Protokol neobsahuje variabilní symbol, takže nelze ověřit, že'
                    . ' patří této firmě.',
            );
        }
        $this->assertFormsBelongToSupplier($supplierId, $environment, $report);

        $fromName = self::attachmentHint($filename);
        $fromComment = self::commentSymbol($xml);
        if ($fromName !== null && $fromComment !== null
            && ltrim($fromName['variable_symbol'], '0') !== ltrim($fromComment, '0')
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_variable_symbol_conflict',
                'Variabilní symbol v názvu souboru neodpovídá variabilnímu symbolu'
                    . ' v protokolu. Načtěte původní přílohu z datové schránky.',
            );
        }
        $variableSymbol = $fromComment ?? $fromName['variable_symbol'] ?? null;
        if ($variableSymbol === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_variable_symbol_missing',
                'Dílčí protokol neuvádí variabilní symbol ani v úvodní poznámce,'
                    . ' ani v názvu souboru. Načtěte původní přílohu z datové'
                    . ' schránky pod jménem, se kterým přišla.',
            );
        }
        JmhzProtocolOwnership::assert(
            $variableSymbol,
            $this->protocols->employerVariableSymbols($supplierId),
        );

        return [
            'report' => $report,
            'variable_symbol' => $variableSymbol,
            'period_month' => $fromName === null ? null : $fromName['month'],
            'period_year' => $fromName === null ? null : $fromName['year'],
        ];
    }

    private function assertFormsBelongToSupplier(
        int $supplierId,
        string $environment,
        JmhzProtocolReport $report,
    ): void {
        $forms = array_values(array_filter(
            $report->parts,
            static fn (JmhzProtocolPart $part): bool => $part->kind === JmhzProtocolPartKind::Form,
        ));
        if ($forms === []) {
            throw new JmhzTransportException(
                'jmhz_protocol_tenant_unverifiable',
                'Dílčí protokol neobsahuje žádný formulář zaměstnance, takže nelze'
                    . ' ověřit, že patří této firmě.',
            );
        }
        $unknown = 0;
        foreach ($forms as $form) {
            if (!$this->isOwnEmployment($supplierId, $environment, $form->idPpv)) {
                ++$unknown;
            }
        }
        if ($unknown > 0) {
            throw new JmhzTransportException(
                'jmhz_protocol_tenant_unverifiable',
                "Dílčí protokol nese {$unknown} z " . count($forms) . ' formulářů'
                    . ' s ID pracovněprávního vztahu, které v evidenci této firmy není.'
                    . ' Protokol se proto neuloží. Pokud vztahy patří této firmě,'
                    . ' načtěte nejdřív měsíční hlášení, ze kterých se ID doplní.',
            );
        }
    }

    private function isOwnEmployment(int $supplierId, string $environment, ?string $idPpv): bool
    {
        if ($idPpv === null || trim($idPpv) === '') {
            return false;
        }
        try {
            $hash = $this->sensitiveData->lookupHash(
                $idPpv,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->registrations->employmentByExternalIdValueHash(
            $supplierId,
            $environment,
            'id_ppv',
            $hash,
        ) !== null;
    }

    /** @return array{variable_symbol:string,year:int,month:int}|null */
    private static function attachmentHint(?string $filename): ?array
    {
        if ($filename === null || preg_match(self::ATTACHMENT_PATTERN, $filename, $match) !== 1) {
            return null;
        }

        return [
            'variable_symbol' => $match[1],
            'year' => (int) $match[2],
            'month' => (int) $match[3],
        ];
    }

    /**
     * Variabilní symbol z komentáře před kořenovým elementem, kam ho ČSSZ
     * píše. Komentář pečeť nekryje, proto je to jen nápověda, kterou musí
     * potvrdit {@see JmhzProtocolOwnership}.
     */
    private static function commentSymbol(string $xml): ?string
    {
        $root = strpos($xml, '<GovTalkMessage');
        $prolog = $root === false ? $xml : substr($xml, 0, $root);
        if (preg_match_all('/<!--(.*?)-->/su', $prolog, $comments) < 1) {
            return null;
        }
        foreach ($comments[1] as $comment) {
            if (preg_match(self::COMMENT_SYMBOL_PATTERN, $comment, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
