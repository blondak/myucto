<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileExporter;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter;

/**
 * Nastavení firmy, které má přežít opakovaný převod (výjimky mapování výkazů, čistý
 * obrat, výchozí dimenze, bankovní pravidla, volby přiznání): dávkový převod si ho
 * před převodem do existující firmy odloží ({@see capture()}) a po úspěšném převodu
 * obnoví ({@see restore()}). Odložení i obnova jdou přes profil firmy, tedy stejnou
 * validací jako nahrání profilu v Nastavení.
 */
class CompanyProfileCarryOver
{
    public function __construct(
        private readonly CompanyProfileExporter $exporter,
        private readonly CompanyProfileImporter $importer,
    ) {}

    /**
     * Profil nastavení firmy před převodem, null = není co odkládat.
     *
     * @return array<string,mixed>|null
     */
    public function capture(int $supplierId): ?array
    {
        try {
            return $this->exporter->export($supplierId);
        } catch (CompanyProfileException) {
            return null;
        }
    }

    /**
     * Obnoví profil po převodu.
     *
     * @param array<string,mixed> $profile výsledek {@see capture()}
     * @return list<string> hlášení do protokolu dávky
     */
    public function restore(int $supplierId, array $profile): array
    {
        try {
            $result = $this->importer->import($supplierId, $profile, false);
        } catch (CompanyProfileException $e) {
            return [sprintf('Profil nastavení firmy se po převodu neobnovil: %s', $e->getMessage())];
        }
        $messages = $result['warnings'];
        if ($result['changed'] > 0) {
            $messages[] = sprintf('Profil nastavení firmy obnoven po převodu (%d změn).', $result['changed']);
        }
        return $messages;
    }
}
