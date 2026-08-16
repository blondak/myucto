<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;

final class PayrollApprovedRevisionPostingService
{
    private const STATUTORY_KINDS = [
        'social_insurance',
        'health_insurance',
        'income_tax',
        'net_pay',
    ];

    public function __construct(
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollPostingAdapter $posting,
        private readonly AccountingModeRepository $accountingModes,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @return array{
     *   batch_id:int,
     *   journal_entry_id:?int,
     *   status:'posted'|'no_change',
     *   idempotent:bool,
     *   preview:PayrollPostingPreview
     * }|null
     */
    public function post(
        int $supplierId,
        int $revisionId,
        array $snapshot,
        array $result,
        int $actorUserId,
    ): ?array {
        $this->assertPostingArguments($supplierId, $revisionId, $actorUserId);
        if (!self::automaticPostingEnabled($snapshot)) {
            return null;
        }

        return $this->execute(
            $supplierId,
            $revisionId,
            $snapshot,
            $result,
            $actorUserId,
        );
    }

    /**
     * Ruční zaúčtování schválené revize — vstupní bod pro příkaz `post`
     * mzdového běhu. Od automatické cesty se liší JEDINÝM bodem: neptá se na
     * `automatic_posting_enabled` ve zmrazené zaměstnavatelské politice.
     * Vypnuté automatické zaúčtování je rozhodnutí o tom, kdy se účtuje, ne
     * o tom, zda se účtuje — účetní si účetní zápis vyvolá sám. Všechny
     * ostatní kontroly (platnost zmrazené politiky, účetní režim firmy, čtyři
     * zákonné sady, zmrazená sada předkontací, idempotence adaptéru) zůstávají
     * beze změny.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @return array{
     *   batch_id:int,
     *   journal_entry_id:?int,
     *   status:'posted'|'no_change',
     *   idempotent:bool,
     *   preview:PayrollPostingPreview
     * }|null null = účetní můstek se na firmu nevztahuje (daňová evidence)
     */
    public function postManually(
        int $supplierId,
        int $revisionId,
        array $snapshot,
        array $result,
        int $actorUserId,
    ): ?array {
        $this->assertPostingArguments($supplierId, $revisionId, $actorUserId);
        // Tvar zmrazené politiky ověřujeme i tady (fail-closed), jen ignorujeme
        // její příznak automatiky.
        self::automaticPostingEnabled($snapshot);

        return $this->execute(
            $supplierId,
            $revisionId,
            $snapshot,
            $result,
            $actorUserId,
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @return array{
     *   batch_id:int,
     *   journal_entry_id:?int,
     *   status:'posted'|'no_change',
     *   idempotent:bool,
     *   preview:PayrollPostingPreview
     * }|null
     */
    private function execute(
        int $supplierId,
        int $revisionId,
        array $snapshot,
        array $result,
        int $actorUserId,
    ): ?array {
        $year = self::snapshotYear($snapshot);
        if ($this->accountingModes->forYear($supplierId, $year) !== 'double_entry') {
            return null;
        }

        $sets = [];
        foreach (self::STATUTORY_KINDS as $kind) {
            $set = $this->statutoryResults->find(
                $supplierId,
                $revisionId,
                $kind,
            );
            if ($set === null) {
                throw new \DomainException(
                    "Schválená revize nemá zákonný výsledek {$kind}.",
                );
            }
            $sets[$kind] = $set;
        }

        $employer = $snapshot['employer'] ?? null;
        $accounts = is_array($employer) && !array_is_list($employer)
            ? ($employer['accounting_accounts'] ?? null)
            : null;
        if (!is_array($accounts) || array_is_list($accounts)) {
            throw new \DomainException(
                'Schválený mzdový snapshot nemá zmrazenou sadu předkontací.',
            );
        }
        $normalizedAccounts = [];
        foreach ($accounts as $key => $account) {
            if (!is_string($key)
                || !is_string($account)
                || preg_match('/^[0-9]{3}[.A-Z0-9]{0,13}$/D', $account) !== 1
            ) {
                throw new \DomainException(
                    'Schválený mzdový snapshot nemá platnou sadu předkontací.',
                );
            }
            $normalizedAccounts[$key] = $account;
        }
        ksort($normalizedAccounts, SORT_STRING);

        return $this->posting->post(
            $supplierId,
            $revisionId,
            $snapshot,
            $result,
            $sets,
            $normalizedAccounts,
            ['user_id' => $actorUserId],
        );
    }

    private function assertPostingArguments(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        if ($supplierId <= 0 || $revisionId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize a uživatel účetního můstku musí být kladná čísla.',
            );
        }
    }

    /** @param array<string,mixed> $snapshot */
    private static function automaticPostingEnabled(array $snapshot): bool
    {
        $policy = $snapshot['employer_policy'] ?? null;
        if (!is_array($policy) || array_is_list($policy)) {
            throw new \DomainException(
                'Mzdový snapshot nemá zmrazenou účinnou zaměstnavatelskou politiku.',
            );
        }
        foreach (['id', 'row_version'] as $field) {
            $value = $policy[$field] ?? null;
            if (!is_int($value) || $value <= 0) {
                throw new \DomainException(
                    'Mzdový snapshot nemá platnou účinnou zaměstnavatelskou politiku.',
                );
            }
        }
        $enabled = $policy['automatic_posting_enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw new \DomainException(
                'Mzdový snapshot nemá platnou politiku automatického zaúčtování.',
            );
        }

        return $enabled;
    }

    /** @param array<string,mixed> $snapshot */
    private static function snapshotYear(array $snapshot): int
    {
        $periodStart = $snapshot['period_start'] ?? null;
        if (!is_string($periodStart)
            || preg_match('/^([0-9]{4})-[0-9]{2}-[0-9]{2}$/D', $periodStart, $match) !== 1
        ) {
            throw new \DomainException(
                'Mzdový snapshot nemá platný počátek období.',
            );
        }

        return (int) $match[1];
    }
}
