<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\Shared\ChartAccountCreator;

/**
 * Účtová osnova: z Money se přenáší jen to, na co se v deníku účtovalo.
 *
 * Firma má osnovu ze standardní šablony MyÚčta; analytiky z Money se pod ni doplní
 * (`042000` → `042.000` pod syntetikou `042`) s názvem z `UcOsnova.DAT`. Existující účet
 * se stejným kódem se použije tak, jak je. Syntetika, kterou šablona nemá, se založí
 * s typem převzatým od sourozence ze stejné skupiny (první dvě číslice), jinak ze stejné
 * třídy — bez sourozence typ účtu (aktivum/pasivum/náklad) odhadovat nejde a převod
 * skončí chybou.
 */
final class ChartImporter
{
    public const STEP = 'chart';

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($this->accounts->count($ctx->supplierId) === 0) {
            $seeded = $this->seeder->seedForSupplier($ctx->supplierId);
            $p->setCount(self::STEP, 'seeded', $seeded);
        }

        $names = [];
        foreach ($ctx->backup->rowsAcrossYears('UcOsnova') as $r) {
            $code = trim((string) ($r['Ucet'] ?? ''));
            $name = trim((string) ($r['Nazev'] ?? ''));
            if ($code !== '' && ctype_digit($code) && $name !== '') {
                $names[$code] = $name;
            }
        }

        $used = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik') as $r) {
            if (Ms3Journal::isYearEndClosing($r)) {
                continue;
            }
            foreach (['UcMD', 'UcD'] as $k) {
                $code = trim((string) ($r[$k] ?? ''));
                if ($code !== '') {
                    $used[$code] = true;
                }
            }
        }

        $map = $this->accounts->codeToIdMap($ctx->supplierId);
        $ctx->accountIds = [];
        foreach ($map as $code => $row) {
            $ctx->accountIds[(string) $code] = $row['id'];
        }

        foreach (array_keys($used) as $raw) {
            $moneyCode = (string) $raw;
            $target = AccountCode::fromMoney($moneyCode);
            if ($target === null) {
                $p->error(self::STEP, 'invalid_account', "Deník Money účtuje na účet „{$moneyCode}\", který není číselný kód účtu.", ['account' => $moneyCode]);
                continue;
            }
            if (isset($ctx->accountIds[$target])) {
                $p->count(self::STEP, 'existing');
                continue;
            }
            $synthetic = substr($target, 0, 3);
            $parent = $this->accounts->findByCode($ctx->supplierId, $synthetic);
            if ($parent === null) {
                $parent = $this->createSynthetic($ctx, $synthetic, $names);
                if ($parent === null) {
                    continue;
                }
            }
            $id = (new ChartAccountCreator($this->accounts))->createAnalytic(
                $ctx->supplierId, $target, $names[$moneyCode] ?? ($names[str_replace('.', '', $target)] ?? ('Analytika ' . $moneyCode)), $parent,
            );
            $ctx->accountIds[$target] = $id;
            $p->count(self::STEP, 'created');
        }
        $p->finish(self::STEP);
    }

    /**
     * @param array<string,string> $names
     * @return array<string,mixed>|null
     */
    private function createSynthetic(ImportContext $ctx, string $synthetic, array $names): ?array
    {
        $created = (new ChartAccountCreator($this->accounts))
            ->createSynthetic($ctx->supplierId, $synthetic, $names[$synthetic . '000'] ?? ('Účet ' . $synthetic), $ctx->protocol, self::STEP, true);
        if ($created === null) {
            return null;
        }
        $ctx->accountIds[$synthetic] = $created['id'];
        return $this->accounts->findById($ctx->supplierId, $created['id']);
    }
}
