<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

/**
 * Připnutá XSD exportu zaměstnanců z ePortálu ČSSZ (`ExportZamestnancu`).
 *
 * MPSV zveřejnilo schéma pro oba tvary exportu: dosavadní (bez začátku
 * pojistného vztahu) a tvar platný od 15. 10. 2026, který u každé věty nese
 * povinné `PojistnyVztahOd`. Soubor se validuje proti tvaru, kterému odpovídá;
 * otisky se ověřují fail-closed stejně jako u ostatních schémat ČSSZ
 * (`api/xsd/cssz/export-zamestnancu.md`).
 */
final class CsszEmployeeExportSchemaCatalog
{
    public const VERSION_LEGACY = 'v1';
    public const VERSION_2026_10 = 'v2';

    private const SCHEMAS = [
        self::VERSION_LEGACY => [
            'directory' => 'export-zamestnancu-v1',
            'sha256' => '27ef2c3a8739cca4ed1a75ea2b634e71c1dc30ae81f850b370a4c13267d49ff2',
            'label' => 'export zaměstnanců ČSSZ (tvar do 14. 10. 2026)',
        ],
        self::VERSION_2026_10 => [
            'directory' => 'export-zamestnancu-v2',
            'sha256' => '7b74be3df755bb619e086cb850ea47210f70209b205fe2a1d5da63a98050443c',
            'label' => 'export zaměstnanců ČSSZ (tvar od 15. 10. 2026)',
        ],
    ];

    /**
     * @return array{path:string,label:string}
     * @throws RegistrationImportFileException když schéma chybí nebo má jiný otisk
     */
    public function schemaFor(string $version): array
    {
        $entry = self::SCHEMAS[$version] ?? throw new \InvalidArgumentException("Neznámá verze exportu zaměstnanců {$version}.");
        $path = dirname(__DIR__, 5) . '/xsd/cssz/' . $entry['directory'] . '/SeznamZamestnancu.xsd';
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        if ($hash === false || !hash_equals($entry['sha256'], $hash)) {
            throw new RegistrationImportFileException(
                'Lokální schéma exportu zaměstnanců ČSSZ chybí nebo má jiný otisk. Soubor se nepřebírá; '
                . 'obnovte instalaci aplikace.',
            );
        }

        return ['path' => $path, 'label' => $entry['label']];
    }
}
