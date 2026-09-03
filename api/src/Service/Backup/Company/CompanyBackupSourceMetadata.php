<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Bootstrap;

/** Stabilní aplikační metadata a lidský CTI-MNE.txt uvnitř archivu. */
final readonly class CompanyBackupSourceMetadata
{
    public function version(): string
    {
        $version = trim((string) @file_get_contents(
            Bootstrap::rootDir() . '/VERSION',
        ));
        if (!CompanyBackupManifestHeader::isSemanticVersion($version)) {
            throw new CompanyBackupJobException('source_version_unavailable');
        }
        return $version;
    }

    public function readme(string $backupId, string $version): string
    {
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
            || !CompanyBackupManifestHeader::isSemanticVersion($version)
        ) {
            throw new \InvalidArgumentException(
                'Metadata CTI-MNE.txt zálohy nejsou platná.',
            );
        }

        return <<<TXT
MyÚčto — přenositelná záloha firmy

Identifikátor zálohy: {$backupId}
Zdrojová verze aplikace: {$version}

Archiv je šifrovaný heslem zvoleným při vytvoření. Bez tohoto hesla jej nelze
obnovit. Heslo není uloženo uvnitř archivu ani v tomto souboru.

Strojová data a jejich formát popisuje manifest.json. Integritu všech položek
lze ověřit podle CHECKSUMS.txt. Pro bezpečnou obnovu použijte stejnou nebo
novější podporovanou verzi MyÚčta a nejprve spusťte preflight.

Do rozbaleného obsahu nezasahujte; změna libovolné položky poruší kontrolní
součty a MyÚčto takový balíček odmítne.
TXT;
    }
}
