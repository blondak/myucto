<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Nastavení firmy, které má přežít opakovaný převod (výjimky mapování výkazů, čistý
 * obrat, výchozí dimenze, bankovní pravidla, volby přiznání): dávkový převod si ho
 * před převodem do existující firmy odloží ({@see capture()}) a po úspěšném převodu
 * obnoví ({@see restore()}).
 *
 * Tady je jen místo napojení: dokud firma nemá export profilu nastavení, nic se
 * neodkládá ani neobnovuje a převod do existující firmy nastavení nemění (převod je
 * idempotentní přes mapu převodu a ručně vybudované nastavení nepřepisuje). Export
 * a import profilu napojí sem svou službu (`capture` = export do pole, `restore` =
 * import pole), volající se nemění.
 */
class CompanyProfileCarryOver
{
    /**
     * Profil nastavení firmy před převodem, null = není co odkládat.
     *
     * @return array<string,mixed>|null
     */
    public function capture(int $supplierId): ?array
    {
        return null;
    }

    /**
     * Obnoví profil po převodu.
     *
     * @param array<string,mixed> $profile výsledek {@see capture()}
     * @return list<string> hlášení do protokolu dávky
     */
    public function restore(int $supplierId, array $profile): array
    {
        return [];
    }
}
