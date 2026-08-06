<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Kanonická reprezentace OBSAHU verze rulesetu — bez lifecycle a bez
 * schvalovacích podpisů.
 *
 * `PayrollRulesetVersion::$canonicalHash` zahrnuje i lifecycle a approval, takže
 * se při každém stavovém přechodu mění. Pro správu (diff, kontrola checksumu,
 * důkaz „schvaluji přesně tenhle obsah") je potřeba otisk, který přechodem
 * draft → reviewed → approved → active zůstane beze změny.
 */
final class PayrollRulesetContent
{
    /** @return array<string, mixed> */
    public static function canonicalArray(PayrollRulesetVersion $version): array
    {
        $parameters = [];
        foreach ($version->parameters as $key => $parameter) {
            $parameters[$key] = $parameter->toCanonicalArray();
        }

        return [
            'capability' => $version->capability->value,
            'domain' => $version->domain->value,
            'effective_from' => $version->effectiveFrom,
            'effective_to' => $version->effectiveTo,
            'id' => $version->id,
            'parameters' => $parameters,
            'sources' => array_map(
                static fn (RulesetSource $source): array => $source->toCanonicalArray(),
                $version->sources,
            ),
            'version' => $version->version,
        ];
    }

    public static function encode(PayrollRulesetVersion $version): string
    {
        return CanonicalJson::encode(self::canonicalArray($version));
    }

    public static function hash(string $canonicalSnapshot): string
    {
        return hash('sha256', $canonicalSnapshot);
    }

    /**
     * Uložený snapshot se do otisku počítá po re-kanonizaci: samotný řetězec
     * z databáze mohl projít jiným zápisem (mezery, pořadí klíčů), ale kanonická
     * podoba je jediná a jen ta smí rozhodovat o platnosti checksumu.
     *
     * @return array{canonical:string, hash:string}
     */
    public static function recanonicalize(string $storedSnapshot): array
    {
        $decoded = json_decode($storedSnapshot, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new PayrollRulesetException('Kanonický snapshot rulesetu není objekt.');
        }
        /** @var array<string, mixed> $decoded */
        $canonical = CanonicalJson::encode($decoded);

        return ['canonical' => $canonical, 'hash' => self::hash($canonical)];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, PayrollRuleValue>
     */
    public static function parameters(array $snapshot): array
    {
        $raw = $snapshot['parameters'] ?? null;
        if (!is_array($raw)) {
            throw new PayrollRulesetException('Kanonický snapshot rulesetu nemá parametry.');
        }

        $parameters = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                throw new PayrollRulesetException('Parametr rulesetu má neplatný tvar.');
            }
            /** @var array<string, mixed> $value */
            $parameters[$key] = PayrollRuleValue::fromCanonicalArray($value);
        }
        ksort($parameters, SORT_STRING);

        return $parameters;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return list<RulesetSource>
     */
    public static function sources(array $snapshot): array
    {
        $raw = $snapshot['sources'] ?? null;
        if (!is_array($raw)) {
            throw new PayrollRulesetException('Kanonický snapshot rulesetu nemá zdroje.');
        }

        $sources = [];
        foreach ($raw as $value) {
            if (!is_array($value)) {
                throw new PayrollRulesetException('Zdroj rulesetu má neplatný tvar.');
            }
            $sources[] = new RulesetSource(
                self::text($value, 'id'),
                self::text($value, 'title'),
                self::text($value, 'url'),
                self::text($value, 'retrieved_on'),
            );
        }

        return $sources;
    }

    /** @param array<array-key, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new PayrollRulesetException("Zdroj rulesetu nemá textové pole {$key}.");
        }

        return $value;
    }
}
