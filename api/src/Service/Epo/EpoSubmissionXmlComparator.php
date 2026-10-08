<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

/**
 * Řekne, ČÍM se ručně nahrané XML liší od archivovaného snapshotu.
 *
 * U asistovaného podání jde soubor přes ruce a přes formulář EPO: účetní tam může
 * hodnotu doupravit, portál může přiznání znovu vygenerovat. Když se pak nahraje
 * zpátky, samotná neshoda otisku říká jen „tohle není ono" — a to je informace,
 * se kterou se nedá nic dělat. Rozdíl po položkách naopak rovnou ukáže, jestli šlo
 * o úpravu čísla (pak snapshot NEODPOVÍDÁ podanému a je potřeba vygenerovat nový),
 * nebo jen o jiný formulář či období (pak byl nahrán soubor od jiného podání).
 *
 * Porovnává se struktura, ne bajty: whitespace ani pořadí atributů nehrají roli.
 */
final class EpoSubmissionXmlComparator
{
    private const MAX_DIFFERENCES = 40;

    /**
     * @return array{
     *   comparable:bool,
     *   form_code:?string,expected_form_code:?string,form_match:?bool,
     *   difference_count:int,
     *   differences:list<array{path:string,expected:?string,actual:?string}>
     * }
     */
    public function compare(string $expectedXml, string $actualXml): array
    {
        return $this->compareDocuments($expectedXml, $actualXml, false);
    }

    /**
     * Porovná snapshot s echem podání z potvrzenky EPO.
     *
     * Portál EPO písemnost před podpisem sám přegeneruje: přepíše `nazevSW`/`verzeSW`,
     * přeřadí atributy, zahodí odsazení i prázdné věty (`VetaR` jen s `poradi`),
     * identifikaci (`VetaP`) může nahradit údaji přihlášené identity a čísla vypíše
     * jinak (`100000.0`). `Kontrola/@KC` je pak MD5 JEHO XML, ne exportu, takže shodu
     * obsahu nese jen věcné porovnání vět. Identifikace podatele se nepočítá — to,
     * KDO podal, potvrzuje pečeť EPO, ne snapshot.
     *
     * @return array{
     *   comparable:bool,
     *   form_code:?string,expected_form_code:?string,form_match:?bool,
     *   difference_count:int,
     *   differences:list<array{path:string,expected:?string,actual:?string}>
     * }
     */
    public function compareFiledContent(string $expectedXml, string $echoXml): array
    {
        return $this->compareDocuments($expectedXml, $echoXml, true);
    }

    /**
     * @return array{
     *   comparable:bool,
     *   form_code:?string,expected_form_code:?string,form_match:?bool,
     *   difference_count:int,
     *   differences:list<array{path:string,expected:?string,actual:?string}>
     * }
     */
    private function compareDocuments(string $expectedXml, string $actualXml, bool $filedContent): array
    {
        $empty = [
            'comparable' => false,
            'form_code' => null,
            'expected_form_code' => null,
            'form_match' => null,
            'difference_count' => 0,
            'differences' => [],
        ];

        $expected = $this->load($expectedXml);
        $actual = $this->load($actualXml);
        if ($expected === null || $actual === null) {
            return $empty;
        }

        $expectedForm = $this->formCode($expected);
        $actualForm = $this->formCode($actual);

        if ($filedContent) {
            $this->stripPortalNoise($expected);
            $this->stripPortalNoise($actual);
        }
        $expectedValues = $this->flatten($expected, $filedContent);
        $actualValues = $this->flatten($actual, $filedContent);

        $differences = [];
        foreach ($expectedValues as $path => $value) {
            if (!array_key_exists($path, $actualValues)) {
                $differences[$path] = ['path' => $path, 'expected' => $value, 'actual' => null];
            } elseif ($actualValues[$path] !== $value) {
                $differences[$path] = ['path' => $path, 'expected' => $value, 'actual' => $actualValues[$path]];
            }
        }
        foreach ($actualValues as $path => $value) {
            if (!array_key_exists($path, $expectedValues)) {
                $differences[$path] = ['path' => $path, 'expected' => null, 'actual' => $value];
            }
        }

        return [
            'comparable' => true,
            'form_code' => $actualForm,
            'expected_form_code' => $expectedForm,
            'form_match' => $expectedForm !== null && $actualForm !== null
                ? $expectedForm === $actualForm
                : null,
            'difference_count' => count($differences),
            'differences' => array_slice(array_values($differences), 0, self::MAX_DIFFERENCES),
        ];
    }

    private function load(string $xml): ?\DOMDocument
    {
        if ($xml === '' || strlen($xml) > 20 * 1024 * 1024) {
            return null;
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $ok ? $dom : null;
    }

    private function formCode(\DOMDocument $dom): ?string
    {
        $known = [
            'dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dpfdp7', 'dppdp9', 'ossei1',
            'dpzmb1', 'dpzdb1', 'dpzvd6', 'dpsvd2', 'dpshl1', 'dpszd1',
        ];
        foreach ((new \DOMXPath($dom))->query('//*') ?: [] as $node) {
            $name = strtolower((string) $node->localName);
            if (in_array($name, $known, true)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Plochá mapa `cesta@atribut => hodnota`. Opakující se sourozenci dostanou pořadové
     * číslo, jinak by se řádky kontrolního hlášení navzájem přebily a rozdíl by zmizel.
     *
     * @return array<string,string>
     */
    private function flatten(\DOMDocument $dom, bool $filedContent = false): array
    {
        $values = [];
        $walk = function (\DOMElement $element, string $prefix) use (&$walk, &$values, $filedContent): void {
            foreach ($element->attributes ?? [] as $attribute) {
                if (!$attribute instanceof \DOMAttr) {
                    continue;
                }
                $value = $this->normalize($attribute->value);
                if ($filedContent) {
                    if ($value === '') {
                        continue;
                    }
                    $value = $this->normalizeNumber($value);
                }
                $values[$prefix . '@' . $attribute->localName] = $value;
            }
            $counts = [];
            $hasChildElement = false;
            foreach ($element->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $hasChildElement = true;
                $name = (string) $child->localName;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $walk($child, $prefix . '/' . $name . '[' . $counts[$name] . ']');
            }
            if (!$hasChildElement) {
                $text = $this->normalize($element->textContent);
                if ($text !== '') {
                    $values[$prefix] = $text;
                }
            }
        };

        $root = $dom->documentElement;
        if ($root !== null) {
            if ($filedContent) {
                // `nazevSW`/`verzeSW` na kořeni přepisuje portál na sebe.
                foreach (iterator_to_array($root->attributes ?? []) as $attribute) {
                    if ($attribute instanceof \DOMAttr) {
                        $root->removeAttributeNode($attribute);
                    }
                }
            }
            $walk($root, (string) $root->localName);
        }
        return $values;
    }

    /**
     * Odstraní z dokumentu, co portál EPO při přegenerování mění nebo doplňuje:
     * identifikaci podatele (`VetaP`), vlastní kontrolní blok (`Kontrola`) a věty
     * bez věcného obsahu (jen pořadové číslo). Atributy kořene odstraní {@see flatten()}.
     */
    private function stripPortalNoise(\DOMDocument $dom): void
    {
        $remove = [];
        foreach ((new \DOMXPath($dom))->query('//*') ?: [] as $node) {
            if (!$node instanceof \DOMElement || $node === $dom->documentElement) {
                continue;
            }
            $name = (string) $node->localName;
            if ($name === 'VetaP' || $name === 'Kontrola') {
                $remove[] = $node;
                continue;
            }
            if (!$this->hasSubstance($node)) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function hasSubstance(\DOMElement $element): bool
    {
        foreach ($element->attributes ?? [] as $attribute) {
            if (
                $attribute instanceof \DOMAttr
                && $attribute->localName !== 'poradi'
                && $this->normalize($attribute->value) !== ''
            ) {
                return true;
            }
        }
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && $this->hasSubstance($child)) {
                return true;
            }
        }
        return $element->getElementsByTagName('*')->length === 0
            && $this->normalize($element->textContent) !== '';
    }

    /** `100000`, `100000.0` i `100000.00` je táž částka. */
    private function normalizeNumber(string $value): string
    {
        if (preg_match('/^-?\d+\.\d+$/', $value) !== 1) {
            return $value;
        }
        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '-0' ? '0' : $value;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
