<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use PDO;

/**
 * Přenos identifikátorů odvodů zaměstnavatele z Nastavení firmy do Mezd.
 *
 * U právnické osoby žijí VS ČSSZ, kód OSSZ a číslo plátce zdravotního pojištění
 * kanonicky ve mzdovém modulu: VS u výchozí mzdové účtárny, kód OSSZ v nastavení
 * zaměstnavatele, číslo plátce jako VS účinného účtu výchozí zdravotní pojišťovny.
 * Staré sloupce `supplier.cssz_vsdp`, `cssz_ossz_code` a `health_insurance_number`
 * přenesla jen jednorázová migrace 1194; firma, která mzdy zapnula (ručně nebo
 * převodem z jiného programu) až po ní, o ně přišla při dalším uložení Nastavení
 * firmy, protože tam se u PO se zapnutými Mzdami nulovaly.
 *
 * Pravidla jsou stejná jako v migraci 1194:
 *  - jen právnická osoba, u OSVČ jsou to osobní údaje a zůstávají na firmě,
 *  - jen do prázdného cíle, vyplněnou hodnotu nic nepřepíše,
 *  - VS a číslo plátce jen jako 1 až 10 číslic (ostatní znaky se zahodí),
 *    kód OSSZ jen v tvaru, který přijme nastavení zaměstnavatele,
 *  - číslo plátce jen do účtu výchozí pojišťovny účinného dnes, pokud existuje.
 *
 * Starý údaj se smí smazat teprve tehdy, když ho kanonický cíl drží
 * ({@see self::settle()}). Neplatný nebo zatím nepřenositelný údaj zůstává na firmě.
 */
final class PayrollEmployerLegacyIdentifierCarryOver
{
    public const SOCIAL_SECURITY_SYMBOL = 'cssz_vsdp';
    public const SOCIAL_SECURITY_OFFICE = 'cssz_ossz_code';
    public const HEALTH_INSURANCE_NUMBER = 'health_insurance_number';

    /** @var list<string> */
    public const LEGACY_FIELDS = [
        self::SOCIAL_SECURITY_SYMBOL,
        self::SOCIAL_SECURITY_OFFICE,
        self::HEALTH_INSURANCE_NUMBER,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployerSettingsRepository $settings,
        private readonly PayrollInstitutionAccountRepository $accounts,
        private readonly PayrollModuleAccess $access,
    ) {}

    /**
     * Přenese, co jde, do prázdných cílů. Nic nemaže.
     *
     * @return array<string,string> starý sloupec => přenesená hodnota
     */
    public function carryOver(int $supplierId, ?int $userId = null): array
    {
        if (!$this->schemaAvailable()) {
            return [];
        }
        $legacy = $this->legacy($supplierId);
        $target = $this->targets($supplierId);
        if ($legacy === null || $target === null) {
            return [];
        }

        $carried = [];
        $symbol = self::variableSymbol($legacy[self::SOCIAL_SECURITY_SYMBOL]);
        if ($symbol !== null && $target['office_symbol'] === null
            && $this->settings->fillEmptyDefaultOfficeSocialSecuritySymbol($supplierId, $symbol)) {
            $carried[self::SOCIAL_SECURITY_SYMBOL] = $symbol;
        }

        $office = self::socialSecurityOfficeCode($legacy[self::SOCIAL_SECURITY_OFFICE]);
        if ($office !== null && $target['office_code'] === null
            && $this->settings->fillEmptySocialSecurityOfficeCode($supplierId, $office)) {
            $carried[self::SOCIAL_SECURITY_OFFICE] = $office;
        }

        $payer = self::variableSymbol($legacy[self::HEALTH_INSURANCE_NUMBER]);
        $account = $target['health_account'];
        if ($payer !== null && $account !== null && $account['variable_symbol'] === null) {
            try {
                if ($this->accounts->fillEmptyVariableSymbol($supplierId, $account['id'], $payer, $userId)) {
                    $carried[self::HEALTH_INSURANCE_NUMBER] = $payer;
                }
            } catch (\PDOException $exception) {
                // Integritní trigger odmítá účet bez úplného ověření. Takový účet
                // se musí nejdřív ověřit v Účtech institucí; údaj zůstává na firmě.
                if ((string) $exception->getCode() !== '45000') {
                    throw $exception;
                }
            }
        }

        return $carried;
    }

    /**
     * Staré sloupce, jejichž hodnotu už drží kanonický cíl v Mzdách.
     *
     * @return list<string>
     */
    public function settledFields(int $supplierId): array
    {
        if (!$this->schemaAvailable()) {
            return [];
        }
        $target = $this->targets($supplierId);
        if ($target === null) {
            return [];
        }
        $settled = [];
        if ($target['office_symbol'] !== null) {
            $settled[] = self::SOCIAL_SECURITY_SYMBOL;
        }
        if ($target['office_code'] !== null) {
            $settled[] = self::SOCIAL_SECURITY_OFFICE;
        }
        if (($target['health_account']['variable_symbol'] ?? null) !== null) {
            $settled[] = self::HEALTH_INSURANCE_NUMBER;
        }

        return $settled;
    }

    /**
     * Přenese a potom smaže na firmě jen to, co už Mzdy drží.
     *
     * Jen právnická osoba se zapnutými Mzdami: s vypnutým modulem zůstává
     * Nastavení firmy jediným zdrojem (párování odvodů v bance, šablony pravidel).
     *
     * @return array{carried:array<string,string>,cleared:list<string>}
     */
    public function settle(int $supplierId, ?int $userId = null): array
    {
        $result = ['carried' => [], 'cleared' => []];
        if (!$this->access->isEnabled($supplierId)) {
            return $result;
        }
        $legacy = $this->legacy($supplierId);
        if ($legacy === null) {
            return $result;
        }
        $result['carried'] = $this->carryOver($supplierId, $userId);
        foreach ($this->settledFields($supplierId) as $field) {
            if ($legacy[$field] === null) {
                continue;
            }
            $this->db->pdo()->prepare(
                "UPDATE supplier SET {$field} = NULL WHERE id = ? AND taxpayer_type = 'po'"
            )->execute([$supplierId]);
            $result['cleared'][] = $field;
        }

        return $result;
    }

    /** VS ČSSZ i číslo plátce: jen číslice, 1 až 10 (jako migrace 1194). */
    public static function variableSymbol(?string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value) ?? '';

        return preg_match('/^[0-9]{1,10}$/D', $digits) === 1 ? $digits : null;
    }

    public static function socialSecurityOfficeCode(?string $value): ?string
    {
        $code = trim((string) $value);

        return $code !== '' && PayrollEmployerSettingsValidator::isValidSocialSecurityOfficeCode($code) ? $code : null;
    }

    /**
     * Staré hodnoty právnické osoby; u fyzické osoby null (nepřenáší se nikdy).
     *
     * @return array<string,?string>|null
     */
    private function legacy(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT taxpayer_type, cssz_vsdp, cssz_ossz_code, health_insurance_number FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['taxpayer_type'] !== 'po') {
            return null;
        }
        $legacy = [];
        foreach (self::LEGACY_FIELDS as $field) {
            $value = $row[$field] === null ? '' : trim((string) $row[$field]);
            $legacy[$field] = $value === '' ? null : $value;
        }

        return $legacy;
    }

    /**
     * Současný stav kanonických cílů; null, když firma nastavení zaměstnavatele nemá.
     *
     * @return array{office_symbol:?string,office_code:?string,health_account:?array{id:int,variable_symbol:?string}}|null
     */
    private function targets(int $supplierId): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT office.social_security_variable_symbol, settings.social_security_office_code,
                    settings.default_health_insurer_code
               FROM payroll_employer_settings settings
               JOIN payroll_offices office
                 ON office.supplier_id = settings.supplier_id
                AND office.id = settings.default_office_id
              WHERE settings.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $healthAccount = null;
        $insurer = trim((string) ($row['default_health_insurer_code'] ?? ''));
        if ($insurer !== '') {
            $accounts = $pdo->prepare(
                "SELECT account.id, account.variable_symbol
                   FROM payroll_institution_accounts account
                   JOIN payroll_institutions institution
                     ON institution.supplier_id = account.supplier_id
                    AND institution.id = account.institution_id
                  WHERE account.supplier_id = ?
                    AND institution.institution_type = ?
                    AND institution.institution_code = ?
                    AND account.currency_code = 'CZK'
                    AND account.valid_from <= CURRENT_DATE
                    AND (account.valid_to IS NULL OR account.valid_to >= CURRENT_DATE)
                  ORDER BY account.id
                  LIMIT 2"
            );
            $accounts->execute([$supplierId, InstitutionAccountType::HEALTH_INSURER->value, $insurer]);
            $rows = $accounts->fetchAll(PDO::FETCH_ASSOC);
            // Víc účinných účtů jedné pojišťovny v téže měně nemá být; když ano,
            // není jasné, kam číslo plátce patří, a nepřenáší se nikam.
            if (count($rows) === 1) {
                $healthAccount = [
                    'id' => (int) $rows[0]['id'],
                    'variable_symbol' => self::filled($rows[0]['variable_symbol']),
                ];
            }
        }

        return [
            'office_symbol' => self::filled($row['social_security_variable_symbol']),
            'office_code' => self::filled($row['social_security_office_code']),
            'health_account' => $healthAccount,
        ];
    }

    private static function filled(mixed $value): ?string
    {
        $text = $value === null ? '' : trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function schemaAvailable(): bool
    {
        return $this->db->hasTable('payroll_employer_settings')
            && $this->db->hasTable('payroll_offices')
            && $this->db->hasColumn('payroll_offices', 'social_security_variable_symbol')
            && $this->db->hasTable('payroll_office_registration_versions')
            && $this->db->hasTable('payroll_institution_accounts')
            && $this->db->hasColumn('supplier', 'cssz_vsdp');
    }
}
