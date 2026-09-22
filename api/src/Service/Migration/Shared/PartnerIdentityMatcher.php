<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\CompanyIdNormalizer;
use PDO;

/**
 * Identita partnera při převodu z cizího programu: normalizace IČO a DIČ a párování na
 * kontakt, který už ve firmě je.
 *
 * Společné jádro převodů (Money S3, Pohoda, PREMIER, Stereo NX):
 *   - IČO v kanonickém tvaru (8 číslic) - cizí programy vedou tentýž subjekt jednou
 *     s vodicí nulou a jednou bez ní,
 *   - DIČ jen tehdy, když má tvar DIČ (kód státu a aspoň jedna číslice); do pole DIČ se
 *     v cizích programech dostane i značka skupiny DPH nebo rejstříkové číslo,
 *   - existující kontakt se páruje podle IČO (nejstarší nearchivovaný kontakt), partner
 *     bez IČO podle přesného názvu,
 *   - klíč mapy převodu u partnera z dokladu: `ico:…`, jinak `name:` + název malými.
 *
 * Kontakt se nikdy nepřepisuje, jen se doplní prázdné údaje ({@see fillMissing()}).
 */
final class PartnerIdentityMatcher
{
    private const VAT_ID_SHAPE = '/^[A-Z]{2}(?=[0-9A-Z]*\d)[0-9A-Z]{2,13}$/';

    public function __construct(private readonly Connection $db) {}

    /** IČO v kanonickém tvaru (8 číslic), '' když IČO není. */
    public static function ico(string $ico): string
    {
        return CompanyIdNormalizer::ic($ico) ?? '';
    }

    /** DIČ, jen když má tvar DIČ (kód státu a aspoň jedna číslice), jinak ''. */
    public static function vatId(string $dic): string
    {
        $clean = CompanyIdNormalizer::dic($dic) ?? '';
        return preg_match(self::VAT_ID_SHAPE, $clean) === 1 ? $clean : '';
    }

    /** Klíč partnera z dokladu v mapě převodu: podle IČO, jinak podle názvu. */
    public static function documentKey(string $ico, string $name): string
    {
        return $ico !== '' ? 'ico:' . $ico : 'name:' . mb_strtolower($name);
    }

    /**
     * Existující kontakty firmy podle IČO (kanonický tvar → nejstarší nearchivovaný).
     *
     * @return array<string,int>
     */
    public function clientsByIco(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, ic FROM clients WHERE supplier_id = ? AND ic IS NOT NULL AND ic <> '' AND archived_at IS NULL ORDER BY id");
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ico = self::ico((string) $row['ic']);
            if ($ico !== '') {
                $out[$ico] ??= (int) $row['id'];
            }
        }
        return $out;
    }

    /**
     * Nejstarší nearchivovaný kontakt firmy s tímto IČO v kanonickém tvaru - i když je
     * u kontaktu uložené s mezerami nebo bez vodicí nuly.
     *
     * @return array{id:int,dic:string}|null
     */
    public function clientByIco(int $supplierId, string $ico): ?array
    {
        $ico = self::ico($ico);
        if ($ico === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, ic, dic FROM clients
              WHERE supplier_id = ? AND ic IS NOT NULL AND archived_at IS NULL
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(ic, '[^0-9]', '')) = ?
           ORDER BY id"
        );
        $stmt->execute([$supplierId, ltrim($ico, '0')]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::ico((string) $row['ic']) === $ico) {
                return ['id' => (int) $row['id'], 'dic' => (string) ($row['dic'] ?? '')];
            }
        }
        return null;
    }

    /** Nejstarší nearchivovaný kontakt firmy s přesně tímto názvem. */
    public function clientByName(int $supplierId, string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id = ? AND company_name = ? AND archived_at IS NULL ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId, $name]);
        $found = $stmt->fetchColumn();
        return $found === false ? null : (int) $found;
    }

    /**
     * Druhý záznam téhož partnera (stejné IČO): kontakt už existuje, doplní se jen údaje,
     * které na něm chybějí (DIČ, adresa, e-mail, telefon). Nic se nepřepisuje.
     *
     * @param array{dic:string,street:string,city:string,zip:string,email:string,phone:string} $s
     */
    public function fillMissing(int $supplierId, int $clientId, array $s): void
    {
        $dic = self::vatId($s['dic']);
        $zip = str_replace(' ', '', $s['zip']);
        $this->db->pdo()->prepare(
            "UPDATE clients SET
                dic = COALESCE(NULLIF(dic, ''), ?),
                is_vat_payer = IF(? IS NOT NULL, 1, is_vat_payer),
                street = IF(street IS NULL OR street IN ('', '-'), COALESCE(?, street), street),
                city = IF(city IS NULL OR city IN ('', '-'), COALESCE(?, city), city),
                zip = IF(zip IS NULL OR zip IN ('', '-'), COALESCE(?, zip), zip),
                main_email = COALESCE(NULLIF(main_email, ''), ?),
                phone = COALESCE(NULLIF(phone, ''), ?)
              WHERE id = ? AND supplier_id = ?"
        )->execute([
            $dic !== '' ? mb_substr($dic, 0, 20) : null,
            $dic !== '' ? $dic : null,
            $s['street'] !== '' ? mb_substr($s['street'], 0, 190) : null,
            $s['city'] !== '' ? mb_substr($s['city'], 0, 120) : null,
            $zip !== '' ? mb_substr($zip, 0, 10) : null,
            $s['email'] !== '' ? mb_substr($s['email'], 0, 190) : null,
            $s['phone'] !== '' ? mb_substr($s['phone'], 0, 40) : null,
            $clientId,
            $supplierId,
        ]);
    }
}
