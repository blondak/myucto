<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zaměstnanci/jednatelé-společníci pro mzdový list (§38j ZDP) — REST CRUD nad
 * `payroll_employees` (migrace 1105). Čistě identifikace + prohlášení poplatníka;
 * samotný rozpad mzdy za měsíc počítá a účtuje {@see PayrollAction}.
 *
 *   GET    /api/accounting/payroll/employees        — seznam (readonly+)
 *   POST   /api/accounting/payroll/employees        — založit (účetní|admin)
 *   PUT    /api/accounting/payroll/employees/{id}    — upravit (účetní|admin)
 *   DELETE /api/accounting/payroll/employees/{id}    — smazat, jen bez historie (účetní|admin)
 */
final class PayrollEmployeeAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** Pracovněprávní vztah — migrace 1156; řídí režim pojistného a srážkové daně. */
    private const EMPLOYMENT_TYPES = ['hpp', 'dpp', 'dpc'];

    /** Účtové skupiny, na které čistou mzdu přeúčtovat nelze — vlastní modul si je hlídá sám. */
    private const MONEY_ACCOUNT_PREFIXES = ['21', '22', '26'];

    /** Shodný strop jako {@see PayrollAction} — nad ním už rozpad není důvěryhodný. */
    private const MAX_MONTHLY_GROSS = 10_000_000;

    private const GROSS_RANGE_MESSAGE = 'Pravidelná hrubá mzda musí být celé číslo v rozsahu 0 až 10 000 000 Kč.';

    public function __construct(
        private readonly PayrollEmployeeRepository $employees,
        private readonly \MyInvoice\Repository\ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $active = array_key_exists('active', $q) && $q['active'] !== ''
            ? (bool) filter_var($q['active'], FILTER_VALIDATE_BOOLEAN)
            : null;

        return Json::ok($response, ['items' => $this->employees->listForTenant($supplierId, $active)]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $data = $this->normalize($supplierId, $body, $response, $err);
        if ($data === null) return $err;
        if (!$this->autoPostHasGross($data, $response, $err)) return $err;

        $id = $this->employees->insert($supplierId, $data);
        $this->log($request, 'payroll_employee.created', $id, ['full_name' => $data['full_name']]);
        return Json::ok($response, ['employee' => $this->employees->find($supplierId, $id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) $args['id'];
        $current = $this->employees->find($supplierId, $id);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $fields = $this->normalizePartial($supplierId, $body, $response, $err);
        if ($fields === null) return $err;
        if (!$this->autoPostHasGross(array_merge($current, $fields), $response, $err)) return $err;

        $this->employees->update($supplierId, $id, $fields);
        $this->log($request, 'payroll_employee.updated', $id, array_keys($fields));
        return Json::ok($response, ['employee' => $this->employees->find($supplierId, $id)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) $args['id'];
        if ($this->employees->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }
        if ($this->employees->hasMonthlyRecords($supplierId, $id)) {
            return Json::error(
                $response,
                'employee_has_records',
                'Zaměstnanec má uložené mzdové záznamy (mzdový list) — smazáním by se ztratila evidence. Deaktivuj ho místo mazání.',
                409,
            );
        }
        $this->employees->delete($supplierId, $id);
        $this->log($request, 'payroll_employee.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    private function normalize(int $supplierId, array $body, Response $response, ?Response &$err): ?array
    {
        $fullName = trim((string) ($body['full_name'] ?? ''));
        if ($fullName === '') {
            $err = Json::error($response, 'validation_failed', 'Jméno a příjmení je povinné.', 422);
            return null;
        }
        $type = (string) ($body['taxpayer_type'] ?? PayrollCalculator::TYPE_EMPLOYEE);
        if (!in_array($type, PayrollCalculator::types(), true)) {
            $err = Json::error($response, 'validation_failed', 'Neznámý typ poplatníka.', 422);
            return null;
        }
        $birthDate = $this->nullableDate($body['birth_date'] ?? null);
        if ($birthDate === false) {
            $err = Json::error($response, 'validation_failed', 'Neplatné datum narození (očekává se YYYY-MM-DD).', 422);
            return null;
        }
        $childCount = (int) ($body['child_count'] ?? 0);
        if ($childCount < 0 || $childCount > 20) {
            $err = Json::error($response, 'validation_failed', 'Počet dětí musí být v rozsahu 0 až 20.', 422);
            return null;
        }
        $monthlyGross = $this->nullableGross($body['monthly_gross'] ?? null);
        if ($monthlyGross === false) {
            $err = Json::error($response, 'validation_failed', self::GROSS_RANGE_MESSAGE, 422);
            return null;
        }
        $settlement = $this->settlementAccount($supplierId, $body['net_settlement_account_code'] ?? null, $response, $err);
        if ($err !== null) {
            return null;
        }

        $err = null;
        return [
            'full_name'           => $fullName,
            'birth_date'          => $birthDate,
            'birth_number'        => $this->nullableString($body['birth_number'] ?? null),
            'address'             => $this->nullableString($body['address'] ?? null),
            'taxpayer_type'       => $type,
            'tax_credit_taxpayer' => array_key_exists('tax_credit_taxpayer', $body)
                ? (bool) filter_var($body['tax_credit_taxpayer'], FILTER_VALIDATE_BOOLEAN)
                : true,
            // § 38k odst. 4 — bez podepsaného prohlášení se měsíční sleva uplatnit NESMÍ.
            // Chybí-li údaj, bere se jako NEPODEPSANÉ: nesrazit dost je horší než srazit
            // víc, protože přeplatek se vrátí v ročním zúčtování, kdežto za nesraženou
            // zálohu ručí plátce (§ 38s ZDP). Pole do teď v API vůbec nebylo, takže
            // zaměstnance bez prohlášení nešlo zadat.
            'tax_declaration_signed' => array_key_exists('tax_declaration_signed', $body)
                ? (bool) filter_var($body['tax_declaration_signed'], FILTER_VALIDATE_BOOLEAN)
                : false,
            'employment_type'     => in_array((string) ($body['employment_type'] ?? ''), self::EMPLOYMENT_TYPES, true)
                ? (string) $body['employment_type']
                : 'hpp',
            'child_count'         => $childCount,
            // Migrace 1178 — kam se měsíčně přeúčtuje čistá mzda (typicky 365.x).
            'net_settlement_account_code' => $settlement,
            // Migrace 1175 — pravidelná mzda a pověření cronu, ať se měsíc od měsíce
            // neopisuje táž konstanta.
            'monthly_gross'       => $monthlyGross,
            'auto_post'           => array_key_exists('auto_post', $body)
                ? (bool) filter_var($body['auto_post'], FILTER_VALIDATE_BOOLEAN)
                : false,
            'is_active'           => array_key_exists('is_active', $body)
                ? (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true,
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    private function normalizePartial(int $supplierId, array $body, Response $response, ?Response &$err): ?array
    {
        $fields = [];
        if (array_key_exists('full_name', $body)) {
            $fullName = trim((string) $body['full_name']);
            if ($fullName === '') {
                $err = Json::error($response, 'validation_failed', 'Jméno a příjmení je povinné.', 422);
                return null;
            }
            $fields['full_name'] = $fullName;
        }
        if (array_key_exists('taxpayer_type', $body)) {
            $type = (string) $body['taxpayer_type'];
            if (!in_array($type, PayrollCalculator::types(), true)) {
                $err = Json::error($response, 'validation_failed', 'Neznámý typ poplatníka.', 422);
                return null;
            }
            $fields['taxpayer_type'] = $type;
        }
        if (array_key_exists('birth_date', $body)) {
            $birthDate = $this->nullableDate($body['birth_date']);
            if ($birthDate === false) {
                $err = Json::error($response, 'validation_failed', 'Neplatné datum narození (očekává se YYYY-MM-DD).', 422);
                return null;
            }
            $fields['birth_date'] = $birthDate;
        }
        if (array_key_exists('birth_number', $body)) {
            $fields['birth_number'] = $this->nullableString($body['birth_number']);
        }
        if (array_key_exists('address', $body)) {
            $fields['address'] = $this->nullableString($body['address']);
        }
        if (array_key_exists('tax_credit_taxpayer', $body)) {
            $fields['tax_credit_taxpayer'] = (bool) filter_var($body['tax_credit_taxpayer'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('tax_declaration_signed', $body)) {
            $fields['tax_declaration_signed'] = (bool) filter_var($body['tax_declaration_signed'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('employment_type', $body)) {
            $type = (string) $body['employment_type'];
            if (!in_array($type, self::EMPLOYMENT_TYPES, true)) {
                $err = Json::error($response, 'validation_failed', sprintf(
                    'Neznámý typ pracovněprávního vztahu (%s).',
                    implode('|', self::EMPLOYMENT_TYPES),
                ), 422);
                return null;
            }
            $fields['employment_type'] = $type;
        }
        if (array_key_exists('child_count', $body)) {
            $childCount = (int) $body['child_count'];
            if ($childCount < 0 || $childCount > 20) {
                $err = Json::error($response, 'validation_failed', 'Počet dětí musí být v rozsahu 0 až 20.', 422);
                return null;
            }
            $fields['child_count'] = $childCount;
        }
        if (array_key_exists('monthly_gross', $body)) {
            $monthlyGross = $this->nullableGross($body['monthly_gross']);
            if ($monthlyGross === false) {
                $err = Json::error($response, 'validation_failed', self::GROSS_RANGE_MESSAGE, 422);
                return null;
            }
            $fields['monthly_gross'] = $monthlyGross;
        }
        if (array_key_exists('net_settlement_account_code', $body)) {
            $fields['net_settlement_account_code'] =
                $this->settlementAccount($supplierId, $body['net_settlement_account_code'], $response, $err);
            if ($err !== null) {
                return null;
            }
        }
        if (array_key_exists('auto_post', $body)) {
            $fields['auto_post'] = (bool) filter_var($body['auto_post'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('is_active', $body)) {
            $fields['is_active'] = (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        $err = null;
        return $fields;
    }

    /**
     * Účet pro měsíční přeúčtování čisté mzdy (migrace 1178). Prázdno = NULL (nepřeúčtovávat).
     *
     * Musí existovat v osnově TÉTO firmy — kód přitéká z API a bez scope kontroly by karta
     * ukazovala na účet cizího tenanta, který by pak zaúčtování stejně neumělo přeložit.
     *
     * PENĚŽNÍ ÚČTY SE ODMÍTAJÍ. Výplatu z pokladny musí zapsat výdajový pokladní doklad,
     * jinak se pokladní kniha (zákonná evidence dle §29 ZoÚ) rozejde s hlavní knihou;
     * bankovní výplatu zaúčtuje párování výpisu a mzdový automat by ji zdvojil. Obojí má
     * v aplikaci vlastní modul, tenhle přepínač je na bezhotovostní přeúčtování (365, 479…).
     */
    private function settlementAccount(int $supplierId, mixed $value, Response $response, ?Response &$err): ?string
    {
        $code = trim((string) ($value ?? ''));
        if ($code === '') {
            $err = null;
            return null;
        }
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null) {
            $err = Json::error($response, 'validation_failed', sprintf(
                'Účet %s není v účtové osnově firmy.',
                $code,
            ), 422);
            return null;
        }
        if (empty($account['is_active'])) {
            $err = Json::error($response, 'validation_failed', sprintf(
                'Účet %s je neaktivní — vyber jiný.',
                $code,
            ), 422);
            return null;
        }
        foreach (self::MONEY_ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                $err = Json::error($response, 'validation_failed', sprintf(
                    'Na peněžní účet (%s) čistou mzdu přeúčtovat nelze — výplatu z pokladny zapiš '
                        . 'výdajovým pokladním dokladem a výplatu z účtu spáruj v bance, jinak se '
                        . 'pokladní kniha a výpis rozejdou s deníkem.',
                    $code,
                ), 422);
                return null;
            }
        }

        $err = null;
        return $code;
    }

    /**
     * Zapnutý automat bez částky je past: cron by neměl co zaúčtovat a uživatel by
     * čekal zápis, který nikdy nepřijde. Kontrola je nutně nad SLOUČENÝM stavem —
     * částečný update může poslat jen příznak a mzda přitom už na kartě je (a naopak,
     * vynulování mzdy musí shodit i příznak).
     *
     * @param array<string,mixed> $effective stav karty po aplikaci změn
     */
    private function autoPostHasGross(array $effective, Response $response, ?Response &$err): bool
    {
        $gross = $effective['monthly_gross'] ?? null;
        if (!empty($effective['auto_post']) && ($gross === null || (int) $gross <= 0)) {
            $err = Json::error(
                $response,
                'validation_failed',
                'Automatické měsíční účtování jde zapnout jen s vyplněnou pravidelnou hrubou mzdou — bez ní by cron neměl co zaúčtovat.',
                422,
            );
            return false;
        }
        $err = null;
        return true;
    }

    /** @return int|null|false false = mimo rozsah */
    private function nullableGross(mixed $v): int|null|false
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return false;
        }
        $gross = (int) $v;
        return ($gross < 0 || $gross > self::MAX_MONTHLY_GROSS) ? false : $gross;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @return string|null|false false = neplatný formát */
    private function nullableDate(mixed $v): string|null|false
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            return false;
        }
        return $v;
    }

    /** @param array<mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'payroll_employee', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
