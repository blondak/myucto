<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Připnutá jednotná datová věta zdravotních pojišťoven, účinná od 1. 1. 2026.
 *
 * Věta je od toho data společná pro všech SEDM pojišťoven — doloženo bajtovou
 * shodou obou schémat stažených nezávisle od ČPZP (205) a OZP (207). Autorem
 * je VZP, namespace je `xmlns.vzp.cz`. Společný ale NENÍ kanál; ten řeší
 * {@see HealthInsurerChannelCatalog}.
 *
 * **Balíček v repu zatím není.** Rešerše ho vědomě nezkopírovala
 * (`private/Mzdy/21-ZP-PODANI-RESERSE.md`, § 11) a stahovat schéma za běhu
 * nepřipadá v úvahu — zdrojové URL ČPZP jsou CDN hashe, které se mohou kdykoli
 * změnit. Katalog proto drží MANIFEST (URL, otisk, namespace, kořen, konstanty)
 * a `schemaFor()` skončí `zp_schema_bundle_missing`, dokud soubory nepřibudou.
 * Otisky pocházejí z rešerše a jsou tím, co se po stažení musí sedět —
 * ne tím, co se dopočítá z toho, co zrovna přijde.
 */
final class HealthInsuranceSchemaCatalog
{
    public const HOZ = 'HOZ_2026';
    public const PPZ = 'PPZ_2026';

    /** Verze technické revize obou schémat (revize 08, 8. 12. 2025). */
    public const XSD_VERSION = '2025-v8';

    public const SOURCE_REFERENCE = 'private/Mzdy/21-ZP-PODANI-RESERSE.md';

    /** Sedm pojišťoven z enumu `kodZdravotniPojistovnyTyp` obou schémat. */
    public const INSURER_CODES = [
        '111', '201', '205', '207', '209', '211', '213',
    ];

    /**
     * @var array<string,array{
     *   filename:string,sha256:string,url:string,namespace:string,
     *   root:string,subject_text:string,subject_code:string
     * }>
     */
    private const MANIFEST = [
        self::HOZ => [
            'filename' => 'hromadneOznameniZamestnavatele_2025_v8.xsd',
            'sha256' =>
                '67b19d3f70b27f30b7f26b46da79a75f53c89a7c6cf04adc81111558826959d9',
            'url' => 'https://www.ozp.cz/web/files/formulare/'
                . 'hromadneOznameniZamestnavatele_2025_v8.xsd',
            'namespace' =>
                'http://xmlns.vzp.cz/hromadneOznameniZamestnavatele/v1',
            'root' => 'hromadneOznameniZamestnavatele',
            'subject_text' => 'Hromadné oznámení zaměstnavatele 2026+',
            'subject_code' => '882b97d9-3a41-4552-887b-3942ae92c3ea',
        ],
        self::PPZ => [
            'filename' => 'prehledPlatbyZamestnavatele_2025_v8.xsd',
            'sha256' =>
                'fee3c66233bc3c2bd78e283b76918759be9b6a701003c8bffeb6db91e311cba1',
            'url' => 'https://www.ozp.cz/web/files/formulare/'
                . 'prehledPlatbyZamestnavatele_2025_v8.xsd',
            'namespace' =>
                'http://xmlns.vzp.cz/PrehledPlatbyZamestnavatele/v1',
            'root' => 'prehledPlatbyZamestnavatele',
            'subject_text' => 'Přehled platby zaměstnavatele pro ZP 2026+',
            'subject_code' => '1079e224-84f4-46e4-99e8-6095bd282301',
        ],
    ];

    /** @return list<string> */
    public function documentTypes(): array
    {
        return array_keys(self::MANIFEST);
    }

    /**
     * Manifest bez souborů: namespace, kořen a fixní konstanty jsou z rešerše
     * doložené, takže je smí použít i serializér, který na XSD teprve čeká.
     *
     * @return array{
     *   filename:string,sha256:string,url:string,namespace:string,
     *   root:string,subject_text:string,subject_code:string,
     *   xsd_version:string,path:string,available:bool
     * }
     */
    public function manifestFor(string $documentType): array
    {
        $entry = self::MANIFEST[$documentType] ?? null;
        if ($entry === null) {
            throw new HealthNotificationException(
                'zp_document_type_unknown',
                'Jednotná datová věta zdravotních pojišťoven tenhle dokument nemá.',
            );
        }
        $path = $this->directory() . '/' . $entry['filename'];

        return $entry + [
            'xsd_version' => self::XSD_VERSION,
            'path' => $path,
            'available' => $this->matchesPin($path, $entry['sha256']),
        ];
    }

    /**
     * Cesta k připnutému XSD. Fail-closed: dokud balíček v repu není nebo má
     * jiný otisk, nesmí projít ani validace, ani nic, co ji předpokládá.
     *
     * @return array{path:string,namespace:string,root:string,xsd_version:string}
     */
    public function schemaFor(string $documentType): array
    {
        $manifest = $this->manifestFor($documentType);
        if (!$manifest['available']) {
            throw new HealthNotificationException(
                'zp_schema_bundle_missing',
                sprintf(
                    'Připnuté XSD %s v repozitáři není nebo má jiný otisk. '
                    . 'Stáhněte %s (SHA-256 %s) do api/xsd/zp/%s/ a spusťte '
                    . 'podání znovu; schéma se za běhu záměrně nestahuje.',
                    $documentType,
                    $manifest['filename'],
                    $manifest['sha256'],
                    self::XSD_VERSION,
                ),
            );
        }

        return [
            'path' => $manifest['path'],
            'namespace' => $manifest['namespace'],
            'root' => $manifest['root'],
            'xsd_version' => $manifest['xsd_version'],
        ];
    }

    public function isBundleAvailable(): bool
    {
        foreach ($this->documentTypes() as $documentType) {
            if (!$this->manifestFor($documentType)['available']) {
                return false;
            }
        }

        return true;
    }

    public function assertInsurerCode(string $code): void
    {
        if (!in_array($code, self::INSURER_CODES, true)) {
            throw new HealthNotificationException(
                'zp_insurer_code_unknown',
                'Kód zdravotní pojišťovny není v jednotné datové větě.',
            );
        }
    }

    private function directory(): string
    {
        return dirname(__DIR__, 5) . '/xsd/zp/' . self::XSD_VERSION;
    }

    private function matchesPin(string $path, string $expected): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $actual = hash_file('sha256', $path);

        return is_string($actual) && hash_equals($expected, $actual);
    }
}
