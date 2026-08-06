<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationSchemaCatalog
{
    /** @return array{path:string,namespace:string} */
    public function schemaFor(string $documentType): array
    {
        $root = dirname(__DIR__, 5) . '/xsd/jmhz';
        $definition = match ($documentType) {
            'PREZEC26' => [
                $root . '/prezec-1.2/PREZEC26 1.2.xsd',
                '117d56be4a79ebec3c1684a4b32b94df6dbeb99a7533489359bee48cb9a11b0a',
                $root . '/prezec-1.2/baseTypes2.xsd',
                'http://schemas.cssz.cz/PREZEC/2026',
            ],
            'REGZEC25' => [
                $root . '/regzec-1.4.0.4/REGZEC25.xsd',
                'bbf96586cccd36457283f8474a982d3bee8ae98bbdba120f240065aa6d40a83b',
                $root . '/regzec-1.4.0.4/baseTypes2.xsd',
                'http://schemas.cssz.cz/REGZEC/2025',
            ],
            default => throw new PayrollRegistrationXmlException(
                'registration_schema_unavailable',
                'Požadovaný registrační formulář nemá připnuté XSD.',
            ),
        };
        [$entry, $entryHash, $dependency, $namespace] = $definition;
        foreach ([
            $entry => $entryHash,
            $dependency =>
                '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58',
        ] as $path => $expectedHash) {
            $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actualHash === false
                || !hash_equals($expectedHash, $actualHash)
            ) {
                throw new PayrollRegistrationXmlException(
                    'registration_schema_integrity_failed',
                    'Lokální registrační XSD balíček chybí nebo má jiný otisk.',
                );
            }
        }

        return ['path' => $entry, 'namespace' => $namespace];
    }
}
