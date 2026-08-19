<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Připnuté XSD e-podání OZUSPOJ.
 *
 * Balíček stojí mimo `api/xsd/jmhz/`, protože OZUSPOJ nevydává MPSV jako ZIP na
 * `developers.mpsv.cz`, ale ČSSZ jako jednotlivý soubor — downloader JMHZ na něj
 * nedosáhne. Otisky se proto ověřují tady, stejně fail-closed jako u registrací:
 * bez shody bajtů se nesmí serializovat nic.
 */
final class OzuspojSchemaCatalog
{
    public const DOCUMENT_TYPE = 'OZUSPOJ23';
    public const NAMESPACE = 'http://schemas.cssz.cz/POJ/OZUSPOJ23';

    private const ENTRY_SHA256 =
        'e4d3968852aaa30e0cc7b37933bdee015fb3288e7a0b7136696ab53df2dce989';
    private const BASE_TYPES_SHA256 =
        '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58';

    /** @return array{path:string,namespace:string} */
    public function schemaFor(string $documentType): array
    {
        if ($documentType !== self::DOCUMENT_TYPE) {
            throw new OzuspojException(
                'ozuspoj_schema_unavailable',
                'Požadovaný formulář nemá připnuté XSD OZUSPOJ.',
            );
        }
        $root = dirname(__DIR__, 5) . '/xsd/cssz/ozuspoj-1.2';
        foreach ([
            $root . '/OZUSPOJ23.xsd' => self::ENTRY_SHA256,
            $root . '/baseTypes2.xsd' => self::BASE_TYPES_SHA256,
        ] as $path => $expectedHash) {
            $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actualHash === false
                || !hash_equals($expectedHash, $actualHash)
            ) {
                throw new OzuspojException(
                    'ozuspoj_schema_integrity_failed',
                    'Lokální XSD balíček OZUSPOJ chybí nebo má jiný otisk.',
                );
            }
        }

        return [
            'path' => $root . '/OZUSPOJ23.xsd',
            'namespace' => self::NAMESPACE,
        ];
    }
}
