<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

final class RegzelSchemaCatalog
{
    private const ENTRY_SHA256 =
        '566a124a708492d783a75296eb37a4f76a49ccc5d0aa7d5119a0fa02eee6eedf';
    private const BASE_TYPES_SHA256 =
        '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58';

    /**
     * @return array{
     *   document_type:string,interaction:string,xsd_version:string,
     *   namespace:string,path:string
     * }
     */
    public function schemaFor(string $interaction): array
    {
        if ($interaction !== 'supplemental_information') {
            throw new RegzelValidationException(
                'regzel_schema_unavailable',
                'Lokální oficiální balíček neobsahuje XSD požadované REGZEL interakce.',
            );
        }

        $directory = dirname(__DIR__, 5)
            . '/xsd/jmhz/regzeldopl-1.2';
        $path = $directory . '/REGZELDOPL25.xsd';
        $baseTypes = $directory . '/baseTypes2.xsd';
        foreach ([
            $path => self::ENTRY_SHA256,
            $baseTypes => self::BASE_TYPES_SHA256,
        ] as $file => $expectedHash) {
            $actualHash = is_file($file)
                ? hash_file('sha256', $file)
                : false;
            if ($actualHash === false
                || !hash_equals($expectedHash, $actualHash)
            ) {
                throw new RegzelValidationException(
                    'regzel_schema_integrity_failed',
                    'Připnutý lokální REGZEL XSD balíček chybí nebo má jiný otisk.',
                );
            }
        }

        return [
            'document_type' => 'REGZELDOPL25',
            'interaction' => $interaction,
            'xsd_version' => RegzelPayloadSnapshot::XSD_VERSION,
            'namespace' => 'http://schemas.cssz.cz/REGZELDOPL/2025',
            'path' => $path,
        ];
    }
}
