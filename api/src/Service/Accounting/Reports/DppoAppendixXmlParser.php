<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Čte přílohu účetní závěrky z XML přiznání DPPDP9 — věty VetaUA (aktiva), VetaUB (VZZ)
 * a VetaUD (pasiva) ve tvaru `věta => c_radku => sloupec => hodnota` (celé tisíce Kč).
 *
 * Slouží dvěma stranám stejného porovnání: podanému přiznání (nahrané nebo archivované
 * XML) i příloze, kterou z účetnictví vyrobí {@see \MyInvoice\Service\Tax\Return\DppoXmlBuilder}.
 * Obě strany tak projdou stejným čtením a rozdíl nemůže vzniknout jen jiným parsováním.
 *
 * Řádkový parser II. oddílu ({@see \MyInvoice\Service\Tax\Return\DppoEpoXmlParser}) přílohu
 * nečte, proto vlastní třída. Bezpečnost stejná: bez externích entit a DTD (XXE).
 */
final class DppoAppendixXmlParser
{
    public const SENTENCES = ['VetaUA', 'VetaUB', 'VetaUD'];

    /**
     * @return array{zdobd_od: ?string, zdobd_do: ?string, ic: string,
     *               appendix: array<string, array<int, array<string,int>>>}
     */
    public function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            throw new ReportException('invalid_xml', 'Soubor přiznání je prázdný.', 400);
        }

        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new ReportException('invalid_xml', 'Soubor přiznání není platné XML.', 400);
        }
        if ($dom->getElementsByTagName('DPPDP9')->item(0) === null) {
            throw new ReportException('wrong_form', 'Soubor není přiznání k dani z příjmů právnických osob (DPPDP9).', 400);
        }

        $vetaD = $dom->getElementsByTagName('VetaD')->item(0);
        $vetaP = $dom->getElementsByTagName('VetaP')->item(0);

        $appendix = [];
        foreach (self::SENTENCES as $sentence) {
            $appendix[$sentence] = [];
            foreach ($dom->getElementsByTagName($sentence) as $el) {
                /** @var \DOMElement $el */
                $cRadku = (int) $el->getAttribute('c_radku');
                if ($cRadku <= 0) {
                    continue;
                }
                $values = [];
                foreach ($el->attributes as $attr) {
                    /** @var \DOMAttr $attr */
                    if ($attr->nodeName === 'c_radku') {
                        continue;
                    }
                    $raw = trim((string) $attr->nodeValue);
                    if ($raw !== '' && is_numeric($raw)) {
                        $values[$attr->nodeName] = (int) round((float) $raw);
                    }
                }
                $appendix[$sentence][$cRadku] = $values;
            }
        }

        // Rozsah rozvahy podání (P plný, Z zkrácený pro malou ÚJ, M pro mikro ÚJ).
        $scope = match ($vetaD instanceof \DOMElement ? trim($vetaD->getAttribute('uv_rozsah_rozv')) : '') {
            'P' => 'full',
            'Z' => 'small',
            'M' => 'micro',
            default => null,
        };

        return [
            'scope'    => $scope,
            'zdobd_od' => $vetaD instanceof \DOMElement ? self::isoDate($vetaD->getAttribute('zdobd_od')) : null,
            'zdobd_do' => $vetaD instanceof \DOMElement ? self::isoDate($vetaD->getAttribute('zdobd_do')) : null,
            'ic'       => $vetaP instanceof \DOMElement ? trim($vetaP->getAttribute('rod_c')) : '',
            'appendix' => $appendix,
        ];
    }

    private static function isoDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!j.n.Y', $v);

        return $d === false ? null : $d->format('Y-m-d');
    }
}
